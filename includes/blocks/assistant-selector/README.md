# Assistant Selector Block

## Purpose

A standalone dropdown block that lets users select from available AI assistants. Optionally displays a "Start Chat" button to initiate a conversation with the chosen assistant.

## Tier

**Base** / PHP 7.4+

## Public Surface

- **Block name:** `mcp-ai-wpoos/assistant-selector`
- **Category:** `mcp-ai-wpoos`
- **Attributes:** `defaultAssistantId`, `label`, `showStartButton`, `startButtonText`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (4 options); fetches assistant list from `WP_MCP_AI_Assistant_CPT`
- **Output:** `<select>` dropdown with JSON-encoded tool/shortcut data attributes and an optional "Start Chat" button
- **Used by:** `assistant-builder/render.php` (rendered inline when `showAssistantSelector=true`)
- **Depends on:** `WP_MCP_AI_Assistant_CPT`, `wp_unique_id()`
- **Registered by:** `WP_MCP_AI_Assistant_Builder_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3, `html: false`
- Escapes all output (`esc_attr`, `esc_html`, `wp_json_encode`)
- Uses `selected()` for default value matching

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/security-checklist.md`
