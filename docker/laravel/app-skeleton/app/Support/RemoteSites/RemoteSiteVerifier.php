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
 *  - `mailjet_mcp` → the MCP handshake when a bridge URL is saved, otherwise
 *    `GET /v3/REST/user` against the selected region. Mailjet is the one type
 *    that may legitimately have no MCP endpoint, so it runs before the
 *    empty-URL guard below.
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
        // A credential-only Mailjet connection has no endpoint of its own, so it
        // must not be short-circuited by the "no URL" guard below.
        if ('mailjet_mcp' === $type) {
            return $this->verifyMailjet($credentials);
        }

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
     * Verify a Mailjet connection in whichever shape it was saved as.
     *
     * The bridge is preferred when present because it is the only shape that
     * proves the MCP tool surface works. Otherwise the API probe answers the
     * narrower question the credential-only mode actually needs: does Mailjet
     * accept this key pair in this region?
     *
     * @param array<string, string> $credentials Config merged with opened credentials.
     *
     * @return array{success: bool, message: string}
     */
    private function verifyMailjet(array $credentials): array
    {
        $bridge = trim((string) ($credentials['mailjet_mcp_url'] ?? ''));

        if ('' !== $bridge) {
            return $this->verifyMcpServer($bridge, $credentials);
        }

        $apiKey = trim((string) ($credentials['api_key'] ?? ''));
        $secret = trim((string) ($credentials['api_secret'] ?? ''));

        if ('' === $apiKey || '' === $secret) {
            return [
                'success' => false,
                'message' => 'A Mailjet API key and secret are required to test this connection.',
            ];
        }

        $region = 'eu' === strtolower(trim((string) ($credentials['region'] ?? 'us'))) ? 'eu' : 'us';
        $host = 'eu' === $region ? 'https://api.eu.mailjet.com' : 'https://api.mailjet.com';

        try {
            $response = $this->http->send('GET', $host.'/v3/REST/user', [
                'Accept' => 'application/json',
                'Authorization' => 'Basic '.base64_encode($apiKey.':'.$secret),
            ]);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach the Mailjet API: '.$e->getMessage()];
        }

        $label = 'eu' === $region ? 'EU' : 'US';

        return match (true) {
            200 === $response->statusCode => [
                'success' => true,
                'message' => sprintf('Mailjet accepted the API key pair (%s region).', $label),
            ],
            401 === $response->statusCode, 403 === $response->statusCode => [
                'success' => false,
                'message' => sprintf(
                    'Mailjet rejected these credentials (HTTP %d) in the %s region. Check the key pair, and confirm the key was created in the same region.',
                    $response->statusCode,
                    $label,
                ),
            ],
            default => [
                'success' => false,
                'message' => sprintf('Unexpected response from the Mailjet API (HTTP %d).', $response->statusCode),
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
