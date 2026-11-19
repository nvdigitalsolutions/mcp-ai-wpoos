# Proposal 154 — Budgets, and why `measurement` is three problems

**Status:** proposed 2026-11-19. Slice A (estate budgets) is the implementing
slice; the metric registry and eval runs are deferred with their preconditions
recorded.

**Related:** [Proposal 149](149-laravel-admin-parity-implementation-plan.md) §45
(the `measurement` contract row), §64/§66 (routing metrics), §81 (token-usage
writer), §82/§87 (cost pages), §85.2 (measurement's original inventory), §86
(estate scope for telemetry).

## 1. Why budgets, and not "measurement"

§85.2 recorded `measurement` as needing three stores — `nvoos_metrics`,
`nvoos_eval_runs`, `nvoos_budgets` — and left the page `planned`. Treating that
as one job hides the fact that the three have wildly different readiness:

| Piece | Writer | Consumer | Buildable now? |
|---|---|---|---|
| Budgets | none, but the **spend record already exists** (`nvoos_token_usage`, estate-scoped since §86) | the chat entry points, and the cost pages | **yes** |
| Metric registry | none — nothing produces a metric definition | a page that would list its own table | no: §81 in reverse |
| Eval runs | none — there is no eval harness | a page that would list an empty table | no |

A budget is a **limit over records that already exist**. The other two are tables
with no producer, and §81's lesson was a table read by five surfaces and written
by nobody; a table written by nobody *and read by one page* is the same mistake
with less to show for it.

So this proposal splits the contract row rather than pretending the three are one
surface:

- **`budgets`** — `ops`, buildable now; this proposal.
- **`metrics`** and **`eval-runs`** — `planned`, reason: *no producer*. That is a
  more honest label than "not ported from WordPress", and the audit vocabulary
  already distinguishes the two.

## 2. What the standards say

**Budgets are an entity + a window + a limit, enforced before the call.**
LiteLLM — the closest widely-deployed analogue — attaches `max_budget` and
`budget_duration` (`30d`) to an entity (key, team, user, org, end-user, tag) and
validates spend against the database on entry
([docs](https://docs.litellm.ai/docs/proxy/users),
[cost tracking](https://docs.litellm.ai/docs/proxy/cost_tracking)). Two details
matter more than the shape:

1. **Pre-admission, not post-hoc.** A limit checked after the provider call is a
   report.
2. **Fail closed.** Their `fail_closed_budget_enforcement` exists because a stale
   or missing counter **under-reports** spend — the one error a budget exists to
   prevent. When spend cannot be verified, the request is refused.

**Do not standardise metric names yet.** OpenTelemetry's GenAI conventions
(`gen_ai.client.inference.usage.*` counters, `gen_ai.operation.name`,
`gen_ai.provider.name`) are still **Development** status in 2026
([metrics](https://github.com/open-telemetry/semantic-conventions-genai/blob/main/docs/gen-ai/gen-ai-metrics.md),
[status](https://greptime.com/blogs/2026-05-09-opentelemetry-genai-semantic-conventions)).
Pinning an unstable vocabulary into a schema is a cost with no present benefit —
the runtime already emits its own routing metrics and token usage.

**Privacy is a property of the store, not of a metric.** Counters and costs are
not personal data; prompts and completions are. This runtime already enforces
that structurally — trace records carry identifiers and timings and never message
text (§84), token usage is metadata only, and transcripts are the one store that
may hold text. A `privacy` column would be a third statement of a rule two stores
already obey.

## 3. Decisions

| # | Decision | Rationale |
|---|---|---|
| **D1** | Budgets are **estate-scoped** in slice A. Assistant scope is deferred until `assistant_id` is a column on `nvoos_token_usage`. | Spend by estate is a `WHERE tenant_id` over an existing column. Spend by assistant is currently only inside the `metadata` JSON, so it would be a per-request row scan on the hot path — the same reason §86 added a column rather than extracting one. |
| **D2** | Two windows: `daily` and `monthly`. | The cost pages already speak in whole days (`startOfDay()`); a rolling window would disagree with them. `monthly` matches how a bill is read. |
| **D3** | Two bands: `warn_at` (a fraction of the limit) and the limit itself. Below warn → allow. Between → allow, and record. At or over → refuse. | A single hard line gives no warning before a customer's chat stops working. |
| **D4** | Enforcement is **pre-flight**, at the chat entry points, before the provider is called. | A limit applied after the call is a report. Entry-only means one turn may overshoot by that turn's cost — the same allowance every gateway makes, and the alternative (per provider call) needs a `lib/core` contract, which is slice A.2. |
| **D5** | **Fail closed.** If spend cannot be read, the request is refused. | LiteLLM's own reasoning: an unreadable counter under-reports, and under-reporting is over-spending. |
| **D6** | Refusal is **429** with `code: budget_exceeded`; an unverifiable budget is **503** with `code: budget_unverifiable`. | 429 is the conventional quota signal a client already retries on; 503 distinguishes "we cannot tell" from "you are over", which are different operator problems. |
| **D7** | A budget row is estate-owned and takes the global tenant scope, like `Approval` and `Assistant`. | Proposal 153's rule, applied to the new model rather than re-derived. |

## 4. Schema

```sql
CREATE TABLE nvoos_budgets (
  id            BIGINT PRIMARY KEY,
  tenant_id     VARCHAR(64) NOT NULL DEFAULT 'default',
  window        VARCHAR(16) NOT NULL,          -- daily | monthly
  limit_usd     DECIMAL(12,6) NOT NULL,
  warn_at       DECIMAL(5,4) NOT NULL DEFAULT 0.8,
  enabled       BOOLEAN NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMP NULL,
  updated_at    TIMESTAMP NULL,
  UNIQUE (tenant_id, window)
);
```

One budget per estate per window. A second row for the same pair is a
configuration mistake, not a feature — the unique key makes it unrepresentable.

Spend is **not** stored. It is `SUM(cost)` over `nvoos_token_usage` for the
estate and window, which means the budget and the cost pages can never disagree.
A stored counter would be a second truth, and §81/§86 spent two slices making the
first one trustworthy.

## 5. Enforcement

```
assertWithinBudget(): void
    estate = TenantContext::current()
    for each enabled budget of the estate:
        spend = SUM(cost) over the window        // throws → 503 fail-closed
        if spend >= limit        → 429 budget_exceeded
        if spend >= limit*warn   → Log::warning('budget.warn'), allow
```

The signature takes no assistant: per-assistant scope needs the column D1 defers,
so accepting an id it could not filter on would be a parameter that promises more
than it does. The warning band writes a structured `budget.warn` line
(`tenant_id`, `window`, `spend`, `limit`, `percent`) rather than a table row — a
warning is a log event, and a `nvoos_budget_events` store would be a third table
with one reader.

A limit of `0` is read as "no limit", not "refuse everything": a zero row is a
misconfiguration, and failing it closed would take an estate offline over a typo.
The hard band and the fail-closed rule apply to configured limits.

Both windows are checked when both exist; the stricter one wins. There is no
"borrow from the monthly" behaviour — a daily limit that the monthly relaxes is
not a daily limit.

Entry points in slice A: `ChatStreamController` (both routes) and
`AssistantTestController`. `AgentNodeRunner` spends through the same orchestrator
and is wired in the same slice; a workflow that runs an agent spends the estate's
budget like any other turn.

## 6. Slices

| Slice | Content |
|---|---|
| **A** | `nvoos_budgets`, `BudgetStore`, `BudgetGuard`, wiring at the entry points, tests |
| **B** | `/ops/budgets` operator page; estate-facing read/write on `/admin`; consumption shown from the same query |
| **C** (deferred) | `nvoos_metrics` and `nvoos_eval_runs` — only when a producer exists |

## 7. Risks

| Risk | Mitigation |
|---|---|
| One turn overshoots the limit | Accepted and documented: the check is per turn, so the worst case is one turn's cost. Per-call enforcement is slice A.2 and needs a `lib/core` contract. |
| The spend query is slow on a large window | It is one indexed `SUM` over `tenant_id` + `created_at`; the same query the cost pages already run. If it ever matters, the rollup (§80) is the answer, not a counter. |
| A budget blocks a support conversation mid-incident | Operators can raise the limit; the 429 names the window and the numbers. |
| Fail-closed turns a database hiccup into an outage | It does, and that is the decision. The 503 carries `budget_unverifiable`, so the operator can tell it apart from a provider failure. |

## 8. Open questions

1. **Assistant scope** needs a column (D1). Its own slice, with the same shape
   §86 used for the token table.
2. **Should an operator override exist** for a single request? Recorded, not
   designed: an override is a policy, and the 429 message is enough to start.
3. **Currency** is USD throughout, matching `nvoos_token_usage.cost`. Multi-currency
   is a billing question, not a budget one.
