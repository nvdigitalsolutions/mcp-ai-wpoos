<?php

/**
 * `toolkit_cpt` — one surface for typed-record CRUD, and the refusals it adds.
 *
 * The WordPress tool normalises instead of refusing: an unknown `compare`
 * silently becomes `=`, an unknown sort falls back to `date`, and a meta key is
 * stripped of anything outside `[A-Za-z0-9_]`. Each of those answers a different
 * question than the one asked, so the tests here assert the refusals rather than
 * the coercion — a filter that quietly means something else is the failure mode
 * this tool must not have.
 *
 * The second thing pinned is the *query the tool builds*: the store is faked with
 * a recorder, because "did `list_items` pass the meta clause and the paging
 * through" is a question about the request, not about filtering the fake could do.
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\PostTypeSchema;
use Nvoos\Core\Domain\Entity\TaxonomySchema;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\ToolkitCptTool;
use PHPUnit\Framework\TestCase;

final class ToolkitCptToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = new class () implements ErrorFactoryInterface {
            public function create(string $code, string $message, array $data = array()): mixed
            {
                return array('error' => array('code' => $code, 'message' => $message, 'data' => $data));
            }

            public function isError(mixed $value): bool
            {
                return \is_array($value) && isset($value['error']);
            }

            public function normalize(mixed $error): array
            {
                return (array) $error;
            }

            public function notFound(string $message = 'Resource not found.', array $data = array()): mixed
            {
                return $this->create('not_found', $message, $data);
            }

            public function forbidden(string $message = 'Access denied.', array $data = array()): mixed
            {
                return $this->create('forbidden', $message, $data);
            }

            public function validationFailed(string $message, array $errors = array()): mixed
            {
                return $this->create('validation_failed', $message, $errors);
            }

            public function rateLimited(string $message, int $retryAfterSeconds = 60): mixed
            {
                return $this->create('rate_limited', $message);
            }
        };
    }

    public function test_it_declares_the_slug_capability_and_flags(): void
    {
        $tool = $this->tool();

        $this->assertSame('toolkit_cpt', $tool->getSlug());
        $this->assertSame('Toolkit CPT', $tool->getName());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertSame(
            array('local-only', 'state-changing', 'write'),
            $tool->getCapabilityFlags(),
            'A tool that can write is classified as writing, even though five actions only read.',
        );
    }

    public function test_the_schema_offers_only_the_actions_and_sorts_the_store_implements(): void
    {
        $properties = $this->tool()->getParametersSchema()['properties'];

        $this->assertSame(
            array('list_types', 'get_schema', 'list_items', 'get_item', 'create_item', 'update_item', 'delete_item', 'bulk_create'),
            $properties['action']['enum'],
        );
        $this->assertSame(array('date', 'title', 'modified', 'ID'), $properties['orderby']['enum'], 'menu_order and rand are absent: the store cannot order by them.');
        $this->assertArrayHasKey('dry_run', $properties);
        $this->assertSame(array('action'), $this->tool()->getParametersSchema()['required']);
    }

    public function test_list_types_drops_machinery_and_sorts_by_slug(): void
    {
        $result = $this->tool()->execute(array('action' => 'list_types'));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count'], 'post and attachment are machinery, not toolkit data.');
        $this->assertSame(
            array('mcp_ai_project', 'mcp_ai_task'),
            \array_column($result['data']['types'], 'post_type'),
        );
        $this->assertSame(array('project_status'), $result['data']['types'][0]['taxonomies']);
        $this->assertSame(2, $result['data']['types'][1]['schema_fields'], 'The count is the declared meta fields.');
    }

    public function test_get_schema_lists_standard_keys_then_the_declared_meta(): void
    {
        $result = $this->tool()->execute(array('action' => 'get_schema', 'post_type' => 'mcp_ai_task'));

        $this->assertTrue($result['success']);
        $this->assertSame('mcp_ai_task', $result['data']['post_type']);
        $this->assertSame(6, $result['data']['field_count'], '4 standard keys plus 2 declared meta keys.');

        $keys = \array_column($result['data']['fields'], 'key');

        $this->assertSame(array('title', 'content', 'excerpt', 'status', '_task_priority', '_task_due_date'), $keys);
        $this->assertFalse($result['data']['fields'][0]['is_meta']);
        $this->assertTrue($result['data']['fields'][4]['is_meta']);
        $this->assertSame(array('high', 'low', 'normal'), $result['data']['fields'][4]['enum']);
    }

    public function test_list_items_builds_the_query_the_store_expects(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'list_items',
            'post_type' => 'mcp_ai_task',
            'search' => 'invoice',
            'filters' => array(
                array('key' => '_task_priority', 'value' => 'high', 'compare' => '='),
                array('key' => '_task_assigned_to', 'value' => 42, 'compare' => '>', 'type' => 'NUMERIC'),
            ),
            'orderby' => 'ID',
            'order' => 'ASC',
            'per_page' => 5,
            'page' => 2,
        ), array('user_id' => 7));

        $this->assertTrue($result['success']);

        $query = $this->content->lastQuery;

        $this->assertInstanceOf(ContentQuery::class, $query);
        $this->assertSame(array('mcp_ai_task'), $query->types);
        $this->assertSame(array('publish'), $query->statuses, 'The reference lists published records only.');
        $this->assertSame('invoice', $query->search);
        $this->assertSame('id', $query->orderBy, 'ID maps onto the id column.');
        $this->assertSame('ASC', $query->order);
        $this->assertSame(5, $query->perPage);
        $this->assertSame(2, $query->page);
        $this->assertSame(7, $query->userId);

        $this->assertSame('AND', $query->metaQuery['relation']);
        $this->assertSame(array('key' => '_task_priority', 'compare' => '=', 'type' => 'CHAR', 'value' => 'high'), $query->metaQuery[0]);
        $this->assertSame(array('key' => '_task_assigned_to', 'compare' => '>', 'type' => 'NUMERIC', 'value' => 42), $query->metaQuery[1]);
    }

    public function test_list_items_returns_only_the_declared_meta_keys_for_a_typed_record(): void
    {
        $result = $this->tool()->execute(array('action' => 'list_items', 'post_type' => 'mcp_ai_task'));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(1, $result['data']['total_pages']);
        $this->assertSame(array('id', 'title', 'status', 'date', 'modified', '_task_priority', '_task_due_date'), \array_keys($result['data']['items'][0]));
        $this->assertSame('high', $result['data']['items'][0]['_task_priority']);
    }

    public function test_list_items_returns_all_meta_minus_the_noise_for_an_untyped_record(): void
    {
        $result = $this->tool()->execute(array('action' => 'list_items', 'post_type' => 'mcp_ai_project'));

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('_edit_lock', $result['data']['items'][0], 'Machine-written meta would drown the record.');
        $this->assertSame('live', $result['data']['items'][0]['project_status']);
    }

    public function test_an_unknown_compare_is_refused_with_the_supported_set(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'list_items',
            'post_type' => 'mcp_ai_task',
            'filters' => array(array('key' => '_task_priority', 'value' => 'high', 'compare' => 'APPROX')),
        ));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('does not implement', $result['error']['message']);
        $this->assertStringContainsString('NOT EXISTS', $result['error']['message'], 'The refusal names what is supported.');
        $this->assertNull($this->content->lastQuery, 'A refused filter must not reach the store.');
    }

    public function test_menu_order_is_refused_rather_than_silently_ordered_by_date(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'list_items',
            'post_type' => 'mcp_ai_task',
            'orderby' => 'menu_order',
        ));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('not a sort field', $result['error']['message']);
    }

    public function test_create_item_splits_standard_fields_from_meta_and_publishes_by_default(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'create_item',
            'post_type' => 'mcp_ai_task',
            'fields' => array(
                'title' => 'Send the invoice',
                'content' => 'Body',
                '_task_priority' => 'high',
                '_task_assigned_to' => 42,
            ),
        ), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(101, $result['data']['item_id']);

        $command = $this->content->lastCreate;

        $this->assertInstanceOf(CreateContentCommand::class, $command);
        $this->assertSame('Send the invoice', $command->title);
        $this->assertSame('mcp_ai_task', $command->type);
        $this->assertSame('publish', $command->status, 'The reference publishes by default.');
        $this->assertSame(7, $command->authorId);
        $this->assertSame(array('_task_priority' => 'high', '_task_assigned_to' => 42), $command->meta);
    }

    public function test_create_item_without_a_title_is_refused_before_the_store_is_called(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'create_item',
            'post_type' => 'mcp_ai_task',
            'fields' => array('_task_priority' => 'high'),
        ));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertNull($this->content->lastCreate);
    }

    public function test_a_date_field_is_refused_instead_of_being_dropped(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'create_item',
            'post_type' => 'mcp_ai_task',
            'fields' => array('title' => 'Timed', 'date' => '2026-01-01 09:00:00'),
        ));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('stamps record dates', $result['error']['message']);
        $this->assertNull($this->content->lastCreate, 'A refused field must not become a silent drop.');
    }

    public function test_a_field_key_that_would_be_rewritten_is_refused(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'create_item',
            'post_type' => 'mcp_ai_task',
            'fields' => array('title' => 'Keys', 'task priority' => 'high'),
        ));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('not a usable field key', $result['error']['message']);
    }

    public function test_create_item_dry_run_reports_without_writing(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'create_item',
            'post_type' => 'mcp_ai_task',
            'fields' => array('title' => 'Preview'),
            'dry_run' => true,
        ));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(array(), $result['data']['meta_keys'], 'A title-only record has no meta.');
        $this->assertNull($this->content->lastCreate);
    }

    public function test_update_item_sends_only_the_fields_given_so_meta_is_merged(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'update_item',
            'post_type' => 'mcp_ai_task',
            'item_id' => 5,
            'fields' => array('status' => 'draft', '_task_priority' => 'low'),
        ), array('user_id' => 7));

        $this->assertTrue($result['success']);

        $command = $this->content->lastUpdate;

        $this->assertInstanceOf(UpdateContentCommand::class, $command);
        $this->assertNull($command->title, 'Null means unchanged, which is what keeps untouched fields intact.');
        $this->assertSame('draft', $command->status);
        $this->assertSame(array('_task_priority' => 'low'), $command->meta);
    }

    public function test_update_item_refuses_author_id_because_the_runtime_cannot_change_it(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'update_item',
            'post_type' => 'mcp_ai_task',
            'item_id' => 5,
            'fields' => array('author_id' => 9),
        ));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('cannot be changed', $result['error']['message']);
        $this->assertNull($this->content->lastUpdate);
    }

    public function test_update_item_with_no_fields_is_refused(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'update_item',
            'post_type' => 'mcp_ai_task',
            'item_id' => 5,
            'fields' => array(),
        ));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('Nothing to update', $result['error']['message']);
    }

    public function test_delete_item_refuses_when_the_store_denies_access(): void
    {
        $this->content()->canDelete = false;

        $result = $this->tool()->execute(array(
            'action' => 'delete_item',
            'post_type' => 'mcp_ai_task',
            'item_id' => 5,
        ), array('user_id' => 7));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertNull($this->content->deletedId, 'A denied delete must not reach the store.');
    }

    public function test_delete_item_dry_run_reports_without_deleting(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'delete_item',
            'post_type' => 'mcp_ai_task',
            'item_id' => 5,
            'dry_run' => true,
        ), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertNull($this->content->deletedId);
    }

    public function test_delete_item_removes_the_record(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'delete_item',
            'post_type' => 'mcp_ai_task',
            'item_id' => 5,
        ), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(5, $this->content->deletedId);
        $this->assertStringContainsString('permanently', $result['message']);
    }

    public function test_bulk_create_reports_every_item_and_the_counts_reconcile(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'bulk_create',
            'post_type' => 'mcp_ai_task',
            'items' => array(
                array('title' => 'First'),
                array('title' => ''),                 // no title -> failed
                array('_task_priority' => 'high'),    // no title -> failed
                'not-an-object',                      // failed
                array('title' => 'Last'),
            ),
        ), array('user_id' => 7));

        $data = $result['data'];

        $this->assertSame(5, $data['total']);
        $this->assertSame(2, $data['created']);
        $this->assertSame(3, $data['failed']);
        $this->assertSame(
            $data['total'],
            $data['created'] + $data['failed'],
            'A batch report a caller cannot reconcile is worse than no report.',
        );
        $this->assertSame(array(1, 2, 3), \array_column($data['errors'], 'index'), 'Each failure names its position.');
    }

    public function test_bulk_create_dry_run_writes_nothing(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'bulk_create',
            'post_type' => 'mcp_ai_task',
            'items' => array(array('title' => 'One'), array('title' => 'Two')),
            'dry_run' => true,
        ));

        $this->assertSame(2, $result['data']['created']);
        $this->assertSame(0, $this->content->createCalls, 'A rehearsal creates nothing.');
    }

    public function test_get_item_returns_the_full_record_with_meta(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'get_item',
            'post_type' => 'mcp_ai_task',
            'item_id' => 5,
        ), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['data']['item']['id']);
        $this->assertSame('high', $result['data']['item']['meta']['_task_priority']);
        $this->assertSame(7, $result['data']['item']['author_id']);
        $this->assertArrayNotHasKey('_edit_lock', $result['data']['item']['meta']);
    }

    public function test_get_item_refuses_a_type_mismatch(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'get_item',
            'post_type' => 'mcp_ai_project',
            'item_id' => 5,
        ), array('user_id' => 7));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('post_type_mismatch', $result['error']['code']);
    }

    public function test_get_item_reports_a_missing_record(): void
    {
        $result = $this->tool()->execute(array(
            'action' => 'get_item',
            'post_type' => 'mcp_ai_task',
            'item_id' => 404,
        ), array('user_id' => 7));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_a_protected_type_is_refused(): void
    {
        $result = $this->tool()->execute(array('action' => 'get_schema', 'post_type' => 'attachment'));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('protected_post_type', $result['error']['code']);
    }

    public function test_an_unregistered_type_is_refused_with_the_discovery_hint(): void
    {
        $result = $this->tool()->execute(array('action' => 'get_schema', 'post_type' => 'not_a_type'));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('post_type_not_found', $result['error']['code']);
        $this->assertStringContainsString('list_types', $result['error']['message']);
    }

    public function test_a_missing_post_type_points_at_discovery(): void
    {
        $result = $this->tool()->execute(array('action' => 'list_items'));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('list_types', $result['error']['message']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @var object|null */
    private $content = null;

    /** @var object|null */
    private $schema = null;

    private function tool(): ToolkitCptTool
    {
        return new ToolkitCptTool($this->errors, $this->content(), $this->schema());
    }

    private function content(): ContentStoreInterface
    {
        if (! $this->content instanceof ContentStoreInterface) {
            $this->content = $this->fakeContent();
        }

        return $this->content;
    }

    private function schema(): SchemaStoreInterface
    {
        if (! $this->schema instanceof SchemaStoreInterface) {
            $this->schema = $this->fakeSchema();
        }

        return $this->schema;
    }

    /**
     * A record with the meta a toolkit type would carry.
     *
     * @param array<string, mixed> $meta
     */
    public static function makeItem(int $id, string $type, array $meta = array()): ContentItem
    {
        if (array() === $meta) {
            $meta = 'mcp_ai_task' === $type
                ? array('_task_priority' => 'high', '_task_due_date' => '2026-02-01', '_edit_lock' => 'x')
                : array('project_status' => 'live', '_edit_lock' => 'x');
        }

        return new ContentItem(
            id: $id,
            title: 'Task ' . $id,
            content: 'Body',
            status: 'publish',
            type: $type,
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
        );
    }

    private function fakeContent(): ContentStoreInterface
    {
        return new class () implements ContentStoreInterface {
            public ?ContentQuery $lastQuery = null;

            public ?CreateContentCommand $lastCreate = null;

            public ?UpdateContentCommand $lastUpdate = null;

            public ?int $deletedId = null;

            public int $createCalls = 0;

            public bool $canDelete = true;

            private int $nextId = 101;

            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                return $id >= 100 ? null : ToolkitCptToolTest::makeItem($id, 'mcp_ai_task');
            }

            public function query(ContentQuery $query): ContentCollection
            {
                $this->lastQuery = $query;
                $type = (string) ($query->types[0] ?? 'mcp_ai_task');

                return new ContentCollection(array(ToolkitCptToolTest::makeItem(5, $type)), 1, $query->page, $query->perPage, 1);
            }

            public function create(CreateContentCommand $command): ContentItem
            {
                $this->lastCreate = $command;
                ++$this->createCalls;

                return ToolkitCptToolTest::makeItem($this->nextId++, $command->type, $command->meta);
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                $this->lastUpdate = $command;

                return ToolkitCptToolTest::makeItem($id, 'mcp_ai_task', $command->meta);
            }

            public function delete(int $id, int $userId): void
            {
                $this->deletedId = $id;
            }

            public function getMeta(int $id): array
            {
                return array();
            }

            public function getTaxonomyTerms(int $id): array
            {
                return array();
            }

            public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
            {
                return array();
            }

            public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
            {
                return 'delete' === $operation ? $this->canDelete : true;
            }
        };
    }

    private function fakeSchema(): SchemaStoreInterface
    {
        return new class () implements SchemaStoreInterface {
            public function getPostType(string $type): ?PostTypeSchema
            {
                $types = $this->types();

                return $types[$type] ?? null;
            }

            public function listPostTypes(): array
            {
                return \array_values($this->types());
            }

            public function getTaxonomy(string $taxonomy): ?TaxonomySchema
            {
                return null;
            }

            public function listTaxonomies(string $postType): array
            {
                return 'mcp_ai_project' === $postType
                    ? array(new TaxonomySchema('project_status', 'Project status'))
                    : array();
            }

            public function getPostStatuses(): array
            {
                return array('publish', 'draft', 'private');
            }

            public function postTypeSupports(string $postType, string $feature): bool
            {
                return true;
            }

            /**
             * @return array<string, PostTypeSchema>
             */
            private function types(): array
            {
                return array(
                    'post' => new PostTypeSchema('post', 'Posts'),
                    'attachment' => new PostTypeSchema('attachment', 'Media'),
                    'mcp_ai_task' => new PostTypeSchema(
                        'mcp_ai_task',
                        'Tasks',
                        'Toolkit tasks',
                        true,
                        false,
                        false,
                        true,
                        null,
                        array(),
                        array(),
                        array('title', 'editor'),
                        array('publish', 'draft'),
                        array(
                            array('meta_key' => '_task_priority', 'label' => 'Priority', 'type' => 'string', 'enum' => array('high', 'low', 'normal')),
                            array('meta_key' => '_task_due_date', 'label' => 'Due date', 'type' => 'string'),
                        ),
                    ),
                    'mcp_ai_project' => new PostTypeSchema('mcp_ai_project', 'Projects'),
                );
            }
        };
    }
}
