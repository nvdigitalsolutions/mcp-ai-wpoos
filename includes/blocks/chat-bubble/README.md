# AI Chat Bubble Block

## Purpose

Displays a floating chat bubble button that opens an AI chat panel. Delegates chat rendering to `[mcp_ai_chat]` shortcode but adds its own bubble UI layer with customizable position, size, animation, colors, and deferred initialization so the chat only activates when opened.

## Tier

**Base** / PHP 7.4+

## Public Surface

- **Block name:** `mcp-ai-wpoos/chat-bubble`
- **Category:** `mcp-ai-wpoos`
- **Attributes:** `assistantId`, `allowGuests`, `saveTranscript`, `enableStreaming`, `allowSensitiveTools`, `template`, `bubblePosition`, `bubbleSize`, `bubbleAnimation`, `bubbleTooltip`, `panelTitle`, `panelWidth`, `panelHeight`, `autoOpenDelay`, `rememberState`, `notificationBadge`, `bubbleColor`, `bubbleTextColor`, `headerBackground`, `headerTextColor`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (20 options) → CSS custom properties + `[mcp_ai_chat ...]` shortcode
- **Output:** Floating bubble button (SVG chat icon) + slide-out panel containing deferred `[mcp_ai_chat]` output; inline `<script>` to populate `window.wpMcpAiChatInstances`
- **Enqueues:** `wp-mcp-ai-chat-bubble` (JS), `wp-mcp-ai-chat-bubble-style` (CSS)
- **Depends on:** `WP_MCP_AI_Shortcode`, `chat-bubble.js` (lazy init), `chat-bubble-block.js` (editor)
- **Registered by:** `WP_MCP_AI_Assistant_Builder_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3, `html: false`
- Color sanitization via `sanitize_hex_color()`, attributes via `absint`/`sanitize_key`
- Deferred chat init: renames `data-wp-mcp-ai-chat` → `data-wp-mcp-ai-chat-deferred` until bubble opens
- ARIA attributes: `role="dialog"`, `aria-expanded`, `aria-hidden`, `inert` on closed panel
- Inline config script uses `JSON_HEX_TAG | JSON_HEX_AMP` to prevent XSS

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/chat-ui.md`
- `.context/security-checklist.md`
