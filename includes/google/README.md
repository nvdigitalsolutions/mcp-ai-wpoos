# google/

## Purpose

Shared Google API infrastructure. Owns the OAuth 2.0 flows, the Calendar API v3 client, the Calendar OAuth scope registry, and Calendar credential resolution — so that Google integrations do not each re-implement token exchange, retry policy, and scope handling.

This folder exists specifically to stop a pre-existing duplication problem: the Google OAuth start/callback pair is already copy-pasted four times (base Gmail, base Drive, Pro Gmail, Pro Drive) and those copies have drifted — the base and Pro Drive flows request *different* scope sets for the same product. New Google integrations must build on this folder instead of adding a fifth copy.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (see [`CLAUDE.md`](../../CLAUDE.md)) |
| **Loaded by** | `includes/integrations/class-wp-mcp-ai-oauth-manager.php` (OAuth handlers) and each Calendar tool via `require_once` |
| **Optional dependencies** | A Google Cloud project with OAuth 2.0 credentials; `openssl` (only for the service-account JWT path); NV oOS Pro (only for Remote Sites connection resolution — degrades gracefully without it) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Google_OAuth_Service` | `class-wp-mcp-ai-google-oauth-service.php` | `includes/integrations/class-wp-mcp-ai-oauth-manager.php`, `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` |
| `WP_MCP_AI_Google_Calendar_Client` | `class-wp-mcp-ai-google-calendar-client.php` | Calendar tools in `includes/tools/` and `addons/pro/includes/tools/google-workspace/`, `class-wp-mcp-ai-google-calendar-sync.php` |
| `WP_MCP_AI_Google_Calendar_Scopes` | `class-wp-mcp-ai-google-calendar-scopes.php` | both connection admin surfaces, every Calendar tool (scope enforcement) |
| `WP_MCP_AI_Google_Calendar_Credentials` | `class-wp-mcp-ai-google-calendar-credentials.php` | every Calendar tool, `WP_MCP_AI_Google_Calendar_Sync` |
| `WP_MCP_AI_Google_Calendar_Sync` | `class-wp-mcp-ai-google-calendar-sync.php` | Action Scheduler jobs, the push-notification REST route |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (base single-connection credentials), `wp_mcp_ai_pro_remote_sites` (Pro multi-connection credentials, via `WP_MCP_AI_Pro_Remote_Site_Manager`), OAuth state transients, access-token cache transients, the `wp_mcp_ai_google_calendar_*` filters.
- **Writes to:** OAuth state transients (`wp_mcp_ai_<service>_oauth_state_<md5>`), access-token cache transients (`wp_mcp_ai_google_access_token_<md5>`), sync-state transients/options, Google Calendar API v3.
- **Upstream callers:** `WP_MCP_AI_OAuth_Manager` (base OAuth), `WP_MCP_AI_Pro_Remote_Sites_Admin` (Pro OAuth), Calendar tool `execute()` methods, Action Scheduler sync jobs, the `mcp-ai/v1/google-calendar/webhook` REST route.
- **Downstream collaborators:** `includes/admin/class-wp-mcp-ai-admin-settings.php` (settings read), `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php` (connection read + `decrypt_value()`).
- **Events fired:** `wp_mcp_ai_google_calendar_scope_profiles`, `wp_mcp_ai_google_calendar_retry_backoff`, `wp_mcp_ai_google_calendar_access_token`, `wp_mcp_ai_google_calendar_service_account_credentials`, `wp_mcp_ai_google_calendar_default_calendar_id`, `wp_mcp_ai_google_calendar_synced`.
- **Events listened to:** none directly; the OAuth handlers are invoked from `admin_init` / `admin_post_*` in `includes/integrations/`.

## Conventions

- **`WP_MCP_AI_Google_Calendar_Scopes` is the only place scope strings may be written.** Never inline a `googleapis.com/auth/calendar*` URL elsewhere. The scope is `calendar.acls` (plural) — `calendar.acl` does not exist.
- **Redirect URIs must come from `WP_MCP_AI_Google_OAuth_Service::build_redirect_uri()` or `build_remote_redirect_uri()`.** Google requires the authorize-time and exchange-time URIs to be byte-identical; rebuilding one inline is how that invariant breaks.
- **OAuth `state` is single-use.** `consume_state()` deletes the transient *before* validating, so a replayed callback cannot succeed inside the TTL window. Do not add a read-then-validate-then-delete variant.
- **Never re-run the authorization flow to obtain a fresh access token.** Google silently invalidates the oldest refresh token once an account exceeds 100 live tokens per client ID. Use `mint_access_token()`, which caches.
- **Never assume requested scopes were granted.** Granular consent lets users approve a subset. Persist the token response's `scope` field and gate on `WP_MCP_AI_Google_Calendar_Credentials::require_scope()`.
- **Branch on `error.errors[0].reason`, never on a bare `410`.** `fullSyncRequired` / `updatedMinTooLongAgo` mean "wipe and resync"; `deleted` on a DELETE is a *success*. The client already encodes this — use `WP_MCP_AI_Google_Calendar_Client::is_full_sync_required()`.
- **Build sync parameters with `build_sync_params()`.** Eight `events.list` parameters (`timeMin`, `timeMax`, `updatedMin`, `q`, `orderBy`, `iCalUID`, and both extended-property filters) are legal on a full sync but return `400` with `syncToken`.
- **Tools consuming this folder must return the canonical envelope** (success array or `WP_Error`, never `array( 'success' => false )`) per the Unix Theory P0–P6 rule in [`CLAUDE.md`](../../CLAUDE.md).
- Secrets (`client_secret`, `refresh_token`) must never be passed through a text sanitiser on input, because that corrupts them, and must be listed in `get_sensitive_fields()` so the admin UI masks them. See [`.context/security-checklist.md`](../../.context/security-checklist.md) for the canonical input/output rules.

## Tests

```bash
vendor/bin/phpunit tests/test-google-calendar-foundation.php
```

That single suite (36 tests) covers the invariants that are cheap to break and expensive to debug: scope implication under granular consent, all eight `events.list` parameters that are forbidden alongside `syncToken`, HTTP 410 discrimination, retry classification, sync-token placement during pagination, cancelled-event routing, single-use/user-bound OAuth state, `invalid_grant` detection, sync-interval jitter bounds, and per-target state isolation.

Redirect-URI byte-equality between the authorize request and the token exchange is additionally asserted by `tests/test-oauth-redirect-uri-consistency.php` and `tests/test-remote-sites-oauth-redirect-uri.php`.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — OAuth state, token storage, capability checks (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — when adding a Calendar tool
- [`.context/rest-api.md`](../../.context/rest-api.md) — the push-notification webhook route
- [`.context/pro-vs-base.md`](../../.context/pro-vs-base.md) — base single-connection vs Pro multi-connection split
- [`docs/reference/google-calendar-api-v3.md`](../../docs/reference/google-calendar-api-v3.md) — scope tiers, forbidden sync params, full error table

## See Also

- Sibling folders: [`includes/integrations/`](../integrations/) (OAuth handler wiring), [`includes/http/`](../http/) (outbound HTTP), [`includes/admin/sections/`](../admin/sections/) (the Tools → Connections UI)
- Pro counterpart: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` (multi-connection OAuth), `addons/pro/includes/tools/google-workspace/` (Calendar tools)
- Architecture note: [`docs/developer/architecture/integrations/google-calendar-connection.md`](../../docs/developer/architecture/integrations/google-calendar-connection.md)
- API reference: [`docs/reference/google-calendar-api-v3.md`](../../docs/reference/google-calendar-api-v3.md)
