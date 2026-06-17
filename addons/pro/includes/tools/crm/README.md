# CRM Toolkit

> Unified umbrella for the CRM & Email Marketing toolkits in NV oOS Pro.
> **Phases A–E deployed.** Shared engine, lead/deal/activity CRUD, inbound triage (email/SMS/WhatsApp webhooks), outbound multichannel (Twilio + notify.lk SMS, Meta WhatsApp API, email), AI-powered draft replies, auto-reply dispatch, sequences, command centre, compliance, and assistant blueprints are all in place. See the [enhancement plan](../../../docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md) for the full roadmap and design rationale.

This directory mirrors the [Healthcare Toolkit](../healthcare/README.md) layout:

* **Shared engine** — `WP_MCP_AI_CRM_Engine` provides cross-cutting helpers (settings, scoring, lifecycle, routing, pipeline, DNC, currency).
* **Standards registry** — `WP_MCP_AI_CRM_Codes` exposes seedable code packs (BANT / MEDDIC / CHAMP frameworks, HubSpot lifecycle stages, Salesforce pipeline stages, GDPR legal bases, disposition codes) so partners can plug in regional variants.
* **Unified audit ledger** — `WP_MCP_AI_CRM_Audit` records every PII read/write to the same append-only buffer.
* **Capability map** — `WP_MCP_AI_CRM_Capabilities` maps sales roles (sales_manager, account_executive, sdr, business_development, sales_ops, marketing_manager, marketing_ops, crm_viewer) onto WordPress capabilities.
* **Consent ledger** — `WP_MCP_AI_CRM_Consent` manages channel-specific consent records per contact, with real-time revocation (TCPA Apr 2025 FCC rule) and DNC enforcement.
* **Pipeline stage registry** — `WP_MCP_AI_CRM_Pipeline_Stages` defines deal stages with win probabilities for weighted forecasting.
* **Classifier** — `WP_MCP_AI_CRM_Classifier` provides heuristic intent/sentiment classification and BANT/MEDDIC field extraction, swappable via filter.
* **Per-toolkit settings** — `wp_mcp_ai_crm_toolkit_settings` stores defaults for currency, lifecycle stage, qualification framework, score thresholds, consent, routing, sequences, pipeline stages, and integration handles.
* **`is_available()` / `get_unavailable_reason()`** on every CRM tool so the orchestrator can skip the toolkit cleanly when its toggle is off.
* **Phased roadmap** — Phases A→E mirror the Healthcare A→E roadmap (see the enhancement plan).

---

## Module map

| Module | Folder | Sub-module | Phase introduced |
|---|---|---|---|
| Shared engine, codes, audit, capabilities, consent, pipeline stages, classifier | `.` (flat) | Shared infrastructure | Phase A |
| Contact & Company CRUD | `.` (flat) | Core | Pre-Phase A (existing) |
| Email search (leads, accounting, correspondence) | `.` (flat) | Core | Pre-Phase A (existing) |
| MemPalace capture | `.` (flat) | Core | Pre-Phase A (existing) |
| Upwork (proposals, scoring, search) | `upwork/` | Core (relocated Phase A) | Pre-Phase A |
| Lead CRUD + qualification | `leads/` | Lead Management | Phase B ✅ |
| Deal / opportunity CRUD | `deals/` | Pipeline | Phase B ✅ |
| Activity CRUD (calls, meetings, tasks) | `activities/` | Core | Phase B ✅ |
| Customer CRUD | `customers/` | Customer Management | Phase B ✅ (v2.6.0) |
| Outreach sequences | `sequences/` | Automation | Phase D ✅ |
| Inbound triage | `inbound/` | Multichannel (IMAP/SMS/WA webhooks + Gmail import) | Phase C ✅ |
| Outbound send | `outbound/` | Multichannel (Twilio/notify.lk/WhatsApp/email) | Phase C ✅ |
| Lead routing | `routing/` | Core | Phase B ✅ |
| Pipeline analytics | `analytics/` | Reporting | Phase B ✅ |
| Consent, DNC, opt-out | `compliance/` | Compliance | Phase E ✅ |
| Workflow Command Center | `command-center/` | Automation | Phase D ✅ |
| Assistant blueprints | `examples/` | Interop | Phase E ✅ |

> All five phases are implemented. Each subdirectory contains its tool files;
> the shared engine classes provide cross-cutting infrastructure. See the
> [enhancement plan](../../../docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md) for the
> design rationale and per-phase tool counts.

---

## Public Surface (single-level tool files)

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Manage_CRM_Contact` | `class-wp-mcp-ai-tool-manage-crm-contact.php` | tool registry |
| `WP_MCP_AI_Tool_Create_Company` | `class-wp-mcp-ai-tool-create-company.php` | tool registry |
| `WP_MCP_AI_Tool_Get_Companies` | `class-wp-mcp-ai-tool-get-companies.php` | tool registry |
| `WP_MCP_AI_Tool_Research_Company` | `class-wp-mcp-ai-tool-research-company.php` | tool registry |
| `WP_MCP_AI_Tool_CRM_Capture_Interaction` | `class-wp-mcp-ai-tool-crm-capture-interaction.php` | tool registry |
| `WP_MCP_AI_Tool_CRM_Email_Search_Accounting` | `class-wp-mcp-ai-tool-crm-email-search-accounting.php` | tool registry |
| `WP_MCP_AI_Tool_CRM_Email_Search_Correspondence` | `class-wp-mcp-ai-tool-crm-email-search-correspondence.php` | tool registry |
| `WP_MCP_AI_Tool_CRM_Email_Search_Leads` | `class-wp-mcp-ai-tool-crm-email-search-leads.php` | tool registry |
| `WP_MCP_AI_Tool_Create_Customer` | `customers/class-wp-mcp-ai-tool-create-customer.php` | tool registry |
| `WP_MCP_AI_Tool_Get_Customer` | `customers/class-wp-mcp-ai-tool-get-customer.php` | tool registry |
| `WP_MCP_AI_Tool_Update_Customer` | `customers/class-wp-mcp-ai-tool-update-customer.php` | tool registry |
| `WP_MCP_AI_Tool_Delete_Customer` | `customers/class-wp-mcp-ai-tool-delete-customer.php` | tool registry |
| `WP_MCP_AI_Tool_List_Customers` | `customers/class-wp-mcp-ai-tool-list-customers.php` | tool registry |
| `WP_MCP_AI_Tool_Draft_Upwork_Proposal` | `upwork/class-wp-mcp-ai-tool-draft-upwork-proposal.php` | tool registry |
| `WP_MCP_AI_Tool_Score_Upwork_Job` | `upwork/class-wp-mcp-ai-tool-score-upwork-job.php` | tool registry |
| `WP_MCP_AI_Tool_Search_Upwork_Jobs` | `upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php` | tool registry |
| `WP_MCP_AI_Tool_Import_CRM_Blueprint` | `examples/class-wp-mcp-ai-tool-import-crm-blueprint.php` | tool registry |
| `WP_MCP_AI_Tool_Identify_Top_Customers` | `analytics/class-wp-mcp-ai-tool-identify-top-customers.php` | tool registry |
| `WP_MCP_AI_Tool_Identify_Top_Clients` | `analytics/class-wp-mcp-ai-tool-identify-top-clients.php` | tool registry |
| `WP_MCP_AI_Tool_Classify_Email_Hygiene` | `compliance/class-wp-mcp-ai-tool-classify-email-hygiene.php` | tool registry |
| `WP_MCP_AI_Tool_Manage_Email_Hygiene` | `compliance/class-wp-mcp-ai-tool-manage-email-hygiene.php` | tool registry |
| `WP_MCP_AI_Tool_Prune_CRM_Messages` | `compliance/class-wp-mcp-ai-tool-prune-crm-messages.php` | tool registry |

### Email Hygiene Module

Three new Phase E tools (v2.8.0) provide email list hygiene for WordPress plugin environments:

| Tool | Purpose |
|---|---|
| `classify_email_hygiene` | Multi-layer heuristic classifier: spam, promotional/newsletter, notification, priority. Returns hygiene score 0–100 + recommended action per CAN-SPAM and Google/Yahoo 2024–2025 guidelines |
| `manage_email_hygiene` | CRUD over exclude list and priority list. Supports exact emails, @domain patterns, and substring matching. Changes propagate instantly to the import pipeline |
| `prune_crm_messages` | Batch-clean leads by spam flag, excluded domains, staleness (configurable age threshold), and zero-engagement. Dry-run mode for safe preview. Industry recommendation: remove unengaged after 90–180 days |

Both lists are managed through **NV CRM → Settings → Configuration → Email Hygiene & List Management** with textarea-based editing.

### Top Customers vs Top Clients — what's the difference?

| Aspect | `identify_top_customers` | `identify_top_clients` |
|---|---|---|
| **Question it answers** | "Who is worth the most to my business?" | "Who do I talk to the most?" |
| **Primary data sources** | `mcp_ai_lead` + `mcp_ai_deal` + `mcp_ai_customer` | `mcp_ai_crm_activity` (calls, emails, meetings, tasks, notes) |
| **Scoring model** | Lead quality (40%) + deal pipeline (35%) + activity volume (15%) + lifecycle stage (10%) | Interaction volume (40%) + recency (25%) + channel diversity (20%) + completion rate (15%) |
| **What high score means** | This contact has strong BANT qualification, high-value deals, and is far along the sales funnel — a prime *conversion/revenue* target | This contact has the most logged interactions across the most channels within the shortest time — your most *actively managed* relationship |
| **Typical use case** | "Show me my top 10 accounts by deal value" — prioritise who to upsell or nurture | "Who haven't I contacted this month?" — audit coverage gaps, rebalance workloads |
| **Considers revenue?** | Yes — deal amounts, won deals, lifecycle stage | No — strictly activity-based; a lead with zero deals can still rank #1 if contacted daily |

## Shared Infrastructure (engine classes + installer)

| Symbol | File | Purpose |
|---|---|---|
| `WP_MCP_AI_Blueprint_Installer` | `../class-wp-mcp-ai-blueprint-installer.php` | Shared static installer for all toolkit blueprints |

| Symbol | File | Purpose |
|---|---|---|
| `WP_MCP_AI_CRM_Engine` | `class-wp-mcp-ai-crm-engine.php` | Settings, scoring, lifecycle, routing, pipeline, DNC, currency |
| `WP_MCP_AI_CRM_Codes` | `class-wp-mcp-ai-crm-codes.php` | BANT/MEDDIC/CHAMP, lifecycle stages, channels, intents, sources, sentiment, dispositions |
| `WP_MCP_AI_CRM_Audit` | `class-wp-mcp-ai-crm-audit.php` | Append-only PII/consent audit ledger (rolling buffer) |
| `WP_MCP_AI_CRM_Capabilities` | `class-wp-mcp-ai-crm-capabilities.php` | Role → WP cap map (8 sales roles, 30+ logical capabilities) |
| `WP_MCP_AI_CRM_Consent` | `class-wp-mcp-ai-crm-consent.php` | Channel-specific consent records + DNC enforcement + revocation |
| `WP_MCP_AI_CRM_Pipeline_Stages` | `class-wp-mcp-ai-crm-pipeline-stages.php` | Deal stage definitions with win probabilities |
| `WP_MCP_AI_CRM_Classifier` | `class-wp-mcp-ai-crm-classifier.php` | Intent/sentiment classification + BANT/MEDDIC extraction |

---

## Settings option (`wp_mcp_ai_crm_toolkit_settings`)

```php
array(
    'default_currency'         => 'USD',
    'default_lifecycle_stage'  => 'lead',
    'qualification_framework'  => 'bant',
    'hot_score_threshold'      => 70,
    'warm_score_threshold'     => 40,
    'audit_retention_days'     => 365,
    'consent'                  => array(
        'require_double_opt_in'   => true,
        'default_legal_basis'     => 'legitimate_interest',
        'unsubscribe_footer_text' => '',
        'physical_address'        => '',
    ),
    'routing'                  => array(
        'strategy'    => 'round_robin',
        'pool'        => array(),
        'territories' => array(),
    ),
    'sequences'                => array(
        'send_hours_local'        => array( 9, 18 ),
        'send_days'               => array( 1, 2, 3, 4, 5 ),
        'pause_on_reply'          => true,
        'pause_on_meeting_booked' => true,
    ),
    'pipeline'                 => array(
        'stages' => array( /* qualification → discovery → proposal → ... → closed_won/lost */ ),
    ),
    'integrations'             => array(
        // Twilio (SMS outbound + inbound webhook).
        'twilio_account_sid_secret' => '',
        'twilio_auth_token_secret'  => '',
        'twilio_from_number'        => '', // E.164, e.g. +1234567890
        // WhatsApp (Meta Cloud API outbound + inbound webhook).
        'whatsapp_access_token'     => '',
        'whatsapp_phone_number_id'  => '',
        'whatsapp_app_secret'       => '',
        // notify.lk (Sri Lanka SMS gateway).
        'notifylk_user_id'          => '',
        'notifylk_api_key'          => '',
        'notifylk_sender_id'        => '',
        // OAuth handles for IMAP/Gmail/Outlook.
        'gmail_oauth_handle'        => '',
        'outlook_oauth_handle'      => '',
        // Default SMS provider: 'twilio' | 'notifylk'.
        'sms_provider'              => 'twilio',
    ),
);
```

Programmatic access: `WP_MCP_AI_CRM_Engine::get_toolkit_settings()`.  Filterable via `wp_mcp_ai_crm_toolkit_settings`.

---

## Filters & Actions (Phase A)

| Hook | Type | Purpose |
|---|---|---|
| `wp_mcp_ai_crm_toolkit_settings` | filter | Override resolved toolkit settings. |
| `wp_mcp_ai_crm_capabilities` | filter | Override the role-to-capability map. |
| `wp_mcp_ai_crm_code_packs` | filter | Register additional CRM code packs. |
| `wp_mcp_ai_crm_pipeline_stages` | filter | Override pipeline stage definitions. |
| `wp_mcp_ai_crm_score_factors` | filter | Override lead-scoring factor weights. |
| `wp_mcp_ai_crm_routing_strategy` | filter | Override the routing-strategy resolution at runtime. |
| `wp_mcp_ai_crm_classify_intent` | filter | Replace the entire intent classifier. |
| `wp_mcp_ai_crm_buying_signal_keywords` | filter | Extend the buying-signal keyword list. |
| `wp_mcp_ai_crm_consent_evidence` | filter | Mutate a consent record before storage. |
| `wp_mcp_ai_crm_before_audit` | filter | Suppress an audit entry before it is written. |
| `wp_mcp_ai_crm_after_audit` | action | Forward audit entries to an external SIEM. |
| `wp_mcp_ai_crm_lead_score_calculated` | action | Fired after a composite lead score is calculated. |

---

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (CRM toggle), CCT/CPT data store for contacts and companies
- **Writes to:** Contact and company records via `WP_MCP_AI_Toolkit_Data_Store`; MemPalace via `WP_MCP_AI_Pro_Capture_Tool_Base`; audit log via `WP_MCP_AI_CRM_Audit`; consent records via `WP_MCP_AI_CRM_Consent`
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Toolkit_Data_Store_Factory`, `WP_MCP_AI_Validator_Service` (email/phone validation), `WP_MCP_AI_Memory_Capture_Service`, `WP_MCP_AI_Upwork_Client`
- **Events fired:** `wp_mcp_ai_crm_lead_score_calculated`, `wp_mcp_ai_crm_after_audit`
- **Events listened to:** `wp_mcp_ai_chat_channel_message_received` (chat channel → CRM pipeline), `rest_api_init` (SMS/WhatsApp webhook routes)

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- All tools implement `is_available()` / `get_unavailable_reason()` (static, checked by the orchestrator before `execute()`).
- Contact management uses the toolkit data store pattern (CCT via JetEngine, CPT fallback).
- Email search tools provide targeted accounting, correspondence, and lead-focused searches.
- Upwork tools (proposal drafting, job scoring, job search) are in `upwork/`.
- `crm_capture_interaction` extends `WP_MCP_AI_Pro_Capture_Tool_Base` with account/ wing prefix.
- Shared engine classes follow the Healthcare toolkit's pattern of static helper methods + constants.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/crm/
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Pro tools index
- [`../../../docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md`](../../../docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md) — Phases A→E enhancement roadmap
- [`../healthcare/README.md`](../healthcare/README.md) — Healthcare toolkit architecture (reference pattern)
