# Calendar Services Block

## Purpose

Displays services from the Calendar toolkit in a configurable grid layout. Renders via the `[mcp_calendar_services]` shortcode.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/calendar-services`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `display` (grid), `columns` (default 3), `show_price` (default true), `show_duration` (default true)
- **Text domain:** `mcp-ai-wpoos-pro`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (4 options) → built into `[mcp_calendar_services ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** Calendar Pro shortcode handler
- **Registered by:** `WP_MCP_AI_Pro_Toolkit_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3
- Shortcode delegation pattern
- Sanitizes at entry (`esc_attr`, `absint`)

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/pro-vs-base.md`
- `.context/security-checklist.md`
