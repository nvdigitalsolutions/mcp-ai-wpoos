# Phase 4 Prerequisite — Pro Entity Storage Audit (Q5)

- **Date:** 2026-10-20
- **Status:** Complete — unblocks Phase 4 planning (the Records feature's data path)
- **Answers:** gap-analysis open question Q5 (CCT exposure) and Q2's data-layer half
- **Companion:** [`nvoos-complete-console-admin-migration-implementation.md`](./nvoos-complete-console-admin-migration-implementation.md) §6

## 1. Finding — the core entities are WP-REST-ready

The Pro entity estate is **predominantly custom post types**, and the
CRM/PM core already exposes `show_in_rest => true`, so the Phase 4 Records
feature can consume WordPress core REST (`wp/v2/{post_type}`) directly for
most toolkits — no bespoke Pro controller needed for the read side.

| Entity | Post type | `show_in_rest` | Phase 4 path |
|---|---|---|---|
| Company | `mcp_ai_company` | ✅ true | `wp/v2/mcp_ai_company` |
| Customer | `mcp_ai_customer` | ✅ true | `wp/v2/mcp_ai_customer` |
| Deal | `mcp_ai_deal` | ✅ true | `wp/v2/mcp_ai_deal` |
| Lead | `mcp_ai_lead` | ✅ true | `wp/v2/mcp_ai_lead` |
| Support ticket | `mcp_ai_ticket` | ✅ true | `wp/v2/mcp_ai_ticket` |
| Project | `mcp_ai_project` | ✅ true | `wp/v2/mcp_ai_project` |
| Task | `mcp_ai_task` | ✅ true | `wp/v2/mcp_ai_task` |
| Event | `mcp_ai_event` | ✅ true | `wp/v2/mcp_ai_event` |
| Appointment | `mcp_appointment` | ❌ false | Pro controller (calendar-booking has its own REST) |
| Financial account / txn | `mcp_ai_fin_account`, `mcp_ai_fin_txn` | mixed | Per-toolkit check before port |
| F&B (stock/purchase/sale/waste/booking) | `mcp_ai_fnb_*` | mixed | F&B universalisation track owns the schema (Proposal 078) — consume its REST |
| COMIC/arch/qms/docs/fashion/outbound-booking | various `mcp_ai_*` | mixed | Per-toolkit check before port |

## 2. JetEngine CCT usage (the minority path)

CCT-backed storage appears for: channel messages/contacts (`mcp_chan_*`),
execution history, autonomous sessions, health-records consolidation,
CRM member research, PM command-center views, and orchestration dashboards.
JetEngine CCTs have **no native REST**, so any CCT-backed surface needs a
small Pro REST controller that reads/writes through `jet_engine()->cct`
guarded by `class_exists()` (the base plugin already ships this pattern in
the JetEngine tool handlers).

## 3. Records-feature data adapter (resolves Q2's data-layer half)

One configurable adapter with three storage strategies, selected per entity
config:

1. **`wp-core`** — `wp/v2/{post_type}` (the CRM/PM default; auth = cookie
   nonce; capability via the CPT's `capability_type`).
2. **`pro-controller`** — a toolkit's existing REST surface (calendar-booking,
   F&B) or a new thin controller for `show_in_rest=false` CPTs / CCTs.
3. **`cct-bridge`** — a new `mcp-ai-pro/v1/records/{entity}` controller for
   CCT-backed entities (channel messages, health records), gated on
   JetEngine presence.

The React side is one feature (list/filter/detail/import/create-from-research)
parameterized by the entity config — storage strategy is config, not code.

## 4. Recommended Phase 4 order (after this audit)

1. **CRM command center** — companies/customers/deals/leads/tickets are all
   `wp-core` strategy; the highest product value, the cheapest port.
2. **PM command center** — projects/tasks/events, same strategy.
3. **Calendar booking** — consume the existing calendar REST.
4. **F&B** — consume the universalisation track's REST.
5. **Media / Chat Channels / Health / remaining toolkits** — per-toolkit
   checks against §1/§2 before each port.

## 5. Open items (no longer blocking)

- Q2's UX half (one generic Records feature vs per-toolkit pages) remains a
  product call; the data layer supports either — the generic feature is
  still recommended.
- `mcp_ai_fin_*` and the remaining mixed CPTs need a two-minute per-file
  check at port time (same grep as §1).
