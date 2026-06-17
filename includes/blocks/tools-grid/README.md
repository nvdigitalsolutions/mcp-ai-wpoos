# AI Tools Grid Block

## Purpose

Displays a grid of available AI tools that users can browse, filter, and enable/disable. Tools are grouped by category and shown with optional descriptions and selection actions.

## Tier

**Base** / PHP 7.4+

## Public Surface

- **Block name:** `mcp-ai-wpoos/tools-grid`
- **Category:** `mcp-ai-wpoos`
- **Attributes:** `title`, `description`, `showDescriptions`, `startCollapsed`, `showActions`, `selectedTools` (array)

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (6 options); fetches tools from `WP_MCP_AI_Tool_Registry`
- **Output:** Server-side rendered HTML with tool checkboxes grouped by category, inline `<script type="application/json">` with tool data
- **Used by:** `assistant-builder/render.php` (rendered inline when `showToolsGrid=true`)
- **Depends on:** `WP_MCP_AI_Tool_Registry`, `WP_MCP_AI_Tool_Interface`, block editor JS, frontend JS
- **Registered by:** `WP_MCP_AI_Assistant_Builder_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3, `html: false`
- Tool data sourced from `WP_MCP_AI_Tool_Registry::get_tools()` and grouped via `get_tool_group_map()` / `get_tool_group_labels()`
- Selected tools tracked as slug array in `selectedTools` attribute

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/tool-registry.md`
- `.context/security-checklist.md`
