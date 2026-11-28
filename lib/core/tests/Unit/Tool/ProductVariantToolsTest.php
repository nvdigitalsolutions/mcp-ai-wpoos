<?php

/**
 * The ecommerce product slice: price and stock over the catalog contract.
 *
 * Proposal 164 section 164.172. What is pinned here is the mapping decision and
 * the arithmetic, not the plumbing: `sale_price` is what is charged and
 * `regular_price` is the comparison price; scopes select the first variant, the
 * later ones, or all of them; a decrease stops at zero and says so; and turning
 * stock management off clears the quantity rather than setting it to zero.
 *
 * Also pinned: an unusable price is refused rather than becoming `0.0` the way
 * `floatval( 'abc' )` would, and a variant that did not change is not written.
 *
 * `ProductVariant` is a readonly entity, so a write produces a new value rather
 * than mutating one. The double therefore records what was saved; the assertions
 * read that, because reading the original entity would assert that nothing
 * happened.
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
use Nvoos\Core\Domain\Contract\ProductCatalogInterface;
use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductVariant;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OptionSelection;
use Nvoos\Core\Tests\Support\ProductCatalogFake;
use Nvoos\Core\Tool\Ecommerce\UpdateProductPriceTool;
use Nvoos\Core\Tool\Ecommerce\UpdateProductStockTool;
use PHPUnit\Framework\TestCase;

final class ProductVariantToolsTest extends TestCase
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
    }

    // ── price ────────────────────────────────────────────────

    public function test_the_price_tool_metadata(): void
    {
        $tool = $this->priceTool();

        $this->assertSame('update_woo_product_price', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertSame(array('product_id'), $tool->getParametersSchema()['required']);
        $this->assertArrayNotHasKey('sale_date_from', $tool->getParametersSchema()['properties']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->priceTool()->execute(array('product_id' => 1, 'sale_price' => 5), array());
    }

    public function test_sale_price_is_what_is_charged(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $result = $this->priceTool($catalog)->execute(
            array('product_id' => 1, 'sale_price' => 12.5),
            $this->context()
        );

        $this->assertTrue($result['success']);
        $this->assertSame(12.5, $result['data']['updated'][0]['after']['price']);
        $this->assertSame(12.5, $catalog->saved[0]->price->minorUnits / 100);
        // The comparison price is untouched by a sale.
        $this->assertSame(24.99, $catalog->saved[0]->compareAtPrice?->minorUnits / 100);
        $this->assertSame(1, $catalog->saves);
    }

    public function test_regular_price_is_the_comparison_price(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $this->priceTool($catalog)->execute(
            array('product_id' => 1, 'regular_price' => 24.0),
            $this->context()
        );

        		$this->assertSame(19.99, $catalog->saved[0]->price->minorUnits / 100, 'the charged price must not move');
        		$this->assertSame(2400, $catalog->saved[0]->compareAtPrice?->minorUnits);
    }

    public function test_clear_sale_removes_the_comparison_price(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $this->priceTool($catalog)->execute(
            array('product_id' => 1, 'clear_sale' => true),
            $this->context()
        );

        $this->assertNull($catalog->saved[0]->compareAtPrice);
    }

    public function test_the_product_scope_takes_the_first_variant_only(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $this->priceTool($catalog)->execute(
            array('product_id' => 1, 'sale_price' => 1.0, 'scope' => 'product'),
            $this->context()
        );

        		$this->assertCount(1, $catalog->saved);
        		$this->assertSame(11, $catalog->saved[0]->id, 'the first variant is the one by position');
        		$this->assertSame(100, $catalog->saved[0]->price->minorUnits);
    }

    public function test_the_variations_scope_skips_the_first(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $this->priceTool($catalog)->execute(
            array('product_id' => 1, 'sale_price' => 1.0, 'scope' => 'variations'),
            $this->context()
        );

        $this->assertCount(1, $catalog->saved);
        $this->assertSame(12, $catalog->saved[0]->id);
    }

    public function test_the_all_scope_takes_every_variant(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $result = $this->priceTool($catalog)->execute(
            array('product_id' => 1, 'sale_price' => 1.0, 'scope' => 'all'),
            $this->context()
        );

        $this->assertCount(2, $result['data']['updated']);
        $this->assertSame(2, $catalog->saves);
    }

    public function test_a_price_field_is_required(): void
    {
        $result = $this->priceTool()->execute(array('product_id' => 1), $this->context());

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_unusable_price_is_refused_rather_than_becoming_free(): void
    {
        // floatval('abc') is 0.0, which would make the variant free.
        $result = $this->priceTool()->execute(
            array('product_id' => 1, 'sale_price' => 'abc'),
            $this->context()
        );

        $this->assertArrayHasKey('sale_price', $result['error']['data']);
    }

    public function test_a_missing_product_is_reported(): void
    {
        $result = $this->priceTool(new ProductCatalogFake(null))->execute(
            array('product_id' => 99, 'sale_price' => 5),
            $this->context()
        );

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_the_currency_is_preserved(): void
    {
        $catalog = new ProductCatalogFake($this->product('EUR'));
        $this->priceTool($catalog)->execute(
            array('product_id' => 1, 'sale_price' => 7.0),
            $this->context()
        );

        $this->assertSame('EUR', $catalog->saved[0]->price->currency);
    }

    // ── stock ────────────────────────────────────────────────

    public function test_the_stock_tool_metadata(): void
    {
        $tool = $this->stockTool();

        $this->assertSame('update_woo_product_qty', $tool->getSlug());
        $this->assertSame(array('product_id', 'quantity'), $tool->getParametersSchema()['required']);
        $this->assertArrayNotHasKey('notify', $tool->getParametersSchema()['properties']);
    }

    public function test_set_writes_the_quantity(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $this->stockTool($catalog)->execute(
            array('product_id' => 1, 'quantity' => 42),
            $this->context()
        );

        $this->assertSame(42, $catalog->saved[0]->stock);
    }

    public function test_increase_adds_to_the_current_level(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $this->stockTool($catalog)->execute(
            array('product_id' => 1, 'quantity' => 5, 'operation' => 'increase'),
            $this->context()
        );

        // The fixture starts at 10.
        $this->assertSame(15, $catalog->saved[0]->stock);
    }

    public function test_decrease_stops_at_zero_and_says_so(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $result = $this->stockTool($catalog)->execute(
            array('product_id' => 1, 'quantity' => 50, 'operation' => 'decrease'),
            $this->context()
        );

        $this->assertSame(0, $catalog->saved[0]->stock);
        $this->assertTrue($result['data']['clamped']);
        $this->assertTrue($result['data']['updated'][0]['clamped']);
    }

    public function test_turning_stock_management_off_clears_the_quantity(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $this->stockTool($catalog)->execute(
            array('product_id' => 1, 'quantity' => 0, 'manage_stock' => false),
            $this->context()
        );

        $this->assertFalse($catalog->saved[0]->trackInventory);
        $this->assertNull($catalog->saved[0]->stock, 'untracked is not zero');
    }

    public function test_a_negative_quantity_is_refused(): void
    {
        $result = $this->stockTool()->execute(
            array('product_id' => 1, 'quantity' => -5),
            $this->context()
        );

        $this->assertArrayHasKey('quantity', $result['error']['data']);
    }

    public function test_an_unchanged_quantity_is_not_written(): void
    {
        $catalog = new ProductCatalogFake($this->product());
        $this->stockTool($catalog)->execute(
            array('product_id' => 1, 'quantity' => 10),
            $this->context()
        );

        $this->assertSame(0, $catalog->saves, 'the fixture is already at 10');
    }

    /** A signed-in caller. */
    private function context(): array
    {
        return array('user_id' => 7);
    }

    /** A two-variant product: 10 in stock, 19.99 charged, 24.99 compared. */
    private function product(string $currency = 'USD'): Product
    {
        return new Product(
            id: 1,
            name: 'Test product',
            variants: array(
                $this->variant(11, 0, 10, 19.99, 24.99, $currency),
                $this->variant(12, 1, 3, 30.0, null, $currency),
            ),
        );
    }

    /** One variant. */
    private function variant(
        int $id,
        int $position,
        ?int $stock,
        float $price,
        ?float $compareAt,
        string $currency,
    ): ProductVariant {
        return new ProductVariant(
            id: $id,
            sku: 'SKU-' . $id,
            price: Money::fromDecimal($price, $currency),
            options: new OptionSelection(array()),
            compareAtPrice: null === $compareAt ? null : Money::fromDecimal($compareAt, $currency),
            stock: $stock,
            trackInventory: true,
            position: $position,
        );
    }

    private function priceTool(?ProductCatalogInterface $catalog = null): UpdateProductPriceTool
    {
        return new UpdateProductPriceTool($this->errors, $catalog ?? new ProductCatalogFake($this->product()));
    }

    private function stockTool(?ProductCatalogInterface $catalog = null): UpdateProductStockTool
    {
        return new UpdateProductStockTool($this->errors, $catalog ?? new ProductCatalogFake($this->product()));
    }
}
