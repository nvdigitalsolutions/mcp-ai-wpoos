# Calendar Booking Form Block

## Purpose

Displays a booking form from the Calendar toolkit. Renders via the `[mcp_calendar_booking_form]` shortcode with configurable service, staff, and display options.

## Tier

**Pro** / PHP 8.1+

## Public Surface

- **Block name:** `mcp-ai-toolkits/calendar-booking`
- **Category:** `mcp-ai-toolkits`
- **Attributes:** `service`, `staff`, `show_calendar` (default true), `show_time_slots` (default true)
- **Text domain:** `mcp-ai-wpoos-pro`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (4 options) → built into `[mcp_calendar_booking_form ...]` shortcode string
- **Output:** `do_shortcode()` result wrapped in `get_block_wrapper_attributes()` div
- **Depends on:** Calendar Pro shortcode handler
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
