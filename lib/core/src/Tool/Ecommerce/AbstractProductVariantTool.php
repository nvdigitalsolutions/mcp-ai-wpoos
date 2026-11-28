<?php

/**
 * Shared behaviour for the variant-shaped product tools.
 *
 * Ports of `update_woo_product_price` and `update_woo_product_qty` (Proposal
 * 164 section 164.172) over {@see ProductCatalogInterface}.
 *
 * ## Why there is a mapping to explain
 *
 * WooCommerce puts a price on the *product* and on each variation. This runtime
 * puts it on the variant, so a scoped update has to say what the two scopes
 * mean. The rule, stated once here so both tools agree:
 *
 *  - `product`    — the first variant by position, which is the only variant of
 *                   a single-variant product;
 *  - `variations` — every variant after the first;
 *  - `all`        — every variant.
 *
 * That is a decision, not a discovery. A WooCommerce variable product has a
 * parent price range that this model has no field for, so `product` cannot mean
 * "the parent" and does not pretend to.
 *
 * The currency is never taken from the caller: each variant already carries one,
 * and an update changes the amount only. A tool that let a caller restate the
 * currency could silently redenominate a price.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ProductCatalogInterface;
use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductVariant;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Tool\AbstractTool;

abstract class AbstractProductVariantTool extends AbstractTool
{
    /** The scopes both tools offer. */
    public const SCOPES = array('product', 'variations', 'all');

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly ProductCatalogInterface $catalog,
    ) {
        parent::__construct($e);
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * The variants a scope selects, ordered by position.
     *
     * @return array<int, ProductVariant>
     */
    protected function targets(Product $product, string $scope): array
    {
        $variants = array_values($product->variants);

        usort(
            $variants,
            static fn (ProductVariant $a, ProductVariant $b): int => $a->position <=> $b->position
        );

        if ('all' === $scope) {
            return $variants;
        }

        if ('variations' === $scope) {
            return array_slice($variants, 1);
        }

        return array_slice($variants, 0, 1);
    }

    /**
     * The scope, defaulting to product.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    protected function scopeParam(array $arguments): string
    {
        $scope = $this->stringParam($arguments, 'scope');

        return in_array($scope, self::SCOPES, true) ? $scope : 'product';
    }

    /**
     * A non-negative amount from a raw value, or null.
     *
     * `floatval('abc')` is `0.0`; a price that quietly became free is worse than
     * a refusal.
     */
    protected function amount(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return is_finite($amount) && $amount >= 0 ? $amount : null;
    }

    /**
     * A copy of `$variant` with some fields changed.
     *
     * ProductVariant is a readonly entity, so an update is a new value rather
     * than a mutation. Named-argument unpacking keeps this from repeating the
     * thirteen-field constructor at every call site, and means a field added to
     * the entity later is carried over automatically.
     *
     * @param array<string, mixed> $changes Field name => new value.
     */
    protected function withChanges(ProductVariant $variant, array $changes): ProductVariant
    {
        $fields = array(
            'id' => $variant->id,
            'sku' => $variant->sku,
            'price' => $variant->price,
            'options' => $variant->options,
            'compareAtPrice' => $variant->compareAtPrice,
            'cost' => $variant->cost,
            'barcode' => $variant->barcode,
            'stock' => $variant->stock,
            'trackInventory' => $variant->trackInventory,
            'position' => $variant->position,
            'imageUrl' => $variant->imageUrl,
            'externalRef' => $variant->externalRef,
            'productId' => $variant->productId,
        );

        return new ProductVariant(...array_merge($fields, $changes));
    }

    /**
     * The amount in `$currency`, keeping the variant's own denomination.
     */
    protected function money(float $amount, string $currency): Money
    {
        return Money::fromDecimal($amount, $currency);
    }

    /**
     * A price as a plain decimal for reporting.
     *
     * Minor units are the stored truth. The cast matters: PHP's `/` returns an
     * int when the division is exact, so `2400 / 100` is `24`, not `24.0`.
     */
    protected function decimal(Money $money): float
    {
        return (float) ( $money->minorUnits / 100 );
    }

    /**
     * Refuse a product that has no variant in the requested scope.
     *
     * @param array<int, ProductVariant> $targets Resolved targets.
     */
    protected function requireTargets(array $targets, string $scope): mixed
    {
        if (array() !== $targets) {
            return null;
        }

        return $this->errors->validationFailed(
            'The product has no variant in that scope.',
            array('scope' => array(sprintf('No variant matches the "%s" scope.', $scope)))
        );
    }
}
