<?php

/**
 * List task-plan templates with filters and usage statistics.
 *
 * Port of `WP_MCP_AI_Pro_Tool_List_Templates` (Proposal 164 section 164.79).
 * Reads `mcp_task_template` content items; the filters and the response shape
 * are WordPress's, with the sorting defect corrected.
 *
 * ## Corrected
 *
 * - **`usage_count` and `success_rate` sorting worked in name only.** The
 *   WordPress CPT query set `orderby => meta_value_num` without a `meta_key`,
 *   so neither field ordered the result. The port fetches the filtered set and
 *   sorts by the field the caller named.
 * - **`total_count` is the matching total**, not the number of rows on the
 *   returned page. WordPress reported the page size, which reads as "there are
 *   20 templates" on a library of 60.
 * - **A typo is refused, not silently reinterpreted.** An unknown category,
 *   status, sort field or order answers a validation error naming the value;
 *   WordPress's filter map fell back to a different field or returned an empty
 *   page that looked like an empty library.
 * - **`truncated` is reported.** The store can sort only `date`, `title`,
 *   `modified` and `id`, so the filtered set is fetched (at most 200) and
 *   sorted in PHP; `truncated` says when the 200-row ceiling was reached.
 *
 * ## Pinned
 *
 * - **`archived` means the item's `private` status**, as WordPress maps it.
 * - **`category_counts` covers the fetched set**, not only the returned page,
 *   while `templates` is cut to `limit`.
 * - **A non-positive `limit` falls back to 20.** WordPress's zero fell through
 *   to the host's posts-per-page option, which is not a number this tool can
 *   know.
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
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\ValueObject\TaskTemplateCategory;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class ListTemplatesTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const TEMPLATE_TYPE = 'mcp_task_template';

    /** How many rows the filtered set is fetched with before sorting in PHP. */
    private const FETCH_LIMIT = 200;

    private const DEFAULT_LIMIT = 20;

    private const STATUSES = array('all', 'draft', 'published', 'archived');

    private const SORTS = array('name', 'usage_count', 'success_rate', 'created', 'modified');

    /**
     * Domain status → the content item's status.
     *
     * @var array<string, string>
     */
    private const STATUS_MAP = array(
        'draft'     => 'draft',
        'published' => 'publish',
        'archived'  => 'private',
    );

    /**
     * Content item status → the domain status.
     *
     * @var array<string, string>
     */
    private const REVERSE_STATUS_MAP = array(
        'draft'   => 'draft',
        'publish' => 'published',
        'private' => 'archived',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'list_templates';
    }

    public function getName(): string
    {
        return 'List Templates';
    }

    public function getDescription(): string
    {
        return 'Lists available task plan templates with filtering by category, status, and sorting options. Shows usage statistics, placeholders and template metadata.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'category' => array(
                    'type'        => 'string',
                    'enum'        => TaskTemplateCategory::filterValues(),
                    'description' => 'Filter by category (default: all).',
                ),
                'status'   => array(
                    'type'        => 'string',
                    'enum'        => self::STATUSES,
                    'description' => 'Filter by status (default: all).',
                ),
                'sort_by'  => array(
                    'type'        => 'string',
                    'enum'        => self::SORTS,
                    'description' => 'Sort templates by field (default: usage_count).',
                ),
                'order'    => array(
                    'type'        => 'string',
                    'enum'        => array('asc', 'desc'),
                    'description' => 'Sort order (default: desc).',
                ),
                'limit'    => array(
                    'type'        => 'integer',
                    'description' => 'Maximum number of templates to return (default: 20, max: 200).',
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'idempotent');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $category = '' === $this->stringParam($arguments, 'category')
            ? 'all'
            : $this->stringParam($arguments, 'category');
        $status   = '' === $this->stringParam($arguments, 'status')
            ? 'all'
            : $this->stringParam($arguments, 'status');
        $sortBy   = '' === $this->stringParam($arguments, 'sort_by')
            ? 'usage_count'
            : $this->stringParam($arguments, 'sort_by');
        $order    = strtolower($this->stringParam($arguments, 'order'));

        if ('' === $order) {
            $order = 'desc';
        }

        $limit = abs($this->intParam($arguments, 'limit', self::DEFAULT_LIMIT));

        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        $limit = min($limit, self::FETCH_LIMIT);

        $errors = array();

        if ('all' !== $category && ! TaskTemplateCategory::isValid($category)) {
            $errors['category'] = array('Unknown category.');
        }

        if (! in_array($status, self::STATUSES, true)) {
            $errors['status'] = array('Status must be all, draft, published or archived.');
        }

        if (! in_array($sortBy, self::SORTS, true)) {
            $errors['sort_by'] = array('Sort field must be name, usage_count, success_rate, created or modified.');
        }

        if (! in_array($order, array('asc', 'desc'), true)) {
            $errors['order'] = array('Order must be asc or desc.');
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The template filter is not valid.', $errors);
        }

        $statuses = 'all' === $status
            ? array_values(self::STATUS_MAP)
            : array(self::STATUS_MAP[ $status ]);

        $metaQuery = array();

        if ('all' !== $category) {
            $metaQuery[] = array(
                'key'     => 'category',
                'value'   => $category,
                'compare' => '=',
            );
        }

        $collection = $this->content->query(
            new ContentQuery(
                types: array(self::TEMPLATE_TYPE),
                statuses: $statuses,
                metaQuery: $metaQuery,
                orderBy: 'date',
                order: 'DESC',
                page: 1,
                perPage: self::FETCH_LIMIT,
            )
        );

        $all = array_values(
            array_filter(
                $collection->items,
                static fn (ContentItem $item): bool => self::TEMPLATE_TYPE === $item->type
            )
        );

        usort(
            $all,
            function (ContentItem $left, ContentItem $right) use ($sortBy, $order): int {
                $comparison = $this->compare($left, $right, $sortBy);

                if (0 === $comparison) {
                    $comparison = strcasecmp($left->title, $right->title);
                }

                if (0 === $comparison) {
                    $comparison = $left->id <=> $right->id;
                }

                return 'asc' === $order ? $comparison : -$comparison;
            }
        );

        $categoryCounts = array();

        foreach ($all as $item) {
            $name = $this->categoryOf($item);
            $categoryCounts[ $name ] = ( $categoryCounts[ $name ] ?? 0 ) + 1;
        }

        $templates = array();

        foreach (array_slice($all, 0, $limit) as $item) {
            $templates[] = $this->format($item);
        }

        return $this->success(
            sprintf('Found %d template(s).', count($templates)),
            array(
                'templates'       => $templates,
                'total_count'     => $collection->total,
                'category_counts' => $categoryCounts,
                'filters'         => array(
                    'category' => $category,
                    'status'   => $status,
                    'sort_by'  => $sortBy,
                    'order'    => $order,
                ),
                'truncated'       => $collection->total > count($all),
                'storage_type'    => 'content_store',
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function format(ContentItem $item): array
    {
        return array(
            'template_id'         => $item->id,
            'template_name'       => $item->title,
            'description'         => (string) ( $item->excerpt ?? '' ),
            'category'            => $this->categoryOf($item),
            'status'              => self::REVERSE_STATUS_MAP[ $item->status ] ?? 'draft',
            'version'             => $this->nonEmpty($item->meta['version'] ?? '') ? (string) $item->meta['version'] : '1.0.0',
            'usage_count'         => (int) ( $item->meta['usage_count'] ?? 0 ),
            'success_rate'        => (float) ( $item->meta['success_rate'] ?? 0 ),
            'avg_completion_time' => (int) ( $item->meta['avg_completion_time'] ?? 0 ),
            'tags'                => $this->tagsOf($item),
            'placeholders'        => TaskPlanMarkdown::placeholders($item->content),
            'author_id'           => $item->authorId,
            'created'             => $item->createdAt->format('c'),
            'modified'            => $item->updatedAt->format('c'),
        );
    }

    private function categoryOf(ContentItem $item): string
    {
        $category = $this->nonEmpty($item->meta['category'] ?? '')
            ? (string) $item->meta['category']
            : TaskTemplateCategory::DEFAULT;

        return $category;
    }

    /**
     * Tags stored as a list, or as the comma-separated CCT form.
     *
     * @return list<string>
     */
    private function tagsOf(ContentItem $item): array
    {
        $raw = $item->meta['tags'] ?? array();

        if (is_string($raw)) {
            $raw = explode(', ', $raw);
        }

        if (! is_array($raw)) {
            return array();
        }

        $tags = array();

        foreach ($raw as $tag) {
            if (is_scalar($tag) && '' !== (string) $tag) {
                $tags[] = (string) $tag;
            }
        }

        return $tags;
    }

    private function compare(ContentItem $left, ContentItem $right, string $sortBy): int
    {
        return match ($sortBy) {
            'name'         => strcasecmp($left->title, $right->title),
            'usage_count'  => (int) ( $left->meta['usage_count'] ?? 0 ) <=> (int) ( $right->meta['usage_count'] ?? 0 ),
            'success_rate' => (float) ( $left->meta['success_rate'] ?? 0 ) <=> (float) ( $right->meta['success_rate'] ?? 0 ),
            'modified'     => $left->updatedAt->getTimestamp() <=> $right->updatedAt->getTimestamp(),
            default        => $left->createdAt->getTimestamp() <=> $right->createdAt->getTimestamp(),
        };
    }

    private function nonEmpty(mixed $value): bool
    {
        return is_scalar($value) && '' !== Text::singleLine((string) $value);
    }
}
