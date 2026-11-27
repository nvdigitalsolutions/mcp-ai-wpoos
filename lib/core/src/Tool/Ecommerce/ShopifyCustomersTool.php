<?php

/**
 * Shopify customers: list, search and get over the Admin GraphQL API.
 *
 * Proposal 164 section 164.113. Port of `WP_MCP_AI_Pro_Tool_Shopify_Customers`.
 * Read-only.
 *
 * ## The correction that matters: the deprecated fields
 *
 * The WordPress tool selected `email`, `phone` and `addresses`, and
 * `emailMarketingConsent`. In the 2026-10 schema:
 *
 * - `Customer.email` and `Customer.emailMarketingConsent` are **deprecated**;
 * - `Customer.phone` is **gone** — a GraphQL selection naming it fails the whole
 *   query, not just that field;
 * - `Customer.addresses` is deprecated in favour of `addressesV2`.
 *
 * The replacements are `defaultEmailAddress { emailAddress marketingState
 * marketingUpdatedAt validFormat }` and `defaultPhoneNumber { phoneNumber }`,
 * which is what this tool selects. Projecting them back to `email` / `phone` in
 * the *result* keeps the tool's output shape stable for a caller, while the
 * query uses only fields that will still exist next release.
 *
 * SMS consent is deliberately not projected: on `CustomerPhoneNumber` the
 * consent fields are themselves deprecated in this version, and a value that is
 * about to disappear is worse than an absent one.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

class ShopifyCustomersTool extends ShopifyTool
{
    /**
     * The fields every customer projection reads.
     */
    private const CUSTOMER_FIELDS = '
        id firstName lastName displayName createdAt updatedAt
        numberOfOrders amountSpent { amount currencyCode }
        verifiedEmail tags note
        defaultEmailAddress { emailAddress marketingState marketingUpdatedAt validFormat }
        defaultPhoneNumber { phoneNumber }
        defaultAddress { address1 address2 city province zip country }';

    public function getSlug(): string
    {
        return 'shopify_customers';
    }

    public function getName(): string
    {
        return 'Shopify Customers';
    }

    public function getDescription(): string
    {
        return 'List, search and retrieve customers on a Shopify store over the Admin GraphQL API. '
            . 'Each customer carries their email, phone, order count, lifetime spend, marketing state, tags and default address; '
            . 'a single customer also carries their address book and recent orders.';
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
                'customer_id'  => array(
                    'type'        => 'string',
                    'description' => 'Customer GID (gid://shopify/Customer/123) or bare numeric id, for get.',
                ),
                'first'        => array(
                    'type'        => 'integer',
                    'description' => 'Customers per page, 1-250. Defaults to 10.',
                    'minimum'     => 1,
                    'maximum'     => 250,
                ),
                'after'        => array(
                    'type'        => 'string',
                    'description' => 'next_cursor from a previous page.',
                ),
                'query'        => array(
                    'type'        => 'string',
                    'description' => 'Shopify search syntax, e.g. email:jane@example.com tag:vip total_spent:>100.',
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
     * One page of customers.
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
            'query Customers($first: Int!, $after: String, $query: String) {
                customers(first: $first, after: $after, query: $query) {
                    pageInfo { hasNextPage endCursor }
                    edges {
                        node {' . self::CUSTOMER_FIELDS . '}
                    }
                }
            }',
            $variables
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $customers = array();

        foreach ($this->edges($result, 'customers') as $node) {
            $customers[] = $this->normalizeCustomer($node, false);
        }

        $data = array(
            'customers' => $customers,
            'count'     => count($customers),
            'page_info' => $this->pageInfo($result, 'customers'),
        );

        $data = $this->meta($data, $result);

        return $this->success(
            sprintf('Found %d customer%s.', count($customers), 1 === count($customers) ? '' : 's'),
            $data
        );
    }

    /**
     * One customer, with their address book and recent orders.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleGet(string $store, string $token, string $version, array $arguments): mixed
    {
        $id = $this->gid($this->text($arguments['customer_id'] ?? null), 'Customer');

        if (null === $id) {
            return $this->errors->validationFailed(
                'A customer id is required for the get action.',
                array('customer_id' => array('Pass a GID or a numeric customer id.'))
            );
        }

        $result = $this->graphql(
            $store,
            $token,
            $version,
            'query Customer($id: ID!) {
                customer(id: $id) {
                    ' . self::CUSTOMER_FIELDS . '
                    addressesV2(first: 10) {
                        edges {
                            node { id address1 address2 city province zip country company name phone formatted }
                        }
                    }
                    orders(first: 10) {
                        edges {
                            node {
                                id name createdAt displayFinancialStatus displayFulfillmentStatus
                                totalPriceSet { shopMoney { amount currencyCode } }
                            }
                        }
                    }
                }
            }',
            array('id' => $id)
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $node = is_array($result) ? ( $result['data']['customer'] ?? null ) : null;

        if (! is_array($node)) {
            return $this->errors->notFound('No customer has that id.', array('customer_id' => $id));
        }

        $data = $this->meta(array('customer' => $this->normalizeCustomer($node, true)), $result);

        return $this->success('Retrieved the customer.', $data);
    }

    /**
     * A customer node, projected.
     *
     * @param array<array-key, mixed> $node   Raw GraphQL customer node.
     * @param bool                    $detail Whether to include the address book and orders.
     *
     * @return array<string, mixed>
     */
    private function normalizeCustomer(array $node, bool $detail): array
    {
        $email = is_array($node['defaultEmailAddress'] ?? null) ? $node['defaultEmailAddress'] : array();
        $phone = is_array($node['defaultPhoneNumber'] ?? null) ? $node['defaultPhoneNumber'] : array();

        $customer = array(
            'id'                  => $this->text($node['id'] ?? null),
            'first_name'          => $this->text($node['firstName'] ?? null),
            'last_name'           => $this->text($node['lastName'] ?? null),
            'display_name'        => $this->text($node['displayName'] ?? null),
            'email'               => $this->text($email['emailAddress'] ?? null),
            'email_valid'         => null === ( $email['validFormat'] ?? null ) ? null : (bool) $email['validFormat'],
            'marketing_state'     => $this->text($email['marketingState'] ?? null),
            'marketing_updated_at' => $this->text($email['marketingUpdatedAt'] ?? null),
            'phone'               => $this->text($phone['phoneNumber'] ?? null),
            'verified_email'      => (bool) ( $node['verifiedEmail'] ?? false ),
            'created_at'          => $this->text($node['createdAt'] ?? null),
            'updated_at'          => $this->text($node['updatedAt'] ?? null),
            'number_of_orders'    => is_numeric($node['numberOfOrders'] ?? null) ? (int) $node['numberOfOrders'] : 0,
            'amount_spent'        => $this->money($node['amountSpent'] ?? null),
            'tags'                => $this->stringList($node['tags'] ?? null),
            'note'                => $this->text($node['note'] ?? null),
            'default_address'     => $this->normalizeAddress($node['defaultAddress'] ?? null),
        );

        if (! $detail) {
            return $customer;
        }

        $customer['addresses'] = array();

        foreach ($this->edgesOf($node, 'addressesV2') as $address) {
            $customer['addresses'][] = array(
                'id'        => $this->text($address['id'] ?? null),
                'address1'  => $this->text($address['address1'] ?? null),
                'address2'  => $this->text($address['address2'] ?? null),
                'city'      => $this->text($address['city'] ?? null),
                'province'  => $this->text($address['province'] ?? null),
                'zip'       => $this->text($address['zip'] ?? null),
                'country'   => $this->text($address['country'] ?? null),
                'company'   => $this->text($address['company'] ?? null),
                'name'      => $this->text($address['name'] ?? null),
                'phone'     => $this->text($address['phone'] ?? null),
                'formatted' => $this->stringList($address['formatted'] ?? null),
            );
        }

        $customer['recent_orders'] = array();

        foreach ($this->edgesOf($node, 'orders') as $order) {
            $customer['recent_orders'][] = array(
                'id'                 => $this->text($order['id'] ?? null),
                'name'               => $this->text($order['name'] ?? null),
                'created_at'         => $this->text($order['createdAt'] ?? null),
                'financial_status'   => $this->text($order['displayFinancialStatus'] ?? null),
                'fulfillment_status' => $this->text($order['displayFulfillmentStatus'] ?? null),
                'total_price'        => $this->money($order['totalPriceSet'] ?? null),
            );
        }

        return $customer;
    }

    /**
     * A default address, projected, or null.
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
        );
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
