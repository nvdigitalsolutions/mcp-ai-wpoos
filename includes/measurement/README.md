# Measurement

## Purpose

Provides the telemetry backbone — metric collection, verifier scoring, reward functions, budget envelopes, evaluation suites, and the metric-events store — that observes chat turns, tool executions, and SSE streams without coupling those subsystems to a particular analytics backend.

## Tier

| | |
|---|---|
| **Distribution** | Base (Pro adds rubric verifiers, schedule metrics, OTEL subscribers in `addons/pro/includes/measurement/`) |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` (eager require for registries + observers; `wp_mcp_ai_measurement_bootstrap()` is called during plugin boot) |
| **Optional dependencies** | OpenTelemetry exporters / OTLP collectors (used by `exporters/`); none are required at runtime |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Measurement_Registry` | `class-wp-mcp-ai-measurement-registry.php` | Bootstrap, Pro measurement bootstrap, tests |
| `WP_MCP_AI_Metric_Collector` | `class-wp-mcp-ai-metric-collector.php` | Every observer + verifier + reward path |
| `WP_MCP_AI_Verifier_Registry` / `WP_MCP_AI_Verifier_Base` / `WP_MCP_AI_Verifier_Interface` | `class-wp-mcp-ai-verifier-*.php`, `interface-wp-mcp-ai-verifier.php` | Eval suites, Pro rubric verifier, tools that score outputs |
| `WP_MCP_AI_Reward_Function_Registry` | `class-wp-mcp-ai-reward-function-registry.php` | Pro budget-guarded rewards, eval suites |
| `WP_MCP_AI_Tool_Execution_Observer`, `_Chat_Turn_Observer`, `_SSE_Observer` | `class-wp-mcp-ai-*-observer.php` | Wired into tool/chat/SSE lifecycle hooks by the bootstrap |
| `WP_MCP_AI_Chat_Turn_Metrics`, `_SSE_Metrics`, `_Stock_Metrics` | `class-wp-mcp-ai-*-metrics.php` | Observers + admin dashboards |
| `WP_MCP_AI_Metric_Event_Store` | `class-wp-mcp-ai-metric-event-store.php` | Persister, retention, admin measurement dashboard |
| `WP_MCP_AI_Metric_Persister`, `_Metric_Retention` | `class-wp-mcp-ai-metric-*.php` | Event store, cron retention job |
| `wp_mcp_ai_measurement_bootstrap()` | `class-wp-mcp-ai-measurement-bootstrap.php` | `includes/bootstrap/` (called once per request) |
| `budgets/` | `WP_MCP_AI_Budget_Envelope`, `WP_MCP_AI_Budget_Registry` | Reward functions, Pro scheduling |
| `eval/` | `WP_MCP_AI_Eval_Case` / `_Suite` / `_Suite_Registry` / `_Runner` / `_Counterfactual_Runner` / `_Regression_Detector` / `_Run_Store` | CLI eval harness, Pro eval scheduler |
| `verifiers/` | `WP_MCP_AI_Rule_Verifier`, `_Schema_Verifier`, `_LLM_Judge_Verifier` | Default verifier shipment |
| `rewards/` | `WP_MCP_AI_Reference_Rewards` | Default reward shipment |
| `exporters/` | `WP_MCP_AI_Otel_Exporter` | Optional OTel pipeline |

## Inputs / Outputs / Neighbors

- **Reads from:** chat-turn, tool-execution, and SSE lifecycle hooks (`wp_mcp_ai_chat_turn_*`, `wp_mcp_ai_tool_execution_*`, `wp_mcp_ai_sse_*`); settings options for retention windows and exporter config.
- **Writes to:** the custom `wp_mcp_ai_metric_events` table managed by `Metric_Event_Store` (auto-installed on every request via `install()`), summary transients, and — when an exporter is enabled — outbound OTel traffic.
- **Upstream callers:** observers are attached to hooks fired from `includes/services/`, `includes/tools/`, `includes/rest/`. Eval suites are driven by CLI commands and the Pro eval scheduler.
- **Downstream collaborators:** `includes/admin/measurement/` (admin dashboard), Pro measurement files in `addons/pro/includes/measurement/`, and the OTel exporter network targets.
- **Events fired:** `wp_mcp_ai_metric_recorded`, `wp_mcp_ai_metric_event_persisted`, `wp_mcp_ai_verifier_scored`, `wp_mcp_ai_reward_calculated`, `wp_mcp_ai_eval_suite_completed`, `wp_mcp_ai_budget_envelope_exceeded`.
- **Events listened to:** `wp_mcp_ai_tool_execution_started/completed/failed`, `wp_mcp_ai_chat_turn_started/completed`, `wp_mcp_ai_sse_stream_*`, plus the filterable enable switches `wp_mcp_ai_tool_execution_observer_enabled`, `wp_mcp_ai_chat_turn_observer_enabled`, `wp_mcp_ai_sse_observer_enabled`.

## Conventions

- Every registry is a singleton with an idempotent `boot()`; do not instantiate them with `new`.
- Observers must attach **after** the collector is primed — follow the ordering in `class-wp-mcp-ai-measurement-bootstrap.php` when adding new ones.
- New verifiers extend `WP_MCP_AI_Verifier_Base` (or implement `WP_MCP_AI_Verifier_Interface` for stateless cases) and register through `WP_MCP_AI_Verifier_Registry`.
- New reward functions register through `WP_MCP_AI_Reward_Function_Registry`; do not hook directly into chat/tool actions.
- Schema changes to the metric-events table go through `WP_MCP_AI_Metric_Event_Store::install()` (idempotent, version-gated) — never `dbDelta()` from elsewhere.
- The folder is hot — code here runs on nearly every request. Prefer cached / lazy work, and gate expensive paths behind the per-observer `*_enabled` filters.

## Tests

```bash
vendor/bin/phpunit tests/measurement/ tests/verifiers/
```

Plus root-level suites: `tests/test-analytics-engine.php`, `tests/test-usage-tracker.php`, `tests/test-data-budget-tracker.php`, `tests/test-token-budget-manager.php`, and chat/SSE observer tests under `tests/test-hooks-*`.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — escaping/sanitisation rules for metric labels and exporter output
- [`.context/testing.md`](../../.context/testing.md) — measurement test patterns (event-store reset, observer harness)
- [`.context/rest-api.md`](../../.context/rest-api.md) — REST surfaces consume measurement output for chat-job status
- [`CLAUDE.md`](../../CLAUDE.md) — PHP compat + tool-execution lifecycle

## See Also

- Pro counterpart: [`addons/pro/includes/measurement/`](../../addons/pro/includes/measurement/) (rubric verifiers, schedule metrics, OTel subscribers)
- Admin surface: [`../admin/measurement/class-wp-mcp-ai-admin-measurement-dashboard.php`](../admin/measurement/class-wp-mcp-ai-admin-measurement-dashboard.php)
- Sibling top-level analytics: [`../class-wp-mcp-ai-analytics-engine.php`](../class-wp-mcp-ai-analytics-engine.php), [`../class-wp-mcp-ai-usage-tracker.php`](../class-wp-mcp-ai-usage-tracker.php)
