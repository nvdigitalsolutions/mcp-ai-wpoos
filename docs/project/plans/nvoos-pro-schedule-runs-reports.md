# NV oOS Pro Schedule Manager — Runs & Reports Surface

**Status:** implementation in progress (PR to follow) · **Track:** Pro SPA v2 (spa-v2)
+ Complete Console (two-bundle rule) · **Backend:** no controller changes — all
routes already exist.

## 1. Problem

The Pro Schedule Manager stores rich, structured run results — per-schedule
ring-buffer runs (status / start_time / duration / error / type-specific
action_log), per-schedule aggregates (last_run_*, run_count), and captured
**result envelopes** (summary/full) — but the SPA renders only "Next/Last"
timestamps. Operators cannot answer the three questions the data exists to
answer:

1. Did each schedule *actually* run in its window, and what happened?
2. What is the success rate and latency profile per schedule?
3. What did a run produce (workflow step results, channel delivery statuses)?

## 2. Research basis (industry standards)

- **Sidekiq web UI / healthchecks.io model** — show what actually ran:
  a ping-style run history with status + duration per execution, not the
  scheduler's intent. The #1 ops question is "did the job really fire?"
- **Failure taxonomy (web-alert.io job-queue monitoring)** — missed runs,
  silent failures, retry storms: the UI must distinguish failure *modes*,
  not render a bare boolean.
- **Observability trio** — success-rate stat, duration distribution,
  time-series timeline (error-rate + latency charts).
- **W3C APG table + disclosure patterns** and the SPA's existing DataTable /
  Pagination / keep-previous-data contracts (see the `mcp-ai-wpoos-spa-ui`
  skill) — all rendering stays on the house patterns.
- **Charts** — reuse the in-repo `chartRecipes` renderer (nvoos-chart v2
  schema, HTML/CSS slats, no new dependency); specs validated through
  `create_chart_fence`.

## 3. Data contracts (already shipped server-side)

| Route | Shape | Notes |
|---|---|---|
| `GET mcp-ai-pro/v1/schedules/{id}/history` | `{ ok, schedule_id, history: RunRecord[], total }` | `RunRecord = { status: 'success'|'failure'|'running'|'timeout', start_time: epoch-s, duration: float-s, error: string, action_log: array }` — newest-first, ring-buffered |
| `GET mcp-ai-pro/v1/schedules/{id}/results` | `{ schedule_id, results: ResultEnvelope[], count }` | `ResultEnvelope = { summary, data, render: 'text'|'table'|…, status, error, generated_at }` — captured per `display.result_capture` |
| `GET mcp-ai-pro/v1/schedules` | `{ ok, schedules, total }` | `ScheduleItem` carries `run_count`, `last_run` etc. |

CSV export: client-side build from the history rows (no new route needed).

## 4. UI design

### 4.1 ScheduleRunsView (new, `features/schedule-manager/`)

Opened from a per-row **Runs** action in the schedules table; back button
returns to the list (state-based navigation, matching the page's tab pattern).

1. **Report strip** (stats row):
   - Success rate — `success / total` with % and count.
   - Avg + p95 duration (seconds).
   - Last-run freshness — age of the latest run; **overdue flag** when the
     age exceeds 2× the median inter-run gap (missed-window detector,
     healthchecks pattern).
2. **Charts** (chartRecipes, collapsed data tables):
   - Outcomes over time — stacked column, one bar per run, success/failure.
   - Duration per run — column with `unit: 's'` and a timeout threshold call-out
     in the caption when the schedule configures one.
   - Outcome split — donut (success vs failure vs timeout).
3. **Runs table** (DataTable): start time, status badge, duration, error
   (truncated, full text on the expandable row), expandable **action_log**
   rendered per shape — workflow steps become a nested step list
   (tool · label · result preview · duration), delivery logs become
   `channel → status` rows, everything else falls back to a pretty-JSON
   `<pre>`.
4. **Results** — the newest `ResultEnvelope`: `render: 'text'` → summary
   paragraph; `render: 'table'` → DataTable from `data` rows; otherwise a
   JSON preview. Older envelopes collapsible behind a disclosure.
5. **CSV export** button — client-side blob download of the visible history.

### 4.2 Wiring

- `api/schedule-manager.ts` — retype `ScheduleHistoryRow` to the real
  `ScheduleRun` server shape (fixes the phantom `started_at/result` drift),
  add `ResultEnvelope` + `results()`; `history()` gains `AbortSignal`.
- `ScheduleManagerPage.tsx` — Runs action + selected-schedule state.
- No new endpoint key → **no** PHP config / runtime-config changes
  (endpoints.scheduleManager already exists everywhere).

## 5. Validation gates

- `npm run typecheck`, `npm test` (new suite: report-strip math, overdue
  flag, table + expandable action_log, envelope rendering, CSV trigger,
  error/empty states — fetch mocked per the commerce-page conventions),
  `npm run lint:a11y`.
- Chart specs validated through `create_chart_fence`.
- Rebuild BOTH bundles per the two-bundle rule:
  `addons/pro/assets/spa-v2` (`npm run build` → tracked `assets/dist/pro-spa.*`)
  and `examples/nvoos-complete-velocity` (`npm run build` + `npm run sync:dist`
  → tracked `dist/`); prove `pro-spa.js` carries the real change (churn probe).
- PR against `alpha-working`; no self-merge.

## 6. Deferred (own PRs)

- **Fleet-wide ops card** on the console Dashboard — needs a new
  fleet-aggregates REST route (4-point endpoint wiring), out of scope here.
- Server-side CSV route if the client-side export proves insufficient.
