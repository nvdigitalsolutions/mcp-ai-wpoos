# REST

## Purpose

Exposes AI capabilities via the WordPress REST API across two surfaces:
- Core namespace `nvoos-content-graph/v1` — chat endpoint (with SSE streaming) and provider listing (`ChatController`, pre-extraction).
- MCP-compatible `mcp-ai/v1` — assistant directory, tools listing, the MCP JSON-RPC 2.0 protocol, and the base-compatible chat route (`AssistantController`, `ToolsController`, `McpController`, `ChatCompatController`; Wave D5), registered standalone-only with CG-AI's auth.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAi\Plugin::register()` on `rest_api_init` |
| **Optional dependencies** | `nvoos-content-graph` (required — shares REST namespace) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Rest\ChatController` | `ChatController.php` | `Plugin::register()` (REST route registration) |
| `NvoosContentGraphAi\Rest\AssistantController` | `AssistantController.php` | `Plugin::register()` (standalone-only `mcp-ai/v1/assistants` routes) |
| `NvoosContentGraphAi\Rest\ToolsController` | `ToolsController.php` | `Plugin::register()` (standalone-only `mcp-ai/v1/tools` route) |
| `NvoosContentGraphAi\Rest\McpController` | `McpController.php` | `Plugin::register()` (standalone-only `mcp-ai/v1/mcp`, `/no-sse`, `/sse` routes) |
| `NvoosContentGraphAi\Rest\ChatCompatController` | `ChatCompatController.php` | `Plugin::register()` (standalone-only `mcp-ai/v1/chat` route) |

## Inputs / Outputs / Neighbors

- **Reads from:** REST request params, `NvoosContentGraphAi\ProviderRegistry` (chat), the assistant CPT (`mcp_ai_assistant` posts + `_wp_mcp_ai_*` meta), the active tool registry (base `WP_MCP_AI_Tool_Registry` monolith / nvoos-core registry via `CoreBridge` standalone)
- **Writes to:** `WP_REST_Response` / `WP_Error`, SSE stream output, assistant posts (create/delete), REST caches
- **Upstream callers:** WordPress REST API (SPA v2, MCP clients)
- **Downstream collaborators:** `src/Chat/ChatService` (chat processing), `nvoos-content-graph` core `ToolRegistry`, `CoreBridge`
- **Events fired:** None (REST handlers return responses directly)
- **Events listened to:** `rest_api_init`

### REST Endpoints

Base paths: `/wp-json/nvoos-content-graph/v1` (chat) and `/wp-json/mcp-ai/v1` (MCP surface, standalone-only — the base plugin owns the same routes in monolith installs)

| Method | Path | Description | Auth |
|---|---|---|---|
| `POST` | `/ai/chat` | Send chat messages (supports SSE streaming via `?stream=1`) | `edit_posts` |
| `GET` | `/ai/providers` | List available AI provider slugs | `edit_posts` |
| `GET` | `/mcp-ai/v1/assistants` | Assistant directory (search/include/per_page/_fields) | `edit_posts` |
| `POST` | `/mcp-ai/v1/assistants` | Create an assistant (title/description/provider/model/temperature/tools/status) | `manage_options` |
| `DELETE` | `/mcp-ai/v1/assistants/{id}` | Delete an assistant | `manage_options` |
| `GET` | `/mcp-ai/v1/tools` | List tools (optionally scoped to an assistant) | `edit_posts` |
| `GET` | `/mcp-ai/v1/mcp` | MCP server discovery JSON | `edit_posts` |
| `POST` | `/mcp-ai/v1/mcp` | MCP JSON-RPC 2.0 (initialize/discover, ping, tools/list, tools/call, resources/*, prompts/*, completion/complete, logging/setLevel, notifications) | `edit_posts` |
| `OPTIONS` | `/mcp-ai/v1/mcp` | CORS preflight | none |
| `GET` | `/mcp-ai/v1/no-sse` | Assistant directory as JSON (non-SSE) | `edit_posts` |
| `GET` | `/mcp-ai/v1/sse` | SSE endpoint placeholder — returns discovery JSON (SSE sessions deferred) | `edit_posts` |
| `POST` | `/mcp-ai/v1/chat` | Base-compatible chat (messages + options envelope; delegates to the CG-AI chat path) | `edit_posts` |
| `GET` | `/mcp-ai/v1/chat` | Base SSE chat handshake — deferred (`wp_mcp_ai_sse_chat_deferred` 501); streaming via POST `options.stream` | `edit_posts` |

## Conventions

- Routes are registered under the core's `nvoos-content-graph/v1` namespace (chat) and the base-compatible `mcp-ai/v1` namespace (assistants/tools/MCP).
- `mcp-ai/v1` route registration is standalone-only; the base plugin owns the same routes in monolith installs.
- Auth is CG-AI's own capability model (`edit_posts` / `manage_options`); token scoping stays with the base hub until CG-AI guest tokens land.
- Response contracts, error codes, cache key structures, and filters are byte-identical to the base (documented per-class seams for settings/config/registry/cache reads).
- **MCP controller deviations (documented in the class docblock):** SSE sessions deferred (`GET /mcp` always returns discovery; `/sse` returns discovery); `tools/call` answers the `wp_mcp_ai_mcp_unavailable` stub until tool execution ports (D-Tools); no OAuth scope enforcement; no default-assistant resolution.
- **Chat compat deviations (documented in the class docblock):** `options` envelope translated to CG-AI chat params; `assistant_id` / `professional_prompt` / `options.response_format` accepted but not applied until the assistant/profession runtimes port; message content-part arrays JSON-encoded; `GET /chat` SSE handshake deferred.
- SSE streaming endpoint sets appropriate headers (`text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`) and flushes output buffering.
- Messages are sanitized via `sanitizeMessages()` — role via `sanitize_text_field`, content via `wp_kses_post`.
- Provider list returns only slugs (not full configuration) to avoid leaking API keys.

## Tests

```bash
vendor/bin/phpunit --filter '/ChatController|REST/'
```

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — nonces, caps, escaping
- [`../../../../.context/rest-api.md`](../../../../.context/rest-api.md) — REST patterns

## See Also

- Parent: [`../`](../) — src root
- Collaborators: [`../CoreBridge.php`](../CoreBridge.php), [`../Tools/`](../Tools/)
- Core REST: [`../../../nvoos-content-graph/src/Rest/Controller.php`](../../../nvoos-content-graph/src/Rest/Controller.php)
