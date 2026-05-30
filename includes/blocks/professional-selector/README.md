# Professional Selector Chat Block

## Purpose

Allows users to select a professional, AI provider, and model before starting a chat session. Delegates rendering to the `[mcp_ai_professional_selector]` shortcode.

## Tier

**Base** / PHP 7.4+

## Public Surface

- **Block name:** `mcp-ai-wpoos/professional-selector`
- **Category:** `mcp-ai-wpoos`
- **Attributes:** `defaultProfessional`, `defaultProvider`, `defaultModel`, `showTemperature`, `allowGuests`, `saveTranscript`, `enableStreaming`, `allowSensitiveTools`, `template` (classic|speech-bubbles|compact|sidebar)
- **Supports:** `align` (wide, full), `anchor`, spacing

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (9 options) → built into `[mcp_ai_professional_selector ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** `WP_MCP_AI_Professional_Selector_Shortcode::render_shortcode()`
- **Registered by:** `WP_MCP_AI_Assistant_Builder_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3, `html: false`
- Sanitizes at entry (`sanitize_text_field`, `sanitize_key`, `bool` casts)
- Shortcode delegation pattern
- Non-block fallback path

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/chat-ui.md`
- `.context/security-checklist.md`
