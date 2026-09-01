# Frontend

## Purpose

Frontend-facing surfaces of the AI addon. Currently the `[nvoos_content_graph_chat]` chat widget (Wave D-UI-1b): a lean, framework-free chat widget speaking the same REST + SSE contract as the admin Chat Tester and the Pro SPA-v2, with guest-token support via `Chat\GuestToken`.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAi\Plugin::register()` (shortcode registration) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Frontend\ChatShortcode` | `ChatShortcode.php` | `Plugin::register()` — `[nvoos_content_graph_chat]` shortcode |

## Inputs / Outputs / Neighbors

- **Reads from:** shortcode attributes, assistant posts (`mcp_ai_assistant`), `GuestToken` (guest-token issuance)
- **Writes to:** shortcode markup, enqueued assets (`content-graph-ai-chat.css`, `content-graph-ai-sse.js`, `content-graph-ai-chat-frontend.js`), inline frontend config (`window.NvoosContentGraphChat`)
- **Upstream callers:** WordPress shortcode engine (page/post content)
- **Downstream collaborators:** `assets/js/content-graph-ai-chat-frontend.js`, `src/Rest/ChatController` (`/ai/chat`), `src/Chat/GuestToken.php`
- **Events fired:** none
- **Events listened to:** none

## Conventions

- The shortcode tag is ecosystem-specific (`nvoos_content_graph_chat`) and never collides with the base plugin's `[mcp_ai_chat]` — registered in both install modes.
- Frontend config is injected via `wp_add_inline_script(…, 'before')` into a `window.NvoosContentGraphChat` array (one entry per widget instance, unique container IDs).
- Guest tokens are issued only when `allow_guests="true"` AND the `assistant` attribute resolves to a published assistant post; the widget echoes the token back via the `X-WP-MCP-AI-Guest` header.
- All widget-rendered text flows through the shared escape + markdown pipeline in `content-graph-ai-sse.js` — no raw content into innerHTML.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping + nonces

## See Also

- Parent: [`../`](../) — src root
- Assets: [`../../assets/js/content-graph-ai-chat-frontend.js`](../../assets/js/content-graph-ai-chat-frontend.js), [`../../assets/js/content-graph-ai-sse.js`](../../assets/js/content-graph-ai-sse.js)
- Guest tokens: [`../Chat/GuestToken.php`](../Chat/GuestToken.php)
- Chat route: [`../Rest/ChatController.php`](../Rest/ChatController.php)
