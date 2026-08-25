# Composio Connect

## Purpose

Pro integration with Composio Connect: one remote-site connection type (`composio`) that gives NV oOS assistants per-user authenticated access to 1,000+ third-party apps (Gmail, Slack, GitHub, Notion, ...) via Composio's hosted auth ("Connect Links"), REST tool execution (API v3.1) and trigger webhooks. Provider OAuth tokens never touch WordPress — only the Composio project API key and webhook signing secret are stored (encrypted in the Remote Site Manager option store). Credential health is *verified* by live probe rather than read from Composio's lagging stored status.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` (`pro_composio` module → `composio-init.php`) |
| **Optional dependencies** | none — degrades to clear `WP_Error`s when no Composio connection is configured |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Composio_Client` | `class-wp-mcp-ai-composio-client.php` | Tools, health engine, auth handler, webhook controller, Remote Site Manager test-connection |
| `WP_MCP_AI_Composio_Account_Health` | `class-wp-mcp-ai-composio-account-health.php` | Health ledger + live credential probe; consumed by the account/execute tools and the auth handler |
| `WP_MCP_AI_Composio_Auth_Handler` | `class-wp-mcp-ai-composio-auth-handler.php` | Connect Link create/callback flows, account expiry tracking |
| `WP_MCP_AI_Composio_Trigger_Bridge` | `class-wp-mcp-ai-composio-trigger-bridge.php` | Webhook event dispatch to automation surfaces |
| `WP_MCP_AI_Composio_Webhook_Controller` | `../rest/class-wp-mcp-ai-composio-webhook-controller.php` | Public signature-gated webhook receiver |
| `composio-init.php` | this folder | Bootstrap: requires all classes, registers the seven `composio_*` tools |

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_Pro_Remote_Site_Manager` connections (`connection_type === 'composio'`); WordPress transients for caching/state/dedup; the `wp_mcp_ai_composio_health_{connection_id}` option for stored credential verdicts.
- **Writes to:** WordPress transients (GET caches, 429 cooldowns, link state, event dedup, resolved probe tools); the per-connection health ledger option; audit log via `WP_MCP_AI_Logger` when available.
- **Upstream callers:** Remote Sites admin page (save/test/Connect Link/refresh/disconnect actions), the seven `composio_*` tools, Composio webhook deliveries.
- **Downstream collaborators:** Composio backend API (`https://backend.composio.dev`, API `v3.1`, `x-api-key` auth); Pro Workflow Builder / Schedule Manager via `wp_mcp_ai_composio_trigger_received`.
- **Events fired:** `wp_mcp_ai_composio_trigger`, `wp_mcp_ai_composio_account_expired`, `wp_mcp_ai_composio_trigger_disabled`, `wp_mcp_ai_composio_trigger_received`, `wp_mcp_ai_composio_tool_executed`, `wp_mcp_ai_composio_account_managed`.
- **Filters exposed:** `wp_mcp_ai_composio_probe_tool` — pin or disable the read-only tool used to verify a toolkit's credentials.
- **Events listened to:** `wp_mcp_ai_bootstrapped` (priority 45).

## Conventions

- All outbound HTTP goes through `WP_MCP_AI_Composio_Client::request()` — never raw `wp_remote_request()` — so URL pinning, `x-api-key` assembly, 429 cooldowns and error normalisation stay in one place.
- API version is pinned via `WP_MCP_AI_Composio_Client::API_VERSION` (`v3.1`). Watch upstream `Deprecation`/`Sunset` headers before upgrading. `POST /connected_accounts/{id}/refresh` (used for in-place reconnection) is already marked Legacy upstream — `composio_manage_accounts` falls back to a fresh Connect Link when it fails.
- **Every v3.1 collection response is wrapped** as `{ items, next_cursor, total_pages, current_page, total_items }`. Unwrap with `unwrap_items()` + `extract_pagination()`; never iterate the raw response (doing so yields the `items` key itself as a bogus first record).
- **Normalise before consuming.** `normalize_account()` and `normalize_tool()` flatten v3.1's nested `toolkit: { slug }`, uppercase the status enum, and lift credential expiry / failure reasons out of `state.val`. Adding a new consumer means calling these, not re-deriving the fields.
- **Stored status is not verified status.** Composio reports `ACTIVE` until its own background refresh has failed repeatedly, so a revoked token looks healthy. Anything that reports account health must go through `WP_MCP_AI_Composio_Account_Health` and surface `verification_method` honestly — `status_only` is never `verified`.
- **Tool execution fails in two different bands, and both must be checked.** `POST /tools/execute/{slug}` answers HTTP 200 with `{ successful: false, error: ... }` when Composio itself rejects the call — *and* HTTP 200 with `successful: true` when Composio successfully **delivered** a call that the provider then refused, in which case the refusal is proxied into `data` (e.g. `data.message = "HTTP 401: Request had invalid authentication credentials."`). `execute_tool()` converts both to `WP_Error` (`wp_mcp_ai_composio_account_auth_required` for auth-class failures, `wp_mcp_ai_composio_tool_failed` otherwise) and attaches `provider_status` when a status was proxied. Never treat a 200 as success without that check. The error scan is deliberately limited to the bounded path list in `EXECUTION_ERROR_PATHS` and anchored via `PROXIED_STATUS_PATTERN`, because tool *content* routinely mentions HTTP statuses (an email body, a fetched document) and must never be mistaken for a failure.
- A client built with `from_connection()` is bound to the connection's Composio identity (`get_user_id()` / `set_user_id()`). Composio rejects a `connected_account_id` sent without its owning `user_id`, so `execute_tool()` and `upsert_trigger()` always carry one. Identity resolution itself lives in `WP_MCP_AI_Composio_Auth_Handler::resolve_user_id()` — the single owner of the `admin_shared` / `per_wp_user` contract.
- Secrets (API key, webhook secret) are encrypted at rest via the Remote Site Manager's AES-256-CBC helper and never logged or returned in REST responses.
- Tools obey the canonical envelope + two-gate sanitisation (Unix Theory P0–P6); PHPCS sniffs `WPMCPAI.Tools.*` must pass.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-composio-client.php
vendor/bin/phpunit addons/pro/tests/test-composio-account-health.php
vendor/bin/phpunit addons/pro/tests/test-composio-auth-handler.php
vendor/bin/phpunit addons/pro/tests/test-composio-tools.php
vendor/bin/phpunit addons/pro/tests/test-composio-webhook-controller.php
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — escape rules (always)
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro/Base placement rules
- [`CLAUDE.md`](../../../../../CLAUDE.md) — PHP-compat (8.1+) + canonical patterns

## See Also

- Parent folder: [`addons/pro/includes/`](../) — Remote Site Manager, module registry
- Proposal + plan: [`docs/project/proposals/030-composio-connect-integration-proposal.md`](../../../../docs/project/proposals/030-composio-connect-integration-proposal.md) and [`...-implementation-plan.md`](../../../../docs/project/proposals/030-composio-connect-integration-implementation-plan.md)
- End-user guide: `docs/composio-connect.md`
- Tools folder: [`addons/pro/includes/tools/composio/`](../tools/composio/)
