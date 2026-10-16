# Food & Beverage Management

## Purpose

Deterministic Food & Beverage operations toolkit: reads the spec's 17 data
tables (Google Drive Sheets or CSV fixtures, or the single-folder ingest),
computes the 21 spec metrics (M-01…M-21) in PHP, and generates the 13 report
layouts (R-01…R-13) with Drafts-folder output only. Built from proposal
`docs/project/proposals/062-food-beverage-management-pro-toolkit-proposal.md`
and universalised in
`docs/project/proposals/078-fnb-toolkit-universalisation-proposal.md`.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | Central Pro tool registry (`enable_fnb_toolkit` toggle) |
| **Optional dependencies** | `google-workspace` toolkit (Drive adapter only) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Fnb_Settings` | `class-wp-mcp-ai-fnb-settings.php` | all tools |
| `WP_MCP_AI_Fnb_Data_Source` | `class-wp-mcp-ai-fnb-data-source.php` | all tools |
| `WP_MCP_AI_Fnb_Fixture_Reader` | `class-wp-mcp-ai-fnb-fixture-reader.php` | data source (csv/fixture adapter) |
| `WP_MCP_AI_Fnb_Google_Sheets_Reader` | `class-wp-mcp-ai-fnb-google-sheets-reader.php` | data source (drive adapter) |
| `WP_MCP_AI_Fnb_Metrics` | `class-wp-mcp-ai-fnb-metrics.php` | metric tools |
| `WP_MCP_AI_Fnb_Report_Builder` | `class-wp-mcp-ai-fnb-report-builder.php` | `fnb_generate_report` |
| `WP_MCP_AI_Fnb_Draft_Writer` | `class-wp-mcp-ai-fnb-draft-writer.php` | `fnb_save_draft`, `fnb_list_drafts` |
| `WP_MCP_AI_Fnb_Structure_Matcher` | `class-wp-mcp-ai-fnb-structure-matcher.php` | header-based table inference (`table_map` fallback) |
| `WP_MCP_AI_Fnb_Loader` | `class-wp-mcp-ai-fnb-loader.php` | CSV/TSV/JSON/JSONL → matrix |
| `WP_MCP_AI_Fnb_Observation` | `class-wp-mcp-ai-fnb-observation.php` | loose observation → canonical binding |
| `WP_MCP_AI_Fnb_Ingest_Quality` | `class-wp-mcp-ai-fnb-ingest-quality.php` | dedupe + out-of-band flags for import |
| `WP_MCP_AI_Fnb_Tool_Base` | `class-wp-mcp-ai-fnb-tool-base.php` | all `fnb_*` tools |
| `WP_MCP_AI_Fnb_Native_Cpt_Storage` (trait) | `trait-wp-mcp-ai-fnb-native-cpt-storage.php` | the five native CPT classes |
| `WP_MCP_AI_Fnb_Cpt_Reader` | `class-wp-mcp-ai-fnb-cpt-reader.php` | data source (native adapter) |
| `WP_MCP_AI_Fnb_Sale_CPT` | `class-wp-mcp-ai-fnb-sale-cpt.php` | table 6.0 native storage |
| `WP_MCP_AI_Fnb_Purchase_CPT` | `class-wp-mcp-ai-fnb-purchase-cpt.php` | table 8.0 native storage |
| `WP_MCP_AI_Fnb_Stock_Count_CPT` | `class-wp-mcp-ai-fnb-stock-count-cpt.php` | table 9.0 native storage |
| `WP_MCP_AI_Fnb_Waste_CPT` | `class-wp-mcp-ai-fnb-waste-cpt.php` | table 10.0 native storage |
| `WP_MCP_AI_Fnb_Booking_CPT` | `class-wp-mcp-ai-fnb-booking-cpt.php` | table 16.0 native storage |
| `WP_MCP_AI_Fnb_Admin_Menu` | `addons/pro/includes/admin/class-wp-mcp-ai-fnb-admin-menu.php` | top-level F&B Management menu |
| `WP_MCP_AI_Fnb_Dashboard_Page` | `addons/pro/includes/admin/class-wp-mcp-ai-fnb-dashboard-page.php` | F&B dashboard landing page |
| `WP_MCP_AI_Fnb_Ingest_Dashboard_Page` | `addons/pro/includes/admin/class-wp-mcp-ai-fnb-ingest-dashboard-page.php` | read-only ingest & preview review surface (Phase 10) |
| `WP_MCP_AI_Fnb_Settings_Page` | `addons/pro/includes/admin/class-wp-mcp-ai-fnb-settings-page.php` | settings page under NV oOS Pro |
| `WP_MCP_AI_Fnb_Pr_Service` | `pr/class-wp-mcp-ai-fnb-pr-service.php` | purchase-request engine (QMS-backed) |
| `WP_MCP_AI_Fnb_Pr_Gates` | `pr/class-wp-mcp-ai-fnb-pr-gates.php` | §A5 preconditions on the QMS state machine |
| 31 `WP_MCP_AI_Tool_Fnb_*` tools | `class-wp-mcp-ai-tool-fnb-*.php` + `pr/` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_fnb_toolkit`),
  `wp_mcp_ai_fnb_settings` (folder IDs, adapter, demo mode,
  `enable_fnb_native_storage`, `table_map`, `infer_tables`), the 17 data
  tables via the configured adapter, and the native CPTs when native storage
  is on.
- **Writes to:** Drafts folder only (Drive or local), transients for table
  caching (`wp_mcp_ai_fnb_tables`), and — via `fnb_import_table` — the five
  native CPTs (`mcp_ai_fnb_sale|purchase|stock|waste|booking`).
- **Upstream callers:** Pro tool registry, assistants A1–A4, Pro Schedule
  Manager.
- **Downstream collaborators:** `WP_MCP_AI_Pro_Google_Drive_Client`
  (google-workspace), the audit logger.

## Conventions

- Canonical envelope: success array or `WP_Error`; two-gate sanitisation
  (sanitize at entry, escape at exit); `required_capability` on every tool.
- All figures come from the metric engine — no model arithmetic (G-02, G-08).
- ACT-tier actions (send, order, change, image generation, posting) are out
  of this toolkit and off by default for the demo.
- Table IDs follow the spec: 1.0…17.0 plus `assumptions`.

## Native Storage (Phase 9)

When `enable_fnb_native_storage` is on (F&B settings page), the five
 transactional tables resolve from WordPress CPTs instead of Drive/CSV:

| Table | CPT | Idempotency key |
|---|---|---|
| 6.0 Daily sales | `mcp_ai_fnb_sale` | date + item_id + type |
| 8.0 Purchases | `mcp_ai_fnb_purchase` | delivery_date + supplier_id + invoice + ingredient_id |
| 9.0 Stock counts | `mcp_ai_fnb_stock` | count_date + count_type + ingredient_id |
| 10.0 Waste log | `mcp_ai_fnb_waste` | date + ingredient_id + reason |
| 16.0 Bookings | `mcp_ai_fnb_booking` | booking_id |

Rows are upserted by `fnb_import_table` (ACT-tier: `manage_options`, default
 off in the assistant packs) and editable under the top-level
 **F&B Management** admin menu. Before/after hooks fire per write:
 `wp_mcp_ai_fnb_before_<type>_log` / `wp_mcp_ai_fnb_after_<type>_log`.

## Data Ingestion (Open Format + Flexible Mapping)

Ingestion resolves *what a file is* from its **structure** (headers), not just
its filename. A configurable `table_map` (table ID → `sheet`/`file` name) is
used first; a **structural matcher** then scores each table ID by canonical-header
overlap; the legacy name guess is the last resort. See proposal
`078-fnb-data-ingestion-open-format-proposal.md`.

| Setting | Meaning |
|---|---|
| `table_map` | `table_id => { sheet|file => name }` operator-authored hint (highest trust) |
| `infer_tables` | enable structural (header-based) auto-map fallback (default on) |

New service classes (read-only, testable in isolation):

- `WP_MCP_AI_Fnb_Structure_Matcher` — `match_table()` / `best_table()` header
  inference with confidence = matched / required canonical columns.
- `WP_MCP_AI_Fnb_Loader` — CSV/TSV/JSON/JSONL/PDF/XLSX/ODS → shared
  `{columns, rows}` matrix with delimiter/encoding/BOM auto-detection. PDFs read
  via the pure-PHP `smalot/pdfparser` (the `extract_pdf_text` fallback
  dependency); XLSX/XLS/ODS read via `phpoffice/phpspreadsheet` (a Pro-addon
  dependency); scanned/image-only PDFs and photo invoices return a clear
  pointer to the OCR tools (`extract_pdf_text` with enable_ocr, `ocr_pdf_text`,
  `analyze_image`).
- `WP_MCP_AI_Fnb_Observation` — binds loose `{type, item, qty, unit, recorded_at,
  source, raw_value}` observations onto the five transactional tables via a
  terminology map (F&B analogue of the Health Records vitals/FHIR pattern);
  preserves `_raw_observation`.
- `WP_MCP_AI_Fnb_Ingest_Quality` — near-duplicate detection (idempotency keys +
  numeric tolerance) and out-of-band flags (negative qty, zero price with qty).
- `WP_MCP_AI_Fnb_Ingest_Dashboard_Page` — admin read-only "Ingest & Preview"
  surface (maps the Medical Vitals dashboard anatomy: Chart.js + AJAX over the
  same `fnb_import_table` dry-run path) showing mapping confidence, missing
  columns, flags and preview rows.

`fnb_import_table` surfaces a `mapping` confidence report, ingest-quality `flags`,
and a three-way source error (no source / wrong shape / unrecognised columns)
in its dry-run preview.

## Purchase Requests (Phase 9b)

The purchase-request approval lifecycle (Appendix A) runs on the QMS
 controlled-document engine — no parallel state machine. Purchase requests
 are `mcp_ai_doc_record` posts of doc-type `purchase-request`; every
 transition goes through `WP_MCP_AI_QMS_Workflow::transition()` and the F&B
 preconditions (§A5) attach via the `wp_mcp_ai_qms_state_preconditions`
 filter (`WP_MCP_AI_Fnb_Pr_Gates`). Requires the Document Generation toolkit
 with QMS compliance (`enable_qms_compliance`).

| Tool | Transition | Gates |
|---|---|---|
| `fnb_pr_create` | → draft (R-04 layout) | lines valid, total > 0, supplier set |
| `fnb_pr_submit` | draft → in_review | cut-off/lead time (A2-05), duplicate open PR, days-of-cover flags (A2-06) |
| `fnb_pr_return` | in_review/approved → draft | comments required |
| `fnb_pr_approve` | in_review → approved | approver ≠ creator, budget line set, M-20 warning |
| `fnb_pr_release` | approved → released | content-hash freeze, co-sign above threshold, PO number assigned |
| `fnb_pr_supersede` | released → superseded | creates next draft revision (same doc ID) |
| `fnb_pr_cancel` | any → obsolete | reason required (audited) |
| `fnb_pr_list` / `fnb_pr_audit` | — | read-only register + immutable trail |

The auto-approval threshold lives in `wp_mcp_ai_fnb_settings`
 (`pr_auto_approval_threshold`); release e-signatures are relaxed for PRs in
 favour of the hash freeze + finance co-sign (the generic QMS filter
 `wp_mcp_ai_qms_require_release_signature`).

## Assistant Blueprints (examples/)

The four A1–A4 assistants also ship as **blueprint-format** mirrors in
`examples/` so the toolkit appears on the unified **Blueprints** admin page
(one-click install) and through `import_fnb_blueprint` — see
`examples/README.md` for the tier discipline. The canonical bundles stay in
`addons/pro/presets/assistant-packs/fnb/` (richer: guardrail structure,
A4 image-tool seeder opt-in); A2's blueprint additionally grants
`fnb_pr_create` / `fnb_pr_list` / `fnb_pr_audit` (DRAFT/READ tier).

## Assistant Packs (A1–A4)

Shipped as canonical v1 `nvoos-assistant` bundles in
`addons/pro/presets/assistant-packs/fnb/` (see that folder's README for the
allowlist matrix and rule coverage). Seed with:

```bash
wp mcp-ai pro fnb seed-assistants            # idempotent
wp mcp-ai pro fnb seed-assistants --dry-run  # preview
```

Validate bundles without WordPress:
`php tests/pro/tools/food-beverage/validate-assistant-packs.php`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/food-beverage/
```

The oracle test reproduces the demo workbook's "Check totals" sheet values;
`test-fnb-native-storage.php` covers the Phase 9a CPT layer (registration
 gate, idempotent upserts, hooks, reader parity, import bridge),
`test-fnb-pr-workflow.php` covers the Phase 9b purchase-request lifecycle
 (transition matrix, preconditions, segregation of duties, freeze semantics,
 co-sign threshold, audit completeness — Appendix A §A8),
`test-fnb-structure-matcher.php` covers header-based inference,
`test-fnb-loader.php` covers multi-format loaders,
`test-fnb-observation.php` covers observation binding, and
`test-fnb-ingest-quality.php` covers dedupe + out-of-band flags.

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md)
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md)
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md)
- Proposal: `docs/project/proposals/062-food-beverage-management-pro-toolkit-proposal.md`
- Implementation plan: `docs/project/proposals/062-food-beverage-management-pro-toolkit-implementation-plan.md`
