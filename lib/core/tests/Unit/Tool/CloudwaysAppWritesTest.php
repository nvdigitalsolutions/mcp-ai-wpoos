<?php

/**
 * The three Cloudways app-settings writes: PHP-FPM, Varnish and cache purge.
 *
 * Proposal 164 section 164.136. What is pinned: the body is partial, so an
 * update never resets a field the caller did not name; a provided-but-unusable
 * value is named rather than rewritten (WordPress's `absint()` flipped a
 * negative into a positive and dropped a zero); and an empty body is refused
 * before the request.
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
use Nvoos\Core\Domain\Error\CloudwaysApiException;
use Nvoos\Core\Tool\Cloudways\FpmSettingsUpdateTool;
use Nvoos\Core\Tool\Cloudways\PurgeAppCacheTool;
use Nvoos\Core\Tool\Cloudways\VarnishSettingsUpdateTool;
use PHPUnit\Framework\TestCase;

final class CloudwaysAppWritesTest extends TestCase
{
    private const CONTEXT = array('user_id' => 2);

    private const TARGET = array('server_id' => 5, 'app_id' => 6);

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

    public function test_fpm_sends_only_the_fields_the_caller_provided(): void
    {
        $client = $this->client(array('operation_id' => 'op-1'));

        $result = (new FpmSettingsUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('max_children' => 20, 'memory_limit' => '512M'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array('max_children' => 20, 'memory_limit' => '512M'),
            $client->calls[0]['body']
        );
        $this->assertSame('put', $client->calls[0]['verb']);
        $this->assertSame('/app/5/6/fpm/settings', $client->calls[0]['path']);
        $this->assertSame(array('operation_id' => 'op-1'), $result['data']['result']);
        $this->assertSame('PHP-FPM settings updated for app 6.', $result['message']);
    }

    public function test_a_negative_fpm_count_is_named_not_flipped(): void
    {
        $client = $this->client(array());

        $result = (new FpmSettingsUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('max_children' => -20),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('max_children', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_zero_fpm_count_is_named_not_dropped(): void
    {
        $client = $this->client(array());

        $result = (new FpmSettingsUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('max_requests' => 0),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('max_requests', $result['error']['data']);
    }

    public function test_an_empty_memory_limit_is_named(): void
    {
        $result = (new FpmSettingsUpdateTool($this->errors, $this->client(array())))->execute(
            self::TARGET + array('memory_limit' => ''),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('memory_limit', $result['error']['data']);
    }

    public function test_an_empty_fpm_body_is_refused_before_the_request(): void
    {
        $client = $this->client(array());

        $result = (new FpmSettingsUpdateTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_no_settings', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── Varnish ─────────────────────────────────────────────────────────

    public function test_varnish_sends_the_ttl_only(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new VarnishSettingsUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('ttl' => 3600),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('ttl' => 3600), $client->calls[0]['body']);
        $this->assertSame('/app/5/6/varnish/settings', $client->calls[0]['path']);
        $this->assertSame('Varnish settings updated for app 6.', $result['message']);
    }

    public function test_a_negative_ttl_is_named(): void
    {
        $client = $this->client(array());

        $result = (new VarnishSettingsUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('ttl' => -1),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('ttl', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_missing_ttl_is_refused(): void
    {
        $result = (new VarnishSettingsUpdateTool($this->errors, $this->client(array())))
            ->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_no_settings', $result['error']['code']);
    }

    // ─── Purge ───────────────────────────────────────────────────────────

    public function test_purge_posts_an_empty_body_to_the_cache_path(): void
    {
        $client = $this->client(array('operation_id' => 'op-2'));

        $result = (new PurgeAppCacheTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('post', $client->calls[0]['verb']);
        $this->assertSame('/app/5/6/cache/purge', $client->calls[0]['path']);
        $this->assertSame(array(), $client->calls[0]['body']);
        $this->assertSame('Cache purged for app 6.', $result['message']);
    }

    public function test_an_api_refusal_keeps_its_classification(): void
    {
        $client         = $this->client(array());
        $client->throws = new CloudwaysApiException('Refused', 'cloudways_forbidden', 403);

        $result = (new PurgeAppCacheTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_forbidden', $result['error']['code']);
        $this->assertSame(403, $result['error']['data']['status']);
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

            public ?\Throwable $throws = null;

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

                if (null !== $this->throws) {
                    throw $this->throws;
                }

                return $this->payload;
            }
        };
    }
}
