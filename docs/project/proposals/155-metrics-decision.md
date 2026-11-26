# Proposal 155 — Metrics without a registry: what `measurement` can honestly be

**Status:** decided 2026-11-19. Recommendation is **Option B** (a derived
catalogue); the build is slice M1 in §6. No code in this proposal.

**Related:** [Proposal 149](149-laravel-admin-parity-implementation-plan.md) §45
(the `measurement` contract row), §81/§84/§86 (token usage, harness traces,
estate scope), §85.2 (the three stores), §92–§95 (budgets, assistant scope, the
dataset browser), and [Proposal 154 §1](154-budgets.md) which split `measurement`
into three problems and built the one that needed no producer.

## 1. What the WordPress page actually is

`includes/admin/measurement/` renders a **Pro admin page** over five registries
plus a collector buffer, with two writes: clear the buffer, download a JSON
export. It is not a port of a WordPress idiom — it is the admin face of a
measurement framework (`includes/measurement/`, 19 classes) that is booted on
every request:

| Section | Reads | Who fills it |
|---|---|---|
| Metrics | `WP_MCP_AI_Measurement_Registry` | four core packs + `wp_mcp_ai_register_metrics` (third parties) |
| Verifiers | `WP_MCP_AI_Verifier_Registry` | core verifiers + filter |
| Rewards | `WP_MCP_AI_Reward_Function_Registry` | core rewards + filter |
| Eval suites | `WP_MCP_AI_Eval_Suite_Registry` | **nothing in core** — filter only |
| Budgets | `WP_MCP_AI_Budget_Registry` | core budgets + filter |
| Recent events | `WP_MCP_AI_Metric_Collector` (in-memory buffer) | four observers: chat turn, tool execution, SSE, session log |
| Sparklines | `WP_MCP_AI_Metric_Event_Store` (own table) | the collector's persister |

The metric vocabulary core ships by default is **21 definitions in four packs**:

| Pack | Metrics |
|---|---|
| chat (7) | `chat.turn.count`, `chat.turn.error.count`, `chat.turn.duration_ms`, `token_usage.prompt_tokens`, `token_usage.completion_tokens`, `token_usage.total_cost_usd`, `chat.agentic.iterations` |
| tool (5) | `tool.execution.count`, `.success.count`, `.error.count`, `.duration_ms`, `.in_flight` |
| stream (7) | `stream.count`, `.chunks.count`, `.error.count`, `.cancelled.count`, `.chunk_interval_ms`, `.ttfb_ms`, `.total_duration_ms` |
| eval (2) | `eval.suite.pass_rate`, `eval.suite.regression.count` |

So §85.2's "no producer" was right about *this* runtime and wrong as a general
statement: the feature has a producer on WordPress, and the question is which of
those producers this runtime already has in another form.

## 2. What this runtime already produces

Three existing stores carry the same facts, each built in its own slice:

| Store | Written by | Answers |
|---|---|---|
| `nvoos_token_usage` | `RecordTokenUsage` on `CostCalculated` (§81) | prompt/completion tokens, cost, model, estate, assistant (§94) |
| `nvoos_harness_trace_records` | `TraceCapture` (§84) | per-run `start`/`routing`/`iteration`/`end`: duration, provider calls, `tool_calls`, tools offered, cancellation, status |
| `nvoos_routing_metrics` | the router (§64/§66) | model fallbacks and cross-provider hops, success |

Mapping the WordPress vocabulary onto them:

| Metric | Here today |
|---|---|
| `token_usage.prompt_tokens` / `.completion_tokens` / `.total_cost_usd` | **derived** — `SUM` over `nvoos_token_usage` |
| `chat.turn.count` | **derived** — one trace run, or one token row, per turn |
| `chat.turn.duration_ms` | **derived** — `end.duration_ms` (already read by `TraceRuns`) |
| `chat.agentic.iterations` | **derived** — `iteration` records per run |
| `chat.turn.error.count` | **partial** — traces distinguish `aborted` and `incomplete`; a provider error that still returned a message is not recorded as one |
| `tool.execution.count`, `.success.count`, `.error.count`, `.duration_ms` | **derived** since §98 — one `tool` record per execution, in the shape OTel's `execute_tool` span uses |
| `tool.execution.in_flight` | **not produced** — a gauge needs a start record; the `tool` record is written when the call is over |
| `stream.*` (7) | **not produced** — the SSE handler observes chunks and TTFB but persists nothing |
| `eval.*` (2) | **not produced** — there is no eval harness on either runtime |

Ten derived, one partial, ten not produced. (§98 added the per-tool record, which
moved four of these; the rest of this proposal is the decision that record belongs
to.)

## 3. The options

| | What it means | Cost | Consequence |
|---|---|---|---|
| **A** | Port the framework: five registries, four observers, an event store, a persister, a retention job | Large — the largest remaining slice in this track | A **second telemetry path** beside three stores that already answer most of it. The observers would have to hook a chat pipeline that already emits `CostCalculated` and trace records, and the two would disagree the first time one was disabled. |
| **B** | **A derived catalogue**: definitions are code, values are queries over the existing three stores | Small — one reader, one page, no schema | No new store, no observers. The page can state exactly which metrics it *cannot* show and why, instead of showing zeroes. |
| **C** | Leave `measurement` planned | none | The row keeps claiming "no producer" while six of its metrics have one. That is the dishonest label §85.2 was written to avoid. |

## 4. Recommendation — B, with these decisions

| # | Decision | Why |
|---|---|---|
| **D1** | **A metric definition is code, not a row.** The catalogue is a class constant set, versioned with the runtime. | §85.2 assumed `nvoos_metrics` (name, type, unit, privacy tier, counter-metric). But a definition is not user data: nobody creates one at runtime, and a table would be a second place to edit a fact that ships in a release — the same objection §92 raised against a stored spend counter. |
| **D2** | **No new table.** Values are `SUM`/`COUNT` over `nvoos_token_usage`, `nvoos_harness_trace_records` and `nvoos_routing_metrics`, reusing the readers that already exist (`TokenUsage`, `TraceRuns`). | Three stores already agree with the cost and observability pages. A fourth would be a second truth on day one. |
| **D3** | **The privacy-tier vocabulary is kept, and every metric here is `internal`.** | The tier exists to gate redaction and export on WordPress, where a metric could carry prompt text. Nothing in this runtime's metric path touches message content (§84), so a higher tier would be a label with no behaviour. |
| **D4** | **An unproduced metric is shown as unproduced, with a reason — never as zero.** | A zero is a claim. §33 already ruled that an empty section is worse than an omitted one; here the section is real, so the honest rendering is the row plus the reason. |
| **D5** | **Verifiers, rewards and eval suites stay `planned`, and that is a product decision.** | A verifier is a rule over an *answer*, a reward is a training signal, a suite runs both. None of that exists on either runtime, and shipping the registries without a harness would produce the page §85.2 warned about: a list of definitions nobody can run. `eval.suite.pass_rate` therefore stays unproduced even though its definition is known. |
| **D6** | **The surface is `/ops/metrics`, read-only, reference-shaped like `/ops/datasets`.** | Operator audience (the contract row already says so); the estate sees spend on `/admin/budgets`, which is a different question. |
| **D7** | **No export in M1.** | The WordPress page exports the collector buffer, which exists to be clearable. This runtime has no buffer and its stores are queryable; an export of derived values is `/ops/reports`, which already exists. |

## 5. What the page would show

Two tables on one page:

1. **The catalogue** — every definition the runtime knows (all 21, so the page can
   name its own gaps): id, label, unit, direction, privacy tier, counter-metric,
   and **produced / partial / not produced** with the reason.
2. **Values** — for the produced ones, a windowed figure over the selected period
   (today / 7 / 30 days), reading the same closed vocabulary the cost pages use.

At M1 the second table has six rows. That is the point: six true rows and fifteen
named gaps, rather than twenty-one zeroes.

## 6. Slices

| Slice | Content | Precondition |
|---|---|---|
| **M1** | `MetricCatalogue` (definitions + the derivation map), `/ops/metrics`, tests | none — this proposal |
| **M2** | Stream metrics: persist chunk count, TTFB and cancellations from the SSE handler | a decision that the SSE path should write telemetry at all; it is the hottest path in the runtime |
| **M3** | Eval harness (suites, verifiers, rewards, `pass_rate`) | its own proposal — it needs a way to run a suite against a model and to store the result, which is a training-loop concern rather than a telemetry one |

`tool.execution.*` sat between M1 and M2: it needed a per-tool execution record.
**It shipped (§98, 2026-11-19)** — four of the five metrics are now derivable, and
`in_flight` is not, because a gauge needs a start record. M1 therefore starts
with **ten** derived metrics rather than six, and with a settled window question: traces
are pruned by **run count**, so a derived figure means "the last N runs of this
assistant", never "today".

## 7. Risks

| Risk | Mitigation |
|---|---|
| A derived metric disagrees with a page built on the same store | It cannot: both call the same reader, the rule §93 applied to budgets and the guard. |
| The catalogue drifts from the WordPress vocabulary | The ids are the WordPress ids, copied verbatim; a test pins the set and the count. |
| The page becomes a second observability view | It shows *definitions and one windowed figure each*. Per-run detail stays on `/ops/observability`; a link, not a duplicate. |
| Someone later "fixes" the zeros by adding a table | D2 and this paragraph are the reason not to; a stored counter is the mistake §92 already refused once. |

## 8. What would change the decision

- **A per-tool execution record** (its own slice, useful beyond metrics) has moved
  four rows from "not produced" to "derived" without touching this design — §98,
  2026-11-19. `in_flight` stays unproduced: it needs a start record.
- **An eval harness** would make D5's deferral wrong, and would come with its own
  data model — at which point `eval.suite.*` becomes derivable and M3 replaces
  this section, not edits it.
- **A metric a customer needs to alert on** (as opposed to read) would want a
  time series rather than a query, and that is the point where a store earns its
  place. Nothing in the WordPress vocabulary is alert-grade today.
