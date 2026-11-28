<?php

/**
 * Change the price of a product's variants.
 *
 * Port of `WP_MCP_AI_Tool_Update_Woo_Product_Price` (Proposal 164 section
 * 164.172).
 *
 * ## The price mapping, stated plainly
 *
 * WooCommerce has `regular_price` (the normal price) and `sale_price` (the
 * discounted one). This runtime's variant has `price` (what is charged) and
 * `compareAtPrice` (the struck-through "was" price). The port maps them by
 * meaning, not by name:
 *
 *     sale_price    -> price           what the customer pays
 *     regular_price -> compareAtPrice  the reference price it is compared to
 *     clear_sale    -> compareAtPrice = null
 *
 * The consequence a WooCommerce caller must know: sending only `regular_price`
 * changes the *comparison* price, not the charged one. Sending only
 * `sale_price` is the "put it on sale" case and changes what is charged. Both
 * are stated in the description, because the alternative — mapping by name —
 * would quietly invert what a sale means.
 *
 * ## What is not accepted
 *
 * `sale_date_from` / `sale_date_to` are absent from the schema. WooCommerce
 * schedules a sale window; this model has no scheduled-price concept, and a
 * parameter accepted and ignored is the defect this whole programme exists to
 * remove. A caller who needs a window must schedule the two updates.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Entity\ProductVariant;

class UpdateProductPriceTool extends AbstractProductVariantTool
{
    public function getSlug(): string
    {
        return 'update_woo_product_price';
    }

    public function getName(): string
    {
        return 'Update Product Price';
    }

    public function getDescription(): string
    {
        return 'Sets the price of a product variant, or of several. sale_price is the amount charged and regular_price is the comparison ("was") price; clear_sale removes the comparison price. Sale windows are not supported.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_id'    => array(
                    'type'        => 'integer',
                    'description' => 'The product to update.',
                    'minimum'     => 1,
                ),
                'scope'         => array(
                    'type'        => 'string',
                    'description' => 'product sets the first variant, variations every later one, all every variant. Default product.',
                    'enum'        => self::SCOPES,
                ),
                'sale_price'    => array(
                    'type'        => 'number',
                    'description' => 'The amount charged.',
                    'minimum'     => 0,
                ),
                'regular_price' => array(
                    'type'        => 'number',
                    'description' => 'The comparison price the charged price is shown against.',
                    'minimum'     => 0,
                ),
                'clear_sale'    => array(
                    'type'        => 'boolean',
                    'description' => 'Remove the comparison price.',
                ),
            ),
            'required'             => array('product_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $productId = $this->intParam($arguments, 'product_id', 0);
        $scope = $this->scopeParam($arguments);
        $clearSale = $this->boolParam($arguments, 'clear_sale', false);

        $hasSale = array_key_exists('sale_price', $arguments) && null !== $arguments['sale_price'];
        $hasRegular = array_key_exists('regular_price', $arguments) && null !== $arguments['regular_price'];

        $salePrice = $hasSale ? $this->amount($arguments['sale_price']) : null;
        $regularPrice = $hasRegular ? $this->amount($arguments['regular_price']) : null;

        $fields = array();

        if ($productId < 1) {
            $fields['product_id'] = array('A product id is required.');
        }

        if ($hasSale && null === $salePrice) {
            $fields['sale_price'] = array('A non-negative number.');
        }

        if ($hasRegular && null === $regularPrice) {
            $fields['regular_price'] = array('A non-negative number.');
        }

        if (! $hasSale && ! $hasRegular && ! $clearSale) {
            $fields['sale_price'] = array('At least one of sale_price, regular_price or clear_sale is required.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The price cannot be updated.', $fields);
        }

        $product = $this->catalog->find($productId);

        if (null === $product) {
            return $this->errors->notFound(
                sprintf('Product %d not found.', $productId),
                array('product_id' => $productId)
            );
        }

        $targets = $this->targets($product, $scope);
        $refusal = $this->requireTargets($targets, $scope);

        if (null !== $refusal) {
            return $refusal;
        }

        $updates = array();

        foreach ($targets as $variant) {
            $updates[] = $this->apply($productId, $variant, $salePrice, $regularPrice, $clearSale);
        }

        return $this->success(
            sprintf('Updated the price of %d variant(s) on product %d.', count($updates), $productId),
            array(
                'product_id' => $productId,
                'scope' => $scope,
                'updated' => array_values(array_filter($updates, static fn (array $u): bool => $u['changed'])),
                'unchanged' => count(array_filter($updates, static fn (array $u): bool => ! $u['changed'])),
                'text' => sprintf(
                    '%d variant(s) updated: %s',
                    count(array_filter($updates, static fn (array $u): bool => $u['changed'])),
                    $this->summary($updates)
                ),
            )
        );
    }

    /**
     * Apply the requested changes to one variant and persist it.
     *
     * @return array<string, mixed> Before and after, and whether anything moved.
     */
    private function apply(
        int $productId,
        ProductVariant $variant,
        ?float $salePrice,
        ?float $regularPrice,
        bool $clearSale,
    ): array {
        $currency = $variant->price->currency;

        $before = array(
            'price' => $this->decimal($variant->price),
            'compare_at_price' => null === $variant->compareAtPrice ? null : $this->decimal($variant->compareAtPrice),
        );

        $newPrice = null === $salePrice ? $variant->price : $this->money($salePrice, $currency);
        $newCompare = $variant->compareAtPrice;

        if (null !== $regularPrice) {
            $newCompare = $this->money($regularPrice, $currency);
        }

        if ($clearSale) {
            $newCompare = null;
        }

        $after = array(
            'price' => $this->decimal($newPrice),
            'compare_at_price' => null === $newCompare ? null : $this->decimal($newCompare),
        );

        $changed = $before !== $after;

        if ($changed) {
            $this->catalog->saveVariant(
                $productId,
                $this->withChanges(
                    $variant,
                    array(
                        'price' => $newPrice,
                        'compareAtPrice' => $newCompare,
                    )
                )
            );
        }

        return array(
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'currency' => $currency,
            'before' => $before,
            'after' => $after,
            'changed' => $changed,
        );
    }

    /**
     * One line naming what moved.
     *
     * @param array<int, array<string, mixed>> $updates Per-variant results.
     */
    private function summary(array $updates): string
    {
        $parts = array();

        foreach ($updates as $update) {
            if (! $update['changed']) {
                continue;
            }

            $parts[] = sprintf(
                'variant %s: %s -> %s',
                null === $update['variant_id'] ? '(unsaved)' : (string) $update['variant_id'],
                $this->priceLabel($update['before']),
                $this->priceLabel($update['after'])
            );
        }

        return array() === $parts ? 'no change' : implode('; ', $parts);
    }

    /**
     * `12.34 (was 19.99)`, or `12.34` when there is no comparison price.
     *
     * @param array<string, mixed> $state A before/after pair.
     */
    private function priceLabel(array $state): string
    {
        $price = $state['price'];

        return null === $state['compare_at_price']
            ? (string) $price
            : sprintf('%s (was %s)', $price, $state['compare_at_price']);
    }
}
