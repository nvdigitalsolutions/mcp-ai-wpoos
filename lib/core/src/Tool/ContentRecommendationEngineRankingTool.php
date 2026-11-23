<?php

/**
 * Tool: content_recommendation_engine — rank real content as related reading.
 *
 * `ContentRecommendationEngineRankingTool` because `ContentRecommendationEngineTool`
 * is the quarantined prompt-builder answering to
 * `content_recommendation_engine_prompt`, and because ranking is the whole job here:
 * no model is asked.
 *
 * ## Why there is no completion call
 *
 * The WordPress tool this replaces is described as "AI-powered", and its parameters
 * advertise semantic search and collaborative filtering. Neither exists: it accepts
 * `use_semantic_search` and never reads it, and its `category_based` mode assigns the
 * literal score `0.8` to posts returned in random order. "Personalized" reads an
 * interaction option that only its own `track_interaction` action ever writes, so on
 * a fresh install it silently falls back to recency.
 *
 * A recommendation is a ranking over rows that exist. Ranking by signals the caller
 * can inspect — shared terms, shared words, age — is auditable; asking a model to
 * rank without embeddings would produce relevance scores it cannot compute, which is
 * the same failure as asking one to invent ids. So this tool computes the score and
 * reports its components, and every id in the answer is a row that was retrieved.
 *
 * ## The formula, and where it departs from WordPress
 *
 * For an item that has taxonomy terms, the published shape is kept:
 *
 *     score = 0.4 · taxonomy_overlap + 0.3 · tag_overlap + 0.3 · title_similarity
 *
 * Three corrections, each named in the proposal:
 *
 * - **Title similarity is token overlap, not `similar_text()`.** WordPress counts
 *   matching characters in order, which scores two unrelated English titles of equal
 *   length surprisingly high because they share letters. Token overlap does not.
 * - **`category_based` returns the real score** instead of a constant `0.8`.
 * - **An item with no terms falls back to text overlap** (`0.7 · text + 0.3 · title`)
 *   rather than scoring every candidate zero and returning them in whatever order the
 *   store produced. A post with no categories and no tags has nothing for the
 *   WordPress formula to weigh; text is the signal that remains.
 *
 * ## What is not offered, and why
 *
 * `train_model`, `track_interaction` and `analyze_performance` need an interaction
 * store this host does not have. They return a named error explaining what is missing
 * rather than writing an option and reporting success, which is what WordPress's
 * `train_model` does. `personalized` is served as the recency fallback WordPress
 * itself uses when there is no history, and the payload says so in `fallback`.
 *
 * `use_semantic_search`, `use_collaborative` and `recency_weight` are not parameters
 * here. The first two did nothing in WordPress; the third is only meaningful against
 * engagement data that does not exist, and with no engagement term it is a monotone
 * rescaling of recency that cannot change the order.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Service\Text\TopicTerms;

final class ContentRecommendationEngineRankingTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const DEFAULT_LIMIT = 5;

    /**
     * A tool call is not a site crawl. Twenty is well past what a caller renders.
     */
    private const MAX_LIMIT = 20;

    private const MAX_TERMS = 3;

    private const CANDIDATES_PER_TERM = 20;

    /**
     * Bounded because every candidate is scored in memory and each row carries its
     * full body. Sixty is roughly three terms' worth of deduplicated hits.
     */
    private const MAX_CANDIDATES = 60;

    /**
     * WordPress's published weights, kept so the score means the same thing it meant
     * there.
     */
    private const W_TAXONOMY = 0.4;

    private const W_TAG = 0.3;

    private const W_TITLE = 0.3;

    /**
     * Weights for an item with no terms, where taxonomy and tag carry no signal.
     */
    private const W_TEXT = 0.7;

    /**
     * The window WordPress scores recency over.
     */
    private const TRENDING_WINDOW_DAYS = 7;

    private const SECONDS_PER_DAY = 86400;

    /**
     * Taxonomies WordPress treats as tags, so the tag weight applies to them rather
     * than the taxonomy weight.
     */
    private const TAG_TAXONOMIES = array( 'post_tag', 'tag' );

    private const SUPPORTED_TYPES = array(
        'similar_content',
        'category_based',
        'trending',
        'personalized',
    );

    private readonly TopicTerms $terms;

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?ContentStoreInterface $content = null,
        ?TopicTerms $terms = null,
    ) {
        parent::__construct($errors);

        $this->terms = $terms ?? new TopicTerms();
    }

    public function getSlug(): string
    {
        return 'content_recommendation_engine';
    }

    public function getName(): string
    {
        return 'Content Recommendation Engine';
    }

    public function getDescription(): string
    {
        return 'Ranks existing published content as related reading for a page or a body of text, with the score broken into the signals that produced it. Supports similar-content, category-based, trending and personalized fallback ranking. Every recommendation is a real row; a view/click share and performance figures are not available because this host records no interactions.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'action'              => array(
                    'type'        => 'string',
                    'description' => 'What to do. Only get_recommendations does work on this host; the other three are named so a caller gets a specific refusal instead of a silent no-op.',
                    'enum'        => array(
                        'get_recommendations',
                        'train_model',
                        'track_interaction',
                        'analyze_performance',
                    ),
                    'default'     => 'get_recommendations',
                ),
                'recommendation_type' => array(
                    'type'        => 'string',
                    'description' => 'similar_content ranks on shared terms, words and title; category_based keeps only items sharing a category or tag; trending ranks the last seven days by recency; personalized is the recency fallback used when no reading history exists.',
                    'enum'        => self::SUPPORTED_TYPES,
                    'default'     => 'similar_content',
                ),
                'post_id'             => array(
                    'type'        => 'integer',
                    'description' => 'Stored item to recommend for. Provide this or inline content.',
                    'minimum'     => 1,
                ),
                'content'             => array(
                    'type'        => 'string',
                    'description' => 'Body text to recommend for when the page is not stored. Provide this or post_id.',
                ),
                'title'               => array(
                    'type'        => 'string',
                    'description' => 'Title of the inline content. Improves title similarity.',
                ),
                'limit'               => array(
                    'type'        => 'integer',
                    'description' => 'How many recommendations to return.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
                ),
                'include_post_types'  => array(
                    'type'        => 'array',
                    'description' => 'Content types to consider.',
                    'items'       => array(
                        'type'      => 'string',
                        'minLength' => 1,
                    ),
                    'maxItems'    => 20,
                ),
                'exclude_posts'       => array(
                    'type'        => 'array',
                    'description' => 'Ids that must not appear in the results.',
                    'items'       => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'maxItems'    => 50,
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getCapabilityFlags(): array
    {
        return array(
            'idempotent',
            'local-only',
            'read-only',
            'requires-capability',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $action = $this->stringParam($arguments, 'action', 'get_recommendations');

        if ('get_recommendations' !== $action) {
            return $this->refuseAction($action);
        }

        if (null === $this->content) {
            return $this->errors->create(
                'content_store_unavailable',
                'This host has no content store, so there is nothing to recommend.'
            );
        }

        $type = $this->stringParam($arguments, 'recommendation_type', 'similar_content');

        if (! \in_array($type, self::SUPPORTED_TYPES, true)) {
            return $this->errors->validationFailed(
                \sprintf('Unknown recommendation_type "%s".', $type),
                array( 'recommendation_type' => 'Use one of: ' . \implode(', ', self::SUPPORTED_TYPES) )
            );
        }

        $userId = (int) ( $context['user_id'] ?? $context['owner_id'] ?? 0 );
        $userId = $userId > 0 ? $userId : null;

        $limit     = \max(1, \min(self::MAX_LIMIT, $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT)));
        $postTypes = $this->postTypes($arguments);

        // Trending and its personalized fallback rank the whole store, so they need no
        // source. Requiring one would make "what is new this week?" answerable only by
        // naming a page first.
        if ('trending' === $type || 'personalized' === $type) {
            return $this->trending($type, $postTypes, $this->excludeIds($arguments, null), $limit, $userId);
        }

        $source = $this->source($arguments, $userId);

        if ($this->errors->isError($source)) {
            return $source;
        }

        $exclude = $this->excludeIds($arguments, $source['item']);

        $derived = $this->searchTerms($source);

        if (array() === $derived) {
            return $this->empty(
                'No searchable topic could be derived from the source, and it has no categories or tags to match on.',
                $source['item']?->id,
                $type
            );
        }

        if ('category_based' === $type && array() === $this->sourceTerms($source['item'])) {
            return $this->empty(
                'The source has no categories or tags, so there is nothing to match a category on.',
                $source['item']?->id,
                $type
            );
        }

        $candidates = $this->candidates($derived, $postTypes, $exclude, $userId);

        if (array() === $candidates) {
            return $this->empty(
                \sprintf('Nothing published matches %s.', \implode(', ', $derived)),
                $source['item']?->id,
                $type
            );
        }

        if ('category_based' === $type) {
            $candidates = $this->sharingTerms($candidates, $source['item']);

            if (array() === $candidates) {
                return $this->empty(
                    'No other published content shares a category or tag with the source.',
                    $source['item']?->id,
                    $type
                );
            }
        }

        return $this->rank($candidates, $source, $type, $derived, $limit);
    }

    /**
     * Turn each unsupported action into the specific thing the host lacks.
     *
     * A generic "not supported" would leave the caller guessing whether to retry,
     * configure something, or give up.
     */
    private function refuseAction(string $action): mixed
    {
        $reason = match ($action) {
            'train_model' => 'There is no recommendation model to train on this host. Ranking is computed from content at request time, so there is nothing to fit and no weights to store.',
            'track_interaction' => 'This host records no interactions, so a view, click, share or conversion cannot be stored. Trending is ranked by recency instead.',
            'analyze_performance' => 'This host records no interactions, so there is no click-through or conversion history to analyse.',
            default => \sprintf('Unknown action "%s".', $action),
        };

        return $this->errors->create('unsupported_action', $reason);
    }

    /**
     * Resolve what the recommendations are for: a stored row, or inline text.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{item: ContentItem|null, title: string, text: string}|mixed
     */
    private function source(array $arguments, ?int $userId): mixed
    {
        $postId = $this->intParam($arguments, 'post_id');

        if ($postId > 0) {
            $item = $this->content?->find($postId, $userId);

            if (null === $item) {
                // Not found and not accessible are the same answer to the caller who
                // may not see it, and distinguishing them would leak existence.
                return $this->errors->create(
                    'content_not_found',
                    \sprintf('No content with id %d is readable by this caller.', $postId)
                );
            }

            return array(
                'item'  => $item,
                'title' => $item->title,
                'text'  => $item->title . ' ' . $item->content,
            );
        }

        $content = $this->stringParam($arguments, 'content');

        if ('' === \trim($content)) {
            return $this->errors->validationFailed(
                'Provide a post_id or some content to recommend for.',
                array( 'post_id' => 'Required when content is empty.', 'content' => 'Required when post_id is absent.' )
            );
        }

        $title = \mb_substr($this->stringParam($arguments, 'title'), 0, 300);

        return array(
            'item'  => null,
            'title' => $title,
            'text'  => $title . ' ' . $content,
        );
    }

    /**
     * What to search the store for.
     *
     * For a stored item the terms are derived from its text; a page with no
     * searchable words but real categories falls back to the category names, which
     * are terms a caller would recognise.
     *
     * @param array{item: ContentItem|null, title: string, text: string} $source
     *
     * @return list<string>
     */
    private function searchTerms(array $source): array
    {
        $derived = $this->terms->top($source['text'], self::MAX_TERMS);

        if (array() !== $derived) {
            return $derived;
        }

        return \array_slice($this->sourceTerms($source['item']), 0, self::MAX_TERMS);
    }

    /**
     * Every category/tag name the source carries, tag taxonomies included.
     *
     * @return list<string>
     */
    private function sourceTerms(?ContentItem $item): array
    {
        if (null === $item) {
            return array();
        }

        $terms = array();

        foreach ($item->taxonomy as $names) {
            foreach ($names as $name) {
                if (\is_string($name) && '' !== \trim($name)) {
                    $terms[] = \trim($name);
                }
            }
        }

        return \array_values(\array_unique($terms));
    }

    /**
     * Published rows the caller may read.
     *
     * @param list<string> $terms
     *
     * @return list<ContentItem>
     */
    private function candidates(array $terms, array $postTypes, array $exclude, ?int $userId): array
    {
        $found = array();

        foreach ($terms as $term) {
            $query = new ContentQuery(
                types: $postTypes,
                statuses: array( 'publish' ),
                search: $term,
                exclude: $exclude,
                perPage: self::CANDIDATES_PER_TERM,
                userId: $userId,
            );

            foreach ($this->content->query($query)->items as $item) {
                if (! $item instanceof ContentItem || isset($found[ $item->id ])) {
                    continue;
                }

                $found[ $item->id ] = $item;

                if (\count($found) >= self::MAX_CANDIDATES) {
                    return \array_values($found);
                }
            }
        }

        return \array_values($found);
    }

    /**
     * Keep only candidates sharing at least one term with the source.
     *
     * The WordPress original applied the category filter in `WP_Query` and then
     * handed every surviving row a constant score. Filtering here keeps the result
     * set the same and lets the scoring say how much they actually share.
     *
     * @param list<ContentItem> $candidates
     *
     * @return list<ContentItem>
     */
    private function sharingTerms(array $candidates, ?ContentItem $source): array
    {
        $wanted = \array_map('mb_strtolower', $this->sourceTerms($source));

        if (array() === $wanted) {
            return array();
        }

        $kept = array();

        foreach ($candidates as $candidate) {
            $theirs = \array_map('mb_strtolower', $this->sourceTerms($candidate));

            if (array() !== \array_intersect($wanted, $theirs)) {
                $kept[] = $candidate;
            }
        }

        return $kept;
    }

    /**
     * The last seven days, newest first.
     *
     * This is what WordPress's `trending` mode actually computes: it ranks on an
     * engagement meta value that only its own `track_interaction` action writes, so
     * without that history the formula collapses to recency over a seven-day window.
     * Reporting `strategy: recency` keeps a caller from reading popularity into it.
     */
    private function trending(string $type, array $postTypes, array $exclude, int $limit, ?int $userId): mixed
    {
        $query = new ContentQuery(
            types: $postTypes,
            statuses: array( 'publish' ),
            exclude: $exclude,
            orderBy: 'date',
            order: 'DESC',
            perPage: self::MAX_CANDIDATES,
            userId: $userId,
        );

        $items = array();

        foreach ($this->content->query($query)->items as $item) {
            if (! $item instanceof ContentItem) {
                continue;
            }

            $days = $this->ageDays($item);

            if ($days > self::TRENDING_WINDOW_DAYS) {
                continue;
            }

            $items[] = array(
                'item'     => $item,
                'recency'  => \max(0.0, 1.0 - ( $days / self::TRENDING_WINDOW_DAYS )),
                'age_days' => $days,
            );
        }

        if (array() === $items) {
            return $this->empty(
                \sprintf(
                    'Nothing has been published in the last %d days.',
                    self::TRENDING_WINDOW_DAYS
                ),
                null,
                $type
            );
        }

        \usort(
            $items,
            static fn (array $a, array $b): int => $b['recency'] <=> $a['recency'] ?: $a['item']->id <=> $b['item']->id
        );

        $recommendations = array();

        foreach (\array_slice($items, 0, $limit) as $row) {
            $recommendations[] = $this->format(
                $row['item'],
                \round($row['recency'], 3),
                'trending',
                array(
                    'recency'  => \round($row['recency'], 3),
                    'age_days' => \round($row['age_days'], 1),
                )
            );
        }

        $data = array(
            'recommendations'       => $recommendations,
            'count'                 => \count($recommendations),
            'recommendation_type'   => $type,
            'strategy'              => 'recency',
            'candidates_considered' => \count($items),
            'window_days'           => self::TRENDING_WINDOW_DAYS,
        );

        if ('personalized' === $type) {
            // WordPress does exactly this when a user has no reading history, which
            // on a host without an interaction store is every user. Say so.
            $data['fallback'] = 'trending';
            $data['fallback_reason'] = 'This host records no reading history, so personalized ranking is the recency fallback.';
        }

        return $this->success(
            \sprintf(
                '%d recommendation%s, ranked by recency over %d days.',
                \count($recommendations),
                1 === \count($recommendations) ? '' : 's',
                self::TRENDING_WINDOW_DAYS
            ),
            $data
        );
    }

    /**
     * Score, sort, truncate.
     *
     * @param list<ContentItem>                                           $candidates
     * @param array{item: ContentItem|null, title: string, text: string}   $source
     * @param list<string>                                                $derived
     */
    private function rank(array $candidates, array $source, string $type, array $derived, int $limit): array
    {
        $scored = array();

        foreach ($candidates as $candidate) {
            $signals = $this->signals($source, $candidate);

            $scored[] = array(
                'item'    => $candidate,
                'score'   => $this->score($signals),
                'signals' => $signals,
            );
        }

        \usort(
            $scored,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['item']->id <=> $b['item']->id
        );

        $recommendations = array();

        foreach (\array_slice($scored, 0, $limit) as $row) {
            $recommendations[] = $this->format(
                $row['item'],
                \round($row['score'], 3),
                'category_based' === $type ? 'category' : 'similarity',
                $row['signals']
            );
        }

        return $this->success(
            \sprintf(
                '%d recommendation%s from %d candidate%s.',
                \count($recommendations),
                1 === \count($recommendations) ? '' : 's',
                \count($candidates),
                1 === \count($candidates) ? '' : 's'
            ),
            array(
                'recommendations'       => $recommendations,
                'count'                 => \count($recommendations),
                'recommendation_type'   => $type,
                'strategy'              => 'similarity',
                'terms'                 => $derived,
                'candidates_considered' => \count($candidates),
            )
        );
    }

    /**
     * The individual overlap ratios that make up the score.
     *
     * Reported per recommendation so a caller can see why one row outranked another
     * instead of trusting an unexplained number.
     *
     * @param array{item: ContentItem|null, title: string, text: string} $source
     *
     * @return array<string, float>
     */
    private function signals(array $source, ContentItem $candidate): array
    {
        if (null === $source['item']) {
            // Nothing stored to compare terms with, so shared words and a shared
            // title are the honest signals.
            return array(
                'text'  => \round($this->jaccard($source['text'], $this->text($candidate)), 3),
                'title' => \round($this->jaccard($source['title'], $candidate->title), 3),
            );
        }

        $sourceTerms = $this->sourceTerms($source['item']);

        if (array() === $sourceTerms) {
            // The WordPress formula would score this zero on both term components and
            // leave the order to the store. Text is the signal that remains.
            return array(
                'text'  => \round($this->jaccard($source['text'], $this->text($candidate)), 3),
                'title' => \round($this->jaccard($source['title'], $candidate->title), 3),
            );
        }

        return array(
            'taxonomy' => \round($this->overlap($source['item'], $candidate, false), 3),
            'tag'      => \round($this->overlap($source['item'], $candidate, true), 3),
            'title'    => \round($this->jaccard($source['title'], $candidate->title), 3),
        );
    }

    /**
     * @param array<string, float> $signals
     */
    private function score(array $signals): float
    {
        if (isset($signals['taxonomy'])) {
            return \min(
                1.0,
                self::W_TAXONOMY * $signals['taxonomy']
                + self::W_TAG * $signals['tag']
                + self::W_TITLE * $signals['title']
            );
        }

        return \min(1.0, self::W_TEXT * $signals['text'] + self::W_TITLE * $signals['title']);
    }

    /**
     * WordPress's overlap coefficient: shared terms over the larger term count.
     *
     * The bigger set in the denominator means a broad tag does not score 1.0 against
     * every item that carries it.
     */
    private function overlap(ContentItem $source, ContentItem $candidate, bool $tags): float
    {
        $a = $this->termsIn($source, $tags);
        $b = $this->termsIn($candidate, $tags);

        if (array() === $a || array() === $b) {
            return 0.0;
        }

        return \count(\array_intersect($a, $b)) / \max(\count($a), \count($b));
    }

    /**
     * @return list<string>
     */
    private function termsIn(ContentItem $item, bool $tags): array
    {
        $names = array();

        foreach ($item->taxonomy as $taxonomy => $termNames) {
            if (\in_array(\mb_strtolower((string) $taxonomy), self::TAG_TAXONOMIES, true) !== $tags) {
                continue;
            }

            foreach ($termNames as $name) {
                if (\is_string($name) && '' !== \trim($name)) {
                    $names[] = \mb_strtolower(\trim($name));
                }
            }
        }

        return \array_values(\array_unique($names));
    }

    /**
     * Jaccard similarity over the topic words of two strings.
     *
     * Replaces WordPress's `similar_text()` on titles, which counts matching
     * characters rather than matching words: two unrelated titles of equal length
     * share most of the alphabet and score far too high.
     */
    private function jaccard(string $a, string $b): float
    {
        $left  = \array_keys($this->terms->rank($a));
        $right = \array_keys($this->terms->rank($b));

        if (array() === $left || array() === $right) {
            return 0.0;
        }

        $union = \count(\array_unique(\array_merge($left, $right)));

        return \count(\array_intersect($left, $right)) / $union;
    }

    private function text(ContentItem $item): string
    {
        return $item->title . ' ' . ($item->excerpt ?? '') . ' ' . $item->content;
    }

    private function ageDays(ContentItem $item): float
    {
        $seconds = \time() - $item->createdAt->getTimestamp();

        return \max(0.0, $seconds / self::SECONDS_PER_DAY);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(ContentItem $item, float $score, string $reason, array $signals): array
    {
        return array(
            'id'         => $item->id,
            'title'      => $item->title,
            'type'       => $item->type,
            'slug'       => $item->slug,
            'excerpt'    => null !== $item->excerpt && '' !== $item->excerpt
                ? \mb_substr($item->excerpt, 0, 300)
                : \mb_substr($item->content, 0, 300),
            'created_at' => $item->createdAt->format('c'),
            'score'      => $score,
            'reason'     => $reason,
            'signals'    => $signals,
        );
    }

    /**
     * @param int|null $sourceId The stored source id, or null when the source was
     *                           inline text (or the mode has no source at all).
     */
    private function empty(string $message, ?int $sourceId, string $type): array
    {
        return $this->success($message, array(
            'recommendations'       => array(),
            'count'                 => 0,
            'recommendation_type'   => $type,
            'strategy'              => 'similar_content' === $type || 'category_based' === $type ? 'similarity' : 'recency',
            'terms'                 => array(),
            'candidates_considered' => 0,
            'source'                => $sourceId ?? 'inline',
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function postTypes(array $arguments): array
    {
        $types = array();

        foreach ($this->stringListParam($arguments, 'include_post_types') as $type) {
            $type = \trim($type);

            if ('' !== $type) {
                $types[] = $type;
            }
        }

        if (array() === $types) {
            // WordPress defaults to `post` alone. Widened here because the Laravel
            // content model holds pages too, and a page recommending only posts is
            // the less useful half of the same store.
            $types = array( 'post', 'page' );
        }

        return \array_values(\array_unique($types));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<int>
     */
    private function excludeIds(array $arguments, ?ContentItem $source): array
    {
        $exclude = array();

        foreach ($this->intListParam($arguments, 'exclude_posts') as $id) {
            if ($id > 0) {
                $exclude[] = $id;
            }
        }

        if (null !== $source) {
            // Recommending the page to itself is never useful.
            $exclude[] = $source->id;
        }

        return \array_values(\array_unique($exclude));
    }
}
