---
type: Skill
name: mcp-ai-wpoos-toolkit-ingestion
description: "Open-format data ingestion for NV oOS Pro toolkits — the reusable pattern behind the F&B data-ingestion track (Proposal 078). Covers structural (header) matching over filename mapping, the configurable table_map + header-matcher + name-guess resolution order and the slug-first regression, the multi-format loader (CSV/TSV/JSON/JSONL/XLSX/ODS/PDF/photo), the Observation + terminology-map layer (vitals/FHIR analogue) with raw preservation, dedupe/retention/out-of-band banding, complete-by-default/resumable (offset) imports, and the read-only ingest-review dashboard anatomy (edit_posts review vs manage_options write, dry-run-first). Use when adding or extending data ingestion to a toolkit, ingesting heterogeneous owner files, wiring XLSX/PDF/photo imports, building an ingest/review dashboard, or debugging partial/truncated imports."
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.99"
  plugin-version-tested: "1.1.99"
  last-updated: "2026-10-19"
---

# NV oOS Toolkit Data Ingestion

Reusable playbook for ingesting heterogeneous owner data into a Pro toolkit as
structured rows, independent of filename or fixed column layout. First built for
the **food-beverage** toolkit's open-format track
(`docs/project/proposals/078-fnb-data-ingestion-open-format-proposal.md`),
Phases 1–10. Complements `mcp-ai-wpoos-toolkit-creation` (build a toolkit) and
`mcp-ai-wpoos-toolkit-universalisation` (de-demo / de-tenant a toolkit).

## When to use this skill

- "Ingest owner files in as many formats as possible" / "auto-map if no mapping
  is provided"
- Adding CSV/TSV/JSON/JSONL/XLSX/ODS/PDF/photo import to a toolkit
- "Replicate the vitals-log ingestion" / bind loose records onto a canonical
  table
- Building or extending an ingest & review dashboard

## 1. Resolve *what a file is* by structure, not filename

Owners rename files; headers are stable. Maintain a per-table **canonical
column** list (the toolkit's single source of truth) and match input headers
against it.

- **Configurable mapping first** (`table_map`): operator-authored
  `table_id → { sheet|file => name }`, stored in the toolkit's settings option.
  Highest trust.
- **Structural header matcher second**: `confidence = matched / required`,
  where `required` is the *idempotency + natural keys*, not every optional
  column (so sparse owner files still map). Report `matched`, `missing`,
  `required`.
- **Legacy name/slug guess last**: the old hard-coded filename map, demoted to
  a low-priority fallback so pre-existing fixtures still resolve with zero
  config.

### The slug-first regression (hard-won)

**Deterministic filename/slug resolution must always win ahead of structural
inference.** In the F&B build, running the header matcher *before* the slug
match let `ingredients.csv` (which also carries `Supplier ID, Supplier`
columns) shadow `suppliers.csv`, silently corrupting any read that depended on
supplier `lead_time`/`order_cutoff` (the PR cutoff gate). Always:

1. `table_map` hint,
2. deterministic slug/filename match,
3. structural header match — **last resort only**,
4. actionable error (no source / wrong shape / unrecognised columns).

**Every reader must implement the order — including the universal
single-folder reader.** The F&B universal reader shipped without step 1:
it scored headers only, so explicit operator hints could never override a
wrong header match (#7112). Pin it with a test that feeds a `table_map`
hint contradicting the structural score and asserts the hint wins.

### Serial-date handling (hard-won)

Exported Google Sheets carry **Excel serial dates** (`46023` = 2026-01-01).
A date normalizer that reads any numeric cell as a Unix epoch timestamp
collapses every row into month `1970-01` — silently ("Latest analytics
(1970-01)", empty trend charts, zero-value KPIs). Always:

1. detect serials **before** epoch interpretation (serial base 1899-12-30;
   values below ~60,000 are serials, not epoch seconds/millis),
2. derive reporting months from the **date column first**, with the month
   label as a fallback only for tables with no dated rows,
3. add a regression row with a serial date (e.g. `46023`) asserting the
   parsed month is the real one.

## 2. Multi-format loader → one matrix contract

Normalise every format to the shared `{ columns, rows }` matrix the rest of the
toolkit already consumes.

- **CSV/TSV**: sniff delimiter (`,` `\t` `;` `|`), strip UTF-8/UTF-16 BOM,
  convert to UTF-8, detect header row; skip fully-empty rows.
- **JSON / JSONL**: infer headers from object keys (union in first-seen order);
  JSONL parses line-by-line.
- **XLSX/XLS/ODS**: `\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile`
  (`setReadDataOnly`, `toArray`), then route through the same matrix
  normalisation. PhpSpreadsheet is already a Pro-addon dep — no new dependency.
- **PDF (text layer)**: `\Smalot\PdfParser\Parser` (also already a Pro dep,
  the `extract_pdf_text` fallback). Scanned/image-only PDFs (empty text layer)
  return a clear pointer to the OCR tools.
- **Photo / scanned images**: return an actionable "use `analyze_image` /
  `extract_image_text` / `extract_pdf_text` with `enable_ocr` /
  `ocr_pdf_text`" error — do not silently fail or fabricate text.

**Guard every dependency with `class_exists(...)` + a clean `WP_Error`
fallback** so a missing autoload degrades gracefully instead of fatals.

**Universal ingest**: expose a `scan_sources()` that walks the source folder(s)
and auto-detects each file's table by header structure (calling `best_table`),
returning `{file, table_id, confidence, format}` sorted by confidence. This is
schema-on-read: no operator mapping or explicit table choice needed; files that
don't parse or don't resolve above threshold are omitted, never guessed.

**Media Worker offload (Proposal 079)**: when a Media Worker sidecar is
configured, `load_file()` parses the heavy formats in the worker first
(`POST /api/ingest/parse` - ExcelJS for XLSX, pdf-parse for PDFs with a
render→OCR chain for scans, tesseract for photos), returning the same
`{columns, rows}` matrix contract; PDF/photo results arrive as extracted
text and convert through the same delimited path. Every worker failure falls
back to the local PHP parsers unchanged, so the cascade is invisible when the
sidecar is absent. Text formats (CSV/TSV/JSON/JSONL) are never offloaded.
Gates: the `use_media_worker` setting (`auto` default), the
`wp_mcp_ai_fnb_worker_enabled` filter, and the `wp_mcp_ai_fnb_worker_client`
filter (client-replacement test seam). An opt-in `ocr_vision_fallback`
setting adds a provider-vision rung for photos (temporary attachment +
`extract_image_text`, then deleted; failures keep the actionable pointer
with the vision error in `data`; tests inject via the
`wp_mcp_ai_fnb_vision_ocr_result` filter). Related Appendix B wiring:
`WP_MCP_AI_OCR_Service::extract_text_from_pdf()` routes auto/tesseract scans
through the worker ingest chain, and `optimise_images_batch` routes its
non-dry-run batch through `/api/image/batch`.

## 3. Observation + terminology map (the vitals/FHIR analogue)

For semi-structured, measurement-shaped owner data, accept a loose observation
row instead of a bespoke wide-column layout:

```json
{ "type": "waste", "item": "tomatoes", "qty": 2.5, "unit": "kg",
  "recorded_at": "2026-10-15", "source": "supplier_csv", "raw_value": "2.5 kg" }
```

- **Terminology map**: `type` (measurement_type) → `table_id`, normalised
  (lowercase, `_`/`-`→space). This is the F&B analogue of the Health Records
  vitals `LOINC_MAP`.
- **Alias binding**: map loose keys onto canonical columns (`qty` →
  `qty_sold`/`qty_delivered`, `recorded_at` → `date`/`delivery_date`/
  `count_date`, `item` ↔ `ingredient`, `party_size` → `guests`, etc.).
- **Always preserve the raw form**: store `raw_value`/`_raw_observation` beside
  the normalised columns (the vitals `raw_value`/`value` split) so a bad
  unit/price transform is reversible and auditable.
- Unknown/missing `type` → a per-row `WP_Error`, accumulating in `map_records`
  so one bad observation never sinks the batch.

## 4. Dedupe, retention and out-of-band banding

Port from the Health Records vitals CCT (`is_near_duplicate`,
`run_retention_sweep`, the `assess_*`/`generate_alerts` banding):

- **Dedupe** keyed on idempotency columns with a small numeric tolerance;
  empty-on-existing-but-present-incoming is *not* a duplicate (re-import still
  fills gaps).
- **Retention** as a daily bounded-batch cron, gated on native storage being on
  and a `retention_days` setting (0 = keep forever). For CPT-backed storage
  prune posts via `wp_delete_post`; for custom tables use bounded
  `DELETE ... LIMIT`.
- **Banding** — domain thresholds (e.g. negative qty → high, zero price with
  qty → medium) surfaced in the import **dry-run preview**, never enforced as
  write blockers.

## 5. Import integration + diagnostics

- Route every value through the canonical column list; never trust model math.
- Keep **dry-run first**; the write step is ACT-tier (`manage_options`) and
  default-off.
- Return a `mapping` confidence report + `flags` in the dry-run result, and a
  **three-way source error**: no source configured / source found but table not
  in it / source found but columns unrecognised — never a bare "file not found".

### Complete-by-default, resumable imports

A row cap in the import bridge silently turns into missing data downstream. In
the F&B build `fnb_import_table` defaulted to `limit = 500` (hard cap 5,000)
while the Drive workbook held 15,405 daily-sales rows: the import returned
`success: true`, the native CPT held only January, and every September metric
returned 0. The source was intact; the copy was truncated.

- **Never make a row cap the default.** `limit = 0` (or omitted) must mean
  "every remaining row". Apply the same ceiling the reader uses (F&B:
  `WP_MCP_AI_Fnb_Cpt_Reader::MAX_ROWS = 25000`) so an import can never write
  more rows than a subsequent read can see.
- **Expose resumability.** Accept `offset`; return `source_total`, `offset`,
  `next_offset`, `has_more`, `complete`, and (after a write) `native_count`.
  A partial import must be visible in the response, never implicit.
- **Loop batches in the admin surface.** The F&B Ingest & Preview dashboard
  sends 500 rows per AJAX request and recurses on `next_offset` until
  `complete`, showing `x of y source rows processed`. A single all-rows request
  can exceed PHP/AJAX time limits on a 15k-row workbook.
- **Invalidate the read cache after a write.** The table reader caches for
  minutes (F&B: `fnb_table_<md5(table_id[:cpt])>` transients). Delete both the
  native (`:cpt`) and source cache keys after a successful import, otherwise a
  verification read sees the stale partial table.
- **Verify the tail, not just the head.** After import assert
  `native_count === source_total` and query a row/period from the newest month;
  an aggregate-only check can pass on a truncated table.

## 6. Read-only ingest-review dashboard

Mirror the Medical Vitals dashboard anatomy for the review surface:

- `add_submenu_page()` under the toolkit parent at **`edit_posts`** (review),
  while the import write stays **`manage_options`** — keep the split.
- Bundled `chart.min.js` + inline JS/CSS + `wp_localize_script` for
  `ajaxUrl`/`nonce`/`strings`; no build step, no framework.
- AJAX endpoint calls the **same import tool's dry-run path** (not a parallel
  query) so the dashboard shows exactly what the assistant sees (preserves the
  cross-assistant figure rule).
- Research-grounded mapping-preview UX: auto-map high-confidence, highlight
  low/unmatched columns, show sample values, one-click remap, imported-vs-
  skipped-vs-errored counts, and never label a file "corrupt" without a fix
  path.
- Add a **source-folder scan** control ("Scan source folder") wired to
  `scan_sources()` so universal ingest works without a pre-selected table. It
  lists detected files + table + confidence for one-click review.
- **Enqueue hook gotcha (silent "button does nothing" bug):** a submenu under a
  *top-level* menu page gets a sanitization-dependent hook whose exact string
  (`"{parent}_page_{slug}"` with the parent title slugified) is fragile. Match
  the page by **substring** — `if ( false === strpos( $hook, self::PAGE_SLUG ) )
  { return; }` — the same pattern `NV_oOS_Graphify_Settings` / WebLLM use. An
  exact `===` here silently skips enqueueing and the page's JS never runs.
- Wrap inline JS in `jQuery(document).ready` (or a `DOMContentLoaded`/readyState
  fallback) and guard `typeof Chart !== "undefined"` + the button's existence so
  a missing Chart.js or an early execution never leaves the UI inert.

## Also load

- `mcp-ai-wpoos-toolkit-creation` — build/register the toolkit (§7a has the
  ingestion pitfalls summary)
- `mcp-ai-wpoos-toolkit-audit` — the four failure classes to scan before a PR
- `mcp-ai-wpoos-test-suite` — Docker cross-worktree test environment + patterns
- `wp-plugin-architecture` — one-class-per-file, composition-root discipline
- Proposal: `docs/project/proposals/078-fnb-data-ingestion-open-format-proposal.md`
