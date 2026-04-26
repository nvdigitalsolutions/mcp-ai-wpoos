# Law Firm Toolkit

> Practice-management toolkit for solo attorneys, boutique firms and AmLaw teams: matters,
> clients, documents, time, trust accounting, e-discovery, compliance, and litigation
> support — all backed by WordPress CPTs and AI tools.

| | |
|---|---|
| **Activation setting** | `enable_law_firm_toolkit` |
| **Per-toolkit settings** | `wp_mcp_ai_law_firm_settings` |
| **Admin location** | NV oOS → Settings → Pro Features → Law Firm Toolkit |
| **Tools** | **64** AI tools across 7 modules |
| **Custom Post Types** | 5 (Matters, Clients, Documents, Time Entries, Trust Transactions) |
| **Compliance basis** | ABA Model Rules, IOLTA trust-accounting standards |
| **Available since** | Pro v2.0.0 |
| **Status** | ⚠️ **Analytical assistance only — does not constitute legal advice.** |

---

## What it provides

The Law Firm Toolkit turns a WordPress site into a complete legal practice-management
back-office. It combines:

1. **Matter, client and document data model** — enterprise-grade CPTs aligned with the
   ABA Model Rules of Professional Conduct and IOLTA trust-accounting standards.
2. **64 AI tools** for legal work across intake, matter management, document automation,
   billing & trust, litigation support, compliance/ethics, and research/analytics.
3. **Admin pages** — a Firm Dashboard and a Research & Add page for AI-assisted client/matter
   creation.

It is appropriate for:

- Solo and small-firm attorneys who need practice management on infrastructure they own.
- Boutique and mid-size firms that want AI-augmented intake, drafting, and analytics.
- Legal-tech vendors building AI workflows on top of WordPress + NV oOS.

---

## Custom post types

All CPTs are registered by `WP_MCP_AI_Law_Firm_CPT` (file:
`addons/pro/includes/class-wp-mcp-ai-law-firm-cpt.php`).

| CPT slug | Singular | Purpose |
|---|---|---|
| `mcp_ai_lf_matter` | Matter | Cases / engagements; linked to a client |
| `mcp_ai_lf_client` | Client | Person or organization the firm represents |
| `mcp_ai_lf_document` | Document | Pleadings, contracts, exhibits — attached to a matter |
| `mcp_ai_lf_time_entry` | Time Entry | Billable time, billed against a matter |
| `mcp_ai_lf_trust_txn` | Trust Transaction | IOLTA trust-account ledger entries |

Relationships:

```
Matter ──linked-to──> Client
Document ──attached-to──> Matter
Time Entry ──billed-to──> Matter
Trust Transaction ──linked-to──> Matter + Client
```

The CPTs ship with meta boxes for practice area, matter status, document type, and
billing type, plus taxonomies for those classifications. They are visible only when the
toolkit is enabled and the plugin is **not** running in Base mode.

---

## Admin pages

When the toolkit is enabled and you are in `wp-admin`:

| Page | Class | Loaded when… |
|---|---|---|
| **Settings** | `WP_MCP_AI_Law_Firm_Settings_Page` | Toolkit enabled |
| **Research & Add** | `WP_MCP_AI_Law_Firm_Research_Page` | `wp_mcp_ai_law_firm_settings.enable_research` is truthy (default `true`) |
| **Firm Dashboard** | `WP_MCP_AI_Law_Firm_Dashboard_Page` | `wp_mcp_ai_law_firm_settings.enable_firm_dashboard` is truthy (default `true`) |

Admin styles (`assets/css/admin-law-firm-toolkit.css`) are enqueued only on the five Law
Firm CPT screens.

---

## Tool modules

The 64 Law Firm tools live under `addons/pro/includes/tools/law-firm/` and are split into
seven modules. All tool slugs use the `lf_*` prefix so they're easy to filter.

### 1. Intake & Client Management (8 tools) — `intake-management/`

Conflict checking, intake forms, engagement letters, lead scoring, referral tracking and
client portal.

- `lf_client_intake_processor`
- `lf_conflict_of_interest_checker`
- `lf_engagement_letter_generator`
- `lf_lead_scoring_calculator`
- `lf_referral_source_tracker`
- `lf_client_communication_logger`
- `lf_client_portal_manager`
- `lf_client_profile_analyzer`

### 2. Matter Management (10 tools) — `matter-management/`

Pipeline, deadlines, court rules, statutes of limitation, opposing-counsel tracking,
case-outcome prediction, dashboards, budgets, and task assignment.

- `lf_matter_pipeline_manager`, `lf_case_status_dashboard`, `lf_case_timeline_generator`
- `lf_court_deadline_tracker`, `lf_calendar_rule_calculator`, `lf_statute_of_limitations_calculator`
- `lf_opposing_counsel_tracker`, `lf_matter_budget_manager`, `lf_task_assignment_manager`
- `lf_case_outcome_predictor`

### 3. Document Automation (10 tools) — `document-automation/`

Drafting, templates, clause library, contract review, redlining, pleading & brief
generation, citation checking, discovery requests, and version tracking.

- `lf_document_drafter`, `lf_document_template_manager`, `lf_clause_library_manager`
- `lf_contract_reviewer`, `lf_redline_comparator`, `lf_legal_citation_checker`
- `lf_pleading_generator`, `lf_brief_outline_generator`, `lf_discovery_request_builder`
- `lf_document_version_tracker`

### 4. Billing & Trust Accounting (10 tools) — `billing-trust/`

ABA-aligned billing, IOLTA trust ledgers, fee calculation, invoicing, AR tracking,
reconciliation, expense reimbursement, retainer monitoring, profitability analytics, and
billing-compliance audits. Backed by the `mcp_ai_lf_time_entry` and `mcp_ai_lf_trust_txn`
CPTs and the `WP_MCP_AI_Law_Firm_Calculator` helper.

- `lf_time_entry_recorder`, `lf_invoice_generator`, `lf_fee_calculator`
- `lf_trust_account_manager`, `lf_trust_reconciliation_tool`, `lf_retainer_balance_monitor`
- `lf_accounts_receivable_tracker`, `lf_expense_reimbursement_tracker`
- `lf_profitability_analyzer`, `lf_billing_compliance_checker`

### 5. Litigation Support (8 tools) — `litigation-support/`

Damages, settlement value, deposition summaries, e-discovery, evidence cataloguing,
expert-witness tracking, jury instructions and trial preparation.

- `lf_damages_calculator`, `lf_settlement_value_calculator`
- `lf_deposition_summary_generator`, `lf_ediscovery_document_analyzer`
- `lf_evidence_catalog_manager`, `lf_expert_witness_tracker`
- `lf_jury_instruction_drafter`, `lf_trial_preparation_checklist`

### 6. Compliance & Ethics (8 tools) — `compliance-ethics/`

Bar deadlines, CLE credits, confidentiality audits, data-privacy compliance, ethics-rule
checks, malpractice risk scoring, regulatory-change monitoring, and AI-usage disclosures
(important for keeping AI-assisted work product in line with ABA Formal Opinion 512 and
state-bar guidance).

- `lf_bar_deadline_monitor`, `lf_cle_credit_tracker`, `lf_client_confidentiality_auditor`
- `lf_data_privacy_compliance_checker`, `lf_ethics_rule_checker`
- `lf_malpractice_risk_scorer`, `lf_regulatory_change_monitor`
- `lf_ai_usage_disclosure_generator`

### 7. Research & Analytics (8 tools) — `research-analytics/`

Case-law analysis, competitive benchmarking, attorney utilization, firm performance,
client satisfaction, matter analytics, revenue forecasting, and a legal-research
assistant.

- `lf_case_law_analyzer`, `lf_legal_research_assistant`
- `lf_attorney_utilization_tracker`, `lf_firm_performance_dashboard`
- `lf_client_satisfaction_analyzer`, `lf_matter_analytics_generator`
- `lf_competitive_benchmarker`, `lf_revenue_forecaster`

---

## Activation

1. Install and activate the NV oOS Pro add-on (a valid license is required).
2. Confirm `WP_MCP_AI_BASE_VERSION` is **not** set to `true` in `wp-config.php`.
3. Go to **NV oOS → Settings → Pro Features** and toggle **Law Firm Toolkit** on.
4. (Optional) Visit **NV oOS → Settings → Law Firm** to enable/disable the
   Research & Add page and Firm Dashboard via the `wp_mcp_ai_law_firm_settings` option.
5. The five Law Firm CPTs will appear in the WordPress admin sidebar.

To activate from code:

```php
$settings = get_option( 'wp_mcp_ai_settings', array() );
$settings['enable_law_firm_toolkit'] = true;
update_option( 'wp_mcp_ai_settings', $settings );
```

---

## Permissions

Tools default to the `manage_options` capability. For multi-user firms, you should:

- Map roles such as `lawyer`, `paralegal`, and `billing_clerk` using a role-management
  plugin or a `wp_mcp_ai_tool_capability_map` filter (see `docs/DEVELOPER_HOOKS_REFERENCE.md`).
- Restrict trust-accounting tools (`lf_trust_*`) to a small group of authorized users —
  these touch IOLTA-regulated funds.

---

## Important compliance notes

- **Not legal advice.** Every Law Firm tool produces analytical output only. Attorneys
  remain professionally responsible for any work product they sign or file.
- **AI usage disclosure.** Use `lf_ai_usage_disclosure_generator` to produce client-facing
  language that aligns with ABA Formal Opinion 512 and applicable state-bar guidance.
- **Trust accounting.** The IOLTA-aligned trust workflow assumes that you reconcile your
  bank statement against the `mcp_ai_lf_trust_txn` ledger using
  `lf_trust_reconciliation_tool` on a schedule that satisfies your state's rules.
- **Confidentiality.** Treat the WordPress database as containing privileged communications.
  Apply at-rest encryption, off-site backup encryption, and TLS in transit; restrict admin
  access; and consider using the [Password Vault](password-vault.md) for sensitive
  credentials referenced by matters.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/README.md`](../../README.md) — Pro feature inventory and licensing
- [`docs/TOOLKIT_ARCHITECTURE.md`](../TOOLKIT_ARCHITECTURE.md) — toolkit boot sequence
- [`docs/DEVELOPER_HOOKS_REFERENCE.md`](../../../docs/DEVELOPER_HOOKS_REFERENCE.md) — how to filter tool capabilities
