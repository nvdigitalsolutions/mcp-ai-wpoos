<?php

namespace Tests\Feature;

use App\Mcp\Client\LaravelHttpClient;
use App\Mcp\Client\McpAppClient;
use App\Mcp\Client\McpAppClientException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHttpClient;
use Tests\TestCase;

/**
 * The Laravel-side MCP Apps client.
 *
 * These are parity assertions against the shared core contract: the dialect
 * order, the `_meta` rule, the `{}` encoding and the discovery ordering all
 * come from `Nvoos\Core\Domain\Mcp`, so what is proved here is that this
 * runtime consumes them rather than re-deriving them.
 */
final class McpAppClientTest extends TestCase
{
    private function client(FakeHttpClient $http, bool $legacyHint = false): McpAppClient
    {
        return new McpAppClient($http, 'https://mcp.example.com/mcp', array(), $legacyHint);
    }

    public function test_handshake_falls_back_from_discover_to_initialize(): void
    {
        $http = new FakeHttpClient();
        $http->queue(400, 'Bad Request');
        $http->queue(200, array('result' => array('protocolVersion' => '2025-03-26')));

        $handshake = $this->client($http)->handshake();

        $this->assertSame('initialize', $handshake['method']);
        $this->assertSame('server/discover', $http->bodyAt(0)['method']);
        $this->assertSame('initialize', $http->bodyAt(1)['method']);
    }

    public function test_a_hinted_session_asks_for_initialize_first(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, array('result' => array('protocolVersion' => '2025-03-26')));

        $handshake = $this->client($http, true)->handshake();

        $this->assertSame('initialize', $handshake['method']);
        $this->assertCount(1, $http->requests);
    }

    public function test_a_non_dialect_failure_is_not_retried(): void
    {
        $http = new FakeHttpClient();
        $http->queue(401, 'Unauthorized');

        try {
            $this->client($http)->handshake();
            $this->fail('Expected an McpAppClientException.');
        } catch (McpAppClientException $error) {
            $this->assertSame(401, $error->status());
        }

        $this->assertCount(1, $http->requests, 'A 401 implies no dialect change, so it must not be retried.');
    }

    public function test_a_legacy_session_sends_no_meta_and_an_object_params(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, array('result' => array('protocolVersion' => '2025-03-26')));
        $http->queue(200, array('result' => array('tools' => array(array('name' => 'a')), 'ttlMs' => 900)));

        $client = $this->client($http, true);
        $client->handshake();
        $tools = $client->listTools();

        $this->assertCount(1, $tools);
        $this->assertSame(900, $client->lastToolsTtlMs());
        $this->assertStringNotContainsString('_meta', $http->rawBodyAt(1));
        $this->assertStringContainsString('"params":{}', $http->rawBodyAt(1));
    }

    public function test_a_stateless_session_carries_meta(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, array('result' => array('serverInfo' => array('name' => 'x'))));
        $http->queue(200, array('result' => array('tools' => array())));

        $client = $this->client($http);
        $client->handshake();
        $client->listTools();

        $params = $http->bodyAt(1)['params'];

        $this->assertArrayHasKey('_meta', $params);
        $this->assertSame(
            '2026-07-28',
            $params['_meta']['io.modelcontextprotocol/protocolVersion']
        );
    }

    public function test_call_tool_sends_empty_arguments_as_an_object(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, array('result' => array('protocolVersion' => '2025-03-26')));
        $http->queue(200, array('result' => array('content' => array())));

        $client = $this->client($http, true);
        $client->handshake();
        $client->callTool('get_weather');

        $this->assertStringContainsString('"arguments":{}', $http->rawBodyAt(1));
    }

    public function test_read_resource_sends_the_uri(): void
    {
        $http = new FakeHttpClient();
        $http->queue(200, array('result' => array('protocolVersion' => '2025-03-26')));
        $http->queue(200, array('result' => array('contents' => array())));

        $client = $this->client($http, true);
        $client->handshake();
        $client->readResource('ui://server/card');

        $this->assertSame('ui://server/card', $http->bodyAt(1)['params']['uri']);
    }

    public function test_discover_metadata_walks_the_shared_candidate_order(): void
    {
        $http = new FakeHttpClient();

        // The five RFC 8414 candidates on the MCP origin, all absent.
        for ($i = 0; $i < 5; $i++) {
            $http->queue(404, 'Not Found');
        }

        // RFC 9728 metadata naming one authorization server.
        $http->queue(200, array('authorization_servers' => array('https://id.example.com')));

        // That server's first RFC 8414 candidate answers.
        $http->queue(200, array(
            'authorization_endpoint' => 'https://id.example.com/authorize',
            'token_endpoint' => 'https://id.example.com/token',
        ));

        $metadata = $this->client($http)->discoverMetadata();

        $this->assertSame('https://id.example.com/authorize', $metadata['authorization_endpoint']);
        $this->assertSame(
            'https://id.example.com/.well-known/oauth-authorization-server',
            $http->requests[6]['url']
        );
    }

    public function test_discover_metadata_returns_null_when_nothing_answers(): void
    {
        // An exhausted fake throws, which the discovery path reads as a
        // transport failure — the same shape as an unreachable host.
        $this->assertNull($this->client(new FakeHttpClient())->discoverMetadata());
    }

    public function test_the_transport_adapter_returns_a_core_response(): void
    {
        Http::fake(array('*' => Http::response(array('ok' => true), 201, array('X-Test' => 'yes'))));

        $response = (new LaravelHttpClient())->send('POST', 'https://x.test/mcp', array(), '{}');

        $this->assertSame(201, $response->statusCode);
        $this->assertSame('yes', $response->headers['X-Test']);
    }
}
