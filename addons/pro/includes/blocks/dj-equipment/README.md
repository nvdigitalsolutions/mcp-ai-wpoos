# DJ Equipment Block

## Purpose

Displays equipment from the DJ Management toolkit in a configurable grid layout. Renders via the `[mcp_dj_equipment]` shortcode with optional category filtering and status display.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/dj-equipment`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `display` (grid), `columns` (default 3), `category`, `show_status` (default true)
- **Text domain:** `mcp-ai-wpoos-pro`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (4 options) → built into `[mcp_dj_equipment ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** DJ Management Pro shortcode handler
- **Registered by:** `WP_MCP_AI_Pro_Toolkit_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3
- Shortcode delegation pattern; `columns` only appended when `display=grid`
- Boolean atts mapped to `"yes"` / omitted
- Sanitizes at entry (`esc_attr`, `absint`)

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/pro-vs-base.md`
- `.context/security-checklist.md`
