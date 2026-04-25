# Measurement Subsystem — Rollout Plan

> **Audience:** maintainers and reviewers tracking the multi-PR
> delivery of the measurement subsystem.
> **Source of truth** for what has shipped, what is in flight, and
> what remains. Update this document in the same PR that changes its
> status.

The measurement subsystem is delivered as a series of **small,
independently-reviewable PRs** rather than a single drop. Each PR
leaves the plugin in a production-safe state: code merged before the
final PR is loaded but inert unless the earlier PRs have shipped, or
it exposes capability that later PRs consume.

Scope is bounded by two architectural commitments:

1. **Goodhart-aware design** — every metric declares a
   `counter_metric` pairing; every reward function declares
   anti-gaming safeguards; the registry audits both invariants.
2. **Verifier's law** — verifiers are registered with an independence
   profile so generators cannot be graded by their own output
   pipeline.

## Status legend

- ✅ Shipped and merged to `alpha-working`
- 🟡 In flight (open PR)
- ▶️ Next up — committed as the next PR after the current in-flight one
- ⬜ Planned — not started

## Delivered PRs

### ✅ PR 1 — Core primitives

Commit: `5806044` · Doc: [`README.md`](README.md)

- `WP_MCP_AI_Measurement_Registry` (singleton, frozen after `boot`)
- `WP_MCP_AI_Metric_Collector` (ring buffer, deterministic sampling,
  `wp_mcp_ai_metric_recorded` action, `wp_mcp_ai_measurement_export`
  filter)
- `WP_MCP_AI_Verifier` interface + base class
- `WP_MCP_AI_Verifier_Registry` (independence-profile enforcement,
  `wp_mcp_ai_verifier_result` action)
- `WP_MCP_AI_Reward_Function_Registry` (mandatory anti-gaming
  safeguards)
- Bootstrap singleton wiring at `plugins_loaded` priority 50
- Capabilities `view_wp_mcp_ai_measurements`,
  `manage_wp_mcp_ai_measurements` (filterable via
  `wp_mcp_ai_measurement_admin_capabilities`)

### ✅ PR 1b — Review cleanup

Commit: `faea3de`

- Hash-safety fix in collector sampling
- Regex hardening in registry normalization
- Test-assertion tightening
- Helper deduplication

### ✅ PR 2 — Reference verifiers & reward functions

Commit: `482c238` · Docs: [`verifier-authoring.md`](verifier-authoring.md),
[`reward-authoring.md`](reward-authoring.md)

- Rule verifier, schema verifier, LLM-judge verifier (registered
  at priority 20 — site overrides at priority 10 pre-empt them)
- Reference reward functions (task success, schema-adherence,
  citation-quality) with anti-gaming safeguards
- `wp_mcp_ai_enable_reference_verifiers` filter to disable any of the
  reference set

### ✅ PR 3 — Eval harness & read-only admin dashboard

Commit: `0766c39` · Docs: [`eval-harness.md`](eval-harness.md),
[`dashboard.md`](dashboard.md)

- `WP_MCP_AI_Eval_Case`, `WP_MCP_AI_Eval_Suite`,
  `WP_MCP_AI_Eval_Suite_Registry`, `WP_MCP_AI_Eval_Runner`
- Read-only measurement dashboard under **Tools → Measurements**

### ✅ PR 4 — Budget envelopes & OTel JSON exporter

Commit: `28f1269` · Docs: [`budgets.md`](budgets.md),
[`otel-exporter.md`](otel-exporter.md)

- `WP_MCP_AI_Budget_Envelope` + `WP_MCP_AI_Budget_Registry` (attached
  to collector events; throws on exceed)
- OTel/OTLP-JSON exporter with rolling buffer
- Dashboard gains writable actions (sample-rate edit, suite run,
  exporter flush)

### ✅ PR 5 — Pro toolkit seed

Commit: `96f5609` · Doc:
[`../../addons/pro/docs/measurement-pro.md`](../../addons/pro/docs/measurement-pro.md)

- `WP_MCP_AI_Pro_Rubric_Verifier` (weighted multi-criteria)
- `WP_MCP_AI_Pro_Budget_Guarded_Reward` (wraps any reward; zeroes
  output when its budget envelope is exceeded)
- Pro measurement bootstrap (auto-register when pro addon loads)

### ✅ PR 5b — Vendor churn cleanup

Commit: `9d8ae44`

### ✅ PR 6 — Tool-execution instrumentation

Commit: `6836f2a` · Doc: [`tool-execution.md`](tool-execution.md)

First code path in the plugin that actually emits metrics from live
traffic. Without PR 6 the infrastructure from PRs 1–5 has no inputs.

- `WP_MCP_AI_Stock_Metrics` — five baseline tool-execution metrics
  with Goodhart pairings
- `WP_MCP_AI_Tool_Execution_Observer` — invocation-stack-based timer
  hooked at `before` priority 5 / `after` priority 95
- Context payload locked to Internal privacy tier
- Opt-outs via `wp_mcp_ai_stock_metrics_definitions` and
  `wp_mcp_ai_tool_execution_observer_enabled`

### ✅ PR 7 — Chat-loop token & cost instrumentation

Doc: [`chat-turn.md`](chat-turn.md)

Second live emission path. PR 6 instruments individual tool calls;
PR 7 instruments the chat turn that orchestrates them — token usage,
realised cost (USD), turn duration, and success/error outcome.

- `WP_MCP_AI_Chat_Turn_Metrics` — seven baseline metrics
  (`chat.turn.count`, `chat.turn.error.count`,
  `chat.turn.duration_ms`, `token_usage.prompt_tokens`,
  `token_usage.completion_tokens`, `token_usage.total_cost_usd`,
  plus the reserved `chat.agentic.iterations` id) with Goodhart
  pairings enforced as a test invariant
- `WP_MCP_AI_Chat_Turn_Observer` — assistant_id-keyed invocation
  stack hooked at `wp_mcp_ai_before_chat_request` priority 5 /
  `wp_mcp_ai_after_chat_response` priority 95 /
  `wp_mcp_ai_cost_calculated` priority 95
- Cost routed through the existing `wp_mcp_ai_cost_calculated`
  hook — no duplicated rate tables
- Privacy: prompts, completions, API keys, system messages and
  attachments are never recorded; only provider, model,
  assistant_id, user_id, guest-flag (asserted by a string-scan test)
- Opt-outs via `wp_mcp_ai_chat_turn_metrics_definitions` and
  `wp_mcp_ai_chat_turn_observer_enabled`

**Deferred from PR 7:** `chat.agentic.iterations` is registered but
not emitted by the shipped observer — the REST agentic loop does
not currently expose a per-iteration action hook. Adding that hook
is a one-line core change that ships as a separate PR so the
measurement PR does not bundle a core-surface change. The metric
id is reserved here so the future emitter does not need to
coordinate an id rename.

## Remaining PRs

The ordering below is chosen so every PR leaves the plugin in a
shippable state and each PR's tests can exercise its own surface
without depending on later PRs.

### Prioritization (confirmed April 2026)

With PR 7 shipped, PR 8 (SSE/stream instrumentation) is next,
reusing the provider/model attribution pattern PR 7 introduced.
PR 9 (persistent store) follows PR 8 so the store lands with real
production emission traffic shaping its schema. PR 11 (CLI runner
and regression alerting) remains blocked on PR 9.

### ✅ PR 7.1 — Agentic-loop iteration hook (core) + emitter

Standalone base-plugin PR that adds a
`do_action( 'wp_mcp_ai_agentic_iteration_complete', $iteration, $assistant_id )`
call at both agentic-loop sites in `class-wp-mcp-ai-rest.php` (the
non-streaming path and the SSE/streaming path). The shipped chat-turn
observer now consumes this hook, tracks the per-assistant maximum
iteration count during the current turn, and emits one
`chat.agentic.iterations` histogram sample when the matching
`wp_mcp_ai_after_chat_response` pops the frame. Turns with no tool
calls emit nothing — this keeps the histogram free of synthetic zeros.
Tests cover the happy path, the no-iterations path, and nested
assistant invocations with independent counts.

### ✅ PR 8 — SSE/stream instrumentation

**Shipped.** See [`sse-stream.md`](sse-stream.md).

Delivered scope:

- `WP_MCP_AI_SSE_Metrics` — 7 stock metric definitions
  (`stream.count`, `stream.error.count`, `stream.cancelled.count`,
  `stream.ttfb_ms`, `stream.chunk_interval_ms`,
  `stream.total_duration_ms`, `stream.chunks.count`). Each
  declares a Goodhart counter pairing; all stay in the Internal
  privacy tier.
- `WP_MCP_AI_SSE_Observer` — job-keyed frame map; records TTFB on
  the first chunk, chunk intervals on subsequent chunks, duration
  and chunk count on stream end. Idempotent `attach()` / `detach()`.
- **Three new lifecycle hooks in the base plugin**
  (`wp_mcp_ai_sse_stream_started`, `…_chunk_sent`, `…_stream_ended`).
  Pure notification hooks — no behaviour change to the SSE
  dispatcher. These were bundled into PR 8 because they are
  narrowly scoped and trivially small; the broader `tool_raised`
  exception hook remains deferred to its own PR.
- **Cancellation is a first-class outcome, not an error.** The
  observer routes `cancelled_by_client` and `cancelled_by_job` to
  `stream.cancelled.count`; only `failed` increments
  `stream.error.count`. Enforced by
  `Test_WP_MCP_AI_SSE_Observer::test_client_cancellation_is_not_error`.
- Privacy: only `job_id` (sanitised) and `outcome` (fixed
  vocabulary) are recorded; chunk content and status payloads
  never leave the dispatcher. Enforced by
  `test_privacy_canary_no_payload_leakage`.
- Tests: 20 new (7 metric, 13 observer) covering every outcome
  branch, concurrent-stream routing, sanitisation, detach / opt-out.

### ✅ PR 9 — Persistent metric store — *2026-04-24*

Delivered durable cross-request persistence so dashboards, CLI
runners, and alerting can read history beyond a single request.

- `WP_MCP_AI_Metric_Event_Store`: custom `{prefix}mcp_ai_metric_events`
  table, schema-versioned via `dbDelta` + the
  `wp_mcp_ai_metric_events_schema_version` option. Batched INSERTs
  (200 rows per statement) keep us below `max_allowed_packet`.
  Bounded queries (`LIMIT` clamped to `[1, 5000]`), tier-scoped
  purge, privacy-keyed counts.
- `WP_MCP_AI_Metric_Persister`: attaches to
  `wp_mcp_ai_metric_recorded`, appends to an in-memory buffer
  (cap via `wp_mcp_ai_persister_buffer_max`, default 2048), and
  fires a single INSERT on `shutdown`. Restricted events are
  dropped **before buffering** — the store has a defensive
  second barrier.
- `WP_MCP_AI_Metric_Retention`: daily
  `wp_mcp_ai_metric_retention_purge` cron, defaults
  Public 365d / Internal 90d / Sensitive 30d;
  Restricted is never persisted (reconciled with
  `privacy-matrix.md` — the rollout-plan's original 7d Restricted
  retention was superseded by the stronger "never persisted"
  invariant and is documented accordingly).
  Filterable via `wp_mcp_ai_measurement_retention`.
- Wired into activation (schema install + cron schedule) and
  `plugins_loaded` (idempotent install as belt-and-braces for
  `wp plugin update` upgrades).
- Tests: 27 new covering schema idempotency, roundtrip queries,
  time-bounded queries, LIMIT clamping, chunked writes,
  Restricted drop at both barriers, unknown-tier coercion,
  per-tier purge, completion action, retention clamping, cron
  schedule idempotency, buffer cap, veto + disabled filters.

### ✅ PR 9.1 — Dashboard time-range + persisted-metrics panel

New "Persisted Metrics" section on the measurement dashboard backed
by the PR 9 event store. Adds:

- Metric picker populated from the measurement registry.
- Time-range selector (1h / 24h / 7d / 30d) persisted in the URL so
  links are shareable and the panel is stateless between requests.
- Per-privacy-tier row-count summary driven by
  `WP_MCP_AI_Metric_Event_Store::count_by_privacy()`.
- Server-rendered inline SVG sparkline bucketed into 24 equal-width
  slots. Each bucket reports the arithmetic mean of samples that
  fell inside it; empty buckets report `0.0` so quiet stretches read
  as flat rather than interpolated. Flat-line guard prevents
  zero-height SVGs when `min == max`. No client-side JS, no XHR —
  works under strict CSP policies.
- Pure-static `bucket_events()` helper for testability with 5
  PHPUnit tests covering empty input, bucket assignment, out-of-range
  drop, flat-line guard, and bucket-count clamping. All 105
  measurement tests remain green.

### ✅ PR 10 — Rubric verifier suite & counterfactual tests

**Goal:** give the pro rubric verifier a library of ready-made
rubrics and a small counterfactual test helper so sites can adopt
evals without authoring rubrics from scratch.

Scope:

- Prompt-adherence, JSON-schema, citation-presence rubrics shipped as
  `WP_MCP_AI_Pro_Rubric_Presets` (3 factories, each filterable via
  `wp_mcp_ai_pro_{slug}_criteria`).
- `WP_MCP_AI_Counterfactual_Runner` — pairs every eval case with a
  shuffled/degraded variant; failure to prefer the candidate flags
  measurement invalidity.
- `WP_MCP_AI_Eval_Runner::run_counterfactual()` extends the harness
  with a one-call counterfactual sweep, persisting separate
  `eval.case.preferred` and `eval.suite.counterfactual.score` metrics
  so a site can graph counterfactual stability alongside pass rate.

### ✅ PR 11 — CLI runner & regression alerting

**Goal:** run eval suites from CI or WP-CLI without the web runtime
and alert on cross-run metric regressions.

Scope:

- `wp mcp-ai measurement run <suite>` — runs a registered suite using
  a generator callable resolved via the
  `wp_mcp_ai_cli_measurement_generator` filter. Persists the run
  summary (`WP_MCP_AI_Eval_Run_Store`) and emits the
  `eval.suite.pass_rate` gauge tagged by suite slug. `--no-persist`
  supports ad-hoc smoke runs that should not influence baselines.
- `wp mcp-ai measurement alert-check <suite>` — reads the trailing N
  runs (`--window`, default 10), feeds the most-recent run + baseline
  through `WP_MCP_AI_Eval_Regression_Detector`, exits 2 if any rule
  triggers. Threshold overrides via `--pass-rate-drop`,
  `--error-rate-rise`, `--abstention-rate-rise`. Emits
  `eval.suite.regression.count` once per offending metric tagged
  with both `suite` and `metric` so dashboards can break regressions
  down by dimension.
- `wp mcp-ai measurement list-runs <suite>` — prints persisted runs.
- Optional webhook sink (`--webhook=<url>`) POSTs the alert payload
  as JSON. Network failures emit a warning but never override the
  regression exit code so CI cannot silently mark a regression as
  green.
- New stock metrics: `eval.suite.pass_rate` (gauge, higher-is-better)
  and `eval.suite.regression.count` (counter, lower-is-better) — both
  registered through the existing `wp_mcp_ai_register_metrics` hook
  and surfacable in the persisted-metrics panel from PR 9.1.
- `WP_MCP_AI_Eval_Run_Store` — per-suite option-backed history
  (`wp_mcp_ai_eval_runs__<slug>`), capped at 100 records (filterable
  via `wp_mcp_ai_eval_run_store_max_runs`). JSON-encoded for cheap
  uninstall hygiene, JSON-corruption tolerant on read.
- `WP_MCP_AI_Eval_Regression_Detector` — pure helper (no WP, no DB)
  with three independent rules: pass-rate drop, error-rate rise,
  abstention-rate rise. Cold-start contract: empty baseline → never
  a regression. Missing summary fields are treated as `0.0` so a
  silent field drop cannot dodge the alarm.
- 16 new PHPUnit tests (9 detector + 7 run-store). All measurement
  tests remain green.

### ✅ PR 12 — GA polish

**Goal:** everything a site needs to operate the subsystem
confidently in production.

Scope:

- Uninstall hygiene: the PR 9 `{prefix}mcp_ai_metric_events` table is
  added to the drop list in `wp_mcp_ai_uninstall_single_site()`. The
  existing `wp_mcp_ai_%` option-wildcard already covers run-store
  options (`wp_mcp_ai_eval_runs__<slug>`) and the schema-version
  option, so no extra option cleanup is required.
- Admin help-tabs on the **Tools → Measurement** dashboard screen:
  four shipped tabs (overview / metrics / privacy / CLI) plus a help
  sidebar linking to docs. Tabs are filterable as a single array via
  `wp_mcp_ai_measurement_help_tabs` so site authors can inject runbook
  links without subclassing.
- Reference snippets under `assets/examples/measurement/`:
  - `example-custom-verifier.php` — minimum-viable subclass of
    `WP_MCP_AI_Verifier_Base` with abstain-on-empty-input and a
    declared `independence_profile`.
  - `example-eval-suite.php` — registering a suite with two cases
    that reference the verifier.
  - `example-cli-generator.php` — wiring a generator callable for
    `wp mcp-ai measurement run` via the
    `wp_mcp_ai_cli_measurement_generator` filter.
- `readme.txt` — `1.2.0` Changelog entry covering PR 6–12 highlights
  and a new Upgrade Notice block calling out the new uninstall
  table-drop.
- PHPUnit coverage:
  - `Test_WP_MCP_AI_Measurement_Help_Tabs` — default-tabs landing,
    filter injection, malformed-tab skipping, and a no-screen safety
    case.
  - `Test_WP_MCP_AI_Measurement_Uninstall_List` — static assertion
    that `activation.php` enumerates the `mcp_ai_metric_events` table.

## Triggers for re-planning

This document must be revised (not just ticked) when any of the
following happen:

- A PR in the delivered list discovers scope it needs to offload to a
  later PR — document the cut-over here and update the affected PR's
  scope line.
- A new architectural commitment is added (e.g. new privacy tier,
  new invariant). Update `conventions.md` first, then propagate
  here.
- An out-of-band hotfix lands that changes the public shape of a
  measurement hook. Add a "Hotfix" entry between the affected PRs.

## Cross-reference

- [`README.md`](README.md) — primitives index
- [`conventions.md`](conventions.md) — naming + OTel mapping
- [`privacy-matrix.md`](privacy-matrix.md) — privacy tiers
- [`goodhart-checklist.md`](goodhart-checklist.md) — metric authoring
- [`../../addons/pro/docs/measurement-pro.md`](../../addons/pro/docs/measurement-pro.md) — Pro toolkit
