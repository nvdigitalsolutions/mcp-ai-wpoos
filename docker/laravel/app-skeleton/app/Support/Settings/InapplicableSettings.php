<?php

namespace App\Support\Settings;

/**
 * Settings that configure software this runtime does not have — Proposal 149 §104.
 *
 * A second list, and a different treatment from `UnimplementedSettings`, because
 * they answer different questions:
 *
 *  - **Unimplemented** — the capability is real on WordPress, we have not built
 *    it yet, and the administrator should see it and know why not. Rendered,
 *    disabled, explained.
 *  - **Inapplicable** — the setting configures a WordPress plugin, endpoint or
 *    hosting model that has no counterpart here. Not rendered at all.
 *
 * The distinction is not cosmetic. A disabled "Simple JWT Login" toggle tells an
 * administrator "this exists and someone could build it", which is false: the
 * tokens it validates come from a WordPress plugin that is not installed and
 * cannot be. Showing it is the same fiction as showing a toggle that works.
 *
 * WHAT IS *NOT* HERE, AND WHY
 *
 * `rest_enable_assistant_*` are deliberately left inert rather than hidden. They
 * do have a counterpart — this runtime serves `/api/v1/assistants` — but honouring
 * them means distinguishing a first-party console call from a third-party token
 * call on the same routes, which the WordPress plugin gets for free because its
 * console does not use its REST API. That is a real design decision, not an
 * absence, so the fields stay visible and marked.
 *
 * The test in `tests/Feature/InapplicableSettingsTest.php` pins the other half:
 * none of these keys may be referenced by runtime code, because a key with a
 * reader is not inapplicable — it is implemented, and belongs back on the form.
 *
 * @see \App\Support\SettingsSchema::forSurface()
 * @see \App\Support\Settings\UnimplementedSettings
 */
final class InapplicableSettings
{
    /**
     * Every key that configures absent software, and what is absent.
     *
     * @var array<string, string>
     */
    public const REASONS = array(
        // The Auth0 → user bridge: configures userinfo + Management API calls
        // this runtime does not make (Proposal 156 D5).
        'enable_auth0_github_bridge'           => 'Configures the WordPress Auth0 bridge; not ported (156 D5).',
        'auth0_management_client_id'           => 'Configures the WordPress Auth0 bridge; not ported (156 D5).',
        'auth0_management_client_secret'       => 'Configures the WordPress Auth0 bridge; not ported (156 D5).',

        // WordPress.com / Gravatar identities.
        'enable_wordpress_gravatar_bridge'     => 'Resolves WordPress.com identities; there are none here.',
        'wordpress_gravatar_userinfo_endpoint' => 'Resolves WordPress.com identities; there are none here.',

        // A third-party plugin's tokens.
        'enable_simple_jwt_login'              => 'Validates tokens from a WordPress plugin that is not installed.',

        // Routes this runtime does not have.
        'sse_enable_post_method'               => 'Toggles POST on a WordPress SSE endpoint; streaming here is already POST.',
        'oauth_disable_open_registration'      => 'Guards RFC 7591 registration; this runtime registers no clients.',

        // Policy met by a different mechanism.
        'credential_lifetime_days'             => 'Expiry is chosen per token at mint time, not by a policy.',
        'require_confirm_destructive_ops'      => 'The approvals queue meets this intent; no global switch.',

        // 149 §187 — WordPress-only security mechanisms. The shared signal
        // catalogue marks each of these RUNTIME_WORDPRESS, so a Laravel signal is
        // not merely unimplemented here: there is nothing on this side for it to
        // describe.
        //
        // The root-key pair is NOT here, deliberately. §66 says operator
        // infrastructure renders so an estate can see who owns it, and
        // `SettingsCatalogueTest` pins that the key is writable from `/ops` and
        // masked as a secret. Hiding it would reverse both. It stays unenforced and
        // is recorded as an open decision in §187.3.
        'enable_loopback_ssl_bypass'           => 'wp_remote_* loopback requests have no counterpart; providers are called directly.',
        'enable_loopback_private_network_requests' => 'No URL guard or loopback request path exists here to allow or block.',
        'mcp_app_allowed_hosts'                => 'App servers are configured per assistant; this runtime hosts no framable app document.',
        'strip_asset_versions'                 => 'Assets are Vite-built with content hashes, not ?ver= query strings.',
        'enable_oauth_token_rate_limit'        => 'Tokens are minted by Auth0; there is no OAuth token endpoint here to limit.',
        'log_file_access'                      => 'No file-serving route exists; media is served by URL, so the app never sees the access.',
        // A real idea, but the runtime already answers it with a different control.
        'api_error_verbosity'                  => 'Error detail is governed by APP_DEBUG; a second verbosity axis would be a second truth.',
    );

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::REASONS);
    }

    public static function isInapplicable(string $key): bool
    {
        return array_key_exists($key, self::REASONS);
    }

    /**
     * The reason, for code readers and for the contract. Never rendered.
     */
    public static function reasonFor(string $key): string
    {
        return self::REASONS[ $key ] ?? '';
    }
}
