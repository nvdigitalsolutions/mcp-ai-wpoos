<?php

namespace App\Mcp\Client;

use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Mcp\McpHandshakePolicy;
use Nvoos\Core\Domain\Mcp\McpRequestEnvelope;
use Nvoos\Core\Domain\Mcp\OAuthDiscoveryCandidates;
use RuntimeException;

/**
 * Streamable-HTTP MCP client for the Laravel runtime.
 *
 * Consumes the shared MIT protocol layer in `lib/core`: the dialect policy,
 * the request envelopes and the OAuth discovery ordering all come from
 * `Nvoos\Core\Domain\Mcp`. What lives here is only what is runtime-specific —
 * the HTTP transport, the JSON-RPC envelope, and turning failures into typed
 * exceptions.
 *
 * This is the counterpart of `WP_MCP_AI_MCP_App_Client` (Proposal 090-I). The
 * two must agree on the wire; the shared classes are how that is guaranteed
 * rather than reviewed.
 *
 * The legacy-dialect hint is a constructor flag rather than a cache: the
 * WordPress client persists it in a transient, and a Laravel caller that wants
 * the same saving can persist it itself and pass it back. With no hint the
 * client probes `server/discover` first, falls back to `initialize` on a
 * legacy rejection, and never retries a failure that implies no dialect
 * change.
 */
final class McpAppClient
{
    /** Protocol version this client speaks. */
    public const PROTOCOL_VERSION = '2026-07-28';

    /** Monotonic JSON-RPC request id. */
    private int $requestId = 0;

    /** Version the session settled on; '' before a handshake. */
    private string $negotiatedProtocolVersion = '';

    /** The SEP-2549 freshness bound of the last `tools/list`. */
    private int $lastToolsTtlMs = 0;

    /**
     * @param HttpClientInterface  $http       Transport.
     * @param string               $serverUrl  Absolute MCP server URL.
     * @param array<string,string> $headers    Extra request headers (auth, tenancy).
     * @param bool                 $legacyHint Caller-persisted legacy-dialect hint.
     * @param string               $clientName Identity reported in `_meta`.
     * @param string               $clientVersion Version reported in `_meta`.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $serverUrl,
        private readonly array $headers = array(),
        private readonly bool $legacyHint = false,
        private readonly string $clientName = 'NV oOS Laravel Runtime',
        private readonly string $clientVersion = '1.0.0',
    ) {
    }

    /**
     * Negotiate the dialect.
     *
     * The order is the shared policy's, and the two fallbacks are what the
     * policy's classifiers decide:
     *
     *  - `initialize` failing with a stateless rejection means the server
     *    upgraded dialects, so the cached hint is stale and discover is next.
     *  - `server/discover` failing with a legacy rejection means the server
     *    predates 2026-07-28, so initialize is next.
     *  - Anything else (401, 429, a timeout, a 500) implies no dialect change
     *    and is surfaced as-is.
     *
     * @return array{method: string, result: array<string, mixed>}
     *
     * @throws McpAppClientException When neither dialect answers.
     */
    public function handshake(): array
    {
        if (McpHandshakePolicy::INITIALIZE === McpHandshakePolicy::firstAttempt($this->legacyHint)) {
            try {
                return array(
                    'method' => 'initialize',
                    'result' => $this->initialize(),
                );
            } catch (McpAppClientException $error) {
                if (!McpHandshakePolicy::isStatelessRejection($error->status(), $error->rpcCode())) {
                    throw $error;
                }
            }
        }

        try {
            return array(
                'method' => 'discover',
                'result' => $this->discover(),
            );
        } catch (McpAppClientException $error) {
            if (!McpHandshakePolicy::isLegacyRejection($error->status(), $error->rpcCode(), $error->getMessage())) {
                throw $error;
            }
        }

        return array(
            'method' => 'initialize',
            'result' => $this->initialize(),
        );
    }

    /**
     * Discover the tools the server exposes.
     *
     * @return array<int, mixed>
     *
     * @throws McpAppClientException
     */
    public function listTools(): array
    {
        $result = $this->sendRequest('tools/list', McpRequestEnvelope::toolsListParams());

        $this->lastToolsTtlMs = McpRequestEnvelope::toolsTtlMs($result);

        return McpRequestEnvelope::tools($result);
    }

    /**
     * The server-declared tools/list cache bound in milliseconds (SEP-2549).
     */
    public function lastToolsTtlMs(): int
    {
        return $this->lastToolsTtlMs;
    }

    /**
     * Execute a tool on the server.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     *
     * @throws McpAppClientException
     */
    public function callTool(string $name, array $arguments = array()): array
    {
        return $this->sendRequest('tools/call', McpRequestEnvelope::toolCallParams($name, $arguments));
    }

    /**
     * Discover the UI resources the server exposes.
     *
     * @return array<int, mixed>
     *
     * @throws McpAppClientException
     */
    public function listResources(): array
    {
        return McpRequestEnvelope::resources(
            $this->sendRequest('resources/list', McpRequestEnvelope::resourcesListParams())
        );
    }

    /**
     * Read a single resource.
     *
     * @return array<string, mixed>
     *
     * @throws McpAppClientException
     */
    public function readResource(string $uri): array
    {
        return $this->sendRequest('resources/read', McpRequestEnvelope::resourceReadParams($uri));
    }

    /**
     * The protocol version the session settled on, or '' before a handshake.
     */
    public function negotiatedProtocolVersion(): string
    {
        return $this->negotiatedProtocolVersion;
    }

    /**
     * Find OAuth metadata without performing any grant.
     *
     * Walks the shared candidate ordering — RFC 8414 on the origin, then RFC
     * 9728 protected-resource metadata and the authorization servers it
     * advertises. Returns null rather than throwing: "this server has no OAuth"
     * is a normal answer that the caller turns into a UI choice.
     *
     * @return array<string, mixed>|null
     */
    public function discoverMetadata(): ?array
    {
        foreach (OAuthDiscoveryCandidates::authorizationServerMetadataUrls($this->serverUrl) as $url) {
            $document = $this->fetchMetadataDocument($url, 'as');

            if (null !== $document) {
                return $document;
            }
        }

        foreach (OAuthDiscoveryCandidates::protectedResourceUrls($this->serverUrl) as $url) {
            $document = $this->fetchMetadataDocument($url, 'prm');

            if (null === $document) {
                continue;
            }

            $servers = isset($document['authorization_servers']) ? (array) $document['authorization_servers'] : array();

            foreach ($servers as $server) {
                if (!is_string($server) || '' === trim($server)) {
                    continue;
                }

                foreach (OAuthDiscoveryCandidates::authorizationServerUrlsFor(trim($server)) as $candidate) {
                    $metadata = $this->fetchMetadataDocument($candidate, 'as');

                    if (null !== $metadata) {
                        return $metadata;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Send a JSON-RPC request and return its result.
     *
     * @param array<string, mixed>|\stdClass $params
     * @return array<string, mixed>
     *
     * @throws McpAppClientException
     */
    private function sendRequest(string $method, array|\stdClass $params): array
    {
        ++$this->requestId;

        if (McpRequestEnvelope::injectsMeta($method, $this->negotiatedProtocolVersion)) {
            $params = array_merge(
                is_array($params) ? $params : array(),
                McpRequestEnvelope::meta(self::PROTOCOL_VERSION, $this->clientName, $this->clientVersion)
            );
        }

        $body = json_encode(
            array(
                'jsonrpc' => '2.0',
                'id' => $this->requestId,
                'method' => $method,
                'params' => $params,
            )
        );

        if (false === $body) {
            throw new McpAppClientException('MCP request could not be encoded.', 0, null, array('method' => $method));
        }

        $headers = array_merge(
            array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            $this->headers
        );

        try {
            $response = $this->http->send('POST', $this->serverUrl, $headers, $body);
        } catch (RuntimeException $error) {
            throw new McpAppClientException(
                'MCP transport failed: '.$error->getMessage(),
                0,
                null,
                array('method' => $method, 'url' => $this->serverUrl)
            );
        }

        return $this->decodeResult($method, $response);
    }

    /**
     * Turn a response into a result or a typed failure.
     *
     * The JSON-RPC error is checked before the status: a 2025-era gateway can
     * answer with an error envelope at HTTP 400 or at HTTP 200, and the code
     * inside is what the dialect policy needs.
     *
     * @return array<string, mixed>
     *
     * @throws McpAppClientException
     */
    private function decodeResult(string $method, HttpResponse $response): array
    {
        $decoded = json_decode($response->body, true);

        if (!is_array($decoded)) {
            throw new McpAppClientException(
                sprintf('MCP %s returned a non-JSON body (HTTP %d).', $method, $response->statusCode),
                $response->statusCode,
                null,
                array('method' => $method)
            );
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $code = isset($decoded['error']['code']) && is_int($decoded['error']['code']) ? $decoded['error']['code'] : null;
            $message = isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : 'MCP error';

            throw new McpAppClientException($message, $response->statusCode, $code, array('method' => $method));
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new McpAppClientException(
                sprintf('MCP %s failed with HTTP %d.', $method, $response->statusCode),
                $response->statusCode,
                null,
                array('method' => $method)
            );
        }

        return isset($decoded['result']) && is_array($decoded['result']) ? $decoded['result'] : array();
    }

    /**
     * `initialize` — the sessionful dialect.
     *
     * @return array<string, mixed>
     *
     * @throws McpAppClientException
     */
    private function initialize(): array
    {
        $result = $this->sendRequest(
            'initialize',
            McpRequestEnvelope::meta(self::PROTOCOL_VERSION, $this->clientName, $this->clientVersion)
        );

        if (isset($result['protocolVersion'])) {
            $this->negotiatedProtocolVersion = (string) $result['protocolVersion'];
        }

        return $result;
    }

    /**
     * `server/discover` — the stateless dialect.
     *
     * @return array<string, mixed>
     *
     * @throws McpAppClientException
     */
    private function discover(): array
    {
        return $this->sendRequest(
            'server/discover',
            McpRequestEnvelope::meta(self::PROTOCOL_VERSION, $this->clientName, $this->clientVersion)
        );
    }

    /**
     * Fetch and validate one metadata candidate.
     *
     * @param string $url  Candidate URL.
     * @param string $kind 'as' for RFC 8414, 'prm' for RFC 9728.
     * @return array<string, mixed>|null
     */
    private function fetchMetadataDocument(string $url, string $kind): ?array
    {
        if ('' === $url) {
            return null;
        }

        try {
            $response = $this->http->send('GET', $url, array('Accept' => 'application/json'));
        } catch (RuntimeException) {
            return null;
        }

        if (200 !== $response->statusCode) {
            return null;
        }

        $document = json_decode($response->body, true);

        if (!is_array($document)) {
            return null;
        }

        $valid = 'as' === $kind
            ? OAuthDiscoveryCandidates::isAuthorizationServerMetadata($document)
            : OAuthDiscoveryCandidates::isProtectedResourceMetadata($document);

        return $valid ? $document : null;
    }
}
