<?php

/**
 * Tool: auto_categorize_content — pick categories for a page from the real vocabulary.
 *
 * `AutoCategorizeContentCompletionTool` because `AutoCategorizeContentTool` is the
 * quarantined prompt-builder answering to `auto_categorize_content_prompt`.
 *
 * ## The model chooses from a list, not from its imagination
 *
 * The vocabulary comes from `ContentStoreInterface::listTaxonomyTerms()` — the terms
 * the site actually defines — and the model is asked to pick from it. An id it
 * returns that is not in the list is dropped and counted, for the same reason
 * `suggest_internal_links` drops invented ids: assigning a term that does not exist
 * would either fail or, worse, silently create a near-duplicate of one that does.
 *
 * An empty vocabulary is a finding, not a failure. There is nothing to choose from,
 * so no model call is spent saying so.
 *
 * ## Three things the WordPress original does that this does not
 *
 * - **`create_new` does nothing there.** The parameter is accepted and never read;
 *   `assign_categories()` only sets ids that already exist. It is not offered here,
 *   because a parameter with no reader is a rendered control with no behaviour.
 * - **`auto_assign` adds here, it does not replace.** WordPress calls
 *   `wp_set_object_terms( ..., $append = false )`, which removes every term the model
 *   did not name. This host's store merges taxonomy on update, and either way a
 *   model's suggestion should not silently delete curation a human made. The tool
 *   computes the union itself, so both hosts land on the same result.
 * - **It can be dry run.** `dry_run` reports what would be added and writes nothing,
 *   which is the safe way to look at a categorizer before trusting it.
 *
 * ## Confidence is the model's, not ours
 *
 * `min_confidence` filters on the number the model returned. That number is a claim,
 * not a measurement, so the payload names it as such by returning it per suggestion
 * alongside the term it refers to — a caller can sort and judge it, rather than
 * receiving a single opaque "relevant".
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
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

final class AutoCategorizeContentCompletionTool extends AbstractCompletionTool
{
    /**
     * How much of the body is sent. Enough to establish the subject; the task is
     * classification, not reading the whole archive.
     */
    private const MAX_CONTENT_FOR_PROMPT = 6000;

    /**
     * A vocabulary bigger than this stops being a closed list the model can weigh and
     * becomes a needle-in-a-haystack. Larger taxonomies need a retrieval step first,
     * which is a different tool.
     */
    private const MAX_VOCABULARY = 200;

    private const DEFAULT_MAX_CATEGORIES = 3;

    private const MAX_MAX_CATEGORIES = 10;

    private const DEFAULT_MIN_CONFIDENCE = 0.6;

    public function __construct(
        ErrorFactoryInterface $errors,
        ?ProviderRouter $providers = null,
        private readonly ?ContentStoreInterface $content = null,
    ) {
        parent::__construct($errors, $providers);
    }

    public function getSlug(): string
    {
        return 'auto_categorize_content';
    }

    public function getName(): string
    {
        return 'Auto-Categorize Content';
    }

    public function getDescription(): string
    {
        return 'Suggests categories or tags for a page by choosing from the terms the site already defines, with a confidence per choice, and can add the chosen terms to the page. Unknown ids are dropped. Adding never removes an existing term.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'post_id'        => array(
                    'type'        => 'integer',
                    'description' => 'Stored item to categorize. Provide this or inline content.',
                    'minimum'     => 1,
                ),
                'content'        => array(
                    'type'        => 'string',
                    'description' => 'Body text when the page is not stored. Provide this or post_id.',
                ),
                'title'          => array(
                    'type'        => 'string',
                    'description' => 'Title of the inline content.',
                ),
                'taxonomy'       => array(
                    'type'        => 'string',
                    'description' => 'Taxonomy to choose from.',
                    'default'     => 'category',
                ),
                'auto_assign'    => array(
                    'type'        => 'boolean',
                    'description' => 'Add the chosen terms to the page. Requires post_id. Existing terms are kept.',
                    'default'     => false,
                ),
                'dry_run'        => array(
                    'type'        => 'boolean',
                    'description' => 'With auto_assign, report what would be added and write nothing.',
                    'default'     => false,
                ),
                'min_confidence' => array(
                    'type'        => 'number',
                    'description' => 'Discard suggestions the model scores below this (0-1).',
                    'minimum'     => 0,
                    'maximum'     => 1,
                    'default'     => self::DEFAULT_MIN_CONFIDENCE,
                ),
                'max_categories' => array(
                    'type'        => 'integer',
                    'description' => 'How many terms to suggest at most.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_MAX_CATEGORIES,
                    'default'     => self::DEFAULT_MAX_CATEGORIES,
                ),
            ) + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.'),
            ),
            'required'             => array(),
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
            'write',
            'state-changing',
            'external-api',
            'network-dependent',
            'consumes-tokens',
            'requires-credentials',
            'model-dependent',
            'rate-limited',
            'non-deterministic',
            'requires-capability',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (null === $this->content) {
            return $this->errors->create(
                'content_store_unavailable',
                'This host has no content store, so there are no terms to choose from.'
            );
        }

        $taxonomy = \trim($this->stringParam($arguments, 'taxonomy', 'category'));

        if ('' === $taxonomy) {
            return $this->errors->validationFailed(
                'A taxonomy is required.',
                array( 'taxonomy' => 'Required.' )
            );
        }

        $userId = (int) ( $context['user_id'] ?? $context['owner_id'] ?? 0 );

        $source = $this->source($arguments, $userId > 0 ? $userId : null);

        if ($this->errors->isError($source)) {
            return $source;
        }

        $vocabulary = $this->vocabulary($taxonomy);

        if (array() === $vocabulary) {
            // Nothing to choose from: a finding, and no model call to say it.
            return $this->success(
                \sprintf('The %s taxonomy has no terms to choose from.', $taxonomy),
                array(
                    'suggestions'   => array(),
                    'taxonomy'      => $taxonomy,
                    'vocabulary'    => 0,
                    'dropped'       => 0,
                    'auto_assigned' => false,
                )
            );
        }

        $answer = $this->completeTool($arguments, $this->prompt($taxonomy, $source, $vocabulary));

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        $parsed = JsonPayload::decode($answer['text']);

        if (null === $parsed) {
            return $this->errors->create(
                'unparseable_response',
                'The model\'s categories could not be read as JSON: ' . \mb_substr(\trim($answer['text']), 0, 300)
            );
        }

        $minConfidence = $this->floatParam($arguments, 'min_confidence', self::DEFAULT_MIN_CONFIDENCE);
        $maxCategories = \max(
            1,
            \min(self::MAX_MAX_CATEGORIES, $this->intParam($arguments, 'max_categories', self::DEFAULT_MAX_CATEGORIES))
        );

        $suggestions = array();
        $dropped     = 0;

        foreach (\is_array($parsed['categories'] ?? null) ? $parsed['categories'] : array() as $row) {
            if (! \is_array($row)) {
                continue;
            }

            $id = \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;

            // A model can name an id that was never in the list. Assigning it would
            // fail, or invent a near-duplicate term, so it is dropped and counted.
            if (! isset($vocabulary[ $id ])) {
                ++$dropped;

                continue;
            }

            $confidence = JsonPayload::confidence($row['confidence'] ?? null, 0.0);

            if ($confidence < $minConfidence) {
                continue;
            }

            $suggestions[] = array(
                'id'         => $id,
                'name'       => $vocabulary[ $id ],
                'confidence' => $confidence,
            );
        }

        // Confidence descending, then name ascending so an equal-scoring pair cannot
        // swap places between runs.
        \usort(
            $suggestions,
            static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']
                ?: \strcmp((string) $a['name'], (string) $b['name'])
        );

        $suggestions = \array_slice($suggestions, 0, $maxCategories);

        $assignment = $this->assign($arguments, $source['item'], $taxonomy, $suggestions, $userId);

        if ($this->errors->isError($assignment)) {
            return $assignment;
        }

        return $this->success(
            \sprintf(
                '%d term%s suggested from %d available%s%s.',
                \count($suggestions),
                1 === \count($suggestions) ? '' : 's',
                \count($vocabulary),
                $dropped > 0 ? \sprintf(' (%d invented id%s dropped)', $dropped, 1 === $dropped ? '' : 's') : '',
                $assignment['auto_assigned'] ? ', added to the page' : ''
            ),
            array(
                'suggestions'   => $suggestions,
                'taxonomy'      => $taxonomy,
                'vocabulary'    => \count($vocabulary),
                'dropped'       => $dropped,
                'auto_assigned' => $assignment['auto_assigned'],
                'assigned'      => $assignment['assigned'],
                'already_present' => $assignment['already_present'],
                'dry_run'       => $assignment['dry_run'],
                'provider'      => $answer['provider'],
                'model'         => $answer['model'],
                'usage'         => $answer['usage'],
            )
        );
    }

    /**
     * Resolve what to categorize.
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
                return $this->errors->create(
                    'content_not_found',
                    \sprintf('No content with id %d is readable by this caller.', $postId)
                );
            }

            return array(
                'item'  => $item,
                'title' => $item->title,
                'text'  => $item->content,
            );
        }

        $content = $this->stringParam($arguments, 'content');

        if ('' === \trim($content)) {
            return $this->errors->validationFailed(
                'Provide a post_id or some content to categorize.',
                array( 'post_id' => 'Required when content is empty.', 'content' => 'Required when post_id is absent.' )
            );
        }

        return array(
            'item'  => null,
            'title' => \mb_substr($this->stringParam($arguments, 'title'), 0, 300),
            'text'  => $content,
        );
    }

    /**
     * The taxonomy's vocabulary as id → name.
     *
     * @return array<int, string>
     */
    private function vocabulary(string $taxonomy): array
    {
        $vocabulary = array();

        foreach ($this->content->listTaxonomyTerms($taxonomy, self::MAX_VOCABULARY) as $term) {
            $id   = (int) ( $term['id'] ?? 0 );
            $name = \trim((string) ( $term['name'] ?? '' ));

            if ($id > 0 && '' !== $name) {
                $vocabulary[ $id ] = $name;
            }
        }

        return $vocabulary;
    }

    /**
     * @param array{item: ContentItem|null, title: string, text: string} $source
     * @param array<int, string>                                         $vocabulary
     */
    private function prompt(string $taxonomy, array $source, array $vocabulary): string
    {
        $listing = array();

        foreach ($vocabulary as $id => $name) {
            $listing[] = \sprintf('- id %d: %s', $id, $name);
        }

        $body = \mb_substr($source['text'], 0, self::MAX_CONTENT_FOR_PROMPT);

        return \sprintf(
            "Choose which of the site's existing %s terms apply to the content below.\n\nRules:\n- Only use ids from the list. Never invent one.\n- Only choose a term that genuinely applies. Choosing none is a valid answer.\n- confidence is 0-1: how sure you are the term applies.\n\nReply with a JSON object only, no prose and no fence:\n{\"categories\": [{\"id\": int, \"confidence\": number}]}\n\nThe content's title: %s\n\nThe content:\n%s\n\nAvailable %s terms:\n%s",
            $taxonomy,
            '' === $source['title'] ? '(untitled)' : $source['title'],
            $body,
            $taxonomy,
            \implode("\n", $listing)
        );
    }

    /**
     * Add the chosen terms, or report what would be added.
     *
     * The union is computed here rather than delegated, because the two adapters
     * disagree: WordPress's `wp_set_post_terms()` replaces, Laravel's
     * `syncWithoutDetaching()` appends. Passing the full desired set means both
     * hosts reach the same state.
     *
     * @param array<string, mixed>                                                $arguments
     * @param list<array{id:int, name:string, confidence:float}>                   $suggestions
     *
     * @return array{auto_assigned: bool, assigned: list<string>, already_present: list<string>, dry_run: bool}
     */
    private function assign(
        array $arguments,
        ?ContentItem $item,
        string $taxonomy,
        array $suggestions,
        int $userId,
    ): array {
        $nothing = array(
            'auto_assigned'   => false,
            'assigned'        => array(),
            'already_present' => array(),
            'dry_run'         => false,
        );

        if (! $this->boolParam($arguments, 'auto_assign') || null === $item || array() === $suggestions) {
            return $nothing;
        }

        if ($userId < 1) {
            // Attribution is part of the write; an anonymous assignment is not one.
            return $this->errors->create(
                'user_required',
                'Assigning terms needs the calling user, and no user id was supplied.'
            );
        }

        $current = array();

        $terms   = $this->content->getTaxonomyTerms($item->id);
        $assigned = \is_array($terms[ $taxonomy ] ?? null) ? $terms[ $taxonomy ] : array();

        foreach ($assigned as $name) {
            if (\is_string($name) && '' !== \trim($name)) {
                $current[] = \trim($name);
            }
        }

        $chosen = \array_map(static fn (array $row): string => (string) $row['name'], $suggestions);

        $already = array_values(\array_intersect($chosen, $current));
        $added   = array_values(\array_diff($chosen, $current));

        if ($this->isDryRun($arguments)) {
            return array(
                'auto_assigned'   => false,
                'assigned'        => $added,
                'already_present' => $already,
                'dry_run'         => true,
            );
        }

        if (array() === $added) {
            // Everything chosen is already there. Writing the same set back would be
            // a no-op with a real cost: it touches the row and fires update hooks.
            return array(
                'auto_assigned'   => true,
                'assigned'        => array(),
                'already_present' => $already,
                'dry_run'         => false,
            );
        }

        $target = \array_values(\array_unique(\array_merge($current, $chosen)));

        try {
            $this->content->update(
                $item->id,
                new UpdateContentCommand(
                    userId: $userId,
                    taxonomyInput: array( $taxonomy => $target ),
                )
            );
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (NotFoundException $error) {
            return $this->errors->notFound($error->getMessage());
        }

        return array(
            'auto_assigned'   => true,
            'assigned'        => $added,
            'already_present' => $already,
            'dry_run'         => false,
        );
    }
}
