# ACP

## Purpose

Agent Client Protocol (ACP) subsystem — JSON-RPC 2.0 dispatcher (`initialize`, `session/new`, `session/prompt`, `session/load`, `session/list`, `session/cancel`), session lifecycle management, ContentBlocks ↔ message bridging, and the HTTP transport (`POST /wp-json/mcp-ai/v1/acp`, `GET /wp-json/mcp-ai/v1/acp/sse`).

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\ACP\ACPService` (via `Plugin::register()`) |
| **Optional dependencies** | Base plugin `mcp-ai-wpoos` (monolith mode — its REST layer owns ACP routing) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\ACP\ACPService` | `ACPService.php` | `Plugin::register()` |
| `NvoosContentGraphAiPlatform\ACP\Server` | `Server.php` | Admin, orchestration |
| `NvoosContentGraphAiPlatform\ACP\JsonRpcDispatcher` | `JsonRpcDispatcher.php` | `TransportHttp`, `ACPService` |
| `NvoosContentGraphAiPlatform\ACP\SessionManager` | `SessionManager.php` | `SessionBridge`, `JsonRpcDispatcher` |
| `NvoosContentGraphAiPlatform\ACP\SessionBridge` | `SessionBridge.php` | `JsonRpcDispatcher` |
| `NvoosContentGraphAiPlatform\ACP\TransportHttp` | `TransportHttp.php` | `ACPService` (standalone mode) |
| `NvoosContentGraphAiPlatform\ACP\AbstractRestController` | `AbstractRestController.php` | `TransportHttp` |

## Inputs / Outputs / Neighbors

- **Reads from:** transients (`acp_sess_*`, `acp_updates_*`), user meta (`_acp_sessions`), `ai_platform_settings` option
- **Writes to:** transients, user meta, SSE streams, `WP_REST_Response`
- **Upstream callers:** REST API (`mcp-ai/v1/acp`), `Plugin::register()`
- **Downstream collaborators:** chat service (injected; stub branch when absent)
- **Events listened to:** `rest_api_init` (standalone mode only)

## Conventions

- Ported 1:1 from `mcp-ai-wpoos/includes/acp/` (extraction Wave A). Transient prefixes, user meta keys, JSON-RPC wire format, and route paths are unchanged.
- Standalone mode (base plugin absent): `ACPService` mounts the transport on `rest_api_init` when `ai_platform_settings.acp_enabled` is set. Monolith mode: the base plugin's REST layer owns ACP routing — never register twice.
- **Intentional deviation:** the base copy's transport ships a permissive `return true` permission callback (TODO). The platform port enforces `is_user_logged_in() && current_user_can( 'edit_posts' )` — it is only mounted in standalone mode, where the base plugin's auth stack does not exist.
- Text domain: `nvoos-content-graph-ai-platform`.

## Tests

```bash
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter ACP
```

## See Also

- Plan: `docs/project/plans/content-graph-platform-extraction-plan.md`
- Tracker: `MIGRATION-GAPS.md`
- Admin UI: [`Admin/`](Admin/)
