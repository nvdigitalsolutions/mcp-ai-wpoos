<?php

/**
 * Shared ground for the Shopify Admin GraphQL tools.
 *
 * Proposal 164 section 164.113. Four tools (`shopify_products`,
 * `shopify_orders`, `shopify_customers`, `shopify_inventory`) sit on one
 * transport, one credential model and one error vocabulary. This class is that
 * common ground, and it carries four corrections that each tool would otherwise
 * have to repeat — or, as the WordPress originals show, fail to.
 *
 * ## 1. The API version
 *
 * Shopify releases quarterly and supports a version for a minimum of twelve
 * months. The WordPress client pinned `2025-01`, which Shopify stopped serving on
 * **2026-04-16**; a request to a retired version does not fail, it *falls
 * forward* to the oldest accessible stable version, so the tool appeared to work
 * while running against a schema nobody had read. The default here is
 * **2026-10**, the latest stable at the time of writing (served until
 * 2026-10-16 2027), and it is overridable per call for an operator who needs to
 * pin. The response's `X-Shopify-API-Version` header is surfaced when it differs
 * from the requested version, so a silent fall-forward is visible rather than
 * invisible.
 *
 * ## 2. GraphQL errors are classified once
 *
 * Shopify reports a bad query, a throttle or an unknown field as **HTTP 200** with
 * a non-empty `errors[]`. The WordPress client checked the status and the JSON and
 * never `errors[]`; each tool then re-checked in its own way (nine checks in the
 * products tool, eight in inventory, four each in orders and customers, all
 * slightly different). Here a GraphQL error becomes `shopify_graphql_error` in one
 * place, with the provider's own message and extension code.
 *
 * ## 3. `userErrors` is a refusal, not a success
 *
 * A mutation that "succeeds" with `userErrors` has written nothing. Each tool
 * checks its own payload path through {@see userError()}, which returns the
 * canonical error or null.
 *
 * ## 4. Money is a decimal string
 *
 * Shopify returns `{ amount: "19.99", currencyCode: "USD" }`; the projection keeps
 * the amount as the provider's string. Converting to a float loses cents and is
 * how a catalogue drifts. {@see money()} does that projection once.
 *
 * The credential and the store URL are **arguments**, the way the chat-channel
 * tools take theirs: this family has no WordPress `Remote Sites` store to read
 * from, and a framework-agnostic core cannot acquire one. A caller-supplied URL is
 * therefore an SSRF surface, so {@see storeUrl()} is the one guard they share.
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

abstract class ShopifyTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Latest stable version at 2026-11-27; Shopify serves it until 2027-10-16.
     */
    public const DEFAULT_API_VERSION = '2026-10';

    /**
     * Shopify's documented page ceiling for every connection this family reads.
     */
    protected const MAX_PAGE_SIZE = 250;

    /**
     * A response beyond this is refused rather than parsed.
     */
    private const MAX_BODY_BYTES = 5242880;

    /**
     * Shopify's quarterly naming: a year and 01, 04, 07 or 10.
     */
    private const API_VERSION_PATTERN = '/^\d{4}-(?:01|04|07|10)$/';

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly HttpClientInterface $http,
    ) {
        parent::__construct($e);
    }

    /**
     * What the WordPress tools enforced.
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
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'read-only',
        );
    }

    /**
     * The store URL, or the canonical refusal.
     *
     * Accepts `https://store.myshopify.com` or a custom domain, with or without a
     * trailing slash. Refuses a non-https scheme, userinfo, a path (the endpoint is
     * appended, so a path means the caller is confused about the shape), a literal
     * private/loopback/reserved address, and a local hostname. The request carries
     * an Admin API token, so an unchecked host is a way to hand that token to
     * whatever address the caller named.
     *
     * A public hostname that resolves to a private address (DNS rebinding) is not
     * caught here; the HTTP seam does not expose the resolved address, so that
     * defence belongs to the host. The documented limit is deliberate.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function storeUrl(array $arguments): mixed
    {
        $value = $this->text($arguments['store_url'] ?? null);
        $parts = '' !== $value ? parse_url($value) : false;

        if (! is_array($parts)) {
            return $this->refusedStoreUrl('A store URL is required, e.g. https://store.myshopify.com.');
        }

        $scheme = strtolower((string) ( $parts['scheme'] ?? '' ));
        $host   = strtolower(trim((string) ( $parts['host'] ?? '' ), '[]'));
        $user   = (string) ( $parts['user'] ?? '' );
        $path   = (string) ( $parts['path'] ?? '' );

        if ('https' !== $scheme || '' === $host || '' !== $user) {
            return $this->refusedStoreUrl('A store URL must be an absolute public https URL.');
        }

        if ('' !== trim($path, '/')) {
            return $this->refusedStoreUrl('A store URL must not carry a path; pass the store root only.');
        }

        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            $public = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if (false === $public) {
                return $this->refusedStoreUrl('A store URL must be a public host.');
            }
        }

        foreach (array('.localhost', '.local', '.internal', '.localdomain') as $suffix) {
            if ('localhost' === $host || str_ends_with($host, $suffix)) {
                return $this->refusedStoreUrl('A store URL must be a public host.');
            }
        }

        return $scheme . '://' . $host . ( isset($parts['port']) ? ':' . (int) $parts['port'] : '' );
    }

    /**
     * The Admin API access token, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function token(array $arguments): mixed
    {
        $token = $this->text($arguments['access_token'] ?? null);

        if ('' === $token) {
            return $this->errors->validationFailed(
                'A Shopify Admin API access token is required.',
                array('access_token' => array('A valid access token is required.'))
            );
        }

        return $token;
    }

    /**
     * The API version to call, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function apiVersion(array $arguments): mixed
    {
        $version = $this->text($arguments['api_version'] ?? null);

        if ('' === $version) {
            return self::DEFAULT_API_VERSION;
        }

        if (1 !== preg_match(self::API_VERSION_PATTERN, $version)) {
            return $this->errors->validationFailed(
                'An API version is a year and a quarter, e.g. 2026-10.',
                array('api_version' => array('Expected YYYY-01, YYYY-04, YYYY-07 or YYYY-10.'))
            );
        }

        return $version;
    }

    /**
     * One GraphQL call, classified.
     *
     * @param array<string, mixed> $variables GraphQL variables.
     *
     * @return array{
     *     data: array<array-key, mixed>,
     *     throttle: array<string, int>|null,
     *     served_version: string,
     *     version_fallback: array{requested: string, served: string}|null
     * }|mixed The document, or the canonical error.
     */
    protected function graphql(
        string $storeUrl,
        string $token,
        string $version,
        string $query,
        array $variables = array(),
    ): mixed {
        $payload = array('query' => $query);

        if (array() !== $variables) {
            $payload['variables'] = $variables;
        }

        try {
            $body = $this->jsonBody($payload);
        } catch (\RuntimeException $error) {
            return $this->errors->create('shopify_request_failed', $error->getMessage());
        }

        try {
            $response = $this->http->send(
                'POST',
                $storeUrl . '/admin/api/' . $version . '/graphql.json',
                array(
                    'Content-Type'           => 'application/json',
                    'Accept'                 => 'application/json',
                    'X-Shopify-Access-Token' => $token,
                ),
                $body
            );
        } catch (\RuntimeException $error) {
            return $this->errors->create(
                'shopify_request_failed',
                'The Shopify API request failed to send. ' . $error->getMessage()
            );
        }

        return $this->interpret($response, $version);
    }

    /**
     * Classify a Shopify response.
     *
     * @return array{
     *     data: array<array-key, mixed>,
     *     throttle: array<string, int>|null,
     *     served_version: string,
     *     version_fallback: array{requested: string, served: string}|null
     * }|mixed
     */
    private function interpret(HttpResponse $response, string $version): mixed
    {
        if (strlen($response->body) > self::MAX_BODY_BYTES) {
            return $this->errors->create(
                'shopify_response_too_large',
                'The Shopify API response exceeds the maximum allowed size.'
            );
        }

        $status = $response->statusCode;

        if (429 === $status) {
            $retry = $this->header($response, 'Retry-After');

            return $this->errors->create(
                'shopify_rate_limited',
                'Shopify API rate limit reached. Retry after a short pause.',
                '' !== $retry ? array('retry_after' => (int) $retry) : array()
            );
        }

        $refusals = array(
            401 => array(
                'shopify_unauthorized',
                'Shopify API access denied. Verify the Admin API access token and its scopes.',
            ),
            402 => array('shopify_payment_required', 'Shopify requires a paid plan for this store.'),
            404 => array('shopify_store_not_found', 'Shopify store not found. Verify the shop domain.'),
        );

        if (isset($refusals[$status])) {
            return $this->errors->create($refusals[$status][0], $refusals[$status][1], array('status' => $status));
        }

        if ($status < 200 || $status >= 300) {
            return $this->errors->create(
                'shopify_http_error',
                sprintf('The Shopify API returned HTTP %d.', $status),
                array('status' => $status)
            );
        }

        $decoded = json_decode($response->body, true);

        if (! is_array($decoded)) {
            return $this->errors->create(
                'shopify_invalid_json',
                'The Shopify API returned a body that is not a JSON object.'
            );
        }

        $errors = $decoded['errors'] ?? null;

        if (is_array($errors) && array() !== $errors) {
            return $this->graphqlError($errors);
        }

        $data = $decoded['data'] ?? null;
        $served = $this->servedVersion($response, $version);

        return array(
            'data'             => is_array($data) ? $data : array(),
            'throttle'         => $this->throttle($decoded),
            'served_version'   => $served,
            // Shopify *falls forward* to the oldest accessible stable version when
            // the requested one is retired. That is a silent schema change, so it
            // is surfaced rather than left in a header nobody reads.
            'version_fallback' => $served !== $version
                ? array('requested' => $version, 'served' => $served)
                : null,
        );
    }

    /**
     * Fold a call's metadata into a payload: throttle state, and a notice when
     * Shopify served a different API version than the one requested.
     *
     * @param array<string, mixed> $data   Payload to extend.
     * @param mixed                $result A decoded call result.
     *
     * @return array<string, mixed>
     */
    protected function meta(array $data, mixed $result): array
    {
        if (! is_array($result)) {
            return $data;
        }

        if (is_array($result['throttle'] ?? null)) {
            $data['throttle'] = $result['throttle'];
        }

        if (is_array($result['version_fallback'] ?? null)) {
            $data['api_version'] = $result['version_fallback'];
        }

        return $data;
    }

    /**
     * Turn a GraphQL `errors[]` list into the canonical error.
     *
     * Shopify may return several; the first is the one that explains the rest.
     *
     * @param array<array-key, mixed> $errors Provider error list.
     */
    private function graphqlError(array $errors): mixed
    {
        $first   = is_array($errors[0] ?? null) ? $errors[0] : array();
        $message = $this->text($first['message'] ?? null);
        $code    = is_array($first['extensions'] ?? null) ? $this->text($first['extensions']['code'] ?? null) : '';

        return $this->errors->create(
            'shopify_graphql_error',
            '' !== $message ? $message : 'The Shopify GraphQL API returned an error.',
            array(
                'code'  => $code,
                'count' => count($errors),
            )
        );
    }

    /**
     * The mutation's `userErrors`, as the canonical error, or null when clear.
     *
     * @param array{data: array<array-key, mixed>} $payload A decoded call result.
     * @param string                               $path    Dot path inside `data`, e.g. `productCreate`.
     */
    protected function userError(array $payload, string $path): mixed
    {
        $node = $payload['data'];

        foreach (explode('.', $path) as $segment) {
            $node = is_array($node) ? ( $node[$segment] ?? null ) : null;
        }

        $userErrors = is_array($node) ? ( $node['userErrors'] ?? null ) : null;

        if (! is_array($userErrors) || array() === $userErrors) {
            return null;
        }

        $first   = is_array($userErrors[0] ?? null) ? $userErrors[0] : array();
        $message = $this->text($first['message'] ?? null);
        $field   = is_array($first['field'] ?? null)
            ? implode('.', array_map(array($this, 'text'), $first['field']))
            : $this->text($first['field'] ?? null);

        return $this->errors->create(
            'shopify_user_error',
            '' !== $message ? $message : 'Shopify refused the change.',
            array(
                'field' => $field,
                'count' => count($userErrors),
            )
        );
    }

    /**
     * Standard pagination, projected.
     *
     * `next_cursor` is `endCursor`; `has_previous_page` and `startCursor` are
     * dropped because no tool in this family pages backwards, and an unused field
     * is one more thing to keep in sync.
     *
     * @param array{data: array<array-key, mixed>} $payload A decoded call result.
     * @param string                               $path    Dot path inside `data`.
     *
     * @return array{has_next_page: bool, next_cursor: string|null}
     */
    protected function pageInfo(array $payload, string $path): array
    {
        $node = $this->at($payload['data'], $path);
        $info = is_array($node) ? ( $node['pageInfo'] ?? null ) : null;
        $info = is_array($info) ? $info : array();
        $next = $this->text($info['endCursor'] ?? null);

        return array(
            'has_next_page' => (bool) ( $info['hasNextPage'] ?? false ),
            'next_cursor'   => '' !== $next ? $next : null,
        );
    }

    /**
     * The `edges[].node` list at a path, as a plain list.
     *
     * @param array{data: array<array-key, mixed>} $payload A decoded call result.
     * @param string                               $path    Dot path inside `data`.
     *
     * @return list<array<array-key, mixed>>
     */
    protected function edges(array $payload, string $path): array
    {
        $node = $this->at($payload['data'], $path);

        return is_array($node) ? $this->nodesFrom($node['edges'] ?? null) : array();
    }

    /**
     * The `edges[].node` list of a connection on a raw node.
     *
     * Distinct from {@see edges()}, which takes a whole document and a path into
     * `data`. A projection works on a node, so it needs this one.
     *
     * @param array<array-key, mixed> $node       Raw node carrying the connection.
     * @param string                  $connection Connection field name.
     *
     * @return list<array<array-key, mixed>>
     */
    protected function edgesOf(array $node, string $connection): array
    {
        $connection = is_array($node[$connection] ?? null) ? $node[$connection] : array();

        return $this->nodesFrom($connection['edges'] ?? null);
    }

    /**
     * The nodes of an `edges` list, dropping anything malformed.
     *
     * @param mixed $edges Raw edges list.
     *
     * @return list<array<array-key, mixed>>
     */
    private function nodesFrom(mixed $edges): array
    {
        if (! is_array($edges)) {
            return array();
        }

        $nodes = array();

        foreach ($edges as $edge) {
            if (is_array($edge) && is_array($edge['node'] ?? null)) {
                $nodes[] = $edge['node'];
            }
        }

        return $nodes;
    }

    /**
     * A `MoneyV2` or money set, projected, or null when absent.
     *
     * The amount stays the provider's decimal **string**. `shopMoney` is the
     * shop-currency member of a `MoneyBag`; every tool here reads the shop's books,
     * so the presentment currency is deliberately not surfaced.
     *
     * @param mixed $set A `MoneyV2`, a `MoneyBag`, or null.
     *
     * @return array{amount: string, currency: string}|null
     */
    protected function money(mixed $set): ?array
    {
        if (! is_array($set)) {
            return null;
        }

        if (is_array($set['shopMoney'] ?? null)) {
            $set = $set['shopMoney'];
        }

        $amount   = $this->text($set['amount'] ?? null);
        $currency = $this->text($set['currencyCode'] ?? null);

        if ('' === $amount || '' === $currency) {
            return null;
        }

        return array('amount' => $amount, 'currency' => $currency);
    }

    /**
     * Normalise a provider identifier to a GID, or null when it cannot be one.
     *
     * A bare numeric id is accepted and prefixed, because that is what a caller
     * reading a URL or an export has. A full `gid://shopify/<Type>/<id>` is
     * passed through. Anything else is null — the WordPress tools forwarded it to
     * the provider and turned a typo into a GraphQL error.
     *
     * @param string $value Raw identifier.
     * @param string $type  Shopify type segment, e.g. `Product`.
     */
    protected function gid(string $value, string $type): ?string
    {
        $value = trim($value);

        if ('' === $value) {
            return null;
        }

        if (ctype_digit($value)) {
            return 'gid://shopify/' . $type . '/' . $value;
        }

        return 1 === preg_match('#^gid://shopify/[A-Za-z]+/\d+$#', $value) ? $value : null;
    }

    /**
     * A list read limit, clamped to Shopify's ceiling.
     *
     * @param mixed $value Raw argument.
     */
    protected function limit(mixed $value, int $default, int $max = self::MAX_PAGE_SIZE): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min($max, (int) $value));
    }

    /**
     * A cursor argument, or ''.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function cursor(array $arguments): string
    {
        return $this->text($arguments['after'] ?? null);
    }

    /**
     * A string field, trimmed, or ''.
     */
    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Cost telemetry, projected, or null when the provider omitted it.
     *
     * Shopify reports the query's cost and the bucket state in
     * `extensions.cost`. A caller that is about to exhaust the bucket wants
     * `available` and `restore_rate`, which is what this keeps; the WordPress
     * client logged it and then dropped it.
     *
     * @param array<array-key, mixed> $decoded The whole response document.
     *
     * @return array<string, int>|null
     */
    private function throttle(array $decoded): ?array
    {
        $cost = is_array($decoded['extensions'] ?? null) ? ( $decoded['extensions']['cost'] ?? null ) : null;

        if (! is_array($cost)) {
            return null;
        }

        $status   = is_array($cost['throttleStatus'] ?? null) ? $cost['throttleStatus'] : array();
        $throttle = array();

        $options = array(
            'cost'         => $cost['actualQueryCost'] ?? null,
            'available'    => $status['currentlyAvailable'] ?? null,
            'maximum'      => $status['maximumAvailable'] ?? null,
            'restore_rate' => $status['restoreRate'] ?? null,
        );

        foreach ($options as $key => $value) {
            if (is_numeric($value)) {
                $throttle[$key] = (int) $value;
            }
        }

        return array() === $throttle ? null : $throttle;
    }

    /**
     * The version Shopify actually served, or the requested one.
     */
    private function servedVersion(HttpResponse $response, string $requested): string
    {
        $served = $this->header($response, 'X-Shopify-API-Version');

        return '' !== $served ? $served : $requested;
    }

    /**
     * A response header, case-insensitively.
     */
    private function header(HttpResponse $response, string $name): string
    {
        foreach ($response->headers as $key => $value) {
            if (0 === strcasecmp((string) $key, $name) && is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * A value at a dot path inside a decoded `data` document.
     *
     * `$data` is `mixed` on purpose: a caller that reaches this with a refusal is
     * a bug, but it must produce a null rather than a warning cascade.
     *
     * @param mixed  $data Decoded `data`, or anything else.
     * @param string $path Dot path, e.g. `location.inventoryLevels`.
     */
    private function at(mixed $data, string $path): mixed
    {
        $node = is_array($data) ? $data : null;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node)) {
                return null;
            }

            $node = $node[$segment] ?? null;
        }

        return $node;
    }

    /**
     * The canonical refusal for a bad store URL.
     */
    private function refusedStoreUrl(string $message): mixed
    {
        return $this->errors->validationFailed(
            $message,
            array('store_url' => array($message))
        );
    }
}
