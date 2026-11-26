<?php

/**
 * The five credit-free GTmetrix reads.
 *
 * Proposal 164 section 164.91. Each case pins the payload shape a caller sees and
 * the corrections that matter: an absent number stays `null` rather than zero, an
 * unknown enum value is refused instead of returning an empty list, and `limit`
 * becomes the API's `page[size]` instead of a slice applied after the fetch.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\GtmetrixApiException;
use Nvoos\Core\Tests\Support\FakeGtmetrixClient;
use Nvoos\Core\Tool\GTmetrix\GtmetrixAccountStatusTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixListBrowsersTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixListLocationsTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixListSimulatedDevicesTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixListTestsTool;
use PHPUnit\Framework\TestCase;

final class GtmetrixReadToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

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

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );
    }

    // ─── account status ──────────────────────────────────────────────────

    public function test_account_status_reports_credits_plan_and_flags(): void
    {
        $client = $this->client(
            'status',
            array(
                'data' => array(
                    'type'       => 'user',
                    'id'         => 'abc123',
                    'attributes' => array(
                        'api_credits'                         => 1497.5,
                        'api_refill'                          => 1767225600,
                        'api_refill_amount'                   => 10,
                        'account_type'                        => 'PRO',
                        'account_pro_analysis_options_access' => true,
                        'account_pro_locations_access'        => true,
                        'account_whitelabel_pdf_access'       => false,
                    ),
                ),
            )
        );

        $result = ( new GtmetrixAccountStatusTool($this->errors, $client) )->execute(array(), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(1497.5, $result['data']['api_credits']);
        $this->assertSame(1767225600, $result['data']['api_refill']);
        $this->assertSame(10, $result['data']['api_refill_amount']);
        $this->assertSame('PRO', $result['data']['account_type']);
        $this->assertTrue($result['data']['pro_analysis_options']);
        $this->assertTrue($result['data']['pro_locations']);
        $this->assertFalse($result['data']['whitelabel_pdf']);
        $this->assertSame('status', $client->calls[0]['path']);
    }

    public function test_an_absent_balance_is_null_not_zero(): void
    {
        $client = $this->client('status', array('data' => array('attributes' => array())));

        $result = ( new GtmetrixAccountStatusTool($this->errors, $client) )->execute(array(), self::CONTEXT);

        $this->assertNull($result['data']['api_credits']);
        $this->assertNull($result['data']['api_refill']);
        $this->assertNull($result['data']['api_refill_amount']);
        $this->assertSame('', $result['data']['account_type']);
    }

    // ─── locations ───────────────────────────────────────────────────────

    public function test_locations_are_projected_and_can_be_filtered_to_usable_ones(): void
    {
        $client = $this->client(
            'locations',
            array(
                'data' => array(
                    array(
                        'id'         => 'loc-1',
                        'type'       => 'location',
                        'attributes' => array(
                            'name'               => 'London, UK',
                            'region'             => 'Europe',
                            'account_has_access' => true,
                            'default'            => true,
                            'active_location'    => true,
                            'servers'            => 3,
                            'browsers'           => array('chrome', 'firefox'),
                        ),
                    ),
                    array(
                        'id'         => 'loc-2',
                        'type'       => 'location',
                        'attributes' => array(
                            'name'               => 'Sydney, Australia',
                            'region'             => 'Asia Pacific',
                            'account_has_access' => false,
                            'active_location'    => true,
                            'servers'            => 2,
                        ),
                    ),
                ),
            )
        );

        $all = ( new GtmetrixListLocationsTool($this->errors, $client) )->execute(array(), self::CONTEXT);

        $this->assertSame(2, $all['data']['count']);
        $this->assertSame('loc-1', $all['data']['locations'][0]['id']);
        $this->assertSame('Europe', $all['data']['locations'][0]['region']);
        $this->assertSame(3, $all['data']['locations'][0]['servers_up']);
        $this->assertSame(array('chrome', 'firefox'), $all['data']['locations'][0]['browsers']);
        $this->assertArrayNotHasKey('region_sort_order', $all['data']['locations'][0]);

        $usable = ( new GtmetrixListLocationsTool($this->errors, $client) )
            ->execute(array('available_only' => true), self::CONTEXT);

        $this->assertSame(1, $usable['data']['count']);
        $this->assertSame('loc-1', $usable['data']['locations'][0]['id']);
    }

    // ─── browsers ────────────────────────────────────────────────────────

    public function test_browsers_carry_their_feature_support(): void
    {
        $client = $this->client(
            'browsers',
            array(
                'data' => array(
                    array(
                        'id'         => 'chrome',
                        'attributes' => array(
                            'name'       => 'Chrome (Desktop)',
                            'platform'   => 'desktop',
                            'default'    => true,
                            'lighthouse' => true,
                            'throttle'   => true,
                            'video'      => true,
                            'cookies'    => true,
                            'dns'        => true,
                            'adblock'    => true,
                            'http_auth'  => true,
                            'user_agent' => true,
                        ),
                    ),
                ),
            )
        );

        $result = ( new GtmetrixListBrowsersTool($this->errors, $client) )->execute(array(), self::CONTEXT);

        $browser = $result['data']['browsers'][0];

        $this->assertSame('chrome', $browser['id']);
        $this->assertSame('desktop', $browser['platform']);
        $this->assertTrue($browser['is_default']);
        $this->assertTrue($browser['features']['lighthouse']);
        $this->assertFalse($browser['features']['filtering']);
        $this->assertSame(1, $result['data']['count']);
    }

    // ─── simulated devices ───────────────────────────────────────────────

    public function test_devices_can_be_filtered_by_category(): void
    {
        $client = $this->client(
            'simulated-devices',
            array(
                'data' => array(
                    array(
                        'id'         => 'iphone_16',
                        'attributes' => array(
                            'category'     => 'phone',
                            'name'         => 'Apple iPhone 15/16',
                            'manufacturer' => 'Apple',
                            'width'        => 393,
                            'height'       => 852,
                            'dppx'         => 3,
                        ),
                    ),
                    array(
                        'id'         => 'ipad_air',
                        'attributes' => array(
                            'category'     => 'tablet',
                            'name'         => 'Apple iPad Air 4/5',
                            'manufacturer' => 'Apple',
                            'width'        => 1180,
                            'height'       => 820,
                            'dppx'         => 2,
                        ),
                    ),
                ),
            )
        );

        $phones = ( new GtmetrixListSimulatedDevicesTool($this->errors, $client) )
            ->execute(array('category' => 'phone'), self::CONTEXT);

        $this->assertSame(1, $phones['data']['count']);
        $this->assertSame('iphone_16', $phones['data']['devices'][0]['id']);
        $this->assertSame(393, $phones['data']['devices'][0]['width']);
        $this->assertSame(3.0, $phones['data']['devices'][0]['dppx']);
    }

    public function test_an_unknown_category_is_refused_instead_of_returning_nothing(): void
    {
        $client = $this->client('simulated-devices', array('data' => array()));

        $result = ( new GtmetrixListSimulatedDevicesTool($this->errors, $client) )
            ->execute(array('category' => 'phablet'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('category', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── tests ───────────────────────────────────────────────────────────

    public function test_tests_are_projected_and_the_limit_becomes_the_page_size(): void
    {
        $client = $this->client(
            'tests',
            array(
                'data' => array(
                    array(
                        'id'         => 'test-1',
                        'attributes' => array(
                            'url'      => 'https://example.test/',
                            'state'    => 'completed',
                            'report'   => 'report-1',
                            'created'  => 1767225600,
                            'finished' => 1767225660,
                        ),
                    ),
                ),
            )
        );

        $result = ( new GtmetrixListTestsTool($this->errors, $client) )->execute(
            array('state' => 'completed', 'limit' => 10),
            self::CONTEXT
        );

        $this->assertSame(1, $result['data']['count']);
        $this->assertSame('test-1', $result['data']['tests'][0]['test_id']);
        $this->assertSame('report-1', $result['data']['tests'][0]['report_id']);
        $this->assertSame(1767225660, $result['data']['tests'][0]['finished']);

        $path = $client->calls[0]['path'];

        $this->assertStringStartsWith('tests?', $path);
        $this->assertStringContainsString('page%5Bsize%5D=10', $path);
        $this->assertStringContainsString('filter%5Bstate%5D=completed', $path);
    }

    public function test_an_unknown_state_is_refused(): void
    {
        $client = $this->client('tests', array('data' => array()));

        $result = ( new GtmetrixListTestsTool($this->errors, $client) )
            ->execute(array('state' => 'finished'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('state', $result['error']['data']);
    }

    public function test_a_limit_outside_the_bound_is_refused(): void
    {
        $client = $this->client('tests', array('data' => array()));

        foreach (array(0, GtmetrixListTestsTool::MAX_LIMIT + 1) as $limit) {
            $result = ( new GtmetrixListTestsTool($this->errors, $client) )
                ->execute(array('limit' => $limit), self::CONTEXT);

            $this->assertSame('validation_failed', $result['error']['code']);
            $this->assertArrayHasKey('limit', $result['error']['data']);
        }
    }

    // ─── shared refusal paths ────────────────────────────────────────────

    public function test_a_runtime_without_a_key_refuses_before_calling(): void
    {
        $client           = new FakeGtmetrixClient();
        $client->configured = false;

        $result = ( new GtmetrixAccountStatusTool($this->errors, $client) )->execute(array(), self::CONTEXT);

        $this->assertSame('gtmetrix_not_configured', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_an_api_failure_keeps_the_code_status_and_retry_advice(): void
    {
        $client       = new FakeGtmetrixClient();
        $client->fail = new GtmetrixApiException(
            'GTmetrix API rate limit exceeded.',
            'gtmetrix_rate_limited',
            429,
            'E42901',
            12
        );

        $result = ( new GtmetrixListBrowsersTool($this->errors, $client) )->execute(array(), self::CONTEXT);

        $this->assertSame('gtmetrix_rate_limited', $result['error']['code']);
        $this->assertSame(429, $result['error']['data']['status']);
        $this->assertSame('E42901', $result['error']['data']['api_code']);
        $this->assertSame(12, $result['error']['data']['retry_after']);
    }

    /**
     * A fake client answering one path.
     *
     * @param array<string, mixed> $payload Response row.
     */
    private function client(string $path, array $payload): FakeGtmetrixClient
    {
        $client                        = new FakeGtmetrixClient();
        $client->responses[ $path ] = $payload;

        return $client;
    }
}
