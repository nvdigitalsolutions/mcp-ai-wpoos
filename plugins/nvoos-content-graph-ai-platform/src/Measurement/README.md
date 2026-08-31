# Measurement

## Purpose

Telemetry/measurement subsystem — metric definition registry (Goodhart-paired
definitions), in-memory metric collector, custom-table metric event store,
shutdown-flushing persister, per-privacy-tier retention cron, chat-turn / SSE
stock metric packs, chat-turn and session-log observers, reward-function and
verifier registries, and declarative budget envelopes.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\Measurement\MeasurementService` (via `Plugin::register()`) |
| **Optional dependencies** | Base plugin `mcp-ai-wpoos` (monolith mode — its measurement copy owns runtime wiring) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Measurement\MeasurementService` | `MeasurementService.php` | `Plugin::register()` |
| `wp_mcp_ai_measurement_bootstrap()` (global) | `shim-functions.php` | `MeasurementService` (standalone mode) |
| `wp_mcp_ai_measurement_ensure_capabilities()` (global) | `shim-functions.php` | `MeasurementService` (standalone mode) |
| `wp_mcp_ai_register_reference_verifiers()` (global) | `shim-functions.php` | `wp_mcp_ai_register_verifiers` hook |
| `wp_mcp_ai_register_reference_rewards()` (global) | `shim-functions.php` | `wp_mcp_ai_register_reward_functions` hook |
| `NvoosContentGraphAiPlatform\Measurement\MeasurementRegistry` | `MeasurementRegistry.php` | bootstrap, collectors, dashboards |
| `NvoosContentGraphAiPlatform\Measurement\MetricCollector` | `MetricCollector.php` | observers, exporters |
| `NvoosContentGraphAiPlatform\Measurement\MetricEventStore` | `MetricEventStore.php` | `MetricPersister`, `MetricRetention` |
| `NvoosContentGraphAiPlatform\Measurement\MetricPersister` | `MetricPersister.php` | bootstrap (standalone) |
| `NvoosContentGraphAiPlatform\Measurement\MetricRetention` | `MetricRetention.php` | bootstrap (standalone), cron |
| `NvoosContentGraphAiPlatform\Measurement\ChatTurnMetrics` | `ChatTurnMetrics.php` | `wp_mcp_ai_register_metrics` (priority 20) |
| `NvoosContentGraphAiPlatform\Measurement\SseMetrics` | `SseMetrics.php` | `wp_mcp_ai_register_metrics` (priority 20) |
| `NvoosContentGraphAiPlatform\Measurement\ChatTurnObserver` | `ChatTurnObserver.php` | bootstrap (standalone) |
| `NvoosContentGraphAiPlatform\Measurement\SessionLogObserver` | `SessionLogObserver.php` | bootstrap (standalone) |
| `NvoosContentGraphAiPlatform\Measurement\RewardFunctionRegistry` | `RewardFunctionRegistry.php` | bootstrap, eval suite |
| `NvoosContentGraphAiPlatform\Measurement\VerifierRegistry` | `VerifierRegistry.php` | bootstrap, eval suite |
| `NvoosContentGraphAiPlatform\Measurement\VerifierBase` | `VerifierBase.php` | concrete verifiers |
| `NvoosContentGraphAiPlatform\Measurement\Verifier` | `Verifier.php` | `VerifierRegistry`, `VerifierBase` |
| `NvoosContentGraphAiPlatform\Measurement\BudgetEnvelope` | `BudgetEnvelope.php` | `BudgetRegistry`, site code |
| `NvoosContentGraphAiPlatform\Measurement\BudgetRegistry` | `BudgetRegistry.php` | bootstrap (standalone) |

## Inputs / Outputs / Neighbors

- **Reads from:** the `{prefix}mcp_ai_metric_events` custom table,
  `wp_mcp_ai_metric_events_schema_version` and `wp_mcp_ai_budget_accumulators`
  options, provider chat hooks (`wp_mcp_ai_before_chat_request`,
  `wp_mcp_ai_after_chat_response`, `wp_mcp_ai_cost_calculated`,
  `wp_mcp_ai_agentic_iteration_complete`), the session-log event stream
  (`wp_mcp_ai_session_log_event`)
- **Writes to:** the `{prefix}mcp_ai_metric_events` table (batched INSERT on
  `shutdown`), the schema-version + budget-accumulator options, the daily
  `wp_mcp_ai_metric_retention_purge` cron event, administrator capabilities
  (`manage_wp_mcp_ai_measurements`, `view_wp_mcp_ai_measurements`)
- **Upstream callers:** `Plugin::register()` → `MeasurementService`, base
  plugin measurement bootstrap (monolith mode)
- **Downstream collaborators:** collectors ↔ observers ↔ persister ↔ event
  store ↔ retention cron; registries ↔ eval suite (base-only)
- **Events fired:** `wp_mcp_ai_register_metrics`,
  `wp_mcp_ai_register_verifiers`, `wp_mcp_ai_register_reward_functions`,
  `wp_mcp_ai_register_budgets`, `wp_mcp_ai_metric_recorded`,
  `wp_mcp_ai_verifier_result`, `wp_mcp_ai_measurement_retention_completed`,
  `wp_mcp_ai_budget_warned`, `wp_mcp_ai_budget_exceeded` — all `wp_mcp_ai_*`
  hook names preserved (data stability)
- **Standalone degradations (base plugin absent):**
  - `wp_mcp_ai_measurement_bootstrap()` boots the ported registries but skips
    the base-only `WP_MCP_AI_Eval_Suite_Registry`,
    `WP_MCP_AI_Tool_Execution_Observer`, `WP_MCP_AI_SSE_Observer`,
    `WP_MCP_AI_Stock_Metrics`, and `WP_MCP_AI_Admin_Measurement_Dashboard`
    (gated `defined( 'WP_MCP_AI_PATH' ) && class_exists( ... )` probes)
  - reference verifiers (`WP_MCP_AI_Rule_Verifier`,
    `WP_MCP_AI_Schema_Verifier`, `WP_MCP_AI_LLM_Judge_Verifier`) and reference
    rewards (`WP_MCP_AI_Reference_Rewards`) are not registered
  - `SessionLogObserver::project_tool_result()` no-ops — the stock
    tool-execution metric ids live on the base-only `WP_MCP_AI_Stock_Metrics`

## Conventions

- Ported 1:1 from `mcp-ai-wpoos/includes/measurement/` (extraction Wave C,
  files: measurement bootstrap, measurement registry, metric collector,
  metric event store, metric persister, metric retention, chat-turn metrics +
  observer, SSE metrics, session-log observer, reward-function registry,
  verifier base/interface/registry, `budgets/`). Table name, option keys,
  cron hook, capability slugs, and all `wp_mcp_ai_*` hook names are
  unchanged — data stability is sacred (extraction plan §3).
- Standalone mode: `MeasurementService::register()` requires
  `shim-functions.php`, a no-namespace file carrying the base-identical
  global bootstrap functions (each `function_exists`-guarded) plus their
  `plugins_loaded` (50) / `admin_init` (5) / reference-registration wiring.
  Monolith mode: `register()` returns after the admin UI block — the base
  plugin's own bootstrap owns runtime wiring and the shim never loads.
- Class files declare `strict_types=1` and live in the
  `NvoosContentGraphAiPlatform\Measurement` namespace; file names follow the
  PSR-4 class-per-file map, not WP file-name conventions.
- Remaining `WP_MCP_AI_*` references are intentional base-only components,
  always behind `defined( 'WP_MCP_AI_PATH' ) && class_exists( ... )` probes.
- Text domain: `nvoos-content-graph-ai-platform`.

## Tests

```bash
# Monolith matrix (base plugin loaded)
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter Measurement

# Standalone matrix (base plugin skipped)
WP_MCP_AI_PLATFORM_STANDALONE=1 vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter Measurement
```

## See Also

- Plan: `docs/project/plans/content-graph-platform-extraction-plan.md`
- Tracker: `MIGRATION-GAPS.md`
- Base sources: `includes/measurement/` (incl. `budgets/`, `eval/`, `verifiers/`,
  `rewards/`, `exporters/` — the latter four stay base-only)
