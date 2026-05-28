# AI Assistant Builder Block

## Purpose

Composite Gutenberg block that provides a complete interface for building new AI assistants. Combines assistant selection, tools grid, knowledge base upload, and a chat UI into a single configurable layout. Requires `edit_posts` capability.

## Tier

**Base** / PHP 7.4+

## Public Surface

- **Block name:** `mcp-ai-wpoos/assistant-builder`
- **Category:** `mcp-ai-wpoos`
- **Attributes:** `showAssistantSelector`, `showToolsGrid`, `showKnowledgeBase`, `showBuildButton`, `defaultAssistantId`, `layout` (stacked|side-by-side), `toolsCollapsed`, `showToolDescriptions`, `enableStreaming`, `chatPlaceholder`, `allowedFileTypes`, `maxFiles`, `maxFileSizeMB`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (14 configurable options)
- **Output:** Server-side rendered HTML with inline `<script type="application/json">` config block and JavaScript initialization data (REST URLs, nonces)
- **Inline includes:** `knowledge-base/render.php`, `tools-grid/render.php` (composes sub-blocks internally)
- **Depends on:** `WP_MCP_AI_Assistant_CPT`, `WP_MCP_AI_Shortcode`, REST API `mcp-ai/v1`, block editor JS (`assistant-builder-blocks.js`), frontend JS (`assistant-builder-blocks-frontend.js`), CSS (`assistant-builder-blocks.css`)
- **Registered by:** `WP_MCP_AI_Assistant_Builder_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3, `html: false`
- Sanitizes `$attributes` at entry (`absint`, `sanitize_html_class`, `sanitize_text_field`)
- Escapes all output via `esc_attr()`, `esc_html()`, `wp_json_encode()`, or `get_block_wrapper_attributes()`
- Non-block fallback path with `esc_attr()` for non-standard rendering contexts
- Capability check (`current_user_can('edit_posts')`) at render entry

## Tests

No dedicated block tests exist. Coverage is indirect via integration/acceptance tests.

## Also Load

- `.context/conventions.md`
- `.context/rest-api.md`
- `.context/chat-ui.md`
- `.context/security-checklist.md`
- `.context/tool-registry.md`
