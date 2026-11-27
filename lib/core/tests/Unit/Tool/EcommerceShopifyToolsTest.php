<?php

/**
 * The four Shopify Admin GraphQL tools.
 *
 * Five properties are worth pinning, because each corresponds to a way the
 * WordPress originals were wrong:
 *
 * 1. **A GraphQL error is a failure**, even though Shopify answers HTTP 200.
 *    A test that only asserted "no exception" would pass against the broken
 *    client, which never looked at `errors[]`.
 * 2. **`userErrors` is a failure**, even though the mutation "succeeded".
 * 3. **The create path sends the current mutation.** `productCreate(input:)` is
 *    deprecated and `ProductInput` has no `variants` field, so the payload is
 *    asserted to use `product:` plus a second bulk-create call.
 * 4. **An adjustment always carries `changeFromQuantity`**, because the schema
 *    requires the key to be present even when it is null.
 * 5. **The credential never appears in a reply.** Every failure case serialises
 *    the result and asserts the token is absent.
 *
 * The store URL and token are arguments here, so the tests also cover the SSRF
 * guard and the "no credential" refusal.
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
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Ecommerce\ShopifyCustomersTool;
use Nvoos\Core\Tool\Ecommerce\ShopifyInventoryTool;
use Nvoos\Core\Tool\Ecommerce\ShopifyOrdersTool;
use Nvoos\Core\Tool\Ecommerce\ShopifyProductsTool;
use Nvoos\Core\Tool\Ecommerce\ShopifySmartSearch;
use PHPUnit\Framework\TestCase;

final class EcommerceShopifyToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const STORE = 'https://example.myshopify.com';

    private const TOKEN = 'shpat_secret_value';

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

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message = 'Not found.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message, 'data' => $data),
            )
        );

        // The tools discriminate a refusal with isError(), never with is_array():
        // the canonical error envelope is itself an array. The mock has to answer
        // that question truthfully or every guard reads a refusal as a result.
        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? null )
        );
    }

    // ─── Connection guards ───────────────────────────────────────────

    public function test_a_missing_store_url_is_refused_before_any_request(): void
    {
        $result = $this->products($this->http())->execute(
            array('access_token' => self::TOKEN, 'action' => 'list'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_a_plain_http_store_url_is_refused(): void
    {
        $result = $this->products($this->http())->execute(
            array(
                'store_url'    => 'http://example.myshopify.com',
                'access_token' => self::TOKEN,
                'action'       => 'list',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('https', $result['error']['message']);
    }

    public function test_a_private_address_is_refused(): void
    {
        $result = $this->products($this->http())->execute(
            array(
                'store_url'    => 'https://127.0.0.1',
                'access_token' => self::TOKEN,
                'action'       => 'list',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_store_url_with_a_path_is_refused(): void
    {
        $result = $this->products($this->http())->execute(
            array(
                'store_url'    => 'https://example.myshopify.com/admin',
                'access_token' => self::TOKEN,
                'action'       => 'list',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('path', $result['error']['message']);
    }

    public function test_a_missing_token_is_refused_before_any_request(): void
    {
        $result = $this->products($this->http())->execute(
            array('store_url' => self::STORE, 'action' => 'list'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_a_call_without_a_user_is_refused(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->products($this->http())->execute(
            array(
                'store_url'    => self::STORE,
                'access_token' => self::TOKEN,
                'action'       => 'list',
            ),
            array()
        );
    }

    public function test_the_default_api_version_is_the_latest_stable(): void
    {
        $this->products($this->http($this->answer(200, array('data' => array('products' => array('edges' => array()))))))
            ->execute($this->credentials('list'), self::CONTEXT);

        $this->assertSame(
            'https://example.myshopify.com/admin/api/2026-10/graphql.json',
            $this->requests[0]['url']
        );
    }

    public function test_an_api_version_can_be_pinned(): void
    {
        $this->products($this->http($this->answer(200, array('data' => array('products' => array('edges' => array()))))))
            ->execute($this->credentials('list', array('api_version' => '2026-04')), self::CONTEXT);

        $this->assertStringContainsString('/admin/api/2026-04/graphql.json', $this->requests[0]['url']);
    }

    public function test_an_impossible_api_version_is_refused(): void
    {
        $result = $this->products($this->http())->execute(
            $this->credentials('list', array('api_version' => '2026-13')),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_a_retired_version_is_reported_when_shopify_falls_forward(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array('data' => array('products' => array('edges' => array()))),
                array('X-Shopify-API-Version' => '2026-07')
            )
        );

        $result = $this->products($http)->execute(
            $this->credentials('list', array('api_version' => '2025-01')),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array('requested' => '2025-01', 'served' => '2026-07'),
            $result['data']['api_version']
        );
    }

    // ─── Transport classification ────────────────────────────────────

    public function test_a_graphql_error_is_a_failure_even_though_the_status_is_200(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array('errors' => array(array('message' => 'Field does not exist', 'extensions' => array('code' => 'undefinedField'))))
            )
        );

        $result = $this->products($http)->execute($this->credentials('list'), self::CONTEXT);

        $this->assertFalse($result['success']);
        $this->assertSame('shopify_graphql_error', $result['error']['code']);
        $this->assertSame('Field does not exist', $result['error']['message']);
        $this->assertSame('undefinedField', $result['error']['data']['code']);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($result));
    }

    public function test_a_401_is_a_credential_refusal(): void
    {
        $result = $this->products($this->http($this->answer(401, array())))
            ->execute($this->credentials('list'), self::CONTEXT);

        $this->assertSame('shopify_unauthorized', $result['error']['code']);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($result));
    }

    public function test_a_429_reports_the_retry_delay(): void
    {
        $http = $this->http($this->answer(429, array(), array('Retry-After' => '2')));

        $result = $this->products($http)->execute($this->credentials('list'), self::CONTEXT);

        $this->assertSame('shopify_rate_limited', $result['error']['code']);
        $this->assertSame(2, $result['error']['data']['retry_after']);
    }

    public function test_a_transport_failure_becomes_the_canonical_error(): void
    {
        $http = $this->http(new \RuntimeException('Could not resolve host'));

        $result = $this->products($http)->execute($this->credentials('list'), self::CONTEXT);

        $this->assertFalse($result['success']);
        $this->assertSame('shopify_request_failed', $result['error']['code']);
        $this->assertStringContainsString('Could not resolve host', $result['error']['message']);
    }

    public function test_a_body_that_is_not_json_is_refused(): void
    {
        $http = $this->http(new HttpResponse(200, 'not json'));

        $result = $this->products($http)->execute($this->credentials('list'), self::CONTEXT);

        $this->assertSame('shopify_invalid_json', $result['error']['code']);
    }

    // ─── Products ────────────────────────────────────────────────────

    public function test_products_are_listed_and_projected(): void
    {
        $result = $this->products($this->http($this->answer(200, $this->productsPayload())))
            ->execute($this->credentials('list'), self::CONTEXT);

        $this->assertTrue($result['success']);

        $product = $result['data']['products'][0];

        $this->assertSame('gid://shopify/Product/1', $product['id']);
        $this->assertSame('ACTIVE', $product['status']);
        $this->assertSame(array('cotton'), $product['tags']);
        $this->assertSame(
            array('min' => array('amount' => '19.99', 'currency' => 'USD'), 'max' => array('amount' => '24.99', 'currency' => 'USD')),
            $product['price_range']
        );
        $this->assertSame(12, $product['total_inventory']);
        $this->assertSame(array('Size' => 'M'), $product['variants'][0]['options']);
        $this->assertTrue($product['variants'][0]['in_stock']);
        $this->assertSame('https://cdn.example.test/tee.jpg', $product['images'][0]['url']);
        $this->assertSame(
            array('has_next_page' => true, 'next_cursor' => 'cursor-1'),
            $result['data']['page_info']
        );
    }

    public function test_the_list_request_sends_the_provider_document_and_variables(): void
    {
        $this->products($this->http($this->answer(200, $this->productsPayload())))
            ->execute($this->credentials('list', array('first' => 25, 'query' => 'status:active')), self::CONTEXT);

        $body = $this->body(0);

        $this->assertStringContainsString('query Products(', $body['query']);
        $this->assertStringContainsString('products(first: $first, after: $after, query: $query)', $body['query']);
        $this->assertSame(25, $body['variables']['first']);
        $this->assertSame('status:active', $body['variables']['query']);
        $this->assertArrayNotHasKey('after', $body['variables']);

        $this->assertSame(self::TOKEN, $this->requests[0]['headers']['X-Shopify-Access-Token']);
        $this->assertSame('POST', $this->requests[0]['method']);
    }

    public function test_a_product_is_fetched_by_numeric_id_and_carries_its_detail(): void
    {
        $payload = array(
            'data' => array(
                'product' => array(
                    'id'                => 'gid://shopify/Product/42',
                    'title'             => 'Tee',
                    'handle'            => 'tee',
                    'descriptionHtml'   => '<p>Cotton</p>',
                    'status'            => 'ACTIVE',
                    'vendor'            => 'Acme',
                    'productType'       => 'Apparel',
                    'tags'              => array(),
                    'createdAt'         => '2026-01-01T00:00:00Z',
                    'updatedAt'         => '2026-01-02T00:00:00Z',
                    'priceRangeV2'      => array(
                        'minVariantPrice' => array('amount' => '19.99', 'currencyCode' => 'USD'),
                        'maxVariantPrice' => array('amount' => '19.99', 'currencyCode' => 'USD'),
                    ),
                    'totalInventory'    => 3,
                    'variants'          => array('edges' => array(array('node' => array(
                        'id'                => 'gid://shopify/ProductVariant/7',
                        'title'             => 'M',
                        'sku'               => 'TEE-M',
                        'price'             => '19.99',
                        'compareAtPrice'    => '24.99',
                        'inventoryQuantity' => 3,
                        'barcode'           => '123456',
                        'selectedOptions'   => array(array('name' => 'Size', 'value' => 'M')),
                    )))),
                    'images'            => array('edges' => array()),
                    'collections'       => array('edges' => array(array('node' => array(
                        'id'     => 'gid://shopify/Collection/5',
                        'title'  => 'Summer',
                        'handle' => 'summer',
                    )))),
                    'seo'               => array('title' => 'Tee', 'description' => 'A tee'),
                ),
            ),
        );

        $result = $this->products($this->http($this->answer(200, $payload)))
            ->execute($this->credentials('get', array('product_id' => '42')), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('gid://shopify/Product/42', $this->body(0)['variables']['id']);
        $this->assertSame('<p>Cotton</p>', $result['data']['product']['description_html']);
        $this->assertSame('summer', $result['data']['product']['collections'][0]['handle']);
        $this->assertSame('A tee', $result['data']['product']['seo']['description']);
        $this->assertSame('123456', $result['data']['product']['variants'][0]['barcode']);
    }

    public function test_a_missing_product_is_a_not_found(): void
    {
        $result = $this->products($this->http($this->answer(200, array('data' => array('product' => null)))))
            ->execute($this->credentials('get', array('product_id' => '42')), self::CONTEXT);

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_create_uses_the_current_mutation_and_a_second_call_for_variants(): void
    {
        $http = $this->http(
            $this->answer(200, array('data' => array('productCreate' => array(
                'product'    => array(
                    'id'           => 'gid://shopify/Product/9',
                    'title'        => 'Tee',
                    'handle'       => 'tee',
                    'status'       => 'ACTIVE',
                    'vendor'       => 'Acme',
                    'productType'  => 'Apparel',
                    'tags'         => array(),
                    'createdAt'    => '2026-01-01T00:00:00Z',
                    'updatedAt'    => '2026-01-01T00:00:00Z',
                    'priceRangeV2' => null,
                    'totalInventory' => 0,
                    'variants'     => array('edges' => array()),
                    'images'       => array('edges' => array()),
                ),
                'userErrors' => array(),
            )))),
            $this->answer(200, array('data' => array('productVariantsBulkCreate' => array(
                'productVariants' => array(array(
                    'id'              => 'gid://shopify/ProductVariant/11',
                    'title'           => 'M',
                    'sku'             => 'TEE-M',
                    'price'           => '19.99',
                    'selectedOptions' => array(array('name' => 'Size', 'value' => 'M')),
                )),
                'userErrors'      => array(),
            )))),
        );

        $result = $this->products($http)->execute(
            $this->credentials(
                'create',
                array(
                    'title'            => 'Tee',
                    'description_html' => '<p>Cotton</p>',
                    'tags'             => array('cotton'),
                    'status'           => 'ACTIVE',
                    'options'          => array(
                        array('name' => 'Size', 'values' => array(array('name' => 'M'), array('name' => 'L'))),
                    ),
                    'variants'         => array(
                        array('sku' => 'TEE-M', 'price' => '19.99', 'options' => array('Size' => 'M')),
                    ),
                )
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertCount(2, $this->requests);

        $create = $this->body(0);

        $this->assertStringContainsString('productCreate(product: $product)', $create['query']);
        $this->assertSame('Tee', $create['variables']['product']['title']);
        $this->assertSame('<p>Cotton</p>', $create['variables']['product']['descriptionHtml']);
        $this->assertSame(
            array(array('name' => 'Size', 'values' => array(array('name' => 'M'), array('name' => 'L')))),
            $create['variables']['product']['productOptions']
        );
        $this->assertArrayNotHasKey('variants', $create['variables']['product']);

        $bulk = $this->body(1);

        $this->assertStringContainsString('productVariantsBulkCreate(productId: $productId', $bulk['query']);
        $this->assertSame('gid://shopify/Product/9', $bulk['variables']['productId']);
        $this->assertSame(
            array(array('optionName' => 'Size', 'name' => 'M')),
            $bulk['variables']['variants'][0]['optionValues']
        );
        $this->assertSame('TEE-M', $bulk['variables']['variants'][0]['sku']);

        $this->assertCount(1, $result['data']['product']['variants']);
        $this->assertSame('gid://shopify/ProductVariant/11', $result['data']['product']['variants'][0]['id']);
    }

    public function test_create_refuses_without_a_title(): void
    {
        $result = $this->products($this->http())->execute(
            $this->credentials('create', array('vendor' => 'Acme')),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_update_sends_the_product_input_with_the_id_inside(): void
    {
        $http = $this->http(
            $this->answer(200, array('data' => array('productUpdate' => array(
                'product'    => array(
                    'id'           => 'gid://shopify/Product/42',
                    'title'        => 'New title',
                    'handle'       => 'tee',
                    'status'       => 'DRAFT',
                    'vendor'       => 'Acme',
                    'productType'  => 'Apparel',
                    'tags'         => array(),
                    'createdAt'    => '2026-01-01T00:00:00Z',
                    'updatedAt'    => '2026-01-03T00:00:00Z',
                    'priceRangeV2' => null,
                    'totalInventory' => 0,
                    'variants'     => array('edges' => array()),
                    'images'       => array('edges' => array()),
                ),
                'userErrors' => array(),
            ))))
        );

        $result = $this->products($http)->execute(
            $this->credentials('update', array('product_id' => '42', 'title' => 'New title', 'status' => 'DRAFT')),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);

        $body = $this->body(0);

        $this->assertStringContainsString('productUpdate(product: $product)', $body['query']);
        $this->assertSame('gid://shopify/Product/42', $body['variables']['product']['id']);
        $this->assertSame('New title', $body['variables']['product']['title']);
        $this->assertSame('DRAFT', $body['variables']['product']['status']);
        $this->assertArrayNotHasKey('productOptions', $body['variables']['product']);
        $this->assertArrayNotHasKey('variants', $body['variables']['product']);
    }

    public function test_update_refuses_when_no_field_was_given(): void
    {
        $result = $this->products($this->http())->execute(
            $this->credentials('update', array('product_id' => '42')),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_a_user_error_from_a_mutation_is_a_refusal(): void
    {
        $http = $this->http(
            $this->answer(200, array('data' => array('productCreate' => array(
                'product'    => null,
                'userErrors' => array(array('field' => array('title'), 'message' => 'Title is taken')),
            ))))
        );

        $result = $this->products($http)->execute(
            $this->credentials('create', array('title' => 'Tee')),
            self::CONTEXT
        );

        $this->assertFalse($result['success']);
        $this->assertSame('shopify_user_error', $result['error']['code']);
        $this->assertSame('Title is taken', $result['error']['message']);
        $this->assertSame('title', $result['error']['data']['field']);
    }

    public function test_an_empty_query_is_relaxed_and_ranked(): void
    {
        $http = $this->http(
            $this->answer(200, array('data' => array('products' => array('edges' => array())))),
            $this->answer(200, $this->oneProduct('gid://shopify/Product/A', 'Tee')),
            $this->answer(200, $this->mergePayload()),
            $this->answer(200, $this->oneProduct('gid://shopify/Product/A', 'Tee')),
            $this->answer(200, array('data' => array('products' => array('edges' => array()))))
        );

        $result = $this->products($http)->execute(
            $this->credentials('list', array('query' => 'solitaire round diamond engagement ring 2 carat')),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['smart_search']);
        $this->assertSame('gid://shopify/Product/A', $result['data']['products'][0]['id']);
        $this->assertSame('gid://shopify/Product/B', $result['data']['products'][1]['id']);
        $this->assertStringContainsString('decomposed', $result['data']['note']);
        $this->assertGreaterThan(1, count($this->requests));
    }

    // ─── Smart search algorithm ──────────────────────────────────────

    public function test_smart_search_needs_three_meaningful_tokens(): void
    {
        $this->assertSame(array(), ShopifySmartSearch::subQueries('red shoes'));
        $this->assertSame(array(), ShopifySmartSearch::subQueries('the red shoes'));
    }

    public function test_smart_search_drops_stop_words_and_numbers(): void
    {
        $subQueries = ShopifySmartSearch::subQueries('the red leather boots 2 cm');

        $this->assertContains('red leather boots cm', $subQueries);
        $this->assertLessThanOrEqual(4, count($subQueries));
    }

    // ─── Orders ──────────────────────────────────────────────────────

    public function test_orders_are_listed_with_totals_customer_and_line_items(): void
    {
        $payload = array('data' => array('orders' => array(
            'pageInfo' => array('hasNextPage' => false, 'endCursor' => null),
            'edges'    => array(array('node' => array(
                'id'                     => 'gid://shopify/Order/1001',
                'name'                   => '#1001',
                'createdAt'              => '2026-02-01T10:00:00Z',
                'updatedAt'              => '2026-02-01T10:05:00Z',
                'processedAt'            => '2026-02-01T10:00:00Z',
                'cancelledAt'            => null,
                'cancelReason'           => null,
                'displayFinancialStatus' => 'PAID',
                'displayFulfillmentStatus' => 'UNFULFILLED',
                'totalPriceSet'          => array('shopMoney' => array('amount' => '59.97', 'currencyCode' => 'USD')),
                'subtotalPriceSet'       => array('shopMoney' => array('amount' => '54.97', 'currencyCode' => 'USD')),
                'totalShippingPriceSet'  => array('shopMoney' => array('amount' => '5.00', 'currencyCode' => 'USD')),
                'totalTaxSet'            => array('shopMoney' => array('amount' => '0.00', 'currencyCode' => 'USD')),
                'customer'               => array(
                    'id'                  => 'gid://shopify/Customer/5',
                    'firstName'           => 'Jane',
                    'lastName'            => 'Doe',
                    'defaultEmailAddress' => array('emailAddress' => 'jane@example.test'),
                ),
                'shippingAddress'        => array(
                    'address1' => '1 Main St',
                    'address2' => null,
                    'city'     => 'Springfield',
                    'province' => 'IL',
                    'zip'      => '62701',
                    'country'  => 'US',
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'company'  => null,
                    'phone'    => null,
                ),
                'tags'                   => array('wholesale'),
                'note'                   => null,
                'lineItems'              => array('edges' => array(array('node' => array(
                    'id'                     => 'gid://shopify/LineItem/1',
                    'title'                  => 'Tee',
                    'quantity'               => 3,
                    'sku'                    => 'TEE-M',
                    'originalUnitPriceSet'   => array('shopMoney' => array('amount' => '18.32', 'currencyCode' => 'USD')),
                    'variant'                => array('id' => 'gid://shopify/ProductVariant/7', 'sku' => 'TEE-M'),
                )))),
                'fulfillments'           => array(),
            ))),
        )));

        $result = $this->orders($this->http($this->answer(200, $payload)))
            ->execute($this->credentials('list'), self::CONTEXT);

        $this->assertTrue($result['success']);

        $order = $result['data']['orders'][0];

        $this->assertSame('#1001', $order['name']);
        $this->assertSame(array('amount' => '59.97', 'currency' => 'USD'), $order['total_price']);
        $this->assertSame('jane@example.test', $order['customer']['email']);
        $this->assertSame('Springfield', $order['shipping_address']['city']);
        $this->assertSame(3, $order['line_items'][0]['quantity']);
        $this->assertSame(array('amount' => '18.32', 'currency' => 'USD'), $order['line_items'][0]['unit_price']);
        $this->assertArrayNotHasKey('transactions', $order);
    }

    public function test_a_single_order_carries_transactions_and_refunds(): void
    {
        $payload = array('data' => array('order' => array(
            'id'                       => 'gid://shopify/Order/1001',
            'name'                     => '#1001',
            'createdAt'                => '2026-02-01T10:00:00Z',
            'updatedAt'                => null,
            'processedAt'              => null,
            'cancelledAt'              => null,
            'cancelReason'             => null,
            'displayFinancialStatus'   => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet'            => array('shopMoney' => array('amount' => '59.97', 'currencyCode' => 'USD')),
            'subtotalPriceSet'         => null,
            'totalShippingPriceSet'    => null,
            'totalTaxSet'              => null,
            'totalRefundedSet'         => array('shopMoney' => array('amount' => '10.00', 'currencyCode' => 'USD')),
            'customer'                 => null,
            'shippingAddress'          => null,
            'billingAddress'           => null,
            'lineItems'                => array('edges' => array()),
            'fulfillments'             => array(array(
                'id'         => 'gid://shopify/Fulfillment/2',
                'status'     => 'SUCCESS',
                'createdAt'  => '2026-02-02T00:00:00Z',
                'updatedAt'  => '2026-02-02T00:00:00Z',
                'trackingInfo' => array(array('company' => 'UPS', 'number' => '1Z', 'url' => 'https://ups.test/1Z')),
            )),
            'transactions'             => array(array(
                'id'         => 'gid://shopify/OrderTransaction/3',
                'kind'       => 'SALE',
                'status'     => 'SUCCESS',
                'gateway'    => 'shopify_payments',
                'createdAt'  => '2026-02-01T10:00:00Z',
                'amountSet'  => array('shopMoney' => array('amount' => '59.97', 'currencyCode' => 'USD')),
            )),
            'refunds'                  => array(array(
                'id'                => 'gid://shopify/Refund/4',
                'createdAt'         => '2026-02-03T00:00:00Z',
                'totalRefundedSet'  => array('shopMoney' => array('amount' => '10.00', 'currencyCode' => 'USD')),
            )),
            'tags'                     => array(),
            'note'                     => 'Leave at door',
        )));

        $result = $this->orders($this->http($this->answer(200, $payload)))
            ->execute($this->credentials('get', array('order_id' => '1001')), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('gid://shopify/Order/1001', $this->body(0)['variables']['id']);

        $order = $result['data']['order'];

        $this->assertSame('1Z', $order['fulfillments'][0]['tracking'][0]['number']);
        $this->assertSame('shopify_payments', $order['transactions'][0]['gateway']);
        $this->assertSame(array('amount' => '10.00', 'currency' => 'USD'), $order['refunds'][0]['total']);
        $this->assertSame('Leave at door', $order['note']);
    }

    // ─── Customers ───────────────────────────────────────────────────

    public function test_customers_use_the_replacement_fields_and_project_them_back(): void
    {
        $payload = array('data' => array('customers' => array(
            'pageInfo' => array('hasNextPage' => false, 'endCursor' => null),
            'edges'    => array(array('node' => array(
                'id'                  => 'gid://shopify/Customer/5',
                'firstName'           => 'Jane',
                'lastName'            => 'Doe',
                'displayName'         => 'Jane Doe',
                'createdAt'           => '2025-12-01T00:00:00Z',
                'updatedAt'           => '2026-01-01T00:00:00Z',
                'numberOfOrders'      => '4',
                'amountSpent'         => array('amount' => '123.45', 'currencyCode' => 'USD'),
                'verifiedEmail'       => true,
                'tags'                => array('vip'),
                'note'                => null,
                'defaultEmailAddress' => array(
                    'emailAddress'      => 'jane@example.test',
                    'marketingState'    => 'SUBSCRIBED',
                    'marketingUpdatedAt' => '2026-01-01T00:00:00Z',
                    'validFormat'       => true,
                ),
                'defaultPhoneNumber'  => array('phoneNumber' => '+15550100'),
                'defaultAddress'      => array(
                    'address1' => '1 Main St',
                    'address2' => null,
                    'city'     => 'Springfield',
                    'province' => 'IL',
                    'zip'      => '62701',
                    'country'  => 'US',
                ),
            ))),
        )));

        $result = $this->customers($this->http($this->answer(200, $payload)))
            ->execute($this->credentials('list'), self::CONTEXT);

        $this->assertTrue($result['success']);

        $customer = $result['data']['customers'][0];

        $this->assertSame('jane@example.test', $customer['email']);
        $this->assertSame('+15550100', $customer['phone']);
        $this->assertSame('SUBSCRIBED', $customer['marketing_state']);
        $this->assertTrue($customer['email_valid']);
        $this->assertSame(4, $customer['number_of_orders']);
        $this->assertSame(array('amount' => '123.45', 'currency' => 'USD'), $customer['amount_spent']);

        $query = $this->body(0)['query'];

        $this->assertStringContainsString('defaultEmailAddress', $query);
        $this->assertStringContainsString('defaultPhoneNumber', $query);
        $this->assertStringNotContainsString('emailMarketingConsent', $query);
    }

    public function test_a_single_customer_carries_the_address_book_and_recent_orders(): void
    {
        $payload = array('data' => array('customer' => array(
            'id'                  => 'gid://shopify/Customer/5',
            'firstName'           => 'Jane',
            'lastName'            => 'Doe',
            'displayName'         => 'Jane Doe',
            'createdAt'           => null,
            'updatedAt'           => null,
            'numberOfOrders'      => '1',
            'amountSpent'         => null,
            'verifiedEmail'       => false,
            'tags'                => array(),
            'note'                => null,
            'defaultEmailAddress' => null,
            'defaultPhoneNumber'  => null,
            'defaultAddress'      => null,
            'addressesV2'         => array('edges' => array(array('node' => array(
                'id'        => 'gid://shopify/MailingAddress/8',
                'address1'  => '2 Side St',
                'address2'  => null,
                'city'      => 'Shelbyville',
                'province'  => 'IL',
                'zip'       => '62565',
                'country'   => 'US',
                'company'   => null,
                'name'      => 'Jane Doe',
                'phone'     => null,
                'formatted' => array('2 Side St', 'Shelbyville IL 62565', 'US'),
            )))),
            'orders'              => array('edges' => array(array('node' => array(
                'id'                     => 'gid://shopify/Order/1001',
                'name'                   => '#1001',
                'createdAt'              => '2026-02-01T00:00:00Z',
                'displayFinancialStatus' => 'PAID',
                'displayFulfillmentStatus' => 'FULFILLED',
                'totalPriceSet'          => array('shopMoney' => array('amount' => '59.97', 'currencyCode' => 'USD')),
            )))),
        )));

        $result = $this->customers($this->http($this->answer(200, $payload)))
            ->execute($this->credentials('get', array('customer_id' => '5')), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('Shelbyville', $result['data']['customer']['addresses'][0]['city']);
        $this->assertSame('#1001', $result['data']['customer']['recent_orders'][0]['name']);
    }

    // ─── Inventory ───────────────────────────────────────────────────

    public function test_inventory_levels_are_read_for_a_location(): void
    {
        $payload = array('data' => array('location' => array(
            'id'   => 'gid://shopify/Location/3',
            'name' => 'Main warehouse',
            'inventoryLevels' => array(
                'pageInfo' => array('hasNextPage' => false, 'endCursor' => null),
                'edges'    => array(array('node' => array(
                    'id'         => 'gid://shopify/InventoryLevel/1',
                    'quantities' => array(
                        array('name' => 'available', 'quantity' => 5),
                        array('name' => 'on_hand', 'quantity' => 9),
                    ),
                    'item'       => array(
                        'id'      => 'gid://shopify/InventoryItem/2',
                        'sku'     => 'TEE-M',
                        'variant' => array(
                            'id'      => 'gid://shopify/ProductVariant/7',
                            'title'   => 'M',
                            'product' => array('id' => 'gid://shopify/Product/1', 'title' => 'Tee'),
                        ),
                    ),
                ))),
            ),
        )));

        $result = $this->inventory($this->http($this->answer(200, $payload)))
            ->execute($this->credentials('list_levels', array('location_id' => '3')), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('gid://shopify/Location/3', $this->body(0)['variables']['locationId']);
        $this->assertSame('Main warehouse', $result['data']['location_name']);
        $this->assertSame(array('available' => 5, 'on_hand' => 9), $result['data']['levels'][0]['quantities']);
        $this->assertSame('Tee', $result['data']['levels'][0]['product']['title']);
    }

    public function test_an_adjustment_always_sends_change_from_quantity(): void
    {
        $result = $this->inventory($this->http($this->answer(200, $this->adjustPayload())))
            ->execute(
                $this->credentials(
                    'adjust',
                    array(
                        'inventory_item_id' => '2',
                        'location_id'       => '3',
                        'delta'             => -2,
                    )
                ),
                self::CONTEXT
            );

        $this->assertTrue($result['success']);

        $change = $this->body(0)['variables']['input']['changes'][0];

        $this->assertArrayHasKey('changeFromQuantity', $change);
        $this->assertNull($change['changeFromQuantity']);
        $this->assertSame(-2, $change['delta']);
        $this->assertSame('gid://shopify/InventoryItem/2', $change['inventoryItemId']);
        $this->assertSame('gid://shopify/Location/3', $change['locationId']);
        $this->assertSame('available', $this->body(0)['variables']['input']['name']);
        $this->assertSame('correction', $this->body(0)['variables']['input']['reason']);
        $this->assertSame(-2, $result['data']['changes'][0]['delta']);
    }

    public function test_an_expected_quantity_becomes_the_compare_and_swap_value(): void
    {
        $this->inventory($this->http($this->answer(200, $this->adjustPayload())))
            ->execute(
                $this->credentials(
                    'adjust',
                    array(
                        'inventory_item_id' => '2',
                        'location_id'       => '3',
                        'delta'             => -2,
                        'expected_quantity' => 7,
                        'reason'            => 'shrinkage',
                    )
                ),
                self::CONTEXT
            );

        $input = $this->body(0)['variables']['input'];

        $this->assertSame(7, $input['changes'][0]['changeFromQuantity']);
        $this->assertSame('shrinkage', $input['reason']);
    }

    public function test_a_zero_delta_is_refused_before_the_call(): void
    {
        $result = $this->inventory($this->http())->execute(
            $this->credentials(
                'adjust',
                array('inventory_item_id' => '2', 'location_id' => '3', 'delta' => 0)
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_an_unknown_adjustment_reason_is_refused_before_the_call(): void
    {
        $result = $this->inventory($this->http())->execute(
            $this->credentials(
                'adjust',
                array('inventory_item_id' => '2', 'location_id' => '3', 'delta' => 1, 'reason' => 'because')
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_an_adjustment_user_error_is_a_refusal(): void
    {
        $http = $this->http(
            $this->answer(200, array('data' => array('inventoryAdjustQuantities' => array(
                'inventoryAdjustmentGroup' => null,
                'userErrors'               => array(array('field' => array('changes'), 'message' => 'Quantity is stale')),
            ))))
        );

        $result = $this->inventory($http)->execute(
            $this->credentials(
                'adjust',
                array('inventory_item_id' => '2', 'location_id' => '3', 'delta' => 1)
            ),
            self::CONTEXT
        );

        $this->assertSame('shopify_user_error', $result['error']['code']);
        $this->assertSame('Quantity is stale', $result['error']['message']);
    }

    public function test_locations_are_listed(): void
    {
        $payload = array('data' => array('locations' => array('edges' => array(array('node' => array(
            'id'       => 'gid://shopify/Location/3',
            'name'     => 'Main warehouse',
            'isActive' => true,
            'address'  => array('city' => 'Springfield', 'province' => 'IL', 'zip' => '62701', 'country' => 'US'),
        ))))));

        $result = $this->inventory($this->http($this->answer(200, $payload)))
            ->execute($this->credentials('list_locations'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['locations'][0]['is_active']);
        $this->assertSame('Springfield', $result['data']['locations'][0]['address']['city']);
    }

    public function test_the_shop_is_reported(): void
    {
        $payload = array('data' => array('shop' => array(
            'id'                  => 'gid://shopify/Shop/1',
            'name'                => 'Example',
            'email'               => 'owner@example.test',
            'myshopifyDomain'     => 'example.myshopify.com',
            'primaryDomain'       => array('host' => 'example.test', 'sslEnabled' => true),
            'currencyCode'        => 'USD',
            'timezoneAbbreviation' => 'EST',
            'ianaTimezone'        => 'America/New_York',
            'shipsToCountries'    => array('US', 'CA'),
            'taxesIncluded'       => false,
        )));

        $result = $this->inventory($this->http($this->answer(200, $payload)))
            ->execute($this->credentials('get_shop_info'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('USD', $result['data']['currency']);
        $this->assertSame('example.test', $result['data']['primary_domain']);
        $this->assertSame(array('US', 'CA'), $result['data']['ships_to_countries']);
    }

    // ─── Families ────────────────────────────────────────────────────

    public function test_the_slugs_are_the_wordpress_slugs(): void
    {
        $slugs = array();

        foreach ($this->tools($this->http()) as $tool) {
            $slugs[$tool->getSlug()] = true;
        }

        $this->assertSame(
            array(
                'shopify_products',
                'shopify_orders',
                'shopify_customers',
                'shopify_inventory',
            ),
            array_keys($slugs)
        );
    }

    public function test_a_writing_tool_declares_state_changing_and_a_reading_tool_does_not(): void
    {
        $this->assertContains('state-changing', $this->products($this->http())->getCapabilityFlags());
        $this->assertContains('state-changing', $this->inventory($this->http())->getCapabilityFlags());
        $this->assertContains('read-only', $this->orders($this->http())->getCapabilityFlags());
        $this->assertContains('read-only', $this->customers($this->http())->getCapabilityFlags());
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * @return list<object>
     */
    private function tools(HttpClientInterface $http): array
    {
        return array(
            new ShopifyProductsTool($this->errors, $http),
            new ShopifyOrdersTool($this->errors, $http),
            new ShopifyCustomersTool($this->errors, $http),
            new ShopifyInventoryTool($this->errors, $http),
        );
    }

    private function products(HttpClientInterface $http): ShopifyProductsTool
    {
        return new ShopifyProductsTool($this->errors, $http);
    }

    private function orders(HttpClientInterface $http): ShopifyOrdersTool
    {
        return new ShopifyOrdersTool($this->errors, $http);
    }

    private function customers(HttpClientInterface $http): ShopifyCustomersTool
    {
        return new ShopifyCustomersTool($this->errors, $http);
    }

    private function inventory(HttpClientInterface $http): ShopifyInventoryTool
    {
        return new ShopifyInventoryTool($this->errors, $http);
    }

    /**
     * @param array<string, mixed> $extra Additional arguments.
     *
     * @return array<string, mixed>
     */
    private function credentials(string $action, array $extra = array()): array
    {
        return array_merge(
            array(
                'store_url'    => self::STORE,
                'access_token' => self::TOKEN,
                'action'       => $action,
            ),
            $extra
        );
    }

    /**
     * @param array<string, mixed> $payload Decoded response document.
     * @param array<string, string> $headers Response headers.
     */
    private function answer(int $status, array $payload, array $headers = array()): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($payload), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function productsPayload(): array
    {
        return array('data' => array('products' => array(
            'pageInfo' => array('hasNextPage' => true, 'endCursor' => 'cursor-1'),
            'edges'    => array(array('node' => array(
                'id'           => 'gid://shopify/Product/1',
                'title'        => 'Tee',
                'handle'       => 'tee',
                'status'       => 'ACTIVE',
                'vendor'       => 'Acme',
                'productType'  => 'Apparel',
                'tags'         => array('cotton'),
                'createdAt'    => '2026-01-01T00:00:00Z',
                'updatedAt'    => '2026-01-02T00:00:00Z',
                'priceRangeV2' => array(
                    'minVariantPrice' => array('amount' => '19.99', 'currencyCode' => 'USD'),
                    'maxVariantPrice' => array('amount' => '24.99', 'currencyCode' => 'USD'),
                ),
                'totalInventory' => 12,
                'variants'       => array('edges' => array(array('node' => array(
                    'id'                => 'gid://shopify/ProductVariant/9',
                    'title'             => 'M',
                    'sku'               => 'TEE-M',
                    'price'             => '19.99',
                    'compareAtPrice'    => null,
                    'inventoryQuantity' => 12,
                    'selectedOptions'   => array(array('name' => 'Size', 'value' => 'M')),
                )))),
                'images'         => array('edges' => array(array('node' => array(
                    'id'      => 'gid://shopify/ProductImage/3',
                    'url'     => 'https://cdn.example.test/tee.jpg',
                    'altText' => 'Tee',
                )))),
            ))),
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function oneProduct(string $id, string $title): array
    {
        return array('data' => array('products' => array(
            'pageInfo' => array('hasNextPage' => false, 'endCursor' => null),
            'edges'    => array(array('node' => array(
                'id'           => $id,
                'title'        => $title,
                'handle'       => '',
                'status'       => 'ACTIVE',
                'vendor'       => '',
                'productType'  => '',
                'tags'         => array(),
                'createdAt'    => null,
                'updatedAt'    => null,
                'priceRangeV2' => null,
                'totalInventory' => 0,
                'variants'     => array('edges' => array()),
                'images'       => array('edges' => array()),
            ))),
        )));
    }

    /**
     * Sub-query two finds A and B; the others are built from {@see oneProduct}.
     *
     * @return array<string, mixed>
     */
    private function mergePayload(): array
    {
        return array('data' => array('products' => array(
            'pageInfo' => array('hasNextPage' => false, 'endCursor' => null),
            'edges'    => array(
                array('node' => array(
                    'id'           => 'gid://shopify/Product/A',
                    'title'        => 'A',
                    'handle'       => '',
                    'status'       => 'ACTIVE',
                    'vendor'       => '',
                    'productType'  => '',
                    'tags'         => array(),
                    'createdAt'    => null,
                    'updatedAt'    => null,
                    'priceRangeV2' => null,
                    'totalInventory' => 0,
                    'variants'     => array('edges' => array()),
                    'images'       => array('edges' => array()),
                )),
                array('node' => array(
                    'id'           => 'gid://shopify/Product/B',
                    'title'        => 'B',
                    'handle'       => '',
                    'status'       => 'ACTIVE',
                    'vendor'       => '',
                    'productType'  => '',
                    'tags'         => array(),
                    'createdAt'    => null,
                    'updatedAt'    => null,
                    'priceRangeV2' => null,
                    'totalInventory' => 0,
                    'variants'     => array('edges' => array()),
                    'images'       => array('edges' => array()),
                )),
            ),
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function adjustPayload(): array
    {
        return array('data' => array('inventoryAdjustQuantities' => array(
            'inventoryAdjustmentGroup' => array(
                'createdAt' => '2026-02-01T00:00:00Z',
                'reason'    => 'correction',
                'changes'   => array(array(
                    'name'               => 'available',
                    'delta'              => -2,
                    'quantityAfterChange' => 3,
                    'item'               => array('id' => 'gid://shopify/InventoryItem/2', 'sku' => 'TEE-M'),
                    'location'           => array('id' => 'gid://shopify/Location/3', 'name' => 'Main warehouse'),
                )),
            ),
            'userErrors'               => array(),
        )));
    }

    /**
     * An HttpClientInterface that replays the queued answers and records every
     * request. A queued RuntimeException is thrown, which is how a transport
     * failure reaches the tool.
     */
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
