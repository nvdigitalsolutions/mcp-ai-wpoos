# Scheduled Result Block

## Purpose

Renders the latest run output of an NV oOS Pro Schedule as a live dashboard tile. Uses `ServerSideRender` in the editor for live preview and a dedicated PHP renderer class on the frontend.

## Tier

**Base** / PHP 7.4+

## Public Surface

- **Block name:** `mcp-ai-wpoos/scheduled-result`
- **Category:** `widgets`
- **Attributes:** `scheduleId`, `renderMode` (summary-card|list|table|metric|timeline|raw), `title`, `showLastRun`, `refreshIntervalSec`, `historyLimit`, `truncateChars`
- **Supports:** `align` (wide, full), `html: false`
- **Editor script:** `edit.js` (React block editor with `ServerSideRender`, schedule picker, preview trigger)

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (7 options) → passed to `WP_MCP_AI_Scheduled_Result_Renderer::render()`
- **Output:** Rendered schedule envelope HTML (renderer escapes all output internally)
- **Editor:** `edit.js` fetches schedules from `/mcp-ai-pro/v1/schedules?selectable=1`, provides InspectorControls (render mode, title, refresh, truncate, preview button)
- **Depends on:** `WP_MCP_AI_Scheduled_Result_Renderer` (in `includes/renderers/`), Pro REST API
- **Registered by:** `WP_MCP_AI_Assistant_Builder_Blocks::register_blocks()` (core class; block itself is base-tier but requires Pro schedules)

## Conventions

- Dynamic block; no `render` key in `block.json` (server-side via registered render callback); API Version 3
- Renderer class pattern (not shortcode delegation)
- `save()` returns `null` (dynamic block)

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/rest-api.md`
- `.context/pro-vs-base.md`
- `.context/security-checklist.md`
