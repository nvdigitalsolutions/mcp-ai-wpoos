<?php

/**
 * The second regulatory-registration slice: product update, delete, duplicate,
 * search.
 *
 * Proposal 164 section 164.145. What is pinned: an update touches only the
 * fields supplied but writes `brand` to both the term and the meta (WordPress
 * wrote the term only), the category is lower-cased to match create and list,
 * the delete does not pretend to cascade, duplicate applies its documented
 * defaults rather than `! empty()` semantics, a copied registration carries the
 * reference fields and never the COS number, and the search row is trimmed to
 * 30 words with a character rather than an HTML entity.
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
use Nvoos\Core\Tool\Regulatory\DeleteRegProductTool;
use Nvoos\Core\Tool\Regulatory\DuplicateRegProductTool;
use Nvoos\Core\Tool\Regulatory\SearchRegProductsTool;
use Nvoos\Core\Tool\Regulatory\UpdateRegProductTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryProductWritesTest extends TestCase
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

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message),
            )
        );
    }

    // ─── Update ──────────────────────────────────────────────────────────

    public function test_an_update_touches_only_the_fields_supplied(): void
    {
        $store = $this->store(array($this->product(4, 'Rose', array('brand' => 'Old', 'hs_code' => '3304'))));

        $result = $this->update($store)->execute(
            array('product_id' => 4, 'hs_code' => '3304.99'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('hs_code' => '3304.99'), $store->updated[0]['meta']);
        $this->assertSame('Old', $store->items[4]->meta['brand']);
    }

    public function test_an_update_writes_the_brand_to_meta_and_to_the_term(): void
    {
        $store = $this->store(array($this->product(4, 'Rose', array('brand' => 'Old'))));

        $this->update($store)->execute(
            array('product_id' => 4, 'brand' => 'New Brand'),
            array('user_id' => 7)
        );

        $this->assertSame('New Brand', $store->updated[0]['meta']['brand']);
        $this->assertSame(array('New Brand'), $store->items[4]->taxonomy['mcp_ai_reg_brand']);
    }

    public function test_an_update_lower_cases_the_category(): void
    {
        $store = $this->store(array($this->product(4, 'Rose')));

        $this->update($store)->execute(
            array('product_id' => 4, 'category' => 'Skincare'),
            array('user_id' => 7)
        );

        $this->assertSame(array('skincare'), $store->items[4]->taxonomy['mcp_ai_reg_category']);
    }

    public function test_a_category_written_through_update_is_found_by_search(): void
    {
        $store = $this->store(array($this->product(4, 'Rose')));

        $this->update($store)->execute(array('product_id' => 4, 'category' => 'Skincare'), array('user_id' => 7));

        $result = $this->search($store)->execute(array('category' => 'skincare'), array('user_id' => 7));

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('Rose', $result['data']['products'][0]['name']);
    }

    public function test_an_empty_string_clears_a_meta_field(): void
    {
        $store = $this->store(array($this->product(4, 'Rose', array('allergens' => 'Limonene'))));

        $this->update($store)->execute(
            array('product_id' => 4, 'allergens' => ''),
            array('user_id' => 7)
        );

        $this->assertSame('', $store->items[4]->meta['allergens']);
    }

    public function test_a_blank_name_is_refused_rather_than_blanking_the_product(): void
    {
        $store  = $this->store(array($this->product(4, 'Rose')));
        $result = $this->update($store)->execute(
            array('product_id' => 4, 'name' => '   '),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('name', $result['error']['data']);
        $this->assertSame(array(), $store->updated);
    }

    public function test_updating_an_unknown_product_is_not_found(): void
    {
        $result = $this->update($this->store())->execute(
            array('product_id' => 99, 'hs_code' => '1'),
            array('user_id' => 7)
        );

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_the_update_response_is_the_full_row(): void
    {
        $store = $this->store(array($this->product(4, 'Rose', array('inci_ingredients' => 'Aqua'))));

        $result = $this->update($store)->execute(
            array('product_id' => 4, 'manufacturer' => 'Acme'),
            array('user_id' => 7)
        );

        $this->assertSame('Aqua', $result['data']['product']['inci_ingredients']);
        $this->assertArrayHasKey('created_date', $result['data']['product']);
        $this->assertSame('Product updated successfully.', $result['message']);
    }

    // ─── Delete ──────────────────────────────────────────────────────────

    public function test_delete_removes_the_product_and_returns_its_id(): void
    {
        $store = $this->store(array($this->product(4, 'Rose')));

        $result = $this->delete($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(array(4), $store->deleted);
        $this->assertSame(4, $result['data']['product_id']);
        $this->assertSame('delete_posts', $this->delete($store)->getRequiredCapability());
    }

    public function test_delete_refuses_a_negative_id_and_an_unknown_one(): void
    {
        $store = $this->store(array($this->product(4, 'Rose')));

        $this->assertSame(
            'validation_failed',
            $this->delete($store)->execute(array('product_id' => -4), array('user_id' => 7))['error']['code']
        );
        $this->assertSame(
            'not_found',
            $this->delete($store)->execute(array('product_id' => 99), array('user_id' => 7))['error']['code']
        );
        $this->assertSame(array(), $store->deleted);
    }

    // ─── Duplicate ───────────────────────────────────────────────────────

    public function test_duplicate_defaults_to_copying_meta_and_taxonomies_into_a_draft(): void
    {
        $store = $this->store(array(
            $this->product(4, 'Rose', array('brand' => 'Acme', 'hs_code' => '3304'), array('mcp_ai_reg_category' => array('skincare'))),
        ));

        $result = $this->duplicate($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('[Copy] Rose', $result['data']['new_title']);
        $this->assertSame(4, $result['data']['source_product_id']);

        $copy = $store->created[0];
        $this->assertSame('draft', $copy['status']);
        $this->assertSame('Acme', $copy['meta']['brand']);
        $this->assertSame('3304', $copy['meta']['hs_code']);
        $this->assertSame(array('skincare'), $copy['taxonomy']['mcp_ai_reg_category']);
        $this->assertTrue($result['data']['copied_items']['product']);
        $this->assertTrue($result['data']['copied_items']['metadata']);
    }

    public function test_duplicate_honours_explicit_false_flags(): void
    {
        $store = $this->store(array(
            $this->product(4, 'Rose', array('brand' => 'Acme'), array('mcp_ai_reg_category' => array('skincare'))),
        ));

        $result = $this->duplicate($store)->execute(
            array('product_id' => 4, 'copy_meta' => false, 'copy_taxonomies' => false),
            array('user_id' => 7)
        );

        $this->assertSame(array(), $store->created[0]['meta']);
        $this->assertSame(array(), $store->created[0]['taxonomy']);
        $this->assertArrayNotHasKey('metadata', $result['data']['copied_items']);
        $this->assertArrayNotHasKey('taxonomies', $result['data']['copied_items']);
    }

    public function test_duplicate_copies_registrations_as_drafts_without_the_evidence(): void
    {
        $store = $this->store(array(
            $this->product(4, 'Rose'),
            $this->record(20, 'NMRA Rose', 'mcp_ai_registration', array(
                'product_id' => 4,
                'country'    => 'LK',
                'authority'  => 'NMRA',
                'cos_number' => 'COS-1',
                'approval_date' => '2026-01-01',
            )),
        ));

        $result = $this->duplicate($store)->execute(
            array('product_id' => 4, 'copy_registrations' => true),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['copied_items']['registrations']);

        $copy = $store->created[1];
        $this->assertSame('mcp_ai_registration', $copy['type']);
        $this->assertSame('draft', $copy['status']);
        $this->assertSame($result['data']['new_product_id'], $copy['meta']['product_id']);
        $this->assertSame('LK', $copy['meta']['country']);
        $this->assertArrayNotHasKey('cos_number', $copy['meta']);
        $this->assertArrayNotHasKey('approval_date', $copy['meta']);
    }

    public function test_duplicate_copies_documents_and_counts_them(): void
    {
        $store = $this->store(array(
            $this->product(4, 'Rose'),
            $this->record(30, 'LOA', 'mcp_ai_reg_document', array(
                'product_id'    => 4,
                'document_type' => 'LOA',
                'file_url'      => 'https://example.test/loa.pdf',
                'version'       => '2',
            )),
        ));

        $result = $this->duplicate($store)->execute(
            array('product_id' => 4, 'copy_documents' => true),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['copied_items']['documents']);
        $this->assertSame('https://example.test/loa.pdf', $store->created[1]['meta']['file_url']);
        $this->assertSame('publish', $store->created[1]['status']);
    }

    // ─── Search ──────────────────────────────────────────────────────────

    public function test_the_search_filters_are_anded_with_the_right_match_modes(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Rose', array('manufacturer' => 'Acme Works', 'hs_code' => '3304.99', 'inci_ingredients' => 'Aqua, Rosa'), array('mcp_ai_reg_category' => array('skincare'))),
            $this->product(2, 'Jasmine', array('manufacturer' => 'Acme Works', 'hs_code' => '3304.91', 'inci_ingredients' => 'Aqua'), array('mcp_ai_reg_category' => array('skincare'))),
        ));

        $byManufacturer = $this->search($store)->execute(
            array('manufacturer' => 'Acme', 'category' => 'skincare'),
            array('user_id' => 7)
        );
        $this->assertSame(2, $byManufacturer['data']['total']);

        $byCode = $this->search($store)->execute(array('hs_code' => '3304.9'), array('user_id' => 7));
        $this->assertSame(0, $byCode['data']['total']);

        $byIngredient = $this->search($store)->execute(array('ingredients' => 'rosa'), array('user_id' => 7));
        $this->assertSame(1, $byIngredient['data']['total']);
        $this->assertSame('Rose', $byIngredient['data']['products'][0]['name']);
    }

    public function test_the_search_row_trims_the_description_to_thirty_words(): void
    {
        $words = array();

        for ($i = 1; $i <= 40; ++$i) {
            $words[] = 'w' . $i;
        }

        $store = $this->store(array($this->product(1, 'Rose', array(), array(), implode(' ', $words))));

        $result = $this->search($store)->execute(array(), array('user_id' => 7));
        $row    = $result['data']['products'][0];

        $this->assertSame(30, count(explode(' ', $row['description'])));
        $this->assertStringEndsWith('…', $row['description']);
        $this->assertStringStartsWith('w1 w2', $row['description']);
        $this->assertArrayNotHasKey('inci_ingredients', $row);
    }

    public function test_the_search_reports_its_pagination(): void
    {
        $store = $this->store(array(
            $this->product(1, 'Alpha'),
            $this->product(2, 'Beta'),
            $this->product(3, 'Gamma'),
        ));

        $result = $this->search($store)->execute(array('per_page' => 2), array('user_id' => 7));

        $this->assertSame(3, $result['data']['total']);
        $this->assertSame(2, $result['data']['per_page']);
        $this->assertSame(2, $result['data']['total_pages']);
        $this->assertSame('read', $this->search($store)->getRequiredCapability());
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function update(ContentStoreInterface $store): UpdateRegProductTool
    {
        return new UpdateRegProductTool($this->errors, $store);
    }

    private function delete(ContentStoreInterface $store): DeleteRegProductTool
    {
        return new DeleteRegProductTool($this->errors, $store);
    }

    private function duplicate(ContentStoreInterface $store): DuplicateRegProductTool
    {
        return new DuplicateRegProductTool($this->errors, $store);
    }

    private function search(ContentStoreInterface $store): SearchRegProductsTool
    {
        return new SearchRegProductsTool($this->errors, $store);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function product(int $id, string $title, array $meta = array(), array $terms = array(), string $content = ''): ContentItem
    {
        return $this->record($id, $title, 'mcp_ai_reg_product', $meta, $terms, $content);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function record(int $id, string $title, string $type, array $meta = array(), array $terms = array(), string $content = ''): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: $content,
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

            /** @var list<array<string, mixed>> */
            public array $updated = array();

            /** @var list<int> */
            public array $deleted = array();

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
                    excerpt: $command->excerpt,
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
                $current = $this->items[$id];

                $meta = array_merge($current->meta, $command->meta);
                $tax  = $current->taxonomy;

                foreach ($command->taxonomyInput as $taxonomy => $terms) {
                    $tax[ $taxonomy ] = $terms;
                }

                $updated = new ContentItem(
                    id: $current->id,
                    title: $command->title ?? $current->title,
                    content: $command->content ?? $current->content,
                    status: $command->status ?? $current->status,
                    type: $current->type,
                    authorId: $current->authorId,
                    createdAt: $current->createdAt,
                    updatedAt: new \DateTimeImmutable('2026-01-03T09:00:00+00:00'),
                    meta: $meta,
                    taxonomy: $tax,
                );

                $this->items[$id] = $updated;
                $this->updated[]  = array('id' => $id, 'meta' => $command->meta, 'taxonomy' => $command->taxonomyInput);

                return $updated;
            }

            public function delete(int $id, int $userId): void
            {
                unset($this->items[$id]);
                $this->deleted[] = $id;
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
