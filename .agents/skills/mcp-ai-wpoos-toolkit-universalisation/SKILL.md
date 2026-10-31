---
type: Skill
name: mcp-ai-wpoos-toolkit-universalisation
description: "Rework an existing NV oOS Pro toolkit from a single-tenant demo into a universal product — currency-neutral schema with legacy aliases, a Tenant Profile + token renderer shared across the two assistant-delivery surfaces (packs via Portability vs blueprints via Blueprint Installer), demo-mode inversion, a universal single-folder ingestor (header-alias dictionary + dry_run + JSON), report export to CSV/PDF (DomPDF vs html_to_pdf choice), the add-a-tool drift checklist (TOOLS_LIST, coverage manifest, admin tool list, presets), and the re-seed-on-settings-save gotcha. Use when asked to 'universalise', 'de-demo', 'multi-tenant', 're-tenant', 'remove the legacy venue/currency hardcoding', 'point the toolkit at a single Drive folder', 'export reports as CSV/PDF', or 'avoid re-installing assistants on save'. First case study: food-beverage (F&B Management, 2026-10-16). Complements mcp-ai-wpoos-toolkit-creation (build new) and mcp-ai-wpoos-toolkit-audit (harden existing)."
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.99"
  plugin-version-tested: "1.1.99"
  last-updated: "2026-10-19"
---

# NV oOS Pro Toolkit Universalisation Loop

Operational playbook for turning a single-tenant demo toolkit into a universal
product that any operator can point at their own data. First full run:
**food-beverage** (2026-10-16 — currency/venue de-coupling, Tenant Profile,
universal ingestor, CSV/PDF export, assistant-pack + blueprint re-tenanting;
see [Case study](#case-study-food-beverage)). Distilled from that run; reuse the
same checklist on the next vertical.

> This skill sits between `mcp-ai-wpoos-toolkit-creation` (build a new toolkit)
> and `mcp-ai-wpoos-toolkit-audit` (harden an existing one): it is the
> **rework/universalise** member of the trio.

## When to use this skill

- "Universalise / de-demo / multi-tenant this toolkit"
- "Remove the hard-coded `<venue>` / `<currency>` (e.g. LKR) from the toolkit"
- "Point the toolkit at a single Drive folder of unorganised files"
- "Export the reports as CSV and PDF"
- "Make the toolkit generic instead of one-off demo data"
- A plan/proposal (`docs/project/proposals/0NN-*.md`) is being implemented that
  is a rework, not a greenfield build.

## 1. Diagnose the tenant coupling first

Before changing anything, map every place a single venue/currency/schema is
assumed. Grep the toolkit folder + the assistant surfaces for:

- venue name + locale ("The Venue", "Example Town", "Example Country")
- currency strings ("LKR", and column keys `*_lkr`)
- character/brand nouns from the demo ("<character>", "<name>", "<noun>")
- demo fallback defaults (`demo_mode => true`, a `DEMO_*` constant used as a
  silent fallback in a `workbook_id()`-style accessor)

There are **four coupling axes**, in order of blast radius:

1. **Currency in column names** (structural — worst). Renaming `price_lkr` →
   `price` ripples into the metric engine, report builder, tools, and native-CPT
   meta fields.
2. **Venue in assistant prompts** (most visible). It lives in the canonical
   `presets/assistant-packs/<slug>/*.json` bundles AND their blueprint mirrors.
3. **Fixed schema / numbered tables**. `1.0…17.0` IDs + a hard-coded sheet map.
4. **Demo default**. A fresh install silently reads demo data.

Separate the **two assistant-delivery surfaces** early — they have different
schemas and importers, and the blueprint path has **no rendering layer** of its
own:

| Surface | Format | Importer |
|---|---|---|
| Canonical packs | `nvoos-assistant` v1 bundle | `WP_MCP_AI_Assistant_Portability` (seeder) |
| Blueprints | CRM-style `{name, description, meta}` | `WP_MCP_AI_Blueprint_Installer` (`import_*_blueprint` tool) |

## 2. Currency de-coupling (do this first)

- Add neutral canonical keys and keep the legacy `*_lkr` ones as **aliases**,
  not deletions. The toolkit already has a `resolve_column()` + per-table
  `aliases` map for exactly this — it is usually under-used (mostly identity
  mappings). Adding `'price' => array('price', 'price_lkr')` lets legacy
  fixtures AND new neutral headers both resolve with **zero migration**.
- The neutral rename touches: `data-source.php` table registry, every
  `cell(…, '*_lkr')` read in the metric engine + report builder + tools, and the
  **native-CPT JetEngine meta field names** (`_fnb_price_lkr` → `_fnb_price` in
  `class-wp-mcp-ai-pro-cpt-meta-schema.php`). If the trait writes meta from the
  canonical key dynamically (`'_fnb_' . $canonical`), the rename cascades there
  automatically — but the **schema file is hardcoded separately** and its test
  probes must be updated too.
- Replace hard-coded "LKR" output text with `format_money()` / `money_suffix()`
  from the Tenant Profile; make rounding precision currency-driven (LKR/JPY = 0
  dp, USD/EUR/GBP = 2 dp).
- **Verify with the oracle** (the `smoke-run.php` / `test-*-metrics-oracle.php`
  that reproduces the demo "Check totals" values). The metric values must be
  byte-identical after the rename — this is the single most important regression
  check.

## 3. Tenant Profile + shared token renderer

- New `class-wp-mcp-ai-<slug>-tenant-profile.php`: `currency_code()`,
  `currency_symbol()`, `currency_decimals()`, `format_money()`, `money_suffix()`,
  `venue_name()`, `venue_type()`, `owner_role()`, and `render($text)`.
- `render()` uses `strtr()` over `{{venue_name}}`, `{{currency}}`,
  `{{currency_symbol}}`, `{{venue_type}}`, `{{owner_role}}` and falls back to
  neutrals ("the venue", "" for currency) when unset — **never assert a wrong
  currency on a fresh install**.
- The **same renderer** must be wired into **both** delivery surfaces:
  - seeder: `WP_MCP_AI_<Slug>_Assistant_Seeder::seed()` — render over `title`,
    `content`, `excerpt`, and `_wp_mcp_ai_system_prompt` after `parse_import()`
    and before `import_bundle()`.
  - blueprints: `WP_MCP_AI_Tool_Import_<Slug>_Blueprint::execute()` — render over
    `name`, `description`, and `meta.instructions` after `load_blueprint()` and
    before `install()`.

## 4. Demo-mode inversion

- Flip `demo_mode` default to `false`.
- Remove the silent `DEMO_*` fallback in the workbook/id accessor (return `''`
  when unset). Keep the constant only behind an explicit "Load demo data"
  action, never a silent default.
- De-venue the settings-page copy ("demo workbook", the venue name in field
  descriptions) and add a **Tenant Profile** settings section that feeds BOTH
  surfaces via §3.

## 5. Universal single-folder ingestor

When the operator "dumps all files (unorganised) into one folder", add a third
reader (`class-wp-mcp-ai-<slug>-universal-reader.php`) implementing the existing
`WP_MCP_AI_<Slug>_Table_Reader` interface, gated by an `ingest_mode` =
`folder|workbook|split` setting (default `folder`, with an empty-folder fallback
to the existing workbook/split path so existing installs don't regress):

1. Scan `data_folder_id` (flat by default).
2. Classify files by MIME (Sheets / XLSX / ODS / CSV / TSV / JSON).
3. Score each header against the canonical ontology via a **rich header-alias
   dictionary** (see §6) rather than narrow aliases + numeric scoring alone.
4. Assign each file a table role; **concatenate** same-role files (not
   last-writer-wins), adding a provenance column.
5. Emit a review manifest (file → role → confidence) with `dry_run` preview +
   `sample_rows`; persist it only when not dry-running.

**The universal reader must honor the operator's `table_map` FIRST** — the
F&B build shipped without it: the reader scored headers only, so explicit
`table_id → { sheet|file => name }` hints (documented highest trust) could
never override a wrong structural match (#7112). Consult the configured
`table_map` before scoring, and pin it with a test where the hint
contradicts the header score.

**Serial dates:** exported Sheets carry Excel serials (`46023` =
2026-01-01); a date parser that treats numeric cells as Unix epoch collapses
every row into `1970-01` (phantom "Latest analytics (1970-01)", no trend
charts). Detect serials before epoch interpretation (base 1899-12-30) and
derive reporting months from the date column first, month label only as a
fallback for the covers/utilities tables.

## 6. Lessons from Healthcare Vitals (generalised)

The `healthcare/vitals` import tool is the in-repo reference for these three
patterns worth porting to any data-heavy toolkit:

- **Rich header-alias dictionary** — a flat `HEADER_ALIASES` const (header
  string → canonical key) covering vendor variants, abbreviations, and
  unit-suffixed spellings (`'inv no' => 'invoice'`, `'quantity sold' =>
  'qty_sold'`, `'sales' => 'revenue'`). Expose it via `resolve_header_alias()`
  and feed both `resolve_column()` and the ingestor's scoring — then **verify
  the metric engine actually calls it**. The F&B run shipped the dictionary
  but `resolve_column()` only did exact canonical/alias matching, so
  `Cost per portion at as-of date (LKR)` still resolved to nothing and M-21
  returned recipe cost 0. Add a unit test that asserts one drifted header
  resolves. (Model on vitals `CSV_COLUMN_MAP` / `LOINC_MAP`.)
- **`dry_run` + `sample_rows` + JSON import** — preview before commit; accept
  `format = csv|json` and parse JSON arrays-of-records.
- **Complete-by-default import with offset batching** — never ship a fixed
  default row cap. The F&B bridge defaulted to `limit = 500` (hard cap 5,000)
  against a 15,405-row workbook, so only January was written while the import
  returned `success: true`; every later-month metric then read 0. Omit `limit`
  → all rows (capped by the reader's `MAX_ROWS`), accept `offset`, return
  `source_total` / `next_offset` / `has_more` / `complete` / `native_count`,
  have the admin AJAX loop 500-row batches, and delete the table-read
  transients after a successful write.
- **Benchmark/threshold registry** — a `WP_MCP_AI_<Slug>_Benchmarks` class with
  a filterable `ranges()` table + `flag_threshold()` → `low|high|in_range|
  unknown`, modeled on `WP_MCP_AI_Healthcare_Engine::reference_ranges()` /
  `flag_value()`. Use it instead of scattered hard-coded % targets.

Skip the *domain* specificity (FHIR/LOINC/USCDI for health, etc.) — port the
**pattern**, not the codes.

## 7. Report export to CSV / PDF

- Keep the report engine deterministic (figures only via the metric engine).
- **CSV**: flatten `Report_Builder::build()` sections into `section/key/value`
  rows via `fputcsv()` into the drafts/CSV directory. Simple, no deps.
- **PDF**: prefer **direct DomPDF** (`\Dompdf\Dompdf`) — no shell, no
  `manage_options`, so a `edit_posts` assistant can export. Fall back to
  `html_to_pdf` (shell+admin gated) only when DomPDF is absent.
  - **Do NOT** use the `generate_pdf` tool for deterministic reports — its
    "generate" path calls an AI model (`call_ai_model`) and consumes tokens;
    `html_to_pdf` (or DomPDF directly) is the deterministic one.
  - The document-generated return shapes differ (`file/url/attachment_id` vs
    `attachment_id/url/filename` vs a chat-response `error` array) — normalise
    onto a stable envelope (`success`, `report_id`, `mode`, + a location key).
- **Report numbering reconciliation**: clients reuse `R-01/02/03` with their
  own meanings. Add a `CLIENT_ALIASES` map (`client-r01` → canonical `R-*`) and
  keep the canonical registry unchanged; add missing builders as new `R-XX`.

## 8. The add-a-tool drift checklist

Adding a tool leaves **five** enumerations stale. Update all of them (the F&B
run caught four of five):

1. **`TOOLS_LIST.txt`** — one slug per line.
2. **Coverage manifest** — `bin/generate-tool-coverage-manifest.sh` (regenerate,
   never hand-edit; it emits `tests/tools/.coverage-manifest.txt` + the Pro one).
3. **Admin toolkit details** — `class-wp-mcp-ai-pro-settings.php` →
   `get_toolkit_details()` tool list AND the `tools_count` integer.
4. **Toolkit memory/hint comment** — `class-wp-mcp-ai-section-tools.php` (the
   `// N tools` comment is cosmetic but referenced by reviewers).
5. **Schedule + workflow presets** — add a preset for the new capability
   (`fnb_export_report`), and sweep the existing presets for leftover venue
   strings ("ranked by LKR impact", "character bible") that earlier passes left.

The pack/blueprint allowlist validator (`validate-assistant-packs.php`) does
**not** auto-register new tools — update its `$expected_tools` matrix only if
you actually grant the tool to a pack.

## 9. The re-seed-on-settings-save gotcha

Symptom: saving the toolkit settings appears to re-install the packaged
assistants. Root causes to eliminate:

- The seeder must be reachable **only** via (a) an explicit "Seed" button (its
  own nonce-protected `admin_post_*` form) and (b) WP-CLI — **never** from the
  settings `sanitize_settings()` / `update_option` / `save_post` path.
- The "Seed"/"Export" action forms are often rendered **inside**
  `render_status_card()`, which runs **before** the main `<form action=
  "options.php">` opens. A `<button type="submit">` there becomes the **first**
  submit target in DOM order, so pressing <kbd>Enter</kbd> in a settings text
  field submits the *seed* form, not the settings form.
- Fix: render those buttons as `<button type="button" onclick="if(confirm(...))
  { this.closest('form').submit(); }">` — `type="button"` removes them as
  Enter-key submit targets; the `confirm()` adds a guard for the idempotent-but-
  irreversible re-seed. Keep `handle_*_submission` `manage_options` + nonce
  gated.

## 10. Validation

- `php -l` every changed file.
- Pack validator (`validate-assistant-packs.php`) — venue-neutral, tokens
  present, allowlists match.
- **Oracle** (`smoke-run.php` / metrics-oracle test) — numbers byte-identical
  after the currency rename (the critical regression gate).
- **Known-row data-resolution checks** — assert one hard-coded item total from
  the source sheet (F&B: F-35 recipe cost 1,762 / margin 3,938) and one
  aggregate from a secondary table (F&B: 2026-09 covers 2,441 from Utilities
  `Total covers`). This catches precomputed-vs-derived column drift and
  missing secondary-table fallbacks that an aggregate-only oracle misses.
- **Full-period import check** — after importing the largest transactional
  sheet, assert `native_count === source_total` and query a row from the newest
  month (F&B: September). A partial import passes any January/aggregate-only
  check and silently zeros every later period.
- Full `<toolkit>` PHPUnit cluster in Docker (see `mcp-ai-wpoos-test-suite` →
  cross-worktree runner; the `oos-wp` container often mounts a *different*
  worktree than the one you are editing).

## Case study: food-beverage

- **Input**: a single-venue / legacy-currency demo (17 tables `1.0…17.0` + 21 metrics
  + 13 reports + 4 A1–A4 assistants), hard-coded venue + currency in column names
  and prompts, `demo_mode => true`, silent `DEMO_WORKBOOK_ID` fallback.
- **Output**: currency-neutral canonical schema (legacy `*_lkr` aliases),
  `WP_MCP_AI_Fnb_Tenant_Profile` token renderer wired into both the pack seeder
  and `import_fnb_blueprint`, `demo_mode => false`, a `WP_MCP_AI_Fnb_
  Universal_Reader` (folder ingest + `HEADER_ALIASES` + `dry_run` + JSON), a
  `fnb_export_report` tool (CSV + DomPDF-first PDF) with client `R-01/02/03`
  aliases, and a `WP_MCP_AI_Fnb_Benchmarks` threshold registry.
- **Result**: 56 tests / 607 assertions green; 20/20 oracle; pack validator
  green; full cluster validated on the Docker cross-worktree runner.
- **Post-universalisation data-resolution fixes** (2026-10-17): M-21 returned
  recipe cost 0 because the Recipes header drift never resolved and the Menu
  precomputed `Recipe cost` was ignored; per-cover metrics returned 0 because
  covers lived on Utilities `Total covers`, not only Daily covers. Fixed with
  Menu → Recipes → supplier-price resolution, the header-dictionary pass, and
  a date-deduplicated 13.0 fallback; added `test-fnb-data-resolution.php`
  (8 tests). Cluster is now 99 tests / 725 assertions.
- **Full-import truncation fix** (2026-10-17): `fnb_import_table` defaulted to
  500 rows (hard cap 5,000) against a 15,405-row Daily sales source, so the
  native CPT held only January and every September metric read 0. Import is
  now complete-by-default with `offset` batching; the Ingest & Preview
  dashboard loops 500-row batches with progress; writes invalidate the table
  transients. Regression tests in `test-fnb-native-storage.php`.
- Docs: `docs/project/proposals/078-fnb-toolkit-universalisation-proposal.md`
  + `-implementation-plan.md`.
