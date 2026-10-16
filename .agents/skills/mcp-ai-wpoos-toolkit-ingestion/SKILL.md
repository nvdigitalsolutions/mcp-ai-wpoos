---
type: Skill
name: mcp-ai-wpoos-toolkit-ingestion
description: "Open-format data ingestion for NV oOS Pro toolkits — the reusable pattern behind the F&B data-ingestion track (Proposal 078). Covers structural (header) matching over filename mapping, the configurable table_map + header-matcher + name-guess resolution order and the slug-first regression, the multi-format loader (CSV/TSV/JSON/JSONL/XLSX/ODS/PDF/photo), the Observation + terminology-map layer (vitals/FHIR analogue) with raw preservation, dedupe/retention/out-of-band banding, and the read-only ingest-review dashboard anatomy (edit_posts review vs manage_options write, dry-run-first). Use when adding or extending data ingestion to a toolkit, ingesting heterogeneous owner files, wiring XLSX/PDF/photo imports, or building an ingest/review dashboard."
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.99"
  plugin-version-tested: "1.1.99"
  last-updated: "2026-10-17"
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
  skipped-vs-errored counts, and never label a file "corrupt" without a fix path.

## Also load

- `mcp-ai-wpoos-toolkit-creation` — build/register the toolkit (§7a has the
  ingestion pitfalls summary)
- `mcp-ai-wpoos-toolkit-audit` — the four failure classes to scan before a PR
- `mcp-ai-wpoos-test-suite` — Docker cross-worktree test environment + patterns
- `wp-plugin-architecture` — one-class-per-file, composition-root discipline
- Proposal: `docs/project/proposals/078-fnb-data-ingestion-open-format-proposal.md`
