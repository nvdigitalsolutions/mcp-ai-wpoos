<?php

/**
 * The last two Cloudways app-setting writes: CNAME and CORS.
 *
 * Proposal 164 section 164.137. What is pinned: the CNAME message actually names
 * the domain (WordPress's `sprintf` had no placeholder, so the value was escaped
 * and discarded), an obviously non-host value is refused before the request, and
 * the CORS body stays partial so an origins-only update leaves the methods and
 * headers alone.
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
use Nvoos\Core\Tool\Cloudways\AppCnameUpdateTool;
use Nvoos\Core\Tool\Cloudways\AppCorsHeadersUpdateTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CloudwaysAppDomainWritesTest extends TestCase
{
    private const CONTEXT = array('user_id' => 8);

    private const TARGET = array('server_id' => 3, 'app_id' => 4);

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

    // ─── CNAME ───────────────────────────────────────────────────────────

    public function test_the_cname_is_sent_and_named_in_the_message(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new AppCnameUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('cname' => '  www.example.com '),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('www.example.com', $result['data']['cname']);
        $this->assertSame(array('cname' => 'www.example.com'), $client->calls[0]['body']);
        $this->assertSame('/app/3/4/cname', $client->calls[0]['path']);
        $this->assertSame('CNAME updated to www.example.com.', $result['message']);
    }

    public function test_an_empty_cname_is_refused(): void
    {
        $client = $this->client(array());

        $result = (new AppCnameUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('cname' => '   '),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('cname', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notHosts(): array
    {
        return array(
            'scheme'    => array('https://example.com'),
            'path'      => array('example.com/blog'),
            'port'      => array('example.com:8080'),
            'whitespace' => array('two words.test'),
        );
    }

    #[DataProvider('notHosts')]
    public function test_a_pasted_url_is_refused_as_a_cname(string $value): void
    {
        $client = $this->client(array());

        $result = (new AppCnameUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('cname' => $value),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('cname', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── CORS ────────────────────────────────────────────────────────────

    public function test_an_origins_only_update_sends_one_field(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new AppCorsHeadersUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('allowed_origins' => 'https://a.test, https://b.test'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array('allowed_origins' => 'https://a.test, https://b.test'),
            $client->calls[0]['body']
        );
        $this->assertSame('/app/3/4/cors', $client->calls[0]['path']);
        $this->assertSame('CORS headers updated for app 4.', $result['message']);
    }

    public function test_all_three_lists_are_sent_when_provided(): void
    {
        $client = $this->client(array('ok' => true));

        (new AppCorsHeadersUpdateTool($this->errors, $client))->execute(
            self::TARGET + array(
                'allowed_origins' => '*',
                'allowed_methods' => 'GET, POST',
                'allowed_headers' => 'Content-Type',
            ),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                'allowed_origins' => '*',
                'allowed_methods' => 'GET, POST',
                'allowed_headers' => 'Content-Type',
            ),
            $client->calls[0]['body']
        );
    }

    public function test_an_empty_origins_list_is_refused(): void
    {
        $client = $this->client(array());

        $result = (new AppCorsHeadersUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('allowed_origins' => ''),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('allowed_origins', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_an_api_refusal_keeps_its_classification(): void
    {
        $client         = $this->client(array());
        $client->throws = new CloudwaysApiException('Refused', 'cloudways_forbidden', 403);

        $result = (new AppCorsHeadersUpdateTool($this->errors, $client))->execute(
            self::TARGET + array('allowed_origins' => '*'),
            self::CONTEXT
        );

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
