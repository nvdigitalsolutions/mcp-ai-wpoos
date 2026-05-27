# AI Tool Builder Schemas Block

## Purpose

Displays parameter schemas from the AI Tool Builder toolkit. Renders schema listings via the `[mcp_ai_tool_builder_schemas]` shortcode.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/ai-tool-builder-schemas`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `display` (list), `limit` (default 30)
- **Text domain:** `mcp-ai-wpoos-pro`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (2 options) → built into `[mcp_ai_tool_builder_schemas ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** AI Tool Builder Pro shortcode handler
- **Registered by:** `WP_MCP_AI_Pro_Toolkit_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3
- Shortcode delegation pattern (block wraps Pro shortcode infrastructure)
- Sanitizes at entry (`esc_attr`, `absint`)

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/pro-vs-base.md`
- `.context/security-checklist.md`
