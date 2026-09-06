# Google

## Purpose

Wave E4 port surface. The Google Calendar subsystem from the base
plugin's `includes/google/`: the shared OAuth 2.0 service, the OAuth
scope registry, the quota-aware Calendar API v3 client, the
connection → settings → filter credential resolver, the incremental
sync engine, the push-notification receiver, and the init.php bootstrap
wiring — ported byte-identically so the platform addon carries the full
Calendar integration in standalone installs (scheduling, webhook, and
token flows) without the base plugin.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerGoogleCalendar()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); the base loader's `google-calendar-init.php` owns the same wiring in monolith installs |
| **Optional dependencies** | None (Pro Remote Sites resolution degrades with `wp_mcp_ai_calendar_pro_required` standalone) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Google\GoogleOAuthService` | `GoogleOAuthService.php` | Static — state store/consume, redirect-URI builders, authorize-URL builder, code exchange, cached token minting, userinfo fetch, revocation; used by `GoogleCalendarCredentials`, `GoogleCalendarPush` (via credentials), and the E4-2 `OAuthManager` calendar handlers |
| `NvoosContentGraphAiPlatform\Google\GoogleCalendarScopes` | `GoogleCalendarScopes.php` | Static — the scope profile registry; used by the credentials resolver and the OAuth flows |
| `NvoosContentGraphAiPlatform\Google\GoogleCalendarClient` | `GoogleCalendarClient.php` | The API v3 transport; built by `GoogleCalendarCredentials::make_client()` |
| `NvoosContentGraphAiPlatform\Google\GoogleCalendarCredentials` | `GoogleCalendarCredentials.php` | Static — credential resolution + client construction; used by the sync engine and the push manager |
| `NvoosContentGraphAiPlatform\Google\GoogleCalendarSync` | `GoogleCalendarSync.php` | Static — state store, sync passes, scheduling, target enumeration; used by the bootstrap cron wiring and the push receiver |
| `NvoosContentGraphAiPlatform\Google\GoogleCalendarPush` | `GoogleCalendarPush.php` | The webhook REST receiver + channel manager; instantiated by the bootstrap, drives one-off sync events |
| `NvoosContentGraphAiPlatform\Google\GoogleCalendarBootstrap` | `GoogleCalendarBootstrap.php` | `Plugin::registerGoogleCalendar()` — the init.php hook wiring (cron_schedules filter, sync/renew cron actions, push receiver, connection-gated scheduling) |

## Inputs / Outputs / Neighbors

- **Reads from:** the `wp_mcp_ai_settings` option (client ID/secret,
  refresh token, granted scopes, calendar ID, timezone, scope profile),
  Pro Remote Sites connections (monolith-only), the
  `wp_mcp_ai_google_calendar_*` legacy filters, the
  `wp_mcp_ai_google_calendar_sync_state` / `_channels` options, the
  `wp_mcp_ai_google_access_token_{md5}` and
  `wp_mcp_ai_{service}_oauth_state_{md5}` transients, and Google's
  OAuth / Calendar v3 REST endpoints.
- **Writes to:** the sync-state and channel options, the access-token /
  state / message-number transients, and WP-Cron events under the
  `wp_mcp_ai_google_calendar_sync` / `_renew_channels` hooks.
- **Upstream callers:** `Plugin::registerGoogleCalendar()`;
  `Integrations\OAuthManager` (calendar start/callback/disconnect
  handlers); future Calendar-tool ports (Wave F).
- **Downstream collaborators:** WordPress options/transients/cron/REST
  APIs; in monolith installs the base
  `WP_MCP_AI_Admin_Settings` / `WP_MCP_AI_Pro_Remote_Site_Manager` /
  `WP_MCP_AI_Google_*` classes (per-mode seams).
- **Events fired:** `wp_mcp_ai_google_calendar_full_sync_required`,
  `wp_mcp_ai_google_calendar_synced` (documented in `GoogleCalendarSync`).
- **Events listened to:** `cron_schedules`,
  `wp_mcp_ai_google_calendar_sync`,
  `wp_mcp_ai_google_calendar_renew_channels`, `rest_api_init`
  (webhook route).

## Conventions

- **Fail closed.** Missing credentials, missing scopes, missing tokens,
  and unauthenticated notifications all return documented `WP_Error`
  codes — never silent fallbacks.
- **Push is a trigger, never a transport.** The webhook only schedules
  a one-off sync; the jittered safety-net poll always runs alongside.
- **Per-mode collaborator seams.** The discriminator is always
  `defined( 'WP_MCP_AI_PATH' )` — never bare `class_exists()` — because
  the monorepo autoloader resolves base classes to disk even when the
  base plugin is inactive. Monolith resolves the base
  `WP_MCP_AI_Google_*` classes; standalone resolves this package's
  classes and degrades documented surfaces (settings reads the
  `wp_mcp_ai_settings` option; Remote Sites returns
  `wp_mcp_ai_calendar_pro_required`).
- Byte-identical constants/hooks/error codes/shapes with the base;
  deviations documented in the class docblocks (text domain, PSR-4
  class names, `\WP_Error` qualification, global init functions →
  static methods on `GoogleCalendarBootstrap`).
- Standalone-only bootstrap registration — the base loader owns the
  same hooks in monolith installs.

## Tests

- `tests/test-google-scopes.php` — profile constants, verification
  flags, normalisation, scope implication, %20 grant healing,
  missing-scope envelope (both matrices).
- `tests/test-google-client.php` — sync-parameter split, forced
  `showDeleted`, `maxResults` clamping, 410 discrimination, retry
  classification with the attempt cap (backoff zeroed), fail-closed
  tokens, pagination, boolean stringification, error probes (both
  matrices).
- `tests/test-google-oauth-service.php` — state single-use/user-bound
  contract, redirect-URI builders, offline/consent authorize URL,
  allowed-hosts filter, exchange branches, cached minting with the
  300 s margin, forgetter, userinfo fetch, revocation (both matrices).
- `tests/test-google-credentials.php` — resolution order, per-mode
  settings surface, filter surface, `make_client()` branches, JWT
  service-account minting (real RS256 key), timezone fallback, scope
  assertion, calendar-ID resolution, Remote Sites degradation (both
  matrices).
- `tests/test-google-sync.php` — state isolation, cancelled-event
  routing, jitter bounds, cron-schedule registration, idempotent
  scheduling, full/incremental runs, `410` downgrade with the
  invalidation action, failure counter, per-mode target enumeration
  (both matrices).
- `tests/test-google-push.php` — route registration, token
  verification, high-water-mark dedupe, deferred sync scheduling,
  eligibility gate, channel lifecycle, write-before-watch,
  credential-loss-safe teardown, replacement-first renewal (both
  matrices).
- `tests/test-google-bootstrap.php` — the init.php hook surface,
  connection gate, cron callbacks, renewal scheduling (both matrices).

```bash
vendor/bin/phpunit plugins/nvoos-content-graph-ai-platform/tests/test-google-*.php
```

## Also Load

- [`../Integrations/README.md`](../Integrations/README.md) — the E4-2
  OAuth manager (owns the Calendar admin connect/disconnect actions and
  resolves these classes standalone)
- [`../Tenant/README.md`](../Tenant/README.md) — the E4-1 sub-cluster
  (shared per-mode seam + README conventions)
- [`../README.md`](../README.md) — composition root + subsystem index
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (token handling, state replay protection)

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E4 row status
- [`includes/google/`](../../../../includes/google/) — the base subsystem (the port's origin)
