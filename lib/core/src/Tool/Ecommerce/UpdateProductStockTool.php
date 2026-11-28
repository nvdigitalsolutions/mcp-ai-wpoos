<?php

/**
 * Change the stock level of a product's variants.
 *
 * Port of `WP_MCP_AI_Tool_Update_Woo_Product_Qty` (Proposal 164 section
 * 164.172).
 *
 * ## Three operations against one nullable integer
 *
 * `set` writes the quantity, `increase` and `decrease` move it. A variant that
 * does not track inventory has a null quantity, which is not the same as zero:
 * `increase` from null starts at the new quantity, and `decrease` from null is
 * a refusal rather than a silent zero. `manage_stock: false` turns tracking off
 * and clears the quantity, which is what WooCommerce does when a product is
 * switched to "do not manage stock".
 *
 * A `decrease` that would cross zero clamps at zero and reports `clamped`, so a
 * caller can tell a stock-out from a partial decrement. WordPress's `absint()`
 * hid that distinction by discarding the sign before the arithmetic.
 *
 * ## What is not accepted
 *
 * `notify` is absent from the schema. WordPress emails the store owner on a
 * low-stock threshold; this runtime binds no mail channel to the catalog, and a
 * parameter accepted and ignored is the defect this programme exists to remove.
 * A caller who needs an alert can schedule a `low_stock` query against the same
 * contract.
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

class UpdateProductStockTool extends AbstractProductVariantTool
{
    /** The operations offered. */
    public const OPERATIONS = array('set', 'increase', 'decrease');

    public function getSlug(): string
    {
        return 'update_woo_product_qty';
    }

    public function getName(): string
    {
        return 'Update Product Quantity';
    }

    public function getDescription(): string
    {
        return 'Sets or adjusts the stock quantity of a product variant, or of several. A decrease that would go below zero stops at zero and reports that it was clamped; turning stock management off clears the quantity.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_id'   => array(
                    'type'        => 'integer',
                    'description' => 'The product to update.',
                    'minimum'     => 1,
                ),
                'quantity'     => array(
                    'type'        => 'integer',
                    'description' => 'The quantity to set, add or subtract.',
                    'minimum'     => 0,
                ),
                'operation'    => array(
                    'type'        => 'string',
                    'description' => 'set writes the quantity, increase adds it, decrease subtracts it. Default set.',
                    'enum'        => self::OPERATIONS,
                ),
                'scope'        => array(
                    'type'        => 'string',
                    'description' => 'product sets the first variant, variations every later one, all every variant. Default product.',
                    'enum'        => self::SCOPES,
                ),
                'manage_stock' => array(
                    'type'        => 'boolean',
                    'description' => 'Whether the variant tracks stock at all. Default true; false clears the quantity.',
                ),
            ),
            'required'             => array('product_id', 'quantity'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $productId = $this->intParam($arguments, 'product_id', 0);
        $quantity = $this->intParam($arguments, 'quantity', -1);
        $scope = $this->scopeParam($arguments);
        $manageStock = $this->boolParam($arguments, 'manage_stock', true);
        $operation = $this->operationParam($arguments);

        $fields = array();

        if ($productId < 1) {
            $fields['product_id'] = array('A product id is required.');
        }

        if ($quantity < 0) {
            $fields['quantity'] = array('A non-negative whole number is required.');
        }

        if (! in_array($operation, self::OPERATIONS, true)) {
            $fields['operation'] = array('One of: ' . implode(', ', self::OPERATIONS) . '.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The stock level cannot be updated.', $fields);
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
        $clamped = false;

        foreach ($targets as $variant) {
            $update = $this->apply($productId, $variant, $quantity, $operation, $manageStock);
            $clamped = $clamped || $update['clamped'];
            $updates[] = $update;
        }

        $changed = array_values(array_filter($updates, static fn (array $u): bool => $u['changed']));

        return $this->success(
            sprintf('Updated the stock of %d variant(s) on product %d.', count($changed), $productId),
            array(
                'product_id' => $productId,
                'scope' => $scope,
                'operation' => $operation,
                'updated' => $changed,
                'unchanged' => count($updates) - count($changed),
                'clamped' => $clamped,
                'text' => sprintf(
                    '%d variant(s) updated: %s',
                    count($changed),
                    $this->summary($changed)
                ),
            )
        );
    }

    /**
     * Apply the operation to one variant and persist it when anything moved.
     *
     * @return array<string, mixed> Before and after, and whether anything moved.
     */
    private function apply(
        int $productId,
        ProductVariant $variant,
        int $quantity,
        string $operation,
        bool $manageStock,
    ): array {
        $before = array(
            'stock' => $variant->stock,
            'track_inventory' => $variant->trackInventory,
        );

        $clamped = false;

        if (! $manageStock) {
            $track = false;
            $stock = null;
        } else {
            $current = $variant->stock ?? 0;

            if ('increase' === $operation) {
                $next = $current + $quantity;
            } elseif ('decrease' === $operation) {
                $next = $current - $quantity;

                if ($next < 0) {
                    $next = 0;
                    $clamped = true;
                }
            } else {
                $next = $quantity;
            }

            $track = true;
            $stock = $next;
        }

        $after = array(
            'stock' => $stock,
            'track_inventory' => $track,
        );

        $changed = $before !== $after;

        if ($changed) {
            $this->catalog->saveVariant(
                $productId,
                $this->withChanges(
                    $variant,
                    array(
                        'stock' => $stock,
                        'trackInventory' => $track,
                    )
                )
            );
        }

        return array(
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'before' => $before,
            'after' => $after,
            'changed' => $changed,
            'clamped' => $clamped,
        );
    }

    /**
     * One line naming what moved.
     *
     * @param array<int, array<string, mixed>> $updates Changed results only.
     */
    private function summary(array $updates): string
    {
        if (array() === $updates) {
            return 'no change';
        }

        $parts = array();

        foreach ($updates as $update) {
            $parts[] = sprintf(
                'variant %s: %s -> %s%s',
                null === $update['variant_id'] ? '(unsaved)' : (string) $update['variant_id'],
                $this->stockLabel($update['before']),
                $this->stockLabel($update['after']),
                $update['clamped'] ? ' (clamped at zero)' : ''
            );
        }

        return implode('; ', $parts);
    }

    /**
     * `5` or `untracked`.
     *
     * @param array<string, mixed> $state A before/after pair.
     */
    private function stockLabel(array $state): string
    {
        if (! $state['track_inventory']) {
            return 'untracked';
        }

        return null === $state['stock'] ? 'untracked' : (string) $state['stock'];
    }

    /**
     * The operation, defaulting to set.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function operationParam(array $arguments): string
    {
        $operation = $this->stringParam($arguments, 'operation');

        return '' === $operation ? 'set' : strtolower($operation);
    }
}
