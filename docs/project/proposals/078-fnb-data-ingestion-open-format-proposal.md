# Proposal 078: F&B Data Ingestion — Open Format, Flexible Mapping & "Vital-Log" Observation Model

**Date:** 2026-10-16
**Status:** PROPOSAL — design + implementation plan written; implementation in progress.
**Estimated Effort:** 34–47 hours (Phases 0–10, incl. dashboard).
**Priority:** HIGH (unblocks live owner data; the current toolkit cannot ingest real operator files without a hard-coded name map).

> Scope: replace the F&B toolkit's hard-coded **sheet-name → table-ID** mapping
> with an **open, format-agnostic ingestion pipeline** that accepts owner data in
> as many shapes as possible — CSV, TSV, JSON/JSONL, XLSX/XLS/ODS, Google Sheets
> tabs, and PDF/photo supplier invoices via the toolkit's OCR stack — and
> resolves *what a file is* from its **structure** (headers) rather than its
> **filename**. The design mirrors
> the Health Records **Vital Signs** ingest (`import_vitals` / `log_vital_signs`,
> `mcp_ai_hc_vital_log` CCT): a single **Observation**-style row shape with
> `raw_value` preserved beside a `normalised` value, driven by a **terminology
> map** (table-ID ↔ canonical columns) instead of a filename map.

> Related in-repo work: [`062-food-beverage-management-pro-toolkit-proposal.md`](062-food-beverage-management-pro-toolkit-proposal.md)
> (the toolkit this extends), the Health Records consolidate page + `vitals_log`
> CCT (`addons/pro/includes/admin/class-wp-mcp-ai-health-records-consolidate-page.php`,
> `addons/graphify/` `mcp_ai_hc_vital_log`), and the `mcp-ai-wpoos-toolkit-audit`
> skill (four failure classes to avoid).

---

## 1. Executive Summary

The F&B toolkit shipped in Proposal 062 can **analyse** data, but it can only
**ingest** data it can already recognise. Recognition is a hard-coded
`$sheet_map` (a filename → table-ID array baked into two reader classes), which
means any owner file that is not named `daily_sales`, `purchases`, `waste_log`,
etc. silently fails with *"No file in the configured folder mapped to F&B table
'X'"* — even when the file's **contents** are perfectly valid.

This proposal makes ingestion **structural, not nominal**:

1. **A detection service** that reads a candidate file's headers and matches them
   against each table's **canonical column set** (already defined in
   `WP_MCP_AI_Fnb_Data_Source::$tables`), independent of filename.
2. **An open-format adapter layer** that normalises CSV / TSV / JSON / JSONL /
   XLSX / ODS / Google Sheets tabs into one `rows×columns` matrix, with
   delimiter/encoding/header auto-detection and a shared **data contract**.
3. **An operator-editable `table_map` setting** (the configurable mapping the
   owner explicitly asked for), used *first* when present, with the structural
   matcher as the automatic fallback.
4. **A "vital-log" Observation ingestion mode** for the five transactional tables
   (sales, purchases, stock, waste, bookings): accept one-observation-per-row
   input with `raw_value` + `unit` + `recorded_at` + `source`, normalise via the
   terminology map, and preserve the raw form for later reconciliation — exactly
   the pattern the Health Records vitals CCT already uses.

The result: an owner can drop a file in *almost any* common form — a Google
Sheet named `"SurfClub October"`, an exported `.xlsx`, a supplier PDF-to-CSV, a
JSONL of POS lines — and the toolkit identifies it, previews a mapping in
`dry_run`, and (for ACT-tier users) imports it into native storage without
touching PHP.

**Outcome:** ingestion stops being the single biggest on-ramp blocker for the
F&B toolkit, and the toolkit ships with a reusable, testable,
industry-grounded ingestion core rather than a demo-specific filename table.

---

## 2. Problem Statement

1. **Recognition is filename-driven, not content-driven.** Both reader adapters
   (`class-wp-mcp-ai-fnb-google-sheets-reader.php`,
   `class-wp-mcp-ai-fnb-fixture-reader.php`) resolve a table ID by matching a
   hard-coded `$sheet_map` name to a Drive file stem or a CSV slug. A renamed
   file is invisible regardless of its contents.
2. **There is no operator-facing mapping surface.** The owner cannot say "this
   sheet is daily sales" without editing PHP. The `wp_mcp_ai_fnb_settings`
   option knows *where* to look (`data_folder_id`, `data_workbook_id`) but never
   *what maps to what*.
3. **Format support is narrow.** The CSV adapter reads a strict first-row-header
   layout; the Drive adapter reads Google Sheets and calls `export_csv`. JSON,
   JSONL, TSV, XLSX/ODS uploads, delimiter variants, and non-UTF-8 encodings are
   not handled, and header drift beyond the per-table `aliases` map is fatal.
4. **The "five transactional tables" are the hardest to reconcile and the
   highest-value to import.** Sales, purchases, stock, waste and bookings are
   precisely the data an owner mails as invoices, spreadsheets and POS exports —
   where line-items are the food-cost requirement (header-only invoice totals are
   insufficient for costing) and supplier price/unit/pack normalisation is the
   operator's manual burden today.
5. **Ingestion errors are opaque and non-actionable.** A failed resolve surfaces
   a generic "no file mapped" message that does not distinguish (a) no source
   configured, (b) source configured but wrong shape, or (c) source found but
   unrecognised columns. Operators cannot self-serve.

---

## 3. Research: Industry Standards & Best Practices

Web research (2026-10-16) grounding the design. It falls into three pillars:
**hospitality invoice/ingestion**, **flexible observation modelling** (the
"vital log" precedent), and **multi-format ingestion engineering**.

### 3.1 Hospitality F&B ingestion & invoice handling

- **Line-item extraction is mandatory for food cost.** "Header-only extraction
  (one row per invoice, with the invoice total and tax) is enough for AP posting
  if the only goal is to record what was paid to whom. It is not enough for
  anything food-cost related." (invoicedataextraction.com). The five transactional
  tables are inherently line-item-shaped — the ingestion model must preserve per
  line-item rows, never flatten to header-only summaries.
- **Supplier invoice coding → canonical categories.** "Each incoming bill is
  posted to the category that preserves the most useful management signal: food,
  alcoholic beverage, non-alcoholic beverage, packaging, cleaning, freight,
  utilities…" (invoicedataextraction.com). The F&B `category` / `group` columns
  are the coding surface; the terminology map should normalise free-text vendor
  categories into the canonical buckets already used by metrics/reports.
- **Item/unit/pack normalisation is where the spreadsheet-era operator breaks.**
  "The layer vendor pages name and skip past — 'we standardize different item
  names, units, and pack formats' — is the layer the operator running the
  spreadsheet has to do explicitly, because it is where the unit-price comparison
  lives." (invoicedataextraction.com). A deterministic alias/normalisation table
  (item name → `item_id`, unit → canonical unit, pack → `pack_size`) is the
  highest-leverage mapping the toolkit can offer beyond filename recognition.
- **Standardise → categorise → review**, and confirm a shared data contract for
  delimiter/encoding/header (topetl.com CSV-transformation guide; parserdata.com).

### 3.2 Flexible "Observation" modelling — the vital-log precedent

The Health Records **Vital Signs** surface already solves a structurally
identical problem: ingest heterogeneous measurements (BP, HR, temperature, SpO2,
glucose, weight) of varying units and sources, without a bespoke table per vital.

- **One observation per row.** FHIR `Observation` carries one measurement or
  simple assertion per resource, with `code` (what was measured, ideally a
  LOINC concept), `value`, `unit` (UCUM), `status`, and provenance (patient,
  encounter, device, time). (build.fhir.org/observation.html; hl7.org/fhir/R4).
- **Raw + normalised dual storage.** The repo's `vitals_log` CCT stores
  `raw_value`, `value`, `unit`, `notes`, `recorded_at`, `measurement_type` —
  i.e. the original input is preserved verbatim beside the normalised number so
  nothing is lost and any transform can be audited. (graphify bridge,
  `mcp_ai_hc_vital_log`.)
- **Terminology binding, not transport rewrite.** "FHIR does not replace
  [terminologies]; it binds to them." (capminds.com). The F&B equivalent: keep the
  existing 17-table + canonical-column contract fixed (it is the terminology),
  and add a *binding* layer that maps arbitrary input onto it — rather than
  regenerating the contract per owner.

**Consequence for F&B:** model each ingested fact as an observation (source
value + unit + recorded timestamp + raw text) bound to a canonical table/column
via the terminology map. This unifies row-shaped tables (sales, bookings) and
measurement-shaped tables (stock counts, waste) under one ingestion core.

### 3.3 Multi-format ingestion engineering

- **Standardise → normalise → clean** is the canonical order; "when you
  standardize and normalize data from all sources during ingestion, you eliminate
  confusion." (montecarlo.ai).
- **Document delimiter + encoding in a data contract, and validate encoding on
  ingest** via checksum or header inspection (topetl.com).
- **Store raw, project parsed (schema-on-read).** The Snowflake "variant column"
  and Palantir `inferSchema` patterns: ingest the raw blob first, then infer a
  schema and build a parsed view on top, rather than destroying the source on
  write (reddit.com/r/dataengineering; palantir.com foundry docs).
- **Schema inference is header-driven and should be surfaced, not silent.**
  `inferSchema`/header inspection resolves types; the toolkit equivalent is
  matching a file's normalised headers against canonical columns and reporting
  match confidence rather than failing closed.
- **OCR is the on-ramp for paper-first operators.** Supplier invoices arrive as
  PDFs and photographed documents; AI-assisted OCR converts them to
  line-item data (invoicedataextraction.com; overeasyoffice.com). The toolkit
  should re-use its existing OCR stack rather than reinventing extraction
  (see §4.5).
- **Prefer extraction with confidence + structured output.** Structured OCR
  (JSON with per-field confidence) feeds the Structure matcher directly, whereas
  plain text needs a second parsing pass (parserdata.com; the toolkit's
  `pro_document_ocr` confidence-scores ✓).

---

## 4. Design

### 4.1 Ingestion pipeline (single path, all formats)

```
raw source (CSV/TSV/JSON/JSONL/XLSX/ODS/Sheets tab/PDF/photo)
        │
        ▼
  Loader  ── detects format by extension + content sniff (not filename meaning)
        │                └─ PDF/photo → OCR stack (extract_pdf_text / pro_document_ocr)
        ▼
  Smart-CSV   ── delimiter / quote / encoding / header-row auto-detection
        │
        ▼
  Matrix      ── normalised { columns[], rows[] } (shared with existing readers)
        │
        ▼
  Structure matcher ── score each table ID by canonical-header overlap
        │
        ▼
  A) dry_run → preview: { table_id, confidence, matched/missing columns, samples }
  B) write    → upsert via existing idempotent CPT upsert (ACT-tier only)
```

### 4.2 Configurable mapping (`table_map`), used first, structural fallback second

Add one setting under `wp_mcp_ai_fnb_settings`:

```php
'table_map' => array(), // table_id => { 'sheet'|'file' => name, 'confidence' => 0..1 }
```

Resolution order per table ID:

1. **Explicit `table_map` entry** — operator-provided `sheet`/`file` name (highest
   trust; bypasses inference).
2. **Structural matcher** — header-overlap score against
   `Data_Source::table_definition( $id )['canonical']` (see 4.3); accept when
   confidence ≥ threshold (default 0.60, filterable).
3. **Name-based guess** — the existing `$sheet_map` demoted to a low-priority
   fallback so the shipped demo still resolves with zero config.
4. **Actionable error** — report *which* of the three failed and why (no source /
   wrong shape / unrecognised columns).

### 4.3 Structure matcher (the core "auto-map" behaviour)

For a candidate matrix and a table ID:

- Normalise headers with the existing `normalise_header()` (already collapses
  `_`/`-`/case).
- Compute matched canonical columns via `resolve_column()` (already alias-aware).
- **Confidence = matched / canonical_required**, where `canonical_required` is a
  per-table subset (idempotency keys + natural keys) rather than every optional
  column, so sparse owner files still map. Compute a `missing` list for preview.
- Emit `confidence` + `matched` + `missing` + up-to-5 sample rows in `dry_run`,
  never silently choosing under threshold into native storage (preserves the
  toolkit's "never invent records" guardrail).

### 4.4 "Vital-log" Observation mode for transactional tables

For sales / purchases / stock / waste / bookings, accept an **observation** row
shape as an alternative to the wide canonical layout:

```json
{ "type": "waste", "item": "tomatoes", "qty": 2.5, "unit": "kg",
  "recorded_at": "2026-10-15", "source": "supplier_csv", "raw_value": "2.5 kg" }
```

- **Terminology map** (a `measurement_type`/`table_id` → canonical binding, the
  F&B analogue of LOINC) instead of the filename map.
- **Raw + normalised**: persist `raw_value`/`raw_row` beside parsed columns so a
  bad unit or unit-price transform is reversible and auditable (matches the
  `vitals_log` `raw_value`/`value` split).

### 4.5 Format adapter layer

| Format | Detection | Notes |
|---|---|---|
| CSV | sniff delimiter (`,"\t`), quote, BOM/UTF-8, header row | primary; validate encoding on ingest |
| TSV | tab delimiter sniff | same engine, `\t` |
| JSON (array of objects) | parse + infer headers from object keys | maps to matrix columns |
| JSONL / NDJSON | line-by-line parse, infer keys | best for POS/event streams |
| XLSX / XLS / ODS | existing upload path → PhpSpreadsheet (already a repo dep) | first-sheet by default, `sheet` hint override |
| Google Sheets tab | existing Sheets reader | tab resolved by `table_map` or structural match |
| PDF (text layer) | `extract_pdf_text` (`enable_ocr=true` for scanned) → matrix | line-item extraction for purchases/sales |
| PDF (scanned / image-only) | `ocr_pdf_text` (OpenAI/Gemini/Ollama/Tesseract) → text → matrix | single-doc; 10-page default, raise `max_pages` |
| PDF (layout-rich, multi-page) | `pro_document_ocr` → JSON/Markdown with per-field confidence | feeds the Structure matcher directly |
| Photo / image invoice | `analyze_image` / `extract_image_text` (vision) → text → matrix | best-effort for photographed bills |

### 4.6 Guardrails (non-negotiable)

- **Everything resolves in `dry_run` first**; writes are `manage_options` and
  default-off (unchanged from 062).
- **Never silently bind** a low-confidence match into native storage.
- **Raw preserved** beside normalised (no destructive transform).
- **Cite the source row/file** in every import result (G-03).

---

## 5. Phased Implementation Plan

### Phase 0 — Research & contract (already done here)
Deliver: this proposal + a written **data contract** (delimiter/encoding/header
rules) recorded in the toolkit README.

### Phase 1 — Configurable mapping surface (0.5 day)
Add `table_map` to `WP_MCP_AI_Fnb_Settings::$defaults`; a JSON/textarea field on
the F&B settings page; `Settings::get('table_map')` accessor + tests.

### Phase 2 — Structure matcher service (1 day)
New `class-wp-mcp-ai-fnb-structure-matcher.php`; confidence scoring; unit test
against the shipped fixtures (positive/negative/confidence-threshold).

### Phase 3 — Smart-CSV + JSON/JSONL loaders (1.5 days)
Delimiter/encoding/header sniffing + JSON/JSONL→matrix; tighten the fixture
reader to reuse them; tests incl. non-UTF-8 and TSV fixtures.

### Phase 4 — XLSX/ODS adapter + Drive tab resolution (1 day)
PhpSpreadsheet path; `sheet` hint from `table_map`; structural match on tabs.

### Phase 5 — PDF/photo invoice extraction (1.5 days)
Wire the ingress path to the existing OCR stack (`extract_pdf_text` for
text-layer and scanned PDFs, `ocr_pdf_text` for image-only, `pro_document_ocr`
for layout-rich/multi-page with confidence, `analyze_image`/`extract_image_text`
for photographed invoices) → matrix; line-item extraction for tables 6.0/8.0;
confidence surfaced in `dry_run` preview.

### Phase 6 — Observation mode + terminology map (2 days)
Observation row → canonical binding for the five transactional tables; `raw_row`
preservation in the CPT post-meta; reconcile test.

### Phase 7 — `fnb_import_table` integration + diagnostics (1 day)
Wire the pipeline into `fnb_import_table`, add a `source`/`format` hint arg,
surface the three-way error taxonomy, and add the config-guardrail (Tier-1)
error when no source is configured at all.

### Phase 8 — Dedupe, retention & alerting banding (1 day)
Adopt the vitals `is_near_duplicate()` tolerance window, `run_retention_sweep()`
cron, and `assess_*`/`generate_alerts()` band flags surfaced in `dry_run` preview.

### Phase 9 — Oracle + docs (0.5 day)
Extend the fixture/oracle harness; update `README.md` + RELEASE-NOTES.

### Phase 10 — Ingest-review dashboard (2–3 days, IN SCOPE)
Port the Medical Vitals Dashboard pattern (§8.5) to an F&B "Ingest & Preview"
admin page: detected files, per-file table_id + confidence, matched/missing
columns, sample values, one-click remap, and imported-vs-skipped charts —
backed by the same `fnb_import_table` dry-run path (research-grounded UI in the
implementation plan).

---

## 6. Out of Scope (deferred)

- Bulk/long-document OCR queuing (`pro_batch_ocr`, `pro_unlimited_ocr`).
- Bidirectional sync back to Drive/Sheets.
- Cross-owner schema registry / multi-tenant mapping sharing.
- Generating invoices back out (`generate_invoice_pdf`/`generate_invoice_batch`).

---

## 7. Success Criteria

- A file named `SurfClub October.xlsx` with `daily sales` headers imports to
  table 6.0 in `dry_run` with confidence ≥ threshold and a `missing` report.
- A JSONL POS export and a TSV stock count both map to 8.0/9.0 by structure.
- `table_map` correctly overrides the structural matcher when both present.
- No import writes below the confidence threshold; raw rows are preserved.
- A scanned supplier invoice PDF resolves to table 8.0 via `pro_document_ocr`
  (or `ocr_pdf_text`) with line items surfaced in the `dry_run` preview.
- A photographed invoice (image) maps to table 8.0 via `analyze_image` /
  `extract_image_text` on a best-effort basis.
- All existing 062 fixtures still resolve with zero config (regression).

---

## 8. Transferable patterns from the Vitals Pro Toolkit

The Health Records **Vitals** tooling already solves, in production code, nearly
every sub-problem this proposal raises. Borrowing these patterns (rather than
reinventing them) is the fastest, most consistent route. The concrete sources
are:

- `WP_MCP_AI_JetEngine_Vitals_Log_CCT` (`addons/pro/includes/class-wp-mcp-ai-jetengine-vitals-log-cct.php`)
- `WP_MCP_AI_Tool_Import_Vitals` (`addons/pro/includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-import-vitals.php`)
- `WP_MCP_AI_Tool_Log_Vital_Signs` (same dir, alerts/trends/assess-* banding)
- `WP_MCP_AI_Medical_Vitals_Dashboard_Page` + `WP_MCP_AI_Health_Wellness_Dashboard_Page` (`addons/pro/includes/admin/`)

### 8.1 Multi-format ingestion is already solved — reuse the `format` switch

`import_vitals::execute()` dispatches on a `format` argument (`csv` | `json` |
`fhir_json`) to three parsers that all return the same `{ success, rows,
parse_errors }` envelope. This is the exact adapter contract this proposal's
§4.5 format table should adopt — the F&B tool just adds `xlsx`/`ods`/`pdf`/
`photo`/`sheets-tab` as additional `format` values over a shared normalised-row
result.

| Vitals pattern | F&B equivalent to adopt |
|---|---|
| `parse_csv` (header-driven `CSV_COLUMN_MAP`) | `CSV_COLUMN_MAP` → the F&B `canonical` header matcher (per-table `aliases`) |
| `parse_json_array` (keys = CCT field names) | JSON rows carry canonical F&B columns directly |
| `parse_fhir_json` + `map_fhir_observation` (LOINC code → field) | `table_id`/`measurement_type` → canonical table binding (the F&B "terminology map" already proposed in §4.4) |
| `normalise_date` (YYYY-MM-DD, M/D/YYYY, DD-Mon-YYYY, `strtotime`) | copy verbatim; F&B `date`/`delivery_date`/`count_date` need the same tolerance |
| **dry-run preview** (`dry_run` → `sample_rows`, `row_count`, `parse_errors`) | already the plan (§4.6); adopt the exact field names |
| result shape `imported` / `skipped` / `parse_errors` / `save_errors` | adopt; keeps import results auditable and testable |
| **non-fatal per-row parse errors** (skip bad lines, log, continue) | adopt — never fail the whole import on one malformed row |
| "require at least a date + one numeric" validity gate | per-table: require the table's idempotency key + one measure column |

### 8.2 Dual storage + graceful degradation (CCT ⟷ options fallback)

`import_vitals` writes to the JetEngine CCT when present and **also** mirrors to
a `wp_mcp_ai_vital_signs_{id}` option (capped at 500) so reads keep working when
JetEngine is absent; the dashboard's AJAX has an explicit options-only fallback
path. The F&B native-storage layer (§ Phase 3) should mirror this: **CPT when
`enable_fnb_native_storage` is on, Drive/CSV/fixture reader otherwise** — which
is already the intent of `Data_Source::get_table's` `$native` switch, but the
*ingestion result reporting* (and a clear degrade message) should follow the
vitals dual-write pattern.

### 8.3 Deduplication & retention

- `is_near_duplicate()` (multi-key numeric equality within a `DEDUP_WINDOW_MINUTES`
  window, empty-field-aware) → the F&B **idempotent upsert** already keys on spec
  idempotency columns; add a `near_duplicate` tolerance window for accidentally
  re-imported supplier deliveries/waste logs.
- `run_retention_sweep()` (daily cron, bounded-batch `DELETE ... LIMIT`) → apply
  to native F&B rows beyond a configurable retention window so the CPT storage
  stays bounded, exactly as the vitals CCT does.
- `build_row_format()` (per-field `%d`/`%f`/`%s` via `$wpdb`) → the *reason* the
  F&B import must not default every value to `%s` (MySQL rounds decimals); reuse
  the numeric/decimal field list discipline (`get_numeric_vital_fields` /
  `get_decimal_vital_fields`) per F&B table.

### 8.4 Alerting & threshold banding

`log_vital_signs` implements `assess_*()` per metric (returns within/above/below
band), `generate_alerts()`, and `calculate_trend()`. The F&B toolkit already
carries industry bands in Proposal 062's research (§3.1: food cost 28–35%,
beverage 18–24%, labour 25–35%, prime cost 55–65%): adopt the **assess/alert
banding pattern** so the import step flags out-of-band rows (e.g. a unit price
anomaly or a food-cost line outside the norm) in the `dry_run` preview, rather
than waiting for the manager brief. This is a clean extension of G-05 / A1-03.

### 8.5 The admin dashboard (the specific surface you asked about)

The **Medical Vitals Dashboard** is the template for an **F&B ingest-review
dashboard**:

- **Menu + capability**: `add_submenu_page()` under the parent F&B menu,
  capability `edit_posts` (broad but not `manage_options`) — the read/review
  surface is lower-privilege than the ingest *write* surface.
- **Chart/UI stack**: bundled `chart.min.js` + inline JS/CSS +
  `wp_localize_script` for `ajaxUrl`/`nonce`/`strings` — no build step,
  no framework.
- **Data via the same tools**: AJAX reads through `WP_MCP_AI_Tool_Log_Vital_Signs`
  (same `execute()`), not a parallel query path — so the dashboard shows exactly
  what the assistants see, preserving the cross-assistant figure-consistency rule
  (A1-04).

**F&B mapping**: an "Ingest & Preview" dashboard that (a) lists detected source
files, (b) shows per-file `table_id` + confidence + `matched`/`missing` columns,
(c) renders a chart of imported vs skipped vs errored rows, and (d) exposes a
"preview mapping" button hitting an AJAX endpoint backed by the same
`fnb_import_table` dry-run path. This turns the proposal's structural matcher
into a *visible, reviewable* surface instead of a silent import.

### 8.6 What NOT to copy

- Do **not** copy the health-specific `get_fields_schema()` CCT shape wholesale —
  F&B keeps its own 17-table + canonical-column contract (§4.3) as the
  terminology; the *pattern* (a single authoritative field list) is what to
  reuse, not the vitals columns.
- Do **not** gate the F&B dashboard at `manage_options` just because ingest is
  ACT-tier — the vitals model cleanly separates **review** (`edit_posts`) from
  **write** (`manage_options`), and F&B should too.

---

## 9. Open Questions

1. Confidence threshold default — 0.60 vs per-table `canonical_required` weights?
2. Should `table_map` live in `wp_mcp_ai_fnb_settings` (option) or a dedicated
   custom table for easier multi-owner management?
3. Terminology-map authoring surface — JSON editor vs guided admin UI?
4. Should the F&B ingest-review dashboard ship in this proposal or as a
   follow-on (it is substantial on its own)?
5. Retention window for native F&B rows — mirror the vitals daily sweep, or
   make it per-table / offline?
