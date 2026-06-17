# Calendar Booking

## Purpose

Registers the three Custom Post Types that back the Calendar Booking toolkit — `mcp_appointment`, `mcp_service`, and `mcp_staff` — and nothing else; all admin pages, booking flows, slot/conflict logic, and tools live in neighbouring folders.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/calendar-booking-toolkit-init.php` (always `require_once`s the three CPT files for early CPT registration; admin pages and tools are loaded conditionally there) |
| **Optional dependencies** | none (the CPTs short-circuit `init()` when `enable_calendar_booking_toolkit` is off or when running in Base mode without the Pro addon) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Appointment_CPT` (`POST_TYPE = mcp_appointment`) | `class-wp-mcp-ai-appointment-cpt.php` | Calendar-booking tools, research pages, schedule presets |
| `WP_MCP_AI_Service_CPT` (`POST_TYPE = mcp_service`) | `class-wp-mcp-ai-service-cpt.php` | Calendar-booking tools, `Calendar_Booking_Research_Add` |
| `WP_MCP_AI_Staff_CPT` (`POST_TYPE = mcp_staff`) | `class-wp-mcp-ai-staff-cpt.php` | Calendar-booking tools, `Calendar_Booking_Research_Add` |

External callers should reference the `POST_TYPE` constants — never the raw slug strings. Metabox internals and admin notice methods are not part of the public surface.

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` option (the `enable_calendar_booking_toolkit` feature flag); per-post meta managed by each CPT's metaboxes.
- **Writes to:** `wp_posts` / `wp_postmeta` for the three CPTs.
- **Upstream callers:** `calendar-booking-toolkit-init.php` (registration), `addons/pro/includes/admin/class-wp-mcp-ai-calendar-booking-research-page.php`, `addons/pro/includes/admin/class-wp-mcp-ai-calendar-booking-settings-page.php`, `addons/pro/includes/research-add/class-wp-mcp-ai-calendar-booking-research-add.php`, and the calendar-booking tools under `addons/pro/includes/tools/calendar-booking/`.
- **Downstream collaborators:** WordPress core CPT API only — this folder deliberately has no service-layer dependencies.
- **Events fired:** the standard WordPress `init`, `save_post_{type}`, and `add_meta_boxes_{type}` actions for each CPT.
- **Events listened to:** `init` (CPT + taxonomy registration), `admin_notices` (disabled-state notice), `save_post_{type}` (meta persistence).

## Conventions

- Each CPT is **feature-gated twice**: first by the Base-vs-Pro check (`wp_mcp_ai_is_base_version()` + `WP_MCP_AI_PRO_VERSION`), then by the `enable_calendar_booking_toolkit` setting. Both gates must remain in `init()` — do not move them into the constructor or `register_post_type()` callback.
- Always reference post types via the class `POST_TYPE` constant, never the literal slug.
- New entity types belong in their own `class-wp-mcp-ai-{entity}-cpt.php` file in this folder; the Research & Add UI auto-discovers them through the `Calendar_Booking_Research_Add` entity map.
- This folder is CPT-registration only. Booking-slot maths, conflict detection, and calendar sync logic must live under `addons/pro/includes/tools/calendar-booking/` or a future `services/` sibling — keep this folder thin.

## Tests

There is no dedicated PHPUnit suite for these CPTs yet; coverage is currently exercised indirectly through the calendar-booking tool tests under `addons/pro/tests/` and the schedule-preset tests. Run the closest existing slice with:

```bash
vendor/bin/phpunit --filter Calendar_Booking addons/pro/tests/
```

Direct CPT-registration tests (`test-appointment-cpt.php`, `test-service-cpt.php`, `test-staff-cpt.php`) are a known gap — add them alongside future tool work.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — capability + nonce rules for metabox saves
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — explains the double feature-gate pattern
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — for downstream calendar-booking tools
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat policy, tool sanitisation, canonical envelope

## See Also

- Toolkit bootstrap: [`../calendar-booking-toolkit-init.php`](../calendar-booking-toolkit-init.php)
- Admin pages: [`../admin/class-wp-mcp-ai-calendar-booking-research-page.php`](../admin/class-wp-mcp-ai-calendar-booking-research-page.php), [`../admin/class-wp-mcp-ai-calendar-booking-settings-page.php`](../admin/class-wp-mcp-ai-calendar-booking-settings-page.php)
- Research & Add UI: [`../research-add/class-wp-mcp-ai-calendar-booking-research-add.php`](../research-add/class-wp-mcp-ai-calendar-booking-research-add.php)
- Block renderer: [`../blocks/calendar-booking/`](../blocks/calendar-booking/)
- Tools (planned/active under): [`../tools/calendar-booking/`](../tools/calendar-booking/)
