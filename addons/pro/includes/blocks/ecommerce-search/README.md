# E-commerce Product Search Block

## Purpose

Displays a product search interface from the E-commerce toolkit with optional filters and sorting. Renders via the `[mcp_ecommerce_search]` shortcode.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/ecommerce-search`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `placeholder` (default "Search products..."), `show_filters` (default true), `show_sorting` (default true), `results_per_page` (default 10)
- **Text domain:** `mcp-ai-wpoos-pro`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (4 options) → built into `[mcp_ecommerce_search ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** E-commerce Pro shortcode handler
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
