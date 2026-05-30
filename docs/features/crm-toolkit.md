# CRM Toolkit

**Added:** Phase A–E: May 29, 2026; Phase C–D completion: May 31, 2026 (v1.1.25)  
**Tier:** Pro only  
**Location:** `addons/pro/includes/tools/crm/`

## Overview

The CRM Toolkit provides **70+ AI-powered tools** for end-to-end customer relationship management — from lead capture through deal closure to compliance auditing. Designed as a five-phase implementation (Phases A–E), the toolkit integrates with WordPress users, custom post types, and JetEngine CCTs to turn your site into a lightweight CRM with AI-assisted workflows.

## Quick Start

1. Navigate to **NV oOS → CRM** in the WordPress admin
2. The **Command Center** provides an overview of leads, deals, and active sequences
3. Configure CPT mappings under **CRM → Settings** (Leads CPT, Deals CPT, etc.)
4. Use **per-CPT Research Pages** to analyze and segment your CRM data
5. Assign CRM tools to assistants via the Assistant Editor

## Architecture

### Module Map

```
addons/pro/includes/tools/crm/
├── README.md                    ← Module map and conventions
├── engine/                      ← Shared infrastructure
│   ├── class-wp-mcp-ai-crm-data-store.php
│   ├── class-wp-mcp-ai-crm-sequence-engine.php
│   └── class-wp-mcp-ai-crm-compliance-checker.php
├── phase-a-leads/               ← Lead capture & management
├── phase-b-triage/              ← Multi-channel triage & routing
├── phase-c-integration/         ← Third-party integration hooks
├── phase-d-extensibility/       ← Extensibility hooks & filters
└── phase-e-compliance/          ← GDPR/CCPA audit & reporting
```

### Phase Summary

| Phase | Focus | Tools | Key Classes |
|-------|-------|-------|-------------|
| **A** | Lead Management | ~20 | Lead capture, scoring, enrichment, dedup, segmentation |
| **B** | Multi-channel Triage | ~15 | Email/SMS/chat routing, auto-responders, sentiment analysis |
| **C** | Integration Hooks | ~10 | Webhook receivers, third-party CRM sync, Zapier bridge |
| **D** | Extensibility | ~10 | Custom field mapping, workflow triggers, plugin API |
| **E** | Compliance | ~15 | GDPR/CCPA audit, data export, consent tracking, retention policies |

## Tools by Phase

### Phase A — Lead Management (~20 tools)

| Tool | Description |
|------|-------------|
| `crm_capture_lead` | Create lead from form, chat, or API |
| `crm_get_lead` | Retrieve lead details |
| `crm_update_lead` | Modify lead fields |
| `crm_delete_lead` | Remove lead (compliance-aware) |
| `crm_list_leads` | List with filtering, sorting, pagination |
| `crm_score_lead` | AI-powered lead scoring |
| `crm_enrich_lead` | Enrich with external data (Clearbit, etc.) |
| `crm_dedup_leads` | Find and merge duplicate leads |
| `crm_segment_leads` | Group leads by criteria |
| `crm_export_leads` | CSV/JSON export |
| `crm_import_leads` | Bulk CSV import |
| `crm_lead_timeline` | Activity timeline for a lead |
| `crm_assign_lead` | Assign to team member |
| `crm_lead_status_transition` | Move through pipeline stages |

### Phase B — Multi-channel Triage (~15 tools)

| Tool | Description |
|------|-------------|
| `crm_triage_inbound` | Route incoming message to correct handler |
| `crm_send_email` | Send from CRM-configured SMTP |
| `crm_send_sms` | Send via Twilio/Vonage |
| `crm_send_chat` | Send via configured chat channel |
| `crm_auto_responder` | Configure rule-based auto-responses |
| `crm_sentiment_analysis` | Analyze message tone and urgency |
| `crm_priority_queue` | View/manage prioritized queue |
| `crm_channel_health` | Monitor channel delivery rates |

### Phase C — Integration Hooks (~10 tools)

| Tool | Description |
|------|-------------|
| `crm_webhook_receive` | Inbound webhook processor |
| `crm_webhook_send` | Outbound webhook dispatcher |
| `crm_sync_external_crm` | Bidirectional sync (HubSpot, Salesforce) |
| `crm_zapier_trigger` | Zapier-compatible webhook emitter |
| `crm_integration_status` | Check integration health |

### Phase D — Extensibility (~10 tools)

| Tool | Description |
|------|-------------|
| `crm_custom_field_map` | Map external fields to CRM schema |
| `crm_workflow_trigger` | Define custom workflow triggers |
| `crm_plugin_bridge` | Expose CRM data to other plugins |
| `crm_extension_register` | Register third-party extensions |

### Phase E — Compliance (~15 tools)

| Tool | Description |
|------|-------------|
| `crm_gdpr_export` | Export all data for a subject |
| `crm_gdpr_delete` | Right-to-erasure compliant deletion |
| `crm_ccpa_report` | CCPA disclosure report |
| `crm_consent_audit` | Consent tracking audit trail |
| `crm_retention_check` | Verify data retention policies |
| `crm_compliance_report` | Full compliance status report |
| `crm_data_inventory` | Map all stored personal data |

## Admin Interface

### Command Center (`NV oOS → CRM`)

- **Dashboard widgets** — Lead volume, conversion rate, deal pipeline, sequence health
- **Quick Actions** — New lead, new deal, start sequence, run compliance check
- **Activity Feed** — Recent CRM events across all phases

### Per-CPT Research Pages

Dedicated research/analysis pages for each configured CPT:

- **Leads Research** — Segmentation analysis, source attribution, conversion funnel
- **Deals Research** — Pipeline velocity, win/loss analysis, forecast accuracy
- Custom CPT research pages configurable per CPT slug

### Analytics Dashboard

- **Pipeline Waterfall** — Visual deal progression through stages
- **Conversion Funnel** — Lead → MQL → SQL → Opportunity → Won
- **Sequence Performance** — Open/click/reply rates per sequence
- **Team Performance** — Per-member activity and conversion metrics

### Settings

Configurable under **CRM → Settings**:

| Setting | Type | Description |
|---------|------|-------------|
| `crm_leads_cpt` | select | CPT used for lead storage |
| `crm_deals_cpt` | select | CPT used for deal storage |
| `crm_default_pipeline` | text | Default deal pipeline stages |
| `crm_email_provider` | select | SMTP / Mailgun / SendGrid |
| `crm_sms_provider` | select | Twilio / Vonage |
| `crm_data_retention_days` | number | Auto-purge after N days (0 = never) |

## REST API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/mcp-ai-pro/v1/crm/leads` | GET, POST | List/create leads |
| `/mcp-ai-pro/v1/crm/leads/{id}` | GET, PUT, DELETE | Single lead CRUD |
| `/mcp-ai-pro/v1/crm/deals` | GET, POST | List/create deals |
| `/mcp-ai-pro/v1/crm/deals/{id}` | GET, PUT, DELETE | Single deal CRUD |
| `/mcp-ai-pro/v1/crm/sequences` | GET, POST | List/create sequences |
| `/mcp-ai-pro/v1/crm/compliance/{type}` | GET | Compliance reports |

All endpoints require `manage_options` capability. Per-object capability checks apply at the CPT level.

## Hooks

| Hook | Type | Description |
|------|------|-------------|
| `wp_mcp_ai_crm_lead_created` | Action | After lead creation |
| `wp_mcp_ai_crm_lead_status_changed` | Action | After status transition |
| `wp_mcp_ai_crm_deal_stage_changed` | Action | After deal moves pipeline stage |
| `wp_mcp_ai_crm_sequence_started` | Action | After outreach sequence triggers |
| `wp_mcp_ai_crm_compliance_check` | Action | After compliance audit runs |
| `wp_mcp_ai_crm_lead_fields` | Filter | Extend lead field schema |
| `wp_mcp_ai_crm_deal_fields` | Filter | Extend deal field schema |
| `wp_mcp_ai_crm_pipeline_stages` | Filter | Customize pipeline stages |
| `wp_mcp_ai_crm_integration_providers` | Filter | Register third-party integrations (Phase C) |
| `wp_mcp_ai_crm_extension_handlers` | Filter | Register extension handlers (Phase D) |

## Conventions

- All tools use canonical return envelope: `array` on success, `WP_Error` on failure
- Tool slugs follow pattern: `crm_{action}_{entity}`
- CPT-agnostic: tools work with any WordPress CPT configured as lead/deal storage
- Phase C integration hooks are provider-agnostic; specific providers register via filter
- Folder README at `addons/pro/includes/tools/crm/README.md`

## See Also

- [Unified Blueprint System](unified-blueprint-system.md) — Pre-built CRM assistant blueprints
- [Cloudways Toolkit](cloudways-toolkit.md)
- [Agent Skills](agent-skills.md)
