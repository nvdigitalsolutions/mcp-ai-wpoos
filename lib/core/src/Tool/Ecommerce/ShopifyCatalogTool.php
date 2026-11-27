<?php

/**
 * Live Shopify catalog search, lookup and detail over the keyless UCP endpoint.
 *
 * Proposal 164 section 164.121. The WordPress original is `shopify_catalog`,
 * which fronted three connection modes: two keyless UCP modes and a deprecated
 * REST Catalog API mode. This port keeps the first two and drops the third.
 *
 * ## Why dropping the REST mode is not a capability loss
 *
 * The WordPress schema marked **every** filter it carried — `min_price`,
 * `max_price`, `categories`, `country_code`, `shop_ids`, `ships_from` — as
 * "REST Catalog API mode only", so a UCP caller never had them. Rather than
 * delete six dead parameters, this port re-targets each one onto the UCP
 * `filters` object the endpoint actually accepts, so the intent survives:
 *
 * | WordPress parameter | UCP filter |
 * |---|---|
 * | `min_price`, `max_price` | `filters.price.min`, `.max` (minor units) |
 * | `categories` | `filters.categories` (OR) |
 * | `shop_ids` | `filters.shops` (shop GIDs) |
 * | `country_code` | `filters.ships_to.country` |
 * | `ships_from` | `filters.ships_from[0].country` |
 *
 * `min_price` and `max_price` are **minor units**, and the schema says so. The
 * WordPress tool documented no unit at all, and only used the pair in the mode
 * that is now gone, so there is no live behaviour to preserve; the wire format
 * is minor units, and guessing a currency exponent is how a filter silently
 * means something else.
 *
 * ## The endpoint, measured rather than read
 *
 * Probed on 2026-11-27 against `https://catalog.shopify.com/api/ucp/mcp` with no
 * credential of any kind: `tools/list` answers `200` with exactly three tools
 * (`search_catalog`, `lookup_catalog`, `get_product`), and `tools/call` answers
 * `200` with ten products for `{catalog:{query:"shoes"}}`. The transport is a
 * single stateless POST — no `initialize` handshake, no session id, no UCP
 * header — so `HttpClientInterface` is sufficient. `signals` is optional: the
 * same query returns the same ten products with and without it.
 *
 * ## Nothing is cached, and that is the provider's rule
 *
 * UCP usage guidelines prohibit caching catalog results or product images. The
 * WordPress tool says so in its description and stores nothing, and this port
 * does the same rather than adding the `cacheable` flag for symmetry with its
 * siblings. A caller that caches the answer is out of contract, not merely
 * stale.
 *
 * ## Five corrections
 *
 * 1. **`limit` is no longer clamped to 10.** The WordPress tool computed
 *    `max(1, min(10, absint($limit)))`, so a caller asking for 50 was silently
 *    served 10 with no note. The bound here is 50 and the schema states it.
 * 2. **`price_range` and `variants` survive the projection.** The WordPress
 *    normaliser reads `pricerange`, `minvariantprice` and `availableforsale` —
 *    the REST shapes — so against a UCP response, whose fields are
 *    `price_range.min.amount` and `availability.available`, it produced an empty
 *    price range and an empty variant list.
 * 3. **Availability is not invented.** The same normaliser defaulted
 *    `availableforsale` to `true` whenever the field was absent, which against
 *    UCP was always. Every variant here reports the provider's own
 *    `availability.available`, and `null` when the provider omitted it.
 * 4. **The provider envelope is not reshaped.** `ucp`, `products`, `pagination`
 *    and `messages` are siblings under `structuredContent`; they stay siblings
 *    here rather than being folded into a `data` key or re-wrapped per endpoint.
 * 5. **`description.plain` is stripped, not trusted.** The field is named
 *    `plain` and carries HTML (`<p>`, `<ul>`). It is stripped and entity-decoded
 *    here, because returning markup from a field labelled plain is how a renderer
 *    ends up escaping it twice.
 *
 * `lookup_by_variant` is kept as an action even though the endpoint has no
 * variant tool: `lookup_catalog` takes variant GIDs in `ids`, which is what the
 * WordPress client did with it too. Collapsing it into `lookup` would have been
 * tidier and would have broken every caller that names it.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\ValueObject\Money;

final class ShopifyCatalogTool extends ShopifyTool
{
    /**
     * Shopify's cross-merchant catalog endpoint.
     */
    public const GLOBAL_ENDPOINT = 'https://catalog.shopify.com/api/ucp/mcp';

    /**
     * The path a storefront serves the same three tools on.
     */
    public const STOREFRONT_PATH = '/api/ucp/mcp';

    /**
     * The UCP agent profile sent in `meta.ucp-agent.profile`.
     *
     * A profile is how an agent declares which UCP capabilities it understands;
     * `valid-with-capabilities` is Shopify's own example profile for an agent
     * that implements them.
     */
    public const AGENT_PROFILE = 'https://shopify.dev/ucp/agent-profiles/2026-08-25/valid-with-capabilities.json';

    /**
     * Default page size, matching the endpoint's own default.
     */
    private const DEFAULT_LIMIT = 10;

    /**
     * The largest page this tool will request.
     */
    private const MAX_LIMIT = 50;

    /**
     * The most identifiers one lookup may carry.
     */
    private const MAX_IDS = 50;

    /**
     * The provider's documented ceiling for `filters.shops`.
     */
    private const MAX_SHOPS = 1000;

    /**
     * A response beyond this is refused rather than parsed.
     */
    private const MAX_BODY_BYTES = 4194304;

    /**
     * Media entries kept per product.
     */
    private const MAX_MEDIA = 8;

    /**
     * Variants projected per product.
     */
    private const MAX_VARIANTS = 25;

    public function getSlug(): string
    {
        return 'shopify_catalog';
    }

    public function getName(): string
    {
        return 'Shopify Catalog';
    }

    public function getDescription(): string
    {
        return 'Searches, looks up and loads product detail from a live Shopify catalog over the '
            . 'Universal Commerce Protocol, either across all Shopify merchants (mode global) or '
            . 'from one storefront (mode storefront). UCP prohibits caching catalog results, so '
            . 'every call is live and nothing is stored. Prices are returned as a decimal string '
            . 'with a currency code, projected from the provider\'s minor units.';
    }

    /**
     * Keyless, so `requires-credentials` is deliberately absent.
     *
     * This is the one place the tool departs from
     * {@see \Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary::REMOTE_CREDENTIALED},
     * and the departure is the measured fact that the endpoint answers without a
     * credential in any form.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'read-only',
        );
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'action'       => array(
                    'type'        => 'string',
                    'enum'        => array('search', 'lookup', 'lookup_by_variant', 'get_product'),
                    'description' => 'search finds products from a natural-language query; lookup '
                        . 'retrieves products by UPID or GID; lookup_by_variant retrieves the '
                        . 'product behind a variant GID; get_product returns one product in full.',
                ),
                'mode'         => array(
                    'type'        => 'string',
                    'enum'        => array('global', 'storefront'),
                    'default'     => 'global',
                    'description' => 'global queries every Shopify merchant through '
                        . 'catalog.shopify.com; storefront queries one store and requires store_url.',
                ),
                'store_url'    => array(
                    'type'        => 'string',
                    'description' => 'Store root, e.g. https://store.myshopify.com. Required when '
                        . 'mode is storefront and ignored otherwise.',
                ),
                'query'        => array(
                    'type'        => 'string',
                    'description' => 'Natural-language search query. Required for the search action.',
                ),
                'upid'         => array(
                    'type'        => 'string',
                    'description' => 'Universal product id from a previous search. Accepted by the '
                        . 'lookup and get_product actions.',
                ),
                'ids'          => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Product or variant GIDs for the lookup action. Any product or '
                        . 'variant GID is accepted. Up to 50 per call.',
                ),
                'vid'          => array(
                    'type'        => 'string',
                    'description' => 'Variant GID. Required for the lookup_by_variant action.',
                ),
                'product_id'   => array(
                    'type'        => 'string',
                    'description' => 'Product GID for the get_product action. upid is accepted as '
                        . 'an alternative.',
                ),
                'selected'     => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Optional field selectors for get_product, passed through to '
                        . 'the provider\'s catalog.selected.',
                ),
                'context'      => array(
                    'type'        => 'object',
                    'description' => 'Buyer signals used for localisation and ranking. Known keys: '
                        . 'address_country (ISO 3166-1 alpha-2), address_region, postal_code, '
                        . 'language (BCP 47), currency (ISO 4217), intent.',
                ),
                'cursor'       => array(
                    'type'        => 'string',
                    'description' => 'Opaque cursor from a previous search response.',
                ),
                'limit'        => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
                    'description' => 'Page size, 1 to 50. The provider\'s own default is 10.',
                ),
                'categories'   => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Category filter, combined with OR. A comma-separated string is '
                        . 'also accepted.',
                ),
                'min_price'    => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => 'Inclusive minimum price in the currency\'s minor units, e.g. '
                        . '1999 for 19.99 USD.',
                ),
                'max_price'    => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => 'Inclusive maximum price in the currency\'s minor units.',
                ),
                'available'    => array(
                    'type'        => 'boolean',
                    'description' => 'Filter to sale-ready products. The provider defaults this to '
                        . 'true; pass false to include unavailable products.',
                ),
                'shop_ids'     => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Restrict results to these shops. Accepts a numeric shop id, a '
                        . 'gid://shopify/Shop/N GID, or a comma-separated string of either.',
                ),
                'country_code' => array(
                    'type'        => 'string',
                    'pattern'     => '^[A-Za-z]{2}$',
                    'description' => 'ISO 3166-1 alpha-2 destination country, applied as '
                        . 'filters.ships_to.country.',
                ),
                'ships_from'   => array(
                    'type'        => 'string',
                    'pattern'     => '^[A-Za-z]{2}$',
                    'description' => 'ISO 3166-1 alpha-2 origin country, applied as '
                        . 'filters.ships_from[0].country.',
                ),
                'smart_search' => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => 'When the query returns nothing, retry with it decomposed into '
                        . 'keywords and merge the results. Costs up to four extra requests.',
                ),
            ),
            'required'             => array('action'),
            'additionalProperties' => false,
        );
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     */
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $action = $this->text($arguments['action'] ?? null);

        if ('' === $action) {
            return $this->errors->validationFailed(
                'An action is required.',
                array('action' => array('Expected search, lookup, lookup_by_variant or get_product.'))
            );
        }

        $endpoint = $this->endpoint($arguments);

        if ($this->errors->isError($endpoint)) {
            return $endpoint;
        }

        switch ($action) {
            case 'search':
                return $this->handleSearch((string) $endpoint, $arguments);

            case 'lookup':
                return $this->handleLookup((string) $endpoint, $arguments, false);

            case 'lookup_by_variant':
                return $this->handleLookup((string) $endpoint, $arguments, true);

            case 'get_product':
                return $this->handleGetProduct((string) $endpoint, $arguments);

            default:
                return $this->errors->validationFailed(
                    'Unknown action.',
                    array('action' => array('Expected search, lookup, lookup_by_variant or get_product.'))
                );
        }
    }

    /**
     * The endpoint for the requested mode, or the canonical refusal.
     *
     * `global` is a fixed public URL and takes no caller input. `storefront`
     * builds on {@see ShopifyTool::storeUrl()}, which is the one place a
     * caller-supplied host is checked.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function endpoint(array $arguments): mixed
    {
        $mode = $this->text($arguments['mode'] ?? null);

        if ('' === $mode || 'global' === $mode) {
            return self::GLOBAL_ENDPOINT;
        }

        if ('storefront' !== $mode) {
            return $this->errors->validationFailed(
                'Unknown mode.',
                array('mode' => array('Expected global or storefront.'))
            );
        }

        $store = $this->storeUrl($arguments);

        if ($this->errors->isError($store)) {
            return $store;
        }

        return (string) $store . self::STOREFRONT_PATH;
    }

    /**
     * A live search, with optional progressive relaxation.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleSearch(string $endpoint, array $arguments): mixed
    {
        $query = $this->text($arguments['query'] ?? null);

        if ('' === $query) {
            return $this->errors->validationFailed(
                'query is required for the search action.',
                array('query' => array('A natural-language search query is required.'))
            );
        }

        $limit    = $this->boundedLimit($arguments);
        $paging   = array('limit' => $limit);
        $cursor   = $this->text($arguments['cursor'] ?? null);
        $catalog  = array('query' => $query);
        $filters  = $this->filters($arguments);

        if ($this->errors->isError($filters)) {
            return $filters;
        }

        if ('' !== $cursor) {
            $paging['cursor'] = $cursor;
        }

        $catalog['pagination'] = $paging;

        $context = $this->context($arguments);

        if (array() !== $context) {
            $catalog['context'] = $context;
        }

        if (array() !== $filters) {
            $catalog['filters'] = $filters;
        }

        $page = $this->call($endpoint, 'search_catalog', $catalog);

        if ($this->errors->isError($page)) {
            return $page;
        }

        $products   = is_array($page) ? $page['products'] : array();
        $pagination = is_array($page) ? $page['pagination'] : array();
        $note       = '';

        if (array() === $products && $this->boolParam($arguments, 'smart_search', true)) {
            $subQueries = ShopifySmartSearch::subQueries($query);
            $sets       = array();

            foreach ($subQueries as $subQuery) {
                $subCatalog = $catalog;
                $subCatalog['query'] = $subQuery;

                $subPage = $this->call($endpoint, 'search_catalog', $subCatalog);

                if ($this->errors->isError($subPage)) {
                    continue;
                }

                $found = is_array($subPage) ? $subPage['products'] : array();

                if (array() !== $found) {
                    $sets[] = $found;
                }
            }

            if (array() !== $sets) {
                $products   = ShopifySmartSearch::rank($sets, $limit);
                $pagination = array('has_next_page' => false, 'cursor' => null);
                $note       = sprintf(
                    'The query "%s" returned nothing; smart search decomposed it and found %d product(s).',
                    $query,
                    count($products)
                );
            }
        }

        return $this->pageResult('search', $products, $pagination, $note, is_array($page) ? $page : array());
    }

    /**
     * A lookup by UPID, GID list, or variant GID.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleLookup(string $endpoint, array $arguments, bool $byVariant): mixed
    {
        $ids = $this->identifierList($arguments, $byVariant);

        if ($this->errors->isError($ids)) {
            return $ids;
        }

        $catalog = array('ids' => $ids);
        $context = $this->context($arguments);
        $filters = $this->filters($arguments);

        if ($this->errors->isError($filters)) {
            return $filters;
        }

        if (array() !== $context) {
            $catalog['context'] = $context;
        }

        if (array() !== $filters) {
            $catalog['filters'] = $filters;
        }

        $page = $this->call($endpoint, 'lookup_catalog', $catalog);

        if ($this->errors->isError($page)) {
            return $page;
        }

        $products   = is_array($page) ? $page['products'] : array();
        $pagination = is_array($page) ? $page['pagination'] : array();

        return $this->pageResult(
            $byVariant ? 'lookup_by_variant' : 'lookup',
            $products,
            $pagination,
            '',
            is_array($page) ? $page : array()
        );
    }

    /**
     * One product in full.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleGetProduct(string $endpoint, array $arguments): mixed
    {
        $id = $this->text($arguments['product_id'] ?? null);

        if ('' === $id) {
            $id = $this->text($arguments['upid'] ?? null);
        }

        if ('' === $id) {
            return $this->errors->validationFailed(
                'A product id is required for the get_product action.',
                array('product_id' => array('Pass product_id or upid.'))
            );
        }

        $catalog = array('id' => $id);
        $context = $this->context($arguments);
        $selected = $this->listArg($arguments, 'selected');

        if (array() !== $context) {
            $catalog['context'] = $context;
        }

        if (array() !== $selected) {
            $catalog['selected'] = $selected;
        }

        $page = $this->call($endpoint, 'get_product', $catalog);

        if ($this->errors->isError($page)) {
            return $page;
        }

        $products = is_array($page) ? $page['products'] : array();
        $product  = array() !== $products ? $this->product($products[0]) : null;

        $data = array(
            'product' => $product,
            'messages' => is_array($page) ? $page['messages'] : array(),
        );

        $data = $this->ucpMeta($data, is_array($page) ? $page : array());

        if (null === $product) {
            return $this->success('The catalog returned no product for that id.', $data);
        }

        return $this->success(sprintf('Loaded "%s".', (string) $product['title']), $data);
    }

    /**
     * Project and return a page of products.
     *
     * @param array<array-key, mixed> $products   Raw products from the provider.
     * @param array<array-key, mixed> $pagination Provider pagination.
     * @param array<array-key, mixed> $page       The whole decoded page.
     *
     * @return array<string, mixed>
     */
    private function pageResult(string $action, array $products, array $pagination, string $note, array $page): array
    {
        $projected = array();

        foreach ($products as $product) {
            if (is_array($product)) {
                $projected[] = $this->product($product);
            }
        }

        $data = array(
            'action'     => $action,
            'products'   => $projected,
            'count'      => count($projected),
            'pagination' => array(
                'has_next_page' => (bool) ( $pagination['has_next_page'] ?? false ),
                'cursor'        => is_scalar($pagination['cursor'] ?? null) ? (string) $pagination['cursor'] : null,
                'total_count'   => is_numeric($pagination['total_count'] ?? null)
                    ? (int) $pagination['total_count']
                    : null,
            ),
            'messages'   => is_array($page['messages'] ?? null) ? array_values($page['messages']) : array(),
        );

        $data = $this->ucpMeta($data, $page);

        if ('' !== $note) {
            $data['smart_search'] = true;
            $data['note']         = $note;
        }

        return $this->success(
            sprintf('Found %d product%s.', count($projected), 1 === count($projected) ? '' : 's'),
            $data
        );
    }

    /**
     * One JSON-RPC `tools/call`, or the canonical refusal.
     *
     * @param array<string, mixed> $catalog The UCP `catalog` argument object.
     */
    private function call(string $endpoint, string $tool, array $catalog): mixed
    {
        $payload = array(
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => array(
                'name'      => $tool,
                'arguments' => array(
                    'meta'    => array(
                        'ucp-agent' => array('profile' => self::AGENT_PROFILE),
                    ),
                    'catalog' => $catalog,
                ),
            ),
        );

        try {
            $body = $this->jsonBody($payload);
        } catch (\RuntimeException $error) {
            return $this->errors->create('shopify_catalog_request_failed', $error->getMessage());
        }

        try {
            $response = $this->http->send(
                'POST',
                $endpoint,
                array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                $body
            );
        } catch (\RuntimeException $error) {
            return $this->errors->create(
                'shopify_catalog_request_failed',
                'The Shopify catalog request failed to send. ' . $error->getMessage()
            );
        }

        return $this->interpret($response);
    }

    /**
     * Classify a catalog response.
     */
    private function interpret(HttpResponse $response): mixed
    {
        if (strlen($response->body) > self::MAX_BODY_BYTES) {
            return $this->errors->create(
                'shopify_catalog_response_too_large',
                'The Shopify catalog response exceeds the maximum allowed size.'
            );
        }

        $status = $response->statusCode;

        if (429 === $status) {
            $retry = $this->header($response, 'Retry-After');

            return $this->errors->create(
                'shopify_catalog_rate_limited',
                'The Shopify catalog rate limit was reached. Retry after a short pause.',
                '' !== $retry ? array('retry_after' => (int) $retry) : array()
            );
        }

        if ($status < 200 || $status >= 300) {
            return $this->errors->create(
                'shopify_catalog_http_error',
                sprintf('The Shopify catalog endpoint returned HTTP %d.', $status),
                array('status' => $status)
            );
        }

        $decoded = json_decode($response->body, true);

        if (! is_array($decoded)) {
            return $this->errors->create(
                'shopify_catalog_invalid_json',
                'The Shopify catalog endpoint returned a response that is not JSON.'
            );
        }

        if (is_array($decoded['error'] ?? null)) {
            $error    = $decoded['error'];
            $message  = is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'unknown error';
            $rpcCode  = is_numeric($error['code'] ?? null) ? (int) $error['code'] : -32603;

            return $this->errors->create(
                'shopify_catalog_rpc_error',
                'The Shopify catalog endpoint returned an error: ' . $message,
                array('code' => $rpcCode)
            );
        }

        $structured = $decoded['result']['structuredContent'] ?? null;

        if (! is_array($structured)) {
            return $this->errors->create(
                'shopify_catalog_unexpected_response',
                'The Shopify catalog endpoint answered without the UCP structured result.'
            );
        }

        return array(
            'products'   => is_array($structured['products'] ?? null) ? array_values($structured['products']) : array(),
            'pagination' => is_array($structured['pagination'] ?? null) ? $structured['pagination'] : array(),
            'messages'   => is_array($structured['messages'] ?? null) ? array_values($structured['messages']) : array(),
            'ucp'        => is_array($structured['ucp'] ?? null) ? $structured['ucp'] : array(),
        );
    }

    /**
     * Project one UCP product.
     *
     * @param array<array-key, mixed> $item Raw product.
     *
     * @return array<string, mixed>
     */
    private function product(array $item): array
    {
        $variants = is_array($item['variants'] ?? null) ? $item['variants'] : array();

        return array(
            'id'            => $this->text($item['id'] ?? null),
            'title'         => $this->text($item['title'] ?? null),
            'handle'        => $this->text($item['handle'] ?? null),
            'description'   => $this->plain($item['description'] ?? null),
            'price_range'   => $this->priceRange($item['price_range'] ?? null),
            'media'         => $this->media($item['media'] ?? null),
            'options'       => $this->options($item['options'] ?? null),
            'variant_count' => count($variants),
            'variants'      => $this->variants($variants),
        );
    }

    /**
     * `description.plain` carries HTML despite its name; strip and decode it.
     *
     * Tags are replaced with a space rather than removed, because `strip_tags()`
     * joins block elements: `<p>A shoe</p><ul><li>Leather</li></ul>` becomes
     * `A shoeLeather`. Collapsing whitespace afterwards keeps the text readable
     * without preserving the markup.
     */
    private function plain(mixed $description): string
    {
        if (! is_array($description)) {
            return '';
        }

        $raw = $this->text($description['plain'] ?? null);

        if ('' === $raw) {
            return '';
        }

        $text = (string) preg_replace('/<[^>]*>/', ' ', $raw);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function priceRange(mixed $range): array
    {
        if (! is_array($range)) {
            return array('min' => null, 'max' => null);
        }

        return array(
            'min' => $this->minorMoney($range['min'] ?? null),
            'max' => $this->minorMoney($range['max'] ?? null),
        );
    }

    /**
     * A money object from minor units, or null when it is not one.
     *
     * The provider sends `{amount: 11499, currency: "USD"}`. The exponent comes
     * from {@see Money}, so a zero-decimal currency is not divided by a hundred.
     *
     * @return array{amount: string, currency: string}|null
     */
    private function minorMoney(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $amount   = $value['amount'] ?? null;
        $currency = $this->text($value['currency'] ?? null);

        if (! is_int($amount) && ! ( is_string($amount) && 1 === preg_match('/^-?\d+$/', $amount) )) {
            return null;
        }

        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }

        $money = Money::fromMinorUnits((int) $amount, $currency);

        return array(
            'amount'   => $money->toDecimal(),
            'currency' => $currency,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function media(mixed $media): array
    {
        if (! is_array($media)) {
            return array();
        }

        $out = array();

        foreach ($media as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (count($out) >= self::MAX_MEDIA) {
                break;
            }

            $url = $this->text($entry['url'] ?? null);

            if ('' === $url) {
                continue;
            }

            $out[] = array(
                'type'     => $this->text($entry['type'] ?? null),
                'url'      => $url,
                'alt_text' => $this->text($entry['alt_text'] ?? null),
                'width'    => is_numeric($entry['width'] ?? null) ? (int) $entry['width'] : null,
                'height'   => is_numeric($entry['height'] ?? null) ? (int) $entry['height'] : null,
            );
        }

        return $out;
    }

    /**
     * Product options, `[{name, values:[{label}]}]`, flattened to labels.
     *
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    private function options(mixed $options): array
    {
        if (! is_array($options)) {
            return array();
        }

        $out = array();

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $labels = array();

            foreach (( is_array($option['values'] ?? null) ? $option['values'] : array() ) as $value) {
                if (is_array($value) && is_scalar($value['label'] ?? null)) {
                    $labels[] = trim((string) $value['label']);
                }
            }

            $out[] = array(
                'name'   => $this->text($option['name'] ?? null),
                'values' => $labels,
            );
        }

        return $out;
    }

    /**
     * Variants, bounded, with the provider's own availability.
     *
     * `available` is `null` when the provider omitted it, never a default: the
     * WordPress normaliser wrote `true` here and so reported every UCP product
     * as available.
     *
     * @return array<int, array<string, mixed>>
     */
    private function variants(mixed $variants): array
    {
        if (! is_array($variants)) {
            return array();
        }

        $out = array();

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            if (count($out) >= self::MAX_VARIANTS) {
                break;
            }

            $availability = $variant['availability'] ?? null;
            $labels       = array();

            foreach (( is_array($variant['options'] ?? null) ? $variant['options'] : array() ) as $option) {
                if (is_array($option) && is_scalar($option['label'] ?? null)) {
                    $labels[] = trim((string) $option['label']);
                }
            }

            $out[] = array(
                'id'           => $this->text($variant['id'] ?? null),
                'title'        => $this->text($variant['title'] ?? null),
                'price'        => $this->minorMoney($variant['price'] ?? null),
                'available'    => is_array($availability) && is_bool($availability['available'] ?? null)
                    ? $availability['available']
                    : null,
                'options'      => $labels,
                'checkout_url' => $this->text($variant['checkout_url'] ?? null),
            );
        }

        return $out;
    }

    /**
     * The `filters` object, or the canonical refusal, or an empty array.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function filters(array $arguments): mixed
    {
        $filters    = array();
        $categories = $this->listArg($arguments, 'categories');

        if (array() !== $categories) {
            $filters['categories'] = $categories;
        }

        $min = $this->intParam($arguments, 'min_price', -1);
        $max = $this->intParam($arguments, 'max_price', -1);

        if ($min > -1 || $max > -1) {
            if ($min > -1 && $max > -1 && $min > $max) {
                return $this->errors->validationFailed(
                    'min_price must not exceed max_price.',
                    array('min_price' => array('The minimum price is above the maximum price.'))
                );
            }

            $price = array();

            if ($min > -1) {
                $price['min'] = $min;
            }

            if ($max > -1) {
                $price['max'] = $max;
            }

            $filters['price'] = $price;
        }

        if (array_key_exists('available', $arguments)) {
            $filters['available'] = $this->boolParam($arguments, 'available', true);
        }

        $shops = $this->shops($arguments);

        if ($this->errors->isError($shops)) {
            return $shops;
        }

        if (array() !== $shops) {
            $filters['shops'] = $shops;
        }

        $destination = $this->country($arguments, 'country_code');

        if ($this->errors->isError($destination)) {
            return $destination;
        }

        if ('' !== $destination) {
            $filters['ships_to'] = array('country' => $destination);
        }

        $origin = $this->country($arguments, 'ships_from');

        if ($this->errors->isError($origin)) {
            return $origin;
        }

        if ('' !== $origin) {
            $filters['ships_from'] = array(array('country' => $origin));
        }

        return $filters;
    }

    /**
     * Shop GIDs, normalising a bare numeric id to a GID.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<int, string>|mixed
     */
    private function shops(array $arguments): mixed
    {
        $shops = array();

        foreach ($this->listArg($arguments, 'shop_ids') as $value) {
            $shop = trim($value);

            if ('' === $shop) {
                continue;
            }

            if (1 === preg_match('/^\d+$/', $shop)) {
                $shop = 'gid://shopify/Shop/' . $shop;
            }

            if (1 !== preg_match('#^gid://shopify/Shop/\d+$#', $shop)) {
                return $this->errors->validationFailed(
                    'A shop id must be numeric or a gid://shopify/Shop/N GID.',
                    array('shop_ids' => array('Unrecognised shop id: ' . $shop))
                );
            }

            $shops[] = $shop;
        }

        if (count($shops) > self::MAX_SHOPS) {
            return $this->errors->validationFailed(
                sprintf('At most %d shops may be named.', self::MAX_SHOPS),
                array('shop_ids' => array('Too many shop ids.'))
            );
        }

        return $shops;
    }

    /**
     * An ISO 3166-1 alpha-2 country, upper-cased, or ''.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function country(array $arguments, string $key): mixed
    {
        $value = $this->text($arguments[$key] ?? null);

        if ('' === $value) {
            return '';
        }

        if (1 !== preg_match('/^[A-Za-z]{2}$/', $value)) {
            return $this->errors->validationFailed(
                sprintf('%s must be a two-letter ISO 3166-1 alpha-2 country code.', $key),
                array($key => array('Expected two letters, e.g. US.'))
            );
        }

        return strtoupper($value);
    }

    /**
     * Buyer context, keeping only the keys the provider documents.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, string>
     */
    private function context(array $arguments): array
    {
        $raw = $arguments['context'] ?? null;

        if (! is_array($raw)) {
            return array();
        }

        $context = array();

        foreach (array('address_country', 'address_region', 'postal_code', 'language', 'currency', 'intent') as $key) {
            $value = $this->text($raw[$key] ?? null);

            if ('' !== $value) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * The identifiers for a lookup, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<int, string>|mixed
     */
    private function identifierList(array $arguments, bool $byVariant): mixed
    {
        if ($byVariant) {
            $vid = $this->text($arguments['vid'] ?? null);

            if ('' === $vid) {
                return $this->errors->validationFailed(
                    'vid is required for the lookup_by_variant action.',
                    array('vid' => array('A variant GID is required.'))
                );
            }

            return array($vid);
        }

        $ids  = $this->listArg($arguments, 'ids');
        $upid = $this->text($arguments['upid'] ?? null);

        if ('' !== $upid) {
            array_unshift($ids, $upid);
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => '' !== trim($id))));

        if (array() === $ids) {
            return $this->errors->validationFailed(
                'A lookup needs an identifier.',
                array('ids' => array('Pass upid, or one or more ids.'))
            );
        }

        if (count($ids) > self::MAX_IDS) {
            return $this->errors->validationFailed(
                sprintf('At most %d identifiers may be looked up at once.', self::MAX_IDS),
                array('ids' => array('Too many identifiers.'))
            );
        }

        return $ids;
    }

    /**
     * A list argument, accepting an array or a comma-separated string.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<int, string>
     */
    private function listArg(array $arguments, string $key): array
    {
        $raw = $arguments[$key] ?? null;

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (! is_array($raw)) {
            return array();
        }

        $list = array();

        foreach ($raw as $value) {
            if (is_scalar($value)) {
                $list[] = trim((string) $value);
            }
        }

        return array_values(array_filter($list, static fn (string $value): bool => '' !== $value));
    }

    /**
     * The page size, bounded by the tool rather than clamped silently.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function boundedLimit(array $arguments): int
    {
        $limit = $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT);

        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * The UCP version and status, kept alongside the page.
     *
     * @param array<string, mixed> $data Projected payload.
     * @param array<string, mixed> $page The decoded provider page.
     *
     * @return array<string, mixed>
     */
    private function ucpMeta(array $data, array $page): array
    {
        $ucp = is_array($page['ucp'] ?? null) ? $page['ucp'] : array();

        $data['ucp'] = array(
            'version' => $this->text($ucp['version'] ?? null),
            'status'  => $this->text($ucp['status'] ?? null),
        );

        return $data;
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
}
