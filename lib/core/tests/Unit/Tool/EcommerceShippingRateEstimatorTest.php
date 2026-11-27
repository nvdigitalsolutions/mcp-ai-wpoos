<?php

/**
 * The shipping rate estimator.
 *
 * Every test here corresponds to one of the five corrections in the tool's file
 * docblock, and each one fails against the WordPress original:
 *
 * 1. A run that cannot quote anything refuses instead of reporting a zero.
 * 2. Totals never add two currencies together.
 * 3. The V1 amount reported is the amount the provider charged — including
 *    `otherCost`, which the original sorted by and then failed to return.
 * 4. No carrier or service is invented; both come from the provider's row.
 * 5. Nothing is read from a plugin option or a connection store: credentials and
 *    the origin address are arguments, so their absence is a refusal before any
 *    request is spent.
 *
 * The HTTP client is a mock fed from a queue, so every assertion can also check
 * exactly what was sent.
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
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Tool\Ecommerce\ShippingRateEstimatorTool;
use PHPUnit\Framework\TestCase;

final class EcommerceShippingRateEstimatorTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const ITEMS = array(
        array('name' => 'Widget', 'length' => 4, 'width' => 4, 'height' => 4, 'weight_oz' => 10),
    );

    private const SHIP_TO = array('postal_code' => '90210', 'state' => 'CA', 'city' => 'Beverly Hills');

    private const SHIP_FROM = array('postal_code' => '10001', 'state' => 'NY', 'city' => 'New York');

    private ErrorFactoryInterface $errors;

    private HttpClientInterface $http;

    /**
     * Every request the tool made, in order.
     *
     * @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    private array $requests = array();

    /**
     * Responses to hand out, in order. A Throwable is thrown instead.
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
    private function tool(array $queue): ShippingRateEstimatorTool
    {
        $this->queue = $queue;

        return new ShippingRateEstimatorTool($this->errors, $this->http);
    }

    /**
     * @param array<mixed> $body JSON body.
     */
    private function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body), array());
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
     * The standalone credential set.
     *
     * @return array<string, mixed>
     */
    private function standaloneCredentials(): array
    {
        return array('api_key' => 'KEY-123', 'carrier_id' => 'se-999');
    }

    /**
     * A standalone rate response with two services.
     */
    private function standaloneRates(string $currency = 'usd'): HttpResponse
    {
        return $this->json(
            200,
            array(
                'rate_response' => array(
                    'rates' => array(
                        array(
                            'service_code' => 'usps_priority_mail',
                            'carrier_friendly_name' => 'USPS',
                            'shipping_amount' => array('amount' => 8.25, 'currency' => $currency),
                        ),
                        array(
                            'service_code' => 'usps_ground_advantage',
                            'carrier_friendly_name' => 'USPS',
                            'shipping_amount' => array('amount' => 6.10, 'currency' => $currency),
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * @param array<string, mixed> $extra Extra arguments merged over the base set.
     *
     * @return array<string, mixed>
     */
    private function standaloneArgs(array $extra = array()): array
    {
        return array_merge(
            array(
                'action' => 'estimate_rates',
                'provider' => 'shipstation_standalone',
                'items' => self::ITEMS,
                'ship_to' => self::SHIP_TO,
                'ship_from' => self::SHIP_FROM,
                'credentials' => $this->standaloneCredentials(),
            ),
            $extra
        );
    }

    /**
     * The decoded body of one recorded request.
     *
     * @return array<string, mixed>
     */
    private function requestBody(int $index): array
    {
        $this->assertArrayHasKey($index, $this->requests);

        $decoded = json_decode((string) $this->requests[$index]['body'], true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    // ─── Defect 1: no quote is a refusal, not a zero ─────────────────

    public function test_missing_credentials_are_refused_before_any_request(): void
    {
        $tool = $this->tool(array());

        $result = $tool->execute(
            $this->standaloneArgs(array('credentials' => array('api_key' => 'KEY-123'))),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $this->errorCode($result));
        $this->assertSame(array(), $this->requests, 'No request may be spent without credentials.');
    }

    public function test_a_missing_origin_is_refused_before_any_request(): void
    {
        $tool = $this->tool(array());

        $result = $tool->execute(
            $this->standaloneArgs(array('ship_from' => array('city' => 'New York'))),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $this->errorCode($result));
        $this->assertSame(array(), $this->requests);
    }

    public function test_every_package_failing_is_a_refusal_not_a_zero_total(): void
    {
        $tool = $this->tool(
            array($this->json(500, array('errors' => array(array('message' => 'carrier down')))))
        );

        $result = $tool->execute($this->standaloneArgs(), self::CONTEXT);

        $this->assertSame('shipping_rate_failed', $this->errorCode($result));
        $this->assertIsArray($result['error']['data']);
        $this->assertSame('carrier down', $result['error']['data']['attempts'][0]['reason']);
    }

    public function test_a_partial_failure_reports_both_sides(): void
    {
        // Two 6-inch cubes: 216 in^3 each, so the second does not fit the first
        // in an 8 x 8 x 6 box and a second package is opened.
        $items = array(
            array('name' => 'Cube', 'length' => 6, 'width' => 6, 'height' => 6, 'weight_oz' => 10, 'quantity' => 2),
        );

        $tool = $this->tool(
            array(
                $this->standaloneRates(),
                $this->json(422, array('errors' => array(array('message' => 'address rejected')))),
            )
        );

        $result = $tool->execute($this->standaloneArgs(array('items' => $items)), self::CONTEXT);
        $data = $this->data($result);

        $this->assertSame(2, $data['total_packages']);
        $this->assertSame(1, $data['rated_packages']);
        $this->assertSame(1, $data['unrated_packages']);
        $this->assertSame('address rejected', $data['unrated'][0]['reason']);
    }

    // ─── Defect 2: currencies are never summed together ──────────────

    public function test_totals_are_kept_per_currency(): void
    {
        $items = array(
            array('name' => 'Cube', 'length' => 6, 'width' => 6, 'height' => 6, 'weight_oz' => 10, 'quantity' => 2),
        );

        $tool = $this->tool(array($this->standaloneRates('usd'), $this->standaloneRates('eur')));

        $result = $tool->execute($this->standaloneArgs(array('items' => $items)), self::CONTEXT);
        $data = $this->data($result);

        $this->assertIsArray($data['currency_totals']);

        $totals = $data['currency_totals'];

        $this->assertArrayHasKey('USD', $totals);
        $this->assertArrayHasKey('EUR', $totals);
        $this->assertInstanceOf(Money::class, $totals['USD']);
        $this->assertInstanceOf(Money::class, $totals['EUR']);

        // The cheapest rate in each response is 6.10, and neither total carries
        // the other currency's amount.
        $this->assertSame(610, $totals['USD']->minorUnits);
        $this->assertSame(610, $totals['EUR']->minorUnits);
    }

    // ─── Defect 3: V1 reports what it charged ────────────────────────

    public function test_v1_reports_the_other_cost_it_sorted_by(): void
    {
        $tool = $this->tool(
            array(
                $this->json(
                    200,
                    array(
                        // 3.50 + 0.50 = 4.00, the cheapest by total.
                        array('shipmentCost' => 3.50, 'otherCost' => 0.50, 'serviceCode' => 'usps_priority_mail'),
                        // 4.10 + 0.00 = 4.10.
                        array('shipmentCost' => 4.10, 'otherCost' => 0.00, 'serviceCode' => 'usps_ground_advantage'),
                    )
                ),
            )
        );

        $result = $tool->execute(
            $this->standaloneArgs(
                array(
                    'provider' => 'shipstation_v1',
                    'credentials' => array('api_key' => 'KEY', 'api_secret' => 'SECRET'),
                )
            ),
            self::CONTEXT
        );

        $data = $this->data($result);

        $this->assertIsArray($data['packages']);

        $package = $data['packages'][0];

        $this->assertInstanceOf(Money::class, $package['rate']);
        $this->assertSame(400, $package['rate']->minorUnits, 'The charge is base plus other cost.');
        $this->assertInstanceOf(Money::class, $package['other_cost']);
        $this->assertSame(50, $package['other_cost']->minorUnits);
        $this->assertSame('usps_priority_mail', $package['service_code']);
    }

    // ─── Defect 4: carrier and service come from the provider ────────

    public function test_the_cheapest_rate_wins_and_its_carrier_is_reported(): void
    {
        $tool = $this->tool(array($this->standaloneRates()));

        $result = $tool->execute($this->standaloneArgs(), self::CONTEXT);
        $data = $this->data($result);

        $this->assertIsArray($data['packages']);

        $package = $data['packages'][0];

        $this->assertSame('usps_ground_advantage', $package['service_code']);
        $this->assertSame('USPS', $package['carrier']);
        $this->assertInstanceOf(Money::class, $package['rate']);
        $this->assertSame(610, $package['rate']->minorUnits);
        $this->assertSame('USD', $package['rate']->currency);
    }

    public function test_no_service_code_is_sent_unless_the_caller_restricts_one(): void
    {
        $tool = $this->tool(array($this->standaloneRates()));

        $tool->execute($this->standaloneArgs(), self::CONTEXT);

        $this->assertCount(1, $this->requests);
        $this->assertSame('https://api.shipengine.com/v1/rates', $this->requests[0]['url']);
        $this->assertSame('KEY-123', $this->requests[0]['headers']['API-Key']);

        $body = $this->requestBody(0);

        $this->assertSame(array('se-999'), $body['rate_options']['carrier_ids']);
        $this->assertArrayNotHasKey('service_code', $body['shipment']);
    }

    public function test_a_service_code_is_sent_when_the_caller_supplies_one(): void
    {
        $tool = $this->tool(array($this->standaloneRates()));

        $tool->execute(
            $this->standaloneArgs(
                array(
                    'credentials' => array(
                        'api_key' => 'KEY-123',
                        'carrier_id' => 'se-999',
                        'service_code' => 'usps_priority_mail',
                    ),
                )
            ),
            self::CONTEXT
        );

        $body = $this->requestBody(0);

        $this->assertSame('usps_priority_mail', $body['shipment']['service_code']);
    }

    // ─── Defect 5: no hidden stores, and the names are not ambiguous ──

    public function test_a_legacy_provider_name_is_refused_with_its_replacement(): void
    {
        $tool = $this->tool(array());

        $result = $tool->execute($this->standaloneArgs(array('provider' => 'shipengine')), self::CONTEXT);

        $this->assertSame('validation_failed', $this->errorCode($result));
        $this->assertIsArray($result['error']);
        $this->assertStringContainsString('shipstation_standalone', (string) $result['error']['message']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_the_wordpress_credential_argument_name_is_still_accepted(): void
    {
        $tool = $this->tool(array($this->standaloneRates()));

        $arguments = $this->standaloneArgs();
        unset($arguments['credentials']);
        $arguments['api_credentials'] = $this->standaloneCredentials();

        $this->data($tool->execute($arguments, self::CONTEXT));

        $this->assertCount(1, $this->requests);
    }

    // ─── Connection test ─────────────────────────────────────────────

    public function test_a_connection_test_reports_carriers_and_remaining_quota(): void
    {
        $tool = $this->tool(
            array(
                new HttpResponse(
                    200,
                    (string) json_encode(
                        array(
                            'carriers' => array(
                                array('carrier_id' => 'se-999', 'friendly_name' => 'USPS'),
                            ),
                        )
                    ),
                    array('X-RateLimit-Remaining' => '87', 'X-RateLimit-Limit' => '120')
                ),
            )
        );

        $data = $this->data(
            $tool->execute(
                array(
                    'action' => 'test_connection',
                    'provider' => 'shipstation_standalone',
                    'credentials' => array('api_key' => 'TEST_abc', 'carrier_id' => 'se-999'),
                ),
                self::CONTEXT
            )
        );

        $this->assertSame(1, $data['carriers_found']);
        $this->assertTrue($data['carrier_id_present']);
        $this->assertTrue($data['sandbox'], 'A TEST_ key is the standalone sandbox.');
        $this->assertSame(87, $data['rate_limits']['requests_remaining']);
        $this->assertSame(120, $data['rate_limits']['requests_limit']);
    }

    public function test_rejected_credentials_are_named(): void
    {
        $tool = $this->tool(array($this->json(401, array())));

        $result = $tool->execute(
            array(
                'action' => 'test_connection',
                'provider' => 'shipstation_standalone',
                'credentials' => $this->standaloneCredentials(),
            ),
            self::CONTEXT
        );

        $this->assertSame('shipping_rate_credentials_rejected', $this->errorCode($result));
    }

    public function test_a_transport_failure_is_reported_not_thrown(): void
    {
        $tool = $this->tool(array(new \RuntimeException('connection refused')));

        $result = $tool->execute($this->standaloneArgs(), self::CONTEXT);

        $this->assertSame('shipping_rate_failed', $this->errorCode($result));
    }

    // ─── Guard rails ─────────────────────────────────────────────────

    public function test_an_unknown_action_is_refused(): void
    {
        $tool = $this->tool(array());

        $result = $tool->execute(array('action' => 'estimate_order_rates'), self::CONTEXT);

        $this->assertSame('validation_failed', $this->errorCode($result));
    }

    public function test_a_missing_user_cannot_quote(): void
    {
        $this->expectException(AccessDeniedException::class);

        $tool = $this->tool(array());

        $tool->execute(array('action' => 'test_connection'), array());
    }

    public function test_the_tool_is_declared_read_only_and_credentialed(): void
    {
        $tool = $this->tool(array());

        $flags = $tool->getCapabilityFlags();

        sort($flags);

        $this->assertSame(
            array('external-api', 'network-dependent', 'read-only', 'requires-credentials'),
            $flags
        );
        $this->assertSame('shipping_rate_estimator', $tool->getSlug());
    }
}
