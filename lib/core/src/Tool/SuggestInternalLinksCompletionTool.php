<?php

/**
 * Tool: suggest_internal_links — find pages this one should link to.
 *
 * `SuggestInternalLinksCompletionTool` because `SuggestInternalLinksTool` is the
 * quarantined prompt-builder answering to `suggest_internal_links_prompt`.
 *
 * ## Retrieval first, model second
 *
 * A model asked "what should this page link to?" invents plausible titles and
 * URLs. So it is never asked that. The candidates come from the content store —
 * real rows, real ids, scoped to what the calling user may read — and the model's
 * only job is to pick from that list and write the anchor text and the reason.
 *
 * **An id the model returns that is not in the candidate list is dropped**, and
 * the count is reported. That is the check that makes the tool safe to act on: a
 * hallucinated id would otherwise become a link to a page that does not exist, and
 * the caller has no way to tell it apart from a real one.
 *
 * ## Terms
 *
 * The search needs terms, and the tool derives them locally rather than spending a
 * second model call to guess them: words of four characters or more, stopwords
 * removed, ranked by frequency. A caller who knows the topic can override the
 * derivation with `query`, which is the escape hatch for a page whose title and
 * body do not name the thing it is about.
 *
 * ## An empty result is a finding
 *
 * A page whose topic nothing else covers has no internal links to suggest. That is
 * a normal answer, not an error, and the payload says so with an empty
 * `suggestions` list and the number of candidates that were considered.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Provider\JsonPayload;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Service\Text\TopicTerms;

final class SuggestInternalLinksCompletionTool extends AbstractCompletionTool
{
    /**
     * How much of the body is sent. Enough to establish the topic; a tool call is
     * not a full-text index.
     */
    private const MAX_CONTENT_FOR_PROMPT = 6000;

    private const MAX_TERMS = 3;

    private const CANDIDATES_PER_TERM = 10;

    private const MAX_CANDIDATES = 20;

    private const MAX_SUGGESTIONS = 10;

    private readonly TopicTerms $topics;

    public function __construct(
        ErrorFactoryInterface $errors,
        ?ProviderRouter $providers = null,
        private readonly ?ContentStoreInterface $content = null,
        ?TopicTerms $topics = null,
    ) {
        parent::__construct($errors, $providers);

        $this->topics = $topics ?? new TopicTerms();
    }

    public function getSlug(): string
    {
        return 'suggest_internal_links';
    }

    public function getName(): string
    {
        return 'Suggest Internal Links';
    }

    public function getDescription(): string
    {
        return 'Suggests internal links from a page to related content that already exists, with the anchor text and the reason each is relevant. Candidates are retrieved from the content store first, so a suggestion always names a real page. Returns nothing when the topic is not covered elsewhere.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'content'          => array(
                    'type'        => 'string',
                    'description' => 'The text to find links for. HTML is stripped before it reaches the model.',
                    'maxLength'   => 100000,
                ),
                'title'            => array(
                    'type'        => 'string',
                    'description' => 'The page\'s title, when it has one.',
                    'maxLength'   => 300,
                ),
                'query'            => array(
                    'type'        => 'string',
                    'description' => 'Search terms to use instead of deriving them from the content.',
                    'maxLength'   => 300,
                ),
                'max_suggestions'  => array(
                    'type'        => 'integer',
                    'description' => 'How many links to return at most.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_SUGGESTIONS,
                    'default'     => 5,
                ),
                'min_relevance'    => array(
                    'type'        => 'number',
                    'description' => 'Discard suggestions the model scores below this (0-1).',
                    'minimum'     => 0,
                    'maximum'     => 1,
                    'default'     => 0.6,
                ),
                'post_types'       => array(
                    'type'        => 'array',
                    'description' => 'Content types to search. Defaults to posts and pages.',
                    'items'       => array( 'type' => 'string' ),
                    'maxItems'    => 10,
                ),
                'exclude_posts'    => array(
                    'type'        => 'array',
                    'description' => 'Ids to leave out, in addition to the page itself.',
                    'items'       => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'maxItems'    => 50,
                ),
            ) + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.'),
            ),
            'required'             => array( 'content' ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getCapabilityFlags(): array
    {
        return array(
            'read-only',
            'external-api',
            'network-dependent',
            'consumes-tokens',
            'requires-credentials',
            'model-dependent',
            'rate-limited',
            'requires-capability',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $content = $this->stringParam($arguments, 'content');

        if ('' === $content) {
            return $this->errors->validationFailed(
                'Some content is required.',
                array( 'content' => 'Required.' )
            );
        }

        if (null === $this->content) {
            return $this->errors->create(
                'content_store_unavailable',
                'This host has no content store, so there is nothing to link to.'
            );
        }

        $title    = \mb_substr($this->stringParam($arguments, 'title'), 0, 300);
        $override = \mb_substr($this->stringParam($arguments, 'query'), 0, 300);

        $terms = '' !== $override ? array( $override ) : $this->terms($content, $title);

        if (array() === $terms) {
            // Nothing to search for is a finding, not a failure: a page of
            // one-letter words genuinely has no topic to link on.
            return $this->emptySuggestions('No searchable topic could be derived from the content.');
        }

        $candidates = $this->candidates($terms, $arguments, $context);

        if (array() === $candidates) {
            return $this->emptySuggestions(
                \sprintf('Nothing in the content store matches %s.', \implode(', ', $terms))
            );
        }

        $answer = $this->completeTool($arguments, $this->prompt($content, $title, $candidates));

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        $parsed = JsonPayload::decode($answer['text']);

        if (null === $parsed) {
            return $this->errors->create(
                'unparseable_response',
                'The model\'s suggestions could not be read as JSON: ' . \mb_substr(\trim($answer['text']), 0, 300)
            );
        }

        $maxSuggestions = \max(1, \min(self::MAX_SUGGESTIONS, $this->intParam($arguments, 'max_suggestions', 5)));
        $minRelevance   = $this->floatParam($arguments, 'min_relevance', 0.6);

        $suggestions = array();
        $dropped     = 0;

        foreach (\is_array($parsed['suggestions'] ?? null) ? $parsed['suggestions'] : array() as $row) {
            if (! \is_array($row)) {
                continue;
            }

            $id = \is_numeric($row['content_id'] ?? null) ? (int) $row['content_id'] : 0;

            // The check that makes the output trustworthy: a model can invent an
            // id, and a link to a page that does not exist looks exactly like a
            // link to one that does.
            if (! isset($candidates[ $id ])) {
                ++$dropped;

                continue;
            }

            $relevance = JsonPayload::confidence($row['relevance'] ?? null, 0.0);

            if ($relevance < $minRelevance) {
                continue;
            }

            $suggestions[] = array(
                'content_id'  => $id,
                'title'       => $candidates[ $id ],
                'anchor_text' => \mb_substr(\trim((string) ($row['anchor_text'] ?? '')), 0, 120),
                'reason'      => \mb_substr(\trim((string) ($row['reason'] ?? '')), 0, 500),
                'relevance'   => $relevance,
            );

            if (\count($suggestions) >= $maxSuggestions) {
                break;
            }
        }

        return $this->success(
            \sprintf(
                '%d internal link%s from %d candidate%s%s.',
                \count($suggestions),
                1 === \count($suggestions) ? '' : 's',
                \count($candidates),
                1 === \count($candidates) ? '' : 's',
                $dropped > 0 ? \sprintf(' (%d invented id%s dropped)', $dropped, 1 === $dropped ? '' : 's') : ''
            ),
            array(
                'suggestions'           => $suggestions,
                'terms'                 => $terms,
                'candidates_considered' => \count($candidates),
                'dropped'               => $dropped,
                'provider'              => $answer['provider'],
                'model'                 => $answer['model'],
                'usage'                 => $answer['usage'],
            )
        );
    }

    /**
     * Rank the words of the content, dropping the ones that carry no topic.
     *
     * The derivation itself lives in `TopicTerms` because the recommendation
     * engine needs the same rule; two copies would drift.
     *
     * @return list<string>
     */
    private function terms(string $content, string $title): array
    {
        return $this->topics->top($title . ' ' . $content, self::MAX_TERMS);
    }

    /**
     * Real rows the model is allowed to choose from.
     *
     * @param list<string>         $terms
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array<int, string> Id → title.
     */
    private function candidates(array $terms, array $arguments, array $context): array
    {
        $types = array();

        foreach (\is_array($arguments['post_types'] ?? null) ? $arguments['post_types'] : array() as $type) {
            if (\is_string($type) && '' !== \trim($type)) {
                $types[] = \trim($type);
            }
        }

        if (array() === $types) {
            $types = array( 'post', 'page' );
        }

        $exclude = array();

        foreach (\is_array($arguments['exclude_posts'] ?? null) ? $arguments['exclude_posts'] : array() as $id) {
            if (\is_numeric($id) && (int) $id > 0) {
                $exclude[] = (int) $id;
            }
        }

        $userId = (int) ($context['user_id'] ?? $context['owner_id'] ?? 0);

        $found = array();

        foreach ($terms as $term) {
            $query = new ContentQuery(
                types: $types,
                statuses: array( 'publish' ),
                search: $term,
                exclude: $exclude,
                perPage: self::CANDIDATES_PER_TERM,
                userId: $userId > 0 ? $userId : null,
            );

            foreach ($this->content->query($query)->items as $item) {
                if (! $item instanceof ContentItem || isset($found[ $item->id ])) {
                    continue;
                }

                $found[ $item->id ] = $item->title;

                if (\count($found) >= self::MAX_CANDIDATES) {
                    return $found;
                }
            }
        }

        return $found;
    }

    /**
     * @param array<int, string> $candidates
     */
    private function prompt(string $content, string $title, array $candidates): string
    {
        $body    = \mb_substr($content, 0, self::MAX_CONTENT_FOR_PROMPT);
        $listing = array();

        foreach ($candidates as $id => $candidateTitle) {
            $listing[] = \sprintf('- id %d: %s', $id, $candidateTitle);
        }

        return \sprintf(
            "Choose which of the existing pages below this page should link to.\n\nRules:\n- Only use ids from the list. Never invent one.\n- Only suggest a link where the target genuinely helps a reader of this page.\n- Suggesting nothing is a valid answer.\n- anchor_text is the words that would be linked, taken from the page's own topic — not the target's title.\n- relevance is 0-1: how likely a reader of this page wants that page next.\n\nReply with a JSON object only, no prose and no fence:\n{\"suggestions\": [{\"content_id\": int, \"anchor_text\": string, \"reason\": string, \"relevance\": number}]}\n\nThis page's title: %s\n\nThis page's text:\n%s\n\nExisting pages:\n%s",
            '' === $title ? '(untitled)' : $title,
            $body,
            \implode("\n", $listing)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySuggestions(string $message): array
    {
        return $this->success($message, array(
            'suggestions'           => array(),
            'terms'                 => array(),
            'candidates_considered' => 0,
            'dropped'               => 0,
        ));
    }
}
