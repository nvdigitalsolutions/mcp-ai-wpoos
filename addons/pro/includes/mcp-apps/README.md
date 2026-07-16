# MCP Apps — Per-Assistant Remote MCP Server Connections

## Purpose

Houses the Pro-only "MCP Apps" subsystem that lets each assistant connect to up to ten remote MCP servers over JSON-RPC 2.0 / Streamable HTTP and bridges their discovered tools into the local tool registry — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | [`addons/pro/includes/mcp-apps/mcp-apps-init.php`](./mcp-apps-init.php) — required from `addons/pro/mcp-ai-wpoos-pro.php` inside `wp_mcp_ai_pro_init()` |
| **Optional dependencies** | none — the remote endpoint is the only external surface; transport uses `wp_remote_*` |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_MCP_App_Client` | `class-wp-mcp-ai-mcp-app-client.php` | `MCP_App_Registry`, `MCP_App_Tool_Bridge`, the REST controller, CLI/slash-command surfaces |
| `WP_MCP_AI_MCP_App_Registry` (singleton) | `class-wp-mcp-ai-mcp-app-registry.php` | `mcp-apps-init.php` (tool registration), assistant metaboxes, REST controller |
| `WP_MCP_AI_MCP_App_Tool_Bridge` | `class-wp-mcp-ai-mcp-app-tool-bridge.php` | `MCP_App_Registry::register_remote_tools()` — wraps each discovered remote tool as a local `WP_MCP_AI_Tool_Interface` |
| `WP_MCP_AI_MCP_App_OAuth_Client` | `class-wp-mcp-ai-mcp-app-oauth-client.php` | OAuth 2.0 client for MCP Apps — handles metadata discovery, DCR, PKCE flow, token exchange/refresh/revocation |
| `WP_MCP_AI_REST_MCP_Apps_Controller` | `class-wp-mcp-ai-rest-mcp-apps-controller.php` | self-registers under namespace `mcp-ai/v1` on `rest_api_init`; includes OAuth endpoints (`/oauth/probe`, `/oauth/init`, `/oauth/callback`, `/oauth/refresh`, `/oauth/revoke`) |
| `wp_mcp_ai_mcp_apps_register_tools()` | `mcp-apps-init.php` | hooked at priority 50 on `wp_mcp_ai_register_tools` |

Storage / protocol constants (stable contract): `WP_MCP_AI_MCP_App_Registry::META_KEY = '_wp_mcp_ai_mcp_apps'`, `MAX_APPS_PER_ASSISTANT = 10`, `CACHE_TTL = 300`, `WP_MCP_AI_MCP_App_Client::PROTOCOL_VERSION = '2025-03-26'`, `MAX_RESPONSE_SIZE = 2 MB`.

## Inputs / Outputs / Neighbors

- **Reads from:** assistant post meta `_wp_mcp_ai_mcp_apps` (per-assistant app configs — URL, auth, allow-list), remote MCP servers (JSON-RPC `initialize` / `tools/list` / `tools/call` / `resources/read`), the request body / query / `$_POST` for the current `assistant_id`, the `wp_mcp_ai_mcp_apps_assistant_id` filter as a fallback resolver, transient cache (`wp_mcp_ai_mcp_app_tools_*`, 5 min TTL).
- **Writes to:** the same assistant post meta on save, transient tool-discovery cache, telemetry via the standard tool-execution hooks when bridged tools run.
- **Upstream callers:** REST clients (CRUD via `mcp-ai/v1/mcp-apps`), chat service via the tool registry, the Pro slash-command `/mcp-app` (see `addons/pro/includes/slash-commands/`).
- **Downstream collaborators:** [`includes/tools/`](../../../../includes/tools/) tool registry (`wp_mcp_ai_register_tools`), [`includes/services/`](../../../../includes/services/) chat service (executes bridged tools transparently), `wp_remote_post`/`wp_remote_get` for transport.
- **Events fired:** `wp_mcp_ai_mcp_apps_assistant_id` (filter — let callers supply an assistant ID when the request doesn't carry one).
- **Events listened to:** `rest_api_init`, `wp_mcp_ai_register_tools` (priority 50, so the bridge registers after base + Pro tools).

## Conventions

- **Per-assistant scope is mandatory.** Every public method on `MCP_App_Registry` takes an `assistant_id`; never read or write the `_wp_mcp_ai_mcp_apps` meta key directly from other folders.
- **`MAX_APPS_PER_ASSISTANT = 10` is a hard cap.** Enforce it on every save path — UI, REST, CLI, slash command — so a single assistant can never balloon discovery cost.
- **Remote URLs must pass `WP_MCP_AI_MCP_App_Registry::is_allowed_server_url()`** (HTTPS-only, blocks loopback/private ranges unless explicitly allow-listed). Do not bypass this for "internal" servers — add an allow-list entry instead.
- **Transport is JSON-RPC 2.0 over Streamable HTTP per the MCP 2025-03-26 spec.** When the spec bumps (e.g. SEP-1865 MCP Apps extension finalises), update `WP_MCP_AI_MCP_App_Client::PROTOCOL_VERSION` and the `initialize` handshake — don't shim older transports inside this folder.
- **Cap remote responses at 2 MB** (`MAX_RESPONSE_SIZE`). Truncate and surface an error rather than allocating an arbitrary payload — a remote MCP server is untrusted input.
- **Cache `tools/list` results in transients keyed by `md5( app_config )`** so a configuration change naturally invalidates the cache; do not hand-build cache keys elsewhere.

## Tests

```bash
vendor/bin/phpunit tests/test-mcp-apps.php
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-mcp-app.php
```

(The base-tree test exercises the registry, client, and tool-bridge; the Pro-tree test covers the `/mcp-app` slash-command surface that drives this folder from the assistant UI.)

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — outbound HTTP, SSRF, secrets (always — this folder talks to untrusted remotes)
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — bridged tools must honour the canonical envelope
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — placement rationale
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat (8.1+ here) and the two-gate sanitisation rule
- [`docs/features/mcp-apps.md`](../../../../docs/features/mcp-apps.md) — feature reference, if present

## See Also

- Sibling: [`../mcp-servers/`](../mcp-servers/) — the inverse direction (this plugin *exposing* MCP servers to other clients)
- Sibling: [`../slash-commands/`](../slash-commands/) — the `/mcp-app` slash command driving CRUD
- Upstream: [`../../../../includes/tools/`](../../../../includes/tools/) — tool registry the bridge plugs into
- Spec: <https://modelcontextprotocol.io/specification/2025-03-26>
