# AI Chat Block

## Purpose

Displays an AI chat interface powered by NV oOS. Delegates rendering to the `[mcp_ai_chat]` shortcode, which handles the full chat UI including streaming responses, transcript saving, and tool execution.

## Tier

**Base** / PHP 7.4+

## Public Surface

- **Block name:** `mcp-ai-wpoos/chat`
- **Category:** `mcp-ai-wpoos`
- **Attributes:** `assistantId`, `allowGuests`, `saveTranscript`, `enableStreaming`, `allowSensitiveTools`, `showBuildButton`, `placeholder`, `template` (classic|speech-bubbles|compact|sidebar)
- **Supports:** `align` (wide, full), `anchor`, spacing

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (8 options) → built into `[mcp_ai_chat ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div; shortcode internally enqueues `chat.js` and `chat.css`
- **Depends on:** `WP_MCP_AI_Shortcode::render_shortcode()`
- **Registered by:** `WP_MCP_AI_Assistant_Builder_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3, `html: false`
- Sanitizes at entry (`absint`, `sanitize_key`, `bool` casts)
- Shortcode delegation pattern (block wraps existing shortcode infrastructure)
- Non-block fallback path for direct PHP includes

## Tests

No dedicated block tests exist. Chat shortcode is tested indirectly via integration tests.

## Also Load

- `.context/conventions.md`
- `.context/chat-ui.md`
- `.context/rest-api.md`
- `.context/security-checklist.md`
