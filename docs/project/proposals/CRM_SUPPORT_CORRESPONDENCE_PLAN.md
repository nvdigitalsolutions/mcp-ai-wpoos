# CRM Support Correspondence Lifecycle — Enhancement Proposal

> **Status:** Proposal — ready for review.
> **Date:** 2026-06-08
> **Scope:** `addons/pro/includes/tools/crm/support/` + supporting infrastructure
> **Companion docs:** `addons/pro/docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md`, `addons/pro/docs/CRM_EMAIL_MARKETING_GUIDE.md`

---

## 1. Executive Summary

The CRM Pro toolkit currently manages the **pre-sale funnel** end-to-end — leads, deals, pipeline forecasting, outreach sequences, multichannel inbox triage, and activity logging. Once a lead converts to `customer`, the lifecycle terminates. There is **no post-sale support correspondence lifecycle**.

This proposal adds a **Support Ticket CPT** (`mcp_ai_support_ticket`) with:

- A **7-stage ticket pipeline** (New → Triaged → In Progress → Waiting on Customer → Waiting on 3rd Party → Resolved → Closed)
- **ITIL-aligned SLA enforcement** (P1–P4 priority matrix with configurable first-response and resolution timers)
- **Reuse of existing infrastructure** — activities CPT for the timeline, leads/contacts as the requester, the CRM engine for defaults/settings, the Zendesk Graphify driver for external sync
- **10 AI tools** for CRUD, classification, escalation, merging, and SLA reporting
- **Dashboard integration** — KPIs, pipeline funnel, and a dedicated Support Tickets tab in the CRM Command Center

The goal is **not** to build a standalone helpdesk — it is to extend the existing CRM surface so that a single agent can handle both sales and support correspondence within one unified command center.

---

## 2. Industry Standards Reviewed

Web research (June 2026) across HubSpot Service Hub, Zendesk, Freshdesk, ServiceNow (ITIL), and Intercom confirms the following are table stakes:

### 2.1 Ticket Lifecycle

| Platform | Pipeline Stages |
|---|---|
| **HubSpot Service Hub** | New → Waiting on Contact → Waiting on Us → Closed (Resolved / Not Solved / Escalated) |
| **Zendesk** | New → Open → Pending → On-Hold → Solved → Closed |
| **Freshdesk** | Open → Pending → Resolved → Closed |
| **ServiceNow (ITIL)** | New → Assigned → In Progress → Pending → Resolved → Closed |
| **Intercom** | New → Snoozed → Waiting → Resolved |

**Consensus:** All major platforms use a pipeline model (identical pattern to the existing Deal CPT) with 4–7 stages, a distinction between "agent-resolved" and "customer-confirmed-closed," and auto-close timers on resolved/waiting states.

### 2.2 Priority & SLA Matrix

Industry-standard P1–P4 priority tiers with configurable response and resolution targets:

| Priority | Label | First Response | Resolution | Schedule |
|---|---|---|---|---|
| **P1** | Critical | 15 min | 4 hours | 24×7 |
| **P2** | High | 1 hour | 8 hours | Business hours |
| **P3** | Medium | 4 hours | 24 hours | Business hours |
| **P4** | Low | 8 hours | 72 hours | Business hours |

Priority is typically derived from an **impact × urgency** matrix (ITIL) or set explicitly by triage. SLA clocks pause when waiting on customer or third party, and auto-escalate when breached.

### 2.3 Key Metadata

Every platform tracks these fields on a ticket:

| Field | HubSpot | Zendesk | Freshdesk | ITIL |
|---|---|---|---|---|
| **Status / Stage** | ✓ | ✓ | ✓ | ✓ |
| **Priority** | Low/Med/High | Low→Urgent | P1–P4 | P1–P4 |
| **Assignee** | Owner | Assignee | Agent | Assigned to |
| **Requester** | Associated contact | Requester | Requester | Caller |
| **Category / Type** | Ticket category | Type (Question/Incident/Problem/Task) | Type | CI class |
| **Source** | Conversations/Email/Form | Channel | Source | — |
| **Tags** | ✓ | ✓ | ✓ | — |
| **CSAT** | ✓ | ✓ | ✓ | — |
| **SLA timer** | Time to close | Due date | Due by | SLA target |
| **Resolution type** | ✓ | — | — | Close code |

### 2.4 Automation & AI

- **Auto-categorisation** — AI classifies inbound as bug/question/feature-request/billing at triage (HubSpot, Intercom, Zendesk Answer Bot)
- **Suggested replies** — Knowledge base article matching + draft reply generation
- **Auto-close** — Resolved tickets auto-close after N days of customer silence (typically 72h–7d)
- **SLA escalation** — Breached tickets auto-reassign or notify managers
- **Sentiment analysis** — Detect frustrated customers for priority bump

---

## 3. Current State vs Target State

### 3.1 What Exists Today (Support-Adjacent)

| Asset | Relevance |
|---|---|
| `support_request` inquiry type | `crm_email_search_leads` already classifies inbound emails including `support_request` — this becomes the intake trigger |
| `sla_breach_only` parameter | `crm_email_search_correspondence` already detects SLA breaches by response time |
| Routing suggestions | Correspondence tool suggests "Route to Customer Support queue" or "Escalate to Senior Support" |
| Zendesk Graphify driver | `class-nvoos-graphify-remote-zendesk.php` syncs Zendesk tickets as graph nodes (read-only) |
| `mcp_ai_crm_activity` CPT | Existing activity logging (calls, emails, notes) — directly reusable as the ticket timeline |
| `mcp_ai_task` with `status=open` | Some preset workflows reference this as a "support queue" — but it has no SLA, no ticket lifecycle, no automations |
| Pipeline stages engine | `WP_MCP_AI_CRM_Engine::get_pipeline_stages()` already supports configurable stage definitions with labels and boolean flags |

### 3.2 What Does Not Exist (Gaps)

| Gap | Impact |
|---|---|
| No `mcp_ai_support_ticket` CPT | Support correspondence has no structured record — it's just freeform activities or email threads |
| No ticket pipeline | No way to track a support issue from open to resolved, measure time-in-stage, or trigger automations |
| No SLA engine | No first-response or resolution timers, no breach detection, no escalation |
| No priority matrix | Inbound support requests are all treated equally — no P1/P2/P3/P4 differentiation |
| No ticket tools | AI agents cannot create, update, list, resolve, escalate, or merge support tickets |
| No dashboard visibility | CRM Command Center has no support KPIs — open ticket count, SLA breaches, avg resolution time |

### 3.3 Target State

After Phase 1–4 implementation:

| Capability | Implementation |
|---|---|
| Ticket CPT with admin list + edit screen | `WP_MCP_AI_Support_Ticket_CPT` — columns, meta boxes, save handler |
| 7-stage pipeline with SLA timers | `WP_MCP_AI_CRM_Engine` defaults + `_ticket_sla_*` meta fields |
| P1–P4 priority matrix | Configurable thresholds in CRM Toolkit Settings |
| 10 AI tools | CRUD + classify + escalate + merge + SLA report |
| Activity timeline | Reuses `mcp_ai_crm_activity` with `related_type=ticket` |
| Dashboard KPIs + tab | CRM Command Center additions |
| Auto-close + auto-escalate | WP-Cron hooks triggered by stage transitions |
| AI Assistant sidebar | Via `wp_mcp_ai_cpt_supported_post_types` filter |

---

## 4. Architecture & Data Model

### 4.1 CPT: `mcp_ai_support_ticket`

```
┌─────────────────────────────────────────────────┐
│              mcp_ai_support_ticket               │
├─────────────────────────────────────────────────┤
│  WordPress Core                                  │
│  ─────────────                                  │
│  post_title    = Ticket subject (required)       │
│  post_content  = Ticket body / description       │
│  post_author   = Creator (agent or system)       │
│  post_date     = Created timestamp               │
│                                                 │
│  Ticket Meta (_ticket_*)                        │
│  ─────────────────────                          │
│  _ticket_status              = new              │
│  _ticket_priority            = p2_high          │
│  _ticket_source              = email            │
│  _ticket_category            = bug              │
│  _ticket_contact_id          = 15417 (lead ID)  │
│  _ticket_assignee_id         = 42 (user ID)     │
│  _ticket_tags                = ["billing","urgent"]│
│  _ticket_parent_id           = 0 (sub-tickets)  │
│                                                 │
│  SLA Meta (_ticket_sla_*)                       │
│  ─────────────────────                          │
│  _ticket_sla_first_response_by  = 2026-06-08 14:15│
│  _ticket_sla_resolution_by      = 2026-06-08 22:00│
│  _ticket_sla_first_response_at  = null (actual) │
│  _ticket_sla_resolved_at        = null (actual) │
│  _ticket_sla_status             = on_track      │
│  _ticket_sla_paused_at          = null           │
│  _ticket_sla_total_paused_secs  = 0              │
│                                                 │
│  Resolution Meta                                │
│  ───────────────                                │
│  _ticket_resolution_type       = null            │
│  _ticket_resolution_note       = ""              │
│  _ticket_closed_by             = 0               │
│  _ticket_closed_at             = null            │
│  _ticket_reopened_count        = 0               │
└─────────────────────────────────────────────────┘
```

### 4.2 Ticket Pipeline Stages

```
                         ┌──────────────────────┐
                         │         NEW          │
                         │  Unread / unassigned │
                         │    SLA: running      │
                         └─────────┬────────────┘
                                   │ triage (classify + assign)
                         ┌─────────▼────────────┐
                         │       TRIAGED        │
                         │  Categorised + owner │
                         │    SLA: running      │
                         └─────────┬────────────┘
                                   │ agent picks up
                         ┌─────────▼────────────┐
                         │     IN PROGRESS      │
                         │  Agent working on it │
                         │    SLA: running      │
                         └──┬──────────────┬────┘
                            │              │
              needs customer│              │blocked externally
                  input     │              │
              ┌─────────────▼──┐  ┌────────▼──────────────┐
              │ WAITING ON     │  │ WAITING ON 3RD PARTY  │
              │ CUSTOMER       │  │ Blocked externally     │
              │ SLA: paused    │  │ SLA: paused            │
              │ auto-close 7d  │  │ auto-escalate 3d       │
              └───────┬────────┘  └───────────┬────────────┘
                      │ reply received        │ unblocked
                      └───────────────────────┘
                                   │ agent resolves
                         ┌─────────▼────────────┐
                         │       RESOLVED       │
                         │  Fix confirmed by    │
                         │  agent. SLA: stopped │
                         │  auto-close 72h      │
                         └─────────┬────────────┘
                                   │ customer confirms
                                   │ or auto-close fires
                         ┌─────────▼────────────┐
                         │        CLOSED        │
                         │  Final, SLA: stopped │
                         │  re-open on reply    │
                         └──────────────────────┘

  RE-OPEN: Customer reply on a Resolved or Closed ticket
  moves it back to IN PROGRESS and increments _ticket_reopened_count.
```

### 4.3 SLA Engine Logic

**First Response Timer:**
- Starts at ticket creation (`post_date`)
- Stops when first agent reply is sent (any activity of type `email` or `note` from assignee, or status transition to `in_progress`)
- Target calculated from priority: P1=15min, P2=1h, P3=4h, P4=8h

**Resolution Timer:**
- Starts at ticket creation
- Stops when stage transitions to `resolved`
- Pauses when stage is `waiting_on_customer` or `waiting_on_third_party`
- Target calculated from priority: P1=4h, P2=8h, P3=24h, P4=72h

**SLA Status (derived):**

| Status | Condition |
|---|---|
| `on_track` | Neither timer breached |
| `at_risk` | > 75% of target elapsed for either timer |
| `breached` | Either timer exceeds target |

**WP-Cron Hooks:**
- `wp_mcp_ai_crm_ticket_sla_check` — runs every 5 minutes, recalculates `_ticket_sla_status` for all non-closed tickets
- `wp_mcp_ai_crm_ticket_auto_close` — daily check for resolved tickets older than 72h

### 4.4 Relationships

```
mcp_ai_lead ──────────────┐
  (requester)             │ _ticket_contact_id
                          ▼
                    mcp_ai_support_ticket
                          │
          ┌───────────────┼───────────────┐
          │               │               │
          ▼               ▼               ▼
  mcp_ai_crm_activity  mcp_ai_deal   mcp_ai_support_ticket
    (timeline)          (linked)       (parent/child)
```

Activities link via `related_type = 'ticket'` and `related_id = ticket_id`. This reuses the existing activity CPT with zero schema changes — only the `related_type` value is new.

---

## 5. Implementation Phases

### Phase 1: Support Ticket CPT + Meta Boxes (~500 lines new)

**File:** `addons/pro/includes/class-wp-mcp-ai-support-ticket-cpt.php`

| Component | Details |
|---|---|
| **Post type** | `mcp_ai_support_ticket`, menu position 58, icon `dashicons-sos`, supports `title`/`editor`/`author`, REST-enabled, submenu under Leads |
| **Admin columns** | Status (stage), Priority, Contact, Assignee, Source, Category, SLA Status, Date |
| **Quick filters** | Stage dropdown, Priority dropdown, Assignee dropdown |
| **Meta boxes** | Ticket Details (priority, stage, contact, assignee, source, category, tags), SLA & Timing (timers, status badge), Related Records (lead, deals, parent/child tickets), Timeline (activities feed) |
| **Save handler** | Nonce + capability check + per-field sanitisation (same pattern as Lead CPT) |
| **AI Assistant** | Registered via `wp_mcp_ai_cpt_supported_post_types` filter |

### Phase 2: SLA Engine (~300 lines new)

**Files:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-engine.php` (edit) + new methods

| Component | Details |
|---|---|
| **`get_support_pipeline_stages()`** | Returns stage definitions with `is_resolved`, `is_closed`, `pauses_sla` flags |
| **`get_sla_matrix()`** | Returns P1–P4 targets from settings (configurable in CRM Toolkit Settings page) |
| **`calculate_sla_targets( $priority, $created_at )`** | Returns first-response-by and resolution-by timestamps |
| **`recalc_ticket_sla( $ticket_id )`** | Derives `_ticket_sla_status` from current timers |
| **`pause_sla( $ticket_id )`** / **`resume_sla( $ticket_id )`** | Pause/resume SLA clock, accumulating paused seconds |
| **`get_sla_status_label( $status )`** | Returns human-readable + color-coded label |
| **Settings defaults** | `sla` section added to `get_toolkit_settings()` with P1–P4 response/resolution targets |
| **WP-Cron hook** | `wp_mcp_ai_crm_ticket_sla_check` — runs `recalc_ticket_sla()` for all open tickets |

### Phase 3: Tools (~1,200 lines new)

**Directory:** `addons/pro/includes/tools/crm/support/`

| # | Tool Slug | Purpose | Required Cap |
|---|---|---|---|
| 1 | `create_support_ticket` | Create ticket with priority, contact, category, source, body | `edit_posts` |
| 2 | `get_support_ticket` | Full ticket with SLA status, timeline, related records | `edit_posts` |
| 3 | `list_support_tickets` | Query with filters (status, priority, assignee, contact, date range, search) | `edit_posts` |
| 4 | `update_support_ticket` | Change status, assign, add internal note, change priority, update body | `edit_posts` |
| 5 | `resolve_support_ticket` | Mark resolved with resolution type + closing note | `edit_posts` |
| 6 | `reopen_support_ticket` | Re-open a resolved/closed ticket (customer replied) | `edit_posts` |
| 7 | `escalate_support_ticket` | Bump priority + notify assignee/manager | `edit_posts` |
| 8 | `merge_support_tickets` | Merge duplicate tickets (copy activities to parent, close duplicates) | `edit_posts` |
| 9 | `classify_support_ticket` | AI categorisation + priority suggestion from ticket body | `edit_posts` |
| 10 | `get_ticket_sla_report` | SLA compliance data: breached count, avg response, avg resolution, by assignee | `edit_posts` |

All tools follow the existing canonical return envelope pattern (success array or `WP_Error`, never `array( 'success' => false, ... )`) and obey the two-gate sanitisation rule.

### Phase 4: Dashboard Integration (~200 lines, edit existing)

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-crm-command-center-page.php`

| Addition | Location |
|---|---|
| **KPIs row** | Overview tab — Open tickets, Breached SLA, Avg first response, Avg resolution time |
| **Support Tickets tab** | New nav tab with sortable/filterable table (reuses leads tab pattern) |
| **Pipeline funnel bar** | Visual bar chart of ticket counts per stage (reuses deal pipeline renderer) |

### Phase 5: Automation & Workflow (~200 lines, edit existing)

| Feature | Implementation |
|---|---|
| **Auto-close resolved** | WP-Cron daily: close tickets in `resolved` > 72h |
| **Auto-escalate waiting** | WP-Cron daily: escalate tickets in `waiting_on_third_party` > 3 days |
| **Stage-transition hooks** | `wp_mcp_ai_crm_ticket_status_changed( $ticket_id, $old_stage, $new_stage )` |
| **SLA breach hook** | `wp_mcp_ai_crm_ticket_sla_breached( $ticket_id, $breach_type )` — fires when SLA timer expires |
| **Inbound auto-triage** | Extend `evaluate_inbound_message` to detect support requests, auto-create tickets, and respond with ticket number + SLA expectation |
| **Workflow rule triggers** | Add "Ticket Created", "Ticket Resolved", "SLA Breached" triggers to `WP_MCP_AI_CRM_Workflow_Rule_CPT` |

### Phase 6 (Future): External Sync

| Integration | Description |
|---|---|
| **Zendesk bi-directional** | Extend the existing Graphify Zendesk driver to push ticket updates back (currently read-only) |
| **Email-to-ticket** | Inbound emails from known contacts auto-create or update tickets |
| **Customer portal** | Front-end shortcode for ticket submission and status tracking |
| **CSAT surveys** | Post-resolution satisfaction survey via email |
| **Knowledge base linking** | AI-suggested KB articles in ticket sidebar |

---

## 6. File Manifest

### New Files

| File | Phase | Lines (est.) |
|---|---|---|
| `addons/pro/includes/class-wp-mcp-ai-support-ticket-cpt.php` | 1 | ~500 |
| `addons/pro/includes/tools/crm/support/class-wp-mcp-ai-tool-create-support-ticket.php` | 3 | ~120 |
| `addons/pro/includes/tools/crm/support/class-wp-mcp-ai-tool-get-support-ticket.php` | 3 | ~120 |
| `addons/pro/includes/tools/crm/support/class-wp-mcp-ai-tool-list-support-tickets.php` | 3 | ~150 |
| `addons/pro/includes/tools/crm/support/class-wp-mcp-ai-tool-update-support-ticket.php` | 3 | ~130 |
| `addons/pro/includes/tools/crm/support/class-wp-mcp-ai-tool-resolve-support-ticket.php` | 3 | ~100 |
| `addons/pro/includes/tools/crm/support/class-wp-mcp-ai-tool-reopen-support-ticket.php` | 3 | ~90 |
| `addons/pro/includes/tools/crm/support/class-wp-mcp-ai-tool-escalate-support-ticket.php` | 3 | ~110 |
| `addons/pro/includes/tools/crm/support/class-wp-mcp-ai-tool-merge-support-tickets.php` | 3 | ~140 |
| `addons/pro/includes/tools/crm/support/class-wp-mcp-ai-tool-classify-support-ticket.php` | 3 | ~120 |
| `addons/pro/includes/tools/crm/support/class-wp-mcp-ai-tool-get-ticket-sla-report.php` | 3 | ~120 |
| `addons/pro/includes/tools/crm/support/init.php` | 3 | ~50 |
| `addons/pro/includes/tools/crm/support/README.md` | 3 | ~80 |

### Modified Files

| File | Phase | Lines (est.) |
|---|---|---|
| `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-engine.php` | 2 | ~200 added |
| `addons/pro/includes/admin/class-wp-mcp-ai-crm-command-center-page.php` | 4 | ~200 added |
| `addons/pro/includes/tools/crm/init.php` | 3 | ~5 added |
| `addons/pro/includes/admin/class-wp-mcp-ai-crm-settings-page.php` | 2 | ~80 added (SLA matrix settings) |

### Total Estimates

| Phase | New Files | Modified Files | ~Lines |
|---|---|---|---|
| Phase 1 — CPT + Meta Boxes | 1 | 0 | 500 |
| Phase 2 — SLA Engine | 0 | 2 | 380 |
| Phase 3 — Tools | 13 | 1 | 1,370 |
| Phase 4 — Dashboard | 0 | 1 | 200 |
| Phase 5 — Automation | 0 | 1 | 200 |
| Phase 6 — Future | — | — | — |
| **Total** | **14** | **5** | **~2,650** |

---

## 7. Integration Points

### 7.1 Existing Infrastructure Reused

| Asset | How It's Reused |
|---|---|
| `mcp_ai_crm_activity` CPT | Timeline for tickets — add `related_type = 'ticket'` value, zero schema changes |
| `mcp_ai_lead` / `mcp_crm_contacts` | Requester/contact linked via `_ticket_contact_id` |
| `WP_MCP_AI_CRM_Engine` | Settings defaults, stage definitions, scoring (for auto-prioritisation), routing (for auto-assignment) |
| `WP_MCP_AI_CRM_Engine::get_pipeline_stages()` | Extended with `ticket` pipeline alongside existing `deal` pipeline |
| Deal CPT pattern | Pipeline stage model, admin columns, meta boxes — directly mirrored |
| Lead CPT edit-screen pattern | Meta box registration, save handler, nonce verification — directly mirrored |
| `wp_mcp_ai_cpt_supported_post_types` filter | AI Assistant sidebar metabox on ticket edit screen |
| CRM Command Center page | New Support Tickets tab + KPIs row in overview |
| Zendesk Graphify driver | Existing read-only sync; Phase 6 adds write-back |
| `evaluate_inbound_message` tool | Auto-triage: detect support intent → create ticket → auto-reply with ticket number |
| `crm_email_search_correspondence` tool | Already has `sla_breach_only` and routing suggestions — becomes the SLA report data source |
| CRM Workflow Rules | New trigger events: Ticket Created, Ticket Resolved, SLA Breached |

### 7.2 Hook Surface (Public API)

```php
// Stage transitions.
do_action( 'wp_mcp_ai_crm_ticket_status_changed', $ticket_id, $old_stage, $new_stage );

// SLA events.
do_action( 'wp_mcp_ai_crm_ticket_sla_breached', $ticket_id, $breach_type ); // 'first_response' | 'resolution'
do_action( 'wp_mcp_ai_crm_ticket_sla_at_risk', $ticket_id, $timer_type );

// Lifecycle.
do_action( 'wp_mcp_ai_crm_ticket_created', $ticket_id, $ticket_data );
do_action( 'wp_mcp_ai_crm_ticket_assigned', $ticket_id, $old_assignee_id, $new_assignee_id );
do_action( 'wp_mcp_ai_crm_ticket_resolved', $ticket_id, $resolution_type );
do_action( 'wp_mcp_ai_crm_ticket_reopened', $ticket_id );
do_action( 'wp_mcp_ai_crm_ticket_closed', $ticket_id, $close_reason ); // 'auto' | 'manual'

// Filters.
apply_filters( 'wp_mcp_ai_crm_ticket_pipeline_stages', $stages );
apply_filters( 'wp_mcp_ai_crm_ticket_sla_matrix', $sla_matrix );
apply_filters( 'wp_mcp_ai_crm_ticket_auto_close_days', 3 );
apply_filters( 'wp_mcp_ai_crm_ticket_auto_escalate_days', 3 );
```

### 7.3 REST API Exposure

All 10 tools are automatically exposed via the existing `/wp-json/mcp-ai/v1/tools` and `/wp-json/mcp-ai/v1/tools/{slug}/run` endpoints. The ticket CPT is registered with `show_in_rest => true`, providing native WP REST endpoints at `/wp-json/wp/v2/mcp_ai_support_ticket/`.

---

## 8. Settings UI Additions

### CRM Toolkit Settings → New Section: Support & SLA

| Setting | Type | Default |
|---|---|---|
| `sla[p1_first_response_minutes]` | number | 15 |
| `sla[p1_resolution_minutes]` | number | 240 |
| `sla[p2_first_response_minutes]` | number | 60 |
| `sla[p2_resolution_minutes]` | number | 480 |
| `sla[p3_first_response_minutes]` | number | 240 |
| `sla[p3_resolution_minutes]` | number | 1440 |
| `sla[p4_first_response_minutes]` | number | 480 |
| `sla[p4_resolution_minutes]` | number | 4320 |
| `sla[business_hours_start]` | time | 09:00 |
| `sla[business_hours_end]` | time | 17:00 |
| `sla[business_days]` | checkbox group | Mon–Fri |
| `sla[auto_close_resolved_days]` | number | 3 |
| `sla[auto_escalate_waiting_days]` | number | 3 |
| `support[default_assignee_id]` | user select | 0 |
| `support[ticket_categories]` | textarea (one per line) | Bug, Question, Feature Request, Account, Billing, Other |
| `support[resolution_types]` | textarea (one per line) | Solved, Not Reproducible, Won't Fix, Duplicate, Third Party |

---

## 9. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| **Scope creep** — trying to build a full Zendesk clone | Strict adherence to 7-stage pipeline; no knowledge base, no CSAT surveys in Phase 1–5, no customer portal until Phase 6 |
| **SLA timer accuracy** | WP-Cron runs every 5 min; SLA status is recalculated on every ticket view as well (not solely cron-dependent) |
| **Activity table growth** | Reuses `mcp_ai_crm_activity` — already exists and scales. Adding `related_type=ticket` adds no new tables |
| **Performance with many open tickets** | SLA recalculation uses a targeted meta query (`_ticket_sla_status != 'breached' AND post_status = 'publish'`) with `no_found_rows` and `fields => 'ids'` |
| **Backward compatibility** | All new features gated behind `enable_crm_toolkit` setting; no existing behaviour changed |

---

## 10. Success Metrics

After Phase 1–5 implementation, the CRM Command Center should surface:

| Metric | Source |
|---|---|
| Open tickets (by stage) | `wp_count_posts( 'mcp_ai_support_ticket' )` filtered by stage |
| SLA compliance % | `_ticket_sla_status` meta aggregation |
| Avg first response time | `_ticket_sla_first_response_at - post_date` across resolved tickets |
| Avg resolution time | `_ticket_sla_resolved_at - post_date` across resolved tickets |
| Tickets per assignee | Grouped by `_ticket_assignee_id` |
| Reopen rate % | `COUNT(_ticket_reopened_count > 0) / COUNT(*)` |

---

## 11. Related Documents

- `addons/pro/docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md` — Phase A–E CRM enhancement roadmap (pre-sale)
- `addons/pro/docs/CRM_EMAIL_MARKETING_GUIDE.md` — Email marketing toolkit guide
- `addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md` — Multichannel inbox (Telegram, WhatsApp, Google Chat)
- `addons/pro/docs/HEALTH_WELLNESS_IMPLEMENTATION.md` — Reference implementation pattern
- [HubSpot Service Hub — Ticket Pipelines](https://knowledge.hubspot.com/object-settings/set-up-and-customize-pipelines)
- [ITIL Incident Management — IT Process Wiki](https://wiki.en.it-processmaps.com/index.php/Incident_Management)
