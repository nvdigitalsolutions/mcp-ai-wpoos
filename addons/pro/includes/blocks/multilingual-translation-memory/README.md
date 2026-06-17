# Translation Memory Block

## Purpose

Displays translation memory entries from the Multilingual toolkit with language pair filtering and quality score threshold. Renders via the `[mcp_multilingual_translation_memory]` shortcode.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/multilingual-translation-memory`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `display` (list), `source_language`, `target_language`, `quality_score_min` (default 0), `limit` (default 20)
- **Text domain:** `mcp-ai-wpoos-pro`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (5 options) → built into `[mcp_multilingual_translation_memory ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** Multilingual Pro shortcode handler
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
