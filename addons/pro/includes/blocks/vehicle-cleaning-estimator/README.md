# Vehicle Cleaning Estimator Block

## Purpose

Interactive AI-powered car-wash / detailing quote widget. Accepts vehicle photos and returns a full line-item estimate. Renders via the `[mcp_vehicle_cleaning_estimator]` shortcode with currency, tax, and UI customization options.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/vehicle-cleaning-estimator`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `assistant_id`, `primary_color`, `show_package_selector` (default true), `show_addon_selector` (default true), `currency` (CAD), `tax_rate`, `placeholder_text`, `cta_label`
- **Text domain:** `mcp-ai-wpoos-pro`
- **Supports:** `align` (wide, full), spacing

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (8 options) → built into `[mcp_vehicle_cleaning_estimator ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** Vehicle Cleaning Pro shortcode handler
- **Registered by:** `WP_MCP_AI_Pro_Toolkit_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3
- Shortcode delegation pattern; boolean atts mapped to `"no"` (not `"yes"`) when false
- `currency` only output when non-default (not `CAD`); `tax_rate` passed as `floatval`
- Sanitizes at entry (`esc_attr`, `floatval`)

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/pro-vs-base.md`
- `.context/security-checklist.md`
- `.context/chat-ui.md`
