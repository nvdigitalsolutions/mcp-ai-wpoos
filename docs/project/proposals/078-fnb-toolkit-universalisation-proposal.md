# Proposal: Food & Beverage Toolkit Universalisation

**Date:** 2026-10-16
**Status:** APPROVED — implementation in progress (see `078-fnb-toolkit-universalisation-implementation-plan.md`)
**Estimated Effort:** 18–24 hours
**Priority:** MEDIUM (correctness refactor — no new customer-facing features, but a prerequisite for re-selling the toolkit to any operator)

> Scope: remove the four tenant-specific assumptions hard-coded into the F&B
> Management Pro Toolkit (`addons/pro/includes/tools/food-beverage/`) and its two
> assistant-delivery surfaces, so the toolkit is a genuinely universal product
> rather than a single-venue demo. The toolkit was built against the
> `Surf_Club_Midigama_-_Assistant_Task_Spec.xlsx` demo and still assumes one venue,
> one currency, one fixed schema, and a demo fallback default.

> Related in-repo work: `062-food-beverage-management-pro-toolkit-proposal.md`
> (the original build), `062-fnb-purchase-request-approval-appendix.md` (Phase 9b),
> `051-openstock-financial-toolkit-integration.md` (financial precedent),
> `google-workspace` toolkit (Drive client), `orchestration` toolkit (blueprint
> installer + Pro Schedule Manager).

---

## 1. Executive Summary

The F&B toolkit was delivered as a demo and still behaves like one. Four coupled
assumptions survive in production code, data schema, report output, and — most
visibly — the four seeded assistants:

1. **Venue** — every assistant prompt and blueprint says "Surf Club Midigama … in
   Midigama, Sri Lanka".
2. **Currency** — the Sri Lankan Rupee is baked into canonical *column names*,
   metric titles, settings labels, and report copy (`*_lkr`).
3. **Schema** — a fixed 17-table registry keyed by numbered IDs (`1.0`…`17.0`) and a
   hard-coded worksheet/file-name map.
4. **Demo default** — a freshly installed toolkit silently falls back to the
   bundled demo workbook (`DEMO_WORKBOOK_ID`) and demo fixtures instead of the
   user's real data.

This proposal removes all four. The centre-piece is a **Tenant Profile** (venue
name, currency, locale, venue type) configured once in settings and rendered into
both assistant-delivery surfaces through a single shared helper. A new
**Universal Ingestor** lets an operator point the toolkit at a single Google Drive
folder of *unorganised* files and have them auto-mapped onto the canonical tables;
a currency-neutral schema preserves the deterministic metric engine; and demo mode
becomes an explicit opt-in rather than a silent default.

**Outcome:** the same F&B toolkit can be pointed at an arbitrary restaurant, bar,
café, club, hotel or ghost-kitchen (any ISO currency/locale), with files dumped
into one folder, and produce correctly-labelled, deterministic metrics, reports and
assistant behaviour with zero per-tenant code changes.

---

## 2. Problem Statement

1. **Venue identity is hard-coded, not configured.** All four assistant packs
   (`presets/assistant-packs/fnb/a*.json`), their blueprint mirrors
   (`examples/fnb-*.json`), the two assistants' READMEs, and the
   `import_fnb_blueprint` tool's usage-guidance strings name "Surf Club Midigama"
   verbatim. A re-sell to another operator ships assistants that describe the wrong
   business.

2. **Currency is structural, not cosmetic.** The Sri Lankan Rupee is embedded in
   canonical data-column keys (`price_lkr`, `revenue_lkr`, `pack_price_lkr`,
   `unit_cost_lkr`, `closing_value_lkr`, `line_total_lkr`, `amount_lkr`,
   `budget_lkr`, payroll `*_lkr` fields), in the 21 metric titles
   (`Revenue (LKR)` … `Dish margin (LKR)`), in report copy, and in a settings label
   ("Auto-Approval Threshold (LKR)"). Ingesting a USD/EUR/GBP/JPY site requires a
   schema fork, not a config change.

3. **Ingestion assumes an organised source.** The reader resolves data through one
   of two paths — (a) a single shared workbook whose *worksheets* carry exact names
   ("Menu", "Daily Sales", …) or (b) one file per table whose *file name* matches a
   fixed slug — in both cases driven by a hard-coded `sheet_map`. There is no mode
   for "the user dumped heterogeneous files into one folder", which is the stated
   real-world onboarding path.

4. **Demo mode is the default.** `WP_MCP_AI_Fnb_Settings::workbook_id()` falls back
   to a constant demo workbook when no explicit ID is set, and `demo_mode` defaults
   to `true`. A fresh install reads the demo venue's data rather than failing
   loudly or asking the operator to point at their own files.

5. **Two assistant surfaces drift independently.** The canonical `nvoos-assistant`
   bundles go through `WP_MCP_AI_Assistant_Portability` (seeder), while the
   blueprint mirrors go through `WP_MCP_AI_Blueprint_Installer`
   (`import_fnb_blueprint` tool + the unified Blueprints admin page). The blueprint
   path has *no* rendering layer today, and every venue/currency edit must be made
   twice. This is the root cause of the demo-coupling being so hard to remove.

---

## 3. Design

### 3.1 Target architecture

```
Tenant Profile ──┬──▶ Canonical packs seeder ─────▶ Assistant CPTs (via Portability)
(venue, currency, │                                  wp mcp-ai pro fnb seed-assistants
 locale, type)    │
                  └──▶ Blueprint import tool ─────▶ Assistant CPTs (via Blueprint Installer)
                       + unified Blueprints page    import_fnb_blueprint
```

- The **Tenant Profile** is a new settings block (`wp_mcp_ai_fnb_settings`) read by
  every layer that renders money, venue or locale text.
- A single **shared renderer** (`WP_MCP_AI_Fnb_Tenant_Profile::render()`) substitutes
  `{{venue_name}}`, `{{currency}}`, `{{currency_symbol}}`, `{{venue_type}}`,
  `{{owner_role}}` in both surfaces, guaranteeing identical output.
- A **currency-neutral canonical schema** keeps the metric engine byte-for-byte
  deterministic; `*_lkr` keys become legacy aliases.
- A **Universal Ingestor** is a third reader implementing
  `WP_MCP_AI_Fnb_Table_Reader`, fed by a single-folder scan + fuzzy header mapping.

### 3.2 Industry grounding

- **USAR** (Uniform System of Accounts for Restaurants, National Restaurant
  Association) — canonical chart of accounts grouping Food / Beverage / Payroll /
  Occupancy / Other Operating. Used to normalise the Expense Ledger and Payroll
  tables onto a stable cost taxonomy instead of demo-specific line names.
- **Kasavana–Smith menu-engineering matrix** — Stars / Plowhorses / Puzzles / Dogs.
  Surfaced in place of the bare "Dish margin" so operators get the industry-standard
  vocabulary.
- **Canonical-schema ETL** — map arbitrary messy source columns onto one normalised
  target ontology with AI-assisted field mapping (the FileFeed / Flatline / retail
  data-engineering pattern). This is the model for the Universal Ingestor.

### 3.3 Currency-neutral schema (backward-compatible)

Every `*_lkr` canonical key gains a neutral twin and keeps `*_lkr` as a legacy
alias:

| Legacy key | Neutral key |
|---|---|
| `price_lkr` | `price` |
| `revenue_lkr` | `revenue` |
| `pack_price_lkr` | `pack_price` |
| `unit_cost_lkr` | `unit_cost` |
| `closing_value_lkr` | `closing_value` |
| `line_total_lkr` | `line_total` |
| `amount_lkr` | `amount` |
| `budget_lkr` | `budget` |
| payroll `*_lkr` | payroll `*` (no suffix) |

Resolution already flows through `Data_Source::resolve_column()` + the per-table
`aliases` map (currently under-used, holding mostly identity entries), so legacy
fixtures and previously-ingested data keep resolving with zero migration. A
`format_money()` / `money_suffix()` helper on the Tenant Profile replaces every
hard-coded "LKR" string; `Metrics::r()` decimal precision becomes currency-driven
(LKR/JPY = 0 dp, USD/EUR/GBP = 2 dp).

### 3.4 Universal Ingestor

A new `ingest_mode` setting supersedes the ambiguous `adapter`:
`workbook` (current single-workbook) | `split` (current one-file-per-table) |
`folder` (new, default). In `folder` mode the ingestor:

1. Scans the configured `data_folder_id` (flat by default; optional recursion).
2. Classifies each file (CSV/TSV/XLSX/ODS/native Sheets) by MIME + content.
3. Scores each file's header against the canonical ontology (column-name fuzz +
   type inference: dates, numeric ids, money values).
4. Assigns each file a table role (Menu … Assumptions) by aggregate signature.
5. Emits a **review manifest** (file → guessed role → confidence → column map),
   persisted and editable on the settings page.
6. Feeds `Data_Source::get_table()` via the existing reader contract.

Multiple files mapping to one role are **concatenated** (with a provenance column)
rather than last-writer-wins.

### 3.5 Two surfaces, one renderer

`WP_MCP_AI_Fnb_Tenant_Profile` exposes `render( string $text ): string` used by
both `WP_MCP_AI_Fnb_Assistant_Seeder::seed()` (packs) and
`WP_MCP_AI_Tool_Import_Fnb_Blueprint::execute()` (blueprints, newly added). In the
blueprint path, rendering happens **after** `load_blueprint()` and **before**
`install()`, on `meta.instructions` and `description`.

### 3.6 Report export surface (CSV / PDF)

The toolkit's report engine (`WP_MCP_AI_Fnb_Report_Builder`) emits structured
`sections` (heading + lines), currently persisted only as markdown/Google-Doc
drafts. Operators hand over **client report templates** (a `.pptx` monthly sales
report and two `.xlsx` reports) that are treated as **layout/content references**
and delivered as **CSV** (data) and **PDF** (presentation) — the two formats the
operator confirms are the real delivery targets. No `.pptx`/`.xlsx` template is
filled in place; instead:

- **CSV** — the report's sections are flattened into rows (`section`, `key`,
  `value`) written via `fputcsv()`. Deterministic and spreadsheet-importable.
- **PDF** — the sections are rendered to HTML and handed to the existing
  `document-generation` toolkit's `generate_pdf`/`pro_pdf_document` engine
  (`pdfkit`), producing a branded, print-ready PDF.

A small **report alias map** reconciles the operator's 1–2–3 numbering with the
canonical `R-01…R-13` registry (the client "R-02 Waste and variance" = toolkit
`R-05`; "R-03 Monthly cost review" = `R-08`; "R-01 Monthly sales report" is a new
`build_r_14` aliased from the client's R-01).

---

## 4. Detailed workstreams

### WS-1 — Currency de-coupling (structural, first)
See §3.3. Rename every `*_lkr` canonical key to a neutral key + legacy alias;
introduce the Tenant Profile money formatters; make `r()` currency-aware.

### WS-2 — Tenant Profile + shared renderer (prerequisite)
New `class-wp-mcp-ai-fnb-tenant-profile.php` with `get_all()`, `currency_code()`,
`currency_symbol()`, `format_money()`, `render()`. Settings defaults:
`venue_name`, `venue_type`, `currency_code` (ISO-4217), `currency_decimals`,
`locale`.

### WS-3a — Venue-neutralise canonical packs
Rewrite `presets/assistant-packs/fnb/a*.json` `content` + `_wp_mcp_ai_system_prompt`
to use `{{…}}` tokens; seed-time render in `seed()`. Replace G-11
("demo assumptions") with a neutral source-table disclosure; generalise A3's
"investor pack / PE firm" and A4's "deity character bible (Rella, Sanda)".

### WS-3b — Venue-neutralise blueprints
Rewrite `examples/fnb-*.json` `meta.instructions` + `description`; neutralise
`class-wp-mcp-ai-tool-import-fnb-blueprint.php` (`get_description`,
`get_usage_guidance`); add token rendering in `execute()`; update
`examples/README.md`.

### WS-4 — Metric/report localisation + USAR taxonomy
Metric titles derive currency from the profile; group operating cost onto USAR
lines; expose the menu-engineering four-quadrant result.

### WS-5 — Demo-mode inversion + settings rewrite
`demo_mode => false`; remove the silent `DEMO_WORKBOOK_ID` fallback (demo becomes
an explicit "Load demo data" action); de-venue the settings-page copy; add the
Tenant Profile + Data Review (manifest) panels.

### WS-6 — Tests + fixtures
Keep demo fixtures as an oracle; add messy-file ingestor fixtures; extend both
`validate-assistant-packs.php` and `test-fnb-blueprints.php` to assert no venue /
currency / demo strings remain and that the blueprint path renders tokens.

### WS-7 — Report export surface (CSV / PDF)
New `WP_MCP_AI_Fnb_Report_Exporter` that renders any report (via a client
`R-01/02/03` → canonical `R-*` alias map) to **CSV** (flattened sections via
`fputcsv()`) and **PDF** (HTML → the document-generation `generate_pdf` engine).
Adds a `Monthly sales report` builder (canonical `R-14`) for the client's R-01,
and reconciles the client's R-02→`R-05` / R-03→`R-08`.

---

## 5. Implementation order

| Phase | Workstream | Gating |
|---|---|---|
| P1 | WS-1 currency de-couple | — |
| P2 | WS-2 Tenant Profile + renderer | P1 |
| P3 | WS-3a packs neutralisation | P2 |
| P4 | WS-3b blueprints neutralisation | P2 |
| P5 | WS-5 demo-mode inversion + settings | P2 |
| P6 | WS-4 localisation + USAR | P1 |
| P7 | WS-2 (ingestor) | P1 |
| P8 | WS-6 tests + fixtures | all |
| P9 | WS-7 report export (CSV/PDF) | P1 |

---

## 6. Open decisions

1. **Currency default** — empty (must be set) vs USD. Recommendation: empty.
2. **Ingestion ambiguity** — concatenate (recommended) vs halt-and-ask.
3. **Fixed-costs handling** — `include_fixed_costs` tenant flag (recommended) vs
   keep hard-coded "rent/depreciation/finance not in data".
4. **Blueprint↔pack parity** — keep two formats but derive blueprints from bundles
   (recommended) vs hand-edit both.

---

## 7. Risks

- **Cross-file rename (WS-1)** is large; mitigated by adding neutral keys + legacy
  aliases in one pass (no deletion), keeping the demo oracle tests green.
- **Ingestor mis-mapping (WS-2)** could silently produce wrong metrics; mitigated
  by the review manifest + confidence scoring + a human-approval gate.
- **Prompt drift (WS-3)** if the two surfaces aren't driven by one renderer;
  mitigated by WS-3c's shared helper + a parify round-trip test.
