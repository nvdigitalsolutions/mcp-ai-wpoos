<?php

/**
 * The three Cloudways app-settings reads: PHP-FPM, Varnish and cron.
 *
 * Proposal 164 section 164.134. What is pinned: the server/app pair is validated
 * before a request and names the missing argument, the projections keep the
 * types the writer tools accept so read-modify-write needs no second call, an
 * empty cron list is a valid answer while a missing block is not, and the path
 * carries both ids.
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
use Nvoos\Core\Tool\Cloudways\CronJobsTool;
use Nvoos\Core\Tool\Cloudways\FpmSettingsTool;
use Nvoos\Core\Tool\Cloudways\VarnishSettingsTool;
use PHPUnit\Framework\TestCase;

final class CloudwaysAppSettingsToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 3);

    private const TARGET = array('server_id' => 12, 'app_id' => 34);

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

    // ─── PHP-FPM ─────────────────────────────────────────────────────────

    public function test_fpm_settings_keep_the_writers_types(): void
    {
        $client = $this->client(
            array(
                'fpm_settings' => array(
                    'max_children'    => '25',
                    'max_requests'    => 500,
                    'memory_limit'    => ' 512M ',
                    'process_manager' => 'dynamic',
                    'extra'           => 'not-in-the-contract',
                ),
            )
        );

        $result = $this->fpm($client)->execute(self::TARGET, self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'max_children'    => 25,
                'max_requests'    => 500,
                'memory_limit'    => '512M',
                'process_manager' => 'dynamic',
            ),
            $result['data']['fpm_settings']
        );
        $this->assertSame('/app/12/34/fpm/settings', $client->calls[0]['path']);
    }

    public function test_a_missing_fpm_block_is_malformed(): void
    {
        $result = $this->fpm($this->client(array()))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
        $this->assertStringContainsString('fpm_settings', $result['error']['message']);
    }

    // ─── Varnish ─────────────────────────────────────────────────────────

    public function test_varnish_settings_project_four_values(): void
    {
        $client = $this->client(
            array(
                'varnish_settings' => array(
                    'ttl'             => '86400',
                    'enabled'         => '1',
                    'cacheable_paths' => '/blog',
                    'exclusions'      => '/cart',
                ),
            )
        );

        $result = $this->varnish($client)->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(
            array(
                'ttl'             => 86400,
                'enabled'         => true,
                'cacheable_paths' => '/blog',
                'exclusions'      => '/cart',
            ),
            $result['data']['varnish_settings']
        );
        $this->assertSame('/app/12/34/varnish/settings', $client->calls[0]['path']);
    }

    public function test_varnish_disabled_reads_as_false_not_as_the_string(): void
    {
        $client = $this->client(array('varnish_settings' => array('enabled' => '0')));

        $result = $this->varnish($client)->execute(self::TARGET, self::CONTEXT);

        $this->assertFalse($result['data']['varnish_settings']['enabled']);
        $this->assertSame(0, $result['data']['varnish_settings']['ttl']);
    }

    public function test_a_missing_varnish_block_is_malformed(): void
    {
        $result = $this->varnish($this->client(array()))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
    }

    // ─── Cron ────────────────────────────────────────────────────────────

    public function test_cron_jobs_are_projected_and_non_rows_dropped(): void
    {
        $client = $this->client(
            array(
                'cron_jobs' => array(
                    array('id' => '5', 'schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run'),
                    array('id' => 6),
                    'not-a-row',
                ),
            )
        );

        $result = $this->cron($client)->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(
            array(
                array('id' => 5, 'schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run'),
                array('id' => 6, 'schedule' => '', 'command' => ''),
            ),
            $result['data']['cron_jobs']
        );
        $this->assertSame('Found 2 cron jobs.', $result['message']);
        $this->assertSame('/app/12/34/cron', $client->calls[0]['path']);
    }

    public function test_an_empty_cron_list_is_a_valid_answer(): void
    {
        $result = $this->cron($this->client(array('cron_jobs' => array())))->execute(
            self::TARGET,
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['cron_jobs']);
        $this->assertSame('Found 0 cron jobs.', $result['message']);
    }

    public function test_a_missing_cron_block_is_malformed(): void
    {
        $result = $this->cron($this->client(array()))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
    }

    // ─── The pair guard ──────────────────────────────────────────────────

    public function test_a_missing_app_id_names_the_argument(): void
    {
        $client = $this->client(array('cron_jobs' => array()));

        $result = $this->cron($client)->execute(array('server_id' => 12), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('app_id', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_missing_server_id_names_the_argument(): void
    {
        $client = $this->client(array('fpm_settings' => array()));

        $result = $this->fpm($client)->execute(array('app_id' => 34), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('server_id', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function fpm(CloudwaysClientInterface $client): FpmSettingsTool
    {
        return new FpmSettingsTool($this->errors, $client);
    }

    private function varnish(CloudwaysClientInterface $client): VarnishSettingsTool
    {
        return new VarnishSettingsTool($this->errors, $client);
    }

    private function cron(CloudwaysClientInterface $client): CronJobsTool
    {
        return new CronJobsTool($this->errors, $client);
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
