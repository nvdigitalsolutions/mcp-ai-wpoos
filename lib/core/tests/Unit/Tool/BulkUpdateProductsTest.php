<?php

/**
 * The ecommerce bulk slice: one plan applied across many products.
 *
 * Proposal 164 section 164.173. What is pinned here is the arithmetic and the
 * refusals, not the plumbing: a percentage or fixed adjustment moves the charged
 * price and stops at zero; a dry run reports changes without writing; a value
 * that is already set is not rewritten; and every field this model cannot
 * express is refused by name rather than dropped.
 *
 * `ProductVariant` and `Product` are readonly entities, so a write produces a
 * new value. The double records what was handed to `saveVariant`, because
 * reading the original entity would assert that nothing happened.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductVariant;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OptionSelection;
use Nvoos\Core\Domain\ValueObject\ProductStatus;
use Nvoos\Core\Tests\Support\MultiProductCatalogFake;
use Nvoos\Core\Tool\Ecommerce\BulkUpdateProductsTool;
use PHPUnit\Framework\TestCase;

final class BulkUpdateProductsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    private MultiProductCatalogFake $catalog;

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
    }

    // ── metadata and guards ──────────────────────────────────

    public function test_the_tool_metadata(): void
    {
        $tool = $this->tool($this->defaultCatalog());
        $schema = $tool->getParametersSchema();

        $this->assertSame('bulk_update_products', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertSame(array('updates'), $schema['required']);
        $this->assertSame(array('product', 'variations', 'all'), $schema['properties']['scope']['enum']);
        $this->assertArrayNotHasKey('status', $schema['properties']['updates']['properties']);
        $this->assertArrayNotHasKey('add_tags', $schema['properties']['updates']['properties']);
        $this->assertArrayNotHasKey('category', $schema['properties']['filter']['properties']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool($this->defaultCatalog())->execute(array('updates' => array('sale_price' => 5)), array());
    }

    public function test_updates_must_be_an_object(): void
    {
        $result = $this->executeTool(array('updates' => 'nope'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('updates', $result['error']['data']);
    }

    public function test_empty_updates_is_refused(): void
    {
        $result = $this->executeTool(array('updates' => array()));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('updates', $result['error']['data']);
        $this->assertSame(0, $this->catalog->saves);
    }

    public function test_an_unknown_update_field_is_refused(): void
    {
        $result = $this->executeTool(array('updates' => array('foo' => 1)));

        $this->assertFalse($result['success']);
        $this->assertSame(array('Unknown field.'), $result['error']['data']['updates.foo']);
    }

    // ── price ────────────────────────────────────────────────

    public function test_sale_price_sets_the_charged_price(): void
    {
        $result = $this->executeTool(array('updates' => array('sale_price' => 12.5), 'scope' => 'product'));

        $this->assertTrue($result['success']);
        $this->assertSame(1250, $this->catalog->saved[0]->price->minorUnits);
        $this->assertSame(1, $result['data']['updated']);
        // The comparison price is untouched by a sale.
        $this->assertSame(2499, $this->catalog->saved[0]->compareAtPrice?->minorUnits);
    }

    public function test_regular_price_sets_the_comparison_price(): void
    {
        $result = $this->executeTool(array('updates' => array('regular_price' => 29.99), 'scope' => 'product'));

        $this->assertTrue($result['success']);
        $this->assertSame(2999, $this->catalog->saved[0]->compareAtPrice?->minorUnits);
        $this->assertSame(1999, $this->catalog->saved[0]->price->minorUnits);
    }

    public function test_a_percentage_increase_moves_the_charged_price(): void
    {
        $result = $this->executeTool(array(
            'updates' => array(
                'price_adjustment' => array('type' => 'percentage', 'value' => 10, 'action' => 'increase'),
            ),
            'scope'   => 'product',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(2199, $this->catalog->saved[0]->price->minorUnits);
    }

    public function test_a_percentage_decrease_moves_the_charged_price(): void
    {
        $result = $this->executeTool(array(
            'updates' => array(
                'price_adjustment' => array('type' => 'percentage', 'value' => 10, 'action' => 'decrease'),
            ),
            'scope'   => 'product',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(1799, $this->catalog->saved[0]->price->minorUnits);
    }

    public function test_a_fixed_increase_moves_the_charged_price(): void
    {
        $result = $this->executeTool(array(
            'updates' => array('price_adjustment' => array('type' => 'fixed', 'value' => 5)),
            'scope'   => 'product',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(2499, $this->catalog->saved[0]->price->minorUnits);
    }

    public function test_a_fixed_decrease_stops_at_zero(): void
    {
        $result = $this->executeTool(array(
            'updates' => array('price_adjustment' => array('type' => 'fixed', 'value' => 25, 'action' => 'decrease')),
            'scope'   => 'product',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->catalog->saved[0]->price->minorUnits);
    }

    public function test_an_unusable_price_is_refused(): void
    {
        $result = $this->executeTool(array('updates' => array('sale_price' => 'abc')));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('updates.sale_price', $result['error']['data']);
        $this->assertSame(0, $this->catalog->saves);
    }

    public function test_an_unknown_adjustment_type_is_refused(): void
    {
        $result = $this->executeTool(array(
            'updates' => array('price_adjustment' => array('type' => 'multiply', 'value' => 2)),
        ));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('updates.price_adjustment.type', $result['error']['data']);
    }

    public function test_sale_price_and_adjustment_together_are_refused(): void
    {
        $result = $this->executeTool(array(
            'updates' => array(
                'sale_price'       => 10,
                'price_adjustment' => array('type' => 'fixed', 'value' => 2),
            ),
        ));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('updates.price_adjustment', $result['error']['data']);
        $this->assertSame(0, $this->catalog->saves);
    }

    // ── stock ────────────────────────────────────────────────

    public function test_stock_quantity_sets_the_quantity(): void
    {
        $result = $this->executeTool(array('updates' => array('stock_quantity' => 42), 'scope' => 'product'));

        $this->assertTrue($result['success']);
        $this->assertSame(42, $this->catalog->saved[0]->stock);
        $this->assertTrue($this->catalog->saved[0]->trackInventory);
    }

    public function test_outofstock_sets_zero(): void
    {
        $result = $this->executeTool(array('updates' => array('stock_status' => 'outofstock'), 'scope' => 'product'));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->catalog->saved[0]->stock);
    }

    public function test_instock_lifts_a_zero_quantity_to_one(): void
    {
        $catalog = new MultiProductCatalogFake($this->zeroStockProduct());

        $result = $this->tool($catalog)->execute(
            array('updates' => array('stock_status' => 'instock'), 'scope' => 'product'),
            $this->context()
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $catalog->saved[0]->stock);
        $this->assertTrue($catalog->saved[0]->trackInventory);
    }

    public function test_instock_does_not_rewrite_a_positive_quantity(): void
    {
        $result = $this->executeTool(array('updates' => array('stock_status' => 'instock'), 'scope' => 'product'));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->catalog->saves);
        $this->assertSame(0, $result['data']['updated']);
        $this->assertSame(1, $result['data']['unchanged']);
    }

    public function test_onbackorder_is_refused_by_name(): void
    {
        $result = $this->executeTool(array('updates' => array('stock_status' => 'onbackorder')));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('updates.stock_status', $result['error']['data']);
        $this->assertStringContainsString(
            'backorder',
            $result['error']['data']['updates.stock_status'][0]
        );
        $this->assertSame(0, $this->catalog->saves);
    }

    public function test_manage_stock_false_clears_the_quantity(): void
    {
        $result = $this->executeTool(array('updates' => array('manage_stock' => false), 'scope' => 'product'));

        $this->assertTrue($result['success']);
        $this->assertNull($this->catalog->saved[0]->stock);
        $this->assertFalse($this->catalog->saved[0]->trackInventory);
    }

    public function test_manage_stock_true_enables_tracking(): void
    {
        $catalog = new MultiProductCatalogFake($this->untrackedProduct());

        $result = $this->tool($catalog)->execute(
            array('updates' => array('manage_stock' => true), 'scope' => 'product'),
            $this->context()
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($catalog->saved[0]->trackInventory);
        $this->assertNull($catalog->saved[0]->stock);
    }

    public function test_manage_stock_false_with_a_quantity_is_refused(): void
    {
        $result = $this->executeTool(array(
            'updates' => array('manage_stock' => false, 'stock_quantity' => 4),
        ));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('updates.stock_quantity', $result['error']['data']);
    }

    public function test_manage_stock_false_with_a_status_is_refused(): void
    {
        $result = $this->executeTool(array(
            'updates' => array('manage_stock' => false, 'stock_status' => 'instock'),
        ));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('updates.stock_status', $result['error']['data']);
    }

    // ── scope ────────────────────────────────────────────────

    public function test_scope_product_updates_only_the_first_variant(): void
    {
        $result = $this->executeTool(array('updates' => array('sale_price' => 5), 'scope' => 'product'));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->catalog->saved);
        $this->assertSame(11, $this->catalog->saved[0]->id);
    }

    public function test_scope_variations_updates_only_the_later_variants(): void
    {
        $result = $this->executeTool(array('updates' => array('sale_price' => 5), 'scope' => 'variations'));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->catalog->saved);
        $this->assertSame(12, $this->catalog->saved[0]->id);
    }

    public function test_scope_defaults_to_all(): void
    {
        $result = $this->executeTool(array('updates' => array('sale_price' => 5)));

        $this->assertTrue($result['success']);
        $this->assertSame('all', $result['data']['scope']);
        $this->assertCount(2, $this->catalog->saved);
    }

    public function test_an_unknown_scope_is_refused(): void
    {
        $result = $this->executeTool(array('updates' => array('sale_price' => 5), 'scope' => 'everything'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('scope', $result['error']['data']);
    }

    // ── selection ────────────────────────────────────────────

    public function test_product_ids_win_over_filter(): void
    {
        $result = $this->executeTool(array(
            'updates'     => array('sale_price' => 5),
            'product_ids' => array(1),
            'filter'      => array('category' => 'shoes'),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $this->catalog->queries);
        $this->assertSame(1, $result['data']['matched']);
    }

    public function test_missing_ids_are_skipped(): void
    {
        $result = $this->executeTool(array(
            'updates'     => array('sale_price' => 5),
            'product_ids' => array(1, 99),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['matched']);
        $this->assertCount(2, $this->catalog->saved);
    }

    public function test_no_products_matched(): void
    {
        $catalog = new MultiProductCatalogFake();

        $result = $this->tool($catalog)->execute(
            array('updates' => array('sale_price' => 5), 'filter' => array('status' => 'published')),
            $this->context()
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['matched']);
        $this->assertSame(0, $catalog->saves);
    }

    public function test_the_filter_selects_by_status_alias(): void
    {
        $this->executeTool(array(
            'updates' => array('sale_price' => 5),
            'filter'  => array('status' => 'publish', 'limit' => 10),
        ));

        $this->assertCount(1, $this->catalog->queries);
        $this->assertSame(ProductStatus::Published, $this->catalog->queries[0]->status);
        $this->assertSame(10, $this->catalog->queries[0]->perPage);
    }

    public function test_private_status_is_refused(): void
    {
        $result = $this->executeTool(array(
            'updates' => array('sale_price' => 5),
            'filter'  => array('status' => 'private'),
        ));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('filter.status', $result['error']['data']);
        $this->assertSame(array(), $this->catalog->queries);
    }

    public function test_a_category_filter_is_refused_by_name(): void
    {
        $result = $this->executeTool(array(
            'updates' => array('sale_price' => 5),
            'filter'  => array('category' => 'shoes'),
        ));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('filter.category', $result['error']['data']);
    }

    public function test_an_out_of_range_limit_is_refused(): void
    {
        $result = $this->executeTool(array(
            'updates' => array('sale_price' => 5),
            'filter'  => array('limit' => 101),
        ));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('filter.limit', $result['error']['data']);
    }

    // ── product-level fields ─────────────────────────────────

    public function test_a_status_update_is_refused_by_name(): void
    {
        $result = $this->executeTool(array('updates' => array('status' => 'draft')));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('updates.status', $result['error']['data']);
        $this->assertCount(1, $result['error']['data']);
    }

    public function test_add_tags_is_refused_by_name(): void
    {
        $result = $this->executeTool(array('updates' => array('add_tags' => array('sale'))));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('updates.add_tags', $result['error']['data']);
    }

    // ── dry run and idempotence ──────────────────────────────

    public function test_a_dry_run_writes_nothing(): void
    {
        $result = $this->executeTool(array('updates' => array('sale_price' => 5), 'dry_run' => true));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->catalog->saves);
        $this->assertSame(2, $result['data']['updated']);
        $this->assertTrue($result['data']['dry_run']);
    }

    public function test_a_value_that_is_already_set_is_not_written(): void
    {
        $result = $this->executeTool(array('updates' => array('sale_price' => 19.99), 'scope' => 'product'));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->catalog->saves);
        $this->assertSame(0, $result['data']['updated']);
        $this->assertSame(1, $result['data']['unchanged']);
    }

    public function test_only_changed_variants_are_written(): void
    {
        $result = $this->executeTool(array('updates' => array('regular_price' => 24.99)));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->catalog->saves);
        // Variant 11 already had this comparison price; variant 12 had none.
        $this->assertSame(12, $this->catalog->saved[0]->id);
    }

    // ── fixtures ─────────────────────────────────────────────

    /**
     * Execute against a fresh catalog holding the default product.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     *
     * @return array<string, mixed>
     */
    private function executeTool(array $arguments): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->tool($this->defaultCatalog())->execute($arguments, $this->context());

        return $result;
    }

    /** A fresh catalog holding the default product, recorded for assertions. */
    private function defaultCatalog(): MultiProductCatalogFake
    {
        $this->catalog = new MultiProductCatalogFake($this->product());

        return $this->catalog;
    }

    private function tool(MultiProductCatalogFake $catalog): BulkUpdateProductsTool
    {
        return new BulkUpdateProductsTool($this->errors, $catalog);
    }

    private function product(): Product
    {
        return new Product(
            id: 1,
            name: 'Test product',
            variants: array(
                $this->variant(11, 0, 10, 19.99, 24.99),
                $this->variant(12, 1, 3, 30.0, null),
            ),
        );
    }

    private function zeroStockProduct(): Product
    {
        return new Product(
            id: 1,
            name: 'Zero stock',
            variants: array($this->variant(11, 0, 0, 9.99, null)),
        );
    }

    private function untrackedProduct(): Product
    {
        return new Product(
            id: 1,
            name: 'Untracked',
            variants: array($this->variant(11, 0, null, 9.99, null, false)),
        );
    }

    /** One variant. */
    private function variant(
        int $id,
        int $position,
        ?int $stock,
        float $price,
        ?float $compareAt,
        bool $track = true,
    ): ProductVariant {
        return new ProductVariant(
            id: $id,
            sku: 'SKU-' . $id,
            price: Money::fromDecimal($price, 'USD'),
            options: new OptionSelection(array()),
            compareAtPrice: null === $compareAt ? null : Money::fromDecimal($compareAt, 'USD'),
            stock: $stock,
            trackInventory: $track,
            position: $position,
        );
    }

    private function context(): array
    {
        return array('user_id' => 7);
    }
}
