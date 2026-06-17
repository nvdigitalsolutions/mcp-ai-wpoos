# MCP Servers — Per-Toolkit MCP Server Framework

## Purpose

Houses the Pro-only framework that promotes each Pro toolkit to a first-class MCP server with its own JSON-RPC endpoint under `/wp-json/mcp-ai-pro/v1/mcp/{slug}`, plus the `/.well-known/mcp` discovery descriptor, toolkit-scoped credentials, and cross-mount audit trail — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | [`addons/pro/includes/mcp-servers/mcp-servers-init.php`](./mcp-servers-init.php) — required from `addons/pro/mcp-ai-wpoos-pro.php` inside `wp_mcp_ai_pro_init()` |
| **Optional dependencies** | none — each per-toolkit server self-describes; the WP REST API is the only required infrastructure |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Toolkit_Server_Interface` | `interface-wp-mcp-ai-toolkit-server.php` | every concrete toolkit server in `servers/` |
| `WP_MCP_AI_Toolkit_Server_Base` (abstract) | `class-wp-mcp-ai-toolkit-server-base.php` | every concrete toolkit server |
| `WP_MCP_AI_Toolkit_Server_Registry` (singleton) | `class-wp-mcp-ai-toolkit-server-registry.php` | `mcp-servers-init.php`, REST controller, CLI, admin page |
| `WP_MCP_AI_Toolkit_MCP_REST_Controller` (singleton) | `class-wp-mcp-ai-toolkit-mcp-rest-controller.php` | self-registers all routes under `mcp-ai-pro/v1` |
| `WP_MCP_AI_Toolkit_MCP_Audit_Log` (singleton) | `class-wp-mcp-ai-toolkit-mcp-audit-log.php` | cross-mount audit trail consumers, admin observability card |
| `WP_MCP_AI_Pro_Toolkit_Server_Token` | `class-wp-mcp-ai-pro-toolkit-server-token.php` | per-toolkit token issuance / rotation / revocation |
| `WP_MCP_AI_Pro_Well_Known_MCP` | `class-wp-mcp-ai-pro-well-known-mcp.php` | self-registers the `/.well-known/mcp` discovery endpoint |
| `WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card` | `class-wp-mcp-ai-pro-toolkit-mcp-observability-card.php` | Pro admin → performance/orchestration section |
| `servers/class-wp-mcp-ai-{toolkit}-mcp-server.php` | per-toolkit concrete servers | registered on `wp_mcp_ai_register_toolkit_servers` |

Stable contract: REST namespace `mcp-ai-pro/v1`, routes `/mcp`, `/mcp/{slug}`, `/mcp/{slug}/token`, `/mcp/{slug}/token/{prefix}`, `/mcp-audit`. Slug regex: `[a-z0-9_\-]+`.

## Inputs / Outputs / Neighbors

- **Reads from:** the global tool registry (each toolkit server exposes a *subset* of registered tools), per-server option key `wp_mcp_ai_toolkit_mcp_server_{slug}` (enabled flag, tool allow-list, disabled surfaces, disabled mounts, rate limit, payload cap, max iterations), per-server tokens issued through `Pro_Toolkit_Server_Token`, JSON-RPC request bodies on the REST routes.
- **Writes to:** the toolkit-server option keys above (via `admin-post.php` action `wp_mcp_ai_save_toolkit_mcp_server`), the cross-mount audit log, JSON-RPC response payloads.
- **Upstream callers:** external MCP clients (Claude Desktop, other LLM agents), the Pro admin page [`WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page`](../admin/class-wp-mcp-ai-pro-toolkit-mcp-servers-page.php), the `/mcp-server` slash command and `wp pro mcp-server` CLI command.
- **Downstream collaborators:** [`includes/tools/`](../../../../includes/tools/) (each server resolves tools via the registry), [`includes/measurement/`](../../../../includes/measurement/) (observability card and audit log emit metric events).
- **Events fired:** `wp_mcp_ai_register_toolkit_servers` (the registry's bootstrap action — every server registers itself here).
- **Events listened to:** `init` priority 12 (bootstrap), `rest_api_init` (route registration), `admin_post_wp_mcp_ai_save_toolkit_mcp_server` (settings persistence).

## Conventions

- **One server per toolkit. One slug per server.** Slugs are kebab-case, stable, and used in URLs + option keys — never rename without a migration in [`../migrations/`](../migrations/).
- **Concrete servers extend `WP_MCP_AI_Toolkit_Server_Base`**, not the bare interface, unless the implementation has a genuine reason to bypass the base capability/config plumbing (rare — document it).
- **Per-server config is the only configuration surface.** Do not add per-server feature flags as top-level options; they belong inside the toolkit-server config array (`enabled`, `tools_allowlist`, `disabled_surfaces`, `disabled_mounts`, `requests_per_minute`, `max_payload_bytes`, `max_iterations`).
- **Tokens are toolkit-scoped, not user-scoped.** Issuance/rotation/revocation must go through `WP_MCP_AI_Pro_Toolkit_Server_Token`; never persist raw tokens — only the prefix + hash.
- **Audit every cross-mount call** through `WP_MCP_AI_Toolkit_MCP_Audit_Log`. A request that resolves a tool from a different toolkit than the requested mount is a security-relevant event.
- **`/.well-known/mcp` is discovery-only** (capabilities, mounts, server URLs). Never include token material or per-mount config there.
- **Don't import or shim the base `/mcp-ai/v1/mcp` endpoint.** The two endpoints coexist deliberately; this folder is the *per-toolkit* surface.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-toolkit-server-contract.php
vendor/bin/phpunit addons/pro/tests/test-toolkit-server-execution.php
vendor/bin/phpunit addons/pro/tests/test-toolkit-server-credentials.php
vendor/bin/phpunit addons/pro/tests/test-toolkit-server-limits.php
vendor/bin/phpunit addons/pro/tests/test-toolkit-mcp-audit-log.php
vendor/bin/phpunit addons/pro/tests/test-cross-toolkit-mounts.php
vendor/bin/phpunit addons/pro/tests/test-ingestion-surface-parity.php
vendor/bin/phpunit addons/pro/tests/test-phase5-toolkit-mcp-servers.php
vendor/bin/phpunit addons/pro/tests/test-phase6-toolkit-mcp-servers.php
vendor/bin/phpunit addons/pro/tests/test-pro-toolkit-mcp-servers-page.php
vendor/bin/phpunit addons/pro/tests/test-pro-cli-mcp-server-command.php
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-mcp-server.php
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — REST permission callbacks, token storage, audit trail (always)
- [`.context/rest-api.md`](../../../../.context/rest-api.md) — namespace conventions, route layering
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — tools resolved by each server live in the global registry
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — placement rationale
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat (8.1+) + canonical envelope
- [`docs/mcp-servers.md`](../../../../docs/mcp-servers.md) — feature reference, if present

## See Also

- Sibling: [`../mcp-apps/`](../mcp-apps/) — the inverse direction (this plugin *consuming* remote MCP servers)
- Sibling: [`../admin/`](../admin/) — `WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page` lives there
- Sibling: [`../slash-commands/`](../slash-commands/) and [`../cli/`](../cli/) — alternate management surfaces
- Folder: [`servers/`](./servers/) — concrete per-toolkit server implementations
