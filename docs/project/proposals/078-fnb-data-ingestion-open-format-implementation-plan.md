# Implementation Plan: F&B Data Ingestion — Open Format, Flexible Mapping & Review Dashboard

**Proposal:** [078-fnb-data-ingestion-open-format-proposal.md](078-fnb-data-ingestion-open-format-proposal.md)
**Status:** IN PROGRESS
**Date:** 2026-10-16
**Owner:** NV oOS (F&B Pro toolkit — `addons/pro/includes/tools/food-beverage/`)

---

## Scope & Non-Negotiables

Implement the ingestion pipeline and review dashboard described in the proposal,
reusing the Vitals-toolkit patterns wherever they apply (§8). Everything must:

- Obey the **canonical-envelope** and **two-gate sanitisation** rules (custom sniffs).
- Preserve the `_fnb_<canonical_key>` post-meta storage contract (never change how
  the CPT reader reads rows back).
- Keep **dry-run first**, ACT-tier writes `manage_options` + default-off.
- **Never silently bind** a low-confidence match into native storage.

**Files in play**

| File | Role |
|---|---|
| `class-wp-mcp-ai-fnb-settings.php` | add `table_map` + `infer` defaults |
| `class-wp-mcp-ai-fnb-structure-matcher.php` | **new** header-based table matcher |
| `class-wp-mcp-ai-fnb-smart-csv.php` | **new** delimiter/encoding/header sniffing |
| `class-wp-mcp-ai-fnb-google-sheets-reader.php` | consult `table_map` + matcher |
| `class-wp-mcp-ai-fnb-fixture-reader.php` | consult `table_map` + matcher |
| `class-wp-mcp-ai-tool-fnb-import-table.php` | wire pipeline, `format`/`source` args, diagnostics |
| `class-wp-mcp-ai-fnb-ingest-dashboard-page.php` | **new** review dashboard |
| `class-wp-mcp-ai-fnb-settings-page.php` | add `table_map` JSON field |
| `tests/pro/tools/food-beverage/test-fnb-structure-matcher.php` | **new** |

---

## Phase 0 — Pre-flight verification (0.5 day)

### Task 0.1 — Confirm current behavior + contracts
- Re-run `fnb_import_table` dry-run to re-confirm the mapping error path.
- Confirm `WP_MCP_AI_Fnb_Native_Cpt_Storage::upsert_row` + `_fnb_` meta prefix.
- Confirm `WP_MCP_AI_Fnb_Settings::$defaults`, `get()`, option key.

### Task 0.2 — Baseline tests
```bash
vendor/bin/phpunit tests/pro/tools/food-beverage/
vendor/bin/phpcs --standard=phpcs.xml.dist addons/pro/includes/tools/food-beverage/
```

---

## Phase 1 — Configurable mapping surface (0.5 day)

### Task 1.1 — Add `table_map` + `infer` defaults
In `WP_MCP_AI_Fnb_Settings::$defaults`:
```php
'table_map'    => array(), // table_id => { sheet|file => name } (nullable values allowed)
'infer_tables' => true,    // enable structural auto-map fallback (default on)
```

### Task 1.2 — Accessor + filter
```php
public static function table_map() {
    $map = self::get( 'table_map', array() );
    return is_array( $map ) ? $map : array();
}
public static function infer_tables() {
    return (bool) self::get( 'infer_tables', true );
}
```

### Task 1.3 — Admin field (JSON textarea) on the settings page
Add a `table_map` textarea (JSON) to `class-wp-mcp-ai-fnb-settings-page.php`,
validated with `json_decode` on save.

---

## Phase 2 — Structure matcher service (1 day)

### Task 2.1 — `WP_MCP_AI_Fnb_Structure_Matcher` (new class)
```php
public static function match_table( $table_id, array $columns ): array
// => array{ table_id, confidence, matched[], missing[], required[] }
```
- Normalise headers via `WP_MCP_AI_Fnb_Data_Source::normalise_header()`.
- Match `canonical` keys via `Data_Source::resolve_column()`.
- `confidence = matched / required`, where `required` is a **per-table subset**
  (idempotency + natural keys, not every optional column).
- Static per-table `required` definition map, overridable via filter
  `wp_mcp_ai_fnb_required_columns`.

### Task 2.2 — `best_table( array $columns, $threshold )` 
Return the highest-confidence table at/above threshold, else `null`.

### Task 2.3 — Unit tests
`tests/pro/tools/food-beverage/test-fnb-structure-matcher.php`:
positive match, negative match, partial (below threshold), alias drift.

---

## Phase 3 — Smart-CSV + JSON/JSONL loaders (1.5 days)

### Task 3.1 — `WP_MCP_AI_Fnb_Smart_Csv`
- Detect delimiter (`,` `;` `\t` `|`) by header-row analysis.
- Detect encoding (UTF-8 BOM, UTF-16LE/BE BOM); convert to UTF-8.
- Detect header row (first non-empty row).
- Output the shared `{columns, rows}` matrix.

### Task 3.2 — JSON / JSONL → matrix
Infer headers from object keys; JSONL reads line-by-line.

### Task 3.3 — Fixture reader reuse + tests
- Re-point the fixture reader's parse path through the Smart-CSV.
- Add TSV + non-UTF-8 + JSONL fixtures and tests.

---

## Phase 4 — XLSX/ODS adapter + Drive tab resolution (1 day)

### Task 4.1 — XLSX/ODS via PhpSpreadsheet
First sheet by default; `sheet` hint from `table_map`.

### Task 4.2 — Drive tab resolution via `table_map` then structural match
In `google-sheets-reader`: consult `table_map[table_id]['sheet']` first,
then structural-match the tab's headers, then the legacy name guess.

---

## Phase 5 — PDF/photo invoice extraction (1.5 days)

### Task 5.1 — `format` values `pdf` / `photo` in the adapter
Map to existing tools: `extract_pdf_text` (text+bw), `ocr_pdf_text`
(image-only), `pro_document_ocr` (layout-rich/confidence), `analyze_image` /
`extract_image_text` (photographed invoices). Output → matrix.

### Task 5.2 — Line-item extraction for 6.0/8.0; confidence in preview.

---

## Phase 6 — Observation mode + terminology map (2 days)

### Task 6.1 — Observation row shape
```json
{ "type": "waste", "item": "tomatoes", "qty": 2.5, "unit": "kg",
  "recorded_at": "2026-10-15", "source": "supplier_csv", "raw_value": "2.5 kg" }
```

### Task 6.2 — Terminology map: `measurement_type`/`table_id` → canonical binding.
### Task 6.3 — Persist `_fnb_raw_row` post-meta beside canonical columns
(so the CPT reader's `_fnb_` contract stays intact; raw is additive).

---

## Phase 7 — `fnb_import_table` integration + diagnostics (1 day)

### Task 7.1 — `format` + `source` args (optional), `table_map` override.
### Task 7.2 — Wire pipeline: loader → smart-csv → matcher → dry-run/write.
### Task 7.3 — Three-way error taxonomy: no-source / wrong-shape / unrecognised.

---

## Phase 8 — Dedupe, retention & alerting banding (1 day)

### Task 8.1 — Near-duplicate tolerance (port vitals `is_near_duplicate`).
### Task 8.2 — Retention sweep cron (port vitals `run_retention_sweep`).
### Task 8.3 — Out-of-band flag in dry-run (food cost %, unit-price anomaly) —
    port the `assess_*` / `generate_alerts` banding pattern.

---

## Phase 9 — Oracle + docs (0.5 day)

### Task 9.1 — Extend fixture/oracle harness to assert ingestion + mapping.
### Task 9.2 — Update `README.md` + `RELEASE-NOTES.md`.

---

## Phase 10 — Ingest-review dashboard (2–3 days)

### Task 10.1 — `WP_MCP_AI_Fnb_Ingest_Dashboard_Page` (new class)
Port the Medical Vitals Dashboard anatomy (§8.5):
- `add_submenu_page()` under the F&B admin menu; capability `edit_posts`.
- Bundled `chart.min.js` + inline JS/CSS + `wp_localize_script` (ajaxUrl/nonce/strings).
- AJAX endpoint backed by the **same** `fnb_import_table` dry-run path.

### Task 10.2 — Mapping-preview UX (research-grounded, §3.4)
Implement the recommended review affordances:
- Auto-map high-confidence matches; **highlight low-confidence/unmatched** columns.
- Show **sample values** from the source column beside each proposed mapping.
- **One-click remap** (re-bind a column) without editing settings JSON.
- Show **imported vs skipped vs errored** row counts (Chart.js).

### Task 10.3 — Non-silent failure handling
Never label a file "corrupt/invalid" without a path to fix: every rejection must
render the *reason* + the *next actionable step* (missing columns, encoding, etc.).

---

## Verification Commands (after each phase)

```bash
# Syntax
php -l addons/pro/includes/tools/food-beverage/class-wp-mcp-ai-fnb-structure-matcher.php
php -l addons/pro/includes/tools/food-beverage/class-wp-mcp-ai-fnb-smart-csv.php
php -l addons/pro/includes/tools/food-beverage/class-wp-mcp-ai-fnb-ingest-dashboard-page.php

# Unit tests (new)
vendor/bin/phpunit tests/pro/tools/food-beverage/test-fnb-structure-matcher.php

# Full F&B suite (regression)
vendor/bin/phpunit tests/pro/tools/food-beverage/

# Style
vendor/bin/phpcs --standard=phpcs.xml.dist addons/pro/includes/tools/food-beverage/

# Oracle / smoke
php addons/pro/includes/tools/food-beverage/tests/fixtures/smoke-run.php
```

---

## Risk Register

| Risk | Mitigation |
|---|---|
| Breaking existing reader/CPT contract | preserve `_fnb_` meta + `columns=canonical` invariant; regression suite |
| Silent low-confidence bind | matcher threshold + dry-run-only by default; never auto-write below threshold |
| Over-scope (dashboard) | dashboard isolated in its own class, reads via existing tool |
| Encoding/delimiter false positives | sniff conservatively; surface "unresolved" rather than guess |

---

## Research: mapping-preview & observability standards (added for the dashboard)

- **Auto-map high, highlight low.** "A well-designed mapping UI auto-maps
  high-confidence matches, highlights unmatched or low-confidence mappings in a
  distinct visual state, shows sample values from the source column, and makes it
  easy to change a mapping with a single interaction." (Xlork; AppMaster)
- **Never dismiss as 'corrupt' without a fix path.** "Many interfaces dismiss the
  file with generic jargon … We can prepare users by setting expectations …
  map column headers but then allow users to fix errors." (Smart Interface
  Design Patterns, bulk-import UX)
- **Review must be real, not cosmetic.** "Staged data storage, persisted import
  sessions, batched validation, resumable progress, row-level updates, and
  reliable preview queries are all part of the UX. Without that, the UI can only
  fake confidence." (mfyz — scalable importers)
- **Normalise at ingest, validate in CI.** "Normalize field names at ingest and
  validate in CI/CD pipelines to ensure consistent dashboards." (OpenObserve)
- **Proactive pipeline testing.** "Embed data-quality tests as early and often as
  makes sense" and "surface issues automatically before they impact the business."
  (SYNQ; Pantomath)

---

## Out of Scope

- Bulk/long-document OCR queuing (`pro_batch_ocr`, `pro_unlimited_ocr`).
- Bidirectional sync back to Drive/Sheets.
- Cross-owner schema registry / multi-tenant mapping sharing.
- Generating invoices back out (`generate_invoice_pdf`/`generate_invoice_batch`).
