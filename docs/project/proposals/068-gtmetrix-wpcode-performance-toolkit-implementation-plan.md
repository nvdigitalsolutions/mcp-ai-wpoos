# Implementation Plan: GTmetrix × WPCode Performance Optimization Pro Toolkit

**Date:** 2026-10-12
**Status:** 🔮 PENDING — build not started (companion to `068-gtmetrix-wpcode-performance-toolkit-proposal.md`)
**Estimated Effort:** ~37 hours across Phases 0–7 (breakdown per phase below)

> Companion doc: `068-gtmetrix-wpcode-performance-toolkit-proposal.md` (scope,
> industry research, API digest, fix taxonomy, tool inventory). This plan covers
> the build order, API/fixture contract, test oracle, file inventory,
> registration checklist and validation gate.

---

## 1. Build Order (dependency-ordered)

| Phase | Content | Est. | Gate |
|---|---|---|---|
| 0 | Fixtures + score-engine oracle extraction | 3h | fixtures load; oracle self-check passes |
| 1 | Scaffold + settings + API client + reference tools (#1–4) | 5h | client unit tests green, phpcs clean |
| 2 | Test lifecycle + poller + snapshot store + report tools (#5–11) | 6h | fixture lifecycle run passes end-to-end |
| 3 | Score engine + analysis tools (#12–13) | 5h | smoke harness reproduces every oracle row |
| 4 | Fix engine + WPCode bridge + tools (#14–15) + developer-tool extension | 6h | golden-file snippet tests green; existing WPCode tool regression-free |
| 5 | Pages / monitoring / alerts / tags (#16–23) | 5h | validation matrices green |
| 6 | Presets + assistant pack + 7-surface registration sweep | 4h | preset stub tests + coverage CI green |
| 7 | Validation gate + docs + PR | 3h | full Docker PHPUnit + phpcs + toolkit-audit scan green |

Critical path: **1 → 2 → 3 → 4** (a working audit→fix loop is the product;
monitoring/presets ride on top). Phases 5–6 can be re-ordered or trimmed if
effort overruns; they are additive surfaces on the same client.

---

## 2. Data Contract

### 2.1 Fixture inventory (`addons/pro/includes/tools/gtmetrix/tests/fixtures/*.json`)

Committed redacted fixtures captured from the API docs (fetched 2026-10-12)
plus synthetic variants exercising edge states. **No network in tests.**

| Fixture | Shape | Exercises |
|---|---|---|
| `status-basic.json` / `status-pro.json` | `/status` responses (api_credits, refill, account_type, PRO flags) | budget gate, plan-aware params |
| `status-low-credits.json` | 3.2 credits left | budget preflight failure (E40201 path) |
| `locations.json` / `browsers.json` / `devices.json` | reference lists incl. `account_has_access: false` entry | plan-aware availability filtering |
| `test-queued.json` / `test-started.json` / `test-error.json` | `/tests/{id}` states (error: 404 Not Found example) | poller state machine |
| `report-lighthouse-good.json` | A-grade report: LCP 1.8 s, TBT 120 ms, CLS 0.03, 890 KB, 46 req | CWV "good" oracle |
| `report-lighthouse-poor.json` | E-grade: LCP 4.6 s, TBT 720 ms, CLS 0.31, 6.2 MB, 210 req | CWV "poor" oracle |
| `report-legacy.json` / `report-metrics-only.json` | pagespeed/yslow scores; no lighthouse metrics | type-aware engine fallbacks |
| `lighthouse-audits.json` | audit subset with `_impactScore`/`_structureScore` per audit (bands: None/Low/Med/High), LCP element breakdown (ttfb/loadDelay/loadTime/renderDelay) | impact ordering, LCP phases |
| `resource-summary.json` | per-type counts/sizes (doc per API: document/font/image/media/script/stylesheet/other) | budget engine inputs |
| `pages.json` / `page.json` / `reports-list.json` | page registry + history (`monitored`, `latest_report`, `page_tags`) | page tools, tag filters |
| `alert-conditions.json` / `alert-events.json` | conditions + trigger history (metric/operator/threshold/value + one error entry) | alert validation matrix |
| `tags.json` | tag list (name/description/color) | tag management |

### 2.2 WP-side registry

`wp_mcp_ai_gtmetrix_settings['core_pages']` — array of
`{ url, label, tags[] }` for the site's monitored pages (default: home URL).
`wp_mcp_ai_gtmetrix_pages` option — URL → GTmetrix `page_id` map maintained
whenever a test completes (the API's page-aggregation key is URL + analysis
options; the WP-side map is per-site truth for retest/sweep).

### 2.3 JSON:API normalisation rule (client contract)

GTmetrix responses are JSON:API v1.1 — `data` is an **object or array**
depending on endpoint, and error bodies are `errors[]`. The client MUST
normalise before anything downstream touches it:

- `normalise_datum( $json )` → array (single) | list (many) | WP_Error
  (parse failure or `errors[]` mapped to the error-code taxonomy in
  Appendix A.4 of the proposal).
- `credits_used`/`credits_left` are **floats** (e.g. 1.1) — never cast to int.
- Timestamps are unix ints; all ms metrics are ints; CLS is a float.
- Never trust `data.links.*` presence without checking (resources appear only
  for the report type that produced them).
- `analysis-options` and `net.har` are never fetched or cached (sensitive:
  httpauth creds / cookies) — the client hard-blocks those two resource names.

---

## 3. Test Oracle (score engine golden values)

`tests/expected-values.php` — `return array( key => value )`; the engine must
reproduce every row (the F&B Check-totals pattern). Source of truth: CWV 2026
thresholds (LCP 2500/4000 ms, CLS 0.1/0.25, TBT 200/600 ms as INP proxy,
TTFB 600/1800 ms guidance), GTmetrix grade bands, and the proposal's budget
classes.

| Oracle key | Input | Expected |
|---|---|---|
| `cwv.lcp.good` | LCP 1800 | `good` |
| `cwv.lcp.boundary.good` | LCP 2500 | `good` (≤ 2500 inclusive) |
| `cwv.lcp.ni` | LCP 2501 | `needs-improvement` |
| `cwv.lcp.poor` | LCP 4000 | `poor` (≥ 4000 inclusive) |
| `cwv.cls.good` | CLS 0.10 | `good` (≤ 0.1) |
| `cwv.cls.ni` | CLS 0.11 | `needs-improvement` |
| `cwv.cls.poor` | CLS 0.25 | `poor` |
| `cwv.tbt.good` | TBT 200 | `good` |
| `cwv.tbt.poor` | TBT 600 | `poor` |
| `cwv.overall.good` | report-lighthouse-good | `pass` |
| `cwv.overall.fail` | report-lighthouse-poor | `fail` |
| `grade.A` / `grade.B` / `grade.C` / `grade.D` / `grade.E` / `grade.F` | _gtmetrixScore 0.90 / 0.80 / 0.70 / 0.60 / 0.50 / 0.49 | A / B / C / D / E / F |
| `budget.lean` | 890 KB, 46 req | `lean` |
| `budget.acceptable` | 2.4 MB, 89 req | `acceptable` |
| `budget.heavy` | 2.5 MB, 91 req | `heavy` |
| `budget.very_heavy` | 5.1 MB, 150 req | `very_heavy` |
| `impact.order` | lighthouse-audits.json | sorted desc by `_impactScore`; band labels match GTmetrix table (High ≤ 1, Med-High ≤ 0.48, Med ≤ 0.36, Med-Low ≤ 0.24, Low ≤ 0.12, None = 0) |
| `lcp.phases` | lighthouse-audits.json | TTFB/LoadDelay/LoadTime/RenderDelay sum ≈ LCP (±1 ms) |
| `delta.score.regressed` | 94 → 87 | `regressed` (score drop > 5) |
| `delta.lcp.regressed` | 1800 → 2350 | `regressed` (LCP +20% threshold: 2160) |
| `delta.lcp.steady` | 1800 → 2100 | `steady` |
| `delta.cls.regressed` | 0.03 → 0.09 | `regressed` (Δ ≥ 0.05) |
| `delta.bytes.regressed` | 890 KB → 1.1 MB | `regressed` (+10%) |

Float tolerance: `assertEqualsWithDelta( ..., 0.001 )` (F&B lesson — the
engine rounds display to 2 dp; never `assertEquals` on floats).

---

## 4. File Inventory

```
addons/pro/includes/tools/gtmetrix/
├── init.php                                  # toolkit registration, CPT + AS hook wiring, settings-defaults filter
├── README.md                                 # per-folder README convention
├── TOOLS_LIST.txt / TOOL_INDEX.md
├── class-wp-mcp-ai-gtmetrix-settings.php     # wp_mcp_ai_gtmetrix_settings wrapper + API key store ref
├── class-wp-mcp-ai-gtmetrix-client.php       # HTTP adapter: auth, JSON:API normalisation, errors, 429 backoff
├── class-wp-mcp-ai-gtmetrix-poller.php       # Action Scheduler test-poll lifecycle + single-flight lock
├── class-wp-mcp-ai-gtmetrix-scheduler.php    # core-pages registry + sweep/monthly-review enqueue helpers
├── class-wp-mcp-ai-gtmetrix-store.php        # storage reader interface (ONE object structure per file)
├── class-wp-mcp-ai-gtmetrix-snapshot-store.php  # CPT mcp_ai_gtmetrix_report + 5-min transients + prune
├── class-wp-mcp-ai-gtmetrix-fixture-reader.php  # fixture adapter behind the store interface
├── class-wp-mcp-ai-gtmetrix-score-engine.php    # pure static: CWV, grades, budgets, impact order, delta
├── class-wp-mcp-ai-gtmetrix-fix-engine.php      # audit → fix taxonomy + matcher + snippet generator
├── class-wp-mcp-ai-gtmetrix-wpcode-bridge.php   # draft-only WPCode install via the shared repository
├── class-wp-mcp-ai-gtmetrix-tool-base.php       # abstract base: guard(), client(), settings(), arg helpers
├── class-wp-mcp-ai-tool-gtmetrix-account-status.php          # 1
├── class-wp-mcp-ai-tool-gtmetrix-list-locations.php          # 2
├── class-wp-mcp-ai-tool-gtmetrix-list-browsers.php           # 3
├── class-wp-mcp-ai-tool-gtmetrix-list-simulated-devices.php  # 4
├── class-wp-mcp-ai-tool-gtmetrix-start-test.php              # 5  (write, credit-gated)
├── class-wp-mcp-ai-tool-gtmetrix-get-test-status.php         # 6
├── class-wp-mcp-ai-tool-gtmetrix-list-tests.php              # 7
├── class-wp-mcp-ai-tool-gtmetrix-get-report.php              # 8
├── class-wp-mcp-ai-tool-gtmetrix-get-lighthouse-audits.php   # 9
├── class-wp-mcp-ai-tool-gtmetrix-get-resource-summary.php    # 10
├── class-wp-mcp-ai-tool-gtmetrix-list-report-resources.php   # 11
├── class-wp-mcp-ai-tool-gtmetrix-compare-reports.php         # 12
├── class-wp-mcp-ai-tool-gtmetrix-analyze-report.php          # 13
├── class-wp-mcp-ai-tool-gtmetrix-generate-wpcode-snippet.php # 14 (read-only)
├── class-wp-mcp-ai-tool-gtmetrix-install-wpcode-fix.php      # 15 (write, chat-restricted, draft-only)
├── class-wp-mcp-ai-tool-gtmetrix-list-pages.php              # 16
├── class-wp-mcp-ai-tool-gtmetrix-get-page.php                # 17
├── class-wp-mcp-ai-tool-gtmetrix-retest-page.php             # 18 (write, credit-gated)
├── class-wp-mcp-ai-tool-gtmetrix-set-monitoring.php          # 19 (write)
├── class-wp-mcp-ai-tool-gtmetrix-get-alert-conditions.php    # 20
├── class-wp-mcp-ai-tool-gtmetrix-set-alert-conditions.php    # 21 (write)
├── class-wp-mcp-ai-tool-gtmetrix-get-alert-events.php        # 22
├── class-wp-mcp-ai-tool-gtmetrix-manage-page-tags.php        # 23 (write; action enum)
├── tests/
│   ├── fixtures/*.json            # §2.1
│   ├── expected-values.php        # §3 oracle
│   ├── test-gtmetrix-client.php   # auth header, content-type, 429 backoff, error mapping, normalisation
│   ├── test-gtmetrix-poller.php   # state machine, lock expiry, snapshot on complete, error state
│   ├── test-gtmetrix-store.php    # CPT round-trip, latest-for-url, prune, transient cache
│   ├── test-gtmetrix-score-engine.php   # oracle rows + boundary values
│   ├── test-gtmetrix-analysis-tools.php # analyze/compare tool envelopes
│   ├── test-gtmetrix-fix-engine.php     # taxonomy completeness + matcher + golden-file snippets
│   ├── test-gtmetrix-wpcode-bridge.php  # availability-gated; draft-only; conditional-logic schema
│   ├── test-gtmetrix-pages-ops.php      # pages/monitoring/alerts/tags validation matrices
│   └── smoke-run.php              # standalone harness: WP stubs + fixture reader → oracle PASS/FAIL
└── assistant-packs/
    └── gtmetrix-performance-optimizer.json
```

Developer-toolkit companion changes (`addons/pro/includes/tools/developer/`):

```
class-wp-mcp-ai-pro-wpcode-snippet-repository.php   # extracted save/get/list/validate-location from the existing tool
class-wp-mcp-ai-pro-tool-create-wpcode-snippet.php  # REFACTOR: delegate to repository; add conditional_logic arg
class-wp-mcp-ai-pro-tool-list-wpcode-snippets.php   # NEW read tool (id/title/status/code_type/location/active, tag filter)
```

Conventions: canonical envelope (`success` array or `WP_Error`, never
`success => false`), two-gate sanitisation (sanitize every `$arguments[...]`
at entry, escape at exit), `get_required_capability()` on every tool (`read`
for read tools, `manage_options` for write/ops tools), `requires_base_pro()`
true, capability flags per proposal §4.3, `get_usage_guidance()` on every tool
(the four failure classes from `mcp-ai-wpoos-toolkit-audit` are the review
lens).

---

## 5. Phases in Detail

### Phase 0 — Fixtures + oracle (3h)
- Capture the §2.1 fixture set (redacted; no real API keys or account data).
- Write `expected-values.php` from the §3 table.
- Stub `smoke-run.php` skeleton (ABSPATH defined FIRST — any repo file with an
  ABSPATH guard silently exits without it).
- **Acceptance:** `php tests/pro/tools/gtmetrix/smoke-run.php` loads fixtures
  and passes a self-check on the oracle file's own totals.

### Phase 1 — Scaffold + settings + client + reference tools (5h)
- `init.php` (requires services, registers `enable_gtmetrix_toolkit` gate,
  settings-defaults filter `wp_mcp_ai_gtmetrix_settings_defaults`), README,
  TOOLS_LIST.txt, TOOL_INDEX.md.
- `WP_MCP_AI_GTmetrix_Settings` — option wrapper with defaults:
  `credit_budget_daily` 25, `allowed_test_hosts` `[ home_url() host ]`,
  `snapshot_retention_days` 90, `poll_max_attempts` 20, `poll_interval_s` 3,
  `default_location` '', `default_browser` '', `fixture_mode`
  (`WP_MCP_AI_GTMETRIX_USE_FIXTURES`), `core_pages` `[ home_url() ]`.
  API key via `wp_mcp_ai_get_api_key( 'gtmetrix_api_key' )`
  (`WP_MCP_AI_Api_Key_Store` — encrypted; env-var auto-detection already
  covers `GTMETRIX_API_KEY`-style constants on activation).
- `WP_MCP_AI_GTmetrix_Client` — `request( $method, $path, $body, $opts )`:
  - `Authorization: Basic base64( key . ':' )` (API key as username, blank
    password — never via query string).
  - `Content-Type: application/vnd.api+json` on every POST.
  - `normalise_datum()` per §2.3; error-code taxonomy map (§A.4 of proposal)
    → `WP_Error` codes `wp_mcp_ai_gtmetrix_<err>` with `title`/`detail`
    surfaced.
  - `X-RateLimit-*` captured into the response meta; 429 E42901 → up to 3
    retries with exponential backoff (1 s / 2 s / 4 s) + `usleep` via
    `sleep()`; E42900 (concurrency) and E42902/E42903 NOT retried — surfaced
    with guidance instead.
  - 30 s timeout; 303 handling: `get_test()` follows the Location header to
    the report endpoint when state completes.
  - Hard-block resource names `analysis-options` and `net.har` (sensitive).
- Public methods mirroring Appendix A.1 of the proposal: `get_status()`,
  `get_locations()`, `get_browsers()`, `get_devices()`,
  `start_test( $params )`, `get_test( $id )`, `list_tests( $args )`,
  `get_report( $id )`, `get_resource( $report_id, $name, $query )`,
  `delete_report( $id )`, `retest_report( $id )`, `get_page( $id )`,
  `list_pages( $args )`, `delete_page( $id )`, `retest_page( $id )`,
  `set_monitoring( $page_id, $frequency )`,
  `get_alert_conditions( $page_id )`, `set_alert_conditions( ... )`,
  `delete_alert_conditions( $page_id )`, `get_alert_events( $page_id, $page )`,
  `list_tags()`, `create_tag( $attrs )`, `update_tag( $id, $attrs )`,
  `delete_tag( $id )`, `attach_tags_to_page()`, `detach_tags_from_page()`,
  `attach_pages_to_tag()`, `detach_pages_from_tag()`. Fixture mode short-circuits
  reads through `WP_MCP_AI_GTmetrix_Fixture_Reader`.
- `WP_MCP_AI_GTmetrix_Tool_Base` — `guard()` (toolkit enabled → key present →
  budget not exhausted for write tools), `client()`, `settings()`.
- Tools #1–4 (reference reads; `cacheable` flag; 1 h transient on
  locations/browsers/devices).
- Tests: `test-gtmetrix-client.php` with `pre_http_request` mocks asserting
  auth header value, content-type, backoff sequence, error-code → WP_Error
  mapping, and array/object normalisation.
- **Acceptance:** client tests + phpcs clean; fixture-mode tools return
  fixture data with no HTTP.

### Phase 2 — Test lifecycle + poller + store + report tools (6h)
- `WP_MCP_AI_GTmetrix_Poller` — `schedule_poll( $test_id, $report_hook )`
  enqueues one-shot Action Scheduler action `wp_mcp_ai_gtmetrix_poll` at
  `+poll_interval_s`; `poll( $test_id, $attempt )`:
  - `get_test()` → `queued`/`started`: reschedule at +3 s if
    `attempt < poll_max_attempts`; `completed`: fetch report, snapshot,
    fire `wp_mcp_ai_gtmetrix_report_ready`; `error`: store error state, fire
    `wp_mcp_ai_gtmetrix_test_error` with the API `error` string.
  - Single-flight per test_id via 5-min transient lock (`set_transient`
    guarded — stale locks expire on their own).
  - Pre-start concurrency guard in `start_test()`: count queued/started tests
    via `list_tests( filter[state]=queued,started )` vs plan cap (2 Basic / 8
    PRO from `/status` account_type) → fail with `wp_mcp_ai_gtmetrix_concurrency`.
- `WP_MCP_AI_GTmetrix_Store` (interface) + `WP_MCP_AI_GTmetrix_Snapshot_Store`
  — CPT `mcp_ai_gtmetrix_report` (title `url — Y-m-d H:i`; meta: report_id,
  test_id, url, page_id, source, grade, all scores, all ms metrics, CLS,
  page_bytes, page_requests, resource_summary JSON, lighthouse audit subset
  (audit id → title, displayValue, score, _impactScore, _structureScore),
  created/expires); `save_report()`, `get_report( $report_id )`,
  `get_latest_for_url( $url )`, `list_for_url( $url, $limit )`,
  `prune()` (scheduled daily; honours `snapshot_retention_days`); 5-min
  transient `gtmetrix_report_{id}`; updates the §2.2 URL→page_id map.
- Tools #5–11:
  - `gtmetrix_start_test` — write: URL allowlist check (`wp_parse_url` host ∈
    `allowed_test_hosts`), budget preflight (`/status` credits ≥ cost of
    chosen report type + retention extras; cost returned and logged via cost
    tracker), param validation (`report` enum incl. comma form, `retention`
    1/6/12/24, `throttle` pattern `/^\d+\/\d+\/\d+$/`, `browser_width`/`height`
    both-or-neither, `simulate_device` only when PRO flag from status); starts
    test AND schedules poll; returns `test_id`, `state`, `credits_used`,
    `credits_left`, poll hint.
  - `gtmetrix_get_test_status` — read; maps state; on completed includes
    report_id; on error includes the API error string.
  - `gtmetrix_list_tests` — read; 24 h window; sort/filter passthrough with
    strict enum checks.
  - `gtmetrix_get_report` — read; merges API report + snapshot (snapshot
    preferred when present — survives GTmetrix's 1-month retention).
  - `gtmetrix_get_lighthouse_audits` — read; fetches `lighthouse.json`,
    normalises, optional `min_impact` filter (band labels), `limit` default
    25, returns audits with `_impactScore` band + `_structureScore`; no raw
    JSON blob to chat.
  - `gtmetrix_get_resource_summary` — read; per-type table + top offenders
    computed by the score engine's budget helpers.
  - `gtmetrix_list_report_resources` — read; links with `expires_24h: true`
    warnings (optimized-images, pagespeed-files); `analysis-options` and
    `net.har` listed as `downloadable: false, reason: sensitive`;
    `report.pdf` flagged with credit cost +2.0/+3.0 and never auto-fetched.
- `init.php` registers the CPT + `wp_mcp_ai_gtmetrix_poll` AS hook + prune
  cron.
- Tests: poller state machine (mock client: queued → started → completed →
  snapshot fired; error path; lock prevents double-poll; max-attempts
  exhaustion), store round-trip/latest/prune, tool envelopes fixture-driven.
- **Acceptance:** fixture lifecycle from `start_test` through snapshot passes;
  poller tests green.

### Phase 3 — Score engine + analysis tools (5h)
- `WP_MCP_AI_GTmetrix_Score_Engine` — pure static methods, no I/O:
  - `assess_metric( $metric, $value )` — thresholds map from §3 oracle
    (LCP 2500/4000, CLS 0.1/0.25, TBT 200/600, TTFB 600/1800, speed_index
    3400/5800, onload/fully_loaded guidance bands); returns
    `good | needs-improvement | poor | unknown` + threshold context.
  - `assess_cwv( $report )` — per-metric status + overall `pass | partial |
    fail` + explicit note that **INP is field-data only; TBT is the lab
    proxy** (never claim INP from lab data — honesty rule from the
    performance-audit skill).
  - `band_grade( $score_0_1 )` — A–F with exact boundaries 0.9/0.8/0.7/0.6/0.5
    (`>=` on the band floor; F below 0.5).
  - `budget_class( $bytes, $requests )` — lean (≤ 1 MB AND ≤ 50), acceptable
    (≤ 2.5 MB AND ≤ 90), heavy, very_heavy (> 5 MB OR > 150); returns deltas
    to the next class down.
  - `lcp_phases( $lighthouse )` — reads the LCP element audit's
    ttfb/loadDelay/loadTime/renderDelay; falls back to TTFB + (LCP − TTFB)
    flagged `estimate` when the breakdown is absent (legacy reports).
  - `impact_order( $audits )` — desc by `_impactScore`; band label per
    GTmetrix's table; null-score diagnostics segregated.
  - `report_delta( $before, $after )` — per-metric verdicts: score ±5,
    LCP ±20 %, CLS ±0.05, bytes ±10 %, requests ±15 %; `regressed |
    improved | steady` with both values.
- Tools #12–13:
  - `gtmetrix_compare_reports` — args: `report_id_a`, `report_id_b`, OR
    `url` + auto-pair (latest vs previous snapshot); output: verdict table +
    per-CWV-metric deltas + note when the pair spans different analysis
    options (not a true before/after).
  - `gtmetrix_analyze_report` — args: `report_id` or `url` (auto-latest);
    output: grades + scores, CWV assessment, budgets, LCP phases, top-N
    impact-ordered audits, `fix_summary` from the fix engine (Phase 4
    dep — Phase 3 ships a placeholder listing matched audit IDs only).
- Tests: `test-gtmetrix-score-engine.php` against `expected-values.php`
  (every row; boundary values included); analysis-tool envelope tests.
- **Acceptance:** `smoke-run.php` prints PASS for every oracle row.

### Phase 4 — Fix engine + WPCode bridge (6h)
- Developer-toolkit refactor first (isolate, don't duplicate):
  - Extract `WP_MCP_AI_Pro_WPCode_Snippet_Repository`
    (`save( $data )`, `get( $id )`, `list( $args )`,
    `validate_location( $location, $code_type )`) from
    `class-wp-mcp-ai-pro-tool-create-wpcode-snippet.php`; the existing tool
    delegates to it (zero behaviour change — existing tests must stay green).
  - Add `conditional_logic` arg to `create_wpcode_snippet` (groups → rules:
    `rule_type`, `operator`, `value`) — persisted through `WPCode_Conditional_Logic`
    if the live plugin exposes it; **verify the class shape against the real
    plugin first** (admin access required) and fall back to
    `WPCode_Snippet` meta if the shape differs; the repository returns the
    exact JSON it stored either way.
  - New read tool `list_wpcode_snippets` (tag filter, active filter) for
    dedupe checks.
- `WP_MCP_AI_GTmetrix_Fix_Engine` — static `fixes()` taxonomy encoding
  Appendix B of the proposal (audit IDs, symptom, action, target, risk,
  block name, WPCode code_type, suggested conditional-logic rule);
  `match( $audits, $context )` walks impact-ordered failing audits → `fix[]`
  records; groups `unfixable` (third-party, no TTL control) and `unknown`
  separately — **never invent a fix for an unmapped audit**;
  `generate_snippet( $fixes, $context )` — assembles the PHP string from
  block templates: safe blocks enabled, risky blocks commented with
  `TEST ON STAGING` warnings, header carries report ID + deploy instructions
  (WPCode → Add Snippet → PHP → Auto Insert → Run Everywhere; activate
  blocks one at a time and re-test).
- `WP_MCP_AI_GTmetrix_WPCode_Bridge` — `install_draft( $fixes, $title,
  $report_id )`: generates via fix engine → saves via the repository with
  tag `gtmetrix-fix`, note `GTmetrix report {id}`, `active` always false →
  returns snippet_id + admin edit URL; `suggested_conditional_logic( $fix )`
  → rule JSON for the manual-paste fallback.
- Tools #14–15: `gtmetrix_generate_wpcode_snippet` (read-only; returns code,
  block list, risk summary, deploy notes), `gtmetrix_install_wpcode_fix`
  (write; `WP_MCP_AI_Tool_Restrict_From_Chat_Client`;
  `wpcode_edit_snippets` capability; drafts only).
- Tests: taxonomy completeness (every row has audit_id/action/risk/block);
  matcher maps fixture audits → expected fixes with zero invented blocks;
  golden-file snippet (contains only matched blocks; risky blocks commented);
  bridge availability-gated (`maybe_skip()` when WPCode absent);
  conditional-logic schema validation; existing developer-tool tests green.
- **Acceptance:** golden-file + regression tests green.

### Phase 5 — Pages / monitoring / alerts / tags (5h)
- Tools #16–23 (client methods already exist from Phase 1 — this phase is
  validation + envelopes + tests):
  - `gtmetrix_list_pages` — filters: `url` (fuzzy, passthrough),
    `monitored`, `page_tag`; joins WP-side labels from §2.2 map.
  - `gtmetrix_get_page` — page + `latest-report` + recent history (limit).
  - `gtmetrix_retest_page` — same budget/allowlist gate as #5; reuses poller.
  - `gtmetrix_set_monitoring` — `frequency` enum
    `no|none|hourly|daily|weekly|monthly`; maps E40303 plan-limit errors to
    actionable messages (hourly not on plan, location/browser not
    monitorable, monitoring slots exhausted).
  - `gtmetrix_get_alert_conditions` / `gtmetrix_set_alert_conditions` —
    metric IDs validated against the Appendix A.3 enum; operator ∈ `<` `=`
    `>`; max 10 conditions; `value` ≥ 0; `send_to` passed through only when
    account is Team (surface E40009/E40010/E40011 mapping); full-replace
    semantics documented in the tool description (the API replaces the whole
    set).
  - `gtmetrix_get_alert_events` — paginated, most-recent-first passthrough;
    error entries surfaced with `error_slug`.
  - `gtmetrix_manage_page_tags` — action enum
    `list|create|update|attach|detach` (name ≤ 45 chars, description ≤ 255,
    color hex without `#` — validated client-side too); deletes excluded
    from the enum in Phase 1 (proposal decision §7.6).
- Tests: validation matrices (invalid metric/operator/count/negative value;
  non-Team send_to rejection; tag name length/color), page fixture flows,
  attach/detach round-trip.
- **Acceptance:** matrices green; E40303 messaging asserted.

### Phase 6 — Presets + assistant pack + registration sweep (4h)
- `WP_MCP_AI_GTmetrix_Scheduler` — `core_pages()` (settings registry),
  `enqueue_sweep( $report_type )` (metrics-only default → 0.6 credits/page),
  `monthly_review()` (full Lighthouse) — both respect the daily budget and
  fail-closed with a logged notice when exhausted.
- Schedule presets — add `self::get_gtmetrix_presets()` to the
  `WP_MCP_AI_Pro_Schedule_Presets::get_presets()` merge:
  - `gtmetrix_weekly_sweep` — weekly, `schedule_type` task, hook
    `wp_mcp_ai_gtmetrix_scheduled_sweep` (wired to the scheduler).
  - `gtmetrix_monthly_review` — monthly, task, hook
    `wp_mcp_ai_gtmetrix_monthly_review`.
- Workflow presets — `WP_MCP_AI_Pro_Workflow_Presets` merge + methods,
  each with **name, description, category, icon, tags, nodes, edges** (edges
  is CI-enforced):
  - `gtmetrix_audit_fix_pipeline` — input(url) → `gtmetrix_start_test` →
    `gtmetrix_get_test_status` → `gtmetrix_analyze_report` →
    `gtmetrix_generate_wpcode_snippet` → condition(risky blocks present?) →
    `gtmetrix_install_wpcode_fix` → output.
  - `gtmetrix_deploy_regression_gate` — input(page) → `gtmetrix_retest_page`
    → poll → `gtmetrix_compare_reports` → condition(verdict regressed?) →
    output(pass/fail + delta).
- Assistant pack — `assistant-packs/gtmetrix-performance-optimizer.json` +
  seeder entry (`wp mcp-ai pro gtmetrix seed-assistant`): read tools + #14,
  #15 excluded by default; system prompt anchors CWV thresholds, grade
  bands, credit discipline, staging-first rule.
- Registration sweep — the full checklist (§6) including both coverage
  manifests via `bin/generate-tool-coverage-manifest.sh` (only additions of
  the new classes should appear in the diff).
- Docs — `docs/toolkits/gtmetrix-performance.md` (tool reference, setup
  guide: API key → settings → core pages → presets), README.md, changelog
  + version bump per `mcp-ai-wpoos-updates`.
- Tests: preset stub tests (define ABSPATH, stub `__`/`sanitize_key`/
  `get_option`), assert `get_presets_by_toolkit( 'gtmetrix' )` returns both
  schedule presets and workflow `get_presets_by_category( 'gtmetrix' )`
  returns both workflows with valid edges; coverage CI green.
- **Acceptance:** `test-pro-workflow-presets.php` +
  `test-pro-schedule-presets.php` + `test-tool-registry-coverage.php` green
  in Docker.

### Phase 7 — Validation gate (3h)
1. `php -l` every new/changed file.
2. `vendor/bin/phpcbf --standard=WordPress <paths>` then
   `vendor/bin/phpcs --standard=WordPress <paths>` clean — includes the
   custom sniffs (`WPMCPAI.Tools.CanonicalReturnEnvelope`,
   `WPMCPAI.Tools.SanitizeAtEntry`) and the WordPress-Docs docblock rules
   (`{@inheritdoc}` where the interface declares the contract).
3. `php addons/pro/includes/tools/gtmetrix/tests/smoke-run.php` → every
   oracle row PASS.
4. Docker PHPUnit green (per `mcp-ai-wpoos-test-suite`) — toolkit tests +
   preset tests + coverage manifests + existing `test-pro-workflow-presets.php`
   and developer-toolkit tests (the refactor must not regress them).
5. `mcp-ai-wpoos-toolkit-audit` four-failure-class scan: no unguarded shell
   calls, no calls to nonexistent client methods, **array-safe parsing of
   every JSON:API response** (`data` object-vs-array — the §2.3
   normalisation is the fix), provider enum/service mismatches (report type
   vs browser lighthouse support).
6. Preset sanity with minimal stubs; registry coverage manifests committed.
7. PR against `alpha-working` with the proposal + plan linked; ecosystem-port
   decision (open question 7 of the proposal) resolved before merge — if
   porting, run the Wave-style port in the same window; self-merge per repo
   workflow.

---

## 6. Registration Checklist (the 7 surfaces + manifests)

| # | Surface | File | Change |
|---|---|---|---|
| 1 | Central registry | `addons/pro/mcp-ai-wpoos-pro.php` → `wp_mcp_ai_pro_register_tools()` | Gated block: `if ( ! empty( $settings['enable_gtmetrix_toolkit'] ) ) { $pro_tools = array_merge( $pro_tools, $gtmetrix_map ); }` with class → file pairs for all 23 tools |
| 2 | Admin Tools section | `includes/admin/sections/class-wp-mcp-ai-section-tools.php` | `enable_gtmetrix_toolkit` checkbox entry (label, checkbox_label, description, default false) |
| 3 | Settings status map | `includes/admin/class-wp-mcp-ai-pro-settings.php` | Toolkit label map (~line 224) + status block (name, description, enabled, category, tools_count, tools[]) |
| 4 | WP-CLI toolkit command | `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-toolkit-command.php` | `TOOLKITS` const entry |
| 5 | WP-CLI status command | `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-status-command.php` | `$toolkit_keys` entry |
| 6 | Schedule presets | `addons/pro/includes/class-wp-mcp-ai-pro-schedule-presets.php` | `get_gtmetrix_presets()` + merge line |
| 7 | Workflow presets | `addons/pro/includes/class-wp-mcp-ai-pro-workflow-presets.php` | Merge + two preset methods with full key set incl. `edges` |
| 8 | Coverage manifests | `bin/generate-tool-coverage-manifest.sh` | `tests/tools/.coverage-manifest.txt` (no change — Pro tools only) + `addons/pro/tests/tools/.coverage-manifest.txt` (+23 basenames; additions only) |
| 9 | Assistant pack | `addons/pro/presets/assistant-packs/gtmetrix/` + seeder | Pack JSON + `wp mcp-ai pro gtmetrix seed-assistant` subcommand |

Settings entry (`wp_mcp_ai_gtmetrix_settings`) itself lives in the toolkit
folder via `init.php` defaults; the API key reuses the existing encrypted
store — **no new key option**, the settings UI references
`gtmetrix_api_key` in the existing security/API-key settings surface.

## 7. Known Gotchas for This Build

- **JSON:API `data` shape variance** (toolkit-audit class 3) — the #1 risk:
  `/tests` and `/pages` return `data: []`, single-resource endpoints return
  `data: {}`. All parsing goes through `normalise_datum()`; tools never
  `$body['data']['attributes']` directly.
- **Credit floats** — `credits_used: 2.8`, `1.1` are floats; budget math must
  use float comparison with epsilon, not int casts.
- **303 on test completion** — `wp_remote_request` follows redirects by
  default for GET; assert the client resolves the final report URL and
  records the report_id from the Location header (fixture tests must
  simulate the 303 explicitly since `pre_http_request` mocks return a single
  response).
- **`stop_onload` sets `fully_loaded_time` to −1** — the engine treats −1 as
  `unknown`, never as a 1 ms improvement.
- **Report type vs browser capability** — legacy reports are not available on
  every browser (`browsers[].lighthouse` flag); `start_test` validates the
  chosen `report` against the browser's flags before spending credits.
- **AS poller in tests** — Action Scheduler functions must be stubbed
  (`as_enqueue_async_action`, `as_next_scheduled_action`); the poller's state
  machine logic is extracted into a pure `advance( $state, $attempt )` method
  so tests never need the real queue.
- **WPCode conditional-logic shape** — verify against the live plugin before
  committing to `WPCode_Conditional_Logic`; the repository returns the stored
  JSON regardless, so the tool contract stays stable if the plugin's API
  differs.
- **phpcs**: `@param $default` reserved-name rule (use `$fallback`), one
  object structure per file (keep the store interface in its own file),
  Yoda conditions, hoisted `count()` out of loop tests.

## 8. Rollout Workflow

1. Branch `068-gtmetrix-wpcode-toolkit` off `alpha-working`; phases land as
   separate commits with the phase gate verified before moving on.
2. Fixtures + tests committed with every phase (no code without its test).
3. Phase 7 PR: proposal + plan + implementation linked; CI (phpcs, phpunit,
   coverage) green; toolkit-audit scan documented in the PR body.
4. Post-merge follow-ups tracked in the proposal's open questions (§8):
   live-key smoke test against a real GTmetrix account, Breeze bridge
   evaluation, ecosystem-port decision, and the conditional-logic live-shape
   verification if it could not be completed in Phase 4.
