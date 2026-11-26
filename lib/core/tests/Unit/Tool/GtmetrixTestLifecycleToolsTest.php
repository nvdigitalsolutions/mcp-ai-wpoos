<?php

/**
 * The GTmetrix test lifecycle: starting a test and reading its state.
 *
 * Proposal 164 section 164.92. The two cases that matter most are the ones the
 * WordPress tools got wrong: credits must come from the response, and a `303`
 * to the report must be read as completion rather than followed into a payload
 * that has no state.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Error\GtmetrixApiException;
use Nvoos\Core\Tests\Support\FakeGtmetrixClient;
use Nvoos\Core\Tool\GTmetrix\GtmetrixGetTestStatusTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixStartTestTool;
use PHPUnit\Framework\TestCase;

final class GtmetrixTestLifecycleToolsTest extends TestCase
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

    // ─── start_test ──────────────────────────────────────────────────────

    public function test_a_test_is_started_with_the_requested_options(): void
    {
        $client = $this->client(
            array(
                'status' => array('data' => array('attributes' => array('api_credits' => 100))),
                'tests'  => array(
                    'status' => 202,
                    'data'   => array('type' => 'test', 'id' => 'test-1', 'attributes' => array('state' => 'queued')),
                    'meta'   => array('credits_left' => 99.0, 'credits_used' => 1.0),
                ),
            )
        );

        $cache  = array();
        $result = $this->tool($client, $cache)->execute(
            array('url' => 'https://example.test/page'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('test-1', $result['data']['test_id']);
        $this->assertSame('queued', $result['data']['state']);
        $this->assertSame(1.0, $result['data']['credits_used']);
        $this->assertSame(99.0, $result['data']['credits_left']);
        $this->assertSame(1.0, $result['data']['daily_budget']['used']);

        $body = $client->calls[1]['body'];
        $this->assertSame('POST', $client->calls[1]['method']);
        $this->assertSame('test', $body['data']['type']);
        $this->assertSame('https://example.test/page', $body['data']['attributes']['url']);
        $this->assertSame('lighthouse', $body['data']['attributes']['report']);
        $this->assertSame(1, $body['data']['attributes']['retention']);
        $this->assertArrayNotHasKey('video', $body['data']['attributes']);
    }

    public function test_the_cost_includes_retention_and_video(): void
    {
        $client = $this->client(
            array(
                'status' => array('data' => array('attributes' => array('api_credits' => 100))),
                'tests'  => array('status' => 202, 'data' => array('id' => 'test-1', 'attributes' => array())),
            )
        );

        $cache  = array();
        $result = $this->tool($client, $cache)->execute(
            array(
                'url'       => 'https://example.test/',
                'report'    => 'legacy',
                'retention' => 12,
                'video'     => true,
            ),
            self::CONTEXT
        );

        // 0.7 report + 0.9 retention + 0.9 video.
        $this->assertSame(2.5, $result['data']['credits_used']);
        $this->assertSame(2.5, $result['data']['daily_budget']['used']);
        $this->assertSame(250, $cache['gtmetrix:credits_cents:' . gmdate('Ymd')]);
    }

    public function test_insufficient_account_credits_refuse_before_starting(): void
    {
        $client = $this->client(
            array('status' => array('data' => array('attributes' => array('api_credits' => 0.5))))
        );

        $cache  = array();
        $result = $this->tool($client, $cache)->execute(
            array('url' => 'https://example.test/'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_insufficient_credits', $result['error']['code']);
        $this->assertCount(1, $client->calls);
        $this->assertSame(array(), $cache);
    }

    public function test_the_daily_budget_refuses_before_starting(): void
    {
        $client = $this->client(
            array('status' => array('data' => array('attributes' => array('api_credits' => 100))))
        );

        $cache = array('gtmetrix:credits_cents:' . gmdate('Ymd') => 2500);
        $result = $this->tool($client, $cache, array('gtmetrix_credit_budget_daily' => 25))
            ->execute(array('url' => 'https://example.test/'), self::CONTEXT);

        $this->assertSame('gtmetrix_budget_exhausted', $result['error']['code']);
        $this->assertCount(1, $client->calls);
        $this->assertSame(2500, $cache['gtmetrix:credits_cents:' . gmdate('Ymd')]);
    }

    public function test_a_host_outside_the_allowlist_is_refused(): void
    {
        $client = $this->client(array());
        $cache  = array();

        $result = $this->tool($client, $cache)->execute(
            array('url' => 'https://someone-else.test/'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_host_denied', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_the_configured_host_list_extends_the_site_host(): void
    {
        $client = $this->client(
            array(
                'status' => array('data' => array('attributes' => array('api_credits' => 100))),
                'tests'  => array('status' => 202, 'data' => array('id' => 'test-1', 'attributes' => array())),
            )
        );

        $cache  = array();
        $result = $this->tool($client, $cache, array('gtmetrix_allowed_test_hosts' => array('staging.example.test')))
            ->execute(array('url' => 'https://staging.example.test/'), self::CONTEXT);

        $this->assertTrue($result['success']);
    }

    public function test_an_invalid_url_is_refused(): void
    {
        $client = $this->client(array());
        $cache  = array();

        foreach (array('not a url', 'ftp://example.test/', '') as $url) {
            $result = $this->tool($client, $cache)->execute(array('url' => $url), self::CONTEXT);

            $this->assertSame('validation_failed', $result['error']['code']);
            $this->assertArrayHasKey('url', $result['error']['data']);
        }
    }

    public function test_unknown_enum_and_malformed_optional_values_are_refused(): void
    {
        $client = $this->client(array());
        $cache  = array();

        $cases = array(
            array(array('url' => 'https://example.test/', 'report' => 'video'), 'report'),
            array(array('url' => 'https://example.test/', 'retention' => 3), 'retention'),
            array(array('url' => 'https://example.test/', 'throttle' => 'fast'), 'throttle'),
            array(array('url' => 'https://example.test/', 'location' => 'bad id!'), 'location'),
        );

        foreach ($cases as $case) {
            $result = $this->tool($client, $cache)->execute($case[0], self::CONTEXT);

            $this->assertSame('validation_failed', $result['error']['code'], $case[1]);
            $this->assertArrayHasKey($case[1], $result['error']['data']);
        }
    }

    public function test_a_refused_start_releases_the_budget_reservation(): void
    {
        $client                     = $this->client(
            array('status' => array('data' => array('attributes' => array('api_credits' => 100))))
        );
        $client->failOn['tests'] = new GtmetrixApiException(
            'Too many tests pending.',
            'gtmetrix_too_many_tests',
            429,
            'E42900'
        );

        $cache  = array();
        $result = $this->tool($client, $cache)->execute(
            array('url' => 'https://example.test/'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_too_many_tests', $result['error']['code']);
        $this->assertSame(0, $cache['gtmetrix:credits_cents:' . gmdate('Ymd')]);
    }

    // ─── get_test_status ─────────────────────────────────────────────────

    public function test_a_queued_test_reports_its_state(): void
    {
        $client = $this->client(
            array(
                'tests/test-1' => array(
                    'data' => array(
                        'type'       => 'test',
                        'id'         => 'test-1',
                        'attributes' => array('state' => 'queued', 'url' => 'https://example.test/'),
                    ),
                ),
            )
        );

        $result = ( new GtmetrixGetTestStatusTool($this->errors, $client) )
            ->execute(array('test_id' => 'test-1'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('queued', $result['data']['state']);
        $this->assertSame('https://example.test/', $result['data']['url']);
        $this->assertArrayNotHasKey('report_id', $result['data']);
    }

    public function test_a_303_reports_completion_and_the_report_from_the_location(): void
    {
        $client = $this->client(
            array(
                'tests/test-1' => array(
                    'status'   => 303,
                    'data'     => null,
                    'location' => '/api/2.0/reports/report-9',
                ),
            )
        );

        $result = ( new GtmetrixGetTestStatusTool($this->errors, $client) )
            ->execute(array('test_id' => 'test-1'), self::CONTEXT);

        $this->assertSame('completed', $result['data']['state']);
        $this->assertSame('report-9', $result['data']['report_id']);
        $this->assertSame(303, $result['data']['http_status']);
    }

    public function test_a_flattened_200_with_a_report_attribute_also_completes(): void
    {
        $client = $this->client(
            array(
                'tests/test-1' => array(
                    'data' => array(
                        'attributes' => array('state' => 'completed', 'report' => 'report-7'),
                    ),
                ),
            )
        );

        $result = ( new GtmetrixGetTestStatusTool($this->errors, $client) )
            ->execute(array('test_id' => 'test-1'), self::CONTEXT);

        $this->assertSame('completed', $result['data']['state']);
        $this->assertSame('report-7', $result['data']['report_id']);
    }

    public function test_a_failed_test_reports_its_error(): void
    {
        $client = $this->client(
            array(
                'tests/test-1' => array(
                    'data' => array(
                        'attributes' => array(
                            'state' => 'error',
                            'error' => 'The page returned an error: 404 Not Found',
                        ),
                    ),
                ),
            )
        );

        $result = ( new GtmetrixGetTestStatusTool($this->errors, $client) )
            ->execute(array('test_id' => 'test-1'), self::CONTEXT);

        $this->assertSame('error', $result['data']['state']);
        $this->assertSame('The page returned an error: 404 Not Found', $result['data']['error']);
    }

    public function test_an_invalid_test_id_is_refused_without_calling(): void
    {
        $client = $this->client(array());

        foreach (array('', '../etc/passwd', 'test id') as $id) {
            $result = ( new GtmetrixGetTestStatusTool($this->errors, $client) )
                ->execute(array('test_id' => $id), self::CONTEXT);

            $this->assertSame('validation_failed', $result['error']['code']);
        }

        $this->assertSame(array(), $client->calls);
    }

    public function test_a_missing_test_is_classified(): void
    {
        $client               = $this->client(array());
        $client->failOn['tests/test-1'] = new GtmetrixApiException(
            'GTmetrix test not found.',
            'gtmetrix_not_found',
            404,
            'E40400'
        );

        $result = ( new GtmetrixGetTestStatusTool($this->errors, $client) )
            ->execute(array('test_id' => 'test-1'), self::CONTEXT);

        $this->assertSame('gtmetrix_not_found', $result['error']['code']);
        $this->assertSame(404, $result['error']['data']['status']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function tool(FakeGtmetrixClient $client, array &$cache, array $settings = array()): GtmetrixStartTestTool
    {
        return new GtmetrixStartTestTool(
            $this->errors,
            $client,
            $this->cacheStore($cache),
            $this->settingsStore($settings),
            $this->identity()
        );
    }

    /**
     * @param array<string, mixed> $payload Responses keyed by path.
     */
    private function client(array $payload): FakeGtmetrixClient
    {
        $client            = new FakeGtmetrixClient();
        $client->responses = $payload;

        return $client;
    }

    /**
     * @param array<string, mixed> $store Counter state, by reference.
     */
    private function cacheStore(array &$store): CacheStoreInterface
    {
        $cache = $this->createMock(CacheStoreInterface::class);

        $cache->method('getValue')->willReturnCallback(
            static function (string $key, mixed $default = null) use (&$store): mixed {
                return $store[ $key ] ?? $default;
            }
        );
        $cache->method('increment')->willReturnCallback(
            static function (string $key, int $by = 1, int $ttl = 3600) use (&$store): int {
                $store[ $key ] = (int) ( $store[ $key ] ?? 0 ) + $by;

                return $store[ $key ];
            }
        );
        $cache->method('setValue')->willReturnCallback(
            static function (string $key, mixed $value, int $ttl = 3600) use (&$store): bool {
                $store[ $key ] = $value;

                return true;
            }
        );

        return $cache;
    }

    /**
     * @param array<string, mixed> $values Settings values.
     */
    private function settingsStore(array $values): SettingsStoreInterface
    {
        $store = $this->createMock(SettingsStoreInterface::class);
        $store->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $values[ $key ] ?? $default
        );

        return $store;
    }

    private function identity(): RuntimeIdentityInterface
    {
        $identity = $this->createMock(RuntimeIdentityInterface::class);
        $identity->method('identity')->willReturn(
            array(
                'name'      => 'test',
                'version'   => '1.0.0',
                'runtime'   => 'laravel',
                'contract'  => 'mcp-ai/v1',
                'site_name' => 'Example',
                'site_url'  => 'https://example.test/',
                'api_base'  => 'https://example.test/api',
            )
        );

        return $identity;
    }
}
