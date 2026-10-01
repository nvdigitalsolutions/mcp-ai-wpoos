# Outbound Booking

## Purpose

The Outbound Appointment Booking toolkit: an automated multi-channel outbound system that turns decision-maker lists into a steady flow of booked sales calls. It composes the CRM toolkit (leads, sequences, ICP scorer, activity/audit trails) and the Calendar Booking toolkit (`mcp_appointment`), adding the automation engine, angle bank + objection matrix, approval board, booking links, Slack notifications, and a command dashboard.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` (conditional toolkit `outbound_booking`, key `enable_outbound_booking_toolkit`) → `tools/outbound-booking/init.php` |
| **Optional dependencies** | CRM Toolkit (`enable_crm_toolkit`) for leads/sequences; Calendar Booking Toolkit (`enable_calendar_booking_toolkit`) for internal booking forms. External-calendar booking links work without either, but the engine degrades gracefully when CRM is off. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_OA_Settings` | `class-wp-mcp-ai-oa-settings.php` | Everything (single settings source of truth) |
| `WP_MCP_AI_OA_Templates` | `class-wp-mcp-ai-oa-templates.php` | Engine, booking, tools |
| `WP_MCP_AI_OA_Angle_CPT` (`POST_TYPE = mcp_ai_oa_angle`) | `class-wp-mcp-ai-oa-angle-cpt.php` | Engine, weekly tests, dashboard, `outbound_manage_angle` |
| `WP_MCP_AI_OA_Booking_Link_CPT` (`POST_TYPE = mcp_ai_oa_booking`) | `class-wp-mcp-ai-oa-booking-link-cpt.php` | Engine, booking, dashboard |
| `WP_MCP_AI_OA_Outbox` (`POST_TYPE = mcp_ai_oa_outbox`) | `class-wp-mcp-ai-oa-outbox.php` | Engine, channels, dashboard, admin board |
| `WP_MCP_AI_OA_Channels` | `class-wp-mcp-ai-oa-channels.php` | Engine, outbox transitions |
| `WP_MCP_AI_OA_Engine` | `class-wp-mcp-ai-oa-engine.php` | Crons, REST, dashboard, tools |
| `WP_MCP_AI_OA_Notifications` | `class-wp-mcp-ai-oa-notifications.php` | Engine, booking |
| `WP_MCP_AI_OA_Booking` | `class-wp-mcp-ai-oa-booking.php` | Shortcode `[nvoos_oa_booking link="slug"]`, REST |
| `WP_MCP_AI_OA_Import` | `class-wp-mcp-ai-oa-import.php` | Dashboard import tab, `outbound_import_leads` |
| `WP_MCP_AI_OA_REST` | `class-wp-mcp-ai-oa-rest.php` | Booking page JS, automation platforms |
| `WP_MCP_AI_OA_Dashboard` | `class-wp-mcp-ai-oa-dashboard.php` | Admin menu `wp-mcp-ai-outbound` |

External callers should reference the `POST_TYPE` constants and the static helpers — never raw slugs or meta keys.

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_outbound_settings` (toolkit settings, no-autoload); `wp_mcp_ai_settings` (toolkit toggles); CRM lead/sequence meta (`_active_sequence_id`, `_sequence_step`, `_sequence_paused`, lead fields, sequence `steps` meta); appointment meta.
- **Writes to:** the three `oa_*` CPTs; lead meta (`_oa_*` keys: `_oa_status`, `_oa_next_due`, `_oa_last_message_at`, `_oa_email_consent`, `_oa_booking_link_id`, `_oa_appointment_id`, `_oa_linkedin`, `_oa_instagram`, …); `mcp_appointment` records (internal bookings).
- **Upstream callers:** module registry; CRM `wp_mcp_ai_crm_message_logged` (inbound replies).
- **Events fired:** `wp_mcp_ai_oa_before_dispatch`, `wp_mcp_ai_oa_after_dispatch`, `wp_mcp_ai_oa_after_booking`, `wp_mcp_ai_oa_after_import`.
- **Events listened to:** `init` (scheduling), `wp_mcp_ai_crm_message_logged`, cron hooks `wp_mcp_ai_oa_tick` (hourly), `wp_mcp_ai_oa_weekly_tests` (weekly), `wp_mcp_ai_oa_digest` (daily).

## Conventions

- Every outbound lead-state change goes through the `_oa_status` lifecycle: `new` → `active` → `replied`/`replied_positive`/`stopped` → `booked`/`completed`.
- Messages always pass through the outbox; dispatch happens only per channel mode (`auto` | `approval` | `webhook` | `off`). Email auto-send requires `_oa_email_consent` on the lead.
- Sequence step `template_id` values reference angle post IDs or slugs (see `WP_MCP_AI_OA_Angle_CPT::resolve()`).
- The tick is bounded: per-run lead cap, daily send cap, send-window hours — keep those in `run_tick()`.
- The public booking REST route is rate-limited, honeypotted, and consent-gated; reply ingestion requires the `X-OA-Token` header matching `reply_token`.

## Tests

PHPUnit suites live in `addons/pro/tests/tools/outbound-booking/`:

```bash
vendor/bin/phpunit --filter Outbound addons/pro/tests/tools/outbound-booking/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — capability + nonce rules
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — feature-gate pattern
- [`../crm/README.md`](../crm/README.md) — the CRM engine this toolkit composes
- [`CLAUDE.md`](../../../../../CLAUDE.md) — PHP-compat, tool sanitisation, canonical envelope

## See Also

- Proposal: [`docs/project/proposals/044-outbound-appointment-booking.md`](../../../../../docs/project/proposals/044-outbound-appointment-booking.md)
- CRM sequence model: [`../crm/sequences/`](../crm/sequences/)
- Calendar booking CPTs: [`../../calendar-booking/`](../../calendar-booking/)
