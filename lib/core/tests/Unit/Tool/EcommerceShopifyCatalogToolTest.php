<?php

/**
 * The keyless UCP catalog tool.
 *
 * Six properties are worth pinning, and each corresponds to a way the WordPress
 * original was wrong or could not have been right:
 *
 * 1. **No credential is required.** The endpoint was measured answering without
 *    one, so no request may carry an Authorization header and no argument may
 *    be required for auth. A test that only asserted "a request happened" would
 *    pass against a tool that quietly demanded a token.
 * 2. **`limit` is not clamped to 10.** The WordPress tool computed
 *    `min(10, $limit)`, so this asserts what is *sent*, not merely what returns.
 * 3. **`price_range` and `variants` survive.** The WordPress normaliser read
 *    `pricerange` and `minvariantprice`, which are REST keys, so against UCP it
 *    emptied both. The assertion is on the projected decimal string.
 * 4. **Availability is not invented.** A variant the provider did not describe
 *    reports `null`, never `true`.
 * 5. **A JSON-RPC error is a refusal** even though the transport answers HTTP
 *    200, and `structuredContent` is required rather than assumed.
 * 6. **The envelope is what the endpoint documents**: `meta.ucp-agent.profile`
 *    plus a `catalog` object, wrapped in `tools/call`.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Tool\Ecommerce\ShopifyCatalogTool;
use PHPUnit\Framework\TestCase;

final class EcommerceShopifyCatalogToolTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const STORE = 'https://example.myshopify.com';

    private ErrorFactoryInterface $errors;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $requests = array();

    protected function setUp(): void
    {
        $this->requests = array();
        $this->errors   = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? null )
        );
    }

    // ─── No credential ───────────────────────────────────────────────

    public function test_a_search_needs_no_credential_and_sends_none(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array($this->product())))));

        $data = $this->data($tool->execute(array('action' => 'search', 'query' => 'shoes'), self::CONTEXT));

        $this->assertCount(1, $this->requests);
        $this->assertArrayNotHasKey('Authorization', $this->requests[0]['headers']);
        $this->assertSame(1, $data['count']);
    }

    public function test_the_global_endpoint_is_the_fixed_url(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $tool->execute(array('action' => 'search', 'query' => 'shoes'), self::CONTEXT);

        $this->assertSame(ShopifyCatalogTool::GLOBAL_ENDPOINT, $this->requests[0]['url']);
        $this->assertSame('POST', $this->requests[0]['method']);
    }

    public function test_the_tool_declares_read_only_and_is_not_credentialled(): void
    {
        $flags = $this->tool($this->http())->getCapabilityFlags();

        $this->assertContains('read-only', $flags);
        $this->assertContains('external-api', $flags);
        $this->assertNotContains('requires-credentials', $flags);
    }

    // ─── The envelope ────────────────────────────────────────────────

    public function test_the_jsonrpc_envelope_carries_the_agent_profile_and_catalog(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $tool->execute(array('action' => 'search', 'query' => 'shoes'), self::CONTEXT);

        $body = $this->body(0);

        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertSame('tools/call', $body['method']);
        $this->assertSame('search_catalog', $body['params']['name']);
        $this->assertSame(
            ShopifyCatalogTool::AGENT_PROFILE,
            $body['params']['arguments']['meta']['ucp-agent']['profile']
        );
        $this->assertSame('shoes', $body['params']['arguments']['catalog']['query']);
    }

    // ─── Storefront mode and the SSRF guard ──────────────────────────

    public function test_storefront_mode_requires_a_store_url(): void
    {
        $result = $this->tool($this->http())->execute(
            array('action' => 'search', 'mode' => 'storefront', 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_storefront_mode_appends_the_ucp_path(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $tool->execute(
            array('action' => 'search', 'mode' => 'storefront', 'store_url' => self::STORE, 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame(self::STORE . '/api/ucp/mcp', $this->requests[0]['url']);
    }

    public function test_a_private_store_address_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(
            array('action' => 'search', 'mode' => 'storefront', 'store_url' => 'https://127.0.0.1', 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_a_plain_http_store_address_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(
            array('action' => 'search', 'mode' => 'storefront', 'store_url' => 'http://example.myshopify.com', 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(
            array('action' => 'search', 'mode' => 'catalog_api', 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame('Unknown mode.', $result['error']['message']);
        $this->assertStringContainsString('global or storefront', $result['error']['data']['mode'][0]);
    }

    // ─── Parameters the WordPress tool got wrong ─────────────────────

    public function test_a_large_limit_is_sent_rather_than_clamped_to_ten(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $tool->execute(array('action' => 'search', 'query' => 'shoes', 'limit' => 40), self::CONTEXT);

        $this->assertSame(40, $this->body(0)['params']['arguments']['catalog']['pagination']['limit']);
    }

    public function test_a_limit_above_the_ceiling_is_bounded(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $tool->execute(array('action' => 'search', 'query' => 'shoes', 'limit' => 500), self::CONTEXT);

        $this->assertSame(50, $this->body(0)['params']['arguments']['catalog']['pagination']['limit']);
    }

    public function test_a_search_without_a_query_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(array('action' => 'search'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(array('action' => 'browse'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('Unknown action', $result['error']['message']);
    }

    public function test_a_cursor_is_forwarded(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $tool->execute(array('action' => 'search', 'query' => 'shoes', 'cursor' => 'abc'), self::CONTEXT);

        $this->assertSame('abc', $this->body(0)['params']['arguments']['catalog']['pagination']['cursor']);
    }

    // ─── The six re-targeted filters ─────────────────────────────────

    public function test_the_filters_are_retargeted_onto_the_ucp_object(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $tool->execute(
            array(
                'action'       => 'search',
                'query'        => 'shoes',
                'categories'   => array('Sneakers', 'Boots'),
                'min_price'    => 1999,
                'max_price'    => 5000,
                'shop_ids'     => array('12345', 'gid://shopify/Shop/678'),
                'country_code' => 'us',
                'ships_from'   => 'gb',
                'available'    => false,
            ),
            self::CONTEXT
        );

        $filters = $this->body(0)['params']['arguments']['catalog']['filters'];

        $this->assertSame(array('Sneakers', 'Boots'), $filters['categories']);
        $this->assertSame(array('min' => 1999, 'max' => 5000), $filters['price']);
        $this->assertSame(
            array('gid://shopify/Shop/12345', 'gid://shopify/Shop/678'),
            $filters['shops']
        );
        $this->assertSame(array('country' => 'US'), $filters['ships_to']);
        $this->assertSame(array(array('country' => 'GB')), $filters['ships_from']);
        $this->assertFalse($filters['available']);
    }

    public function test_a_comma_separated_categories_string_is_accepted(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $tool->execute(
            array('action' => 'search', 'query' => 'shoes', 'categories' => 'Sneakers, Boots'),
            self::CONTEXT
        );

        $this->assertSame(
            array('Sneakers', 'Boots'),
            $this->body(0)['params']['arguments']['catalog']['filters']['categories']
        );
    }

    public function test_a_minimum_price_above_the_maximum_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(
            array('action' => 'search', 'query' => 'shoes', 'min_price' => 5000, 'max_price' => 1000),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_a_bad_country_code_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(
            array('action' => 'search', 'query' => 'shoes', 'country_code' => 'USA'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_malformed_shop_id_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(
            array('action' => 'search', 'query' => 'shoes', 'shop_ids' => array('example.myshopify.com')),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── The projection the WordPress normaliser lost ────────────────

    public function test_the_price_range_survives_as_a_decimal_string(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array($this->product())))));

        $data = $this->data($tool->execute(array('action' => 'search', 'query' => 'shoes'), self::CONTEXT));

        $this->assertSame(
            array('min' => array('amount' => '114.99', 'currency' => 'USD'), 'max' => array('amount' => '129.99', 'currency' => 'USD')),
            $data['products'][0]['price_range']
        );
    }

    public function test_a_zero_decimal_currency_is_not_divided_by_a_hundred(): void
    {
        $product = $this->product(
            array(
                'price_range' => array(
                    'min' => array('amount' => 1500, 'currency' => 'JPY'),
                    'max' => array('amount' => 1500, 'currency' => 'JPY'),
                ),
            )
        );

        $tool = $this->tool($this->http($this->ucp(array('products' => array($product)))));

        $data = $this->data($tool->execute(array('action' => 'search', 'query' => 'shoes'), self::CONTEXT));

        $this->assertSame(
            array('amount' => '1500', 'currency' => 'JPY'),
            $data['products'][0]['price_range']['min']
        );
    }

    public function test_variants_survive_with_their_provider_availability(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array($this->product())))));

        $data = $this->data($tool->execute(array('action' => 'search', 'query' => 'shoes'), self::CONTEXT));

        $variant = $data['products'][0]['variants'][0];

        $this->assertSame('gid://shopify/ProductVariant/1', $variant['id']);
        $this->assertSame(array('amount' => '114.99', 'currency' => 'USD'), $variant['price']);
        $this->assertTrue($variant['available']);
    }

    public function test_an_undescribed_availability_is_null_rather_than_true(): void
    {
        $product = $this->product(
            array(
                'variants' => array(
                    array(
                        'id'    => 'gid://shopify/ProductVariant/9',
                        'title' => 'Size 9',
                        'price' => array('amount' => 100, 'currency' => 'USD'),
                    ),
                ),
            )
        );

        $tool = $this->tool($this->http($this->ucp(array('products' => array($product)))));

        $data = $this->data($tool->execute(array('action' => 'search', 'query' => 'shoes'), self::CONTEXT));

        $this->assertNull($data['products'][0]['variants'][0]['available']);
    }

    public function test_the_html_in_description_plain_is_stripped(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array($this->product())))));

        $data = $this->data($tool->execute(array('action' => 'search', 'query' => 'shoes'), self::CONTEXT));

        $this->assertSame('A shoe Leather', $data['products'][0]['description']);
    }

    public function test_pagination_is_projected_as_siblings(): void
    {
        $tool = $this->tool(
            $this->http(
                $this->ucp(
                    array(
                        'products'   => array($this->product()),
                        'pagination' => array('has_next_page' => true, 'total_count' => 789, 'cursor' => 'eyJv'),
                        'ucp'        => array('version' => '2026-08-25', 'status' => 'success'),
                    )
                )
            )
        );

        $data = $this->data($tool->execute(array('action' => 'search', 'query' => 'shoes'), self::CONTEXT));

        $this->assertSame(
            array('has_next_page' => true, 'cursor' => 'eyJv', 'total_count' => 789),
            $data['pagination']
        );
        $this->assertSame('2026-08-25', $data['ucp']['version']);
        $this->assertSame('success', $data['ucp']['status']);
    }

    // ─── Transport failures ──────────────────────────────────────────

    public function test_a_jsonrpc_error_is_a_refusal_even_on_http_200(): void
    {
        $response = new HttpResponse(
            200,
            (string) json_encode(
                array(
                    'jsonrpc' => '2.0',
                    'id'      => 1,
                    'error'   => array('code' => -32602, 'message' => 'Invalid params'),
                )
            )
        );

        $result = $this->tool($this->http($response))->execute(
            array('action' => 'search', 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame('shopify_catalog_rpc_error', $result['error']['code']);
        $this->assertStringContainsString('Invalid params', $result['error']['message']);
        $this->assertSame(-32602, $result['error']['data']['code']);
    }

    public function test_a_rate_limited_response_carries_retry_after(): void
    {
        $response = new HttpResponse(429, '{}', array('Retry-After' => '12'));

        $result = $this->tool($this->http($response))->execute(
            array('action' => 'search', 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame('shopify_catalog_rate_limited', $result['error']['code']);
        $this->assertSame(12, $result['error']['data']['retry_after']);
    }

    public function test_a_server_error_is_a_refusal(): void
    {
        $response = new HttpResponse(503, 'unavailable');

        $result = $this->tool($this->http($response))->execute(
            array('action' => 'search', 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame('shopify_catalog_http_error', $result['error']['code']);
        $this->assertSame(503, $result['error']['data']['status']);
    }

    public function test_a_non_json_body_is_a_refusal(): void
    {
        $result = $this->tool($this->http(new HttpResponse(200, '<html>nope</html>')))->execute(
            array('action' => 'search', 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame('shopify_catalog_invalid_json', $result['error']['code']);
    }

    public function test_a_result_without_structured_content_is_a_refusal(): void
    {
        $response = new HttpResponse(200, (string) json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'result' => array())));

        $result = $this->tool($this->http($response))->execute(
            array('action' => 'search', 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame('shopify_catalog_unexpected_response', $result['error']['code']);
    }

    public function test_a_transport_exception_is_a_refusal(): void
    {
        $result = $this->tool($this->http(new \RuntimeException('dns failure')))->execute(
            array('action' => 'search', 'query' => 'shoes'),
            self::CONTEXT
        );

        $this->assertSame('shopify_catalog_request_failed', $result['error']['code']);
        $this->assertStringContainsString('dns failure', $result['error']['message']);
    }

    // ─── Smart search ────────────────────────────────────────────────

    public function test_an_empty_search_is_relaxed_and_flagged(): void
    {
        $empty = $this->ucp(array('products' => array()));
        $hit   = $this->ucp(array('products' => array($this->product())));

        $tool = $this->tool($this->http($empty, $hit, $hit, $hit, $hit));

        $data = $this->data(
            $tool->execute(
                array('action' => 'search', 'query' => 'solitaire round diamond engagement ring'),
                self::CONTEXT
            )
        );

        $this->assertTrue($data['smart_search']);
        $this->assertSame(1, $data['count']);
        $this->assertStringContainsString('decomposed', $data['note']);
        $this->assertFalse($data['pagination']['has_next_page']);
    }

    public function test_smart_search_can_be_turned_off(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $data = $this->data(
            $tool->execute(
                array('action' => 'search', 'query' => 'solitaire round diamond engagement ring', 'smart_search' => false),
                self::CONTEXT
            )
        );

        $this->assertSame(0, $data['count']);
        $this->assertArrayNotHasKey('smart_search', $data);
        $this->assertCount(1, $this->requests);
    }

    // ─── Lookup and detail ───────────────────────────────────────────

    public function test_lookup_by_variant_maps_onto_lookup_catalog(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array($this->product())))));

        $data = $this->data(
            $tool->execute(
                array('action' => 'lookup_by_variant', 'vid' => 'gid://shopify/ProductVariant/1'),
                self::CONTEXT
            )
        );

        $this->assertSame('lookup_catalog', $this->body(0)['params']['name']);
        $this->assertSame(
            array('gid://shopify/ProductVariant/1'),
            $this->body(0)['params']['arguments']['catalog']['ids']
        );
        $this->assertSame('lookup_by_variant', $data['action']);
    }

    public function test_lookup_by_variant_without_a_vid_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(array('action' => 'lookup_by_variant'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_lookup_without_an_identifier_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(array('action' => 'lookup'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_lookup_sends_upid_with_the_ids(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $tool->execute(
            array('action' => 'lookup', 'upid' => 'gid://shopify/p/A', 'ids' => array('gid://shopify/p/B')),
            self::CONTEXT
        );

        $this->assertSame(
            array('gid://shopify/p/A', 'gid://shopify/p/B'),
            $this->body(0)['params']['arguments']['catalog']['ids']
        );
    }

    public function test_get_product_uses_the_product_id(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array($this->product())))));

        $data = $this->data(
            $tool->execute(array('action' => 'get_product', 'product_id' => 'gid://shopify/p/A'), self::CONTEXT)
        );

        $this->assertSame('get_product', $this->body(0)['params']['name']);
        $this->assertSame('gid://shopify/p/A', $this->body(0)['params']['arguments']['catalog']['id']);
        $this->assertSame('Air Force 1', $data['product']['title']);
    }

    public function test_get_product_without_an_id_is_refused(): void
    {
        $result = $this->tool($this->http())->execute(array('action' => 'get_product'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_get_product_reports_a_missing_product_rather_than_failing(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $data = $this->data(
            $tool->execute(array('action' => 'get_product', 'product_id' => 'gid://shopify/p/A'), self::CONTEXT)
        );

        $this->assertNull($data['product']);
    }

    public function test_the_context_is_whitelisted(): void
    {
        $tool = $this->tool($this->http($this->ucp(array('products' => array()))));

        $tool->execute(
            array(
                'action'  => 'search',
                'query'   => 'shoes',
                'context' => array('address_country' => 'US', 'evil' => 'drop table'),
            ),
            self::CONTEXT
        );

        $this->assertSame(
            array('address_country' => 'US'),
            $this->body(0)['params']['arguments']['catalog']['context']
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function tool(HttpClientInterface $http): ShopifyCatalogTool
    {
        return new ShopifyCatalogTool($this->errors, $http);
    }

    /**
     * @param array<string, mixed> $structured
     */
    private function ucp(array $structured, int $status = 200, array $headers = array()): HttpResponse
    {
        return new HttpResponse(
            $status,
            (string) json_encode(
                array(
                    'jsonrpc' => '2.0',
                    'id'      => 1,
                    'result'  => array('structuredContent' => $structured),
                )
            ),
            $headers
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function product(array $overrides = array()): array
    {
        return array_merge(
            array(
                'id'          => 'gid://shopify/p/ABC',
                'title'       => 'Air Force 1',
                'handle'      => 'air-force-1',
                'description' => array('plain' => '<p>A shoe</p><ul><li>Leather</li></ul>'),
                'price_range' => array(
                    'min' => array('amount' => 11499, 'currency' => 'USD'),
                    'max' => array('amount' => 12999, 'currency' => 'USD'),
                ),
                'media'       => array(
                    array(
                        'type'     => 'image',
                        'url'      => 'https://cdn.example/x.jpg',
                        'alt_text' => 'A white shoe',
                        'width'    => 1500,
                        'height'   => 1500,
                    ),
                ),
                'options'     => array(
                    array(
                        'name'   => 'Size',
                        'values' => array(array('label' => '10.0')),
                    ),
                ),
                'variants'    => array(
                    array(
                        'id'           => 'gid://shopify/ProductVariant/1',
                        'title'        => 'Size 10',
                        'price'        => array('amount' => 11499, 'currency' => 'USD'),
                        'availability' => array('available' => true),
                        'options'      => array(array('name' => 'Size', 'label' => '10.0')),
                        'checkout_url' => 'https://example.myshopify.com/cart/1',
                    ),
                ),
            ),
            $overrides
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function data(mixed $result): array
    {
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);

        return $result['data'];
    }

    private function http(HttpResponse|\RuntimeException ...$answers): HttpClientInterface
    {
        $requests = &$this->requests;
        $queue    = $answers;
        $http     = $this->createMock(HttpClientInterface::class);

        $http->method('send')->willReturnCallback(
            static function (string $method, string $url, array $headers = array(), ?string $body = null) use (&$queue, &$requests): HttpResponse {
                $requests[] = array(
                    'method'  => $method,
                    'url'     => $url,
                    'headers' => $headers,
                    'body'    => $body,
                );

                $answer = array_shift($queue);

                if ($answer instanceof \RuntimeException) {
                    throw $answer;
                }

                if (! $answer instanceof HttpResponse) {
                    throw new \LogicException('No queued HTTP answer.');
                }

                return $answer;
            }
        );

        return $http;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $index): array
    {
        $body = $this->requests[$index]['body'] ?? '';

        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : array();
    }
}
