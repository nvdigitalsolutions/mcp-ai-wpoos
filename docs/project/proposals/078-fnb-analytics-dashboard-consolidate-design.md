# F&B Analytics Dashboard & Consolidate/Import — Design (Proposal 078 follow-on)

**Status:** Design + research complete; implementation in progress.

## Goal

Mirror the vitals toolkit's two-surface model onto F&B:

| Vitals surface | F&B equivalent | Backing |
|---|---|---|
| Medical Vitals dashboard (KPIs + trend charts + recent/panel tables) | **Analytics dashboard** | `WP_MCP_AI_Fnb_Metrics` + native CPT reads |
| Health Records Consolidate & Import (upload → map → preview → import) | **Consolidate & Import onboarding wizard** | loader + structure matcher + `fnb_import_table` |
| (n/a — already shipped) Ingest & Preview | existing read-only dry-run page | `fnb_import_table` dry-run |

## Research grounding (2026-10-17)

- **Multi-step import wizard** (CSVbox, Salesforce, Smart UI Patterns): upload → map → preview → repair → import, in digestible steps.
- **"Map automatically" + preview/repair** (Power Platform, Salesforce): auto-map headers, then let the user review/fix before writing.
- **Trust moment** (saasui.design): "data import is the first consequential thing … the moment the tool earns or forfeits trust" → preview-before-write is the core trust mechanism.
- **Schema-on-read** (SingleStore/Databricks): structure at read time; explicit mapping first, infer as fallback.
- **Analytics display anatomy** (Medical Vitals, already studied): empty KPI skeleton + `<canvas>` chart cards + `wp-list-table` bodies, populated by one AJAX `history` payload.

## Decisions

1. **Analytics dashboard** lives on `WP_MCP_AI_Fnb_Dashboard_Page` (server-rendered KPIs for the latest month; a "Load" refresh via AJAX for trend/table is optional on first cut).
2. **Consolidate & Import** is a new submenu page mirroring the vitals consolidate layout: source-folder scan → per-file detected table + confidence → "Preview" (dry-run) → "Import" (ACT-tier, `manage_options`, separate from the `edit_posts` review).
3. **Read/draft vs write split preserved**: review = `edit_posts`; import write = `manage_options`.

## Scope for this pass

Implement the **analytics dashboard** (KPIs + trend charts + recent table) on the existing dashboard page, backed by the metric engine + native CPT reads. The Consolidate & Import wizard is the next pass.
