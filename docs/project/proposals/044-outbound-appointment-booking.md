# Proposal 044 — Outbound Appointment Booking Toolkit

- **Status:** In progress
- **Branch:** `feat/outbound-appointment-booking`
- **Target:** Pro addon (`addons/pro`), PHP 8.1+, gated by `enable_outbound_booking_toolkit`

## Goal

Ship a managed-style outbound system for marketing agency owners, B2B coaches, and
consultants: a pipeline that sits between the operator's service and new prospects and
produces a steady flow of booked sales calls, so founders stop doing manual outreach and
focus on closing.

Modeled on the public playbook of JKD Agency-style operations:

1. Offer & positioning — booking links carry the offer copy (headline, value props, call
   title) so prospects understand the value before they book.
2. ICP list building — CSV import of decision-maker lists into `mcp_ai_lead` with ICP
   scoring via the existing CRM ICP scorer.
3. Multi-channel outbound automation — cold email (auto-send with consent attestation),
   LinkedIn DMs and Instagram DMs (human-approval board or webhook hand-off to
   Make/n8n/Instantly-style automation).
4. Sequences & scripts — a sequence library (reusing `mcp_ai_sequence` steps), an angle
   bank, an objection matrix, and weekly A/B tests that promote the winning variant.
5. Appointment booking — booking links render offer pages with a booking form or external
   calendar (Calendly/Cal.com); submissions create `mcp_appointment` records, mark the
   lead booked, and ping Slack.
6. Ongoing optimization & support — daily digest to Slack/email, weekly test promotion,
   pipeline dashboard.

## Architecture

New toolkit folder `addons/pro/includes/tools/outbound-booking/`, loaded through the Pro
module registry behind the `enable_outbound_booking_toolkit` setting. The toolkit
*composes* existing Pro infrastructure instead of duplicating it:

- `mcp_ai_lead` + `mcp_ai_sequence` (CRM toolkit) — prospects and cadence definitions.
- `mcp_appointment` (Calendar Booking toolkit) — the booked call record.
- `WP_MCP_AI_ICP_Scorer` / `WP_MCP_AI_ICP_Profile` — ICP scoring at import time.
- `WP_MCP_AI_CRM_Activity_CPT` / `WP_MCP_AI_CRM_Audit` — activity and audit trails.
- `WP_MCP_AI_CRM_Message_Log` — inbound reply ingestion hook.

New entities (CPTs): `mcp_ai_oa_angle` (angle bank + objection matrix + test stats),
`mcp_ai_oa_booking` (offer + calendar routing), `mcp_ai_oa_outbox` (approval board
and delivery log).

## Files

```
addons/pro/includes/tools/outbound-booking/
├── init.php                                   # gate + loader + tool registration filter
├── class-wp-mcp-ai-oa-settings.php            # wp_mcp_ai_outbound_settings accessor
├── class-wp-mcp-ai-oa-templates.php           # {{token}} renderer
├── class-wp-mcp-ai-oa-angle-cpt.php           # angle bank / objection matrix CPT
├── class-wp-mcp-ai-oa-booking-link-cpt.php    # booking offer CPT
├── class-wp-mcp-ai-oa-outbox.php              # approval board / delivery log CPT
├── class-wp-mcp-ai-oa-channels.php            # email / LinkedIn / Instagram dispatch
├── class-wp-mcp-ai-oa-engine.php              # hourly tick, replies, weekly tests
├── class-wp-mcp-ai-oa-notifications.php       # Slack webhook + daily digest
├── class-wp-mcp-ai-oa-booking.php             # shortcode + booking creation
├── class-wp-mcp-ai-oa-import.php              # CSV ICP list building
├── class-wp-mcp-ai-oa-rest.php                # bookings / replies / pipeline routes
├── class-wp-mcp-ai-oa-dashboard.php           # command dashboard admin page
├── tools/                                     # 3 MCP tools
└── README.md
```

## Safety rails

- Human approval is the default for LinkedIn and Instagram DMs (no native send APIs);
  email auto-send requires per-lead consent attestation set at import.
- Send windows, daily caps, and per-channel modes are settings-driven.
- Public booking REST endpoint is rate-limited, honeypotted, and requires explicit
  consent; reply ingestion requires a shared token.
- Weekly test promotion only promotes a champion after a minimum-send threshold.

## Follow-ups (deferred)

- `instagram_dm` added to `WP_MCP_AI_CRM_Codes::CHANNELS` (additive).
- Optional AI first-line personalization via the `wp_mcp_ai_oa_render_template` filter.
- CLI toolkit listings for the new toolkit.
