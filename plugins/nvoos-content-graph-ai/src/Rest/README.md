# REST

## Purpose

Exposes AI chat capabilities via WordPress REST API under the core's `nvoos-content-graph/v1` namespace — chat endpoint (with SSE streaming support) and provider listing endpoint.

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

## Inputs / Outputs / Neighbors

- **Reads from:** REST request params (`messages`, `provider`, `stream`), `NvoosContentGraphAi\ProviderRegistry`
- **Writes to:** `WP_REST_Response` / `WP_Error`, SSE stream output
- **Upstream callers:** WordPress REST API
- **Downstream collaborators:** `src/Chat/ChatService` (chat processing), `nvoos-content-graph` core `ToolRegistry`
- **Events fired:** None (REST handlers return responses directly)
- **Events listened to:** `rest_api_init`

### REST Endpoints

Base path: `/wp-json/nvoos-content-graph/v1`

| Method | Path | Description | Auth |
|---|---|---|---|
| `POST` | `/ai/chat` | Send chat messages (supports SSE streaming via `?stream=1`) | `edit_posts` |
| `GET` | `/ai/providers` | List available AI provider slugs | `edit_posts` |

## Conventions

- Routes are registered under the core's `nvoos-content-graph/v1` namespace (not a separate namespace).
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
