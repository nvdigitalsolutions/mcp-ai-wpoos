<?php

/**
 * Apply one set of price and stock changes across many products.
 *
 * Port of `WP_MCP_AI_Tool_Bulk_Update_Products` (Proposal 164 section 164.173).
 * Uses the same catalog contract, the same variant mapping and the same scopes
 * as the single-product tools in section 164.172.
 *
 * ## One deliberate divergence
 *
 * WordPress applies `price_adjustment` to `regular_price`, which in this port's
 * mapping is the *comparison* price. Adjusting that would leave the amount the
 * customer pays untouched, and on a product with no comparison price it would
 * do nothing at all — a bulk price tool that changes no price. The port adjusts
 * the **charged** price instead, and says so here and in the description. The
 * explicit `regular_price` and `sale_price` fields keep the WooCommerce-exact
 * behaviour available for callers who want it.
 *
 * ## Ambiguity is refused, not resolved by ordering
 *
 * WordPress lets `sale_price` and `price_adjustment` both be present and applies
 * them in a fixed order, and it does the same for a stock field alongside
 * `manage_stock: false`. Two instructions about one field in one call is a
 * caller mistake, so each of those combinations is a field error naming the
 * conflict rather than an order-dependent guess.
 *
 * ## `onbackorder` is refused, not approximated
 *
 * The runtime variant tracks a quantity and whether that quantity is tracked. It
 * has no "backorder" state, so `stock_status: onbackorder` is a field error
 * naming the missing concept rather than a silent mapping onto zero stock.
 *
 * ## Fields this slice does not write
 *
 * WooCommerce's bulk update also accepts `status`, `featured`, `add_categories`,
 * `remove_categories`, `add_tags` and `remove_tags`. The first three have no
 * representation in this model. `status` and the tag fields do — but on
 * `Product`, which is a readonly entity with its own copy path, while this tool
 * writes `ProductVariant`. All six are therefore **refused by name**, not
 * dropped: a caller who sends one is told it did not happen.
 *
 * ## Filtering
 *
 * `product_ids` wins when supplied, as it does in WordPress. Otherwise the filter
 * narrows the catalog through {@see ProductQuery}. `category` and
 * `stock_status` have no counterpart — the runtime product carries tags and a
 * type rather than a category tree, and the query filters on whether a variant
 * is sellable rather than on which of three stock states it is in — so both are
 * refused by name.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductQuery;
use Nvoos\Core\Domain\Entity\ProductVariant;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\ProductStatus;

final class BulkUpdateProductsTool extends AbstractProductVariantTool
{
    /** The price adjustments WooCommerce offers. */
    public const ADJUSTMENT_TYPES = array('percentage', 'fixed');

    /** The directions an adjustment offers. */
    public const ADJUSTMENT_ACTIONS = array('increase', 'decrease');

    /** The stock states the runtime can express. */
    public const STOCK_STATUSES = array('instock', 'outofstock');

    /** The update fields this tool writes. */
    public const SUPPORTED_UPDATES = array(
        'sale_price',
        'regular_price',
        'price_adjustment',
        'stock_quantity',
        'stock_status',
        'manage_stock',
    );

    /** The filter fields this tool honours. */
    public const SUPPORTED_FILTERS = array(
        'status',
        'search',
        'tags',
        'vendor',
        'in_stock_only',
        'limit',
    );

    /**
     * Update fields WooCommerce offers that this slice refuses, with the reason.
     *
     * @var array<string, string>
     */
    public const UNSUPPORTED_UPDATES = array(
        'status'            => 'A product-level field. This tool writes variants; product-level updates are a separate slice.',
        'featured'          => 'The runtime product has no featured flag.',
        'add_categories'    => 'The runtime product carries tags and a type, not a category tree.',
        'remove_categories' => 'The runtime product carries tags and a type, not a category tree.',
        'add_tags'          => 'A product-level field. This tool writes variants; product-level updates are a separate slice.',
        'remove_tags'       => 'A product-level field. This tool writes variants; product-level updates are a separate slice.',
    );

    /**
     * Filter fields WooCommerce offers that this slice refuses, with the reason.
     *
     * @var array<string, string>
     */
    public const UNSUPPORTED_FILTERS = array(
        'category'     => 'The runtime product carries tags and a type, not a category tree.',
        'stock_status' => 'The catalog query can select only sellable products; it cannot select only out-of-stock ones. Use in_stock_only.',
    );

    /**
     * The filter statuses a WooCommerce caller sends, and what they mean here.
     *
     * `private` is absent on purpose: the runtime product has draft, published
     * and archived, and no private state to map onto.
     *
     * @var array<string, string>
     */
    public const STATUS_ALIASES = array(
        'publish'   => 'published',
        'published' => 'published',
        'draft'     => 'draft',
        'archived'  => 'archived',
    );

    /** The most products one call will touch. */
    public const MAX_PRODUCTS = 100;

    /** How many products a filter selects when it does not say. */
    public const DEFAULT_LIMIT = 50;

    public function getSlug(): string
    {
        return 'bulk_update_products';
    }

    public function getName(): string
    {
        return 'Bulk Update Products';
    }

    public function getDescription(): string
    {
        return 'Applies one set of price and stock changes across many products, optionally as a dry run that reports what would change. The price adjustment applies to the charged price, not the comparison price. Product-level fields such as status and tags are not written by this tool.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_ids' => array(
                    'type'        => 'array',
                    'description' => 'Explicit products to update. Wins over filter when supplied.',
                    'items'       => array('type' => 'integer'),
                    'maxItems'    => self::MAX_PRODUCTS,
                ),
                'filter'      => array(
                    'type'        => 'object',
                    'description' => 'Selects products when product_ids is omitted.',
                    'properties'  => array(
                        'status'        => array(
                            'type'        => 'string',
                            'description' => 'publish or published, draft, or archived. private is not representable.',
                            'enum'        => array_keys(self::STATUS_ALIASES),
                        ),
                        'search'        => array(
                            'type'        => 'string',
                            'description' => 'Free text matched against the product name.',
                        ),
                        'tags'          => array(
                            'type'        => 'array',
                            'description' => 'Products carrying every one of these tags.',
                            'items'       => array('type' => 'string'),
                        ),
                        'vendor'        => array(
                            'type'        => 'string',
                            'description' => 'Vendor name.',
                        ),
                        'in_stock_only' => array(
                            'type'        => 'boolean',
                            'description' => 'Only products with a sellable variant.',
                        ),
                        'limit'         => array(
                            'type'        => 'integer',
                            'description' => 'How many products to select. Default 50, at most 100.',
                            'minimum'     => 1,
                            'maximum'     => self::MAX_PRODUCTS,
                        ),
                    ),
                ),
                'updates'     => array(
                    'type'        => 'object',
                    'description' => 'The changes to apply. At least one field is required.',
                    'properties'  => array(
                        'sale_price'       => array(
                            'type'        => 'number',
                            'description' => 'Set the charged price to this amount.',
                            'minimum'     => 0,
                        ),
                        'regular_price'    => array(
                            'type'        => 'number',
                            'description' => 'Set the comparison price to this amount.',
                            'minimum'     => 0,
                        ),
                        'price_adjustment' => array(
                            'type'        => 'object',
                            'description' => 'Move the charged price by an amount or a percentage. Not combinable with sale_price.',
                            'properties'  => array(
                                'type'   => array('type' => 'string', 'enum' => self::ADJUSTMENT_TYPES),
                                'value'  => array('type' => 'number', 'minimum' => 0),
                                'action' => array('type' => 'string', 'enum' => self::ADJUSTMENT_ACTIONS),
                            ),
                            'required'    => array('type', 'value'),
                        ),
                        'stock_quantity'   => array(
                            'type'        => 'integer',
                            'description' => 'Set the stock quantity. Not combinable with stock_status or with manage_stock false.',
                            'minimum'     => 0,
                        ),
                        'stock_status'     => array(
                            'type'        => 'string',
                            'description' => 'instock sets the quantity to at least one, outofstock sets it to zero. Backorder is not representable.',
                            'enum'        => self::STOCK_STATUSES,
                        ),
                        'manage_stock'     => array(
                            'type'        => 'boolean',
                            'description' => 'Whether the variants track stock. False clears the quantity.',
                        ),
                    ),
                ),
                'scope'       => array(
                    'type'        => 'string',
                    'description' => 'product selects the first variant, variations every later one, all every variant. Default all.',
                    'enum'        => self::SCOPES,
                ),
                'dry_run'     => array(
                    'type'        => 'boolean',
                    'description' => 'Report what would change without writing anything.',
                ),
            ),
            'required'             => array('updates'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $updates = $arguments['updates'] ?? null;

        if (! is_array($updates)) {
            return $this->errors->validationFailed(
                'The bulk update cannot be applied.',
                array('updates' => array('An object of fields to change.'))
            );
        }

        $fields = array();
        $dryRun = $this->boolParam($arguments, 'dry_run', false);
        $scope = $this->stringParam($arguments, 'scope', 'all');

        if (! in_array($scope, self::SCOPES, true)) {
            $fields['scope'] = array('One of: ' . implode(', ', self::SCOPES) . '.');
        }

        $plan = $this->plan($updates, $fields);
        $products = $this->resolveProducts($arguments, $fields);

        if (array() !== $fields) {
            return $this->errors->validationFailed('The bulk update cannot be applied.', $fields);
        }

        if (array() === $products) {
            return $this->success(
                'No products matched.',
                array(
                    'dry_run'          => $dryRun,
                    'scope'            => $scope,
                    'matched'          => 0,
                    'updated'          => 0,
                    'unchanged'        => 0,
                    'skipped_products' => array(),
                    'results'          => array(),
                )
            );
        }

        $results = array();
        $pending = array();
        $skipped = array();

        foreach ($products as $product) {
            if (null === $product->id) {
                // A catalog product without an id cannot be written to.
                continue;
            }

            $targets = $this->targets($product, $scope);

            if (array() === $targets) {
                $skipped[] = $product->id;
                continue;
            }

            foreach ($targets as $variant) {
                $result = $this->applyToVariant($product, $variant, $plan);
                $savable = $result['variant'];

                unset($result['variant']);
                $results[] = $result;

                if (array() !== $result['changes']) {
                    $pending[] = array(
                        'product_id' => $result['product_id'],
                        'variant'    => $savable,
                    );
                }
            }
        }

        if (! $dryRun) {
            foreach ($pending as $write) {
                $this->catalog->saveVariant($write['product_id'], $write['variant']);
            }
        }

        $updated = count($pending);
        $unchanged = count($results) - $updated;

        return $this->success(
            sprintf(
                '%s %d variant(s) across %d product(s).',
                $dryRun ? 'Would update' : 'Updated',
                $updated,
                count($products)
            ),
            array(
                'dry_run'          => $dryRun,
                'scope'            => $scope,
                'matched'          => count($products),
                'updated'          => $updated,
                'unchanged'        => $unchanged,
                'skipped_products' => $skipped,
                'results'          => $results,
            )
        );
    }

    /**
     * Normalise the requested updates into a plan, recording field errors.
     *
     * @param array<string, mixed> $updates Raw updates.
     * @param array<string, mixed> $fields  Field errors, by reference.
     *
     * @return array{
     *     price: float|null,
     *     compare_at: float|null,
     *     adjustment: array{type: string, action: string, value: float}|null,
     *     quantity: int|null,
     *     in_stock: bool,
     *     track: bool|null
     * }
     */
    private function plan(array $updates, array &$fields): array
    {
        $this->refuseUnsupported($updates, self::SUPPORTED_UPDATES, self::UNSUPPORTED_UPDATES, 'updates', $fields);

        $plan = array(
            'price'      => null,
            'compare_at' => null,
            'adjustment' => null,
            'quantity'   => null,
            'in_stock'   => false,
            'track'      => null,
        );

        // An explicit null is "not supplied", matching the single-product tools:
        // a variant's charged price has no empty state to clear.
        if (array_key_exists('sale_price', $updates) && null !== $updates['sale_price']) {
            $plan['price'] = $this->amount($updates['sale_price']);

            if (null === $plan['price']) {
                $fields['updates.sale_price'] = array('A non-negative number.');
            }
        }

        if (array_key_exists('regular_price', $updates) && null !== $updates['regular_price']) {
            $plan['compare_at'] = $this->amount($updates['regular_price']);

            if (null === $plan['compare_at']) {
                $fields['updates.regular_price'] = array('A non-negative number.');
            }
        }

        if (array_key_exists('price_adjustment', $updates) && null !== $updates['price_adjustment']) {
            $plan['adjustment'] = $this->adjustment($updates['price_adjustment'], $fields);
        }

        if (array_key_exists('stock_quantity', $updates) && null !== $updates['stock_quantity']) {
            $quantity = $updates['stock_quantity'];

            if (! is_numeric($quantity) || (int) $quantity < 0) {
                $fields['updates.stock_quantity'] = array('A non-negative whole number.');
            } else {
                $plan['quantity'] = (int) $quantity;
            }
        }

        if (array_key_exists('stock_status', $updates) && null !== $updates['stock_status']) {
            $status = is_string($updates['stock_status']) ? strtolower($updates['stock_status']) : '';

            if (! in_array($status, self::STOCK_STATUSES, true)) {
                $fields['updates.stock_status'] = array(
                    'One of: ' . implode(', ', self::STOCK_STATUSES) . '. The runtime cannot express backorder.',
                );
            } elseif ('instock' === $status) {
                $plan['in_stock'] = true;
            } else {
                $plan['quantity'] = 0;
            }
        }

        if (array_key_exists('manage_stock', $updates) && null !== $updates['manage_stock']) {
            $plan['track'] = $this->boolParam($updates, 'manage_stock', true);
        }

        $this->refuseConflicts($updates, $plan, $fields);

        $touched = null !== $plan['price']
            || null !== $plan['compare_at']
            || null !== $plan['adjustment']
            || null !== $plan['quantity']
            || $plan['in_stock']
            || null !== $plan['track'];

        if (! $touched && array() === $fields) {
            $fields['updates'] = array(
                'At least one of ' . implode(', ', self::SUPPORTED_UPDATES) . ' is required.',
            );
        }

        return $plan;
    }

    /**
     * Refuse two instructions about the same field in one call.
     *
     * @param array<string, mixed> $updates Raw updates.
     * @param array<string, mixed> $plan    Normalised plan.
     * @param array<string, mixed> $fields  Field errors, by reference.
     */
    private function refuseConflicts(array $updates, array $plan, array &$fields): void
    {
        if (null !== ($updates['sale_price'] ?? null) && null !== ($updates['price_adjustment'] ?? null)) {
            $fields['updates.price_adjustment'] = array(
                'Cannot be combined with sale_price: both set the charged price.',
            );
        }

        if (false !== $plan['track']) {
            return;
        }

        if (null !== ($updates['stock_quantity'] ?? null)) {
            $fields['updates.stock_quantity'] = array(
                'Cannot be combined with manage_stock false: untracked stock has no quantity.',
            );
        }

        if (null !== ($updates['stock_status'] ?? null)) {
            $fields['updates.stock_status'] = array(
                'Cannot be combined with manage_stock false: untracked stock has no status.',
            );
        }
    }

    /**
     * A validated adjustment, or null after recording an error.
     *
     * @param mixed                $raw    Raw adjustment.
     * @param array<string, mixed> $fields Field errors, by reference.
     *
     * @return array{type: string, action: string, value: float}|null
     */
    private function adjustment(mixed $raw, array &$fields): ?array
    {
        if (! is_array($raw)) {
            $fields['updates.price_adjustment'] = array('An object with type, value and action.');
            return null;
        }

        $type = is_string($raw['type'] ?? null) ? strtolower($raw['type']) : '';
        $action = is_string($raw['action'] ?? null) ? strtolower($raw['action']) : 'increase';
        $value = $this->amount($raw['value'] ?? null);

        if (! in_array($type, self::ADJUSTMENT_TYPES, true)) {
            $fields['updates.price_adjustment.type'] = array('One of: ' . implode(', ', self::ADJUSTMENT_TYPES) . '.');
            return null;
        }

        if (! in_array($action, self::ADJUSTMENT_ACTIONS, true)) {
            $fields['updates.price_adjustment.action'] = array('One of: ' . implode(', ', self::ADJUSTMENT_ACTIONS) . '.');
            return null;
        }

        if (null === $value) {
            $fields['updates.price_adjustment.value'] = array('A non-negative number.');
            return null;
        }

        return array('type' => $type, 'action' => $action, 'value' => $value);
    }

    /**
     * The products to update.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     *
     * @return array<int, Product>
     */
    private function resolveProducts(array $arguments, array &$fields): array
    {
        if (array_key_exists('product_ids', $arguments)) {
            return $this->resolveExplicit($arguments['product_ids'], $fields);
        }

        $filter = $arguments['filter'] ?? array();

        if (! is_array($filter)) {
            $fields['filter'] = array('An object of filter criteria.');
            return array();
        }

        $this->refuseUnsupported($filter, self::SUPPORTED_FILTERS, self::UNSUPPORTED_FILTERS, 'filter', $fields);

        $limit = isset($filter['limit']) ? (int) $filter['limit'] : self::DEFAULT_LIMIT;

        if ($limit < 1 || $limit > self::MAX_PRODUCTS) {
            $fields['filter.limit'] = array(sprintf('1 to %d.', self::MAX_PRODUCTS));
            return array();
        }

        $status = null;

        if (isset($filter['status'])) {
            $status = $this->status($filter['status'], $fields);

            if (null === $status) {
                return array();
            }
        }

        $query = new ProductQuery(
            search: isset($filter['search']) && is_string($filter['search']) ? $filter['search'] : '',
            status: $status,
            vendor: isset($filter['vendor']) && is_string($filter['vendor']) ? $filter['vendor'] : null,
            tags: isset($filter['tags']) && is_array($filter['tags']) ? array_values($filter['tags']) : array(),
            inStockOnly: ! empty($filter['in_stock_only']),
            perPage: $limit,
        );

        return $this->catalog->query($query)->items;
    }

    /**
     * Resolve an explicit id list, skipping ids the catalog does not hold.
     *
     * @param mixed                $raw    Raw product ids.
     * @param array<string, mixed> $fields Field errors, by reference.
     *
     * @return array<int, Product>
     */
    private function resolveExplicit(mixed $raw, array &$fields): array
    {
        if (! is_array($raw)) {
            $fields['product_ids'] = array('An array of product ids.');
            return array();
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $id): bool => $id > 0
        )));

        if (array() === $ids) {
            $fields['product_ids'] = array('At least one product id is required.');
            return array();
        }

        if (count($ids) > self::MAX_PRODUCTS) {
            $fields['product_ids'] = array(sprintf('At most %d products per call.', self::MAX_PRODUCTS));
            return array();
        }

        $products = array();

        foreach ($ids as $id) {
            $product = $this->catalog->find($id);

            if (null !== $product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Map a WooCommerce filter status onto a runtime one.
     *
     * @param mixed                $raw    Raw status.
     * @param array<string, mixed> $fields Field errors, by reference.
     */
    private function status(mixed $raw, array &$fields): ?ProductStatus
    {
        $key = is_string($raw) ? strtolower(trim($raw)) : '';
        $mapped = self::STATUS_ALIASES[$key] ?? null;

        if (null === $mapped) {
            $fields['filter.status'] = array(
                'One of: ' . implode(', ', array_keys(self::STATUS_ALIASES)) . '. The runtime has no private product status.',
            );

            return null;
        }

        return ProductStatus::from($mapped);
    }

    /**
     * Record a field error for every key the tool does not handle.
     *
     * Named so the caller learns which field was refused and why, rather than
     * receiving a success envelope for a write that never happened.
     *
     * @param array<string, mixed>  $given     Raw input.
     * @param array<int, string>    $supported Keys the tool handles.
     * @param array<string, string> $reasons   Reasons for the known omissions.
     * @param array<string, mixed>  $fields    Field errors, by reference.
     */
    private function refuseUnsupported(
        array $given,
        array $supported,
        array $reasons,
        string $prefix,
        array &$fields
    ): void {
        foreach (array_keys($given) as $key) {
            if (in_array($key, $supported, true)) {
                continue;
            }

            $fields[$prefix . '.' . $key] = array($reasons[$key] ?? 'Unknown field.');
        }
    }

    /**
     * Apply a percentage or fixed adjustment, rounded to the currency's unit.
     *
     * The arithmetic is done in minor units because `Money::fromDecimal()`
     * refuses a sub-cent amount rather than silently truncating it: 10% of
     * 19.99 is 21.989, which is not a USD amount. Rounding once, at the end,
     * keeps 21.99 and stops a stack of adjustments drifting. The unit comes
     * from the currency, so a zero-exponent currency is not divided by 100.
     *
     * @param array{type: string, action: string, value: float} $adjustment Adjustment.
     */
    private function adjust(Money $price, array $adjustment): Money
    {
        $unit = 10 ** $price->exponent();
        $current = $price->minorUnits;

        $delta = 'percentage' === $adjustment['type']
            ? $current * ( $adjustment['value'] / 100 )
            : $adjustment['value'] * $unit;

        $next = 'increase' === $adjustment['action'] ? $current + $delta : $current - $delta;

        return Money::fromMinorUnits(max(0, (int) round($next)), $price->currency);
    }

    /**
     * Compute the plan against one variant.
     *
     * A value that is already what the caller asked for is not recorded as a
     * change, so an idempotent call performs no writes.
     *
     * @param array{
     *     price: float|null,
     *     compare_at: float|null,
     *     adjustment: array{type: string, action: string, value: float}|null,
     *     quantity: int|null,
     *     in_stock: bool,
     *     track: bool|null
     * } $plan Normalised updates.
     *
     * @return array{
     *     product_id: int,
     *     variant_id: int|null,
     *     sku: string|null,
     *     before: array<string, mixed>,
     *     changes: array<string, mixed>,
     *     variant: ProductVariant
     * }
     */
    private function applyToVariant(Product $product, ProductVariant $variant, array $plan): array
    {
        $currency = $variant->price->currency;
        $changes = array();

        $price = $variant->price;
        $compareAt = $variant->compareAtPrice;
        $stock = $variant->stock;
        $track = $variant->trackInventory;

        if (null !== $plan['price']) {
            $candidate = $this->money($plan['price'], $currency);

            if (! $candidate->equals($variant->price)) {
                $changes['price'] = $this->decimal($candidate);
            }

            $price = $candidate;
        }

        if (null !== $plan['compare_at']) {
            $candidate = $this->money($plan['compare_at'], $currency);

            if (null === $variant->compareAtPrice || ! $candidate->equals($variant->compareAtPrice)) {
                $changes['compare_at_price'] = $this->decimal($candidate);
            }

            $compareAt = $candidate;
        }

        if (null !== $plan['adjustment']) {
            // Adjusts the charged price, unlike WordPress, which adjusts the
            // comparison price; see the class docblock.
            $candidate = $this->adjust($price, $plan['adjustment']);

            if (! $candidate->equals($variant->price)) {
                $changes['price'] = $this->decimal($candidate);
            }

            $price = $candidate;
        }

        if (false === $plan['track']) {
            $track = false;
            $stock = null;
        } else {
            if (null !== $plan['quantity']) {
                $stock = $plan['quantity'];
                $track = true;
            }

            if ($plan['in_stock']) {
                $stock = max(1, $stock ?? 0);
                $track = true;
            }

            if (true === $plan['track']) {
                $track = true;
            }
        }

        if ($stock !== $variant->stock) {
            $changes['stock'] = $stock;
        }

        if ($track !== $variant->trackInventory) {
            $changes['track_inventory'] = $track;
        }

        return array(
            'product_id' => (int) $product->id,
            'variant_id' => $variant->id,
            'sku'        => $variant->sku,
            'before'     => array(
                'price'            => $this->decimal($variant->price),
                'compare_at_price' => null === $variant->compareAtPrice ? null : $this->decimal($variant->compareAtPrice),
                'stock'            => $variant->stock,
                'track_inventory'  => $variant->trackInventory,
            ),
            'changes'    => $changes,
            'variant'    => $this->withChanges(
                $variant,
                array(
                    'price'          => $price,
                    'compareAtPrice' => $compareAt,
                    'stock'          => $stock,
                    'trackInventory' => $track,
                )
            ),
        );
    }
}
