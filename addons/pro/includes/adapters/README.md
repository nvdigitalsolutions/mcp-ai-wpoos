# Adapters

## Purpose

Booking-system adapters that let Calendar Booking and Places tools interact
with third-party booking plugins (JetAppointment, JetBooking) without
hardcoding vendor-specific logic.

## Public Surface

- `WP_MCP_AI_Booking_Adapter_Interface` — contract every adapter must satisfy
- `WP_MCP_AI_Booking_Adapter_Factory` — detection + lazy instantiation
- `WP_MCP_AI_JetAppointment_Adapter` — JetAppointment REST API bridge
- `WP_MCP_AI_JetBooking_Adapter` — JetBooking REST + DB bridge

## Conventions

- Every adapter's `is_available()` must check for plugin class, DB tables, AND
  configuration — not just `is_plugin_active()`.
- All public methods return canonical envelope (success array or WP_Error).
- Adapters cache provider/service lists in transients (5 min TTL).
- Never call adapter methods from tools that haven't checked `is_available()` first.
- Credentials must come from the Password Vault, never from raw option keys.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | `addons/pro/mcp-ai-wpoos-pro.php` (conditionally when JetEngine/Jet_Booking are present) |
| **Optional dependencies** | JetEngine, JetAppointment, JetBooking (Crocoblock) |

## Inputs / Outputs / Neighbors

- **Reads from:** JetAppointment REST API (`/wp-json/jet-engine/v2/appointment-*`), JetBooking REST API (`/wp-json/jet-booking/v2/`), JetBooking database tables (`wp_jet_apartment_*`), Password Vault for credentials.
- **Writes to:** JetAppointment/JetBooking via their REST APIs (create/update/cancel bookings).
- **Upstream callers:** Calendar Booking Toolkit tools, Places Toolkit tools, WP-CLI commands.
- **Downstream collaborators:** WordPress HTTP API, `$wpdb`.

## Tests

```bash
vendor/bin/phpunit --filter JetAppointment_Adapter addons/pro/tests/adapters/
vendor/bin/phpunit --filter JetBooking_Adapter addons/pro/tests/adapters/
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — capability + nonce rules
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat, tool sanitisation, canonical envelope

## See Also

- Calendar Booking toolkit: [`../tools/calendar-booking/README.md`](../tools/calendar-booking/README.md)
- Places toolkit: [`../tools/places/README.md`](../tools/places/README.md)
- JetAppointment REST API: https://gist.github.com/Crocoblock/b0797f1011bdae579e2a4893e12d6ce2
