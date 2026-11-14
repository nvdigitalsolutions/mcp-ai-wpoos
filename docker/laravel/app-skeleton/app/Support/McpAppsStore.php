<?php

namespace App\Support;

use App\Models\Assistant;
use InvalidArgumentException;

/**
 * Assistant-scoped MCP app assignments.
 *
 * Mirrors the WordPress registry's storage key and limit so one console screen
 * can read either runtime: the Pro registry keeps the list in the
 * `_wp_mcp_ai_mcp_apps` post meta and caps it at ten.
 *
 * Secrets are deliberately absent from the writable set. The console's client
 * (`spa-v2/src/api/mcp-apps.ts`) strips `token` and `oauth_data` before sending,
 * and this store ignores them if a caller sends them anyway — so there is
 * nothing to mask on read. A future admin surface that accepts a token must
 * encrypt it before it reaches `meta`; storing a plaintext credential here
 * would be the one way to make this endpoint leak.
 */
final class McpAppsStore
{
    /** Assistant meta key, matching `WP_MCP_AI_MCP_App_Registry::META_KEY`. */
    public const META_KEY = '_wp_mcp_ai_mcp_apps';

    /** Matches `WP_MCP_AI_MCP_App_Registry::MAX_APPS_PER_ASSISTANT`. */
    public const MAX_APPS = 10;

    /** Authentication modes the console can render. */
    private const AUTH_TYPES = ['none', 'bearer', 'basic', 'header', 'oauth'];

    /**
     * The apps assigned to an assistant.
     *
     * @return array<int, array<string, mixed>>
     */
    public function read(Assistant $assistant): array
    {
        $meta = is_array($assistant->meta) ? $assistant->meta : array();
        $apps = $meta[self::META_KEY] ?? array();

        return is_array($apps) ? array_values(array_filter($apps, 'is_array')) : array();
    }

    /**
     * Replace the assigned apps.
     *
     * @param array<int, mixed> $apps Raw payload entries.
     * @return array<int, array<string, mixed>> The stored list.
     *
     * @throws InvalidArgumentException When an entry is unusable.
     */
    public function replace(Assistant $assistant, array $apps): array
    {
        if (count($apps) > self::MAX_APPS) {
            throw new InvalidArgumentException(
                sprintf('At most %d MCP apps may be assigned to one assistant.', self::MAX_APPS)
            );
        }

        $normalised = array();

        foreach (array_values($apps) as $index => $app) {
            $normalised[] = $this->normalise($app, $index);
        }

        $meta = is_array($assistant->meta) ? $assistant->meta : array();
        $meta[self::META_KEY] = $normalised;

        $assistant->meta = $meta;
        $assistant->save();

        return $normalised;
    }

    /**
     * Whitelist one entry.
     *
     * Every field is named explicitly. Unknown keys — including `token` and
     * `oauth_data` — are dropped rather than passed through, so a caller
     * cannot widen the stored shape by adding a key.
     *
     * @throws InvalidArgumentException
     */
    private function normalise(mixed $app, int $index): array
    {
        if (! is_array($app)) {
            throw new InvalidArgumentException(sprintf('MCP app at position %d must be an object.', $index));
        }

        $url = is_string($app['server_url'] ?? null) ? trim($app['server_url']) : '';
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ('' === $url || ! in_array($scheme, array('http', 'https'), true)) {
            throw new InvalidArgumentException(
                sprintf('MCP app at position %d needs an http(s) server_url.', $index)
            );
        }

        $authType = is_string($app['auth_type'] ?? null) ? strtolower($app['auth_type']) : 'none';

        return array(
            'label' => is_string($app['label'] ?? null) ? $app['label'] : '',
            'server_url' => $url,
            'connection_ref' => is_string($app['connection_ref'] ?? null) ? $app['connection_ref'] : '',
            'auth_type' => in_array($authType, self::AUTH_TYPES, true) ? $authType : 'none',
            'header_name' => is_string($app['header_name'] ?? null) ? $app['header_name'] : '',
            'enabled' => ! array_key_exists('enabled', $app) || (bool) $app['enabled'],
            'timeout' => $this->timeout($app['timeout'] ?? 30),
            'verify_ssl' => ! array_key_exists('verify_ssl', $app) || (bool) $app['verify_ssl'],
        );
    }

    /**
     * Clamp the per-app timeout to the range the console offers.
     */
    private function timeout(mixed $value): int
    {
        $timeout = is_numeric($value) ? (int) $value : 30;

        return max(1, min(300, $timeout));
    }
}
