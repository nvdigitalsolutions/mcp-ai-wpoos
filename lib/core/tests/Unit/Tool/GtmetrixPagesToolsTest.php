<?php

/**
 * The GTmetrix page tools: read, list and retest.
 *
 * Proposal 164 section 164.96. The cases that matter most: the list limit is a
 * page size rather than a post-fetch slice, and a retest URL resolves through
 * the API with an exact match instead of a local snapshot map.
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
use Nvoos\Core\Tool\GTmetrix\GtmetrixGetPageTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixListPagesTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixRetestPageTool;
use PHPUnit\Framework\TestCase;

final class GtmetrixPagesToolsTest extends TestCase
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

    // ─── get_page ────────────────────────────────────────────────────────

    public function test_a_page_is_read_with_its_options_and_tags(): void
    {
        $result = ( new GtmetrixGetPageTool($this->errors, $this->client()) )
            ->execute(array('page_id' => 'PAGE1'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('PAGE1', $result['data']['page_id']);
        $this->assertSame('https://example.test/shop', $result['data']['url']);
        $this->assertSame('3', $result['data']['browser']);
        $this->assertSame('24', $result['data']['location']);
        $this->assertSame('REPORT1', $result['data']['latest_report']);
        $this->assertSame(1700000000, $result['data']['latest_report_time']);
        $this->assertSame(5, $result['data']['report_count']);
        $this->assertSame('daily', $result['data']['monitored']);
        $this->assertSame(array('TAG1', 'TAG2'), $result['data']['tags']);
    }

    public function test_a_datum_without_an_id_falls_back_to_the_requested_one(): void
    {
        $client            = $this->client();
        $client->responses = array(
            'pages/PAGE1' => array(
                'data' => array('type' => 'page', 'attributes' => array('url' => 'https://example.test/shop')),
            ),
        );

        $result = ( new GtmetrixGetPageTool($this->errors, $client) )
            ->execute(array('page_id' => 'PAGE1'), self::CONTEXT);

        $this->assertSame('PAGE1', $result['data']['page_id']);
    }

    public function test_an_invalid_page_id_is_refused_without_calling(): void
    {
        $client = $this->client();

        foreach (array('', '../pages', 'PAGE 1') as $id) {
            $result = ( new GtmetrixGetPageTool($this->errors, $client) )
                ->execute(array('page_id' => $id), self::CONTEXT);

            $this->assertSame('validation_failed', $result['error']['code'], $id);
        }

        $this->assertSame(array(), $client->calls);
    }

    public function test_a_missing_page_is_classified(): void
    {
        $client                        = $this->client();
        $client->failOn['pages/PNONE'] = new GtmetrixApiException(
            'GTmetrix page not found.',
            'gtmetrix_not_found',
            404,
            'E40402'
        );

        $result = ( new GtmetrixGetPageTool($this->errors, $client) )
            ->execute(array('page_id' => 'PNONE'), self::CONTEXT);

        $this->assertSame('gtmetrix_not_found', $result['error']['code']);
        $this->assertSame(404, $result['error']['data']['status']);
    }

    // ─── list_pages ──────────────────────────────────────────────────────

    public function test_the_list_sends_its_filters_and_page_size(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixListPagesTool($this->errors, $client) )->execute(
            array('url' => 'https://example.test/shop', 'monitored' => 'daily', 'limit' => 25),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            'pages?filter[url]=https%3A%2F%2Fexample.test%2Fshop&filter[monitored]=daily&page[size]=25',
            $client->calls[0]['path']
        );
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame('PAGE1', $result['data']['pages'][0]['page_id']);
        $this->assertSame(5, $result['data']['pages'][0]['report_count']);
        $this->assertSame(array('TAG1'), $result['data']['pages'][0]['tags']);
    }

    public function test_the_limit_is_a_page_size_with_a_ceiling(): void
    {
        $client = $this->client();

        ( new GtmetrixListPagesTool($this->errors, $client) )
            ->execute(array('limit' => 1000), self::CONTEXT);

        $this->assertStringEndsWith('page[size]=500', $client->calls[0]['path']);
    }

    public function test_an_unknown_monitoring_filter_is_refused(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixListPagesTool($this->errors, $client) )
            ->execute(array('monitored' => 'sometimes'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('monitored', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── retest_page ─────────────────────────────────────────────────────

    public function test_a_page_id_is_retested_and_the_credits_come_from_the_response(): void
    {
        $cache  = array();
        $result = $this->retest($this->client(), $cache)->execute(
            array('page_id' => 'PAGE1'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('PAGE1', $result['data']['page_id']);
        $this->assertSame('TEST9', $result['data']['test_id']);
        $this->assertSame('queued', $result['data']['state']);
        $this->assertSame(1.0, $result['data']['credits_used']);
        $this->assertSame(41.5, $result['data']['credits_left']);
        $this->assertSame(1.0, $result['data']['daily_budget']['used']);
        $this->assertSame(100, $cache['gtmetrix:credits_cents:' . gmdate('Ymd')]);
    }

    public function test_a_url_resolves_to_the_exact_page(): void
    {
        $cache  = array();
        $client = $this->client();

        $result = $this->retest($client, $cache)->execute(
            array('url' => 'https://example.test/shop'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('PAGE1', $result['data']['page_id']);
        $this->assertStringStartsWith('pages?filter[url]=', $client->calls[0]['path']);
        $this->assertSame('pages/PAGE1/retest', $client->calls[1]['path']);
        $this->assertSame('POST', $client->calls[1]['method']);
    }

    public function test_a_url_with_no_page_is_refused_with_candidates(): void
    {
        $cache  = array();
        $client = $this->client();

        $result = $this->retest($client, $cache)->execute(
            array('url' => 'https://example.test/nowhere'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_page_not_found', $result['error']['code']);
        $this->assertSame('https://example.test/nowhere', $result['error']['data']['url']);
        $this->assertSame('PAGE1', $result['error']['data']['candidates'][0]['page_id']);
        $this->assertCount(1, $client->calls);
        $this->assertSame(array(), $cache);
    }

    public function test_an_ambiguous_url_is_refused_rather_than_guessed(): void
    {
        $cache  = array();
        $client = $this->client();
        $client->responses['pages'] = array(
            'data' => array(
                array('type' => 'page', 'id' => 'PAGE1', 'attributes' => array('url' => 'https://example.test/shop')),
                array('type' => 'page', 'id' => 'PAGE2', 'attributes' => array('url' => 'https://EXAMPLE.test/shop')),
            ),
        );

        $result = $this->retest($client, $cache)->execute(
            array('url' => 'https://example.test/shop'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_page_ambiguous', $result['error']['code']);
        $this->assertSame(array('PAGE1', 'PAGE2'), $result['error']['data']['page_ids']);
        $this->assertCount(1, $client->calls);
    }

    public function test_a_host_outside_the_allowlist_is_refused(): void
    {
        $cache  = array();
        $client = $this->client();

        $result = $this->retest($client, $cache)->execute(
            array('url' => 'https://someone-else.test/shop'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_host_denied', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_the_daily_budget_refuses_before_the_retest(): void
    {
        $cache  = array('gtmetrix:credits_cents:' . gmdate('Ymd') => 2500);
        $client = $this->client();

        $result = $this->retest($client, $cache, array('gtmetrix_credit_budget_daily' => 25))
            ->execute(array('page_id' => 'PAGE1'), self::CONTEXT);

        $this->assertSame('gtmetrix_budget_exhausted', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
        $this->assertSame(2500, $cache['gtmetrix:credits_cents:' . gmdate('Ymd')]);
    }

    public function test_a_refused_retest_releases_the_reservation(): void
    {
        $cache                   = array();
        $client                  = $this->client();
        $client->failOn['pages/PAGE1/retest'] = new GtmetrixApiException(
            'Too many tests pending.',
            'gtmetrix_too_many_tests',
            429,
            'E42900'
        );

        $result = $this->retest($client, $cache)->execute(
            array('page_id' => 'PAGE1'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_too_many_tests', $result['error']['code']);
        $this->assertSame(0, $cache['gtmetrix:credits_cents:' . gmdate('Ymd')]);
    }

    public function test_a_reply_without_a_test_id_releases_the_reservation(): void
    {
        $cache            = array();
        $client           = $this->client();
        $client->responses['pages/PAGE1/retest'] = array(
            'status' => 202,
            'data'   => array('type' => 'test', 'attributes' => array()),
        );

        $result = $this->retest($client, $cache)->execute(
            array('page_id' => 'PAGE1'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_invalid_response', $result['error']['code']);
        $this->assertSame(0, $cache['gtmetrix:credits_cents:' . gmdate('Ymd')]);
    }

    public function test_a_page_id_or_a_url_is_required(): void
    {
        $cache  = array();
        $client = $this->client();

        $result = $this->retest($client, $cache)->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('page_id', $result['error']['data']);
        $this->assertArrayHasKey('url', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function retest(
        FakeGtmetrixClient $client,
        array &$cache,
        array $settings = array()
    ): GtmetrixRetestPageTool {
        return new GtmetrixRetestPageTool(
            $this->errors,
            $client,
            $this->cacheStore($cache),
            $this->settingsStore($settings),
            $this->identity()
        );
    }

    private function client(): FakeGtmetrixClient
    {
        $client            = new FakeGtmetrixClient();
        $client->responses = array(
            'pages/PAGE1' => array(
                'data' => array(
                    'type'       => 'page',
                    'id'         => 'PAGE1',
                    'attributes' => array(
                        'url'                => 'https://example.test/shop',
                        'browser'            => '3',
                        'location'           => '24',
                        'latest_report'      => 'REPORT1',
                        'latest_report_time' => 1700000000,
                        'report_count'       => 5,
                        'monitored'          => 'daily',
                        'page_tags'          => array('TAG1', 'TAG2'),
                    ),
                ),
            ),
            'pages'        => array(
                'data' => array(
                    array(
                        'type'       => 'page',
                        'id'         => 'PAGE1',
                        'attributes' => array(
                            'url'          => 'https://example.test/shop',
                            'latest_report' => 'REPORT1',
                            'report_count' => 5,
                            'monitored'    => 'daily',
                            'page_tags'    => array('TAG1'),
                        ),
                    ),
                    array(
                        'type'       => 'page',
                        'id'         => 'PAGE2',
                        'attributes' => array(
                            'url'          => 'https://example.test/shop/other',
                            'report_count' => 1,
                            'monitored'    => 'no',
                        ),
                    ),
                    'not-a-resource',
                ),
            ),
            'pages/PAGE1/retest' => array(
                'status' => 202,
                'data'   => array('type' => 'test', 'id' => 'TEST9', 'attributes' => array('state' => 'queued')),
                'meta'   => array('credits_used' => 1.0, 'credits_left' => 41.5),
            ),
        );

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
