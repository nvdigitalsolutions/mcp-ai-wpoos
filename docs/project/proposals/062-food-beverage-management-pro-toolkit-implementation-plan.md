# Implementation Plan: Food & Beverage Management Pro Toolkit

**Date:** 2026-10-08
**Status:** 🔮 PENDING (companion to `062-food-beverage-management-pro-toolkit-proposal.md`)
**Estimated Effort:** 30–40 hours

> Companion doc: `062-food-beverage-management-pro-toolkit-proposal.md` (scope,
> industry research, spec analysis, design). This plan covers the build order,
> data contract, file inventory, test oracle and rollout workflow.

---

## 1. Build Order (critical path first)

The demo runs Thursday 8 October 2026. Phases are ordered so the demo-critical
path (data access → metrics → reports → assistants) completes first; scheduling,
MCP exposure and polish are demo-optional.

| Phase | Content | Demo-critical | Est. |
|---|---|---|---|
| 0 | Data contract + fixture import + oracle extraction | ✅ | 4h |
| 1 | Toolkit scaffold + data-source abstraction + `fnb_read_table` | ✅ | 4h |
| 2 | Metric engine M-01…M-21 + `fnb_calculate_metric` (+ composite tools) | ✅ | 8h |
| 3 | Report generators R-01…R-13 + draft writer (`fnb_save_draft`) | ✅ | 6h |
| 4 | Assistant packs A1–A4 (instructions, allowlists, settings) | ✅ | 3h |
| 5 | Scheduling (Pro Schedule Manager) + audit viewer | optional | 3h |
| 6 | MCP exposure (bridge, read+draft subset) | optional | 2h |
| 7 | Test hardening: oracle tests, phpcs, audit-skill checklist | ✅ | 4h |
| 8 | Demo dry-run + A4 image-tool wiring (default Off) | ✅ | 3h |
| 9 | **Native storage (CPTs/CCTs) + import bridge** — post-demo | ❌ | 10h |

---

## 2. Data Contract: Spec Table IDs ↔ Workbook Sheets

The data layer addresses tables by spec ID (1…17) + setup names; the demo
mapping below points each at its workbook sheet. Column drift is tolerated via
key-column mapping with a warning when a required column is missing.

| Spec | Sheet (demo data) | Key columns used by metrics |
|---|---|---|
| 1.0 Menu items | `Menu` | Item ID, Menu, Section, Item, Price (LKR), Type, Source |
| 2.0 Recipes | `Recipes` | Item ID, Ingredient ID, Qty per portion, Unit |
| 3.0 Ingredients | `Ingredients` | Ingredient ID, Unit, Category, Group, Storage, Shelf life (days), Minimum level, Supplier ID |
| 4.0 Suppliers | `Suppliers` | Supplier ID, Supplies, Delivery days, Lead time, Order cut-off, Payment terms |
| 5.0 Supplier prices | `Supplier prices` | Supplier ID, Ingredient ID, Pack size, Price, **Effective from** (as-of join) |
| 6.0 Daily sales | `Daily sales` | Date, Item ID, Type, Qty sold, Price, Revenue |
| 7.0 Daily covers | `Daily covers` | Date, Lunch, Dinner, Bar-only, Total covers |
| 8.0 Purchases | `Purchases` | Delivery date, Supplier ID, Invoice, Ingredient ID, Qty delivered, Pack price, Line total |
| 9.0 Stock counts | `Stock counts` | Count date, Count type, Ingredient ID, Opening, Bought, Closing, Unit cost |
| 10.0 Waste log | `Waste log` | Date, Ingredient ID, Qty, Reason, Logged by, Value (LKR) |
| 11.0 Payroll | `Payroll` | Month, Department, Basic pay, EPF, ETF, Overtime hours/pay, Casual shifts/pay, Total payroll cost |
| 12.0 Timesheets | `Timesheets` | Date, Month, Department, Contracted hours, Overtime hours, Casual shifts |
| 13.0 Utilities | `Utilities` | Date, Month, Electricity kWh, Water m³, LPG cylinders |
| 14.0 Expense ledger | `Expense ledger` | Vendor invoice, Expense line, Group, **For month**, Invoice date, Amount, Due date, Paid date, Status |
| 15.0 Budgets | `Budgets` | Month, Line, Budget (LKR) |
| 16.0 Bookings | `Bookings` | Booking ID, Date, Session, Type, Guests, Notes, Status |
| 17.0 Asset log | `Asset log` | Image ID, Deity, Created, Featured item, Post date, Channel, Status |
| Setup | `Read me`, `Assumptions` | constants for G-11 disclosure |
| Setup (Drive only) | Report library, Metric definitions, Investor pack template, Character bibles | runtime-read via `fnb_read_table` |

**Derived-column rule:** `Menu.Recipe cost`, `Stock counts.Used/Expected/Waste/
Unexplained`, `Utilities.kWh per cover` and the `Sales by period` pivot are
precomputed in the workbook. The toolkit **recomputes from raw tables** (G-02,
G-08) and uses these columns only as test cross-checks. The `Check totals` sheet
is the primary oracle (see §3).

---

## 3. Test Oracle: `Check totals` Sheet

Golden values the metric engine must reproduce (exact, ±1 LKR tolerance on sums;
percentages to 4 dp):

| Oracle row | Jul | Aug | Sep | Exercises |
|---|---|---|---|---|
| Covers | 1919 | 2263 | 2441 | M-02 |
| Food revenue | 7,914,800 | 9,507,700 | 10,492,100 | M-01 |
| Beverage revenue | 6,758,450 | 7,749,250 | 8,414,700 | M-01 |
| Total revenue | 14,673,250 | 17,256,950 | 18,906,800 | M-01 |
| Food cost (stock used) | 2,014,114 | 2,417,145 | 2,820,333 | M-15 × avg price |
| Beverage cost (stock used) | 1,858,551 | 2,120,446 | 2,368,449 | M-15 × avg price |
| Food cost % | 25.45% | 25.42% | 26.88% | M-04 |
| Beverage cost % | 27.50% | 27.36% | 28.15% | M-05 |
| Payroll volume / per-cover effect | 179,959.53 / 73,825.47 (Sep vs Aug) | — | — | M-07, M-08 |
| Overtime hours per 100 covers | 14.62 | 13.63 | 20.42 | M-09 |
| kWh per cover | 6.22 | 5.45 | 6.25 | M-11 |
| Total operating expenses | 7,653,646 | 8,483,153 | 9,988,746 | M-06 input |
| Cost per cover | 3,988.35 | 3,748.63 | 4,092.07 | M-06 |
| Opex % of revenue | 52.16% | 49.16% | 52.83% | R-08 |
| Sep vs budget (revenue lines, payroll, utilities, opex lines) | — | — | e.g. food revenue +492,100; maintenance +363,000 | M-20 |

Test data source: `Surf_Club_Midigama_-_Demo_Data.xlsx` imported in Phase 0 into
a PHP fixture (CSV per sheet) committed under
`addons/pro/includes/tools/food-beverage/tests/fixtures/`. No network in tests.

---

## 4. File Inventory

```
addons/pro/includes/tools/food-beverage/
├── init.php                                  # toolkit registration
├── README.md                                 # per-folder README convention
├── TOOLS_LIST.txt / TOOL_INDEX.md
├── class-wp-mcp-ai-fnb-data-source.php       # table registry + reader interface
├── class-wp-mcp-ai-fnb-google-sheets-reader.php  # Drive adapter (uses google-workspace client)
├── class-wp-mcp-ai-fnb-cpt-reader.php        # Phase 9: WP_Query/meta adapter
├── class-wp-mcp-ai-fnb-cct-reader.php        # Phase 9: JetEngine CCT adapter
├── class-wp-mcp-ai-fnb-metrics.php           # M-01…M-21, one pure method per M-id
├── class-wp-mcp-ai-fnb-report-builder.php    # R-01…R-13 section builders
├── class-wp-mcp-ai-fnb-draft-writer.php      # Drafts-folder-scoped Doc/Sheet writer
├── class-wp-mcp-ai-fnb-settings.php          # folder IDs, model prefs, demo mode
├── class-wp-mcp-ai-fnb-sale-cpt.php          # Phase 9: mcp_ai_fnb_sale
├── class-wp-mcp-ai-fnb-stock-count-cpt.php   # Phase 9: mcp_ai_fnb_stock_count
├── class-wp-mcp-ai-fnb-waste-cpt.php         # Phase 9: mcp_ai_fnb_waste
├── class-wp-mcp-ai-fnb-purchase-cpt.php      # Phase 9: mcp_ai_fnb_purchase
├── class-wp-mcp-ai-fnb-booking-cpt.php       # Phase 9: mcp_ai_fnb_booking
├── class-wp-mcp-ai-tool-fnb-read-table.php
├── class-wp-mcp-ai-tool-fnb-search-table.php
├── class-wp-mcp-ai-tool-fnb-calculate-metric.php
├── class-wp-mcp-ai-tool-fnb-compare-periods.php
├── class-wp-mcp-ai-tool-fnb-variance-split.php        # M-07/M-08
├── class-wp-mcp-ai-tool-fnb-stock-used.php            # M-15
├── class-wp-mcp-ai-tool-fnb-expected-use.php          # M-16
├── class-wp-mcp-ai-tool-fnb-stock-variance.php        # M-17
├── class-wp-mcp-ai-tool-fnb-waste-analysis.php        # M-18, by item/reason, MoM
├── class-wp-mcp-ai-tool-fnb-days-of-cover.php         # M-14 + shelf-life flags (A2-06)
├── class-wp-mcp-ai-tool-fnb-weekend-demand.php        # M-12/M-13 + PAR shortfall + order-by dates (A2-04/05)
├── class-wp-mcp-ai-tool-fnb-price-change-impact.php   # M-19 (as-of join)
├── class-wp-mcp-ai-tool-fnb-budget-variance.php       # M-20
├── class-wp-mcp-ai-tool-fnb-dish-margin.php           # M-21 + menu-engineering quadrant
├── class-wp-mcp-ai-tool-fnb-duplicate-invoice-scan.php # A3-04 (flag, not conclude)
├── class-wp-mcp-ai-tool-fnb-labour-analysis.php       # M-09/M-10
├── class-wp-mcp-ai-tool-fnb-utilities-per-cover.php   # M-11
├── class-wp-mcp-ai-tool-fnb-generate-report.php       # R-01…R-13
├── class-wp-mcp-ai-tool-fnb-save-draft.php            # Doc/Sheet into Drafts folder
├── class-wp-mcp-ai-tool-fnb-list-drafts.php
├── class-wp-mcp-ai-tool-fnb-audit-log.php             # read-only CEO viewer
├── class-wp-mcp-ai-tool-fnb-import-table.php          # Phase 9: Sheet → CPT/CCT (ACT-tier)
├── assistant-packs/
│   ├── a1-manager.json / a2-kitchen-bar-stock.json
│   ├── a3-financial.json / a4-content.json
└── tests/
    ├── fixtures/            # CSV imports of the demo workbook sheets
    ├── test-fnb-metrics-oracle.php   # Check-totals reproduction
    ├── test-fnb-reports.php          # R-layout smoke tests
    ├── test-fnb-permissions.php      # allowlist + draft-scope tests
    └── test-fnb-cpt-storage.php      # Phase 9: CPT round-trip + adapter parity
```

Conventions: canonical envelope (`success` array or `WP_Error`), two-gate
sanitisation at entry / escape at exit, `required_capability` on every tool,
no unguarded shell calls, string/array-safe parsing of Sheets responses
(the four failure classes from the `mcp-ai-wpoos-toolkit-audit` skill).

---

## 5. Phases in Detail

### Phase 0 — Data contract + fixtures (4h)
- Extract every workbook sheet to CSV fixtures (committed, not the xlsx).
- Build the table-ID → sheet mapping + key-column maps in
  `WP_MCP_AI_Fnb_Data_Source`.
- Write the oracle-extraction script outputting the `Check totals` rows as a
  PHP array fixture (`expected-values.php`).
- **Acceptance:** `vendor/bin/phpunit tests/.../test-fnb-metrics-oracle.php` can
  load fixtures and assert the fixture's own totals (self-check).

### Phase 1 — Scaffold + data access (4h)
- `init.php`, README, TOOLS_LIST/INDEX; register toolkit.
- `fnb_read_table(table_id, filters?)` → rows + column list + source citations;
  5-min transient cache keyed by table ID.
- `fnb_search_table(table_id, column, value, date_range?)`.
- Drive adapter behind `Fnb_Table_Reader` interface; a `Fixture_Reader` (CSV)
  backs all unit tests so tests never hit the network.
- **Acceptance:** A1–A4 can list tables and read any table with correct rows.

### Phase 2 — Metric engine (8h)
- Implement M-01…M-21 as pure methods; period args default to Sep-vs-Aug
  comparison and the 9–11 Oct weekend per the spec.
- `fnb_calculate_metric(metric_id, args)` + the composite tools.
- As-of-date price join helper (Supplier prices); month-incurred vs date-paid
  split helper (expense ledger).
- **Acceptance:** oracle tests pass (§3) — every `Check totals` row reproduced.

### Phase 3 — Reports + drafts (6h)
- R-01…R-13 section builders in the Report builder; layouts per the Reports
  sheet; all figures via the metric engine (G-09, A1-04).
- `fnb_save_draft` (Google Doc or Sheet, Drafts-folder-only, title convention
  `[R-xx] <title> — <period> — Draft — for <approver>`) and `fnb_list_drafts`.
- **Acceptance:** each report generates from fixtures with correct section order
  and figures; drafts written to a test folder only.

### Phase 4 — Assistant packs (3h)
- Four JSON packs: system prompt (G-rules + applicable A-rules + pointer to the
  Drive folder layout), tool allowlist (READ + DRAFT tier only), settings
  (Drafts folder ID, model).
- A4 pack includes the character-bible contract (Rella, Sanda), the
  AI-content-label reminder (A4-04), and the `image-production` tools
  (`generate_image_ai`, `generate_image_variations`, `image_inpainting`,
  `text_to_image_prompt_optimizer`) pre-listed in its allowlist **disabled by
  default** (ACT-tier gate per the spec) — enabling later is a settings
  change, not a re-deploy.
- **Acceptance:** import each pack; verify tool visibility per allowlist and
  that ACT-tier tools (including image generation) are absent/disabled.

### Phase 5 — Scheduling + audit (3h)
- Pro Schedule Manager entries: R-01 daily 08:00, R-02 Mon 08:00, R-03 Thu
  09:00, R-05 Mon, R-13 Mon (orchestration toolkit `create-pro-schedule`).
- Delivery schedules: `channel_broadcast`-type entries for R-02/R-03 push to
  the approver's WhatsApp/email — registered but **disabled** for the demo
  (ACT tier off per spec); enableable via `update-pro-schedule`.
- `fnb_audit_log` read-only viewer.
- **Acceptance:** dry-run a scheduled R-03; audit log shows tool calls;
  disabled broadcast schedule refuses to fire (capability gate).

### Phase 6 — MCP exposure (2h, optional)
- Expose A1–A4 via the existing bridge with read+draft-only tool subsets.
- **Acceptance:** an external MCP client can list/read/draft; ACT tools return
  capability errors.

### Phase 7 — Test hardening (4h)
- Oracle tests (§3); permission tests; report smoke tests; phpcs both
  standards; toolkit-audit four-failure-class checklist; coverage for the
  canonical-envelope + sanitisation sniffs.
- **Acceptance:** CI-equivalent run green (Docker PHPUnit per test-suite skill).

### Phase 8 — Demo dry-run + A4 image-tool wiring (3h)
- Run all 20 Questions-sheet questions against the real Drive folder; verify
  figures match `Check totals`; verify zero ACT calls in the audit log; time
  each answer for the cost table (§7).
- A4 image API wiring (open question 10): confirm the four `image-production`
  tools are registered, capability-gated and default-Off in the A4 pack;
  run one prompt-only A4 answer end-to-end (character bible → prompt +
  caption + hashtags) and verify `generate_image_ai` refuses with a
  capability error while disabled.
- **Acceptance:** demo script passes; open questions 1–10 resolutions hold;
  flipping the A4 image toggle in settings is the only change needed to
  enable generation post-demo.

### Phase 9 — Native storage: CPTs/CCTs + import bridge (10h, post-demo)
Mirrors the vitals/document-generation patterns (proposal §5.8).
The purchase-request approval lifecycle (`fnb_pr_*` tools on the QMS engine)
is specified in
[`062-fnb-purchase-request-approval-appendix.md`](./062-fnb-purchase-request-approval-appendix.md)
and lands in the second half of this phase.
- Register 5 CPTs with the `mcp_ai_hc_vital_log` pattern: hidden, no UI/REST,
  `map_meta_cap`, `init` priority 12, gated behind an `enable_fnb_native_storage`
  toggle; before/after hooks per CPT (`wp_mcp_ai_fnb_before_<type>_log`, …after).
- Register JetEngine CCT fields for the reference tables via
  `WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields()` (per the
  document-generation init pattern); recipe → ingredient relationship fields.
- Implement `CPT_Reader` / `CCT_Reader` adapters; wire the table-ID → storage
  map in `WP_MCP_AI_Fnb_Data_Source` so metrics/reports run unchanged.
- `fnb_import_table` (ACT-tier, capability-gated): Sheet → CPT/CCT,
  modelled on `import_vitals` / `excel-data-import`; idempotent by
  (table key columns).
- Adapter-parity tests: run the §3 oracle suite against CPT/CCT-backed
  fixtures and require identical results to the Sheets path.
- **Acceptance:** `test-fnb-cpt-storage.php` green; oracle suite passes on
  both storage backends; import round-trips the demo workbook losslessly.

---

## 6. Validation Strategy

1. **Oracle tests** — the `Check totals` reproduction is the primary correctness
   gate (differential testing against the workbook's own maths); Phase 9 runs
   the same suite against CPT/CCT-backed fixtures (adapter parity).
2. **Unit tests per metric** — each M-id against fixture-derived small cases.
3. **Cross-assistant consistency** — A1/A2/A3 shared figures asserted identical
   (A1-04) via a single engine instance test.
4. **Permission tests** — allowlist enforcement, Drafts-scope refusal, ACT-tier
   capability errors (incl. `fnb_import_table` gating in Phase 9).
5. **Lint** — `composer run lint` + `lint:compat` (PHP 7.4+); PHPCS sniff
   `WPMCPAI.Tools.CanonicalReturnEnvelope` and `SanitizeAtEntry` at severity 5.
6. **Hardening checklist** — no unguarded `exec`/`shell_exec`; no calls to
   non-existent client methods; array/string-safe parsing of provider/Sheets
   responses; provider enum consistency (audit skill).
7. **CPT conventions** (Phase 9) — follow the vitals README conventions:
   sub-toolkit toggle gating, before/after hooks, tests under
   `tests/pro/tools/food-beverage/`, README with Purpose/Tier/Public
   Surface/Inputs-Outputs-Neighbors/Conventions/Tests/Also Load.

## 7. Model & Cost Estimate (open question 9)

| Workload | Model | Rationale |
|---|---|---|
| Metric/report tool calls | none (PHP engine) | deterministic, free |
| Analysis + writing | DeepSeek (default) | low cost per question |
| Decisions (rankings, approvals) | Jev / DeepSeek | Jev for strict decision-only paths |

Estimates (assumes ~20 demo questions, ~8K prompt + ~2K completion tokens each):
DeepSeek ~USD 0.003–0.01 per question → **sub-USD 0.20 for the whole demo**.
Backup: OpenAI 4o-class ~USD 0.05–0.15 per question. Caching (Phase 1
transients) removes repeated read costs entirely. Verify live pricing before
the meeting; the model setting is per-assistant, so switching is a settings
change, not a code change.

## 8. Rollout Workflow

Per repo conventions: work on `alpha-working`, cluster the change into focused
PRs (data source → metrics → reports → assistants → scheduling/MCP), each PR
green on Docker PHPUnit + phpcs before merge. Register the toolkit in the
existing `addons/pro` toolkit registry (`init.php` + any central registry
constants); update `TOOLS_LIST.txt`, `TOOL_INDEX.md`, and the toolkit README
(per-folder README convention incl. which `.context/*.md` files to load).
After the demo, back-port findings into
`docs/tool-reference.md` tool docs and a `COMPLETION_SUMMARY.md` in the toolkit
folder (pattern used by `financial-planning`).
