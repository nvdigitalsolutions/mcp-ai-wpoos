<?php

/**
 * Shopify orders: list, search and get over the Admin GraphQL API.
 *
 * Proposal 164 section 164.113. Port of `WP_MCP_AI_Pro_Tool_Shopify_Orders`.
 * Read-only: there is no refund, cancel or fulfilment action here, because the
 * WordPress tool had none either and the orders that need one are WooCommerce's,
 * not a remote store's.
 *
 * ## Corrections
 *
 * 1. **No `email` / `phone` on the order, and no `customer { email phone }`.**
 *    Those Customer fields are deprecated in the 2026-10 schema (`phone` is gone
 *    entirely, replaced by `defaultPhoneNumber`), so selecting them risks the
 *    whole query. The customer's email is read through
 *    `defaultEmailAddress { emailAddress }`.
 * 2. **Money is the provider's decimal string**, projected once by the base
 *    class: `{amount: "19.99", currency: "USD"}`.
 * 3. **`fulfillments`, `transactions` and `refunds` are lists, not connections.**
 *    The WordPress normaliser read them as plain arrays and that is right; the
 *    projection here keeps that and does not pretend they paginate.
 * 4. **`page_info` is projected**, so paging is one cursor rather than Shopify's
 *    four `pageInfo` fields.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

class ShopifyOrdersTool extends ShopifyTool
{
    /**
     * The fields every order projection reads.
     */
    private const ORDER_FIELDS = '
        id name createdAt updatedAt processedAt cancelledAt cancelReason
        displayFinancialStatus displayFulfillmentStatus
        totalPriceSet { shopMoney { amount currencyCode } }
        subtotalPriceSet { shopMoney { amount currencyCode } }
        totalShippingPriceSet { shopMoney { amount currencyCode } }
        totalTaxSet { shopMoney { amount currencyCode } }
        customer { id firstName lastName defaultEmailAddress { emailAddress } }
        shippingAddress { address1 address2 city province zip country firstName lastName company phone }
        tags note';

    public function getSlug(): string
    {
        return 'shopify_orders';
    }

    public function getName(): string
    {
        return 'Shopify Orders';
    }

    public function getDescription(): string
    {
        return 'List, search and retrieve orders on a Shopify store over the Admin GraphQL API. '
            . 'Each order carries its totals, customer, shipping address, line items and tags; a single order also carries '
            . 'billing address, fulfilments with tracking, transactions and refunds.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'store_url'    => array(
                    'type'        => 'string',
                    'description' => 'Store root, e.g. https://store.myshopify.com. No path.',
                    'minLength'   => 1,
                ),
                'access_token' => array(
                    'type'        => 'string',
                    'description' => 'Shopify Admin API access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'api_version'  => array(
                    'type'        => 'string',
                    'description' => 'Admin API version, e.g. 2026-10.',
                    'pattern'     => '^\\d{4}-(01|04|07|10)$',
                ),
                'action'       => array(
                    'type'        => 'string',
                    'description' => 'list (default), get, or search (list with a query).',
                    'enum'        => array('list', 'get', 'search'),
                ),
                'order_id'     => array(
                    'type'        => 'string',
                    'description' => 'Order GID (gid://shopify/Order/123) or bare numeric id, for get.',
                ),
                'first'        => array(
                    'type'        => 'integer',
                    'description' => 'Orders per page, 1-250. Defaults to 10.',
                    'minimum'     => 1,
                    'maximum'     => 250,
                ),
                'after'        => array(
                    'type'        => 'string',
                    'description' => 'next_cursor from a previous page.',
                ),
                'query'        => array(
                    'type'        => 'string',
                    'description' => 'Shopify search syntax, e.g. financial_status:paid fulfillment_status:unfulfilled.',
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

        switch ('' !== $action ? $action : 'list') {
            case 'list':
            case 'search':
                return $this->handleList((string) $store, (string) $token, (string) $version, $arguments);

            case 'get':
                return $this->handleGet((string) $store, (string) $token, (string) $version, $arguments);

            default:
                return $this->errors->validationFailed(
                    'Unknown action.',
                    array('action' => array('Expected list, get or search.'))
                );
        }
    }

    /**
     * One page of orders.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleList(string $store, string $token, string $version, array $arguments): mixed
    {
        $first     = $this->limit($arguments['first'] ?? null, 10);
        $after     = $this->cursor($arguments);
        $query     = $this->text($arguments['query'] ?? null);
        $variables = array('first' => $first);

        if ('' !== $after) {
            $variables['after'] = $after;
        }

        if ('' !== $query) {
            $variables['query'] = $query;
        }

        $result = $this->graphql(
            $store,
            $token,
            $version,
            'query Orders($first: Int!, $after: String, $query: String) {
                orders(first: $first, after: $after, query: $query) {
                    pageInfo { hasNextPage endCursor }
                    edges {
                        node {' . self::ORDER_FIELDS . '
                            lineItems(first: 20) {
                                edges {
                                    node {
                                        id title quantity sku
                                        originalUnitPriceSet { shopMoney { amount currencyCode } }
                                        variant { id sku }
                                    }
                                }
                            }
                            fulfillments(first: 5) { id status trackingInfo { company number url } }
                        }
                    }
                }
            }',
            $variables
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $orders = array();

        foreach ($this->edges($result, 'orders') as $node) {
            $orders[] = $this->normalizeOrder($node, false);
        }

        $data = array(
            'orders'    => $orders,
            'count'     => count($orders),
            'page_info' => $this->pageInfo($result, 'orders'),
        );

        $data = $this->meta($data, $result);

        return $this->success(
            sprintf('Found %d order%s.', count($orders), 1 === count($orders) ? '' : 's'),
            $data
        );
    }

    /**
     * One order, with payment and fulfilment detail.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleGet(string $store, string $token, string $version, array $arguments): mixed
    {
        $id = $this->gid($this->text($arguments['order_id'] ?? null), 'Order');

        if (null === $id) {
            return $this->errors->validationFailed(
                'An order id is required for the get action.',
                array('order_id' => array('Pass a GID or a numeric order id.'))
            );
        }

        $result = $this->graphql(
            $store,
            $token,
            $version,
            'query Order($id: ID!) {
                order(id: $id) {
                    ' . self::ORDER_FIELDS . '
                    totalRefundedSet { shopMoney { amount currencyCode } }
                    billingAddress { address1 address2 city province zip country firstName lastName company phone }
                    lineItems(first: 50) {
                        edges {
                            node {
                                id title quantity sku fulfillableQuantity fulfillmentStatus
                                originalUnitPriceSet { shopMoney { amount currencyCode } }
                                discountedUnitPriceSet { shopMoney { amount currencyCode } }
                                variant { id title sku barcode }
                            }
                        }
                    }
                    fulfillments(first: 10) {
                        id status createdAt updatedAt
                        trackingInfo { company number url }
                    }
                    transactions(first: 10) {
                        id kind status gateway createdAt
                        amountSet { shopMoney { amount currencyCode } }
                    }
                    refunds(first: 10) {
                        id createdAt
                        totalRefundedSet { shopMoney { amount currencyCode } }
                    }
                }
            }',
            array('id' => $id)
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $node = is_array($result) ? ( $result['data']['order'] ?? null ) : null;

        if (! is_array($node)) {
            return $this->errors->notFound('No order has that id.', array('order_id' => $id));
        }

        $data = $this->meta(array('order' => $this->normalizeOrder($node, true)), $result);

        return $this->success('Retrieved the order.', $data);
    }

    /**
     * An order node, projected.
     *
     * @param array<array-key, mixed> $node  Raw GraphQL order node.
     * @param bool                    $detail Whether this is the single-order shape.
     *
     * @return array<string, mixed>
     */
    private function normalizeOrder(array $node, bool $detail): array
    {
        $order = array(
            'id'                 => $this->text($node['id'] ?? null),
            'name'               => $this->text($node['name'] ?? null),
            'created_at'         => $this->text($node['createdAt'] ?? null),
            'updated_at'         => $this->text($node['updatedAt'] ?? null),
            'processed_at'       => $this->text($node['processedAt'] ?? null),
            'cancelled_at'       => $this->text($node['cancelledAt'] ?? null),
            'cancel_reason'      => $this->text($node['cancelReason'] ?? null),
            'financial_status'   => $this->text($node['displayFinancialStatus'] ?? null),
            'fulfillment_status' => $this->text($node['displayFulfillmentStatus'] ?? null),
            'total_price'        => $this->money($node['totalPriceSet'] ?? null),
            'subtotal_price'     => $this->money($node['subtotalPriceSet'] ?? null),
            'total_shipping'     => $this->money($node['totalShippingPriceSet'] ?? null),
            'total_tax'          => $this->money($node['totalTaxSet'] ?? null),
            'customer'           => $this->normalizeCustomer($node['customer'] ?? null),
            'shipping_address'   => $this->normalizeAddress($node['shippingAddress'] ?? null),
            'line_items'         => $this->normalizeLineItems($node, $detail),
            'fulfillments'       => $this->normalizeFulfillments($node),
            'tags'               => $this->stringList($node['tags'] ?? null),
            'note'               => $this->text($node['note'] ?? null),
        );

        if (! $detail) {
            return $order;
        }

        $order['billing_address'] = $this->normalizeAddress($node['billingAddress'] ?? null);
        $order['total_refunded']  = $this->money($node['totalRefundedSet'] ?? null);
        $order['transactions']    = $this->normalizeTransactions($node);
        $order['refunds']         = $this->normalizeRefunds($node);

        return $order;
    }

    /**
     * A customer reference on an order.
     *
     * @param mixed $customer Raw customer node.
     *
     * @return array{id: string, first_name: string, last_name: string, email: string}|null
     */
    private function normalizeCustomer(mixed $customer): ?array
    {
        if (! is_array($customer)) {
            return null;
        }

        $email = is_array($customer['defaultEmailAddress'] ?? null)
            ? $this->text($customer['defaultEmailAddress']['emailAddress'] ?? null)
            : '';

        return array(
            'id'         => $this->text($customer['id'] ?? null),
            'first_name' => $this->text($customer['firstName'] ?? null),
            'last_name'  => $this->text($customer['lastName'] ?? null),
            'email'      => $email,
        );
    }

    /**
     * A mailing address, projected, or null.
     *
     * @param mixed $address Raw address node.
     *
     * @return array<string, string>|null
     */
    private function normalizeAddress(mixed $address): ?array
    {
        if (! is_array($address)) {
            return null;
        }

        return array(
            'address1'   => $this->text($address['address1'] ?? null),
            'address2'   => $this->text($address['address2'] ?? null),
            'city'       => $this->text($address['city'] ?? null),
            'province'   => $this->text($address['province'] ?? null),
            'zip'        => $this->text($address['zip'] ?? null),
            'country'    => $this->text($address['country'] ?? null),
            'first_name' => $this->text($address['firstName'] ?? null),
            'last_name'  => $this->text($address['lastName'] ?? null),
            'company'    => $this->text($address['company'] ?? null),
            'phone'      => $this->text($address['phone'] ?? null),
        );
    }

    /**
     * The order's line items.
     *
     * @param array<array-key, mixed> $node   Raw order node.
     * @param bool                    $detail Whether to include the extra detail fields.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeLineItems(array $node, bool $detail): array
    {
        $edges = is_array($node['lineItems'] ?? null) ? ( $node['lineItems']['edges'] ?? null ) : null;
        $items = array();

        foreach (is_array($edges) ? $edges : array() as $edge) {
            $item = is_array($edge) && is_array($edge['node'] ?? null) ? $edge['node'] : null;

            if (null === $item) {
                continue;
            }

            $row = array(
                'id'         => $this->text($item['id'] ?? null),
                'title'      => $this->text($item['title'] ?? null),
                'quantity'   => is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0,
                'sku'        => $this->text($item['sku'] ?? null),
                'unit_price' => $this->money($item['originalUnitPriceSet'] ?? null),
            );

            if ($detail) {
                $row['fulfillable_quantity'] = is_numeric($item['fulfillableQuantity'] ?? null)
                    ? (int) $item['fulfillableQuantity']
                    : null;
                $row['fulfillment_status'] = $this->text($item['fulfillmentStatus'] ?? null);
                $row['discounted_unit_price'] = $this->money($item['discountedUnitPriceSet'] ?? null);
                $row['variant'] = is_array($item['variant'] ?? null)
                    ? array(
                        'id'      => $this->text($item['variant']['id'] ?? null),
                        'title'   => $this->text($item['variant']['title'] ?? null),
                        'sku'     => $this->text($item['variant']['sku'] ?? null),
                        'barcode' => $this->text($item['variant']['barcode'] ?? null),
                    )
                    : null;
            } else {
                $row['variant'] = is_array($item['variant'] ?? null)
                    ? array(
                        'id'  => $this->text($item['variant']['id'] ?? null),
                        'sku' => $this->text($item['variant']['sku'] ?? null),
                    )
                    : null;
            }

            $items[] = $row;
        }

        return $items;
    }

    /**
     * The order's fulfilments, with tracking.
     *
     * `Order.fulfillments` is a plain list, so this does not look for `edges`.
     *
     * @param array<array-key, mixed> $node Raw order node.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeFulfillments(array $node): array
    {
        $fulfillments = is_array($node['fulfillments'] ?? null) ? $node['fulfillments'] : array();
        $rows         = array();

        foreach ($fulfillments as $fulfillment) {
            if (! is_array($fulfillment)) {
                continue;
            }

            $tracking = array();

            foreach (is_array($fulfillment['trackingInfo'] ?? null) ? $fulfillment['trackingInfo'] : array() as $info) {
                if (! is_array($info)) {
                    continue;
                }

                $tracking[] = array(
                    'company' => $this->text($info['company'] ?? null),
                    'number'  => $this->text($info['number'] ?? null),
                    'url'     => $this->text($info['url'] ?? null),
                );
            }

            $rows[] = array(
                'id'             => $this->text($fulfillment['id'] ?? null),
                'status'         => $this->text($fulfillment['status'] ?? null),
                'created_at'     => $this->text($fulfillment['createdAt'] ?? null),
                'updated_at'     => $this->text($fulfillment['updatedAt'] ?? null),
                'tracking'       => $tracking,
            );
        }

        return $rows;
    }

    /**
     * The order's payment transactions.
     *
     * @param array<array-key, mixed> $node Raw order node.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeTransactions(array $node): array
    {
        $transactions = is_array($node['transactions'] ?? null) ? $node['transactions'] : array();
        $rows         = array();

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $rows[] = array(
                'id'         => $this->text($transaction['id'] ?? null),
                'kind'       => $this->text($transaction['kind'] ?? null),
                'status'     => $this->text($transaction['status'] ?? null),
                'amount'     => $this->money($transaction['amountSet'] ?? null),
                'gateway'    => $this->text($transaction['gateway'] ?? null),
                'created_at' => $this->text($transaction['createdAt'] ?? null),
            );
        }

        return $rows;
    }

    /**
     * The order's refunds.
     *
     * @param array<array-key, mixed> $node Raw order node.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeRefunds(array $node): array
    {
        $refunds = is_array($node['refunds'] ?? null) ? $node['refunds'] : array();
        $rows    = array();

        foreach ($refunds as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $rows[] = array(
                'id'         => $this->text($refund['id'] ?? null),
                'created_at' => $this->text($refund['createdAt'] ?? null),
                'total'      => $this->money($refund['totalRefundedSet'] ?? null),
            );
        }

        return $rows;
    }

    /**
     * A scalar list from a provider field.
     *
     * @param mixed $value Raw list.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
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
