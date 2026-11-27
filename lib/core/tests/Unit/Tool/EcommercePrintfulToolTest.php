<?php

/**
 * The Printful tool.
 *
 * Each test corresponds to a correction in the tool's file docblock, and each
 * fails against the WordPress original:
 *
 * 1. **No cache.** Two identical reads are two requests. The original cached GETs
 *    in a transient keyed on the URL alone, so two stores could be served each
 *    other's products.
 * 2. **No local limiter.** A `429` is a refusal carrying `Retry-After`; the
 *    original slept for up to 20 seconds inside the tool call, and its guard was
 *    per user against a per-account provider limit.
 * 3. **Paging is a sibling.** A single-object result is not relabelled as
 *    `{items: …}` depending on whether the endpoint paginated.
 * 4. **No hidden store.** The token and store id are arguments; the WordPress
 *    `list_connections` action and its connection id are gone.
 *
 * Fixtures mirror the provider's documented envelope: `{code, result}` with
 * `paging` alongside, and `{code, result, error: {reason, message}}` for a
 * failure.
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
use Nvoos\Core\Tool\Ecommerce\PrintfulTool;
use PHPUnit\Framework\TestCase;

final class EcommercePrintfulToolTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const TOKEN = 'pf_private_token';

    private ErrorFactoryInterface $errors;

    private HttpClientInterface $http;

    /**
     * Every request the tool made, in order.
     *
     * @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    private array $requests = array();

    /**
     * Responses to hand out, in order.
     *
     * @var list<HttpResponse|\RuntimeException>
     */
    private array $queue = array();

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        // A refusal is array-shaped, so `is_error()` is the only discriminator
        // that separates it from a request description.
        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? null )
        );

        $this->requests = array();
        $this->queue = array();

        $this->http = $this->createMock(HttpClientInterface::class);

        $this->http->method('send')->willReturnCallback(
            function (string $method, string $url, array $headers = array(), ?string $body = null): HttpResponse {
                $this->requests[] = array(
                    'method' => $method,
                    'url' => $url,
                    'headers' => $headers,
                    'body' => $body,
                );

                $next = array_shift($this->queue);

                if ($next instanceof \RuntimeException) {
                    throw $next;
                }

                if (! $next instanceof HttpResponse) {
                    throw new \RuntimeException('No queued response for ' . $url);
                }

                return $next;
            }
        );
    }

    /**
     * @param list<HttpResponse|\RuntimeException> $queue Canned responses.
     */
    private function tool(array $queue): PrintfulTool
    {
        $this->queue = $queue;

        return new PrintfulTool($this->errors, $this->http);
    }

    /**
     * @param array<mixed>         $body    Provider body.
     * @param array<string,string> $headers Response headers.
     */
    private function json(int $status, array $body, array $headers = array()): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(mixed $result): array
    {
        $this->assertIsArray($result);
        $this->assertTrue($result['success'] ?? false, 'Expected a success envelope.');
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    private function errorCode(mixed $result): string
    {
        $this->assertIsArray($result);
        $this->assertFalse($result['success'] ?? true, 'Expected a refusal envelope.');
        $this->assertArrayHasKey('error', $result);
        $this->assertIsArray($result['error']);

        return (string) $result['error']['code'];
    }

    /**
     * @param array<string, mixed> $extra Extra arguments merged over the base set.
     *
     * @return array<string, mixed>
     */
    private function args(array $extra = array()): array
    {
        return array_merge(array('api_token' => self::TOKEN), $extra);
    }

    // ─── Correction 1: no cache, no cross-store sharing ──────────────

    public function test_two_identical_reads_are_two_requests(): void
    {
        $body = array('code' => 200, 'result' => array(array('id' => 71)));

        $tool = $this->tool(array($this->json(200, $body), $this->json(200, $body)));

        $tool->execute($this->args(array('action' => 'list_catalog_products')), self::CONTEXT);
        $tool->execute($this->args(array('action' => 'list_catalog_products')), self::CONTEXT);

        // The WordPress client served the second from a transient.
        $this->assertCount(2, $this->requests);
        $this->assertSame($this->requests[0]['url'], $this->requests[1]['url']);
    }

    public function test_the_store_header_distinguishes_two_accounts(): void
    {
        $body = array('code' => 200, 'result' => array(array('id' => 1)));

        $tool = $this->tool(array($this->json(200, $body), $this->json(200, $body)));

        $tool->execute(
            $this->args(array('action' => 'list_orders', 'store_id' => '111')),
            self::CONTEXT
        );
        $tool->execute(
            $this->args(array('action' => 'list_orders', 'store_id' => '222')),
            self::CONTEXT
        );

        $this->assertSame('111', $this->requests[0]['headers']['X-PF-Store-Id']);
        $this->assertSame('222', $this->requests[1]['headers']['X-PF-Store-Id']);
        $this->assertSame('Bearer ' . self::TOKEN, $this->requests[0]['headers']['Authorization']);
    }

    // ─── Correction 2: a throttle is reported, not slept through ─────

    public function test_a_throttle_is_a_refusal_carrying_retry_after(): void
    {
        $tool = $this->tool(
            array(
                $this->json(
                    429,
                    array(
                        'code' => 429,
                        'result' => 'Too many requests',
                        'error' => array('reason' => 'Ratelimit', 'message' => 'Rate limit reached'),
                    ),
                    array('Retry-After' => '12', 'X-Ratelimit-Remaining' => '0')
                ),
            )
        );

        $started = microtime(true);

        $result = $tool->execute($this->args(array('action' => 'list_orders')), self::CONTEXT);

        $elapsed = microtime(true) - $started;

        $this->assertSame('printful_rate_limited', $this->errorCode($result));
        $this->assertIsArray($result['error']['data']);
        $this->assertSame(12, $result['error']['data']['retry_after']);
        $this->assertLessThan(1.0, $elapsed, 'The tool must not block the worker.');
    }

    // ─── Correction 3: paging is a sibling of the payload ───────────

    public function test_a_paginated_single_object_is_not_relabelled_as_items(): void
    {
        $tool = $this->tool(
            array(
                $this->json(
                    200,
                    array(
                        'code' => 200,
                        'result' => array('id' => 71, 'type' => 'T-SHIRT'),
                        'paging' => array('total' => 1, 'offset' => 0, 'limit' => 20),
                    )
                ),
            )
        );

        $data = $this->data(
            $tool->execute($this->args(array('action' => 'get_catalog_product', 'product_id' => 71)), self::CONTEXT)
        );

        // The original wrapped any array-shaped result as {items: …}.
        $this->assertSame(array('id' => 71, 'type' => 'T-SHIRT'), $data['data']);
        $this->assertSame(array('total' => 1, 'offset' => 0, 'limit' => 20), $data['paging']);
        $this->assertSame('v1', $data['api_version']);
    }

    public function test_an_unpaginated_response_reports_null_paging(): void
    {
        $tool = $this->tool(
            array($this->json(200, array('code' => 200, 'result' => array(array('code' => 'US')))))
        );

        $data = $this->data($tool->execute($this->args(array('action' => 'list_countries')), self::CONTEXT));

        $this->assertNull($data['paging']);
    }

    // ─── Correction 4: no hidden store, and no id-0 lookups ─────────

    public function test_a_missing_identifier_is_refused_before_any_request(): void
    {
        $tool = $this->tool(array());

        $result = $tool->execute($this->args(array('action' => 'get_catalog_product')), self::CONTEXT);

        // The original warned and asked Printful for product 0.
        $this->assertSame('validation_failed', $this->errorCode($result));
        $this->assertSame(array(), $this->requests);
    }

    public function test_a_missing_token_is_refused_except_for_countries(): void
    {
        $tool = $this->tool(array($this->json(200, array('code' => 200, 'result' => array()))));

        $refused = $tool->execute(array('action' => 'list_orders'), self::CONTEXT);
        $this->assertSame('validation_failed', $this->errorCode($refused));
        $this->assertSame(array(), $this->requests);

        // Printful serves the country list without a token.
        $this->data($tool->execute(array('action' => 'list_countries'), self::CONTEXT));
        $this->assertCount(1, $this->requests);
        $this->assertArrayNotHasKey('Authorization', $this->requests[0]['headers']);
    }

    // ─── Provider vocabulary ─────────────────────────────────────────

    public function test_the_provider_message_and_reason_are_preserved(): void
    {
        $tool = $this->tool(
            array(
                $this->json(
                    404,
                    array(
                        'code' => 404,
                        'result' => null,
                        'error' => array('reason' => 'NotFound', 'message' => 'Product not found'),
                    )
                ),
            )
        );

        $result = $tool->execute(
            $this->args(array('action' => 'get_catalog_product', 'product_id' => 9)),
            self::CONTEXT
        );

        $this->assertSame('printful_api_error', $this->errorCode($result));
        $this->assertIsArray($result['error']);
        $this->assertSame('Product not found', $result['error']['message']);
        $this->assertSame('NotFound', $result['error']['data']['reason']);
        $this->assertSame(404, $result['error']['data']['status']);
    }

    public function test_rate_limit_headers_are_surfaced_on_success(): void
    {
        $tool = $this->tool(
            array(
                $this->json(
                    200,
                    array('code' => 200, 'result' => array()),
                    array('X-Ratelimit-Limit' => '120', 'X-Ratelimit-Remaining' => '118', 'X-Ratelimit-Reset' => '1')
                ),
            )
        );

        $data = $this->data($tool->execute($this->args(array('action' => 'list_orders')), self::CONTEXT));

        $this->assertIsArray($data['rate_limits']);
        $this->assertSame(120, $data['rate_limits']['requests_limit']);
        $this->assertSame(118, $data['rate_limits']['requests_remaining']);
        $this->assertSame(1, $data['rate_limits']['reset_seconds']);
    }

    // ─── Dispatch ────────────────────────────────────────────────────

    public function test_a_write_action_is_flagged_as_a_write(): void
    {
        $tool = $this->tool(
            array(
                $this->json(200, array('code' => 200, 'result' => array('id' => 5))),
                $this->json(200, array('code' => 200, 'result' => array())),
            )
        );

        $created = $this->data(
            $tool->execute(
                $this->args(
                    array(
                        'action' => 'create_sync_product',
                        'payload' => array('sync_product' => array('name' => 'Tee')),
                    )
                ),
                self::CONTEXT
            )
        );

        $this->assertTrue($created['writes']);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://api.printful.com/store/products', $this->requests[0]['url']);

        $listed = $this->data($tool->execute($this->args(array('action' => 'list_orders')), self::CONTEXT));
        $this->assertFalse($listed['writes']);
    }

    public function test_a_write_action_without_a_payload_is_refused(): void
    {
        $tool = $this->tool(array());

        $result = $tool->execute($this->args(array('action' => 'create_order')), self::CONTEXT);

        $this->assertSame('validation_failed', $this->errorCode($result));
        $this->assertSame(array(), $this->requests);
    }

    public function test_an_external_id_is_percent_encoded(): void
    {
        $tool = $this->tool(array($this->json(200, array('code' => 200, 'result' => array('id' => 5)))));

        $tool->execute(
            $this->args(array('action' => 'get_sync_product', 'sync_product_id' => '@ext-1')),
            self::CONTEXT
        );

        $this->assertSame(
            'https://api.printful.com/store/products/%40ext-1',
            $this->requests[0]['url']
        );
    }

    public function test_confirm_is_sent_as_a_query_flag_for_order_creation(): void
    {
        $tool = $this->tool(array($this->json(200, array('code' => 200, 'result' => array('id' => 5)))));

        $tool->execute(
            $this->args(
                array(
                    'action' => 'create_order',
                    'confirm' => true,
                    'payload' => array('recipient' => array('name' => 'John')),
                )
            ),
            self::CONTEXT
        );

        $this->assertStringContainsString('?confirm=1', $this->requests[0]['url']);
    }

    public function test_size_guide_appends_the_unit_and_the_suffix(): void
    {
        $tool = $this->tool(array($this->json(200, array('code' => 200, 'result' => array()))));

        $tool->execute(
            $this->args(array('action' => 'get_product_size_guide', 'product_id' => 71, 'unit' => 'cm')),
            self::CONTEXT
        );

        $this->assertSame(
            'https://api.printful.com/products/71/sizes?unit=cm',
            $this->requests[0]['url']
        );
    }

    public function test_list_paging_is_capped_at_the_provider_limit(): void
    {
        $tool = $this->tool(array($this->json(200, array('code' => 200, 'result' => array()))));

        $tool->execute($this->args(array('action' => 'list_orders', 'limit' => 500)), self::CONTEXT);

        $this->assertStringContainsString('limit=100', $this->requests[0]['url']);
    }

    // ─── Statistics cap ──────────────────────────────────────────────

    public function test_a_statistics_range_longer_than_six_months_is_refused(): void
    {
        $tool = $this->tool(array());

        $result = $tool->execute(
            $this->args(
                array(
                    'action' => 'get_statistics',
                    'date_from' => '2026-01-01',
                    'date_to' => '2026-08-01',
                    'report_types' => array('profit'),
                )
            ),
            self::CONTEXT
        );

        // The WordPress schema documented the cap and never enforced it.
        $this->assertSame('validation_failed', $this->errorCode($result));
        $this->assertSame(array(), $this->requests);
    }

    public function test_a_statistics_request_within_six_months_is_sent(): void
    {
        $tool = $this->tool(array($this->json(200, array('code' => 200, 'result' => array()))));

        $this->data(
            $tool->execute(
                $this->args(
                    array(
                        'action' => 'get_statistics',
                        'date_from' => '2026-01-01',
                        'date_to' => '2026-06-30',
                        'report_types' => array('sales_and_costs', 'profit'),
                    )
                ),
                self::CONTEXT
            )
        );

        $this->assertStringContainsString('report_types=sales_and_costs%2Cprofit', $this->requests[0]['url']);
        $this->assertStringContainsString('date_from=2026-01-01', $this->requests[0]['url']);
    }

    public function test_statistics_without_report_types_is_refused(): void
    {
        $tool = $this->tool(array());

        $result = $tool->execute(
            $this->args(
                array(
                    'action' => 'get_statistics',
                    'date_from' => '2026-01-01',
                    'date_to' => '2026-01-31',
                )
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $this->errorCode($result));
    }

    // ─── Guard rails ─────────────────────────────────────────────────

    public function test_an_unknown_action_is_refused(): void
    {
        $tool = $this->tool(array());

        $this->assertSame(
            'validation_failed',
            $this->errorCode($tool->execute($this->args(array('action' => 'get_webhooks')), self::CONTEXT))
        );
    }

    public function test_a_transport_failure_is_reported_not_thrown(): void
    {
        $tool = $this->tool(array(new \RuntimeException('dns failure')));

        $result = $tool->execute($this->args(array('action' => 'list_orders')), self::CONTEXT);

        $this->assertSame('printful_unreachable', $this->errorCode($result));
    }

    public function test_a_missing_user_cannot_call_printful(): void
    {
        $this->expectException(AccessDeniedException::class);

        $tool = $this->tool(array());

        $tool->execute(array('action' => 'list_countries'), array());
    }

    public function test_the_tool_declares_its_credentials_and_writes(): void
    {
        $tool = $this->tool(array());

        $flags = $tool->getCapabilityFlags();

        sort($flags);

        $this->assertSame(
            array('external-api', 'network-dependent', 'requires-credentials', 'write'),
            $flags
        );
        $this->assertSame('printful', $tool->getSlug());
        $this->assertSame('v1', PrintfulTool::API_VERSION);
    }
}
