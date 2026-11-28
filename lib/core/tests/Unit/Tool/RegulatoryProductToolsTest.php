<?php

/**
 * The first regulatory-registration slice: product create, list and get.
 *
 * Proposal 164 section 164.144. What is pinned: the stored meta keys are the
 * WordPress ones (unprefixed, so an export round-trips), the category and brand
 * are written to their taxonomies as well as to `brand` meta, the list keeps
 * WordPress's filter semantics and refuses an offset that cannot be addressed
 * page-by-page, and the two product projections stay different — the list does
 * not carry a page of ingredient lists.
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
use Nvoos\Core\Tool\Regulatory\CreateRegProductTool;
use Nvoos\Core\Tool\Regulatory\GetRegProductTool;
use Nvoos\Core\Tool\Regulatory\ListRegProductsTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryProductToolsTest extends TestCase
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

    public function test_a_product_needs_a_name(): void
    {
        $store  = $this->store();
        $result = $this->create($store)->execute(array('brand' => 'Acme'), array('user_id' => 7));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('product_name', $result['error']['data']);
        $this->assertCount(0, $store->created);
    }

    public function test_a_product_is_stored_with_the_wordpress_meta_keys(): void
    {
        $store = $this->store();

        $result = $this->create($store)->execute(
            array(
                'product_name'       => 'Rose Serum',
                'brand'              => 'Acme Beauty',
                'supplier_reference' => 'SUP-9',
                'item_group'         => 'Serums',
                'origin_country'     => 'LK',
                'manufacturer'       => 'Acme Works',
                'inci_ingredients'   => 'Aqua, Rosa Damascena',
                'allergens'          => 'Limonene',
                'hs_code'            => '3304.99',
                'barcode'            => '8901234567890',
                'pack_size'          => '30ml',
                'variant'            => '30ml',
                'description'        => 'A rose serum.',
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['product_id']);
        $this->assertNull($result['data']['edit_url']);
        $this->assertSame('Product "Rose Serum" created successfully.', $result['message']);

        $meta = $store->created[0]['meta'];
        $this->assertSame('Acme Beauty', $meta['brand']);
        $this->assertSame('SUP-9', $meta['supplier_reference']);
        $this->assertSame('Serums', $meta['item_group']);
        $this->assertSame('LK', $meta['origin_country']);
        $this->assertSame('Acme Works', $meta['manufacturer']);
        $this->assertSame('Aqua, Rosa Damascena', $meta['inci_ingredients']);
        $this->assertSame('Limonene', $meta['allergens']);
        $this->assertSame('3304.99', $meta['hs_code']);
        $this->assertSame('8901234567890', $meta['barcode']);
        $this->assertSame('30ml', $meta['pack_size']);
        $this->assertSame('30ml', $meta['variant']);
        $this->assertSame('A rose serum.', $store->created[0]['content']);
        $this->assertSame('mcp_ai_reg_product', $store->created[0]['type']);
    }

    public function test_the_category_is_lower_cased_and_the_brand_becomes_a_term(): void
    {
        $store = $this->store();

        $this->create($store)->execute(
            array('product_name' => 'Rose Serum', 'brand' => 'Acme Beauty', 'category' => 'Skincare'),
            array('user_id' => 7)
        );

        $taxonomy = $store->created[0]['taxonomy'];

        $this->assertSame(array('skincare'), $taxonomy['mcp_ai_reg_category']);
        $this->assertSame(array('Acme Beauty'), $taxonomy['mcp_ai_reg_brand']);
    }

    // ─── Get ─────────────────────────────────────────────────────────────

    public function test_get_returns_the_full_row_including_the_ingredient_lists(): void
    {
        $store = $this->store(array(
            $this->product(4, 'Rose Serum', array(
                'brand'            => 'meta-brand',
                'inci_ingredients' => 'Aqua',
                'allergens'        => 'Limonene',
            ), array('mcp_ai_reg_category' => array('Skincare', 'Serums'), 'mcp_ai_reg_brand' => array('Term Brand'))),
        ));

        $result = $this->get($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $row = $result['data']['product'];

        $this->assertSame('Rose Serum', $row['name']);
        $this->assertSame('Aqua', $row['inci_ingredients']);
        $this->assertSame('Limonene', $row['allergens']);
        $this->assertSame('Skincare', $row['category']);
        $this->assertSame(array('Skincare', 'Serums'), $row['categories']);
        // The term is the canonical spelling; the meta copy is the fallback.
        $this->assertSame('Term Brand', $row['brand']);
        $this->assertSame('2026-01-01 09:00:00', $row['created_date']);
        $this->assertSame('2026-01-02 09:00:00', $row['modified_date']);
    }

    public function test_a_product_without_terms_falls_back_to_the_brand_meta(): void
    {
        $store = $this->store(array($this->product(4, 'Rose Serum', array('brand' => 'Meta Brand'))));

        $result = $this->get($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $this->assertSame('Meta Brand', $result['data']['product']['brand']);
        $this->assertArrayNotHasKey('category', $result['data']['product']);
    }

    public function test_an_unknown_product_is_not_found(): void
    {
        $result = $this->get($this->store())->execute(array('product_id' => 99), array('user_id' => 7));

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_include_registrations_appends_the_rows_with_their_status(): void
    {
        $store = $this->store(array(
            $this->product(4, 'Rose Serum'),
            $this->registration(20, 'NMRA Rose', array('product_id' => '4', 'country' => 'LK', 'cos_number' => 'COS-1'), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(21, 'MOHAP Rose', array('product_id' => '4', 'country' => 'AE')),
            $this->registration(22, 'Other product', array('product_id' => '5')),
        ));

        $result = $this->get($store)->execute(
            array('product_id' => 4, 'include_registrations' => true),
            array('user_id' => 7)
        );

        $product = $result['data']['product'];

        $this->assertSame(2, $product['registration_count']);

        $ids = array_column($product['registrations'], 'id');
        sort($ids);
        $this->assertSame(array(20, 21), $ids);

        $byId = array_column($product['registrations'], null, 'id');
        $this->assertSame('Approved', $byId[20]['status']);
        $this->assertArrayNotHasKey('status', $byId[21]);
        $this->assertSame('LK', $byId[20]['country']);
        $this->assertSame('COS-1', $byId[20]['cos_number']);
    }

    // ─── List ────────────────────────────────────────────────────────────

    public function test_the_list_is_alphabetical_and_reports_pagination(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Zeta'),
            $this->product(2, 'Alpha'),
            $this->product(3, 'Mike'),
        ));

        $first = $this->list($store)->execute(array('limit' => 2), array('user_id' => 7));

        $this->assertSame(3, $first['data']['total']);
        $this->assertSame(2, $first['data']['returned']);
        $this->assertSame(2, $first['data']['limit']);
        $this->assertSame(0, $first['data']['offset']);
        $this->assertTrue($first['data']['has_more']);
        $this->assertSame(array('Alpha', 'Mike'), array_column($first['data']['products'], 'name'));

        $second = $this->list($store)->execute(array('limit' => 2, 'offset' => 2), array('user_id' => 7));

        $this->assertSame(array('Zeta'), array_column($second['data']['products'], 'name'));
        $this->assertFalse($second['data']['has_more']);
    }

    public function test_an_offset_off_the_page_boundary_is_refused(): void
    {
        $store  = $this->store(array($this->product(1, 'Alpha')));
        $result = $this->list($store)->execute(array('limit' => 20, 'offset' => 5), array('user_id' => 7));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('offset', $result['error']['data']);
    }

    public function test_the_limit_is_clamped_to_the_ceiling(): void
    {
        $result = $this->list($this->store())->execute(array('limit' => 500), array('user_id' => 7));

        $this->assertSame(100, $result['data']['limit']);
    }

    public function test_the_filters_are_anded_and_the_country_matches_exactly(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose', array('origin_country' => 'LK'), array('mcp_ai_reg_category' => array('skincare'))),
            $this->product(2, 'Jasmine', array('origin_country' => 'LK'), array('mcp_ai_reg_category' => array('perfumes'))),
            $this->product(3, 'Sandal', array('origin_country' => 'LK-South'), array('mcp_ai_reg_category' => array('skincare'))),
        ));

        $result = $this->list($store)->execute(
            array('category' => 'skincare', 'origin_country' => 'LK'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('Rose', $result['data']['products'][0]['name']);
    }

    public function test_the_brand_filter_matches_a_term_name(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose', array(), array('mcp_ai_reg_brand' => array('Acme'))),
            $this->product(2, 'Jasmine', array(), array('mcp_ai_reg_brand' => array('Other'))),
        ));

        $result = $this->list($store)->execute(array('brand' => 'Acme'), array('user_id' => 7));

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('Rose', $result['data']['products'][0]['name']);
    }

    public function test_the_list_row_is_lighter_than_the_get_row(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose', array('inci_ingredients' => 'Aqua', 'allergens' => 'Limonene')),
        ));

        $result = $this->list($store)->execute(array(), array('user_id' => 7));

        $this->assertArrayNotHasKey('inci_ingredients', $result['data']['products'][0]);
        $this->assertArrayNotHasKey('allergens', $result['data']['products'][0]);
    }

    public function test_the_tools_gate_on_the_capabilities_wordpress_uses(): void
    {
        $store = $this->store();

        $this->assertSame('edit_posts', $this->create($store)->getRequiredCapability());
        $this->assertSame('read', $this->list($store)->getRequiredCapability());
        $this->assertSame('read', $this->get($store)->getRequiredCapability());
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function create(ContentStoreInterface $store): CreateRegProductTool
    {
        return new CreateRegProductTool($this->errors, $store);
    }

    private function list(ContentStoreInterface $store): ListRegProductsTool
    {
        return new ListRegProductsTool($this->errors, $store);
    }

    private function get(ContentStoreInterface $store): GetRegProductTool
    {
        return new GetRegProductTool($this->errors, $store);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function product(int $id, string $title, array $meta = array(), array $terms = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: 'mcp_ai_reg_product',
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
            taxonomy: $terms,
        );
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function registration(int $id, string $title, array $meta = array(), array $terms = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: 'mcp_ai_registration',
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
            taxonomy: $terms,
        );
    }

    /**
     * A store that evaluates the query it is given, including tax clauses.
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
