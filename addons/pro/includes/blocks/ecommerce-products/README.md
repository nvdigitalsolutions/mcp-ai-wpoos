# E-commerce Products Block

## Purpose

Displays products from the E-commerce toolkit in a configurable grid or list. Renders via the `[mcp_ecommerce_products]` shortcode with category filtering, ordering, and pagination.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/ecommerce-products`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `display` (grid), `columns` (default 3), `limit` (default 9), `category`, `orderby` (date), `order` (desc)
- **Text domain:** `mcp-ai-wpoos-pro`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (6 options) → built into `[mcp_ecommerce_products ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** E-commerce Pro shortcode handler
- **Registered by:** `WP_MCP_AI_Pro_Toolkit_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3
- Shortcode delegation pattern; `columns` only appended when `display=grid`
- Sanitizes at entry (`esc_attr`, `absint`)

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/pro-vs-base.md`
- `.context/security-checklist.md`
