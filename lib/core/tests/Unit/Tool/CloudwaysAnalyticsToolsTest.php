<?php

/**
 * The five Cloudways analytics reads.
 *
 * Proposal 164 section 164.135. What is pinned: each tool requests its own path,
 * the two documented fields keep the types the WordPress toolkit gave them, and
 * `values` is additive — every scalar the block returned, with nested structures
 * left out rather than flattened into a shape nobody has seen.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\Cloudways\AppMonitorSummaryTool;
use Nvoos\Core\Tool\Cloudways\AppMysqlAnalyticsTool;
use Nvoos\Core\Tool\Cloudways\AppPhpAnalyticsTool;
use Nvoos\Core\Tool\Cloudways\AppTrafficAnalyticsTool;
use Nvoos\Core\Tool\Cloudways\ServerMonitorSummaryTool;
use PHPUnit\Framework\TestCase;

final class CloudwaysAnalyticsToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 4);

    private const TARGET = array('server_id' => 7, 'app_id' => 9);

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
    }

    public function test_the_app_monitoring_summary_projects_requests_and_bandwidth(): void
    {
        $client = $this->client(
            array('summary' => array('requests' => '12k', 'bandwidth' => '4.2 GB', 'uptime' => '99.9'))
        );

        $result = (new AppMonitorSummaryTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(
            array('requests' => '12k', 'bandwidth' => '4.2 GB'),
            $result['data']['summary']
        );
        $this->assertSame(
            array('requests' => '12k', 'bandwidth' => '4.2 GB', 'uptime' => '99.9'),
            $result['data']['values']
        );
        $this->assertSame('/app/7/9/monitor/summary', $client->calls[0]['path']);
        $this->assertSame('Monitoring summary retrieved for app 9.', $result['message']);
    }

    public function test_the_server_monitoring_summary_is_server_scoped(): void
    {
        $client = $this->client(
            array('summary' => array('bandwidth' => '1 TB', 'disk' => '20 GB', 'apps' => array('nested' => true)))
        );

        $result = (new ServerMonitorSummaryTool($this->errors, $client))->execute(
            array('server_id' => 7),
            self::CONTEXT
        );

        $this->assertSame(
            array('bandwidth' => '1 TB', 'disk' => '20 GB'),
            $result['data']['summary']
        );
        // A nested value is not flattened into the scalar map.
        $this->assertArrayNotHasKey('apps', $result['data']['values']);
        $this->assertSame('/server/7/monitor/summary', $client->calls[0]['path']);
    }

    public function test_mysql_analytics_are_counts(): void
    {
        $client = $this->client(
            array('mysql_analytics' => array('slow_queries' => '3', 'query_count' => 1900))
        );

        $result = (new AppMysqlAnalyticsTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(
            array('slow_queries' => 3, 'query_count' => 1900),
            $result['data']['mysql_analytics']
        );
        $this->assertSame('/app/7/9/analytics/mysql', $client->calls[0]['path']);
    }

    public function test_php_analytics_keep_one_string_and_one_count(): void
    {
        $client = $this->client(
            array('php_analytics' => array('response_time' => '120 ms', 'slow_pages' => '2'))
        );

        $result = (new AppPhpAnalyticsTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(
            array('response_time' => '120 ms', 'slow_pages' => 2),
            $result['data']['php_analytics']
        );
        $this->assertSame('/app/7/9/analytics/php', $client->calls[0]['path']);
    }

    public function test_traffic_analytics_project_visits_and_bandwidth(): void
    {
        $client = $this->client(
            array('traffic' => array('visits' => '800', 'bandwidth' => '2 GB'))
        );

        $result = (new AppTrafficAnalyticsTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(
            array('visits' => 800, 'bandwidth' => '2 GB'),
            $result['data']['traffic']
        );
        $this->assertSame('/app/7/9/analytics/traffic', $client->calls[0]['path']);
    }

    public function test_a_missing_block_is_malformed(): void
    {
        $result = (new AppTrafficAnalyticsTool($this->errors, $this->client(array())))
            ->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
        $this->assertStringContainsString('traffic', $result['error']['message']);
    }

    public function test_an_absent_app_id_is_refused_before_the_request(): void
    {
        $client = $this->client(array('traffic' => array()));

        $result = (new AppTrafficAnalyticsTool($this->errors, $client))
            ->execute(array('server_id' => 7), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('app_id', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    /**
     * A recorder client that answers the same payload for every verb.
     *
     * @param array<string, mixed> $payload Response body.
     */
    private function client(array $payload = array()): CloudwaysClientInterface
    {
        return new class ($payload) implements CloudwaysClientInterface {
            /** @var list<array{verb: string, path: string, query: array<string, mixed>, body: array<string, mixed>}> */
            public array $calls = array();

            /**
             * @param array<string, mixed> $payload Response body.
             */
            public function __construct(private array $payload)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function get(string $path, array $query = array()): array
            {
                return $this->record('get', $path, $query, array());
            }

            public function post(string $path, array $body = array()): array
            {
                return $this->record('post', $path, array(), $body);
            }

            public function put(string $path, array $body = array()): array
            {
                return $this->record('put', $path, array(), $body);
            }

            public function delete(string $path, array $body = array()): array
            {
                return $this->record('delete', $path, array(), $body);
            }

            /**
             * @param array<string, mixed> $query Query.
             * @param array<string, mixed> $body  Body.
             *
             * @return array<string, mixed>
             */
            private function record(string $verb, string $path, array $query, array $body): array
            {
                $this->calls[] = array('verb' => $verb, 'path' => $path, 'query' => $query, 'body' => $body);

                return $this->payload;
            }
        };
    }
}
