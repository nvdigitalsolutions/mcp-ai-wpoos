# ACP — Agent Client Protocol

## Purpose

Implements the [Agent Client Protocol](https://agentclientprotocol.com/) server surface so external IDE clients (Zed, JetBrains, etc.) can drive NV oOS assistants over JSON-RPC sessions — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/class-wp-mcp-ai-rest.php` constructor (`require_once` chain when ACP REST routes register) |
| **Optional dependencies** | none — purely additive to the core chat service |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_ACP_Server` | `class-wp-mcp-ai-acp-server.php` | orchestrator, wired from `class-wp-mcp-ai-rest.php` |
| `WP_MCP_AI_ACP_JSONRPC_Dispatcher` | `class-wp-mcp-ai-acp-jsonrpc-dispatcher.php` | transport layer + REST controller |
| `WP_MCP_AI_ACP_Session_Manager` | `class-wp-mcp-ai-acp-session-manager.php` | dispatcher (`session/new`, `session/load`, `session/list`, `session/cancel`) |
| `WP_MCP_AI_ACP_Session_Bridge` | `class-wp-mcp-ai-acp-session-bridge.php` | dispatcher (ContentBlock ↔ chat-message translation + `tool_call` routing) |
| `WP_MCP_AI_ACP_Transport_HTTP` (in `transport/`) | `transport/class-wp-mcp-ai-acp-transport-http.php` | REST routes for ACP HTTP transport |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress transients prefixed `acp_sess_` (session state), current user (`wp_get_current_user()`), assistant CPT metadata for capability/tool discovery.
- **Writes to:** session transients (24 h lifetime, see `SESSION_LIFETIME`), chat-message stream via the bridge, tool-execution side-effects via `WP_MCP_AI_Tool_Registry`.
- **Upstream callers:** [`includes/rest/`](../rest/) (ACP HTTP transport route), [`includes/class-wp-mcp-ai-rest.php`](../) when the routes register.
- **Downstream collaborators:** [`includes/services/`](../services/) chat service (consumed by `Session_Bridge::set_chat_service()`), [`includes/tools/`](../tools/) via the registry, the approvals controller in [`includes/services/`](../services/) for `session/request_permission`.
- **Events fired:** none directly — protocol responses are returned as JSON-RPC envelopes, not WordPress actions.
- **Events listened to:** REST permission callbacks gate every dispatch (no additional WP hook registrations from this folder).

## Conventions

- Every dispatcher method MUST return a JSON-RPC 2.0 result or error envelope. Returning raw arrays bypasses the protocol — translate `WP_Error` into `{ "error": { "code", "message", "data" } }` at the dispatcher boundary.
- ACP sessions are scoped to the **WordPress user that owns the session transient**. Never load a session for a different `user_id` without re-running capability checks — the manager enforces this on `session/load`.
- The `transport/` subdirectory exists to keep wire-level transports (HTTP today, stdio/WebSocket potentially later) isolated from protocol semantics. New transports go there; new RPC methods go in the dispatcher.
- ContentBlock translation lives in `Session_Bridge` and **only** there — do not duplicate ACP↔NV oOS message conversion in REST controllers or tools.

## Tests

```bash
vendor/bin/phpunit tests/acp/
```

Covers the dispatcher, session manager, and session bridge:

- `tests/acp/test-acp-jsonrpc-dispatcher.php`
- `tests/acp/test-acp-session-manager.php`
- `tests/acp/test-acp-session-bridge.php`

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — auth/cap rules for REST + session ownership (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — how ACP routes register alongside the rest of `/wp-json/mcp-ai/v1/`
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — `tool_call` is dispatched into the same registry
- ACP spec: <https://agentclientprotocol.com/>

## See Also

- Sibling: [`a2a/`](../a2a/) — different protocol (agent-to-agent, not client-to-agent)
- Sibling: [`services/`](../services/) — chat service consumed via `Session_Bridge::set_chat_service()`
- Sibling: [`tools/`](../tools/) — invoked through the registry on every ACP `tool_call`
