<?php

/**
 * The GTmetrix client: what it sends, what it unwraps, what it refuses.
 *
 * The interesting assertions are the three the WordPress client got wrong: the
 * JSON:API `meta` block must survive, a `303` must be reported rather than
 * followed, and a rate limit must become a retryable classification instead of a
 * blocking sleep.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\Gtmetrix;

use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Error\GtmetrixApiException;
use Nvoos\Core\Infrastructure\Gtmetrix\GtmetrixClient;
use PHPUnit\Framework\TestCase;

final class GtmetrixClientTest extends TestCase
{
    private const BASE = 'https://gtmetrix.test/api/2.0/';

    public function test_a_client_without_a_key_refuses_before_any_request(): void
    {
        $calls = array();
        $client = $this->client('', array(), $calls);

        $this->assertFalse($client->isConfigured());

        try {
            $client->request('GET', 'status');
            $this->fail('a request without a key must not be attempted.');
        } catch (GtmetrixApiException $error) {
            $this->assertSame('gtmetrix_not_configured', $error->errorCode);
        }

        $this->assertCount(0, $calls);
    }

    public function test_a_read_is_basic_authenticated_and_accepts_json_api(): void
    {
        $calls  = array();
        $client = $this->client(
            'abc123',
            array(new HttpResponse(200, '{"data":{"type":"user","id":"abc123","attributes":{}}}')),
            $calls
        );

        $client->request('GET', 'status');

        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame(self::BASE . 'status', $calls[0]['url']);
        $this->assertSame('Basic ' . base64_encode('abc123:'), $calls[0]['headers']['Authorization']);
        $this->assertSame('application/vnd.api+json', $calls[0]['headers']['Accept']);
        $this->assertArrayNotHasKey('Content-Type', $calls[0]['headers']);
        $this->assertNull($calls[0]['body']);
    }

    public function test_a_write_carries_a_json_api_body(): void
    {
        $calls  = array();
        $client = $this->client('abc123', array(new HttpResponse(202, '{"data":{"type":"test","id":"t1"}}')), $calls);

        $client->request('POST', 'tests', array('data' => array('type' => 'test')));

        $this->assertSame('application/vnd.api+json', $calls[0]['headers']['Content-Type']);
        $this->assertSame('{"data":{"type":"test"}}', $calls[0]['body']);
    }

    public function test_the_meta_block_is_preserved(): void
    {
        $calls  = array();
        $client = $this->client(
            'abc123',
            array(
                new HttpResponse(
                    202,
                    '{"data":{"type":"test","id":"t1","attributes":{"state":"queued"}},"meta":{"credits_left":121.2,"credits_used":2.8}}'
                ),
            ),
            $calls
        );

        $response = $client->request('POST', 'tests', array('data' => array()));

        $this->assertSame(121.2, $response['meta']['credits_left']);
        $this->assertSame(2.8, $response['meta']['credits_used']);
    }

    public function test_a_303_is_reported_with_its_location_and_no_body(): void
    {
        $calls  = array();
        $client = $this->client(
            'abc123',
            array(new HttpResponse(303, '', array('Location' => '/api/2.0/reports/abc'))),
            $calls
        );

        $response = $client->request('GET', 'tests/t1');

        $this->assertSame(303, $response['status']);
        $this->assertSame('/api/2.0/reports/abc', $response['location']);
        $this->assertNull($response['data']);
    }

    public function test_a_rate_limit_is_classified_with_the_servers_retry_advice(): void
    {
        $calls  = array();
        $client = $this->client(
            'abc123',
            array(
                new HttpResponse(
                    429,
                    '{"errors":[{"status":"429","code":"E42901","title":"Rate limit exceeded","detail":"Slow down."}]}',
                    array('Retry-After' => '7')
                ),
            ),
            $calls
        );

        try {
            $client->request('GET', 'tests');
            $this->fail('a 429 must throw.');
        } catch (GtmetrixApiException $error) {
            $this->assertSame('gtmetrix_rate_limited', $error->errorCode);
            $this->assertSame(429, $error->statusCode);
            $this->assertSame('E42901', $error->apiCode);
            $this->assertSame(7, $error->retryAfter);
            $this->assertSame('Slow down.', $error->getMessage());
        }
    }

    public function test_an_unmapped_rejection_is_a_request_error_with_the_detail(): void
    {
        $calls  = array();
        $client = $this->client(
            'abc123',
            array(
                new HttpResponse(
                    400,
                    '{"errors":[{"status":"400","code":"E40009","title":"Invalid tag parameter","detail":"Tag name must be unique."}]}'
                ),
            ),
            $calls
        );

        try {
            $client->request('POST', 'page_tags', array('data' => array()));
            $this->fail('a rejected parameter must throw.');
        } catch (GtmetrixApiException $error) {
            $this->assertSame('gtmetrix_invalid_request', $error->errorCode);
            $this->assertSame('Tag name must be unique.', $error->getMessage());
        }
    }

    public function test_a_non_json_success_is_a_bad_response(): void
    {
        $calls  = array();
        $client = $this->client('abc123', array(new HttpResponse(200, 'not json')), $calls);

        try {
            $client->request('GET', 'status');
            $this->fail('a non-JSON body must throw.');
        } catch (GtmetrixApiException $error) {
            $this->assertSame('gtmetrix_bad_response', $error->errorCode);
        }
    }

    public function test_a_transport_failure_is_reported_as_a_request_failure(): void
    {
        $calls  = array();
        $client = $this->client('abc123', array(), $calls, new \RuntimeException('DNS is down'));

        try {
            $client->request('GET', 'status');
            $this->fail('a transport failure must throw.');
        } catch (GtmetrixApiException $error) {
            $this->assertSame('gtmetrix_request_failed', $error->errorCode);
            $this->assertStringContainsString('DNS is down', $error->getMessage());
        }
    }

    public function test_the_rate_limit_state_is_read_from_the_headers(): void
    {
        $calls  = array();
        $client = $this->client(
            'abc123',
            array(
                new HttpResponse(
                    200,
                    '{"data":[]}',
                    array(
                        'X-RateLimit-Limit'     => '240, 240;window=60',
                        'X-RateLimit-Remaining' => '239',
                        'X-RateLimit-Reset'     => '12',
                    )
                ),
            ),
            $calls
        );

        $response = $client->request('GET', 'locations');

        $this->assertSame(
            array('limit' => 240, 'remaining' => 239, 'reset' => 12),
            $response['rate_limit']
        );
    }

    /**
     * @param list<HttpResponse|\Throwable> $queue Responses in order.
     * @param list<array<string, mixed>>   $calls Recorded requests, by reference.
     */
    private function client(string $key, array $queue, array &$calls, ?\Throwable $failure = null): GtmetrixClient
    {
        $calls = array();

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('getApiKey')->willReturnCallback(
            static fn (string $provider): ?string => GtmetrixClient::PROVIDER_KEY === $provider ? $key : null
        );

        $http = $this->createMock(HttpClientInterface::class);
        $http->method('send')->willReturnCallback(
            function (string $method, string $url, array $headers = array(), ?string $body = null) use (&$calls, &$queue, $failure): HttpResponse {
                $calls[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);

                if (null !== $failure) {
                    throw $failure;
                }

                $next = array_shift($queue);

                return $next instanceof HttpResponse ? $next : new HttpResponse(200, '{}');
            }
        );

        return new GtmetrixClient($settings, $http, self::BASE);
    }
}
