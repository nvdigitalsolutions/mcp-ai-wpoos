# CRM Toolkit Enhancement Plan — Lead Management, Multichannel Triage & Workflow Command Center

> **Status:** Proposal — ready for review.
> **Date:** 2026-05-28
> **Scope:** `addons/pro/includes/tools/crm/` + supporting infrastructure
> **Inspired by:** [`healthcare/README.md`](../includes/tools/healthcare/README.md) (the gold-standard pattern in this codebase)
> **Companion docs:** [`PRO_TOOLKIT_ENHANCEMENT_REVIEW.md`](PRO_TOOLKIT_ENHANCEMENT_REVIEW.md), [`CRM_EMAIL_MARKETING_GUIDE.md`](CRM_EMAIL_MARKETING_GUIDE.md), [`CHAT_CHANNELS_TOOLKIT.md`](CHAT_CHANNELS_TOOLKIT.md)

---

## 1. Executive Summary

The CRM toolkit currently ships **11 tools** organised around basic contact / company CRUD, three read-only lead/correspondence/accounting email search filters, a MemPalace capture tool, and three Upwork-specific tools. By contrast, the Healthcare toolkit ships **~50 tools across five sub-modules** with:

- a shared `WP_MCP_AI_Healthcare_Engine` (units, identity, settings, reference ranges),
- a standards registry (`Codes`),
- FHIR / HL7 / CCDA / DICOMweb interoperability,
- a unified PHI audit ledger,
- a role-to-capability map,
- a phased A→E roadmap with extension hooks at every boundary.

The CRM toolkit needs the same depth before it can credibly own the workflow described in the parent request:

> *"reviewing emails, SMS, WhatsApp etc., qualifying them and adding to a workflow command center to be followed up by automated replies, messages etc."*

This document proposes a **5-phase enhancement plan (A → E)** that adds ~60 new tools and elevates CRM to the architectural parity of Healthcare, **while reusing existing infrastructure**:

- `WP_MCP_AI_Channel_Messages_CPT/CCT` (multichannel inbox: Telegram, WhatsApp, Google Chat, webchat) — already in `chat-channels-toolkit`,
- `WP_MCP_AI_Pro_Schedule_Manager` (recurring background jobs),
- `WP_MCP_AI_Pro_Workflow_Builder_Page` (React visual builder),
- `WP_MCP_AI_Pro_Capture_Tool_Base` + MemPalace,
- `WP_MCP_AI_Validator_Service` (email/phone RFC 5322 + libphonenumber),
- `mailparser`, `nodemailer`, `mjml` (already declared in `package.json`).

The goal is **not** to build a parallel HubSpot — it is to wire the assets we already have into a defensible, compliance-aware CRM that an LLM agent can drive end-to-end.

---

## 2. Industry Standards Reviewed

Web research (May 2026) confirms the following are table stakes for a modern CRM:

| Domain | Standard / Reference |
|---|---|
| **Qualification frameworks** | BANT (Budget · Authority · Need · Timeline), MEDDIC (Metrics · Economic Buyer · Decision Criteria · Decision Process · Identify Pain · Champion), CHAMP, ANUM, GPCTBA/C — Salesforce / HubSpot / monday.com all support BANT + MEDDIC as first-class object schemas. |
| **Lifecycle stages** | HubSpot canonical set: Subscriber → Lead → MQL → SAL → SQL → Opportunity → Customer → Evangelist → Other. Salesforce equivalent: Lead → MQL → SQL → Opportunity → Customer. |
| **Lead scoring** | 0–100 numeric score with conventional buckets: 0–39 cold, 40–69 warm, 70–100 hot. Already implemented as `score_label()` in `crm_email_search_leads`. |
| **Routing** | Round-robin · weighted · territory · skill-based · account-based. HubSpot "Rotate records" action is the de-facto reference. |
| **Sequences / cadences** | Multi-step, multi-channel (email → wait → SMS → wait → LinkedIn → wait → call task). Auto-pause on reply; pause across all enrolments per contact. HubSpot Sequences / Salesforce Cadence / Outreach.io. |
| **Multichannel ingestion** | Email (IMAP/Gmail/Outlook), SMS (Twilio/Vonage), WhatsApp Business Cloud API (24-hour session + pre-approved templates outside it), LinkedIn DM, web forms, voice transcripts. |
| **AI triage** | Intent classification (new_inquiry, support, complaint, spam, follow-up), sentiment, BANT/MEDDIC field extraction, draft-reply generation. Lyzr / Respond.io / monday AI references. |
| **Compliance** | GDPR Art. 6 + 9 (legitimate interest, explicit consent for special-category data); CAN-SPAM (unsubscribe link, physical address); TCPA (express written consent for SMS/calls, real-time revocation across channels — Apr 2025 FCC rule); CASL (Canada); WhatsApp Business Policy (24-hour session window + template message approval); CCPA right-to-delete. |
| **Suppression** | DNC (federal + state + internal), opt-out list, hard-bounce list, complaint list. |
| **Pipeline visualisation** | Kanban by deal stage; conversion funnel; weighted-pipeline forecast (`amount × stage_probability`). |

The plan below codifies every one of these as either a tool, a registry entry, or a filter hook.

---

## 3. Current State vs Target State

### 3.1 What CRM already has (11 tools)

| Tool slug | Surface | Notes |
|---|---|---|
| `manage_crm_contact` | CRUD on `mcp_crm_contacts` via `WP_MCP_AI_Toolkit_Data_Store` | Generic, uses CCT/CPT abstraction |
| `create_company` | Create `mcp_ai_company` | |
| `get_companies` | List companies | |
| `research_company` | AI/web-search research-to-add | Solid pattern, mirrors Healthcare research-add |
| `crm_capture_interaction` | MemPalace `account/{id}` wing capture | Good groundwork for memory recall |
| `crm_email_search_accounting` | Read-only filter | Has WP Cron scheduling, cache layer |
| `crm_email_search_correspondence` | Read-only filter | |
| `crm_email_search_leads` | Read-only filter — has BANT-adjacent `mql_stage`, `contact_owner`, score range | Closest thing to a lead pipeline view today |
| `draft_upwork_proposal` | Outbound draft (Upwork only) | Channel-locked |
| `score_upwork_job` | Scoring (Upwork only) | Channel-locked |
| `search_upwork_jobs` | Job board search (Upwork only) | Channel-locked |

### 3.2 What CRM is missing (compared to Healthcare's exemplary depth)

| Gap | Healthcare equivalent that exists today |
|---|---|
| Shared engine class (units, identity, settings, scoring) | `WP_MCP_AI_Healthcare_Engine` |
| Standards registry (BANT/MEDDIC, lifecycle stages, sources) | `WP_MCP_AI_Healthcare_Codes` |
| Audit ledger for PII / consent | `WP_MCP_AI_Healthcare_Audit` |
| Role-to-capability map (sales_manager, ae, sdr, sales_ops, marketing_ops) | `WP_MCP_AI_Healthcare_Capabilities` |
| Per-toolkit settings option with documented filterable defaults | `wp_mcp_ai_healthcare_settings` |
| Phased roadmap with stable extension hooks | Healthcare Phases A → E |
| Assistant blueprints (`examples/*.json`) | `examples/general-clinic.json` etc. |
| Interop layer (FHIR ↔ EHR) | `import_fhir_bundle`, `connect_to_ehr` |
| Sub-toolkit toggles | `enable_health_wellness_management`, `enable_healthcare_imaging`, `enable_medical_vitals` |

**Direct lead-management gaps:**

| Capability | Status |
|---|---|
| Lead CRUD (separate lifecycle object) | ❌ Conflated with `manage_crm_contact` |
| Deal / opportunity CRUD + pipeline | ❌ Absent |
| Outreach sequences (multi-step, multi-channel) | ❌ Absent |
| Lead routing / round-robin / territory | ❌ Absent |
| Inbound message AI triage (intent + BANT extraction + draft reply) | ❌ Absent |
| Multichannel outbound (SMS / WhatsApp / email) action tools | ❌ Absent (chat-channels has plumbing, CRM doesn't expose it) |
| Activity / task CRUD (calls, meetings, follow-ups) | ❌ Absent |
| Consent ledger + DNC / opt-out enforcement | ❌ Absent |
| Pipeline analytics / forecasting / owner workload | ❌ Absent |
| Workflow Command Center inbox (unified "needs my action" queue) | ❌ Absent |

---

## 4. Proposed Architecture

### 4.1 New shared infrastructure

```
addons/pro/includes/tools/crm/
├── README.md
├── class-wp-mcp-ai-crm-engine.php              [NEW] scoring, lifecycle, currency, territory
├── class-wp-mcp-ai-crm-codes.php               [NEW] BANT/MEDDIC schemas, sources, channels, stages
├── class-wp-mcp-ai-crm-audit.php               [NEW] append-only PII/consent ledger
├── class-wp-mcp-ai-crm-capabilities.php        [NEW] role → WP cap map
├── class-wp-mcp-ai-crm-consent.php             [NEW] channel-specific consent ledger
├── class-wp-mcp-ai-crm-pipeline-stages.php     [NEW] stage definitions, probabilities, filterable
├── class-wp-mcp-ai-crm-classifier.php          [NEW] intent/sentiment shim around LLM provider
├── leads/                                       [NEW] lead CRUD + qualification
├── deals/                                       [NEW] opportunity / pipeline CRUD
├── activities/                                  [NEW] call/meeting/task CRUD
├── sequences/                                   [NEW] cadence definition + enrolment
├── inbound/                                     [NEW] email/sms/whatsapp triage
├── outbound/                                    [NEW] send-on-behalf tools
├── routing/                                     [NEW] assignment + rotation
├── analytics/                                   [NEW] pipeline, funnel, forecast
├── compliance/                                  [NEW] consent + DNC + opt-out
├── command-center/                              [NEW] unified inbox + workflow rules
├── upwork/                                      [MOVED] existing Upwork tools relocated here
└── examples/                                    [NEW] assistant blueprints (B2B SaaS SDR, agency, real estate)
```

### 4.2 Settings option

A new option `wp_mcp_ai_crm_toolkit_settings` (modelled on `wp_mcp_ai_healthcare_settings`) holds CRM-wide configuration:

```php
array(
    'default_currency'             => 'USD',
    'default_lifecycle_stage'      => 'lead',
    'qualification_framework'      => 'bant',         // 'bant' | 'meddic' | 'champ' | 'custom'
    'hot_score_threshold'          => 70,
    'warm_score_threshold'         => 40,
    'audit_retention_days'         => 365,
    'consent'                      => array(
        'require_double_opt_in'    => true,
        'default_legal_basis'      => 'legitimate_interest', // ePrivacy / GDPR Art. 6
        'unsubscribe_footer_text'  => '',
        'physical_address'         => '',                    // CAN-SPAM requirement
    ),
    'routing'                      => array(
        'strategy'    => 'round_robin', // 'round_robin' | 'weighted' | 'territory' | 'skill'
        'pool'        => array(),       // WP user IDs
        'territories' => array(),       // map of country/region → user_id
    ),
    'sequences'                    => array(
        'send_hours_local'         => array( 9, 18 ), // business hours
        'send_days'                => array( 1, 2, 3, 4, 5 ), // Mon–Fri
        'pause_on_reply'           => true,
        'pause_on_meeting_booked'  => true,
    ),
    'pipeline'                     => array(
        'stages' => array(
            // stage_id => array( 'label', 'probability', 'is_won', 'is_lost' )
            'qualification' => array( 'label' => 'Qualification', 'probability' => 0.10 ),
            'discovery'     => array( 'label' => 'Discovery',     'probability' => 0.25 ),
            'proposal'      => array( 'label' => 'Proposal',      'probability' => 0.50 ),
            'negotiation'   => array( 'label' => 'Negotiation',   'probability' => 0.75 ),
            'closed_won'    => array( 'label' => 'Closed-Won',    'probability' => 1.00, 'is_won' => true ),
            'closed_lost'   => array( 'label' => 'Closed-Lost',   'probability' => 0.00, 'is_lost' => true ),
        ),
    ),
    'integrations'                 => array(
        'twilio_account_sid_secret' => '',   // password-vault handle (NEVER raw)
        'whatsapp_phone_number_id'  => '',
        'gmail_oauth_handle'        => '',
        'outlook_oauth_handle'      => '',
    ),
);
```

Programmatic access via `WP_MCP_AI_CRM_Engine::get_toolkit_settings()`; filterable via `wp_mcp_ai_crm_toolkit_settings`.

### 4.3 Sub-toolkit toggles (in `wp_mcp_ai_settings`)

To keep CRM modular and lean by default:

| Toggle | Sub-module | Default |
|---|---|---|
| `enable_crm_toolkit` | core (contacts, companies, leads, deals) | off |
| `enable_crm_sequences` | outreach sequences | inherits `enable_crm_toolkit` |
| `enable_crm_command_center` | unified inbox + workflow rules | inherits `enable_crm_toolkit` |
| `enable_crm_multichannel` | SMS / WhatsApp outbound | off (carrier API keys required) |

### 4.4 New custom post types / CCTs

| Slug | Purpose |
|---|---|
| `mcp_ai_lead` *(or: enrich `mcp_crm_contacts`)* | Lifecycle-stage entity with BANT/MEDDIC fields, score, owner, source |
| `mcp_ai_customer` | Post-conversion customer record with billing, LTV, and source-lead linkage |
| `mcp_ai_deal` | Opportunity / pipeline record |
| `mcp_ai_crm_activity` | Calls, meetings, tasks, follow-ups |
| `mcp_ai_sequence` | Cadence definition (steps, channels, waits) |
| `mcp_ai_sequence_enrollment` | Per-lead state machine |
| `mcp_ai_crm_workflow_rule` | If-this-then-that rules (used by Command Center) |
| `mcp_ai_crm_consent_log` *(CCT)* | Channel-specific consent + revocation events |

> **Migration note.** We will not break the existing `mcp_crm_contacts` schema. `mcp_ai_lead` is conceptually an enriched view of a contact in a pre-customer lifecycle stage; `mcp_ai_customer` is the post-conversion entity created by `convert_lead_to_customer`. A `migrate_contacts_to_leads` WP-CLI command will be provided.

### 4.5 Reuse map

The plan deliberately **wires together existing infrastructure** rather than re-implementing it:

| Existing surface | How CRM Phase B–E uses it |
|---|---|
| `WP_MCP_AI_Channel_Messages_CCT/CPT` | Source of inbound messages for triage |
| `WP_MCP_AI_Chat_Channels_REST_Controller` | Webhook entry point — we add a `wp_mcp_ai_chat_channel_message_received` listener that hands the message to `evaluate_inbound_message` |
| `WP_MCP_AI_Pro_Schedule_Manager` | Drives sequence step execution, pipeline-decay scoring, hot-lead alerts |
| `WP_MCP_AI_Pro_Workflow_Builder_Page` | Visual editor for `mcp_ai_crm_workflow_rule` records |
| `WP_MCP_AI_Pro_Capture_Tool_Base` | Base class for `crm_capture_*` tools |
| `WP_MCP_AI_Validator_Service` | Email / phone validation in CRUD tools |
| MemPalace (already wired by `crm_capture_interaction`) | Long-term per-account memory for assistants |
| Password Vault | Storage for Twilio / WhatsApp / Gmail OAuth secrets |
| `nodemailer` + `mjml` (NPM, pro/node-services) | Outbound email rendering & SMTP send |
| `mailparser` (NPM, pro/node-services) | Inbound IMAP parsing |

---

## 5. Phased Roadmap (A → E)

The roadmap follows the same A→E shape as Healthcare so the contributor experience is consistent across toolkits.

### Phase A — Foundations & relocation *(no new tool slugs)*

**Goal:** Stand up the shared infrastructure without changing any existing behavior.

- Add `WP_MCP_AI_CRM_Engine`, `_Codes`, `_Audit`, `_Capabilities`, `_Consent`, `_Pipeline_Stages`, `_Classifier`.
- Add `wp_mcp_ai_crm_toolkit_settings` option + `wp_mcp_ai_crm_toolkit_settings` filter.
- Add `WP_MCP_AI_CRM_Engine::get_toolkit_settings()` (mirrors Healthcare engine API).
- Introduce `is_available()` / `get_unavailable_reason()` on every existing CRM tool (already used by `crm_email_search_leads`; backfill the other 10).
- Relocate the 3 Upwork tools into `crm/upwork/` (no slug change).
- Backwards-compatible forwarder in `crm-toolkit-init.php`.
- Add CRM-specific PHPCS sniff fixtures + tests directory `tests/pro/tools/crm/`.

**Exit criterion:** Existing CRM tools continue to work identically; `WP_MCP_AI_CRM_Engine::get_toolkit_settings()` returns sane defaults; new directory structure is in place.

---

### Phase B — Lead, Deal, Activity & Pipeline CRUD *(≈ 22 new tools)*

**Goal:** Bring CRUD parity with Project Management (the existing gold standard for CRUD depth).

Pattern is the established `[operation]_[entity]` convention (see `PRO_TOOLKIT_ENHANCEMENT_REVIEW.md` §"Complete CRUD Pattern").

**Leads (6)**
- `create_lead`, `list_leads`, `get_lead`, `update_lead`, `delete_lead`, `convert_lead_to_customer`

**Deals / Opportunities (6)**
- `create_deal`, `list_deals`, `get_deal`, `update_deal`, `delete_deal`, `move_deal_stage`

**Activities (5)**
- `create_crm_activity` *(types: call, email, meeting, task, note)*
- `list_crm_activities`, `get_crm_activity`, `complete_crm_activity`, `snooze_crm_activity`

**Pipeline analytics (3)**
- `get_pipeline_view` *(Kanban-style snapshot grouped by stage)*
- `get_conversion_funnel` *(stage-to-stage rates, weighted)*
- `forecast_pipeline_revenue` *(`amount × stage_probability` aggregated by close_date bucket)*

**Routing (2)**
- `assign_lead_to_owner` *(modes: round_robin, weighted, territory, manual)*
- `rotate_leads` *(bulk reassignment)*

**Hooks added in Phase B**
- `wp_mcp_ai_crm_before_lead_create`, `wp_mcp_ai_crm_after_lead_create`
- `wp_mcp_ai_crm_before_deal_stage_change`, `wp_mcp_ai_crm_after_deal_stage_change`
- `wp_mcp_ai_crm_lead_score_calculated`
- `wp_mcp_ai_crm_pipeline_stages` *(filter on the stage map)*
- `wp_mcp_ai_crm_routing_strategy` *(filter to plug in custom routing)*

---

### Phase C — Inbound Triage & Outbound Multichannel *(≈ 18 new tools)*

**Goal:** Read messages from any channel, qualify them, and respond on-channel.

**Inbound triage (7)**
- `evaluate_inbound_message` *(orchestrator: classify → score → extract → store → trigger rules)*
- `classify_message_intent` *(`new_inquiry`, `support`, `complaint`, `spam`, `follow_up`, `qualification_response`, `unsubscribe`)*
- `extract_lead_from_message` *(channel-agnostic: parses subject/body/transcript to draft a `mcp_ai_lead`)*
- `detect_buying_signals` *(keyword + LLM heuristic, e.g. "pricing", "demo", "next steps")*
- `score_lead` *(0–100, factor-decomposable: fit + intent + engagement + recency)*
- `qualify_lead_bant` *(produces a `bant_status` record + missing-info checklist)*
- `qualify_lead_meddic` *(MEDDIC variant for enterprise sales)*

**Outbound multichannel (6)**
- `send_lead_email` *(MJML template, nodemailer; tracks open + click via pixel/CTR URLs)*
- `send_lead_sms` *(Twilio adapter; respects TCPA quiet hours per recipient timezone)*
- `send_lead_whatsapp` *(WhatsApp Cloud API; auto-detects 24-hour session vs template message)*
- `send_lead_dm` *(LinkedIn — via existing chat-channels DM plumbing if available; else stub)*
- `log_call_outcome` *(disposition, recording URL, transcript)*
- `draft_lead_reply` *(LLM draft preview; does NOT send; respects channel and brand voice)*

**Auto-reply (2)**
- `auto_reply_inbound` *(rule-driven: when message matches intent X → send template Y on same channel)*
- `schedule_follow_up` *(creates a `mcp_ai_crm_activity` task at +N business days)*

**Listeners (3, no new slugs)**
- `wp_mcp_ai_chat_channel_message_received` → routes to `evaluate_inbound_message`
- Email IMAP polling job (uses Schedule Manager) → routes parsed email to `evaluate_inbound_message`
- Web form submission → `extract_lead_from_message`

**Hooks added in Phase C**
- `wp_mcp_ai_crm_classify_intent` *(filter to swap the classifier)*
- `wp_mcp_ai_crm_score_factors` *(filter on scoring decomposition)*
- `wp_mcp_ai_crm_before_outbound_send` *(suppression + consent gate)*
- `wp_mcp_ai_crm_after_outbound_send`
- `wp_mcp_ai_crm_buying_signal_keywords`

---

### Phase D — Sequences, Workflow Command Center & Routing Engine *(≈ 14 new tools)*

**Goal:** Multi-step cadences and a single "what needs my attention" inbox.

**Sequences (7)**
- `create_outreach_sequence` *(definition: ordered steps {channel, template_id, wait, branch_on_reply})*
- `update_outreach_sequence`, `delete_outreach_sequence`, `list_outreach_sequences`
- `enroll_lead_in_sequence` *(idempotent; respects suppression list)*
- `pause_sequence`, `resume_sequence`, `exit_sequence`
- `get_sequence_performance` *(per-step open/reply/meeting rates)*

**Workflow Command Center (5)**
- `create_workflow_rule` *(trigger → conditions → actions; persisted as `mcp_ai_crm_workflow_rule`)*
- `update_workflow_rule`, `delete_workflow_rule`, `list_workflow_rules`
- `simulate_workflow_rule` *(dry-run against historical messages — critical for safe deployment)*
- `get_workflow_inbox` *(unified queue: hot leads + overdue follow-ups + needs-approval drafts)*

**Routing engine extensions (2)**
- `auto_route_inbound_message` *(applies routing settings + workload-aware weighting)*
- `get_owner_workload` *(active leads, overdue tasks, response SLA per rep)*

**Hooks added in Phase D**
- `wp_mcp_ai_crm_workflow_trigger` *(action: fired when a rule matches; carries `trigger`, `lead_id`, `context`)*
- `wp_mcp_ai_crm_sequence_step_before_send`, `…_after_send`
- `wp_mcp_ai_crm_command_center_widgets` *(filter to add custom widgets to the inbox)*

**UI:** the existing `WP_MCP_AI_Pro_Workflow_Builder_Page` gains a "CRM" preset palette (trigger types, conditions, actions all sourced from the registry). The Chat Channels admin's existing automation page (`wp_mcp_ai_chat_channels_automation_rules`) is upgraded to forward to the new workflow-rule engine via a transparent shim.

---

### Phase E — Compliance, Interop & Assistant Blueprints *(≈ 8 new tools)*

**Goal:** Make the toolkit deployable in regulated markets and importable into agents out of the box.

**Compliance (5)**
- `record_consent` *(channel + legal_basis + source + evidence_url; writes to `mcp_ai_crm_consent_log`)*
- `revoke_consent` *(propagates across all channels in real time — required by Apr 2025 FCC rule)*
- `process_opt_out` *(channel-specific or global; idempotent; logged)*
- `check_dnc_status` *(internal list; filter-extensible to federal/state DNC)*
- `get_consent_audit` *(for regulator inspection / DSAR)*

**Interop (2)**
- `import_crm_csv` *(field-mapping, dedupe-by-email, dry-run preview)*
- `connect_to_external_crm` *(generic OAuth: HubSpot / Salesforce / Pipedrive — uses Password Vault)*

**Compliance hooks**
- `wp_mcp_ai_crm_dnc_lists` *(register federal / state / partner DNC sources)*
- `wp_mcp_ai_crm_suppression_check` *(filter return `WP_Error` to block a send)*
- `wp_mcp_ai_crm_consent_evidence` *(filter the stored evidence record)*

**Assistant blueprints (1 tool + 4 JSON files)**
- `import_crm_blueprint` *(installs a curated assistant from `examples/`)*
- `examples/b2b-saas-sdr.json` *(BANT-driven SDR for SaaS pipeline)*
- `examples/agency-account-manager.json` *(retainer / project work; Upwork-aware)*
- `examples/real-estate-buyer-agent.json` *(showings, MLS hand-off, TCPA-aware)*
- `examples/wholesale-distributor.json` *(quote → invoice handoff to E-commerce toolkit)*

🎯 **Toolkit roadmap complete after Phase E.**

---

## 6. Tool Count Summary

| Phase | New tools | Cumulative |
|---|---|---|
| Existing (today) | 11 | 11 |
| Phase A (foundations only — no new tools) | 0 | 11 |
| Phase B (CRUD: leads, deals, activities, pipeline, routing) | 22 | 33 |
| Phase C (inbound triage + outbound multichannel + auto-reply) | 18 | 51 |
| Phase D (sequences + command center + routing engine) | 14 | 65 |
| Phase E (compliance + interop + blueprints) | 8 | 73 |

**Target: ≈ 73 tools** — comparable to Healthcare's depth, and structurally analogous so contributors familiar with one toolkit can navigate the other.

---

## 7. Capability Flags Convention

All new tools follow the canonical envelope and two-gate sanitisation rules (per [`CLAUDE.md`](../../../CLAUDE.md)) and tag with:

| Flag | Used by |
|---|---|
| `'pro'` | every tool |
| `'database-read'` | list / get / search / analytics |
| `'database-write'` | create / update |
| `'destructive'` | delete / revoke / opt-out |
| `'outbound-network'` | every `send_lead_*` and external-CRM sync (gates remote calls for offline test envs) |
| `'requires-consent'` *(new)* | every outbound multichannel tool — engine performs a consent check before execution |
| `'pii-access'` *(new)* | every read tool that returns lead/contact PII — engine logs to audit ledger |

The two new flags (`requires-consent`, `pii-access`) are introduced in Phase A and become first-class concepts. Healthcare uses similar gating via the BAA toggle; this is the CRM analogue.

---

## 8. Security & Compliance Checklist

Every tool in the plan satisfies:

- **Sanitisation at entry:** `sanitize_text_field`, `absint`, `sanitize_email`, `wp_kses` for HTML bodies, `sanitize_key` for stage/intent enums.
- **Escaping at exit:** every value returned to admin UI or rendered into a template is escaped at the boundary.
- **Capability checks:** `current_user_can( …, $lead_id )` per-object where ownership matters.
- **Nonce on every form-submitting admin page.**
- **Consent gate** *(new)*: outbound tools call `WP_MCP_AI_CRM_Consent::is_permitted( $contact_id, $channel )` and return `WP_Error( 'crm_consent_required' )` if not.
- **DNC gate** *(new)*: outbound tools call `WP_MCP_AI_CRM_Engine::check_dnc( $phone_or_email )`.
- **Audit ledger** *(new)*: every PII read and outbound send writes to `WP_MCP_AI_CRM_Audit`.
- **No raw secrets** in code or post meta — all carrier credentials live in Password Vault and tools dereference by handle.
- **Idempotency keys** on outbound sends (channel + contact + content_hash) to prevent duplicate sends during retries.
- **Rate limiting** via existing `wp_mcp_ai_chat_channel_is_rate_limited` style guard.

The PHPCS `WPMCPAI.Tools.CanonicalReturnEnvelope` and `WPMCPAI.Tools.SanitizeAtEntry` sniffs will catch deviations at CI time.

---

## 9. Testing Strategy

- **Per-tool unit tests** under `tests/pro/tools/crm/{leads,deals,sequences,inbound,outbound,compliance,…}/`.
- **End-to-end fixtures**:
  1. Inbound email → triage → lead created → routed → owner notified → sequence enrolled.
  2. Inbound WhatsApp → consent check → triage → auto-reply within 24-hour window.
  3. Sequence step send blocked by suppression list → audit entry written → owner notified.
  4. Lead converted to customer → deal created in `closed_won` → pipeline forecast updated.
  5. Consent revocation → propagation across SMS / WhatsApp / email → next sequence step exits cleanly.
- **TCPA quiet-hours simulation** (per-recipient timezone) is a non-skippable test.
- **24-hour WhatsApp session window** simulation (in-session vs template-required) is a non-skippable test.
- Existing `composer run ci:all` is the gate (lint + sniffs + PHPUnit + folder-README check).

---

## 10. Migration & Backwards Compatibility

- **No existing tool slug changes.** Upwork tools relocate path-wise only.
- `mcp_crm_contacts` post type is preserved; `mcp_ai_lead` is added alongside it. The engine resolves either ID for backwards compatibility.
- `wp_mcp_ai_chat_channels_automation_rules` (the today's flat option used by Chat Channels) is upgraded **in place** to also store CRM-workflow-rule shims; existing rules continue to fire identically.
- `crm_email_search_leads` keeps its current behavior; new code calls `list_leads` for richer queries.
- A `wp mcp-ai crm migrate` WP-CLI sub-command handles the contacts→leads enrichment in batches with a `--dry-run`.

---

## 11. Documentation Deliverables

- `addons/pro/includes/tools/crm/README.md` — rewritten in the Healthcare style (module map, phased roadmap, settings, hooks, compliance section).
- `addons/pro/docs/toolkits/crm.md` — operator overview (mirrors `health-wellness.md`).
- `docs/tool-reference.md` — auto-regenerated from registry.
- `docs/guides/developer/crm-workflow-cookbook.md` — recipes: "How to build a 5-step sequence", "How to plug in a custom DNC list", "How to integrate a third-party classifier".
- Folder READMEs under every new subdirectory (per the project's folder-README convention; enforced by `composer run docs:check-folder-readmes`).

---

## 12. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| TCPA / GDPR liability if consent gate is bypassed | Consent + DNC checks are enforced **in the engine**, not in individual tools. Outbound capability flag `requires-consent` is opt-out only via filter, and the filter return is audited. |
| Carrier API downtime causes silent failures | All outbound writes pass through the existing `WP_MCP_AI_Pro_Schedule_Manager` retry queue; failures emit `wp_mcp_ai_crm_outbound_failed`. |
| WhatsApp 24-hour session expiry triggers user-pay template send unexpectedly | `send_lead_whatsapp` requires explicit `allow_template_message: true` argument outside the 24-hour window; otherwise returns `WP_Error( 'crm_whatsapp_session_expired' )`. |
| Sequence runaway sends to a list after consent revocation | Phase E `revoke_consent` synchronously calls `pause_sequence` for every active enrolment for that contact. Covered by E2E test #5. |
| AI classifier hallucinations route a lead to a wrong owner | `auto_route_inbound_message` always sets a confidence score; below threshold → routed to the unified Command Center inbox for human triage (never silently mis-assigned). |
| Existing CRM users have data shaped to `mcp_crm_contacts` | `mcp_ai_lead` is additive; `WP_MCP_AI_CRM_Engine::resolve_lead_id()` accepts either CPT id and returns a normalised record. WP-CLI migration is opt-in. |
| Scope creep into "full marketing automation" | Roadmap is bounded at Phase E. Marketing-automation features (landing pages, A/B emails, attribution modelling) are out of scope and tracked separately. |

---

## 13. Acceptance Criteria

The plan is "done" when:

1. The CRM toolkit registers **~73 tools** at runtime (verified by `WP_MCP_AI_Tool_Registry::get_tools()`).
2. The architecture matches Healthcare's layout 1:1 (engine, codes, audit, capabilities, per-toolkit settings, examples, phased README).
3. The Chat Channels inbox surfaces every classified inbound message inside the **Workflow Command Center**, and clicking a message opens the lead/deal context.
4. Outbound sends across email / SMS / WhatsApp are blocked when consent is missing — automatically, without per-tool wiring.
5. The four assistant blueprints import cleanly and pass smoke tests against fixture data.
6. `composer run ci:all` is green.

---

## 14. Open Questions for the Product Owner

1. **Single source of truth: Lead vs Contact.** Should `mcp_ai_lead` be a separate post type, or should we promote `mcp_crm_contacts` to carry lifecycle fields and keep a single object? *(Recommendation: separate — matches HubSpot's "Contact owns a Lead record" model and avoids overloading `manage_crm_contact`.)*
2. **Carrier preference.** Twilio is the obvious default for SMS — is there an existing customer-preferred provider (Vonage, MessageBird, Plivo, AWS SNS) we should support out of the gate?
3. **WhatsApp BSP.** WhatsApp Cloud API (Meta) vs a BSP (360dialog, Twilio, Infobip)? Existing chat-channels code suggests Cloud API direct — confirm.
4. **External CRM sync scope.** Phase E ships a generic OAuth-based `connect_to_external_crm`. Should we ship bidirectional sync mappings for HubSpot + Salesforce in Phase E, or defer to a follow-up?
5. **Pricing tier.** Multichannel outbound + sequences likely move CRM into a higher Pro tier. Decision needed before Phase C kickoff.

---

## 15. Next Steps

1. **Approve this plan** (or request changes) — one-line ack opens Phase A.
2. **File the umbrella issue** on GitHub mirroring this document's sections (one issue per phase as sub-issues).
3. **Phase A PR** (foundations + relocation, ~3 days; zero new tools, zero behavior change).
4. **Phase B PR** (CRUD batch, ~2 weeks; ~22 tools).
5. **Phase C PR** (triage + outbound, ~3 weeks; ~18 tools; new carrier integrations + IMAP polling).
6. **Phase D PR** (sequences + command center, ~2 weeks).
7. **Phase E PR** (compliance + blueprints, ~1.5 weeks).

Total estimate: **~9 engineering weeks** for the full A → E delivery, sized similarly to the Healthcare A → E delivery.

---

*Document prepared: 2026-05-28. Cross-references: [`PRO_TOOLKIT_ENHANCEMENT_REVIEW.md`](PRO_TOOLKIT_ENHANCEMENT_REVIEW.md) · [`../includes/tools/healthcare/README.md`](../includes/tools/healthcare/README.md) · [`CHAT_CHANNELS_TOOLKIT.md`](CHAT_CHANNELS_TOOLKIT.md) · [`CRM_EMAIL_MARKETING_GUIDE.md`](CRM_EMAIL_MARKETING_GUIDE.md).*
