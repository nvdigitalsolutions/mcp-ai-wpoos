# Proposal 149 — `measurement` data model (track P3)

**Parent:** [`149-laravel-admin-parity-implementation-plan.md`](./149-laravel-admin-parity-implementation-plan.md) §45, §46
**State:** design. **No code in this track.** **Unblocks:** order item 17 (`measurement`, contract `planned`).

## 1. Verified state — why the row is `planned`

- **No measurement tables exist.** `docker/laravel/app-skeleton/database/migrations/` has
  no `metric`, `eval` or `budget` table. The nearest neighbours are not substitutes:
  - `nvoos_harness_trace_records` (`lib/laravel-adapter/…/2026_01_01_000021_…`) stores
    per-assistant trace-policy records, not metric events.
  - `TokenBudgetServiceInterface` and `DataBudgetTrackerInterface` are **per-response
    caps** (tokens, payload size), not the WordPress page's persistent budget envelopes.
- **The WordPress page is itself read-only over in-memory registries** ("before PR 4
  adds the OTel exporter and writable admin actions"), with three admin-post actions.
- Two of its five sections are **code registries**, not data: verifiers (independence
  profile) and reward functions (anti-gaming text). Three need storage: metrics, eval
  suites, budgets.

## 2. Scope decision: platform, not estate

The contract row is `audience: operator`, `ops: planned`. Keep it platform-scoped:

- No `tenant_id` on the stores; the page is `/ops/measurement`, which has no estate
  selected and must not imply one.
- Metric **events** may carry an optional `tenant_label` string purely for grouping in
  the UI. It is a label, not an access boundary — a tenant never reaches this page.

If per-estate measurement is ever wanted, it is a new row with its own decision, not a
column added later.

## 3. Tables (five)

| Table | Purpose | Key columns |
|---|---|---|
| `nvoos_metrics` | registry | `key` (PK, `^[a-z][a-z0-9_.]{1,63}$`), `label`, `type` (`counter`/`gauge`/`histogram`), `unit`, `privacy_tier` (`public`/`internal`/`sensitive`), `counter_metric` (nullable, must resolve to another key), `source`, timestamps |
| `nvoos_metric_events` | observations | `id` (ULID), `metric_key`, `value` decimal(20,6), `labels` JSON, `tenant_label` (nullable), `occurred_at` (indexed), `created_at` |
| `nvoos_eval_suites` | catalogue | `key` (PK), `label`, `case_count`, `verifier`, timestamps |
| `nvoos_eval_runs` | results | `id` (ULID), `suite_key`, `status` (`queued`/`running`/`passed`/`failed`), `cases_total`, `cases_passed`, `score` decimal(6,4) nullable, `started_at`, `finished_at`, `error` nullable |
| `nvoos_budgets` | envelopes | `slug` (PK), `label`, `limit` decimal(20,6), `used` decimal(20,6), `window` (`daily`/`monthly`/`rolling`), `window_started_at`, `reset_at` |

Conventions to copy from existing migrations: guarded (`Schema::hasTable` /
`hasColumn`), `down()` that drops only what it created, indexes on the columns the
page filters (`metrics.privacy_tier`, `metric_events.occurred_at`,
`eval_runs.status`, `budgets.reset_at`).

## 4. Retention

- Default **30 days** for `nvoos_metric_events`, matching the WordPress page's stated
  policy. Config key `oos.measurement.retention_days`, clamped 1–365.
- A daily `nvoos:prune-metrics` command deletes by `occurred_at` and is **unscoped** —
  a retention job with no request estate must not inherit one (`TranscriptStore::prune`
  is the precedent).
- The buffer view is `LIMIT`-ed with the ceiling printed in the header
  (`/ops/audit-log` rule: never a silent truncation).

## 5. API (thin, read-mostly)

| Method + path | Purpose | Audited |
|---|---|---|
| `GET /api/v1/measurement/overview` | the five sections (metrics, verifiers, rewards, eval suites, buffer) | no |
| `GET /api/v1/measurement/events?limit=` | buffer slice, ceiling echoed | no |
| `POST /api/v1/measurement/buffer/clear` | clear the collector buffer | **yes** |
| `GET /api/v1/measurement/export` | JSON download | **yes** |
| `POST /api/v1/measurement/budgets/{slug}/reset` | reset a persistent envelope | **yes** |

All inside `can:manage_options` + operator membership. The three audited actions write
`nvoos_admin_audit` record rows (Livewire traffic is excluded from the request audit —
the same rule every panel action follows).

## 6. Page mapping (`/ops/measurement`)

| WP section | Source | Rule |
|---|---|---|
| Metrics | `nvoos_metrics` | show `privacy_tier`; a `sensitive` metric renders its label and count only — never raw label values |
| Verifiers | code registry | read-only list; no store |
| Reward functions | code registry | read-only list; no store |
| Eval suites | `nvoos_eval_suites` + latest run | case count + last status |
| Buffer | `nvoos_metric_events` | `LIMIT` + printed ceiling |

Actions: **Clear buffer**, **Download JSON**, **Reset budget** (armed two-step, like
every destructive action on the panels).

## 7. Slice order and acceptance

1. Migrations + models + stores (`metrics`, `eval`, `budgets`) — one PR, five tables.
2. Commands: `nvoos:prune-metrics`; a seeder is not needed.
3. API + tests (platform scope; audit rows for the three actions).
4. Page + tests (operator-only; capability 403; no secret/value leakage).
5. Contract row `measurement`: `planned` → `full`; `writer: ops` holds.

Acceptance:

- [ ] Fresh `php artisan migrate` creates all five tables; a guarded re-run is a no-op.
- [ ] The page is unreachable for anyone outside the operator estate.
- [ ] The three actions each write exactly one audit row and nothing else.
- [ ] A `sensitive` metric never renders a raw label value.
- [ ] Pruning removes only rows older than the configured window.

## 8. Open questions (decide before step 1)

1. **Metric types**: is `histogram` needed now, or only `counter`/`gauge`? Histograms
   imply percentile queries and a second table; recommend deferring.
2. **Rolling window semantics** for budgets: rolling-24h needs an event scan; daily and
   monthly need only `window_started_at`. Recommend daily/monthly only in v1.
3. **OTel exporter**: out of scope. The WordPress page itself says it is PR 4; porting
   an exporter is a different track with a different failure surface.
