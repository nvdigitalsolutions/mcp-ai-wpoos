<?php

/**
 * The contact pair: the six-action manager and the duplicate scanner.
 *
 * The assertions that matter are the measured facts, not the happy path:
 *
 * - The manager reads and writes `mcp_crm_contacts` **only**. A lead id is not
 *   found, which pins the separation §164.63 got wrong.
 * - Contact fields are stored **unprefixed** (`first_name`, `email`, …), and a
 *   created contact has **no title** unless one is supplied — both WordPress
 *   behaviours, both pinned.
 * - An update through `first_name` / `last_name` leaves the title untouched.
 * - The scanner reads leads and customers, not toolkit contacts, and matches
 *   email/phone from the **live** keys (`email` / `phone`) with the dead
 *   `_contact_*` family as the legacy fallback.
 *
 * The store is stateful, so create → read → update → delete is one flow.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\Crm\ManageCrmContactTool;
use Nvoos\Core\Tool\Crm\ScanDuplicateContactsTool;
use PHPUnit\Framework\TestCase;

final class CrmContactToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message),
            )
        );
    }

    // ─── manage_crm_contact ──────────────────────────────────────────────

    public function test_the_manager_keeps_the_wordpress_surface(): void
    {
        $tool = $this->manager($this->store());

        $this->assertSame('manage_crm_contact', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array('action'), $schema['required']);
        $this->assertSame(
            array('create', 'read', 'update', 'delete', 'list', 'search'),
            $schema['properties']['action']['enum']
        );
        $this->assertSame(20, $schema['properties']['per_page']['default']);
        $this->assertSame(1, $schema['properties']['page']['default']);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $result = $this->manager($this->store())->execute(
            array('action' => 'merge'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('action', $result['error']['data']);
    }

    public function test_a_contact_needs_data_and_an_email(): void
    {
        $tool = $this->manager($this->store());

        $noData = $tool->execute(array('action' => 'create'), array('user_id' => 7));
        $this->assertSame('validation_failed', $noData['error']['code']);
        $this->assertArrayHasKey('contact_data', $noData['error']['data']);

        $noEmail = $tool->execute(
            array('action' => 'create', 'contact_data' => array('first_name' => 'Ada')),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $noEmail['error']['code']);
        $this->assertArrayHasKey('email', $noEmail['error']['data']);
    }

    public function test_a_malformed_email_or_short_phone_is_refused(): void
    {
        $tool = $this->manager($this->store());

        $badEmail = $tool->execute(
            array('action' => 'create', 'contact_data' => array('email' => 'not-an-email')),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $badEmail['error']['code']);
        $this->assertArrayHasKey('email', $badEmail['error']['data']);

        $badPhone = $tool->execute(
            array(
                'action'       => 'create',
                'contact_data' => array('email' => 'ada@example.test', 'phone' => '12345'),
            ),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $badPhone['error']['code']);
        $this->assertArrayHasKey('phone', $badPhone['error']['data']);
    }

    public function test_a_contact_is_stored_unprefixed_in_the_toolkit_type_with_no_derived_title(): void
    {
        $store = $this->store();

        $result = $this->manager($store)->execute(
            array(
                'action'       => 'create',
                'contact_data' => array(
                    'first_name' => 'Ada',
                    'last_name'  => 'Lovelace',
                    'email'      => 'ada@example.test',
                    'phone'      => '+44 20 0000 0000',
                    'company'    => 'Analytical Engines',
                    'job_title'  => 'Chief Engineer',
                    'tags'       => array('vip', 'referral'),
                ),
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('cpt', $result['data']['storage_type']);

        $item = $store->items[ $result['data']['contact_id'] ];

        $this->assertSame('mcp_crm_contacts', $item->type);
        $this->assertSame('publish', $item->status);
        $this->assertSame(7, $item->authorId);

        // Pinned: no title is derived from the name.
        $this->assertSame('', $item->title);

        $this->assertSame('Ada', $item->meta['first_name']);
        $this->assertSame('Lovelace', $item->meta['last_name']);
        $this->assertSame('ada@example.test', $item->meta['email']);
        $this->assertSame('+44 20 0000 0000', $item->meta['phone']);
        $this->assertSame('Analytical Engines', $item->meta['company']);
        $this->assertSame('Chief Engineer', $item->meta['job_title']);
        $this->assertSame(array('vip', 'referral'), $item->meta['tags']);
        $this->assertSame('crm', $item->meta['_toolkit_slug']);
        $this->assertSame('contacts', $item->meta['_entity_type']);
    }

    public function test_a_supplied_title_is_honoured_and_not_stored_as_meta(): void
    {
        $store = $this->store();

        $result = $this->manager($store)->execute(
            array(
                'action'       => 'create',
                'contact_data' => array('title' => 'Ada Lovelace', 'email' => 'ada@example.test'),
            ),
            array('user_id' => 7)
        );

        $item = $store->items[ $result['data']['contact_id'] ];

        $this->assertSame('Ada Lovelace', $item->title);
        $this->assertArrayNotHasKey('title', $item->meta);
    }

    public function test_read_returns_the_store_shape_without_internal_meta(): void
    {
        $store = $this->contactStore(100, 'Lead Ada', array(
            'email'        => 'ada@example.test',
            'first_name'   => 'Ada',
            '_toolkit_slug' => 'crm',
        ));

        $result = $this->manager($store)->execute(
            array('action' => 'read', 'contact_id' => 100),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);

        $contact = $result['data']['contact'];

        $this->assertSame(100, $contact['id']);
        $this->assertSame('Lead Ada', $contact['title']);
        $this->assertSame('ada@example.test', $contact['email']);
        $this->assertSame('cpt', $result['data']['storage_type']);
        $this->assertArrayNotHasKey('_toolkit_slug', $contact);
    }

    public function test_a_lead_id_is_not_a_contact_id(): void
    {
        $store = $this->store(array(
            $this->item(200, 'Legacy Ada', 'mcp_ai_lead', array('email' => 'ada@example.test')),
        ));

        $result = $this->manager($store)->execute(
            array('action' => 'read', 'contact_id' => 200),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_an_update_merges_meta_but_leaves_the_title_alone(): void
    {
        $store = $this->contactStore(100, 'Original title', array(
            'email'      => 'ada@example.test',
            'first_name' => 'Ada',
        ));

        $result = $this->manager($store)->execute(
            array(
                'action'       => 'update',
                'contact_id'   => 100,
                'contact_data' => array('first_name' => 'Augusta', 'phone' => '+44 20 0000 0001'),
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);

        $item = $store->items[100];

        $this->assertSame('Original title', $item->title);
        $this->assertSame('Augusta', $item->meta['first_name']);
        $this->assertSame('+44 20 0000 0001', $item->meta['phone']);
        $this->assertSame('ada@example.test', $item->meta['email']);
    }

    public function test_an_update_with_a_title_changes_it(): void
    {
        $store = $this->contactStore(100, 'Original title', array('email' => 'ada@example.test'));

        $this->manager($store)->execute(
            array(
                'action'       => 'update',
                'contact_id'   => 100,
                'contact_data' => array('title' => 'Ada Lovelace'),
            ),
            array('user_id' => 7)
        );

        $this->assertSame('Ada Lovelace', $store->items[100]->title);
    }

    public function test_delete_removes_the_contact_and_refuses_a_non_contact(): void
    {
        $store = $this->contactStore(100, 'Ada', array('email' => 'ada@example.test'));

        $deleted = $this->manager($store)->execute(
            array('action' => 'delete', 'contact_id' => 100),
            array('user_id' => 7)
        );

        $this->assertTrue($deleted['success']);
        $this->assertArrayNotHasKey(100, $store->items);

        $missing = $this->manager($store)->execute(
            array('action' => 'delete', 'contact_id' => 100),
            array('user_id' => 7)
        );

        $this->assertSame('not_found', $missing['error']['code']);
    }

    public function test_list_is_newest_first_and_only_lists_contacts(): void
    {
        $store = $this->store(array(
            $this->item(10, 'Older', 'mcp_crm_contacts', array('email' => 'a@example.test'), '2026-01-01 09:00:00'),
            $this->item(11, 'Newer', 'mcp_crm_contacts', array('email' => 'b@example.test'), '2026-01-02 09:00:00'),
            $this->item(12, 'A lead', 'mcp_ai_lead', array('email' => 'c@example.test'), '2026-01-03 09:00:00'),
        ));

        $result = $this->manager($store)->execute(
            array('action' => 'list'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(20, $result['data']['per_page']);
        $this->assertSame(1, $result['data']['page']);
        $this->assertSame(array(11, 10), array_column($result['data']['contacts'], 'id'));
    }

    public function test_search_needs_a_query_and_filters_on_it(): void
    {
        $store = $this->store(array(
            $this->item(10, 'Ada Lovelace', 'mcp_crm_contacts', array('email' => 'ada@example.test')),
            $this->item(11, 'Grace Hopper', 'mcp_crm_contacts', array('email' => 'grace@example.test')),
        ));

        $tool = $this->manager($store);

        $missing = $tool->execute(array('action' => 'search'), array('user_id' => 7));
        $this->assertSame('validation_failed', $missing['error']['code']);
        $this->assertArrayHasKey('search_query', $missing['error']['data']);

        $found = $tool->execute(
            array('action' => 'search', 'search_query' => 'Hopper'),
            array('user_id' => 7)
        );

        $this->assertSame('Hopper', $found['data']['search_query']);
        $this->assertSame(array(11), array_column($found['data']['contacts'], 'id'));
    }

    // ─── scan_duplicate_contacts ─────────────────────────────────────────

    public function test_the_scanner_keeps_the_wordpress_surface(): void
    {
        $tool = $this->scanner($this->store());

        $this->assertSame('scan_duplicate_contacts', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array(), $schema['required']);
        $this->assertSame(80, $schema['properties']['min_similarity']['default']);
        $this->assertSame(200, $schema['properties']['limit']['default']);
        $this->assertSame(array('email', 'phone', 'name', 'all'), $schema['properties']['match_field']['enum']);
    }

    public function test_email_duplicates_match_on_the_live_key(): void
    {
        $store = $this->store(array(
            $this->item(20, 'Ada', 'mcp_ai_lead', array('email' => 'Ada@Example.test')),
            $this->item(21, 'Ada Again', 'mcp_ai_customer', array('email' => 'ada@example.test')),
            $this->item(22, 'Grace', 'mcp_ai_lead', array('email' => 'grace@example.test')),
        ));

        $result = $this->scanner($store)->execute(
            array('match_field' => 'email'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['total_contacts']);
        $this->assertSame(1, $result['data']['duplicate_groups']);

        $group = $result['data']['duplicates'][0];

        $this->assertSame('email', $group['match_field']);
        $this->assertSame('ada@example.test', $group['match_value']);
        $this->assertSame(100, $group['similarity']);
        $this->assertSame(array(20, 21), array_column($group['contacts'], 'id'));
        $this->assertSame(array('lead', 'customer'), array_column($group['contacts'], 'type'));
    }

    public function test_the_legacy_underscore_keys_are_still_read(): void
    {
        $store = $this->store(array(
            $this->item(20, 'Ada', 'mcp_ai_lead', array('_contact_email' => 'ada@example.test')),
            $this->item(21, 'Ada Again', 'mcp_ai_customer', array('_contact_email' => 'ada@example.test')),
        ));

        $result = $this->scanner($store)->execute(
            array('match_field' => 'email'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['duplicate_groups']);
    }

    public function test_phone_duplicates_are_normalized_and_masked(): void
    {
        $store = $this->store(array(
            $this->item(20, 'Ada', 'mcp_ai_lead', array('phone' => '+44 (20) 0000 0000')),
            $this->item(21, 'Ada Again', 'mcp_ai_customer', array('phone' => '442000000000')),
        ));

        $result = $this->scanner($store)->execute(
            array('match_field' => 'phone'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['duplicate_groups']);

        $group = $result['data']['duplicates'][0];

        $this->assertSame('phone', $group['match_field']);
        $this->assertSame('********0000', $group['match_value']);
    }

    public function test_name_similarity_respects_the_threshold(): void
    {
        $store = $this->store(array(
            $this->item(20, 'Ada Lovelace', 'mcp_ai_lead', array()),
            $this->item(21, 'Ada Lovelace', 'mcp_ai_customer', array()),
            $this->item(22, 'Grace Hopper', 'mcp_ai_lead', array()),
        ));

        $result = $this->scanner($store)->execute(
            array('match_field' => 'name', 'min_similarity' => 90),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['duplicate_groups']);

        $group = $result['data']['duplicates'][0];

        $this->assertSame('name', $group['match_field']);
        $this->assertSame(100, $group['similarity']);
        $this->assertSame('ada lovelace ↔ ada lovelace', $group['match_value']);
    }

    public function test_one_pair_is_reported_once_even_when_both_email_and_name_match(): void
    {
        $store = $this->store(array(
            $this->item(20, 'Ada Lovelace', 'mcp_ai_lead', array('email' => 'ada@example.test')),
            $this->item(21, 'Ada Lovelace', 'mcp_ai_customer', array('email' => 'ada@example.test')),
        ));

        $result = $this->scanner($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(1, $result['data']['duplicate_groups']);
    }

    public function test_the_scanner_does_not_scan_toolkit_contacts_or_other_types(): void
    {
        $store = $this->store(array(
            $this->item(20, 'Ada', 'mcp_crm_contacts', array('email' => 'ada@example.test')),
            $this->item(21, 'Ada', 'mcp_crm_contacts', array('email' => 'ada@example.test')),
            $this->item(22, 'Ada', 'mcp_ai_deal', array('email' => 'ada@example.test')),
        ));

        $result = $this->scanner($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(0, $result['data']['total_contacts']);
        $this->assertSame(array(), $result['data']['duplicates']);
    }

    public function test_the_group_limit_applies(): void
    {
        $contacts = array();

        for ($i = 1; $i <= 4; $i++) {
            $contacts[] = $this->item(30 + $i, 'Person ' . $i, 'mcp_ai_lead', array('email' => 'same@example.test'));
        }

        $result = $this->scanner($this->store($contacts))->execute(
            array('match_field' => 'email', 'limit' => 1),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['duplicate_groups']);
        $this->assertCount(1, $result['data']['duplicates']);
    }

    // ─── fixtures ────────────────────────────────────────────────────────

    private function manager(ContentStoreInterface $store): ManageCrmContactTool
    {
        return new ManageCrmContactTool($this->errors, $store);
    }

    private function scanner(ContentStoreInterface $store): ScanDuplicateContactsTool
    {
        return new ScanDuplicateContactsTool($this->errors, $store);
    }

    /**
     * A store holding one toolkit contact.
     */
    private function contactStore(int $id, string $title, array $meta): ContentStoreInterface
    {
        return $this->store(array(
            $this->item($id, $title, 'mcp_crm_contacts', $meta),
        ));
    }

    /**
     * One content item.
     *
     * @param array<string, mixed> $meta
     */
    private function item(
        int $id,
        string $title,
        string $type,
        array $meta,
        string $createdAt = '2026-01-01 09:00:00',
    ): ContentItem {
        $when = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));

        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: $type,
            authorId: 1,
            createdAt: $when,
            updatedAt: $when,
            meta: $meta,
        );
    }

    /**
     * A stateful in-memory store: query, find, create, update and delete all
     * behave the way the contract says they do.
     *
     * @param array<int, ContentItem> $items
     */
    private function store(array $items = array()): ContentStoreInterface
    {
        return new class ($items) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items = array();

            private int $nextId = 1;

            public function __construct(array $items)
            {
                foreach ($items as $item) {
                    $this->items[ $item->id ] = $item;
                    $this->nextId             = max($this->nextId, $item->id + 1);
                }
            }

            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                return $this->items[ $id ] ?? null;
            }

            public function query(ContentQuery $query): ContentCollection
            {
                $rows = array_values(array_filter(
                    $this->items,
                    function (ContentItem $item) use ($query): bool {
                        if (array() !== $query->types && ! in_array($item->type, $query->types, true)) {
                            return false;
                        }

                        if (array() !== $query->statuses && ! in_array($item->status, $query->statuses, true)) {
                            return false;
                        }

                        if (null !== $query->search && '' !== $query->search) {
                            $needle = strtolower($query->search);

                            return str_contains(strtolower($item->title), $needle)
                                || str_contains(strtolower($item->content), $needle)
                                || str_contains(strtolower((string) ( $item->excerpt ?? '' )), $needle);
                        }

                        return true;
                    }
                ));

                usort(
                    $rows,
                    static fn (ContentItem $a, ContentItem $b): int => $b->createdAt->getTimestamp()
                        <=> $a->createdAt->getTimestamp()
                );

                $total = count($rows);
                $slice = array_slice($rows, ($query->page - 1) * $query->perPage, $query->perPage);

                return new ContentCollection(
                    $slice,
                    $total,
                    $query->page,
                    $query->perPage,
                    (int) ceil($total / max(1, $query->perPage))
                );
            }

            public function create(CreateContentCommand $command): ContentItem
            {
                $id  = $this->nextId++;
                $now = new \DateTimeImmutable('2026-01-01T09:00:00+00:00');

                $item = new ContentItem(
                    id: $id,
                    title: $command->title,
                    content: $command->content,
                    status: $command->status,
                    type: $command->type,
                    authorId: $command->authorId,
                    createdAt: $now,
                    updatedAt: $now,
                    meta: $command->meta,
                    excerpt: $command->excerpt,
                );

                $this->items[ $id ] = $item;

                return $item;
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                $item = $this->items[ $id ];

                $updated = new ContentItem(
                    id: $item->id,
                    title: $command->title ?? $item->title,
                    content: $command->content ?? $item->content,
                    status: $command->status ?? $item->status,
                    type: $item->type,
                    authorId: $item->authorId,
                    createdAt: $item->createdAt,
                    updatedAt: $item->updatedAt,
                    meta: array_merge($item->meta, $command->meta),
                    taxonomy: $item->taxonomy,
                    excerpt: $command->excerpt ?? $item->excerpt,
                    slug: $item->slug,
                );

                $this->items[ $id ] = $updated;

                return $updated;
            }

            public function delete(int $id, int $userId): void
            {
                unset($this->items[ $id ]);
            }

            public function getMeta(int $id): array
            {
                return $this->items[ $id ]->meta ?? array();
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
                return true;
            }
        };
    }
}
