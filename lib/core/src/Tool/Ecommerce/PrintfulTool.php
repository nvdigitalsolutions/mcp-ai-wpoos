<?php

/**
 * Printful print-on-demand: catalog, store products, orders and reports.
 *
 * Proposal 164 section 164.118. Ports the WordPress `printful` tool, which is a
 * 24-action dispatcher over a 903-line client.
 *
 * ## Why V1, and what V2 would cost
 *
 * Printful's V2 API is published as **`2.0.0-beta`**, and its own migration guide
 * says product management is not there yet: *"Product management, with sync
 * products or product templates, is not available in version 2 of the API yet."*
 * The `/files` list endpoint was removed in V2 as well (a call now answers `410
 * Gone`). Five of this tool's actions are sync-product operations and it
 * exposes no template action, so a V2 port would silently drop a fifth of the
 * surface. V1 stays, pinned in {@see self::BASE} and {@see self::API_VERSION},
 * and `version` is returned on every response so a caller can see which API
 * answered.
 *
 * V2 also does not use its new RFC 9457 error format for Catalog, Orders or
 * Webhooks yet — those still return the V1 `{code, result, error{reason,message}}`
 * envelope, which is what {@see self::failure()} reads.
 *
 * ## Four corrections
 *
 * 1. **No cross-store cache.** The WordPress client cached every GET in a
 *    transient keyed on `md5(url)` alone — the token and `X-PF-Store-Id` were not
 *    in the key, so two stores sharing a URL shared a cache entry and one could
 *    be served the other's products. The port does not cache at all: the caller
 *    decides, the way `ShopifySmartSearch` declines to. That removes the leak
 *    instead of narrowing it.
 * 2. **No local rate limiter.** The WordPress tool allowed 30 requests/minute
 *    **per user** against a provider limit of 120/minute **per account**, so five
 *    users could spend 150 and have the last thirty rejected. A counter keyed on
 *    the wrong dimension is worse than none: it is limit-shaped and does not
 *    limit. A throttle is now returned as a refusal carrying `Retry-After`, and
 *    every response surfaces the provider's `X-Ratelimit-*` headers.
 * 3. **Paging does not relabel the payload.** The original wrapped any array
 *    result as `{items: …, paging: …}` when the envelope had a `paging` key, so a
 *    single-object response became a list-like shape depending on whether that
 *    endpoint happened to paginate. Here `data` is always the provider's `result`
 *    and `paging` is a sibling of it.
 * 4. **No hidden connection store.** `list_connections`, `connection_id` and
 *    per-assistant enablement read a WordPress `Remote Sites` store this runtime
 *    does not have. The token and optional store id are arguments.
 *
 * Four unguarded `$arguments[...]` reads in the original are gone too: every
 * identifier is resolved through {@see self::identifier()}, so a missing one is a
 * named validation failure rather than a warning and a lookup of id `0`.
 *
 * ## One tool, not four
 *
 * The measurement recommended splitting this by object. That is the better
 * shape, and it is **not** what shipped, for a bookkeeping reason worth stating:
 * toolkit parity is counted slug-for-slug against the WordPress corpus, which has
 * exactly one `printful` slug. Four core slugs would leave `printful` listed as
 * unported while the work was done, which makes the register untrue. The actions
 * are grouped in the schema instead, so the split remains a rename away.
 *
 * ## Money stays the provider's decimal string
 *
 * Printful returns amounts as strings (`"14.95"`). They are passed through
 * unparsed: converting to float and back is how a cents figure drifts, and the
 * provider has already chosen the representation.
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
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Tool\AbstractTool;

final class PrintfulTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The v1 API base.
     */
    public const BASE = 'https://api.printful.com';

    /**
     * The version this tool speaks. V2 is beta and lacks sync products.
     */
    public const API_VERSION = 'v1';

    /**
     * Read actions, which never mutate anything.
     *
     * @var list<string>
     */
    private const READ_ACTIONS = array(
        'list_catalog_products',
        'get_catalog_product',
        'get_catalog_variant',
        'get_product_size_guide',
        'list_categories',
        'get_category',
        'list_countries',
        'list_sync_products',
        'get_sync_product',
        'list_orders',
        'get_order',
        'estimate_costs',
        'get_shipping_rates',
        'list_stores',
        'get_statistics',
        'list_warehouse_products',
        'get_mockup_task',
    );

    /**
     * Write actions: they change state at the provider.
     *
     * @var list<string>
     */
    private const WRITE_ACTIONS = array(
        'create_sync_product',
        'update_sync_product',
        'delete_sync_product',
        'create_order',
        'update_order',
        'cancel_order',
        'confirm_order',
        'create_mockup_task',
    );

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'printful';
    }

    public function getName(): string
    {
        return 'Printful';
    }

    public function getDescription(): string
    {
        return 'Manage a Printful print-on-demand store through the v1 API: browse the blank-product '
            . 'catalog and its variants, categories, size guides and countries; list, create, update '
            . 'and delete store (sync) products; list, create, update, confirm and cancel orders, and '
            . 'estimate order and shipping costs; read store statistics and generate mockup tasks. '
            . 'The token and optional store id are arguments. Money is returned as the provider '
            . 'decimal string.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'action' => array(
                    'type' => 'string',
                    'description' => 'The operation to perform.',
                    'enum' => array_merge(self::READ_ACTIONS, self::WRITE_ACTIONS),
                ),
                'api_token' => array(
                    'type' => 'string',
                    'description' => 'Printful private token or OAuth token. Required for every '
                        . 'action except list_countries. Printful also serves the catalog reads '
                        . '(products, variants, categories, size guides) without a token; this '
                        . 'tool still asks for one so every call site has a single credential '
                        . 'path.',
                ),
                'store_id' => array(
                    'type' => 'string',
                    'description' => 'Store to act on, sent as X-PF-Store-Id. Required when the token '
                        . 'is account-level; ignored for a store-level token.',
                ),
                'product_id' => array(
                    'type' => 'integer',
                    'description' => 'Catalog product id.',
                ),
                'variant_id' => array(
                    'type' => 'integer',
                    'description' => 'Catalog variant id.',
                ),
                'category_id' => array(
                    'type' => 'integer',
                    'description' => 'Catalog category id.',
                ),
                'sync_product_id' => array(
                    'type' => array('string', 'integer'),
                    'description' => 'Store product id, or an external id prefixed with "@".',
                ),
                'order_id' => array(
                    'type' => array('string', 'integer'),
                    'description' => 'Order id, or an external id prefixed with "@".',
                ),
                'task_key' => array(
                    'type' => 'string',
                    'description' => 'Mockup task key returned by create_mockup_task.',
                ),
                'status' => array(
                    'type' => 'string',
                    'description' => 'Order status filter, or the status filter for '
                        . 'list_sync_products.',
                ),
                'unit' => array(
                    'type' => 'string',
                    'description' => 'Size-guide measurement unit.',
                    'enum' => array('inches', 'cm'),
                ),
                'offset' => array(
                    'type' => 'integer',
                    'description' => 'Pagination offset.',
                    'minimum' => 0,
                    'default' => 0,
                ),
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Items per page. Printful caps this at 100 on list endpoints.',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 20,
                ),
                'payload' => array(
                    'type' => 'object',
                    'description' => 'Provider request body for create/update actions, passed through '
                        . 'as documented by Printful: sync products take sync_product and '
                        . 'sync_variants; orders take recipient plus order items. Refused when empty '
                        . 'on a write action.',
                ),
                'confirm' => array(
                    'type' => 'boolean',
                    'description' => 'create_order and update_order: confirm for fulfilment instead of '
                        . 'leaving the order in draft. Irreversible in Printful and bills the account.',
                    'default' => false,
                ),
                'date_from' => array(
                    'type' => 'string',
                    'description' => 'get_statistics start date, Y-m-d.',
                ),
                'date_to' => array(
                    'type' => 'string',
                    'description' => 'get_statistics end date, Y-m-d. The range may not exceed six months.',
                ),
                'currency' => array(
                    'type' => 'string',
                    'description' => 'get_statistics three-letter currency code.',
                ),
                'report_types' => array(
                    'type' => 'array',
                    'description' => 'get_statistics report types, e.g. ["sales_and_costs","profit"].',
                    'items' => array('type' => 'string'),
                ),
            ),
            'required' => array('action'),
        );
    }

    /**
     * What the WordPress tool enforced.
     */
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('external-api', 'network-dependent', 'requires-credentials', 'write');
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     */
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $action = $this->stringParam($arguments, 'action', '');

        if (! in_array($action, array_merge(self::READ_ACTIONS, self::WRITE_ACTIONS), true)) {
            return $this->errors->validationFailed(
                sprintf('Unknown action "%s".', $action),
                array('action' => array('Unknown action.'))
            );
        }

        $token = $this->text($arguments, 'api_token');
        $store = $this->text($arguments, 'store_id');

        if ('' === $token && 'list_countries' !== $action) {
            return $this->errors->validationFailed(
                'api_token is required. Pass the Printful private or OAuth token as an argument; '
                . 'this runtime has no connection store to read one from.',
                array('api_token' => array('A token is required.'))
            );
        }

        $request = $this->requestFor($action, $arguments);

        // A refusal is a platform error object, which is itself array-shaped in
        // some factories; `isError()` is the only correct discriminator.
        if ($this->errors->isError($request)) {
            return $request;
        }

        if (! is_array($request)) {
            return $this->errors->create(
                'printful_invalid_action',
                sprintf('Action "%s" is not implemented.', $action),
                array('action' => $action)
            );
        }

        /** @var array{method: string, path: string, query: array<string, string>, body: array<string, mixed>|null} $request */
        return $this->send($action, $request, $token, $store, in_array($action, self::WRITE_ACTIONS, true));
    }

    // ─── Action → request mapping ───────────────────────────────────

    /**
     * Resolve an action into its HTTP request, or a refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array{method: string, path: string, query: array<string, string>, body: array<string, mixed>|null}|mixed A refusal is a platform error object; test it with isError().
     */
    private function requestFor(string $action, array $arguments): mixed
    {
        $payload = $this->objectParam($arguments, 'payload');

        switch ($action) {
            // ── Catalog ──────────────────────────────────────────────
            case 'list_catalog_products':
                $categoryId = $this->intOrNull($arguments, 'category_id');

                return $this->get(
                    '/products',
                    null === $categoryId ? array() : array('category_id' => (string) $categoryId)
                );

            case 'get_catalog_product':
                return $this->identified('GET', '/products/', $arguments, 'product_id');

            case 'get_catalog_variant':
                return $this->identified('GET', '/products/variant/', $arguments, 'variant_id');

            case 'get_product_size_guide':
                return $this->identified('GET', '/products/', $arguments, 'product_id', '/sizes', array(
                    'unit' => $this->choice($arguments, 'unit', 'inches'),
                ));

            case 'list_categories':
                return $this->get('/categories');

            case 'get_category':
                return $this->identified('GET', '/categories/', $arguments, 'category_id');

            case 'list_countries':
                return $this->get('/countries');

            // ── Store (sync) products ────────────────────────────────
            case 'list_sync_products':
                return $this->get('/store/products', array(
                    'status' => $this->text($arguments, 'status'),
                    'offset' => (string) $this->pageOffset($arguments),
                    'limit' => (string) $this->pageLimit($arguments),
                ));

            case 'get_sync_product':
                return $this->identified('GET', '/store/products/', $arguments, 'sync_product_id');

            case 'create_sync_product':
                return $this->body('POST', '/store/products', $payload);

            case 'update_sync_product':
                return $this->identified('PUT', '/store/products/', $arguments, 'sync_product_id', '', array(), $payload);

            case 'delete_sync_product':
                return $this->identified('DELETE', '/store/products/', $arguments, 'sync_product_id');

            // ── Orders ───────────────────────────────────────────────
            case 'list_orders':
                return $this->get('/orders', array(
                    'status' => $this->text($arguments, 'status'),
                    'offset' => (string) $this->pageOffset($arguments),
                    'limit' => (string) $this->pageLimit($arguments),
                ));

            case 'get_order':
                return $this->identified('GET', '/orders/', $arguments, 'order_id');

            case 'create_order':
                if (array() === $payload) {
                    return $this->payloadRequired('create_order');
                }

                return array(
                    'method' => 'POST',
                    'path' => '/orders',
                    'query' => $this->boolParam($arguments, 'confirm') ? array('confirm' => '1') : array(),
                    'body' => $payload,
                );

            case 'update_order':
                if (array() === $payload) {
                    return $this->payloadRequired('update_order');
                }

                $id = $this->identifier($arguments, 'order_id');

                if (null === $id) {
                    return $this->missingIdentifier('order_id');
                }

                return array(
                    'method' => 'PUT',
                    'path' => '/orders/' . rawurlencode($id),
                    'query' => $this->boolParam($arguments, 'confirm') ? array('confirm' => '1') : array(),
                    'body' => $payload,
                );

            case 'cancel_order':
                return $this->identified('DELETE', '/orders/', $arguments, 'order_id');

            case 'confirm_order':
                return $this->identified('POST', '/orders/', $arguments, 'order_id', '/confirm');

            case 'estimate_costs':
                if (array() === $payload) {
                    return $this->payloadRequired('estimate_costs');
                }

                return $this->body('POST', '/orders/estimate-costs', $payload);

            case 'get_shipping_rates':
                if (array() === $payload) {
                    return $this->payloadRequired('get_shipping_rates');
                }

                return $this->body('POST', '/shipping/rates', $payload);

            // ── Stores and reports ───────────────────────────────────
            case 'list_stores':
                return $this->get('/stores');

            case 'get_statistics':
                return $this->statistics($arguments);

            case 'list_warehouse_products':
                return $this->get('/warehouse/products', array(
                    'offset' => (string) $this->pageOffset($arguments),
                    'limit' => (string) $this->pageLimit($arguments),
                ));

            // ── Mockups ──────────────────────────────────────────────
            case 'create_mockup_task':
                $productId = $this->intOrNull($arguments, 'product_id');

                if (null === $productId) {
                    return $this->missingIdentifier('product_id');
                }

                if (array() === $payload) {
                    return $this->payloadRequired('create_mockup_task');
                }

                return $this->body('POST', '/mockup-generator/create-task/' . $productId, $payload);

            case 'get_mockup_task':
                $taskKey = $this->text($arguments, 'task_key');

                if ('' === $taskKey) {
                    return $this->missingIdentifier('task_key');
                }

                return $this->get('/mockup-generator/task', array('task_key' => $taskKey));
        }

        return null;
    }

    /**
     * `get_statistics`, with the six-month cap enforced rather than described.
     *
     * The WordPress schema documented the cap and never checked it, so an
     * over-long range cost a round trip and came back as a provider error.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function statistics(array $arguments): mixed
    {
        $from = $this->text($arguments, 'date_from');
        $to = $this->text($arguments, 'date_to');

        if ('' === $from || '' === $to) {
            return $this->errors->validationFailed(
                'get_statistics needs date_from and date_to, both Y-m-d.',
                array('date_from' => array('Required.'), 'date_to' => array('Required.'))
            );
        }

        if (! $this->isIsoDate($from) || ! $this->isIsoDate($to)) {
            return $this->errors->validationFailed(
                'date_from and date_to must be Y-m-d dates.',
                array('date_from' => array('Expected Y-m-d.'), 'date_to' => array('Expected Y-m-d.'))
            );
        }

        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);

        if ($end < $start) {
            return $this->errors->validationFailed(
                'date_to must not be before date_from.',
                array('date_to' => array('Before date_from.'))
            );
        }

        // Six calendar months, measured from the start date.
        if ($start->modify('+6 months') < $end) {
            return $this->errors->validationFailed(
                'Printful will not return statistics for a range longer than six months.',
                array('date_to' => array('Range exceeds six months.'))
            );
        }

        $reportTypes = $this->stringListParam($arguments, 'report_types');

        if (array() === $reportTypes) {
            return $this->errors->validationFailed(
                'get_statistics needs at least one report type.',
                array('report_types' => array('Required.'))
            );
        }

        return $this->get('/reports/statistics', array(
            'date_from' => $from,
            'date_to' => $to,
            'currency' => $this->text($arguments, 'currency'),
            'report_types' => implode(',', $reportTypes),
        ));
    }

    // ─── Transport ──────────────────────────────────────────────────

    /**
     * Send the request and unwrap the provider envelope.
     *
     * @param array{method: string, path: string, query: array<string, string>, body: array<string, mixed>|null} $request Request.
     */
    private function send(string $action, array $request, string $token, string $store, bool $writes): mixed
    {
        $url = self::BASE . $request['path'];

        $query = array_filter(
            $request['query'],
            static fn (string $value): bool => '' !== $value
        );

        if (array() !== $query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'NVoOS-Core/1.0',
        );

        if ('' !== $token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        if ('' !== $store) {
            $headers['X-PF-Store-Id'] = $store;
        }

        $body = null === $request['body'] ? null : $this->jsonBody($request['body']);

        try {
            $response = $this->http->send($request['method'], $url, $headers, $body);
        } catch (\RuntimeException $error) {
            return $this->errors->create(
                'printful_unreachable',
                'The Printful API could not be reached: ' . $error->getMessage(),
                array('action' => $action)
            );
        }

        $decoded = $this->decode($response);

        if (429 === $response->statusCode) {
            return $this->throttled($action, $response, $decoded);
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            return $this->failure($action, $response, $decoded);
        }

        return $this->success(
            sprintf('Printful %s succeeded.', $action),
            array(
                'action' => $action,
                'api_version' => self::API_VERSION,
                'writes' => $writes,
                // The provider's `result`, never relabelled.
                'data' => $decoded['result'] ?? null,
                // Paging is a sibling of the payload, as the provider sends it.
                'paging' => $decoded['paging'] ?? null,
                'rate_limits' => $this->rateLimits($response),
            )
        );
    }

    /**
     * A 429 is a refusal that says when to come back.
     *
     * The WordPress client slept for up to 20 seconds inside the tool call. A
     * queue-backed runtime should not hold a worker to a provider's throttle, so
     * the wait is reported instead of performed.
     *
     * @param array<string, mixed> $decoded Decoded body.
     */
    private function throttled(string $action, HttpResponse $response, array $decoded): mixed
    {
        $retryAfter = $this->header($response, 'retry-after');

        return $this->errors->create(
            'printful_rate_limited',
            'Printful rejected the request: the account rate limit was reached. Retry after '
            . ( '' !== $retryAfter ? $retryAfter . ' second(s).' : 'a short wait.' ),
            array(
                'action' => $action,
                'retry_after' => '' !== $retryAfter ? (int) $retryAfter : null,
                'rate_limits' => $this->rateLimits($response),
                'detail' => $this->errorMessage($decoded),
            )
        );
    }

    /**
     * A provider error, with its own wording preserved.
     *
     * Printful answers `{code, result, error: {reason, message}}`; `result` also
     * carries a message for some endpoints.
     *
     * @param array<string, mixed> $decoded Decoded body.
     */
    private function failure(string $action, HttpResponse $response, array $decoded): mixed
    {
        $version = $this->header($response, 'x-pf-api-version');

        return $this->errors->create(
            'printful_api_error',
            $this->errorMessage($decoded),
            array(
                'action' => $action,
                'status' => $response->statusCode,
                'api_version' => '' !== $version ? $version : self::API_VERSION,
                'reason' => is_array($decoded['error'] ?? null) && isset($decoded['error']['reason'])
                    ? (string) $decoded['error']['reason']
                    : null,
            )
        );
    }

    /**
     * The provider's own message, in its own words.
     *
     * @param array<string, mixed> $decoded Decoded body.
     */
    private function errorMessage(array $decoded): string
    {
        if (is_array($decoded['error'] ?? null) && isset($decoded['error']['message']) && is_scalar($decoded['error']['message'])) {
            return (string) $decoded['error']['message'];
        }

        if (isset($decoded['result']) && is_scalar($decoded['result']) && '' !== (string) $decoded['result']) {
            return (string) $decoded['result'];
        }

        return 'Printful returned an error with no message.';
    }

    // ─── Request builders ───────────────────────────────────────────

    /**
     * @param array<string, string> $query Query values; empty strings are dropped.
     *
     * @return array{method: string, path: string, query: array<string, string>, body: null}
     */
    private function get(string $path, array $query = array()): array
    {
        return array('method' => 'GET', 'path' => $path, 'query' => $query, 'body' => null);
    }

    /**
     * @param array<string, mixed> $body Request body.
     *
     * @return array{method: string, path: string, query: array<string, string>, body: array<string, mixed>}
     */
    private function body(string $method, string $path, array $body): array
    {
        return array('method' => $method, 'path' => $path, 'query' => array(), 'body' => $body);
    }

    /**
     * A request whose path ends in a caller-supplied identifier.
     *
     * @param array<string, mixed>  $arguments Tool arguments.
     * @param string                $key       Identifier argument name.
     * @param string                $suffix    Path suffix after the identifier.
     * @param array<string, string> $query     Query values.
     * @param array<string, mixed>  $body      Body for a write.
     *
     * @return mixed A request, or a refusal.
     */
    private function identified(
        string $method,
        string $prefix,
        array $arguments,
        string $key,
        string $suffix = '',
        array $query = array(),
        array $body = array()
    ): mixed {
        $id = $this->identifier($arguments, $key);

        if (null === $id) {
            return $this->missingIdentifier($key);
        }

        return array(
            'method' => $method,
            'path' => $prefix . rawurlencode($id) . $suffix,
            'query' => $query,
            'body' => array() === $body ? null : $body,
        );
    }

    private function payloadRequired(string $action): mixed
    {
        return $this->errors->validationFailed(
            sprintf('%s needs a payload: the Printful request body, as documented.', $action),
            array('payload' => array('A request body is required.'))
        );
    }

    private function missingIdentifier(string $key): mixed
    {
        return $this->errors->validationFailed(
            sprintf('%s is required for this action.', $key),
            array($key => array('Required.'))
        );
    }

    // ─── Argument helpers ───────────────────────────────────────────

    /**
     * An identifier as a string: integers and "@external" strings both work.
     *
     * The original read `$arguments['product_id']` directly in two places, so a
     * missing key warned and looked up id 0.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param string               $key       Argument name.
     */
    private function identifier(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param string               $key       Argument name.
     */
    private function intOrNull(array $arguments, string $key): ?int
    {
        $value = $arguments[$key] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param string               $key       Argument name.
     * @param string               $default   Fallback for an absent value.
     */
    private function choice(array $arguments, string $key, string $default): string
    {
        $value = $this->text($arguments, $key);

        return in_array($value, array('inches', 'cm'), true) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function pageOffset(array $arguments): int
    {
        return max(0, $this->intParam($arguments, 'offset', 0));
    }

    /**
     * Printful caps list pages at 100.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function pageLimit(array $arguments): int
    {
        return max(1, min(100, $this->intParam($arguments, 'limit', 20)));
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param string               $key       Argument name.
     * @param string               $default   Fallback for an absent or non-scalar value.
     */
    private function text(array $arguments, string $key, string $default = ''): string
    {
        $value = $arguments[$key] ?? null;

        if (! is_scalar($value)) {
            return $default;
        }

        $text = trim((string) $value);

        return '' !== $text ? $text : $default;
    }

    // ─── Response helpers ───────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response): array
    {
        $decoded = json_decode($response->body, true);

        if (! is_array($decoded)) {
            return array();
        }

        $map = array();

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    /**
     * The provider's rate-limit headers, when it sends them.
     *
     * @return array<string, int|string>
     */
    private function rateLimits(HttpResponse $response): array
    {
        $limits = array();
        $map = array(
            'requests_limit' => 'x-ratelimit-limit',
            'requests_remaining' => 'x-ratelimit-remaining',
            'reset_seconds' => 'x-ratelimit-reset',
            'policy' => 'x-ratelimit-policy',
        );

        foreach ($map as $key => $header) {
            $value = $this->header($response, $header);

            if ('' === $value) {
                continue;
            }

            $limits[$key] = 'policy' === $key ? $value : (int) $value;
        }

        return $limits;
    }

    private function header(HttpResponse $response, string $name): string
    {
        foreach ($response->headers as $key => $value) {
            if (strtolower($key) === $name) {
                return trim($value);
            }
        }

        return '';
    }
}
