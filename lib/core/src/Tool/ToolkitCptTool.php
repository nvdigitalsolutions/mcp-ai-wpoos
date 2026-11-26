<?php

/**
 * Toolkit CPT — one discoverable surface for CRUD over typed toolkit records.
 *
 * `toolkit_cpt` is the WordPress Pro toolkit's generic entry point: the Pro
 * toolkits store their entities as custom post types, and this tool is how an
 * agent lists those types, learns their fields, finds records, and writes them
 * without knowing which toolkit owns which slug. On this runtime the same records
 * live in `ContentStoreInterface` as typed content items — `mcp_ai_project`,
 * `mcp_ai_task`, … — so the tool's job is unchanged: "CPT" here means *a
 * registered content type*, not a WordPress object.
 *
 * ## Why this is not just `save_post` under another name
 *
 * Several of the actions are already served under other slugs (`save_post` with
 * `post_type` and `meta_input`, `get_post`, `delete_post`, `get_post_type_schema`).
 * Three are not, and they are the reason this tool exists:
 *
 *  - **`list_types`** — nothing on this runtime enumerates the registered types.
 *    `get_post_type_schema` requires a type, so an agent that does not already
 *    know the slug has no way to discover it.
 *  - **`list_items` without a search term** — `search_content` requires `query`,
 *    forces `publish`, and cannot filter by meta. Listing "tasks assigned to 42"
 *    is not expressible there.
 *  - **`bulk_create` for any type** — `bulk_import` handles tasks and projects
 *    only.
 *
 * ## Refusals where WordPress is silent
 *
 * The reference implementation normalises rather than refuses: an unknown
 * `compare` becomes `=`, an unknown meta `type` becomes `CHAR`, and a meta key
 * is stripped of every character outside `[A-Za-z0-9_]`. Each of those turns a
 * caller's typo into a *different query* or a *differently named field*, and a
 * filter that silently means something else is worse than one that fails. So this
 * port validates and names the problem instead.
 *
 * `menu_order` and `rand` are absent from `orderby` for the same reason: the
 * store cannot order by them, and offering them would be an argument that is
 * accepted and then ignored.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\PostTypeSchema;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

class ToolkitCptTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Types that are machinery rather than toolkit data.
     *
     * The WordPress list is longer because WordPress registers more machinery;
     * only the members that can exist here are kept, plus `post` and `page`, which
     * the reference also skips as built-in.
     */
    private const PROTECTED_TYPES = array(
        'attachment',
        'revision',
        'post',
        'page',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
        'oembed_cache',
    );

    /** Field keys that map to the record itself; everything else is meta. */
    private const STANDARD_FIELDS = array('title', 'content', 'excerpt', 'status', 'date', 'author_id');

    /**
     * The compare operators the store's meta query implements.
     *
     * Mirrors `ContentStore`'s meta clause switch. An operator outside this list
     * would reach the adapter and throw a developer-facing exception, so it is
     * refused here with an explanation instead.
     */
    private const COMPARES = array(
        '=',
        '!=',
        '>',
        '>=',
        '<',
        '<=',
        'LIKE',
        'NOT LIKE',
        'IN',
        'NOT IN',
        'BETWEEN',
        'NOT BETWEEN',
        'EXISTS',
        'NOT EXISTS',
    );

    /** A meta key is a single JSON path segment in the store. */
    private const KEY_PATTERN = '/^[A-Za-z0-9_.-]+$/';

    /** Machine-written meta that would drown a record if returned. */
    private const NOISY_META = array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time');

    /** The store's documented page ceiling. */
    private const MAX_PER_PAGE = 100;

    /** A ceiling on one bulk call, so a runaway payload cannot become a timeout. */
    private const MAX_BULK = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly SchemaStoreInterface $schema,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'toolkit_cpt';
    }

    public function getName(): string
    {
        return 'Toolkit CPT';
    }

    public function getDescription(): string
    {
        return 'Query and manage toolkit records by content type. Use this for mcp_ai_project, mcp_ai_task and any other registered toolkit type when you need to list, read, create, update, delete or bulk-create records, or to discover what types and field keys exist. Workflow: 1) list_types to find the type; 2) get_schema to learn the exact field keys; 3) list_items with filters to find ids; 4) get_item, update_item or delete_item by id.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'action' => array(
                    'type' => 'string',
                    'description' => 'What to do. Call list_types first when you do not know the type, and get_schema before creating or updating so the field keys are exact.',
                    'enum' => array('list_types', 'get_schema', 'list_items', 'get_item', 'create_item', 'update_item', 'delete_item', 'bulk_create'),
                    'default' => 'list_types',
                ),
                'post_type' => array(
                    'type' => 'string',
                    'description' => 'Content type slug, e.g. mcp_ai_project or mcp_ai_task. Required for every action except list_types.',
                ),
                'item_id' => array(
                    'type' => 'integer',
                    'description' => 'Record id for get_item, update_item and delete_item.',
                    'minimum' => 1,
                ),
                'fields' => array(
                    'type' => 'object',
                    'description' => 'Field values for create_item or update_item. Standard keys: title, content, excerpt, status, author_id. Every other key is stored as meta under exactly the name given, so use the keys get_schema reports.',
                    'additionalProperties' => true,
                ),
                'items' => array(
                    'type' => 'array',
                    'description' => 'Records for bulk_create. Each element has the same shape as fields.',
                    'maxItems' => self::MAX_BULK,
                    'items' => array('type' => 'object', 'additionalProperties' => true),
                ),
                'search' => array(
                    'type' => 'string',
                    'description' => 'Free-text search across title and content for list_items.',
                    'maxLength' => 200,
                ),
                'filters' => array(
                    'type' => 'array',
                    'description' => 'Meta filters for list_items, combined with AND. Each entry: {"key": "_task_priority", "value": "high", "compare": "=", "type": "CHAR"}. EXISTS and NOT EXISTS take no value; IN and NOT IN take an array; BETWEEN takes exactly two values.',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'key' => array('type' => 'string'),
                            'value' => array(
                                'anyOf' => array(
                                    array('type' => 'string'),
                                    array('type' => 'number'),
                                    array('type' => 'boolean'),
                                    array('type' => 'null'),
                                    array('type' => 'array', 'items' => array('type' => 'string')),
                                ),
                            ),
                            'compare' => array('type' => 'string', 'enum' => self::COMPARES, 'default' => '='),
                            'type' => array(
                                'type' => 'string',
                                'description' => 'How the store should compare. NUMERIC/SIGNED/UNSIGNED/DECIMAL compare numerically; DATE/DATETIME compare as text, which is what ISO dates need.',
                                'enum' => array('CHAR', 'NUMERIC', 'DECIMAL', 'SIGNED', 'UNSIGNED', 'DATE', 'DATETIME', 'TIME', 'BINARY'),
                                'default' => 'CHAR',
                            ),
                        ),
                        'required' => array('key'),
                        'additionalProperties' => false,
                    ),
                ),
                'orderby' => array(
                    'type' => 'string',
                    'description' => 'Sort field for list_items. Default: date. "ID" is accepted for parity and means the record id.',
                    'enum' => array('date', 'title', 'modified', 'ID'),
                    'default' => 'date',
                ),
                'order' => array(
                    'type' => 'string',
                    'description' => 'Sort direction. Default: DESC.',
                    'enum' => array('ASC', 'DESC'),
                    'default' => 'DESC',
                ),
                'per_page' => array(
                    'type' => 'integer',
                    'description' => 'Records per page for list_items. Default: 10.',
                    'minimum' => 1,
                    'maximum' => self::MAX_PER_PAGE,
                    'default' => 10,
                ),
                'page' => array(
                    'type' => 'integer',
                    'description' => 'Page number for list_items. Default: 1.',
                    'minimum' => 1,
                    'default' => 1,
                ),
                'dry_run' => array(
                    'type' => 'boolean',
                    'description' => 'For create_item, update_item, delete_item and bulk_create: validate and report the write without performing it. Default: false.',
                    'default' => false,
                ),
            ),
            'required' => array('action'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        // A tool that can write must be classified as writing, even though five
        // of its eight actions only read.
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        switch ($this->stringParam($arguments, 'action', 'list_types')) {
            case 'list_types':
                return $this->listTypes();

            case 'get_schema':
                return $this->getSchema($arguments);

            case 'list_items':
                return $this->listItems($arguments, $context);

            case 'get_item':
                return $this->getItem($arguments, $context);

            case 'create_item':
                return $this->createItem($arguments, $context);

            case 'bulk_create':
                return $this->bulkCreate($arguments, $context);

            case 'update_item':
                return $this->updateItem($arguments, $context);

            case 'delete_item':
                return $this->deleteItem($arguments, $context);

            default:
                return $this->errors->validationFailed(
                    'Unknown action.',
                    array('action' => array('Use one of: list_types, get_schema, list_items, get_item, create_item, update_item, delete_item, bulk_create.')),
                );
        }
    }

    // ── Read actions ─────────────────────────────────────────────────────────

    /**
     * Every registered type an agent may manage, sorted by slug.
     */
    private function listTypes(): mixed
    {
        $types = array();

        foreach ($this->schema->listPostTypes() as $registered) {
            if (! $registered instanceof PostTypeSchema) {
                continue;
            }

            if (\in_array($registered->slug, self::PROTECTED_TYPES, true)) {
                continue;
            }

            $taxonomies = array();

            foreach ($this->schema->listTaxonomies($registered->slug) as $taxonomy) {
                $taxonomies[] = $taxonomy->slug;
            }

            $types[] = array(
                'post_type' => $registered->slug,
                'label' => $registered->label,
                'description' => $registered->description,
                'public' => $registered->isPublic,
                'show_in_rest' => $registered->showInRest,
                'schema_fields' => \count($registered->metaFields),
                'taxonomies' => $taxonomies,
            );
        }

        \usort($types, static fn (array $a, array $b): int => \strcmp((string) $a['post_type'], (string) $b['post_type']));

        return $this->success(
            \sprintf('%d registered type(s).', \count($types)),
            array('types' => $types, 'count' => \count($types)),
        );
    }

    /**
     * The field vocabulary for one type: four standard keys plus its meta schema.
     */
    private function getSchema(array $arguments): mixed
    {
        $schema = $this->resolveType($arguments);

        if ($this->errors->isError($schema)) {
            return $schema;
        }

        $fields = array(
            array('key' => 'title', 'label' => 'Title', 'type' => 'string', 'description' => 'Record title.', 'is_meta' => false),
            array('key' => 'content', 'label' => 'Content', 'type' => 'string', 'description' => 'Body content.', 'is_meta' => false),
            array('key' => 'excerpt', 'label' => 'Excerpt', 'type' => 'string', 'description' => 'Short summary.', 'is_meta' => false),
            array('key' => 'status', 'label' => 'Status', 'type' => 'string', 'description' => 'Publication status. Default: publish.', 'is_meta' => false),
        );

        foreach ($schema->metaFields as $definition) {
            if (! \is_array($definition)) {
                continue;
            }

            $key = isset($definition['meta_key']) && \is_string($definition['meta_key']) ? $definition['meta_key'] : '';

            if ('' === $key) {
                continue;
            }

            $field = array(
                'key' => $key,
                'label' => isset($definition['label']) ? (string) $definition['label'] : $key,
                'type' => isset($definition['type']) ? (string) $definition['type'] : 'string',
                'description' => isset($definition['description']) ? (string) $definition['description'] : '',
                'is_meta' => true,
            );

            if (isset($definition['enum']) && \is_array($definition['enum'])) {
                $field['enum'] = \array_values($definition['enum']);
            }

            $fields[] = $field;
        }

        $taxonomies = array();

        foreach ($this->schema->listTaxonomies($schema->slug) as $taxonomy) {
            $taxonomies[] = array(
                'taxonomy' => $taxonomy->slug,
                'label' => $taxonomy->label,
                'hierarchical' => $taxonomy->isHierarchical,
                'description' => $taxonomy->description,
            );
        }

        return $this->success(
            \sprintf('Schema for "%s": %d field(s).', $schema->slug, \count($fields)),
            array(
                'post_type' => $schema->slug,
                'label' => $schema->label,
                'supports' => $schema->supports,
                'field_count' => \count($fields),
                'fields' => $fields,
                'taxonomies' => $taxonomies,
                'usage_note' => 'Pass these exact "key" values in "fields". Keys with is_meta=false map to the record; the rest are stored as meta.',
            ),
        );
    }

    /**
     * List published records of one type, with meta filters applied by the store.
     */
    private function listItems(array $arguments, array $context): mixed
    {
        $schema = $this->resolveType($arguments);

        if ($this->errors->isError($schema)) {
            return $schema;
        }

        try {
            $meta = $this->metaClauses($arguments);
            $orderBy = $this->orderField($arguments);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage(), array('filters' => array($e->getMessage())));
        }

        $perPage = \max(1, \min(self::MAX_PER_PAGE, $this->intParam($arguments, 'per_page', 10)));
        $search = $this->stringParam($arguments, 'search');

        $query = new ContentQuery(
            types: array($schema->slug),
            statuses: array('publish'),
            search: '' === $search ? null : $search,
            metaQuery: $meta,
            orderBy: (string) $orderBy,
            order: 'ASC' === \strtoupper($this->stringParam($arguments, 'order', 'DESC')) ? 'ASC' : 'DESC',
            page: \max(1, $this->intParam($arguments, 'page', 1)),
            perPage: $perPage,
            userId: $this->userIdOrNull($context),
        );

        try {
            $result = $this->content->query($query);
        } catch (\Throwable $e) {
            return $this->errors->create('toolkit_query_failed', $e->getMessage());
        }

        $schemaKeys = $this->metaKeys($schema);
        $items = array();

        foreach ($result->items as $item) {
            if ($item instanceof ContentItem) {
                $items[] = $this->formatListItem($item, $schemaKeys);
            }
        }

        return $this->success(
            \sprintf('%d of %d record(s).', \count($items), $result->total),
            array(
                'post_type' => $schema->slug,
                'items' => $items,
                'total' => $result->total,
                'total_pages' => $result->totalPages,
                'page' => $result->page,
                'per_page' => $result->perPage,
            ),
        );
    }

    /**
     * One record with its full meta.
     */
    private function getItem(array $arguments, array $context): mixed
    {
        $schema = $this->resolveType($arguments);

        if ($this->errors->isError($schema)) {
            return $schema;
        }

        $id = $this->intParam($arguments, 'item_id');

        if ($id <= 0) {
            return $this->errors->validationFailed(
                'item_id is required for get_item.',
                array('item_id' => array('Give the record id.')),
            );
        }

        $item = $this->findItem($schema->slug, $id, $context);

        if ($this->errors->isError($item)) {
            return $item;
        }

        return $this->success(
            \sprintf('Record %d.', $id),
            array('post_type' => $schema->slug, 'item' => $this->formatFullItem($item)),
        );
    }

    // ── Write actions ────────────────────────────────────────────────────────

    private function createItem(array $arguments, array $context): mixed
    {
        $schema = $this->resolveType($arguments);

        if ($this->errors->isError($schema)) {
            return $schema;
        }

        try {
            [$standard, $meta] = $this->splitFields($this->fieldsParam($arguments));
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage(), array('fields' => array($e->getMessage())));
        }

        $title = \trim((string) ($standard['title'] ?? ''));

        if ('' === $title) {
            return $this->errors->validationFailed(
                'A title is required to create a record.',
                array('fields' => array('fields.title is required.')),
            );
        }

        $userId = $this->userId($context);

        $command = new CreateContentCommand(
            title: $title,
            type: $schema->slug,
            authorId: isset($standard['author_id']) ? (int) $standard['author_id'] : $userId,
            status: isset($standard['status']) ? (string) $standard['status'] : 'publish',
            content: (string) ($standard['content'] ?? ''),
            excerpt: isset($standard['excerpt']) ? (string) $standard['excerpt'] : null,
            meta: $meta,
        );

        if ($this->isDryRun($arguments)) {
            return $this->success(
                'Dry run: the record would be created.',
                array(
                    'dry_run' => true,
                    'post_type' => $schema->slug,
                    'fields' => $standard,
                    'meta_keys' => \array_keys($meta),
                ),
            );
        }

        try {
            $item = $this->content->create($command);
        } catch (\Throwable $e) {
            return $this->errors->create('toolkit_create_failed', $e->getMessage());
        }

        return $this->success(
            \sprintf('Created record %d.', $item->id),
            array('item_id' => $item->id, 'post_type' => $schema->slug, 'item' => $this->formatFullItem($item)),
        );
    }

    /**
     * Create many records, reporting per-item failures beside the successes.
     *
     * `created + failed === total` by construction, which is the same discipline
     * `BulkImportTool` uses: a batch report a caller cannot reconcile is worse
     * than no report.
     */
    private function bulkCreate(array $arguments, array $context): mixed
    {
        $schema = $this->resolveType($arguments);

        if ($this->errors->isError($schema)) {
            return $schema;
        }

        $items = $arguments['items'] ?? null;

        if (! \is_array($items) || ! \array_is_list($items) || array() === $items) {
            return $this->errors->validationFailed(
                'items must be a non-empty list of field objects.',
                array('items' => array('Give an array of records.')),
            );
        }

        if (\count($items) > self::MAX_BULK) {
            return $this->errors->validationFailed(
                \sprintf('Create at most %d records per call.', self::MAX_BULK),
                array('items' => array('Split the batch into smaller calls.')),
            );
        }

        $dryRun = $this->isDryRun($arguments);
        $userId = $this->userId($context);

        $created = array();
        $failed = array();

        foreach ($items as $index => $fields) {
            if (! \is_array($fields)) {
                $failed[] = array('index' => $index, 'error' => 'Each item must be a field object.');
                continue;
            }

            try {
                [$standard, $meta] = $this->splitFields($fields);
            } catch (\InvalidArgumentException $e) {
                $failed[] = array('index' => $index, 'error' => $e->getMessage());
                continue;
            }

            $title = \trim((string) ($standard['title'] ?? ''));

            if ('' === $title) {
                $failed[] = array('index' => $index, 'error' => 'A title is required to create a record.');
                continue;
            }

            if ($dryRun) {
                $created[] = array('index' => $index, 'title' => $title);
                continue;
            }

            try {
                $item = $this->content->create(new CreateContentCommand(
                    title: $title,
                    type: $schema->slug,
                    authorId: isset($standard['author_id']) ? (int) $standard['author_id'] : $userId,
                    status: isset($standard['status']) ? (string) $standard['status'] : 'publish',
                    content: (string) ($standard['content'] ?? ''),
                    excerpt: isset($standard['excerpt']) ? (string) $standard['excerpt'] : null,
                    meta: $meta,
                ));

                $created[] = array('index' => $index, 'item_id' => $item->id, 'title' => $item->title);
            } catch (\Throwable $e) {
                $failed[] = array('index' => $index, 'error' => $e->getMessage());
            }
        }

        $total = \count($items);

        return $this->success(
            \sprintf('%s %d of %d record(s).', $dryRun ? 'Dry run:' : 'Created', \count($created), $total),
            array(
                'dry_run' => $dryRun,
                'post_type' => $schema->slug,
                'total' => $total,
                'created' => \count($created),
                'failed' => \count($failed),
                'items' => $created,
                'errors' => $failed,
            ),
        );
    }

    /**
     * Update one record. Meta is merged by key, so untouched keys survive.
     */
    private function updateItem(array $arguments, array $context): mixed
    {
        $schema = $this->resolveType($arguments);

        if ($this->errors->isError($schema)) {
            return $schema;
        }

        $id = $this->intParam($arguments, 'item_id');

        if ($id <= 0) {
            return $this->errors->validationFailed(
                'item_id is required for update_item.',
                array('item_id' => array('Give the record id.')),
            );
        }

        $item = $this->findItem($schema->slug, $id, $context);

        if ($this->errors->isError($item)) {
            return $item;
        }

        try {
            [$standard, $meta] = $this->splitFields($this->fieldsParam($arguments));
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage(), array('fields' => array($e->getMessage())));
        }

        if (isset($standard['author_id'])) {
            return $this->errors->validationFailed(
                'author_id cannot be changed after creation.',
                array('fields' => array('This runtime sets the author when the record is created and has no update path for it.')),
            );
        }

        if (array() === $standard && array() === $meta) {
            return $this->errors->validationFailed(
                'Nothing to update.',
                array('fields' => array('Give at least one field.')),
            );
        }

        if ($this->isDryRun($arguments)) {
            return $this->success(
                \sprintf('Dry run: record %d would be updated.', $id),
                array('dry_run' => true, 'item_id' => $id, 'post_type' => $schema->slug, 'fields' => $standard, 'meta_keys' => \array_keys($meta)),
            );
        }

        try {
            $updated = $this->content->update($id, new UpdateContentCommand(
                userId: $this->userId($context),
                title: isset($standard['title']) ? (string) $standard['title'] : null,
                content: isset($standard['content']) ? (string) $standard['content'] : null,
                status: isset($standard['status']) ? (string) $standard['status'] : null,
                excerpt: isset($standard['excerpt']) ? (string) $standard['excerpt'] : null,
                meta: $meta,
            ));
        } catch (\Throwable $e) {
            return $this->errors->create('toolkit_update_failed', $e->getMessage());
        }

        return $this->success(
            \sprintf('Updated record %d.', $updated->id),
            array('item_id' => $updated->id, 'post_type' => $schema->slug, 'item' => $this->formatFullItem($updated)),
        );
    }

    /**
     * Delete one record permanently.
     */
    private function deleteItem(array $arguments, array $context): mixed
    {
        $schema = $this->resolveType($arguments);

        if ($this->errors->isError($schema)) {
            return $schema;
        }

        $id = $this->intParam($arguments, 'item_id');

        if ($id <= 0) {
            return $this->errors->validationFailed(
                'item_id is required for delete_item.',
                array('item_id' => array('Give the record id.')),
            );
        }

        $item = $this->findItem($schema->slug, $id, $context);

        if ($this->errors->isError($item)) {
            return $item;
        }

        $userId = $this->userId($context);

        if (! $this->content->userCanAccess($id, $userId, 'delete')) {
            return $this->errors->forbidden(\sprintf('You do not have permission to delete record %d.', $id));
        }

        if ($this->isDryRun($arguments)) {
            return $this->success(
                \sprintf('Dry run: record %d would be deleted.', $id),
                array('dry_run' => true, 'item_id' => $id, 'post_type' => $schema->slug),
            );
        }

        try {
            $this->content->delete($id, $userId);
        } catch (\Throwable $e) {
            return $this->errors->create('toolkit_delete_failed', $e->getMessage());
        }

        return $this->success(
            \sprintf('Deleted "%s" (record %d) permanently.', $item->title, $id),
            array('item_id' => $id, 'post_type' => $schema->slug),
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Resolve the requested type to its schema, or to a named refusal.
     *
     * Type resolution is a tool-level decision (missing, protected, unregistered)
     * so it answers an error envelope. Field-level validation throws instead,
     * because a bad field in a bulk item is a per-item failure rather than the
     * whole call's.
     *
     * The return type is `mixed` on purpose: a host's error factory answers with
     * a domain error object (the Laravel adapter throws a RuntimeException), not
     * with an array, so narrowing it to `array` would be a TypeError on the very
     * path that is supposed to refuse gracefully.
     */
    private function resolveType(array $arguments): mixed
    {
        $slug = $this->stringParam($arguments, 'post_type');

        if ('' === $slug) {
            return $this->errors->validationFailed(
                'post_type is required. Call list_types to see the available types.',
                array('post_type' => array('Give the content type slug.')),
            );
        }

        if (\in_array($slug, self::PROTECTED_TYPES, true)) {
            return $this->errors->create(
                'protected_post_type',
                \sprintf('"%s" is an internal type and cannot be managed through this tool.', $slug),
            );
        }

        $schema = $this->schema->getPostType($slug);

        if (null === $schema) {
            return $this->errors->create(
                'post_type_not_found',
                \sprintf('"%s" is not a registered type. Call list_types to see the available types.', $slug),
            );
        }

        return $schema;
    }

    /**
     * Find one record and prove it belongs to the requested type.
     *
     * Returns the item, or the host's error value (see `resolveType`).
     */
    private function findItem(string $type, int $id, array $context): mixed
    {
        $userId = $this->userIdOrNull($context);

        try {
            $item = $this->content->find($id, $userId);
        } catch (\Throwable $e) {
            return $this->errors->create('toolkit_read_failed', $e->getMessage());
        }

        if (null === $item) {
            return $this->errors->notFound(\sprintf('No record %d.', $id));
        }

        if ($item->type !== $type) {
            return $this->errors->create(
                'post_type_mismatch',
                \sprintf('Record %d is "%s", not "%s".', $id, $item->type, $type),
            );
        }

        return $item;
    }

    /**
     * Split a fields map into record-level values and meta.
     *
     * Keys are validated rather than rewritten. The WordPress tool strips every
     * character outside `[A-Za-z0-9_]` from a meta key, so `my-field` silently
     * becomes `myfield`; a renamed field is a different field, and the caller
     * would never know. `date` is refused because this runtime stamps record
     * dates from the store and offers no way to set them.
     *
     * @param array<string, mixed> $fields
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     *
     * @throws \InvalidArgumentException When a key is unusable or unsupported.
     */
    private function splitFields(array $fields): array
    {
        $standard = array();
        $meta = array();

        foreach ($fields as $key => $value) {
            if (! \is_string($key) || 1 !== \preg_match(self::KEY_PATTERN, $key)) {
                throw new \InvalidArgumentException(\sprintf(
                    '"%s" is not a usable field key; use letters, digits, underscore, dot or dash.',
                    \is_string($key) ? $key : \gettype($key),
                ));
            }

            if (! \in_array($key, self::STANDARD_FIELDS, true)) {
                $meta[$key] = $value;
                continue;
            }

            if ('date' === $key) {
                throw new \InvalidArgumentException('"date" cannot be set: this runtime stamps record dates itself.');
            }

            $standard[$key] = $value;
        }

        return array($standard, $meta);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldsParam(array $arguments): array
    {
        $fields = $arguments['fields'] ?? array();

        return \is_array($fields) ? $fields : array();
    }

    /**
     * Build the store's meta clause list from the `filters` argument.
     *
     * @return array<int|string, mixed>
     *
     * @throws \InvalidArgumentException When a filter is malformed or unsupported.
     */
    private function metaClauses(array $arguments): array
    {
        $filters = $arguments['filters'] ?? array();

        if (! \is_array($filters) || array() === $filters) {
            return array();
        }

        $clauses = array('relation' => 'AND');

        foreach ($filters as $index => $filter) {
            if (! \is_array($filter)) {
                throw new \InvalidArgumentException(\sprintf('Filter %s is not an object.', (string) $index));
            }

            $key = $filter['key'] ?? null;

            if (! \is_string($key) || 1 !== \preg_match(self::KEY_PATTERN, $key)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Filter %s needs a usable "key"; use letters, digits, underscore, dot or dash.',
                    (string) $index,
                ));
            }

            $compare = \strtoupper((string) ($filter['compare'] ?? '='));

            if (! \in_array($compare, self::COMPARES, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Filter %s compares with "%s", which this runtime does not implement. Use one of: %s.',
                    (string) $index,
                    $compare,
                    \implode(', ', self::COMPARES),
                ));
            }

            $type = \strtoupper((string) ($filter['type'] ?? 'CHAR'));

            if (! \in_array($type, array('CHAR', 'NUMERIC', 'DECIMAL', 'SIGNED', 'UNSIGNED', 'DATE', 'DATETIME', 'TIME', 'BINARY'), true)) {
                throw new \InvalidArgumentException(\sprintf('Filter %s declares an unknown meta type "%s".', (string) $index, $type));
            }

            $entry = array('key' => $key, 'compare' => $compare, 'type' => $type);

            if (! \in_array($compare, array('EXISTS', 'NOT EXISTS'), true)) {
                if (! \array_key_exists('value', $filter)) {
                    throw new \InvalidArgumentException(\sprintf('Filter %s (%s) needs a "value".', (string) $index, $compare));
                }

                $entry['value'] = $filter['value'];
            }

            if (\in_array($compare, array('IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'), true)) {
                $values = \is_array($entry['value'] ?? null) ? \array_values($entry['value']) : array($entry['value'] ?? null);

                if (\in_array($compare, array('BETWEEN', 'NOT BETWEEN'), true) && 2 !== \count($values)) {
                    throw new \InvalidArgumentException(\sprintf('Filter %s compares %s and needs exactly two values.', (string) $index, $compare));
                }

                $entry['value'] = $values;
            }

            $clauses[] = $entry;
        }

        return $clauses;
    }

    /**
     * Map the caller's order field onto one the store implements.
     *
     * `menu_order` and `rand` are absent from the schema for the same reason they
     * are refused here: the store cannot order by them, and silently falling back
     * to `date` would answer a different question.
     *
     * @throws \InvalidArgumentException When the field is not one the store sorts by.
     */
    private function orderField(array $arguments): string
    {
        $raw = $this->stringParam($arguments, 'orderby', 'date');

        $map = array('date' => 'date', 'title' => 'title', 'modified' => 'modified', 'ID' => 'id', 'id' => 'id');

        if (! isset($map[$raw])) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a sort field this runtime implements. Use date, title, modified or ID.', $raw));
        }

        return $map[$raw];
    }

    /**
     * The meta keys the type's schema declares, if any.
     *
     * @return list<string>
     */
    private function metaKeys(PostTypeSchema $schema): array
    {
        $keys = array();

        foreach ($schema->metaFields as $definition) {
            if (\is_array($definition) && isset($definition['meta_key']) && \is_string($definition['meta_key']) && '' !== $definition['meta_key']) {
                $keys[] = $definition['meta_key'];
            }
        }

        return \array_values(\array_unique($keys));
    }

    /**
     * A compact row for a listing.
     *
     * When the type declares a meta schema only those keys are returned, which is
     * what makes a listing readable; otherwise every meta key comes back minus the
     * machine-written noise.
     *
     * @param list<string> $schemaKeys
     * @return array<string, mixed>
     */
    private function formatListItem(ContentItem $item, array $schemaKeys): array
    {
        $row = array(
            'id' => $item->id,
            'title' => $item->title,
            'status' => $item->status,
            'date' => $item->createdAt->format('c'),
            'modified' => $item->updatedAt->format('c'),
        );

        if (array() !== $schemaKeys) {
            foreach ($schemaKeys as $key) {
                $row[$key] = $item->meta[$key] ?? null;
            }

            return $row;
        }

        foreach ($item->meta as $key => $value) {
            if (\is_string($key) && ! \in_array($key, self::NOISY_META, true)) {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    /**
     * Everything a record holds, meta included.
     *
     * @return array<string, mixed>
     */
    private function formatFullItem(ContentItem $item): array
    {
        $meta = array();

        foreach ($item->meta as $key => $value) {
            if (\is_string($key) && ! \in_array($key, self::NOISY_META, true)) {
                $meta[$key] = $value;
            }
        }

        return array(
            'id' => $item->id,
            'title' => $item->title,
            'content' => $item->content,
            'excerpt' => $item->excerpt,
            'status' => $item->status,
            'date' => $item->createdAt->format('c'),
            'modified' => $item->updatedAt->format('c'),
            'author_id' => $item->authorId,
            'meta' => $meta,
        );
    }

    private function userId(array $context): int
    {
        return isset($context['user_id']) ? (int) $context['user_id'] : 0;
    }

    /**
     * The caller's id, or null when the host supplied none.
     *
     * Null and 0 mean different things to the store — 0 is a real id — so a read
     * that has no user to scope by must pass null rather than 0.
     */
    private function userIdOrNull(array $context): ?int
    {
        return isset($context['user_id']) && (int) $context['user_id'] > 0 ? (int) $context['user_id'] : null;
    }
}
