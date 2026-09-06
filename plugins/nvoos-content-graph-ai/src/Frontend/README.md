# Frontend

## Purpose

Public-facing frontend surface of the AI addon: the `[nvoos_content_graph_chat]` chat widget (`ChatShortcode`), which renders a lean, framework-free chat UI speaking the same REST + SSE contract as the admin Chat Tester and the Pro SPA-v2. Per `CHAT-SHORTCODE-PLAN.md` this is the ecosystem's aligned implementation — the base plugin's `[mcp_ai_chat]` widget (`assets/js/chat.js` bundle) stays with the base hub in monolith installs.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAi\Plugin::register()` (both install modes) |
| **Optional dependencies** | None (JetEngine assistant posts optional for guest-token scope) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Frontend\ChatShortcode` | `ChatShortcode.php` | `Plugin::register()` — registers `[nvoos_content_graph_chat]` |

## Inputs / Outputs / Neighbors

- **Reads from:** shortcode attributes (`assistant`, `allow_guests`, `provider`, `model`, `height`, `show_cost`, `placeholder`), assistant posts (`mcp_ai_assistant`)
- **Writes to:** widget markup, enqueued assets (`content-graph-ai-chat.css`, `content-graph-ai-sse.js`, `content-graph-ai-chat-frontend.js`), inline config (`window.NvoosContentGraphChat`), guest token transients (via `Chat\GuestToken`)
- **Upstream callers:** WordPress shortcode API (post content, widgets, Elementor text widgets)
- **Downstream collaborators:** `src/Chat/GuestToken.php`, `src/Rest/ChatController.php` (chat route), `assets/js/content-graph-ai-sse.js` (SSE parser + markdown)
- **Events fired:** none
- **Events listened to:** none

## Conventions

- Registered in both install modes — the tag is ecosystem-specific and never collides with the base's `[mcp_ai_chat]`.
- All widget config leaves PHP as JSON via `wp_add_inline_script`; the frontend JS renders all text through the shared escape + markdown pipeline (no raw insertion).
- Guest tokens are only issued when `allow_guests="true"` AND `assistant` resolves to an assistant post.
- Transcript persistence is sessionStorage-only per widget (server transcripts need the transcript storage wave).

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping + nonces

## See Also

- Parent: [`../`](../) — src root
- Widget assets: [`../../assets/js/content-graph-ai-chat-frontend.js`](../../assets/js/content-graph-ai-chat-frontend.js), [`../../assets/js/content-graph-ai-sse.js`](../../assets/js/content-graph-ai-sse.js), [`../../assets/css/content-graph-ai-chat.css`](../../assets/css/content-graph-ai-chat.css)
- Plan: [`../../CHAT-SHORTCODE-PLAN.md`](../../CHAT-SHORTCODE-PLAN.md)
- Guest tokens: [`../Chat/GuestToken.php`](../Chat/GuestToken.php)
