# Measurement (Pro) — Rubric Verifiers, Schedule Metrics, OTel Subscribers

## Purpose

Wires Pro-only artifacts — multi-criterion rubric verifiers (with three stock presets), a budget-guarded reward wrapper, a request-scope cost envelope, Pro Schedule metric definitions, and the Pro Schedule OTel subscriber — into the Base measurement subsystem's registries on the standard registration hooks.

This folder **extends** the Base measurement subsystem in [`includes/measurement/`](../../../../includes/measurement/) (registries, collector, observers, verifier base, reward registry, budget registry, event store, OTel exporter). It does **not** replace any Base file; everything here registers via the canonical `wp_mcp_ai_register_*` actions so a Base-only install stays untouched.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/mcp-ai-wpoos-pro.php` → `wp_mcp_ai_pro_init()` requires the five class files and calls `WP_MCP_AI_Pro_Measurement_Bootstrap::boot()` once per request |
| **Optional dependencies** | OpenTelemetry exporter targets (consumed by the OTel subscriber via the Base `WP_MCP_AI_OTel_Exporter`) — none required at runtime |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Pro_Measurement_Bootstrap` | `class-wp-mcp-ai-pro-measurement-bootstrap.php` | Pro bootstrap; tests reset via `::reset()` |
| `WP_MCP_AI_Pro_Rubric_Verifier` | `class-wp-mcp-ai-pro-rubric-verifier.php` | the stock `pro_content_rubric` verifier and the three preset rubrics |
| `WP_MCP_AI_Pro_Rubric_Presets` | `class-wp-mcp-ai-pro-rubric-presets.php` | `Pro_Measurement_Bootstrap::register_preset_rubrics()` |
| `WP_MCP_AI_Pro_Budget_Guarded_Reward` | `class-wp-mcp-ai-pro-budget-guarded-reward.php` | `Pro_Measurement_Bootstrap::register_rewards()` (wraps the Base `verified_success` reward) |
| `WP_MCP_AI_Pro_Schedule_Metrics` | `class-wp-mcp-ai-pro-schedule-metrics.php` | OTel subscriber and any consumer reading the metric ids |
| `WP_MCP_AI_Pro_Schedule_Otel_Subscriber` | `class-wp-mcp-ai-pro-schedule-otel-subscriber.php` | self-boots from `Pro_Measurement_Bootstrap::boot()` |

Stable contract: verifier slug `pro_content_rubric` and the three preset slugs (`prompt_adherence`, `json_schema`, `citation_presence`); budget slug `pro_request_cost_usd` (request scope, USD); reward slug `verified_success_budget_guarded`; metric ids `schedule.run.duration_ms` (histogram) and `schedule.run.failure.count` (counter).

## Inputs / Outputs / Neighbors

- **Reads from:** Base verifier / reward / budget registries (must exist — guarded by `class_exists()` so a degraded load never fatals), filters `wp_mcp_ai_pro_rubric_default_criteria`, `wp_mcp_ai_pro_request_cost_budget_limit`, `wp_mcp_ai_pro_schedule_metrics_definitions`, `wp_mcp_ai_pro_schedule_otel_enabled`, `wp_mcp_ai_pro_schedule_otel_jit_dispatch`.
- **Writes to:** the Base registries via their `register()` APIs, the Base `WP_MCP_AI_Metric_Collector` (per-run histogram + counter records), and — when an OTel exporter target is configured — outbound OTel traffic via the Base `WP_MCP_AI_OTel_Exporter::dispatch()`.
- **Upstream callers:** Pro bootstrap calls `Pro_Measurement_Bootstrap::boot()` once; the OTel subscriber is fired by the action `wp_mcp_ai_pro_schedule_run_completed` (from [`../`](..) Pro schedule manager).
- **Downstream collaborators:** Base measurement registries / collector / OTel exporter, eval suites that reference `pro_content_rubric` or the three preset rubrics.
- **Events fired:** none directly — registration only.
- **Events listened to:** `wp_mcp_ai_register_verifiers` (priority 20 for the composite, 25 for the three presets), `wp_mcp_ai_register_budgets` (priority 20), `wp_mcp_ai_register_reward_functions` (priority 30, after Base reference rewards at 10), `wp_mcp_ai_pro_schedule_run_completed` (OTel subscriber).

## Conventions

- **Boot through `Pro_Measurement_Bootstrap::boot()`. Never call `add_action()` for these registrations from elsewhere** — the priority ordering (budgets at 20, rewards at 30) is load-bearing.
- **`boot()` is idempotent and gated.** It checks `class_exists()` for the three Base registries before wiring and silently no-ops if a registry is missing. Preserve that pattern when adding new Pro registrations here.
- **Slugs are stable contracts.** `pro_content_rubric`, the three preset slugs, `pro_request_cost_usd`, `verified_success_budget_guarded`, `schedule.run.duration_ms`, `schedule.run.failure.count` are referenced by eval suites, dashboards, and exporter configs. Don't rename — extend via filter.
- **Goodhart pairing is required for new metric pairs.** `schedule.run.duration_ms` is paired with `schedule.run.failure.count` so a "fast but always-failing" run cannot look like a health win. Apply the same pattern to any new duration / count pair added here.
- **Filter-removable, never deletable.** Every Pro registration here must be skippable from a filter (empty criteria array, zero/negative limit, `false` enabled flag) — operators must be able to disable Pro measurement opt-ins without code edits.
- **Pro Schedule metrics, not Base.** Anything that isn't strictly Pro Schedule (or rubric / budget-guarded reward) belongs in Base [`includes/measurement/`](../../../../includes/measurement/), not here.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/measurement/
vendor/bin/phpunit addons/pro/tests/test-pro-schedule-otel-subscriber.php
```

Suite files: `test-pro-measurement-bootstrap.php`, `test-pro-rubric-verifier.php`, `test-pro-rubric-presets.php`, `test-pro-budget-guarded-reward.php`, `test-pro-schedule-otel-subscriber.php`.

## Also Load

- [`includes/measurement/README.md`](../../../../includes/measurement/README.md) — Base measurement subsystem (mandatory pre-read for any change here)
- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — exporter output, metric labels (always)
- [`.context/testing.md`](../../../../.context/testing.md) — measurement test patterns (registry resets, observer harness)
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro placement rationale
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat (8.1+) and the canonical envelope

## See Also

- Base counterpart: [`../../../../includes/measurement/`](../../../../includes/measurement/) — registries, collector, observers, OTel exporter this folder plugs into
- Sibling: [`../`](..) Pro Schedule Manager (`class-wp-mcp-ai-pro-schedule-manager.php`) — fires `wp_mcp_ai_pro_schedule_run_completed`
- Sibling: [`../harness/`](../harness/) — Pro Layer H eval-curriculum export (uses Base eval suites scored by these verifiers)
