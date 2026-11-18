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
 * Tokens are the exception to the "no secrets here" rule that applied until
 * #7743. They are accepted now, but only through `CredentialStore`: sealed
 * under the app key, kept at `token_sealed`, and projected back as a uniform
 * mask. The plaintext never enters `meta`, never appears in a response, and a
 * blank token means "keep what is stored" — matching the console's update
 * contract. Identity for that carry-over is `connection_ref` when set, else
 * `server_url`.
 *
 * The mask is also treated as blank. A caller that reads the list and writes it
 * back is the normal shape for a bulk editor — the panel does exactly that — and
 * without this guard the read projection's `••••••••` would be sealed as the
 * new credential, silently replacing a working token with a mask.
 *
 * `oauth_data` stays unsupported: it is a multi-field blob with its own
 * refresh semantics, and the console never sends it.
 */
final class McpAppsStore
{
    /** Assistant meta key, matching `WP_MCP_AI_MCP_App_Registry::META_KEY`. */
    public const META_KEY = '_wp_mcp_ai_mcp_apps';

    /** Matches `WP_MCP_AI_MCP_App_Registry::MAX_APPS_PER_ASSISTANT`. */
    public const MAX_APPS = 10;

    /** Authentication modes the console can render. */
    private const AUTH_TYPES = ['none', 'bearer', 'basic', 'header', 'oauth'];

    public function __construct(private readonly CredentialStore $credentials)
    {
    }

    /**
     * The apps assigned to an assistant, with tokens masked.
     *
     * @return array<int, array<string, mixed>>
     */
    public function read(Assistant $assistant): array
    {
        $projected = array();

        foreach ($this->stored($assistant) as $app) {
            $sealed = isset($app['token_sealed']) && is_string($app['token_sealed']) ? $app['token_sealed'] : null;
            unset($app['token_sealed']);

            $mask = $this->credentials->mask($sealed);

            if ('' !== $mask) {
                $app['token'] = $mask;
            }

            $projected[] = $app;
        }

        return $projected;
    }

    /**
     * Replace the assigned apps.
     *
     * @param array<int, mixed> $apps Raw payload entries.
     * @return array<int, array<string, mixed>> The stored list, tokens masked.
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

        $previous = $this->sealedByIdentity($assistant);
        $stored = array();

        foreach (array_values($apps) as $index => $app) {
            $normalised = $this->normalise($app, $index);

            $token = is_array($app) && is_string($app['token'] ?? null) ? trim($app['token']) : '';

            // A read projection carries the mask, not a value. Treating it as
            // absent keeps a read → write round-trip from sealing the mask as
            // the credential.
            if (CredentialStore::MASK === $token) {
                $token = '';
            }

            // A supplied token is sealed; an absent one keeps whatever this
            // connection already had. Never fall back to another app's token.
            $sealed = '' !== $token
                ? $this->credentials->encrypt(array('token' => $token))
                : ($previous[$this->identity($normalised)] ?? '');

            if ('' !== $sealed) {
                $normalised['token_sealed'] = $sealed;
            }

            $stored[] = $normalised;
        }

        $meta = is_array($assistant->meta) ? $assistant->meta : array();
        $meta[self::META_KEY] = $stored;

        $assistant->meta = $meta;
        $assistant->save();

        return $this->read($assistant);
    }

    /**
     * Stored entries as written, including any sealed token.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stored(Assistant $assistant): array
    {
        $meta = is_array($assistant->meta) ? $assistant->meta : array();
        $apps = $meta[self::META_KEY] ?? array();

        return is_array($apps) ? array_values(array_filter($apps, 'is_array')) : array();
    }

    /**
     * Sealed tokens keyed by connection identity, for the carry-over on update.
     *
     * @return array<string, string>
     */
    private function sealedByIdentity(Assistant $assistant): array
    {
        $sealed = array();

        foreach ($this->stored($assistant) as $app) {
            if (! isset($app['token_sealed']) || ! is_string($app['token_sealed'])) {
                continue;
            }

            $sealed[$this->identity($app)] = $app['token_sealed'];
        }

        return $sealed;
    }

    /**
     * What makes one connection "the same" across an update.
     *
     * @param array<string, mixed> $app A stored or normalised entry.
     */
    private function identity(array $app): string
    {
        $ref = is_string($app['connection_ref'] ?? null) ? trim($app['connection_ref']) : '';

        return '' !== $ref ? $ref : (string) ($app['server_url'] ?? '');
    }

    /**
     * Whitelist one entry.
     *
     * Every field is named explicitly, so a caller cannot widen the stored
     * shape by adding a key. `token` is handled separately because it is sealed.
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
            'enabled' => (bool) ($app['enabled'] ?? true),
            'timeout' => $this->timeout($app['timeout'] ?? 30),
            // `null` means "not supplied", not "false": a partial payload (a
            // panel row that never touched the toggle) must get the default
            // rather than silently disabling TLS verification.
            'verify_ssl' => (bool) ($app['verify_ssl'] ?? true),
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
