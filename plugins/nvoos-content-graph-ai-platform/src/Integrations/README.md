# Integrations

## Purpose

Wave E4 port surface. The OAuth authentication flows for third-party
service integrations from the base plugin's `includes/integrations/`:
the central `OAuthManager` (Gmail, Google Drive, Yahoo Sports, Google
Calendar) plus the GitHub, Meta, Mailjet, and QuickBooks provider
handlers — ported byte-identically so connected-service surfaces work
in both install modes.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerIntegrations()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); the base owns the same `admin_post_*` wiring in monolith installs |
| **Optional dependencies** | None (the League OAuth2 client is used when present, with manual-URL fallbacks) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Integrations\OAuthManager` | `OAuthManager.php` | `Plugin::registerIntegrations()` — the Gmail/Drive/Yahoo/Calendar OAuth actions; the calendar flow delegates to the shared Google service (E4 calendar sub-cluster) |
| `NvoosContentGraphAiPlatform\Integrations\GithubOAuthHandler` | `GithubOAuthHandler.php` | `Plugin::registerIntegrations()` — `admin_post_wp_mcp_ai_github_oauth_*` + `allowed_redirect_hosts` |
| `NvoosContentGraphAiPlatform\Integrations\MetaOAuthHandler` | `MetaOAuthHandler.php` | `Plugin::registerIntegrations()` — `admin_post_wp_mcp_ai_meta_oauth_*` + `allowed_redirect_hosts` |
| `NvoosContentGraphAiPlatform\Integrations\MailjetOAuthHandler` | `MailjetOAuthHandler.php` | Static token utility (`is_connected()` / `get_access_token()`) — no hooks, byte-identical to the base |
| `NvoosContentGraphAiPlatform\Integrations\QuickbooksOAuthHandler` | `QuickbooksOAuthHandler.php` | `Plugin::registerIntegrations()` — `admin_post_wp_mcp_ai_quickbooks_oauth_*` + the `admin_notices` transient display |

## Inputs / Outputs / Neighbors

- **Reads from:** the `wp_mcp_ai_settings` option (per-service client
  credentials, tokens, connected-user info), OAuth callback query
  params (`state`/`code`/`error`/`realmId`), state transients
  (`wp_mcp_ai_{service}_oauth_state_*`), and the Yahoo token user meta.
- **Writes to:** the `wp_mcp_ai_settings` option, OAuth state
  transients, Yahoo access/refresh token user meta, the
  `settings_errors` + per-service notice transients.
- **Upstream callers:** `Plugin::registerIntegrations()`; the
  admin-post action surface; future connected-service tool surfaces.
- **Downstream collaborators:** the WordPress HTTP API (token
  exchange + profile fetches), the League OAuth2 client when present,
  and — for the Google Calendar flow — the base's
  `WP_MCP_AI_Google_OAuth_Service` + `WP_MCP_AI_Google_Calendar_Scopes`
  monolith (boot-gated probes; standalone degrades until the E4
  calendar sub-cluster ports them).
- **Events fired:** none public (redirects + notice transients).
- **Events listened to:** `admin_init` (callback router),
  `admin_post_wp_mcp_ai_*` (start/disconnect handlers),
  `allowed_redirect_hosts` (github/meta filters), `admin_notices`
  (QuickBooks transient display).

## Conventions

- **Fail closed.** Every flow verifies the single-use `state` transient
  (or the Yahoo state data) before exchanging codes; capability
  (`manage_options`) and nonce gates precede every start/disconnect.
- Byte-identical constants/hooks/error codes/redirect envelopes with
  the base; deviations documented in the class docblocks (text domain,
  PSR-4 class names, per-mode settings/log/sanitizer seams, the
  standalone Google-Calendar degradation, standalone-only wiring).
- Redirect envelopes terminate with `exit` in production; the GitHub
  `wp_mcp_ai_github_oauth_redirect_terminate` filter and the Meta
  `redirect_and_exit()` blocked-redirect contract exist so tests can
  exercise the flows without killing the process.

## Tests

- `tests/test-oauth-manager.php` — constants, callback wiring, gates,
  manual token-exchange branches, authorize-URL builder, redirect-host
  filter, per-mode settings + Google-services seams (both matrices).
- `tests/test-oauth-github.php` — constants, redirect-host filter,
  callback branches via the terminate filter (accumulated settings
  errors), end-to-end happy path with HTTP interception (both
  matrices).
- `tests/test-oauth-meta.php` — constants, redirect-host filter,
  callback branches via the blocked-redirect WPDieException contract,
  happy path (per-mode sanitizer contract), disconnect (both
  matrices).
- `tests/test-oauth-mailjet.php` — constants, state-key format,
  `is_connected()`/`get_access_token()` with the 60-second expiry
  buffer and refresh error codes (both matrices).
- `tests/test-oauth-quickbooks.php` — constants, state-key format,
  `is_connected()`/`get_access_token()` refresh contract (both
  matrices).

```bash
vendor/bin/phpunit plugins/nvoos-content-graph-ai-platform/tests/test-oauth-*.php
```

## Also Load

- [`../Tenant/README.md`](../Tenant/README.md) — the E4 tenant
  isolation layer (per-mode seams pattern)
- [`../Queues/README.md`](../Queues/README.md) — the E2 queue family
  (per-mode collaborator resolution pattern)
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (OAuth flows handle credentials; always)

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E4 row status
- [`includes/google/`](../../../../includes/google/) — the shared Google OAuth service the calendar flow delegates to (next E4 sub-cluster)
