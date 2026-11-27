<?php

/**
 * Tests for the catalog model: options, variants, the product aggregate, and its
 * query/result value objects.
 *
 * The behaviour pinned here is the behaviour the three source models disagree
 * about, so that an adapter cannot quietly choose a different answer:
 * option order is not identity, a duplicate variant identity is an error, an
 * untracked variant is available even with a null stock, and a coherent product
 * reports no option errors.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductCollection;
use Nvoos\Core\Domain\Entity\ProductQuery;
use Nvoos\Core\Domain\Entity\ProductVariant;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OptionSelection;
use Nvoos\Core\Domain\ValueObject\ProductOption;
use Nvoos\Core\Domain\ValueObject\ProductStatus;
use PHPUnit\Framework\TestCase;

final class ProductModelTest extends TestCase
{
    public function test_a_selection_is_sorted_by_option_name(): void
    {
        $selection = new OptionSelection(
            array(
                'Size'   => 'M',
                'Colour' => 'Red',
            )
        );

        $this->assertSame(array('Colour', 'Size'), array_keys($selection->values));
    }

    public function test_a_selections_key_is_order_and_case_independent(): void
    {
        $one = new OptionSelection(
            array(
                'Size'   => 'M',
                'Colour' => 'Red',
            )
        );
        $two = new OptionSelection(
            array(
                'colour' => 'red',
                'size'   => 'm',
            )
        );

        $this->assertSame($one->key(), $two->key());
        $this->assertSame('colour=red|size=m', $one->key());
    }

    public function test_a_selection_finds_a_value_case_insensitively(): void
    {
        $selection = new OptionSelection(array('Size' => 'M'));

        $this->assertSame('M', $selection->value('size'));
        $this->assertNull($selection->value('Colour'));
    }

    public function test_an_empty_selection_means_a_variant_without_options(): void
    {
        $selection = new OptionSelection(array());

        $this->assertSame(array(), $selection->values);
        $this->assertSame('', $selection->key());
        $this->assertNull($selection->value('Size'));
    }

    public function test_a_blank_selection_value_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OptionSelection(array('Size' => '  '));
    }

    public function test_an_option_offers_a_value_case_insensitively(): void
    {
        $option = new ProductOption('Size', array('S', 'M', 'L'));

        $this->assertTrue($option->offers('m'));
        $this->assertFalse($option->offers('XL'));
    }

    public function test_an_option_refuses_a_repeated_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductOption('Size', array('M', 'm'));
    }

    public function test_an_option_refuses_an_empty_value_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductOption('Size', array());
    }

    public function test_variant_identity_prefers_the_sku(): void
    {
        $variant = $this->variant('TSHIRT-RED-M', '19.99', array('Colour' => 'Red', 'Size' => 'M'));

        $this->assertSame('sku:tshirt-red-m', $variant->identity());
    }

    public function test_variant_identity_falls_back_to_the_options(): void
    {
        $variant = $this->variant(null, '19.99', array('Colour' => 'Red', 'Size' => 'M'));

        $this->assertSame('options:colour=red|size=m', $variant->identity());
    }

    public function test_an_untracked_variant_is_in_stock_with_a_null_quantity(): void
    {
        $variant = $this->variant('SKU-1', '10.00', array('Size' => 'M'), 0, null, false);

        $this->assertTrue($variant->isInStock());
    }

    public function test_a_tracked_variant_with_no_quantity_is_not_in_stock(): void
    {
        $variant = $this->variant('SKU-1', '10.00', array('Size' => 'M'), 0, null, true);

        $this->assertFalse($variant->isInStock());
    }

    public function test_a_tracked_variant_with_stock_is_sellable(): void
    {
        $variant = $this->variant('SKU-1', '10.00', array('Size' => 'M'), 0, 4, true);

        $this->assertTrue($variant->isInStock());
    }

    public function test_a_product_refuses_two_variants_with_one_identity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Product(
            id: null,
            name: 'T-Shirt',
            variants: array(
                $this->variant('SAME-SKU', '10.00', array('Size' => 'M')),
                $this->variant('same-sku', '12.00', array('Size' => 'L'), 1),
            ),
        );
    }

    public function test_a_product_refuses_a_blank_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Product(id: null, name: '   ');
    }

    public function test_the_price_range_spans_the_variants(): void
    {
        $product = $this->tShirt();

        $range = $product->priceRange();

        $this->assertNotNull($range);
        $this->assertSame('19.99', $range['min']->toDecimal());
        $this->assertSame('24.99', $range['max']->toDecimal());
    }

    public function test_a_product_with_no_variants_has_no_price_range(): void
    {
        $this->assertNull((new Product(id: null, name: 'Draft'))->priceRange());
    }

    public function test_the_primary_variant_is_the_first_in_display_order(): void
    {
        $product = $this->tShirt();

        $this->assertSame('TSHIRT-RED-M', $product->primaryVariant()?->sku);
    }

    public function test_a_variant_is_found_by_sku_case_insensitively(): void
    {
        $product = $this->tShirt();

        $this->assertSame('TSHIRT-RED-M', $product->variantBySku('tshirt-red-m')?->sku);
        $this->assertNull($product->variantBySku('NOPE'));
    }

    public function test_total_stock_ignores_untracked_variants(): void
    {
        $product = new Product(
            id: 1,
            name: 'T-Shirt',
            variants: array(
                $this->variant('A', '10.00', array('Size' => 'S'), 0, 3, true),
                $this->variant('B', '10.00', array('Size' => 'M'), 1, 4, true),
                $this->variant('C', '10.00', array('Size' => 'L'), 2, null, false),
            ),
        );

        $this->assertSame(7, $product->totalStock());
    }

    public function test_a_coherent_product_reports_no_option_errors(): void
    {
        $this->assertSame(array(), $this->tShirt()->optionErrors());
    }

    public function test_a_variant_naming_an_undefined_option_is_reported(): void
    {
        $product = new Product(
            id: 1,
            name: 'T-Shirt',
            options: array(new ProductOption('Size', array('M'))),
            variants: array($this->variant('A', '10.00', array('Colour' => 'Red'))),
        );

        $errors = $product->optionErrors();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('does not define', $errors[0]);
    }

    public function test_a_variant_naming_an_unoffered_value_is_reported(): void
    {
        $product = new Product(
            id: 1,
            name: 'T-Shirt',
            options: array(new ProductOption('Size', array('M'))),
            variants: array($this->variant('A', '10.00', array('Size' => 'XL'))),
        );

        $errors = $product->optionErrors();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('does not offer', $errors[0]);
    }

    public function test_the_product_serialises_derived_reads(): void
    {
        $data = $this->tShirt()->jsonSerialize();

        $this->assertSame('published', $data['status']);
        $this->assertSame(7, $data['total_stock']);
        $this->assertSame('19.99', $data['price_range']['min']->toDecimal());
        $this->assertCount(2, $data['variants']);
    }

    public function test_a_query_converts_a_page_to_an_offset(): void
    {
        $query = new ProductQuery(page: 3, perPage: 25);

        $this->assertSame(50, $query->getOffset());
        $this->assertSame(4, $query->nextPage()->page);
    }

    public function test_a_query_refuses_an_out_of_range_page_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductQuery(perPage: 101);
    }

    public function test_a_query_refuses_an_unknown_sort_direction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductQuery(order: 'sideways');
    }

    public function test_a_collection_reports_further_pages(): void
    {
        $last  = new ProductCollection(array(), 40, 2, 20, 2);
        $first = new ProductCollection(array(), 40, 1, 20, 2);

        $this->assertFalse($last->hasMorePages());
        $this->assertTrue($first->hasMorePages());
        $this->assertFalse($first->hasItems());
    }

    /**
     * A two-variant T-shirt with coherent options, 3 + 4 tracked in stock.
     */
    private function tShirt(): Product
    {
        return new Product(
            id: 7,
            name: 'T-Shirt',
            variants: array(
                $this->variant('TSHIRT-RED-M', '19.99', array('Colour' => 'Red', 'Size' => 'M'), 0, 3, true),
                $this->variant('TSHIRT-RED-L', '24.99', array('Colour' => 'Red', 'Size' => 'L'), 1, 4, true),
            ),
            options: array(
                new ProductOption('Colour', array('Red', 'Blue')),
                new ProductOption('Size', array('M', 'L')),
            ),
            status: ProductStatus::Published,
        );
    }

    /**
     * @param array<string, string> $options
     */
    private function variant(
        ?string $sku,
        string $price,
        array $options,
        int $position = 0,
        ?int $stock = null,
        bool $track = true,
    ): ProductVariant {
        return new ProductVariant(
            id: null,
            sku: $sku,
            price: Money::fromDecimal($price, 'USD'),
            options: new OptionSelection($options),
            stock: $stock,
            trackInventory: $track,
            position: $position,
            productId: 7,
        );
    }
}
