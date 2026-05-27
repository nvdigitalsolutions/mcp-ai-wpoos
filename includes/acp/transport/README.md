# ACP HTTP Transport

## Purpose

Houses the HTTP transport controller for the Agent Communication Protocol (ACP) — exposing REST endpoints for JSON-RPC method dispatch and Server-Sent Events streaming between ACP clients and the WordPress backend.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | `addons/pro/includes/acp/` via REST API route registration |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_ACP_Transport_HTTP` | `class-wp-mcp-ai-acp-transport-http.php` | ACP clients, agent mesh |

The class extends `WP_MCP_AI_REST_Controller_Base` and registers two routes:
- `POST /wp-json/mcp-ai/v1/acp` — JSON-RPC method dispatch
- `GET /wp-json/mcp-ai/v1/acp/sse` — Server-Sent Events streaming

## Inputs / Outputs / Neighbors

- **Reads from:** JSON-RPC request body, SSE session ID parameter, `acp_updates_{session_id}` transients
- **Writes to:** JSON-RPC responses, SSE event streams, transient cleanup
- **Upstream callers:** ACP clients (external agents, mesh nodes) via HTTP
- **Downstream collaborators:** `includes/acp/WP_MCP_AI_ACP_JSONRPC_Dispatcher` (request dispatch), `includes/acp/WP_MCP_AI_ACP_Session_Manager` (session updates)
- **Events fired:** SSE keep-alive heartbeats every 15 seconds
- **Events listened to:** none

## Conventions

- JSON-RPC requests are dispatched via `WP_MCP_AI_ACP_JSONRPC_Dispatcher::dispatch()` into a standard `array` response.
- SSE connections run a 300-second (5-minute) polling loop with 1-second sleep intervals, pulling pending updates from transients.
- SSE output buffering is explicitly flushed (`ob_end_clean()` loop) before streaming begins.
- Keep-alive pings (`: keep-alive`) are sent every 15 seconds to prevent connection drops.
- `permission_callback` returns `true` (authentication is delegated to the dispatcher/session manager).

## Tests

```bash
vendor/bin/phpunit tests/test-acp-transport-http.php
```

Coverage targets: route registration, JSON-RPC dispatch, SSE connection lifecycle, and keep-alive heartbeat.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`.context/rest-api.md`](../../.context/rest-api.md) — REST API registration patterns
