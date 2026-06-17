# Financial Goals Block

## Purpose

Displays financial goals from the Financial toolkit in a configurable grid with progress tracking. Renders via the `[mcp_financial_goals]` shortcode.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/financial-goals`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `display` (grid), `columns` (default 3), `status` (all), `show_progress` (default true)
- **Text domain:** `mcp-ai-wpoos-pro`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (4 options) → built into `[mcp_financial_goals ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** Financial Pro shortcode handler
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
