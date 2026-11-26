<?php

/**
 * Create a draft post from a research record or raw research data.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Create_Post_From_Research` (Proposal 164 section
 * 164.81). The source is either a Paper Store record or an inline `data`
 * object; the destination is `ContentStoreInterface`.
 *
 * ## What the port has to translate
 *
 * - **The category.** WordPress took a `category_id` and handed the integer to
 *   `wp_insert_post`. The core's content contract names terms, so the id is
 *   resolved through `listTaxonomyTerms('category')` first and an unknown id is
 *   a validation error. Passing the number through would create a term
 *   literally named "12", which is worse than refusing.
 * - **The author.** WordPress demoted an author who lacked `edit_posts` to the
 *   caller. The core has no user-capability evaluator, so a positive
 *   `author_id` is trusted and only an absent one falls back to the caller.
 * - **The body.** WordPress ran `wp_kses_post()`. The core has no kses and the
 *   same is true of `create_post`: content policy belongs to the store and the
 *   renderer, so the research body is stored as supplied. A shortcode or block
 *   body must not be tag-stripped on the way in.
 * - **`edit_url` / `permalink`.** Present and `null`: the core knows no admin or
 *   permalink route, and keeping the keys preserves the response shape.
 *
 * ## One correction
 *
 * When `update_paper_status` marked the record published, WordPress saved a
 * fresh record carrying only id/title/type/status/description/meta — which
 * **erased the stored body and tags**, because the Paper Store `save()` is a
 * full replace. The port merges the status and meta into the record it read,
 * so the report survives being marked published.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class CreatePostFromResearchTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** @var list<string> */
    private const POST_STATUSES = array('draft', 'pending');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ?PaperStoreInterface $paper = null,
        private readonly ?ContentStoreInterface $content = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'create_post_from_research';
    }

    public function getName(): string
    {
        return 'Create Post from Research';
    }

    public function getDescription(): string
    {
        return 'Creates a draft post from a Paper Store research record or raw research data. Converts stored research into a post with configurable type, status, category, tags, and author. Optionally marks the Paper Store record published after creation.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'paper_store_record_id'  => array(
                    'type'        => 'string',
                    'description' => 'Paper Store record ID to convert into a post. Required if "data" is not provided.',
                ),
                'paper_store_collection' => array(
                    'type'        => 'string',
                    'description' => 'Paper Store collection name containing the record. Required if paper_store_record_id is provided.',
                ),
                'data'                   => array(
                    'type'        => 'object',
                    'description' => 'Raw research data to convert (alternative to Paper Store record). Must include a "title" field. Can include "report" (post content), "description" (excerpt), and "sources".',
                ),
                'post_type'              => array(
                    'type'        => 'string',
                    'description' => 'Content type for the draft. Default: "post".',
                    'default'     => 'post',
                ),
                'post_status'            => array(
                    'type'        => 'string',
                    'enum'        => self::POST_STATUSES,
                    'description' => 'Post status. Use "draft" for private review or "pending" for editorial workflow.',
                    'default'     => 'draft',
                ),
                'category_id'            => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Category term ID to assign to the post.',
                ),
                'tags'                   => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Tags to assign to the post.',
                ),
                'author_id'              => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'User ID to set as the post author. Defaults to the calling user.',
                ),
                'update_paper_status'    => array(
                    'type'        => 'boolean',
                    'description' => 'Mark the Paper Store record "published" after successful creation.',
                    'default'     => false,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'publish_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('local-only', 'requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'publish_posts');

        if (null === $this->content) {
            return $this->errors->create(
                'content_store_unavailable',
                'This host has no content store, so it cannot create posts.'
            );
        }

        $userId     = (int) ( $context['user_id'] ?? 0 );
        $recordId   = Text::sanitizeKey($this->stringParam($arguments, 'paper_store_record_id'));
        $collection = Text::sanitizeKey($this->stringParam($arguments, 'paper_store_collection'));
        $raw        = isset($arguments['data']) && is_array($arguments['data']) ? $arguments['data'] : null;

        if ('' === $recordId && null === $raw) {
            return $this->errors->create(
                'missing_source',
                'Either "paper_store_record_id" (with "paper_store_collection") or "data" must be provided.'
            );
        }

        if (null === $raw && '' === $collection) {
            return $this->errors->create(
                'missing_collection',
                '"paper_store_collection" is required when using "paper_store_record_id".'
            );
        }

        $record = null;

        if (null === $raw) {
            if (null === $this->paper) {
                return $this->errors->create(
                    'paper_store_unavailable',
                    'This host has no Paper Store, so it cannot read research records.'
                );
            }

            $record = $this->paper->find($collection, $recordId);

            if (null === $record) {
                return $this->errors->create(
                    'record_not_found',
                    sprintf(
                        'Paper Store record "%s" not found in collection "%s".',
                        $recordId,
                        $collection
                    )
                );
            }
        }

        $source = null === $raw ? $record : $raw;

        $title = isset($source['title']) && is_scalar($source['title'])
            ? Text::singleLine((string) $source['title'])
            : '';
        $content = $this->body($source);
        $excerpt = isset($source['description']) && is_scalar($source['description'])
            ? Text::singleLine((string) $source['description'])
            : '';

        if ('' === $title) {
            return $this->errors->validationFailed(
                'The research data has no title.',
                array('title' => array('Research data must include a "title" field.'))
            );
        }

        if ('' === $content) {
            return $this->errors->validationFailed(
                'The research data has no body.',
                array('content' => array('Research data must include content (report, body.markdown, or body string).'))
            );
        }

        $postType   = Text::sanitizeKey($this->stringParam($arguments, 'post_type', 'post'));
        $postStatus = Text::sanitizeKey($this->stringParam($arguments, 'post_status', 'draft'));
        $postStatus = in_array($postStatus, self::POST_STATUSES, true) ? $postStatus : 'draft';
        $authorId   = max(0, $this->intParam($arguments, 'author_id', 0));

        if ($authorId <= 0) {
            $authorId = $userId;
        }

        $taxonomyInput = array();
        $categoryId    = max(0, $this->intParam($arguments, 'category_id', 0));

        if ($categoryId > 0) {
            $name = $this->categoryName($categoryId);

            if (null === $name) {
                return $this->errors->validationFailed(
                    'The category does not exist.',
                    array('category_id' => array(sprintf('No category term has id %d.', $categoryId)))
                );
            }

            $taxonomyInput['category'] = array($name);
        }

        if (isset($arguments['tags']) && is_array($arguments['tags'])) {
            $tags = array();

            foreach ($arguments['tags'] as $tag) {
                if (is_scalar($tag)) {
                    $clean = Text::singleLine((string) $tag);

                    if ('' !== $clean) {
                        $tags[] = $clean;
                    }
                }
            }

            if (array() !== $tags) {
                $taxonomyInput['post_tag'] = array_values(array_unique($tags));
            }
        }

        $command = new CreateContentCommand(
            title: $title,
            type: '' !== $postType ? $postType : 'post',
            authorId: $authorId,
            status: $postStatus,
            content: $content,
            excerpt: '' !== $excerpt ? $excerpt : null,
            taxonomyInput: $taxonomyInput,
        );

        try {
            $item = $this->content->create($command);
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (ValidationException $error) {
            return $this->errors->validationFailed(
                $error->getMessage(),
                $error->hasFieldErrors() ? $error->errors : array()
            );
        } catch (\Throwable $error) {
            return $this->errors->create('create_failed', $error->getMessage());
        }

        $paperStatus = null;

        if (null === $raw && null !== $record && $this->boolParam($arguments, 'update_paper_status', false)) {
            $paperStatus = $this->markPublished($collection, $recordId, $record, $item->id, $item->type);
        }

        return $this->success(
            sprintf('Draft post "%s" created with status "%s".', $item->title, $item->status),
            array(
                'post_id'             => $item->id,
                'post_type'           => $item->type,
                'post_status'         => $item->status,
                'title'               => $item->title,
                'edit_url'            => null,
                'permalink'           => null,
                'author_id'           => $item->authorId,
                'source'              => null === $raw ? 'paper_store' : 'raw_data',
                'paper_store_status'  => $paperStatus,
            )
        );
    }

    /**
     * The research body, in the WordPress precedence order.
     *
     * @param array<string, mixed> $source Record or raw data.
     */
    private function body(array $source): string
    {
        if (isset($source['report']) && is_scalar($source['report'])) {
            return Text::multiline((string) $source['report']);
        }

        $body = $source['body'] ?? null;

        if (is_array($body)) {
            foreach (array('markdown', 'report') as $key) {
                if (isset($body[ $key ]) && is_scalar($body[ $key ])) {
                    return Text::multiline((string) $body[ $key ]);
                }
            }

            return '';
        }

        return is_scalar($body) ? Text::multiline((string) $body) : '';
    }

    /**
     * Resolve a category id to the name the content contract understands.
     */
    private function categoryName(int $categoryId): ?string
    {
        if (null === $this->content) {
            return null;
        }

        foreach ($this->content->listTaxonomyTerms('category', 500) as $term) {
            if ((int) ( $term['id'] ?? 0 ) === $categoryId) {
                return (string) ( $term['name'] ?? '' );
            }
        }

        return null;
    }

    /**
     * Mark the record published while preserving its body and tags.
     *
     * @param array<string, mixed> $record The record as read.
     */
    private function markPublished(
        string $collection,
        string $recordId,
        array $record,
        int $postId,
        string $postType,
    ): ?string {
        if (null === $this->paper) {
            return null;
        }

        $meta = isset($record['meta']) && is_array($record['meta']) ? $record['meta'] : array();
        $meta['post_id']      = $postId;
        $meta['post_type']    = $postType;
        $meta['published_at'] = gmdate('c');

        $record['id']     = $recordId;
        $record['status'] = 'published';
        $record['meta']   = $meta;

        try {
            $this->paper->save($collection, $record);
        } catch (\Throwable $error) {
            // The post exists; a failed status flip must not read as a failed
            // publish. The caller sees the record's status unchanged.
            return null;
        }

        return 'published';
    }
}
