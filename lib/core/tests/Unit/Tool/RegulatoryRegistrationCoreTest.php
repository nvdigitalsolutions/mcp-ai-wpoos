<?php

/**
 * The third regulatory-registration slice: registration create, get and list.
 *
 * Proposal 164 section 164.146. What is pinned: the title convention, the meta
 * keys and their types, the status written as a term name and filtered by its
 * slug form, the expiry arithmetic with its three flags and its refusal to
 * guess at an unparsable date, the two optional expansions, and the page
 * boundary rule the listing shares with the product listing.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
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
use Nvoos\Core\Tool\Regulatory\CreateRegistrationTool;
use Nvoos\Core\Tool\Regulatory\GetRegistrationTool;
use Nvoos\Core\Tool\Regulatory\ListRegistrationsTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryRegistrationCoreTest extends TestCase
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
            static fn (string $message = 'Resource not found.'): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message),
            )
        );
    }

    // ─── Create ──────────────────────────────────────────────────────────

    public function test_create_names_both_required_fields(): void
    {
        $store  = $this->store();
        $result = $this->create($store)->execute(array(), array('user_id' => 7));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('product_id', $result['error']['data']);
        $this->assertArrayHasKey('country', $result['error']['data']);
        $this->assertCount(0, $store->created);
    }

    public function test_create_refuses_a_product_id_that_is_not_a_product(): void
    {
        $store  = $this->store(array($this->record(9, 'Not a product', 'mcp_ai_company')));
        $result = $this->create($store)->execute(
            array('product_id' => 9, 'country' => 'Sri Lanka'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('product_id', $result['error']['data']);
    }

    public function test_create_uses_the_title_convention_and_stores_the_wordpress_meta(): void
    {
        $store = $this->store(array($this->product(4, 'Rose Serum')));

        $result = $this->create($store)->execute(
            array(
                'product_id'        => 4,
                'country'           => 'Sri Lanka',
                'authority'         => 'NMRA',
                'registration_type' => 'renewal',
                'status'            => 'pending_documents',
                'cos_number'        => 'COS-7',
                'submission_date'   => '2026-01-05',
                'expiry_date'       => '2028-01-05',
                'notes'             => 'Filed by hand.',
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame($store->created[0]['id'], $result['data']['registration_id']);
        $this->assertNull($result['data']['edit_url']);
        $this->assertSame('Registration for "Rose Serum" in Sri Lanka created successfully.', $result['message']);

        $created = $store->created[0];
        $this->assertSame('Rose Serum - Sri Lanka Registration', $created['title']);
        $this->assertSame('Filed by hand.', $created['content']);
        $this->assertSame(4, $created['meta']['product_id']);
        $this->assertSame('Sri Lanka', $created['meta']['country']);
        $this->assertSame('NMRA', $created['meta']['authority']);
        $this->assertSame('renewal', $created['meta']['registration_type']);
        $this->assertSame('COS-7', $created['meta']['cos_number']);
        $this->assertSame('2026-01-05', $created['meta']['submission_date']);
        $this->assertSame('2028-01-05', $created['meta']['expiry_date']);
        $this->assertSame(array('Pending Documents'), $created['taxonomy']['mcp_ai_reg_status']);
    }

    public function test_create_defaults_the_status_to_draft_and_the_type_to_new(): void
    {
        $store = $this->store(array($this->product(4, 'Rose Serum')));

        $this->create($store)->execute(
            array('product_id' => 4, 'country' => 'UAE'),
            array('user_id' => 7)
        );

        $this->assertSame('new', $store->created[0]['meta']['registration_type']);
        $this->assertSame(array('Draft'), $store->created[0]['taxonomy']['mcp_ai_reg_status']);
    }

    public function test_a_status_written_by_create_is_found_by_the_slug_filter(): void
    {
        $store = $this->store(array($this->product(4, 'Rose Serum')));

        $this->create($store)->execute(
            array('product_id' => 4, 'country' => 'Sri Lanka', 'status' => 'pending_documents'),
            array('user_id' => 7)
        );

        $result = $this->list($store)->execute(array('status' => 'pending_documents'), array('user_id' => 7));

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('Pending Documents', $result['data']['registrations'][0]['status']);
    }

    public function test_create_names_a_malformed_date_and_creates_nothing(): void
    {
        $store  = $this->store(array($this->product(4, 'Rose Serum')));
        $result = $this->create($store)->execute(
            array('product_id' => 4, 'country' => 'UAE', 'expiry_date' => '05/01/2028'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('expiry_date', $result['error']['data']);
        $this->assertCount(0, $store->created);
    }

    // ─── Get ─────────────────────────────────────────────────────────────

    public function test_get_returns_the_full_row_with_its_status(): void
    {
        $store = $this->store(array(
            $this->registration(20, 'Rose - LK', array(
                'product_id' => 4,
                'country'    => 'Sri Lanka',
                'authority'  => 'NMRA',
                'cos_number' => 'COS-1',
            ), array('mcp_ai_reg_status' => array('Approved'))),
        ));

        $result = $this->get($store)->execute(array('registration_id' => 20), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $row = $result['data']['registration'];

        $this->assertSame(20, $row['id']);
        $this->assertSame(4, $row['product_id']);
        $this->assertSame('Sri Lanka', $row['country']);
        $this->assertSame('NMRA', $row['authority']);
        $this->assertSame('COS-1', $row['cos_number']);
        $this->assertSame('Approved', $row['status']);
        $this->assertSame('2026-01-01 09:00:00', $row['created_date']);
        $this->assertArrayNotHasKey('days_to_expiry', $row);
    }

    public function test_the_expiry_flags_cover_expired_soon_and_far(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'Expired', array('expiry_date' => gmdate('Y-m-d', time() - ( 10 * 86400 )))),
            $this->registration(2, 'Soon', array('expiry_date' => gmdate('Y-m-d', time() + ( 10 * 86400 )))),
            $this->registration(3, 'Far', array('expiry_date' => gmdate('Y-m-d', time() + ( 400 * 86400 )))),
            $this->registration(4, 'Undated'),
            $this->registration(5, 'Unparsable', array('expiry_date' => 'not-a-date')),
        ));

        $expired = $this->get($store)->execute(array('registration_id' => 1), array('user_id' => 7));
        $this->assertTrue($expired['data']['registration']['is_expired']);
        $this->assertFalse($expired['data']['registration']['expiring_soon']);

        $soon = $this->get($store)->execute(array('registration_id' => 2), array('user_id' => 7));
        $this->assertFalse($soon['data']['registration']['is_expired']);
        $this->assertTrue($soon['data']['registration']['expiring_soon']);
        $this->assertLessThanOrEqual(10, $soon['data']['registration']['days_to_expiry']);

        $far = $this->get($store)->execute(array('registration_id' => 3), array('user_id' => 7));
        $this->assertFalse($far['data']['registration']['is_expired']);
        $this->assertFalse($far['data']['registration']['expiring_soon']);

        $undated = $this->get($store)->execute(array('registration_id' => 4), array('user_id' => 7));
        $this->assertArrayNotHasKey('days_to_expiry', $undated['data']['registration']);

        $unparsable = $this->get($store)->execute(array('registration_id' => 5), array('user_id' => 7));
        $this->assertArrayNotHasKey('days_to_expiry', $unparsable['data']['registration']);
        $this->assertArrayNotHasKey('is_expired', $unparsable['data']['registration']);
    }

    public function test_include_product_returns_the_summary(): void
    {
        $store = $this->store(array(
            $this->product(4, 'Rose Serum', array('brand' => 'Acme', 'manufacturer' => 'Acme Works')),
            $this->registration(20, 'Rose - LK', array('product_id' => 4)),
        ));

        $result = $this->get($store)->execute(
            array('registration_id' => 20, 'include_product' => true),
            array('user_id' => 7)
        );

        $this->assertSame(
            array('id' => 4, 'name' => 'Rose Serum', 'brand' => 'Acme', 'manufacturer' => 'Acme Works'),
            $result['data']['registration']['product']
        );
    }

    public function test_include_documents_lists_only_this_registration(): void
    {
        $store = $this->store(array(
            $this->registration(20, 'Rose - LK'),
            $this->record(30, 'LOA', 'mcp_ai_reg_document', array(
                'registration_id' => 20,
                'document_type'   => 'LOA',
                'status'          => 'valid',
            )),
            $this->record(31, 'Other', 'mcp_ai_reg_document', array('registration_id' => 21)),
        ));

        $result = $this->get($store)->execute(
            array('registration_id' => 20, 'include_documents' => true),
            array('user_id' => 7)
        );

        $documents = $result['data']['registration']['documents'];

        $this->assertSame(1, $result['data']['registration']['document_count']);
        $this->assertSame('LOA', $documents[0]['type']);
        $this->assertSame('valid', $documents[0]['status']);
    }

    public function test_an_unknown_registration_is_not_found(): void
    {
        $result = $this->get($this->store())->execute(array('registration_id' => 99), array('user_id' => 7));

        $this->assertSame('not_found', $result['error']['code']);
    }

    // ─── List ────────────────────────────────────────────────────────────

    public function test_the_list_is_newest_first_and_carries_the_product_name(): void
    {
        $store = $this->store(array(
            $this->product(4, 'Rose Serum'),
            $this->registration(20, 'Older', array('product_id' => 4)),
            $this->registration(21, 'Newer', array('product_id' => 4)),
        ));

        $result = $this->list($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(2, $result['data']['total']);
        $this->assertSame(array('Newer', 'Older'), array_column($result['data']['registrations'], 'title'));
        $this->assertSame('Rose Serum', $result['data']['registrations'][0]['product_name']);
    }

    public function test_the_filters_are_anded_and_the_status_is_addressed_by_slug(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'A', array('country' => 'LK', 'registration_type' => 'new'), array('mcp_ai_reg_status' => array('Pending Documents'))),
            $this->registration(2, 'B', array('country' => 'LK', 'registration_type' => 'new'), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(3, 'C', array('country' => 'AE', 'registration_type' => 'new'), array('mcp_ai_reg_status' => array('Pending Documents'))),
        ));

        $result = $this->list($store)->execute(
            array('country' => 'LK', 'status' => 'pending_documents'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('A', $result['data']['registrations'][0]['title']);
        $this->assertSame('Pending Documents', $result['data']['registrations'][0]['status']);
    }

    public function test_the_expiry_window_excludes_undated_rows(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'Soon', array('expiry_date' => gmdate('Y-m-d', time() + ( 5 * 86400 )))),
            $this->registration(2, 'Later', array('expiry_date' => gmdate('Y-m-d', time() + ( 200 * 86400 )))),
            $this->registration(3, 'Undated'),
        ));

        $result = $this->list($store)->execute(array('expiring_within_days' => 30), array('user_id' => 7));

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('Soon', $result['data']['registrations'][0]['title']);
    }

    public function test_an_offset_off_the_page_boundary_is_refused_and_the_limit_is_clamped(): void
    {
        $store = $this->store(array($this->registration(1, 'A')));

        $refused = $this->list($store)->execute(array('limit' => 20, 'offset' => 5), array('user_id' => 7));
        $this->assertSame('validation_failed', $refused['error']['code']);

        $clamped = $this->list($store)->execute(array('limit' => 500), array('user_id' => 7));
        $this->assertSame(100, $clamped['data']['limit']);
    }

    public function test_the_capabilities_match_the_wordpress_checks(): void
    {
        $store = $this->store();

        $this->assertSame('edit_posts', $this->create($store)->getRequiredCapability());
        $this->assertSame('read', $this->get($store)->getRequiredCapability());
        $this->assertSame('read', $this->list($store)->getRequiredCapability());
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function create(ContentStoreInterface $store): CreateRegistrationTool
    {
        return new CreateRegistrationTool($this->errors, $store);
    }

    private function get(ContentStoreInterface $store): GetRegistrationTool
    {
        return new GetRegistrationTool($this->errors, $store);
    }

    private function list(ContentStoreInterface $store): ListRegistrationsTool
    {
        return new ListRegistrationsTool($this->errors, $store);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function product(int $id, string $title, array $meta = array()): ContentItem
    {
        return $this->record($id, $title, 'mcp_ai_reg_product', $meta);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function registration(int $id, string $title, array $meta = array(), array $terms = array()): ContentItem
    {
        return $this->record($id, $title, 'mcp_ai_registration', $meta, $terms);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function record(int $id, string $title, string $type, array $meta = array(), array $terms = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: $type,
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
            taxonomy: $terms,
        );
    }

    /**
     * A store that evaluates the query it is given and records its writes.
     *
     * @param array<int, ContentItem> $items
     */
    private function store(array $items = array()): ContentStoreInterface
    {
        return new class ($items) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items = array();

            /** @var list<array<string, mixed>> */
            public array $created = array();

            private int $nextId = 1;

            public function __construct(array $items)
            {
                foreach ($items as $item) {
                    $this->items[$item->id] = $item;
                    $this->nextId            = max($this->nextId, $item->id + 1);
                }
            }

            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                return $this->items[$id] ?? null;
            }

            public function query(ContentQuery $query): ContentCollection
            {
                $rows = $this->keep($query, array_values($this->items));

                usort($rows, static fn (ContentItem $a, ContentItem $b): int => $b->id <=> $a->id);

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

            /**
             * @param list<ContentItem> $rows
             *
             * @return list<ContentItem>
             */
            private function keep(ContentQuery $query, array $rows): array
            {
                if (array() !== $query->types) {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (ContentItem $i): bool => in_array($i->type, $query->types, true)
                    ));
                }

                if (array() !== $query->statuses) {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (ContentItem $i): bool => in_array($i->status, $query->statuses, true)
                    ));
                }

                foreach ($query->metaQuery as $clause) {
                    if (! is_array($clause) || ! isset($clause['key'])) {
                        continue;
                    }

                    $key     = (string) $clause['key'];
                    $value   = (string) ($clause['value'] ?? '');
                    $compare = (string) ($clause['compare'] ?? '=');

                    $rows = array_values(array_filter(
                        $rows,
                        static function (ContentItem $i) use ($key, $value, $compare): bool {
                            if (! array_key_exists($key, $i->meta)) {
                                return false;
                            }

                            $stored = (string) $i->meta[ $key ];

                            return match ($compare) {
                                'LIKE'  => false !== stripos($stored, $value),
                                '<='    => $stored <= $value,
                                '>='    => $stored >= $value,
                                default => $stored === $value,
                            };
                        }
                    ));
                }

                foreach ($query->taxQuery as $clause) {
                    if (! is_array($clause) || ! isset($clause['taxonomy'])) {
                        continue;
                    }

                    $taxonomy = (string) $clause['taxonomy'];
                    $terms    = (array) ($clause['terms'] ?? array());
                    $needle   = strtolower((string) reset($terms));

                    $rows = array_values(array_filter(
                        $rows,
                        static function (ContentItem $i) use ($taxonomy, $needle): bool {
                            foreach ($i->taxonomy[$taxonomy] ?? array() as $name) {
                                if (strtolower((string) $name) === $needle) {
                                    return true;
                                }
                            }

                            return false;
                        }
                    ));
                }

                return $rows;
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
                    taxonomy: $command->taxonomyInput,
                );

                $this->items[$id] = $item;
                $this->created[]  = array(
                    'id'       => $id,
                    'title'    => $command->title,
                    'content'  => $command->content,
                    'status'   => $command->status,
                    'type'     => $command->type,
                    'meta'     => $command->meta,
                    'taxonomy' => $command->taxonomyInput,
                );

                return $item;
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                throw new \LogicException('not used here.');
            }

            public function delete(int $id, int $userId): void
            {
                throw new \LogicException('not used here.');
            }

            public function getMeta(int $id): array
            {
                return $this->items[$id]->meta ?? array();
            }

            public function getTaxonomyTerms(int $id): array
            {
                return $this->items[$id]->taxonomy ?? array();
            }

            public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
            {
                return array();
            }

            public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
            {
                return isset($this->items[$id]);
            }
        };
    }
}
