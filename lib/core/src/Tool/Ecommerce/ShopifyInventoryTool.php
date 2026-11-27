<?php

/**
 * Shopify inventory: levels, adjustments, locations and shop info.
 *
 * Proposal 164 section 164.113. Port of `WP_MCP_AI_Pro_Tool_Shopify_Inventory`.
 *
 * ## The correction that would have made every adjustment fail
 *
 * `InventoryChangeInput.changeFromQuantity` is documented as **mandatory**: "you
 * must explicitly pass in a value, even if that value is `null`, or the mutation
 * returns an error". The WordPress client sent only
 * `{inventoryItemId, locationId, delta}`, so `inventoryAdjustQuantities` was
 * rejected on the current schema. This port always sends the key — `null` by
 * default, or the caller's `expected_quantity` when they want Shopify's
 * compare-and-swap guard against a concurrent update.
 *
 * Two smaller ones:
 *
 * - **`delta` must be non-zero.** A zero delta is a no-op that still writes an
 *   inventory-history entry; refusing it is more useful than silently recording
 *   that nothing happened.
 * - **`reason` is validated against the provider's list before the call.**
 *   Shopify types it as `String!` and documents the permitted values, so a typo
 *   becomes a validation error here rather than a provider error after a round
 *   trip.
 *
 * `get_shop_info` deliberately omits `Shop.plan`: the plan field has moved
 * between releases, and a query that names it risks failing for a decoration.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

class ShopifyInventoryTool extends ShopifyTool
{
    /**
     * The quantity names the levels query reads.
     */
    private const QUANTITY_NAMES = '["available","incoming","on_hand","reserved"]';

    /**
     * The adjustment reasons Shopify documents for `available`.
     *
     * @var list<string>
     */
    private const REASONS = array(
        'correction',
        'cycle_count_available',
        'damaged',
        'movement_created',
        'movement_updated',
        'movement_received',
        'movement_canceled',
        'other',
        'promotion',
        'quality_control',
        'received',
        'reservation_created',
        'reservation_deleted',
        'reservation_updated',
        'retail_pack',
        'shrinkage',
        'unknown',
        'unpack',
    );

    public function getSlug(): string
    {
        return 'shopify_inventory';
    }

    public function getName(): string
    {
        return 'Shopify Inventory';
    }

    public function getDescription(): string
    {
        return 'Read and adjust inventory on a Shopify store over the Admin GraphQL API. '
            . 'Lists stock levels per location (available, incoming, on hand, reserved), adjusts the available quantity '
            . 'with an optional compare-and-swap guard, lists locations, and reports the shop. '
            . 'Adjustments change live stock, so confirm the delta first.';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'state-changing',
        );
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'store_url'         => array(
                    'type'        => 'string',
                    'description' => 'Store root, e.g. https://store.myshopify.com. No path.',
                    'minLength'   => 1,
                ),
                'access_token'      => array(
                    'type'        => 'string',
                    'description' => 'Shopify Admin API access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'api_version'       => array(
                    'type'        => 'string',
                    'description' => 'Admin API version, e.g. 2026-10.',
                    'pattern'     => '^\\d{4}-(01|04|07|10)$',
                ),
                'action'            => array(
                    'type'        => 'string',
                    'description' => 'list_levels (default), adjust, list_locations or get_shop_info.',
                    'enum'        => array('list_levels', 'adjust', 'list_locations', 'get_shop_info'),
                ),
                'location_id'       => array(
                    'type'        => 'string',
                    'description' => 'Location GID or bare numeric id. For list_levels, defaults to the first location.',
                ),
                'inventory_item_id' => array(
                    'type'        => 'string',
                    'description' => 'InventoryItem GID or bare numeric id, for adjust.',
                ),
                'delta'             => array(
                    'type'        => 'integer',
                    'description' => 'Quantity change for adjust. Positive adds stock, negative removes. Must not be zero.',
                ),
                'expected_quantity' => array(
                    'type'        => 'integer',
                    'description' => 'Current quantity the caller believes is on hand. When given, Shopify refuses the '
                        . 'adjustment if it has changed (compare-and-swap). Omit to skip the check.',
                ),
                'reason'            => array(
                    'type'        => 'string',
                    'description' => 'Why the quantity changed. Defaults to correction.',
                    'enum'        => self::REASONS,
                ),
                'first'             => array(
                    'type'        => 'integer',
                    'description' => 'Rows per page, 1-250. Defaults to 50 for levels and 10 for locations.',
                    'minimum'     => 1,
                    'maximum'     => 250,
                ),
                'after'             => array(
                    'type'        => 'string',
                    'description' => 'next_cursor from a previous list_levels page.',
                ),
            ),
            'required'             => array('store_url', 'access_token', 'action'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $store = $this->storeUrl($arguments);

        if ($this->errors->isError($store)) {
            return $store;
        }

        $token = $this->token($arguments);

        if ($this->errors->isError($token)) {
            return $token;
        }

        $version = $this->apiVersion($arguments);

        if ($this->errors->isError($version)) {
            return $version;
        }

        $action = $this->text($arguments['action'] ?? null);

        switch ('' !== $action ? $action : 'list_levels') {
            case 'list_levels':
                return $this->handleLevels((string) $store, (string) $token, (string) $version, $arguments);

            case 'adjust':
                return $this->handleAdjust((string) $store, (string) $token, (string) $version, $arguments);

            case 'list_locations':
                return $this->handleLocations((string) $store, (string) $token, (string) $version, $arguments);

            case 'get_shop_info':
                return $this->handleShop((string) $store, (string) $token, (string) $version);

            default:
                return $this->errors->validationFailed(
                    'Unknown action.',
                    array('action' => array('Expected list_levels, adjust, list_locations or get_shop_info.'))
                );
        }
    }

    /**
     * Stock levels, for one location or the store's first.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleLevels(string $store, string $token, string $version, array $arguments): mixed
    {
        $first    = $this->limit($arguments['first'] ?? null, 50);
        $after    = $this->cursor($arguments);
        $location = $this->gid($this->text($arguments['location_id'] ?? null), 'Location');

        $rawLocation = $this->text($arguments['location_id'] ?? null);

        if ('' !== $rawLocation && null === $location) {
            return $this->errors->validationFailed(
                'The location id is not a Shopify id.',
                array('location_id' => array('Pass a GID or a numeric location id.'))
            );
        }

        $variables = array('first' => $first);

        if ('' !== $after) {
            $variables['after'] = $after;
        }

        if (null === $location) {
            $query = 'query LocationsInventory($first: Int!, $after: String) {
                locations(first: 1) {
                    edges {
                        node {
                            id name
                            inventoryLevels(first: $first, after: $after) {
                                pageInfo { hasNextPage endCursor }
                                edges {
                                    node {
                                        id
                                        quantities(names: ' . self::QUANTITY_NAMES . ') { name quantity }
                                        item { id sku variant { id title product { id title } } }
                                    }
                                }
                            }
                        }
                    }
                }
            }';
        } else {
            $query               = 'query InventoryLevels($locationId: ID!, $first: Int!, $after: String) {
                location(id: $locationId) {
                    id name
                    inventoryLevels(first: $first, after: $after) {
                        pageInfo { hasNextPage endCursor }
                        edges {
                            node {
                                id
                                quantities(names: ' . self::QUANTITY_NAMES . ') { name quantity }
                                item { id sku variant { id title product { id title } } }
                            }
                        }
                    }
                }
            }';
            $variables['locationId'] = $location;
        }

        $result = $this->graphql($store, $token, $version, $query, $variables);

        if ($this->errors->isError($result)) {
            return $result;
        }

        $locationNode = null;

        if (null !== $location) {
            $locationNode = is_array($result) ? ( $result['data']['location'] ?? null ) : null;
        } else {
            $locations = $this->edges($result, 'locations');

            $locationNode = is_array($locations[0] ?? null) ? $locations[0] : null;
        }

        if (! is_array($locationNode)) {
            return $this->errors->notFound(
                null === $location ? 'This store has no locations.' : 'No location has that id.',
                null === $location ? array() : array('location_id' => $location)
            );
        }

        $levels = array();

        foreach ($this->edgesOf($locationNode, 'inventoryLevels') as $level) {
            $levels[] = $this->normalizeLevel($level);
        }

        $data = array(
            'location_id'   => $this->text($locationNode['id'] ?? null),
            'location_name' => $this->text($locationNode['name'] ?? null),
            'levels'        => $levels,
            'count'         => count($levels),
            'page_info'     => $this->pageInfoOf($locationNode, 'inventoryLevels'),
        );

        $data = $this->meta($data, $result);

        return $this->success(
            sprintf('Read %d inventory level%s.', count($levels), 1 === count($levels) ? '' : 's'),
            $data
        );
    }

    /**
     * Adjust available stock for one item at one location.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleAdjust(string $store, string $token, string $version, array $arguments): mixed
    {
        $item = $this->gid($this->text($arguments['inventory_item_id'] ?? null), 'InventoryItem');

        if (null === $item) {
            return $this->errors->validationFailed(
                'An inventory item id is required for the adjust action.',
                array('inventory_item_id' => array('Pass a GID or a numeric inventory item id.'))
            );
        }

        $location = $this->gid($this->text($arguments['location_id'] ?? null), 'Location');

        if (null === $location) {
            return $this->errors->validationFailed(
                'A location id is required for the adjust action.',
                array('location_id' => array('Pass a GID or a numeric location id.'))
            );
        }

        if (! isset($arguments['delta']) || ! is_numeric($arguments['delta'])) {
            return $this->errors->validationFailed(
                'A delta is required for the adjust action.',
                array('delta' => array('Pass a non-zero integer.'))
            );
        }

        $delta = (int) $arguments['delta'];

        if (0 === $delta) {
            return $this->errors->validationFailed(
                'A delta of zero would change nothing.',
                array('delta' => array('Pass a non-zero integer.'))
            );
        }

        $reason = $this->text($arguments['reason'] ?? null);
        $reason = '' !== $reason ? $reason : 'correction';

        if (! in_array($reason, self::REASONS, true)) {
            return $this->errors->validationFailed(
                'That adjustment reason is not one Shopify accepts.',
                array('reason' => array('One of: ' . implode(', ', self::REASONS) . '.'))
            );
        }

        $change = array(
            'inventoryItemId' => $item,
            'locationId'      => $location,
            'delta'           => $delta,
            // Mandatory in the schema: must be present, even as null.
            'changeFromQuantity' => isset($arguments['expected_quantity']) && is_numeric($arguments['expected_quantity'])
                ? (int) $arguments['expected_quantity']
                : null,
        );

        $result = $this->graphql(
            $store,
            $token,
            $version,
            'mutation AdjustInventory($input: InventoryAdjustQuantitiesInput!) {
                inventoryAdjustQuantities(input: $input) {
                    inventoryAdjustmentGroup {
                        createdAt reason
                        changes { name delta quantityAfterChange item { id sku } location { id name } }
                    }
                    userErrors { field message }
                }
            }',
            array(
                'input' => array(
                    'reason'  => $reason,
                    'name'    => 'available',
                    'changes' => array($change),
                ),
            )
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $refusal = $this->userError($result, 'inventoryAdjustQuantities');

        if (null !== $refusal) {
            return $refusal;
        }

        $group   = is_array($result) ? ( $result['data']['inventoryAdjustQuantities']['inventoryAdjustmentGroup'] ?? null ) : null;
        $changes = array();

        if (is_array($group)) {
            foreach (is_array($group['changes'] ?? null) ? $group['changes'] : array() as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $changes[] = array(
                    'name'                => $this->text($entry['name'] ?? null),
                    'delta'               => is_numeric($entry['delta'] ?? null) ? (int) $entry['delta'] : 0,
                    'quantity_after'      => is_numeric($entry['quantityAfterChange'] ?? null) ? (int) $entry['quantityAfterChange'] : null,
                    'inventory_item_id'   => is_array($entry['item'] ?? null) ? $this->text($entry['item']['id'] ?? null) : '',
                    'sku'                 => is_array($entry['item'] ?? null) ? $this->text($entry['item']['sku'] ?? null) : '',
                    'location_id'         => is_array($entry['location'] ?? null) ? $this->text($entry['location']['id'] ?? null) : '',
                    'location_name'       => is_array($entry['location'] ?? null) ? $this->text($entry['location']['name'] ?? null) : '',
                );
            }
        }

        $data = array(
            'reason'     => is_array($group) ? $this->text($group['reason'] ?? null) : $reason,
            'created_at' => is_array($group) ? $this->text($group['createdAt'] ?? null) : '',
            'changes'    => $changes,
        );

        $data = $this->meta($data, $result);

        return $this->success(
            sprintf('Adjusted stock by %+d.', $delta),
            $data
        );
    }

    /**
     * The store's locations.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleLocations(string $store, string $token, string $version, array $arguments): mixed
    {
        $first = $this->limit($arguments['first'] ?? null, 10);

        $result = $this->graphql(
            $store,
            $token,
            $version,
            'query Locations($first: Int!) {
                locations(first: $first) {
                    edges {
                        node { id name isActive address { city province zip country } }
                    }
                }
            }',
            array('first' => $first)
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $locations = array();

        foreach ($this->edges($result, 'locations') as $node) {
            $address = is_array($node['address'] ?? null) ? $node['address'] : array();

            $locations[] = array(
                'id'        => $this->text($node['id'] ?? null),
                'name'      => $this->text($node['name'] ?? null),
                'is_active' => (bool) ( $node['isActive'] ?? false ),
                'address'   => array(
                    'city'     => $this->text($address['city'] ?? null),
                    'province' => $this->text($address['province'] ?? null),
                    'zip'      => $this->text($address['zip'] ?? null),
                    'country'  => $this->text($address['country'] ?? null),
                ),
            );
        }

        $data = array(
            'locations' => $locations,
            'count'     => count($locations),
        );

        $data = $this->meta($data, $result);

        return $this->success(
            sprintf('Found %d location%s.', count($locations), 1 === count($locations) ? '' : 's'),
            $data
        );
    }

    /**
     * The shop's basic settings.
     */
    private function handleShop(string $store, string $token, string $version): mixed
    {
        $result = $this->graphql(
            $store,
            $token,
            $version,
            'query ShopInfo {
                shop {
                    id name email myshopifyDomain
                    primaryDomain { host sslEnabled }
                    currencyCode timezoneAbbreviation ianaTimezone
                    shipsToCountries taxesIncluded
                }
            }'
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $shop = is_array($result) ? ( $result['data']['shop'] ?? null ) : null;

        if (! is_array($shop)) {
            return $this->errors->create('shopify_unexpected_response', 'Shopify returned no shop document.');
        }

        $domain = is_array($shop['primaryDomain'] ?? null) ? $shop['primaryDomain'] : array();

        $data = array(
            'id'                 => $this->text($shop['id'] ?? null),
            'name'               => $this->text($shop['name'] ?? null),
            'email'              => $this->text($shop['email'] ?? null),
            'myshopify_domain'   => $this->text($shop['myshopifyDomain'] ?? null),
            'primary_domain'     => $this->text($domain['host'] ?? null),
            'ssl_enabled'        => (bool) ( $domain['sslEnabled'] ?? false ),
            'currency'           => $this->text($shop['currencyCode'] ?? null),
            'timezone'           => $this->text($shop['ianaTimezone'] ?? null),
            'timezone_abbreviation' => $this->text($shop['timezoneAbbreviation'] ?? null),
            'ships_to_countries' => $this->stringListFrom($shop['shipsToCountries'] ?? null),
            'taxes_included'     => (bool) ( $shop['taxesIncluded'] ?? false ),
        );

        $data = $this->meta($data, $result);

        return $this->success('Retrieved the shop.', $data);
    }

    /**
     * An inventory level, projected.
     *
     * @param array<array-key, mixed> $level Raw inventory level node.
     *
     * @return array<string, mixed>
     */
    private function normalizeLevel(array $level): array
    {
        $quantities = array();

        foreach (is_array($level['quantities'] ?? null) ? $level['quantities'] : array() as $quantity) {
            if (is_array($quantity)) {
                $name = $this->text($quantity['name'] ?? null);

                if ('' !== $name) {
                    $quantities[$name] = is_numeric($quantity['quantity'] ?? null) ? (int) $quantity['quantity'] : 0;
                }
            }
        }

        $item    = is_array($level['item'] ?? null) ? $level['item'] : array();
        $variant = is_array($item['variant'] ?? null) ? $item['variant'] : array();
        $product = is_array($variant['product'] ?? null) ? $variant['product'] : array();

        return array(
            'id'         => $this->text($level['id'] ?? null),
            'quantities' => $quantities,
            'item_id'    => $this->text($item['id'] ?? null),
            'sku'        => $this->text($item['sku'] ?? null),
            'variant'    => array(
                'id'    => $this->text($variant['id'] ?? null),
                'title' => $this->text($variant['title'] ?? null),
            ),
            'product'    => array(
                'id'    => $this->text($product['id'] ?? null),
                'title' => $this->text($product['title'] ?? null),
            ),
        );
    }

    /**
     * Pagination for a connection on a raw node.
     *
     * @param array<array-key, mixed> $node       Raw node.
     * @param string                  $connection Connection field name.
     *
     * @return array{has_next_page: bool, next_cursor: string|null}
     */
    private function pageInfoOf(array $node, string $connection): array
    {
        $connection = is_array($node[$connection] ?? null) ? $node[$connection] : array();
        $info       = is_array($connection['pageInfo'] ?? null) ? $connection['pageInfo'] : array();
        $next       = $this->text($info['endCursor'] ?? null);

        return array(
            'has_next_page' => (bool) ( $info['hasNextPage'] ?? false ),
            'next_cursor'   => '' !== $next ? $next : null,
        );
    }

    /**
     * A scalar list from a provider field.
     *
     * @param mixed $value Raw list.
     *
     * @return list<string>
     */
    private function stringListFrom(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $list = array();

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }
}
