# Social Media Calendar Block

## Purpose

Displays a social media content calendar from the Social Media toolkit with platform filtering and status/preview display. Renders via the `[mcp_social_media_calendar]` shortcode.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/social-calendar`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `view` (month), `platform`, `show_status` (default true), `show_preview` (default true)
- **Text domain:** `mcp-ai-wpoos-pro`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (4 options) → built into `[mcp_social_media_calendar ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** Social Media Pro shortcode handler
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
