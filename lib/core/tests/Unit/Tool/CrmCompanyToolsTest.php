<?php

/**
 * The two company tools: the meta family, the filter semantics, the shape.
 *
 * The assertions that matter are the storage contract and the filter
 * behaviour. The meta keys must be the WordPress ones so an export round-trips;
 * a substring filter must not become exact, and an exact filter must not become
 * a substring, because either drift silently returns the wrong companies.
 *
 * The store is stateful and evaluates the query it is given, so a test can
 * assert on the rows that come back rather than on the query object.
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
use Nvoos\Core\Tool\Crm\CreateCompanyTool;
use Nvoos\Core\Tool\Crm\GetCompaniesTool;
use PHPUnit\Framework\TestCase;

final class CrmCompanyToolsTest extends TestCase
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

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message),
            )
        );
    }

    public function test_a_company_needs_a_name_and_an_industry_and_both_are_named(): void
    {
        $store  = $this->store();
        $result = $this->create($store)->execute(array(), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('company_name', $result['error']['data']);
        $this->assertArrayHasKey('industry', $result['error']['data']);
        $this->assertCount(0, $store->created);
    }

    public function test_a_company_is_stored_with_the_wordpress_meta_family(): void
    {
        $store = $this->store();

        $result = $this->create($store)->execute(
            array(
                'company_name'  => 'Acme Ltd',
                'industry'      => 'Manufacturing',
                'company_size'  => '51-200',
                'website'       => 'https://acme.example',
                'description'   => 'Makes anvils.',
                'city'          => 'Bristol',
                'country'       => 'United Kingdom',
                'revenue'       => '1250000.5',
                'target_status' => 'target',
                'tags'          => 'industrial, priority',
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Acme Ltd', $result['data']['company_name']);
        $this->assertSame('Manufacturing', $result['data']['industry']);
        $this->assertNull($result['data']['edit_url']);

        $meta = $store->created[0]['meta'];
        $this->assertSame('Manufacturing', $meta['_company_industry']);
        $this->assertSame('51-200', $meta['_company_size']);
        $this->assertSame('https://acme.example', $meta['_company_website']);
        $this->assertSame('Bristol', $meta['_company_city']);
        $this->assertSame('United Kingdom', $meta['_company_country']);
        $this->assertSame('target', $meta['_company_target_status']);
        $this->assertSame('industrial, priority', $meta['_company_tags']);
        $this->assertSame(1250000.5, $meta['_company_revenue']);
        $this->assertSame('Makes anvils.', $store->created[0]['content']);
    }

    public function test_no_target_status_default_is_invented(): void
    {
        $store = $this->store();

        $this->create($store)->execute(
            array('company_name' => 'Acme Ltd', 'industry' => 'Manufacturing'),
            array('user_id' => 7)
        );

        $this->assertArrayNotHasKey('_company_target_status', $store->created[0]['meta']);
    }

    public function test_the_two_tools_gate_on_the_capabilities_wordpress_uses(): void
    {
        $store = $this->store();

        $this->assertSame('edit_posts', $this->create($store)->getRequiredCapability());
        $this->assertSame('read', $this->list($store)->getRequiredCapability());
    }

    public function test_industry_matches_as_a_substring_and_size_matches_exactly(): void
    {
        $store = $this->store(
            array(
                $this->company(1, 'Alpha', array('_company_industry' => 'Financial Services', '_company_size' => '51-200')),
                $this->company(2, 'Beta', array('_company_industry' => 'Healthcare', '_company_size' => '51-2000')),
            )
        );

        $industry = $this->list($store)->execute(
            array('industry' => 'Financial'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $industry['data']['total_companies']);
        $this->assertSame('Alpha', $industry['data']['companies'][0]['company_name']);

        $size = $this->list($store)->execute(
            array('company_size' => '51-200'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $size['data']['total_companies']);
        $this->assertSame('Alpha', $size['data']['companies'][0]['company_name']);
    }

    public function test_filters_are_anded_and_echoed(): void
    {
        $store = $this->store(
            array(
                $this->company(1, 'Alpha', array('_company_industry' => 'Finance', '_company_country' => 'Ireland')),
                $this->company(2, 'Beta', array('_company_industry' => 'Finance', '_company_country' => 'Spain')),
            )
        );

        $result = $this->list($store)->execute(
            array('industry' => 'Finance', 'country' => 'Ireland'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['total_companies']);
        $this->assertSame(
            array('industry' => 'Finance', 'country' => 'Ireland'),
            $result['data']['filters_applied']
        );
        $this->assertSame(1, $result['data']['current_page']);
        $this->assertSame(20, $result['data']['per_page']);
    }

    public function test_the_list_is_alphabetical_and_paginated(): void
    {
        $store = $this->store(
            array(
                $this->company(1, 'Zeta'),
                $this->company(2, 'Alpha'),
                $this->company(3, 'Mike'),
            )
        );

        $first = $this->list($store)->execute(array('per_page' => 2), array('user_id' => 7));

        $this->assertSame(3, $first['data']['total_companies']);
        $this->assertSame(2, $first['data']['total_pages']);
        $this->assertSame(array('Alpha', 'Mike'), array_column($first['data']['companies'], 'company_name'));

        $second = $this->list($store)->execute(array('per_page' => 2, 'page' => 2), array('user_id' => 7));

        $this->assertSame(array('Zeta'), array_column($second['data']['companies'], 'company_name'));
    }

    public function test_per_page_is_clamped_to_the_ceiling(): void
    {
        $store = $this->store(array($this->company(1, 'Alpha')));

        $result = $this->list($store)->execute(array('per_page' => 500), array('user_id' => 7));

        $this->assertSame(100, $result['data']['per_page']);
    }

    public function test_a_search_matches_name_or_description(): void
    {
        $store = $this->store(
            array(
                $this->company(1, 'Alpha'),
                $this->company(2, 'Beta', array(), 'Anvil supplier'),
                $this->company(3, 'Gamma'),
            )
        );

        $result = $this->list($store)->execute(array('search' => 'anvil'), array('user_id' => 7));

        $this->assertSame(1, $result['data']['total_companies']);
        $this->assertSame('Beta', $result['data']['companies'][0]['company_name']);
    }

    public function test_a_row_carries_the_documented_shape_including_a_null_edit_url(): void
    {
        $store = $this->store(
            array($this->company(9, 'Alpha', array('_company_industry' => 'Finance', '_company_revenue' => '1000')))
        );

        $result = $this->list($store)->execute(array(), array('user_id' => 7));
        $row    = $result['data']['companies'][0];

        $this->assertSame(
            array(
                'id', 'company_name', 'description', 'industry', 'company_size', 'website',
                'city', 'state', 'country', 'phone', 'revenue', 'target_status',
                'linkedin', 'twitter', 'edit_url', 'created_date',
            ),
            array_keys($row)
        );
        $this->assertNull($row['edit_url']);
        $this->assertSame(1000.0, $row['revenue']);
        $this->assertMatchesRegularExpression('/^2026-01-01 09:00:00$/', $row['created_date']);
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    private function create(ContentStoreInterface $store): CreateCompanyTool
    {
        return new CreateCompanyTool($this->errors, $store);
    }

    private function list(ContentStoreInterface $store): GetCompaniesTool
    {
        return new GetCompaniesTool($this->errors, $store);
    }

    private function company(int $id, string $title, array $meta = array(), string $content = ''): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: $content,
            status: 'publish',
            type: 'mcp_ai_company',
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
        );
    }

    /**
     * A store that evaluates the query it is given.
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

                usort($rows, static fn (ContentItem $a, ContentItem $b): int => strcmp($a->title, $b->title));

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

                if (null !== $query->search && '' !== $query->search) {
                    $needle = strtolower($query->search);
                    $rows   = array_values(array_filter(
                        $rows,
                        static fn (ContentItem $i): bool => str_contains(strtolower($i->title), $needle)
                            || str_contains(strtolower($i->content), $needle)
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
                            $stored = (string) ($i->meta[$key] ?? '');

                            return 'LIKE' === $compare
                                ? false !== stripos($stored, $value)
                                : $stored === $value;
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
                );

                $this->items[$id] = $item;
                $this->created[]  = array(
                    'id'      => $id,
                    'title'   => $command->title,
                    'content' => $command->content,
                    'meta'    => $command->meta,
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
