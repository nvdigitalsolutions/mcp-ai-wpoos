<?php

/**
 * The Cloudways client: what it sends, what it caches, what it refuses.
 *
 * The interesting assertions are about the token lifecycle, because that is
 * where the WordPress client's behaviour lives and where a port silently
 * diverges. A cached token must not trigger an exchange; a token inside the
 * sixty-second buffer must; an exchange must be form-encoded with the two
 * credential fields; and the new token must land in settings so the next
 * request reuses it.
 *
 * The transport is a recorder rather than a mock: an anonymous class collects
 * every `send()` and returns the queued responses in order, so a test can
 * assert the sequence and the exact request that left the building.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\Cloudways;

use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Error\CloudwaysApiException;
use Nvoos\Core\Infrastructure\Cloudways\CloudwaysClient;
use PHPUnit\Framework\TestCase;

final class CloudwaysClientTest extends TestCase
{
    private const BASE = 'https://cloudways.test/api/v2';

    private const OAUTH = 'https://cloudways.test/api/v2/oauth/access_token';

    public function test_a_client_without_credentials_refuses_before_any_request(): void
    {
        $settings = $this->settings();
        $http     = $this->http();

        $client = $this->client($settings, $http);

        $this->assertFalse($client->isConfigured());

        try {
            $client->get('/server');
            $this->fail('a request without credentials must not be attempted.');
        } catch (CloudwaysApiException $e) {
            $this->assertSame('cloudways_not_configured', $e->errorCode);
        }

        $this->assertCount(0, $http->calls);
    }

    public function test_a_cached_token_is_used_without_exchanging_credentials(): void
    {
        $settings = $this->settings(
            array(
                'cloudways_email'              => 'ops@example.test',
                'cloudways_api_key'            => 'key-1',
                'cloudways_access_token'       => 'cached-token',
                'cloudways_token_expires_at'   => time() + 3600,
            )
        );
        $http = $this->http(array($this->json(200, array('servers' => array()))));

        $result = $this->client($settings, $http)->get('/server');

        $this->assertSame(array('servers' => array()), $result);
        $this->assertCount(1, $http->calls);
        $this->assertSame('GET', $http->calls[0]['method']);
        $this->assertSame(self::BASE . '/server', $http->calls[0]['url']);
        $this->assertSame('Bearer cached-token', $http->calls[0]['headers']['Authorization']);
    }

    public function test_an_expired_token_is_exchanged_and_cached(): void
    {
        $settings = $this->settings(
            array(
                'cloudways_email'            => 'ops@example.test',
                'cloudways_api_key'          => 'key-1',
                'cloudways_access_token'     => 'stale-token',
                'cloudways_token_expires_at' => time() - 10,
            )
        );

        $http = $this->http(
            array(
                $this->json(200, array('access_token' => 'fresh-token', 'expires_in' => 3600)),
                $this->json(200, array('servers' => array())),
            )
        );

        $this->client($settings, $http)->get('/server');

        $this->assertCount(2, $http->calls);
        $this->assertSame('POST', $http->calls[0]['method']);
        $this->assertSame(self::OAUTH, $http->calls[0]['url']);
        $this->assertSame('application/x-www-form-urlencoded', $http->calls[0]['headers']['Content-Type']);
        $this->assertStringContainsString('email=ops%40example.test', (string) $http->calls[0]['body']);
        $this->assertStringContainsString('api_key=key-1', (string) $http->calls[0]['body']);

        $this->assertSame('Bearer fresh-token', $http->calls[1]['headers']['Authorization']);
        $this->assertSame('fresh-token', $settings->data['cloudways_access_token']);
        $this->assertGreaterThan(time() + 3000, (int) $settings->data['cloudways_token_expires_at']);
        $this->assertTrue($settings->data['cloudways_connected']);
    }

    public function test_a_token_inside_the_safety_buffer_is_refreshed(): void
    {
        $settings = $this->settings(
            array(
                'cloudways_email'            => 'ops@example.test',
                'cloudways_api_key'          => 'key-1',
                'cloudways_access_token'     => 'almost-expired',
                'cloudways_token_expires_at' => time() + 30,
            )
        );

        $http = $this->http(
            array(
                $this->json(200, array('access_token' => 'fresh-token', 'expires_in' => 3600)),
                $this->json(200, array('servers' => array())),
            )
        );

        $this->client($settings, $http)->get('/server');

        $this->assertCount(2, $http->calls);
        $this->assertSame('Bearer fresh-token', $http->calls[1]['headers']['Authorization']);
    }

    public function test_a_rejected_exchange_is_reported_as_auth_failed(): void
    {
        $settings = $this->settings(
            array('cloudways_email' => 'ops@example.test', 'cloudways_api_key' => 'wrong')
        );

        $http = $this->http(array($this->json(401, array('error_description' => 'Bad credentials'))));

        try {
            $this->client($settings, $http)->get('/server');
            $this->fail('a failed exchange must throw.');
        } catch (CloudwaysApiException $e) {
            $this->assertSame('cloudways_auth_failed', $e->errorCode);
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('Bad credentials', $e->getMessage());
        }

        $this->assertArrayNotHasKey('cloudways_access_token', $settings->data);
    }

    public function test_api_errors_are_classified_by_status(): void
    {
        $cases = array(
            array(401, 'cloudways_unauthorized'),
            array(403, 'cloudways_api_error'),
            array(404, 'cloudways_not_found'),
            array(429, 'cloudways_rate_limited'),
            array(500, 'cloudways_api_error'),
        );

        foreach ($cases as $case) {
            $settings = $this->settings(
                array(
                    'cloudways_email'            => 'ops@example.test',
                    'cloudways_api_key'          => 'key-1',
                    'cloudways_access_token'     => 'token',
                    'cloudways_token_expires_at' => time() + 3600,
                )
            );

            $http = $this->http(array($this->json($case[0], array('message' => 'nope'))));

            try {
                $this->client($settings, $http)->get('/server');
                $this->fail('HTTP ' . $case[0] . ' must throw.');
            } catch (CloudwaysApiException $e) {
                $this->assertSame($case[1], $e->errorCode, 'HTTP ' . $case[0]);
                $this->assertSame($case[0], $e->statusCode);
                $this->assertSame('nope', $e->getMessage());
            }
        }
    }

    public function test_a_transport_failure_is_reported_as_a_request_failure(): void
    {
        $settings = $this->settings(
            array(
                'cloudways_email'            => 'ops@example.test',
                'cloudways_api_key'          => 'key-1',
                'cloudways_access_token'     => 'token',
                'cloudways_token_expires_at' => time() + 3600,
            )
        );

        $http = $this->http();
        $http->throws = new \RuntimeException('connection refused');

        try {
            $this->client($settings, $http)->get('/server');
            $this->fail('a transport failure must throw.');
        } catch (CloudwaysApiException $e) {
            $this->assertSame('cloudways_request_failed', $e->errorCode);
            $this->assertSame(0, $e->statusCode);
            $this->assertStringContainsString('connection refused', $e->getMessage());
        }
    }

    public function test_a_post_carries_a_json_body_and_content_type(): void
    {
        $settings = $this->settings(
            array(
                'cloudways_email'            => 'ops@example.test',
                'cloudways_api_key'          => 'key-1',
                'cloudways_access_token'     => 'token',
                'cloudways_token_expires_at' => time() + 3600,
            )
        );

        $http = $this->http(array($this->json(200, array('operation_id' => 7))));

        $this->client($settings, $http)->post('/app', array('server_id' => 1, 'application' => 'wordpress'));

        $this->assertSame('POST', $http->calls[0]['method']);
        $this->assertSame('application/json', $http->calls[0]['headers']['Content-Type']);
        $this->assertSame(
            array('server_id' => 1, 'application' => 'wordpress'),
            json_decode((string) $http->calls[0]['body'], true)
        );
    }

    public function test_query_parameters_are_appended_to_the_url(): void
    {
        $settings = $this->settings(
            array(
                'cloudways_email'            => 'ops@example.test',
                'cloudways_api_key'          => 'key-1',
                'cloudways_access_token'     => 'token',
                'cloudways_token_expires_at' => time() + 3600,
            )
        );

        $http = $this->http(array($this->json(200, array('apps' => array()))));

        $this->client($settings, $http)->get('/app', array('server_id' => 42, 'app_id' => 9));

        $this->assertSame(self::BASE . '/app?server_id=42&app_id=9', $http->calls[0]['url']);
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    private function client(SettingsStoreInterface $settings, HttpClientInterface $http): CloudwaysClient
    {
        return new CloudwaysClient($settings, $http, self::BASE, self::OAUTH);
    }

    private function json(int $status, array $payload): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($payload));
    }

    private function settings(array $initial = array()): SettingsStoreInterface
    {
        return new class ($initial) implements SettingsStoreInterface {
            /** @var array<string, mixed> */
            public array $data;

            public function __construct(array $initial)
            {
                $this->data = $initial;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function all(): array
            {
                return $this->data;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->data[$key]);
            }

            public function getDefaultProvider(): string
            {
                return 'openai';
            }

            public function getDefaultModel(): string
            {
                return 'gpt-test';
            }

            public function getApiKey(string $provider): ?string
            {
                return null;
            }

            public function getApiBaseUrl(string $provider): ?string
            {
                return null;
            }

            public function getRequestTimeout(): int
            {
                return 30;
            }

            public function isEnabled(string $feature): bool
            {
                return true;
            }
        };
    }

    private function http(array $responses = array()): HttpClientInterface
    {
        return new class ($responses) implements HttpClientInterface {
            /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
            public array $calls = array();

            public ?\Throwable $throws = null;

            /**
             * @param list<HttpResponse> $responses
             */
            public function __construct(private array $responses)
            {
            }

            public function send(string $method, string $url, array $headers = array(), ?string $body = null): HttpResponse
            {
                $this->calls[] = compact('method', 'url', 'headers', 'body');

                if (null !== $this->throws) {
                    throw $this->throws;
                }

                $next = array_shift($this->responses);

                if (null === $next) {
                    throw new \RuntimeException('no queued response for ' . $method . ' ' . $url);
                }

                return $next;
            }
        };
    }
}
