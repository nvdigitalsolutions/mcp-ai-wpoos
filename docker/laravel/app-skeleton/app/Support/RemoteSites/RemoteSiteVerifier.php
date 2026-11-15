<?php

namespace App\Support\RemoteSites;

use App\Mcp\Client\McpAppClient;
use App\Mcp\Client\McpAppClientException;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Throwable;

/**
 * Verify a connection against the thing it points at (090-i, `remoteSites`).
 *
 * Only the types in {@see RemoteSiteTypes} are implemented, and each check is a
 * real round trip — there is no "assume it works":
 *
 *  - `wordpress` → `GET {url}/wp-json/wp/v2/users/me` with the application
 *    password. A 401/403 is reported as *reached, rejected* rather than as an
 *    unreachable site, because the distinction is the whole point of a Test
 *    button.
 *  - `mcp_server` → the shared `McpAppClient` handshake and `tools/list`, so
 *    the check exercises the same dialect negotiation a real call would.
 *
 * Every failure is a message, never an exception: a Test button that 500s tells
 * the operator nothing.
 */
final class RemoteSiteVerifier
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /**
     * @param array<string, string> $credentials Opened credentials, runtime-only.
     *
     * @return array{success: bool, message: string}
     */
    public function verify(string $type, string $url, array $credentials): array
    {
        if ('' === trim($url)) {
            return ['success' => false, 'message' => 'This connection has no URL to test.'];
        }

        return match ($type) {
            'wordpress' => $this->verifyWordpress($url, $credentials),
            'mcp_server' => $this->verifyMcpServer($url, $credentials),
            default => [
                'success' => false,
                'message' => sprintf('Verification is not implemented for "%s" on this runtime.', $type),
            ],
        };
    }

    /**
     * @param array<string, string> $credentials
     *
     * @return array{success: bool, message: string}
     */
    private function verifyWordpress(string $url, array $credentials): array
    {
        $username = trim((string) ($credentials['username'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        if ('' === $username || '' === $password) {
            return [
                'success' => false,
                'message' => 'A username and application password are required to test this site.',
            ];
        }

        $endpoint = rtrim($url, '/').'/wp-json/wp/v2/users/me';

        try {
            $response = $this->http->send('GET', $endpoint, [
                'Accept' => 'application/json',
                'Authorization' => 'Basic '.base64_encode($username.':'.$password),
            ]);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach the site: '.$e->getMessage()];
        }

        return match (true) {
            200 === $response->statusCode => [
                'success' => true,
                'message' => sprintf('Connected to %s as %s.', rtrim($url, '/'), $username),
            ],
            401 === $response->statusCode, 403 === $response->statusCode => [
                'success' => false,
                'message' => sprintf(
                    'The site answered, but rejected the credentials (HTTP %d).',
                    $response->statusCode,
                ),
            ],
            default => [
                'success' => false,
                'message' => sprintf('Unexpected response from the site (HTTP %d).', $response->statusCode),
            ],
        };
    }

    /**
     * @param array<string, string> $credentials
     *
     * @return array{success: bool, message: string}
     */
    private function verifyMcpServer(string $url, array $credentials): array
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];
        $token = trim((string) ($credentials['token'] ?? ''));

        if ('' !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $client = new McpAppClient($this->http, $url, $headers);

        try {
            $handshake = $client->handshake();
            $tools = $client->listTools();
        } catch (McpAppClientException $e) {
            return ['success' => false, 'message' => 'The MCP server rejected the check: '.$e->getMessage()];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach the MCP server: '.$e->getMessage()];
        }

        $count = count($tools);

        return [
            'success' => true,
            'message' => sprintf(
                'MCP server answered the %s handshake and lists %d tool%s.',
                (string) ($handshake['method'] ?? 'session'),
                $count,
                1 === $count ? '' : 's',
            ),
        ];
    }
}
