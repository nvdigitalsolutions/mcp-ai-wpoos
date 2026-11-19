# Proposal 152 — Estate scope for telemetry

**Status:** accepted for implementation 2026-11-19. Phase 1 (token usage and its
rollup) is the implementing slice; Phase 2 (harness traces) is specified here and
lands before any estate-facing trace surface exists.

**Related:** [Proposal 149](149-laravel-admin-parity-implementation-plan.md) §64
(scope decisions), §76 (retention), §80 (rollup), §81 (token-usage writer),
§84 (observability), §85 (planned surfaces).

## 1. Why

### 1.1 A live cross-estate read, not a missing feature

`GET /api/v1/cost/dashboard-summary`, `/cost/trend` and `/cost/by-provider` are
registered inside the `can:manage_options` group — the estate-administrator
surface. All three call `Nvoos\Laravel\Adapter\TokenUsage`, which reads
`nvoos_token_usage` with **no scope**:

- `summary()` sums every estate's tokens and cost;
- it resolves `top_users` to **names** through `userNames()`, which queries the
  user table by id with no estate filter.

On a multi-estate deployment an estate administrator can therefore read another
estate's spend and its users' names. The class is the same as the workflow-run
leak §18 fixed — except this one is reachable today and its payload is closer to
rows than to aggregates.

### 1.2 It blocks a WordPress view and a retention decision

- `per_site` (token manager) cannot be built: there is nothing to group by
  (Proposal 149 §82.3).
- Retention is deployment-wide (§76.4 item 2): one estate's history cannot be
  kept longer than another's.

### 1.3 It will block traces next

`nvoos_harness_trace_records` is written on every traced turn and read by
`/ops/observability`. That page is operator-only today, so nothing leaks yet.
The §18 lesson is that the schema change comes **before** the surface, not after
the first estate admin asks for it.

## 2. What the standards say

- **Row-level tenancy** — a `tenant_id` column on every shared table, with query
  scoping — is the pragmatic default for SaaS on one database; schema-per-tenant
  costs more in migration and backup complexity
  ([row-level scoping in Laravel](https://msaied.com/articles/multi-tenant-saas-with-laravel-isolating-tenant-data-using-row-level-scoping)).
- **Observability platforms model tenancy as an attribute** (an OTel resource
  attribute). This runtime stores telemetry relationally, so a column is the
  direct equivalent and is what makes SQL scoping, indexing and per-estate
  retention possible; a JSON attribute would force extraction at every read.
- **Usage metering is per-tenant by definition.** A cost record with no tenant is
  a platform invoice, not a tenant one — which is why the column lands on the
  billing-adjacent tables first.

## 3. Decisions

| # | Decision | Rationale |
|---|---|---|
| **D1** | Add `tenant_id` to `nvoos_token_usage` and `nvoos_token_usage_daily` in Phase 1; `nvoos_harness_trace_records` in Phase 2. | Phase 1 closes the live leak and unblocks `per_site`. Traces are operator-only today, so they can follow without a window of exposure. |
| **D2** | **No `tenant_id` on `nvoos_routing_metrics`.** | §64/§66 recorded the decision: routing happens per provider, before an estate dimension is meaningful, and the rows carry no user or message data. Changing it needs its own argument. |
| **D3** | Existing rows are attributed to `default` by the column default; **no backfill pass.** | §18's shape and its documented limitation: a pre-tenancy row records no estate, and inventing one is worse than a documented default. Guarded migration so it replays on SQLite. |
| **D4** | The **writer** supplies the estate in metadata (`tenant_id`), the adapter writes the column and defaults to `default`. | The adapter lives in `lib/laravel-adapter` and must not depend on `App\Support\TenantContext`. The writer (an app listener) knows the request's estate; the adapter does not. |
| **D5** | The **readers** take an explicit `?string $tenantId`; `null` means platform-wide. | The two modes are real: `/api/v1/cost/*` must see one estate, `/ops` must see all. A default would hide the choice; every call site now states it. |
| **D6** | The rollup's unique key becomes `(tenant_id, day, provider, model_id)` and the aggregation groups by tenant. | Without it, two estates' same-model day collide and the second upsert **overwrites** the first — silent data loss, not a scoping preference. |
| **D7** | Retention stays deployment-wide. | Per-estate windows are a separate policy question (§76.4 item 2). The prune guard (§80.3) is unaffected because the rollup now covers every estate. |
| **D8** | Phase 2 threads the estate through `TraceStoreInterface::append()` as an optional argument, set from `TraceCapture`'s `$meta`. | The orchestrator already receives startup facts in `$meta`; adding one is a contract-compatible change, and it keeps the store free of app-level context. |

## 4. Schema

### 4.1 `nvoos_token_usage`

```sql
ALTER TABLE nvoos_token_usage
  ADD COLUMN tenant_id VARCHAR(64) NOT NULL DEFAULT 'default';
CREATE INDEX nvoos_token_usage_tenant_id_index ON nvoos_token_usage (tenant_id);
```

Chosen over a composite `(tenant_id, created_at)` index: every scoped read from
the token pages also filters `created_at`, but the planner can use the existing
`created_at` index and filter the estate, and the tables are pruned. A composite
index is a follow-up if the query plan says so, not a guess.

### 4.2 `nvoos_token_usage_daily`

```sql
ALTER TABLE nvoos_token_usage_daily
  ADD COLUMN tenant_id VARCHAR(64) NOT NULL DEFAULT 'default';
ALTER TABLE nvoos_token_usage_daily
  DROP INDEX nvoos_token_usage_daily_key;
CREATE UNIQUE INDEX nvoos_token_usage_daily_tenant_key
  ON nvoos_token_usage_daily (tenant_id, day, provider, model_id);
```

The old key is dropped explicitly rather than left redundant: a unique index on
a subset of the new key would still be satisfied, but it would also reject a
legitimate row — two estates may publish the same model on the same day.

## 5. Writer and reader inventory (Phase 1)

### Writers

| Site | Change |
|---|---|
| `App\Listeners\RecordTokenUsage` | adds `tenant_id => TenantContext::current()` to the metadata it already builds |
| `Nvoos\Laravel\Adapter\TokenUsage::trackUsage()` | writes `tenant_id` from metadata, defaulting to `default` |
| `Nvoos\Laravel\Adapter\TokenUsageRollup::rollupDay()` | groups by `tenant_id` and writes it; upsert key gains it |
| Queue / CLI | no pinned estate → `default`. Correct for a single-estate deployment; documented for a multi-estate one |

### Readers

| Site | Mode |
|---|---|
| `AnalyticsController` (`/api/v1/cost/*`) | **estate** — `TenantContext::current()` |
| `CommandCenterController::tokensToday()` | **estate** |
| `SlashCommandExecutor::tokensToday()` and `::cost()` | **estate** — both query the table directly, so the adapter's new signature does not catch them |
| `DiagnosticsPage` (30-day summary) | **platform** — operator page |
| `TokenUsageReport` (`/ops/token-usage`) | **platform** |
| `TokenAnalyticsReport` (`/ops/token-analytics`) | **platform** |
| `TokenUsage::trend()` rollup fallback | follows the caller's mode |

`getUserUsage()` / `getModelUsage()` keep their present signatures: they are
already specific to one user or model, and no estate-facing surface calls them.

## 6. Test plan — the leak test comes first

Written before the implementation, and expected to fail on `main`:

1. **`test_an_estate_administrator_reads_only_their_own_estate`** — estate A and
   estate B each record spend; an A administrator calling
   `/api/v1/cost/dashboard-summary` with `X-Tenant-Id: estate-a` sees A's total
   only, and **no user name from B** anywhere in the payload.
2. **`test_the_chart_endpoints_are_scoped_too`** — `/cost/trend` and
   `/cost/by-provider` sum to one estate.
3. **`test_the_operator_page_sees_every_estate`** — the `/ops/token-usage` rows
   include both estates.
4. **`test_the_rollup_keeps_two_estates_apart`** / **`…does_not_overwrite_one_estate`**
   — same day, provider and model in both estates produce two rows, and a re-run
   rewrites rather than overwrites.
5. **`test_the_trend_falls_back_to_one_estates_rollup`** — the retention fallback
   is scoped like the raw read.
6. **`test_a_row_without_an_estate_is_attributed_to_default`** and
   **`test_the_writer_stamps_the_pinned_estate`** — D3 and D4 end to end.
7. **`test_a_traced_turn_records_the_estate`** — Phase 2.
8. Existing suites must stay green: the token pages, analytics, diagnostics,
   retention and rollup tests all call the changed readers.

## 7. Risks

| Risk | Mitigation |
|---|---|
| A missed reader is a leak | Every reader takes the tenant explicitly; the leak test exercises the estate-facing path; the operator pages are the only `null` callers |
| Queue/CLI writes blend into `default` | `TenantContext` is reset on `JobProcessed`/`JobExceptionOccurred`; the default is documented, not silent |
| `trend()` rollup fallback mixes modes | The rollup query takes the same tenant argument as the raw query; a scoped trend never reads another estate's aggregate |
| MySQL index rebuild on a large table | Both tables are small (raw is pruned, the rollup is one row per day/model). `ALTER TABLE` is online in MySQL 8 for adding a column with a default |
| Phase 2 is forgotten and an estate trace surface ships first | Phase 2 is a pre-condition stated in §3 D1 and in the observability page's own plan section |

## 8. Rollout

1. Migrations, forwards only; `down()` drops the column and restores the old
   unique key.
2. Deploy, then verify:
   - `SELECT tenant_id, COUNT(*) FROM nvoos_token_usage GROUP BY tenant_id;`
   - the same on the daily table;
   - a scoped summary against an operator summary, expected to differ only by
     other estates' rows.
3. No data rewrite. No downtime: the column has a default and no reader requires
   it to be populated before the code that scopes by it ships **with** the
   migration in the same release.

## 9. Open questions

1. **Per-estate retention** — deferred (§76.4 item 2). The column makes it
   possible; the policy (per-estate windows, who sets them) is a product call.
2. **A platform-mode audit trail** — an operator reading every estate's spend is
   normal, but it is still a cross-estate read. Recording it in `admin_audit`
   would follow the §34 reports precedent if it is ever asked for.
3. **Traces and `assistant_id`** — Phase 2 adds the estate to the trace store,
   but a trace row already carries `assistant_id`, which is estate-owned. The
   two must agree; the writer should read the assistant's estate rather than
   trust a header alone if they ever diverge.
