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

## Remaining PRs

The ordering below is chosen so every PR leaves the plugin in a
shippable state and each PR's tests can exercise its own surface
without depending on later PRs.

### Prioritization (confirmed April 2026)

PR 7 (chat-loop token & cost) is the committed next PR, ahead of
both PR 8 (SSE) and PR 9 (persistent store). Rationale:

- **Highest user-visible value per diff.** Token and cost are the
  two measurements operators ask for first; PR 6 covers tool
  latency but not model spend.
- **No downstream dependency.** Chat-loop emission writes through
  the same `Metric_Collector` ring buffer PR 6 uses, so PR 9's
  persistent store is not a prerequisite — when PR 9 lands the
  events flow through automatically.
- **Provider-normalization work unblocks PR 8.** The cost/token
  adapter layer PR 7 introduces for OpenAI/Gemini/Ollama is reused
  by the SSE observer in PR 8 to attribute stream timings to a
  provider.

PR 9 (persistent store) is intentionally sequenced after PRs 7–8 so
the store lands with real production emission traffic shaping its
schema, rather than being sized against PR 6 alone. PR 11 (CLI
runner and regression alerting) remains blocked on PR 9.

### ▶️ PR 7 — Chat-loop token & cost instrumentation

**Goal:** close the second largest observability gap — the REST chat
loop and agentic iterations — so cost/latency metrics reach the
collector. PR 6 instruments individual tool calls; PR 7 instruments
the turn that orchestrates them.

Scope:

- Stock metrics: `token_usage.prompt_tokens`,
  `token_usage.completion_tokens`, `token_usage.total_cost_usd`,
  `chat.turn.duration_ms`, `chat.turn.error.count`,
  `chat.agentic.iterations`
- Observer attached to the existing
  `wp_mcp_ai_before_chat_request` / `wp_mcp_ai_after_chat_response`
  hooks and the agentic-loop iteration hook
- Cost calculation routed through the existing
  `wp_mcp_ai_cost_calculated` hook rather than duplicating rate
  tables
- Privacy: no prompt content ever leaves scope; only provider,
  model, assistant_id, user_id

### ⬜ PR 8 — SSE/stream instrumentation

**Goal:** surface streaming health — TTFB, chunk cadence, and
client-cancellation rates.

Scope:

- Stock metrics: `stream.ttfb_ms`, `stream.chunk_interval_ms`,
  `stream.total_duration_ms`, `stream.cancelled.count`,
  `stream.error.count`
- Observer wired into the SSE dispatcher's existing lifecycle hooks
- Privacy: chunk content never recorded; only timing + outcome

### ⬜ PR 9 — Persistent metric store

**Goal:** move buffered events from in-memory ring buffer to a
custom table so the dashboard (PR 3/4) can display cross-request
history.

Scope:

- Custom table `wp_mcp_ai_metric_events` with schema-versioned
  migration
- Persister attached to `wp_mcp_ai_metric_recorded`; buffers async
  per request, flushes on shutdown
- Retention controller: TTL per privacy tier
  (Public 365d / Internal 90d / Sensitive 30d / Restricted 7d)
- Dashboard gains time-range filter and sparkline renders

### ⬜ PR 10 — Rubric verifier suite & counterfactual tests

**Goal:** give the pro rubric verifier a library of ready-made
rubrics and a small counterfactual test helper so sites can adopt
evals without authoring rubrics from scratch.

Scope:

- Prompt-adherence, JSON-schema, citation-presence rubrics
- Counterfactual helper: for each eval case, verifier receives both
  the candidate and a shuffled/degraded variant; failure to prefer
  the candidate flags measurement invalidity
- Expansion of the base eval harness with `run_counterfactual()`

### ⬜ PR 11 — CLI runner & regression alerting

**Goal:** run eval suites from CI or WP-CLI without the web runtime
and alert on cross-run metric regressions.

Scope:

- `wp mcp-ai measurement run <suite>` command
- `wp mcp-ai measurement alert-check` — compares the latest N runs
  against a baseline, emits a non-zero exit on regression
- Stock metrics: `eval.suite.regression.count`,
  `eval.suite.pass_rate`
- Optional webhook sink for alerts

### ⬜ PR 12 — GA polish

**Goal:** everything a site needs to operate the subsystem
confidently in production.

Scope:

- Uninstall hygiene (drop tables under `wp_mcp_ai_uninstall_delete_data`)
- Admin help-tabs on dashboard screens
- Example rubrics + example custom verifier under
  `assets/examples/measurement/`
- Release notes + upgrade notice template in the main `readme.txt`

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
