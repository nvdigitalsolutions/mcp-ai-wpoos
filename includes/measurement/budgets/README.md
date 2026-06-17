# Budgets

## Purpose

Provides the budget envelope value object and its singleton registry that track consumption against declarative caps (cost, tokens, error rate, latency) and fire observable signals when warn and exceed thresholds are crossed — serving as the anti-Goodhart guard for reward functions.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/measurement` bootstrap; registry attaches to `wp_mcp_ai_metric_recorded` during `boot()` |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Budget_Envelope` | `class-wp-mcp-ai-budget-envelope.php` | Budget registry, reward functions, Pro scheduling |
| `WP_MCP_AI_Budget_Registry` | `class-wp-mcp-ai-budget-registry.php` | Measurement bootstrap, Pro scheduling, admin dashboard |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_metric_recorded` action (metric id + value), persistent accumulators from the `wp_mcp_ai_budget_accumulators` option.
- **Writes to:** `wp_mcp_ai_budget_accumulators` option (persistent scope), `wp_mcp_ai_budget_warned` and `wp_mcp_ai_budget_exceeded` actions.
- **Upstream callers:** measurement bootstrap, Pro guardrail system, admin dashboard, reward function path.
- **Downstream collaborators:** `WP_MCP_AI_Metric_Collector` (listens for recorded metrics), WordPress options API.
- **Events fired:** `wp_mcp_ai_register_budgets` (registration hook), `wp_mcp_ai_budget_warned`, `wp_mcp_ai_budget_exceeded`.
- **Events listened to:** `wp_mcp_ai_metric_recorded` (priority 5) — automatically ticks consumption on every recorded metric.

## Conventions

- `WP_MCP_AI_Budget_Envelope` is a plain value object — slug, label, metric_ids, limit, warn_ratio, unit, scope (`request`/`persistent`), window_seconds, tags. No business logic.
- `WP_MCP_AI_Budget_Registry` is a singleton; use `get_instance()`, call `boot()` once, register envelopes via the `wp_mcp_ai_register_budgets` hook.
- Persistent-scope accumulators are stored in a single autoloaded option (`wp_mcp_ai_budget_accumulators`) keyed by slug with rolling-window support.
- Signals (`warned`, `exceeded`) are idempotent per envelope per scope-window — the registry tracks which have fired.
- Neither signal is a veto — the registry does not block recording. Downstream systems (log sinks, APM, Pro guardrail) react to the breach.

## Tests

```bash
vendor/bin/phpunit tests/measurement/
```

Budget-specific coverage is part of the measurement test suite.

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — escaping for labels and signals (always)
- Parent folder: [`includes/measurement/README.md`](../README.md) — full measurement layer overview

## See Also

- Upstream parent: [`includes/measurement/`](../) — measurement layer
- Reward functions: [`includes/measurement/rewards/`](../rewards/) — consume budget state for cost-adjusted scoring
- Metric collector: [`includes/measurement/class-wp-mcp-ai-metric-collector.php`](../class-wp-mcp-ai-metric-collector.php)
