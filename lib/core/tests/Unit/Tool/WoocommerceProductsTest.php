<?php

/**
 * The products tool: six actions, three taxonomy refusals, and a narrow update.
 *
 * Proposal 164 section 164.177. Pinned here:
 *
 * - the schema names the six actions performed, and the three taxonomy actions
 *   are refused with their reason rather than dropped;
 * - arguments with no field in this model (`categories`, `short_description`,
 *   and the rest) are refused by name, so a migration cannot lose half a
 *   catalogue and be told it succeeded;
 * - `create` decides the charge from both price fields and needs a currency;
 * - `update` is product-level only and refuses a price by name, pointing at the
 *   tool that takes a scope;
 * - `publish` maps to `published` while `pending` and `private` map to `draft`.
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
use Nvoos\Core\Tests\Support\ProductCatalogFake;
use Nvoos\Core\Tool\Ecommerce\WoocommerceProductsTool;
use PHPUnit\Framework\TestCase;

final class WoocommerceProductsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

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

    // ── metadata and refusals ────────────────────────────────

    public function test_the_tool_metadata(): void
    {
        $tool = $this->tool(new ProductCatalogFake($this->product()));
        $schema = $tool->getParametersSchema();

        $this->assertSame('woo_products', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertSame(array('get', 'list', 'search', 'create', 'update', 'delete'), $schema['properties']['action']['enum']);
        $this->assertArrayNotHasKey('categories', $schema['properties']);
        $this->assertArrayNotHasKey('attributes', $schema['properties']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool(new ProductCatalogFake($this->product()))->execute(array('action' => 'list'), array());
    }

    public function test_manage_categories_is_refused_by_name(): void
    {
        $result = $this->execute(array('action' => 'manage_categories'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('category terms', $result['error']['data']['action'][0]);
    }

    public function test_manage_attributes_is_refused_by_name(): void
    {
        $result = $this->execute(array('action' => 'manage_attributes'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('attribute', $result['error']['data']['action'][0]);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $result = $this->execute(array('action' => 'archive'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('action', $result['error']['data']);
    }

    public function test_a_categories_argument_is_refused_by_name(): void
    {
        $result = $this->execute(array('action' => 'list', 'categories' => array('shoes')));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('category tree', $result['error']['data']['categories'][0]);
    }

    // ── read ─────────────────────────────────────────────────

    public function test_get_returns_the_product(): void
    {
        $result = $this->execute(array('action' => 'get', 'product_id' => 1));

        $this->assertTrue($result['success']);
        $this->assertSame('Test product', $result['data']['name']);
        $this->assertCount(1, $result['data']['variants']);
    }

    public function test_get_of_a_missing_product_is_not_found(): void
    {
        $result = $this->execute(array('action' => 'get', 'product_id' => 9), new ProductCatalogFake(null));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_list_omits_variants_and_keeps_the_count(): void
    {
        $result = $this->execute(array('action' => 'list'));

        $product = $result['data']['products'][0];

        $this->assertArrayNotHasKey('variants', $product);
        $this->assertSame(1, $product['variant_count']);
        $this->assertSame(1, $result['data']['total_count']);
    }

    public function test_list_maps_publish_to_published(): void
    {
        $catalog = new MultiProductCatalogFake($this->product());

        $this->execute(array('action' => 'list', 'status' => 'publish'), $catalog);

        $this->assertSame(ProductStatus::Published, $catalog->queries[0]->status);
    }

    public function test_a_private_status_maps_to_draft(): void
    {
        $catalog = new MultiProductCatalogFake($this->product());

        $this->execute(array('action' => 'list', 'status' => 'private'), $catalog);

        $this->assertSame(ProductStatus::Draft, $catalog->queries[0]->status);
    }

    public function test_search_needs_a_term(): void
    {
        $result = $this->execute(array('action' => 'search'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('search', $result['error']['data']);
    }

    public function test_search_passes_the_term_to_the_query(): void
    {
        $catalog = new MultiProductCatalogFake($this->product());

        $this->execute(array('action' => 'search', 'search' => 'tee'), $catalog);

        $this->assertSame('tee', $catalog->queries[0]->search);
    }

    public function test_a_type_filter_is_refused(): void
    {
        $result = $this->execute(array('action' => 'list', 'type' => 'simple'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('type', $result['error']['data']);
    }

    public function test_a_page_above_the_maximum_is_refused(): void
    {
        $result = $this->execute(array('action' => 'list', 'per_page' => 101));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('per_page', $result['error']['data']);
    }

    // ── create ───────────────────────────────────────────────

    public function test_create_requires_a_name(): void
    {
        $result = $this->execute(array('action' => 'create', 'price' => 10, 'currency' => 'USD'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('name', $result['error']['data']);
    }

    public function test_create_requires_a_currency_with_a_price(): void
    {
        $result = $this->execute(array('action' => 'create', 'name' => 'Tee', 'price' => 10));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('currency', $result['error']['data']);
    }

    public function test_create_with_price_only_has_no_comparison(): void
    {
        $catalog = new ProductCatalogFake(null);

        $result = $this->execute(
            array('action' => 'create', 'name' => 'Tee', 'price' => 10, 'currency' => 'USD'),
            $catalog
        );

        $this->assertTrue($result['success']);
        $variant = $catalog->product?->variants[0];

        $this->assertNotNull($variant);
        $this->assertSame(1000, $variant->price->minorUnits);
        $this->assertNull($variant->compareAtPrice);
    }

    public function test_create_with_both_prices_charges_the_sale(): void
    {
        $catalog = new ProductCatalogFake(null);

        $this->execute(
            array(
                'action'     => 'create',
                'name'       => 'Tee',
                'price'      => 20,
                'sale_price' => 15,
                'currency'   => 'USD',
            ),
            $catalog
        );

        $variant = $catalog->product?->variants[0];

        $this->assertNotNull($variant);
        $this->assertSame(1500, $variant->price->minorUnits);
        $this->assertSame(2000, $variant->compareAtPrice?->minorUnits);
    }

    public function test_create_carries_tags_status_and_stock(): void
    {
        $catalog = new ProductCatalogFake(null);

        $this->execute(
            array(
                'action'         => 'create',
                'name'           => 'Tee',
                'price'          => 10,
                'currency'       => 'USD',
                'status'         => 'publish',
                'tags'           => array('sale', 'sale', ' new '),
                'stock_quantity' => 7,
                'sku'            => 'TEE-1',
            ),
            $catalog
        );

        $product = $catalog->product;

        $this->assertNotNull($product);
        $this->assertSame(ProductStatus::Published, $product->status);
        $this->assertSame(array('sale', 'new'), $product->tags);
        $this->assertSame(7, $product->variants[0]->stock);
        $this->assertSame('TEE-1', $product->variants[0]->sku);
    }

    // ── update and delete ────────────────────────────────────

    public function test_update_refuses_a_price_by_name(): void
    {
        $result = $this->execute(array('action' => 'update', 'product_id' => 1, 'price' => 5));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('update_woo_product_price', $result['error']['data']['price'][0]);
    }

    public function test_update_needs_a_field(): void
    {
        $result = $this->execute(array('action' => 'update', 'product_id' => 1));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('action', $result['error']['data']);
    }

    public function test_update_changes_product_fields(): void
    {
        $catalog = new ProductCatalogFake($this->product());

        $result = $this->execute(
            array(
                'action'     => 'update',
                'product_id' => 1,
                'name'       => 'Renamed',
                'status'     => 'draft',
                'tags'       => array('clearance'),
            ),
            $catalog
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Renamed', $catalog->product?->name);
        $this->assertSame(ProductStatus::Draft, $catalog->product?->status);
        $this->assertSame(array('clearance'), $catalog->product?->tags);
        // The variant list is untouched by a product-field update.
        $this->assertCount(1, $catalog->product?->variants ?? array());
    }

    public function test_delete_removes_the_product(): void
    {
        $catalog = new ProductCatalogFake($this->product());

        $result = $this->execute(array('action' => 'delete', 'product_id' => 1), $catalog);

        $this->assertTrue($result['success']);
        $this->assertSame(array(1), $catalog->deleted);
    }

    public function test_delete_of_a_missing_product_is_not_found(): void
    {
        $result = $this->execute(array('action' => 'delete', 'product_id' => 9), new ProductCatalogFake(null));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ── fixtures ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments Raw tool arguments.
     * @param ProductCatalogFake|MultiProductCatalogFake|null $catalog Catalog double.
     *
     * @return array<string, mixed>
     */
    private function execute(array $arguments, mixed $catalog = null): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->tool($catalog ?? new ProductCatalogFake($this->product()))
            ->execute($arguments, array('user_id' => 7));

        return $result;
    }

    private function tool(mixed $catalog): WoocommerceProductsTool
    {
        return new WoocommerceProductsTool($this->errors, $catalog);
    }

    private function product(): Product
    {
        return new Product(
            id: 1,
            name: 'Test product',
            variants: array(
                new ProductVariant(
                    id: 11,
                    sku: 'SKU-11',
                    price: Money::fromDecimal('19.99', 'USD'),
                    options: new OptionSelection(array()),
                    compareAtPrice: Money::fromDecimal('24.99', 'USD'),
                    stock: 5,
                    trackInventory: true,
                    position: 0,
                ),
            ),
            description: 'A product.',
            status: ProductStatus::Published,
        );
    }
}
