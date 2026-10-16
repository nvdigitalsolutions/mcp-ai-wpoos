# Implementation Plan: Food & Beverage Toolkit Universalisation

**Date:** 2026-10-16
**Status:** COMPLETE — P1–P9 done (currency de-coupling, tenant renderer, packs + blueprint neutralisation, demo inversion + settings, universal ingestor, USAR four-quadrant surfacing, new tests, CSV/PDF report export). PHPUnit could not be run locally (missing deps in this Windows env) — validated via `php -l` + the standalone pack validator + direct logic review.
**Parent proposal:** `078-fnb-toolkit-universalisation-proposal.md`
**Owner:** NV Digital Solutions

---

## 0. Summary

Execute the four assumption-removal workstreams from the proposal in a
strictly-ordered sequence so every intermediate commit keeps the PHPUnit suite and
the two packaged-validator scripts green. The ordering rule is: **schema and
renderer first, prompts second, defaults/UI third, ingestor last** — because money
labels depend on the neutral schema, and both prompt surfaces depend on a single
renderer to avoid drift.

All file paths are relative to the repo root. The toolkit lives at
`addons/pro/includes/tools/food-beverage/`; the two assistant surfaces at
`addons/pro/presets/assistant-packs/fnb/` (packs) and
`addons/pro/includes/tools/food-beverage/examples/` (blueprints); the two
validators at `tests/pro/tools/food-beverage/`.

---

## P1 — WS-1: Currency de-coupling

### 1.1 New Tenant Profile (currency facet)
Create `addons/pro/includes/tools/food-beverage/class-wp-mcp-ai-fnb-tenant-profile.php`:

| Member | Purpose |
|---|---|
| `currency_code()` | ISO-4217 code, default `''` (unset) |
| `currency_symbol()` | symbol for the code (fallback: the code itself) |
| `currency_decimals()` | 0 for LKR/JPY/KRW/VND, else 2 |
| `locale()` | locale tag for number/date formatting |
| `venue_name()` | display name, default neutral "the venue" |
| `venue_type()` | restaurant\|bar\|cafe\|club\|hotel\|ghost-kitchen |
| `format_money( $amount )` | formatted string + currency symbol |
| `money_suffix()` | `' (' . symbol . ')'` for metric titles |
| `render( $text )` | substitute `{{…}}` tokens (§P2) |

Defaults read from `wp_mcp_ai_fnb_settings`. Register the class for autoload parity
with the other toolkit services (loaded by `init.php`).

### 1.2 Neutralise the canonical column keys
In `class-wp-mcp-ai-fnb-data-source.php` `$tables`, for every table, add a neutral
canonical key and keep the legacy key as an alias:

- `1.0` Menu: `price_lkr` → `price`.
- `5.0` Supplier prices: `pack_price_lkr` → `pack_price`.
- `6.0` Daily sales: `price_lkr` → `price`, `revenue_lkr` → `revenue`.
- `8.0` Purchases: `pack_price_lkr` → `pack_price`, `line_total_lkr` → `line_total`.
- `9.0` Stock counts: `unit_cost_lkr` → `unit_cost`, `closing_value_lkr` → `closing_value`.
- `10.0` Waste: `value_lkr` → `value`.
- `11.0` Payroll: `basic_pay_lkr` → `basic_pay`, `epf_lkr` → `epf`, `etf_lkr` → `etf`,
  `overtime_pay_lkr` → `overtime_pay`, `casual_pay_lkr` → `casual_pay`,
  `total_payroll_cost_lkr` → `total_payroll_cost`.
- `14.0` Expense ledger: `amount_lkr` → `amount`.
- `15.0` Budgets: `budget_lkr` → `budget`.

For each, add the legacy key to that table's `aliases[neutral]` list (e.g.
`'price' => array( 'price', 'price_lkr' )`). `resolve_column()` already merges the
alias candidate into lookup, so both `price` and `price_lkr` headers keep resolving.

### 1.3 Update the metric engine to neutral keys
In `class-wp-mcp-ai-fnb-metrics.php`, change every `Data_Source::cell( …, '*_lkr' )`
read to the neutral key (the alias map guarantees legacy fixtures still resolve).
Update the `METRICS` title map to derive currency:
`'Revenue'` → `'Revenue (' . money_suffix() . ')'`, etc. Replace the `r()` doc and
precision with `currency_decimals()`.

### 1.4 Currency-neutral report + settings copy
- `class-wp-mcp-ai-fnb-report-builder.php`: any literal "LKR" in section text →
  `format_money()`/`money_suffix()`.
- `class-wp-mcp-ai-fnb-pr-*` and other `_lkr` string references: de-currency.
- Settings page: "Auto-Approval Threshold (LKR)" → dynamic currency.

**Gate:** `vendor/bin/phpunit tests/pro/tools/food-beverage/` green; the oracle test
(which reads the demo CSV fixtures via legacy headers) must still pass — this proves
the alias mechanism works.

---

## P2 — WS-2: Tenant Profile + shared renderer

Extend the class created in 1.1 with `render()`. Token map:

| Token | Source | Fallback |
|---|---|---|
| `{{venue_name}}` | `venue_name` | "the venue" |
| `{{currency}}` | ISO code | `''` |
| `{{currency_symbol}}` | symbol | `''` |
| `{{venue_type}}` | `venue_type` | "hospitality business" |
| `{{owner_role}}` | `owner_role` (add key) | "the owner" |

`render()` iterates a `strtr()` over the token map and is intentionally
idempotent/no-op when no tokens are present (so runtime answers never error).

Settings-page "Tenant Profile" section (WS-5) will populate these keys; until then
`render()` falls back to neutrals and both seeder and blueprint importer remain
functional.

**Gate:** unit-testable without WordPress (pure string function); add a targeted
PHPUnit case in P8.

---

## P3 — WS-3a: Venue-neutralise the canonical packs

1. Rewrite `presets/assistant-packs/fnb/a1-manager.json`, `a2-…`, `a3-…`, `a4-…`:
   - `content` + `excerpt` + `_wp_mcp_ai_system_prompt`: replace "Surf Club
     Midigama", "Midigama, Sri Lanka", "LKR", "Rella/Sanda", "PE firm/investor" with
     `{{…}}` tokens.
   - Replace G-10 "Quote amounts in LKR" → "Quote amounts in {{currency}}".
   - Replace G-11 ("demo assumptions") → neutral source-table disclosure.
   - A3: "investor pack for the CEO and finance / PE firm" → "owner/investor report
     for {{owner_role}}".
   - A4: "deity character bible (Rella, Sanda)" → "brand bible / tone-of-voice guide
     (read from the setup folder if present)".
2. In `class-wp-mcp-ai-fnb-assistant-seeder.php::seed()`, after
   `WP_MCP_AI_Assistant_Portability::parse_import()` and before `import_bundle()`,
   run `WP_MCP_AI_Fnb_Tenant_Profile::render()` over `content`, `excerpt` and every
   assistant's `_wp_mcp_ai_system_prompt`.
3. Update `presets/assistant-packs/fnb/README.md` (venue-neutral description,
   document the token mechanism).

**Gate:** `php tests/pro/tools/food-beverage/validate-assistant-packs.php` green
(after P8 updates its assertions).

---

## P4 — WS-3b: Venue-neutralise the blueprints + import tool

1. Rewrite `examples/fnb-a*.json` `meta.instructions` + `description` with `{{…}}`
   tokens (same replacement set as P3).
2. In `examples/class-wp-mcp-ai-tool-import-fnb-blueprint.php`:
   - Neutralise `get_description()` and `get_usage_guidance()` strings (drop
     "Surf Club Midigama", "LKR").
   - In `execute()`, after `load_blueprint()` returns `$data` and before
     `install()`, render `{{…}}` over `$data['name']`, `$data['description']`, and
     `$data['meta']['instructions']` via `WP_MCP_AI_Fnb_Tenant_Profile::render()`.
3. Update `examples/README.md` (venue-neutral mirrors + token mechanism note).

**Gate:** `test-fnb-blueprints.php` round-trip still green (P8 adds the render
assertion).

---

## P5 — WS-5: Demo-mode inversion + settings rewrite

1. `class-wp-mcp-ai-fnb-settings.php`: change `demo_mode` default to `false`;
   remove the silent fallback in `workbook_id()` (return `''` when
   `data_workbook_id` isn't set; keep `DEMO_WORKBOOK_ID` only behind an explicit
   `is_demo_mode()` action).
2. `class-wp-mcp-ai-fnb-settings-page.php`: de-venue copy ("demo workbook", "Surf
   Club"); relabel adapter → ingest mode placeholder; add **Tenant Profile** section
   (venue name, venue type, currency code, decimals, locale) and a **Data Review**
   panel placeholder (populated by the ingestor in P7).
3. `WP_MCP_AI_Fnb_Settings::is_demo_mode()` returns `false` by default.

**Gate:** manual settings-page smoke check; no test regression.

---

## P6 — WS-4: Metric/report localisation + USAR taxonomy

1. `class-wp-mcp-ai-fnb-metrics.php::total_operating_cost()`: group lines onto USAR
   taxonomy (Food, Beverage, Payroll, Occupancy, Other Operating) instead of
   demo-specific line names; keep a `raw_lines` passthrough for the oracle test.
2. `menu_engineering()`: label the four quadrants Stars/Plowhorses/Puzzles/Dogs and
   surface them through `M-21` output and the R-07 report row.
3. `class-wp-mcp-ai-fnb-report-builder.php`: localise any remaining hard-coded dates/
   numbers via the profile `locale()`.

**Gate:** oracle test still green (numbers unchanged, labels richer).

---

## P7 — WS-2 (ingestor): Universal single-folder ingestion

1. Add `ingest_mode` setting (`workbook|split|folder`, default `folder`).
2. New `class-wp-mcp-ai-fnb-universal-reader.php` implementing
   `WP_MCP_AI_Fnb_Table_Reader`:
   - `read_table( $table_id )` resolves the table by scanning `data_folder_id`.
   - Reuse `WP_MCP_AI_Pro_Google_Drive_Client` for folder listing + file export.
   - Header scoring + type inference; role assignment by aggregate signature.
   - Concatenate same-role files; add a `_source_file` provenance column.
   - Persist a review manifest (transient `wp_mcp_ai_fnb_ingest_manifest`) and expose
     it on the settings-page Data Review panel (one-click remap).
3. Wire `Data_Source::reader()` to instantiate the universal reader for
   `ingest_mode === 'folder'`.

**Gate:** new messy-file fixture test (P8) proves auto-mapping + manifest generation;
existing split/workbook modes unaffected.

---

## P8 — WS-6: Tests + fixtures

1. Extend `tests/pro/tools/food-beverage/validate-assistant-packs.php`: assert no
   "Surf Club" / "Midigama" / "LKR" / "Rella" / "Sanda" remain in any shipped prompt;
   assert `{{…}}` tokens present.
2. Extend `tests/pro/tools/food-beverage/test-fnb-blueprints.php`: same no-venue
   assertions on `examples/fnb-*.json` + tool-class strings; add a round-trip test
   that sets a tenant profile, imports a blueprint, and asserts the assistant's
   `_wp_mcp_ai_system_prompt`/`post_content` reflects the rendered venue/currency.
3. Add `tests/pro/tools/food-beverage/test-fnb-tenant-profile.php` (unit — pure
   `render()`/`format_money()`/`money_suffix()`).
4. Add `tests/pro/tools/food-beverage/test-fnb-universal-reader.php` + a
   `tests/pro/tools/food-beverage/fixtures/messy/` fixture set (deliberately
   mis-named files: "sales_jan_final (2).csv", "MY_INGREDIENTS.csv", etc.) asserting
   auto-role mapping, manifest shape, and concatenation.

**Final gate:** full F&B suite
`vendor/bin/phpunit tests/pro/tools/food-beverage/` plus both validators green.

---

## P9 — WS-7: Report export surface (CSV / PDF)

1. Add a **report alias map** (client 1–2–3 → canonical):
   - client `R-01 Monthly sales report` → new canonical `R-14` (`build_r_14`).
   - client `R-02 Waste and variance report` → canonical `R-05`.
   - client `R-03 Monthly cost review` → canonical `R-08`.
2. New `class-wp-mcp-ai-fnb-report-exporter.php` with two public methods:
   - `to_csv( $report_id, $args )` — flatten `Report_Builder::build()` sections
     into rows (`section`, `key`, `value`) and write via `fputcsv()` into the
     CSV/Drafts directory; returns `{file,url,mode}`.
   - `to_pdf( $report_id, $args )` — render sections to a simple HTML string and
     delegate to the `document-generation` `generate_pdf` tool
     (`WP_MCP_AI_Tool_Pro_PDF::execute()` or `generate_pdf`), returning
     `{file,url,attachment_id}` (no new dependency — reuses `pdfkit`).
3. Add `build_r_14()` (monthly sales report) to `WP_MCP_AI_Fnb_Report_Builder`.
4. Register a `fnb_export_report` tool (READ-tier, capability `edit_posts`) that
   runs either CSV or PDF export by `format`, keeping the deterministic
   metric-engine discipline (no model-authored figures).
5. Update `README.md` / `TOOL_INDEX.md` / the proposal to name CSV + PDF as the
   delivery formats for the operator's three report templates.

**Gate:** `report-exporter` CSV flattening unit-tested (no shell/network in the
bootstrap); PDF path gated behind the document-generation toolkit + `pdfkit`
bundle availability (skip gracefully when absent).

---

## 9. Definition of done

- [ ] No "Surf Club"/"Midigama"/"LKR"/"Rella"/"Sanda"/"Sri Lanka" in shipped
      prompts, blueprints, tool strings, settings copy, or metric/report labels.
- [ ] Currency-neutral canonical schema with `*_lkr` legacy aliases; oracle test
      green.
- [ ] Tenant Profile configured once, rendered into both assistant surfaces by one
      helper.
- [ ] `import_fnb_blueprint` renders tokens at install time.
- [ ] `demo_mode` off by default; no silent demo-workbook fallback.
- [ ] Universal single-folder ingest mode with review manifest.
- [ ] Client report templates (monthly sales / waste+variance / monthly cost
      review) exported as **CSV** and **PDF** via a `fnb_export_report` tool.
- [ ] Both validators + full F&B PHPUnit suite green.
