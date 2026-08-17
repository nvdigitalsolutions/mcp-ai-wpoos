# Composio Connect

## Purpose

Pro integration with Composio Connect: one remote-site connection type (`composio`) that gives NV oOS assistants per-user authenticated access to 1,000+ third-party apps (Gmail, Slack, GitHub, Notion, ...) via Composio's hosted auth ("Connect Links"), REST tool execution (API v3.1) and trigger webhooks. Provider OAuth tokens never touch WordPress — only the Composio project API key and webhook signing secret are stored (encrypted in the Remote Site Manager option store).

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
| `WP_MCP_AI_Composio_Client` | `class-wp-mcp-ai-composio-client.php` | Tools, auth handler, webhook controller, Remote Site Manager test-connection |
| `WP_MCP_AI_Composio_Auth_Handler` | `class-wp-mcp-ai-composio-auth-handler.php` | Connect Link create/callback flows, account expiry tracking |
| `WP_MCP_AI_Composio_Trigger_Bridge` | `class-wp-mcp-ai-composio-trigger-bridge.php` | Webhook event dispatch to automation surfaces |
| `WP_MCP_AI_Composio_Webhook_Controller` | `../rest/class-wp-mcp-ai-composio-webhook-controller.php` | Public signature-gated webhook receiver |
| `composio-init.php` | this folder | Bootstrap: requires all classes, registers the six `composio_*` tools |

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_Pro_Remote_Site_Manager` connections (`connection_type === 'composio'`); WordPress transients for caching/state/dedup.
- **Writes to:** WordPress transients (GET caches, 429 cooldowns, link state, event dedup); audit log via `WP_MCP_AI_Logger` when available.
- **Upstream callers:** Remote Sites admin page (save/test/Connect Link actions), the six `composio_*` tools, Composio webhook deliveries.
- **Downstream collaborators:** Composio backend API (`https://backend.composio.dev`, API `v3.1`, `x-api-key` auth); Pro Workflow Builder / Schedule Manager via `wp_mcp_ai_composio_trigger_received`.
- **Events fired:** `wp_mcp_ai_composio_trigger`, `wp_mcp_ai_composio_account_expired`, `wp_mcp_ai_composio_trigger_disabled`, `wp_mcp_ai_composio_trigger_received`, `wp_mcp_ai_composio_tool_executed`.
- **Events listened to:** `wp_mcp_ai_bootstrapped` (priority 45).

## Conventions

- All outbound HTTP goes through `WP_MCP_AI_Composio_Client::request()` — never raw `wp_remote_request()` — so URL pinning, `x-api-key` assembly, 429 cooldowns and error normalisation stay in one place.
- API version is pinned via `WP_MCP_AI_Composio_Client::API_VERSION` (`v3.1`). Watch upstream `Deprecation`/`Sunset` headers before upgrading.
- Secrets (API key, webhook secret) are encrypted at rest via the Remote Site Manager's AES-256-CBC helper and never logged or returned in REST responses.
- Tools obey the canonical envelope + two-gate sanitisation (Unix Theory P0–P6); PHPCS sniffs `WPMCPAI.Tools.*` must pass.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-composio-client.php
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
