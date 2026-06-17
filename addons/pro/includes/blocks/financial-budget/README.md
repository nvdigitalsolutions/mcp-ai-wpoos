# Financial Budget Block

## Purpose

Displays budget tracking from the Financial toolkit with configurable view, period, and category/progress display. Renders via the `[mcp_financial_budget]` shortcode.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/financial-budget`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `view` (summary), `period` (month), `show_categories` (default true), `show_progress` (default true)
- **Text domain:** `mcp-ai-wpoos-pro`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (4 options) → built into `[mcp_financial_budget ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** Financial Pro shortcode handler
- **Registered by:** `WP_MCP_AI_Pro_Toolkit_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3
- Shortcode delegation pattern; boolean atts mapped to `"yes"` / omitted
- Sanitizes at entry (`esc_attr`)

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/pro-vs-base.md`
- `.context/security-checklist.md`
