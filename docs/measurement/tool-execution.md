# Tool Execution Instrumentation

Introduced in **PR 6** of the measurement rollout. Before this PR the
measurement registry, collector, verifiers, rewards, budgets and
exporter were all in place — but nothing in the plugin's runtime code
path actually emitted a metric. This doc describes the first real
instrumentation layer.

## What this adds

| Component | File | Role |
|---|---|---|
| `WP_MCP_AI_Stock_Metrics` | `includes/measurement/class-wp-mcp-ai-stock-metrics.php` | Registers the baseline tool-execution metric definitions on `wp_mcp_ai_register_metrics` |
| `WP_MCP_AI_Tool_Execution_Observer` | `includes/measurement/class-wp-mcp-ai-tool-execution-observer.php` | Subscribes to `wp_mcp_ai_before_tool_execution` / `wp_mcp_ai_after_tool_execution` and calls `Metric_Collector::record()` |

## Stock metrics registered

| Metric id | Type | Unit | Direction | Counter pair | Privacy tier |
|---|---|---|---|---|---|
| `tool.execution.count` | counter | calls | neutral | `tool.execution.error.count` | internal |
| `tool.execution.success.count` | counter | calls | higher-is-better | `tool.execution.error.count` | internal |
| `tool.execution.error.count` | counter | calls | lower-is-better | `tool.execution.success.count` | internal |
| `tool.execution.duration_ms` | histogram | ms | lower-is-better | `tool.execution.success.count` | internal |
| `tool.execution.in_flight` | gauge | calls | neutral | `tool.execution.duration_ms` | internal |

Every stock metric declares a `counter_metric`. This is a hard
invariant enforced by `Test_WP_MCP_AI_Stock_Metrics::test_every_stock_metric_declares_counter`
— future additions cannot silently land without a Goodhart pairing.

### Filtering the stock set

```php
add_filter( 'wp_mcp_ai_stock_metrics_definitions', function ( $definitions ) {
    // Drop the in-flight gauge on sites that don't want it.
    return array_values( array_filter( $definitions, static function ( $d ) {
        return 'tool.execution.in_flight' !== $d['id'];
    } ) );
} );
```

Returning an empty array from this filter disables the entire pack.

## Observer behavior

The observer attaches at `plugins_loaded` priority 50 (alongside the
rest of the measurement bootstrap). It uses:

* `wp_mcp_ai_before_tool_execution` at priority **5** — ensures we
  capture `started_at` before any third-party listener short-circuits
  the call.
* `wp_mcp_ai_after_tool_execution` at priority **95** — runs after
  third-party listeners have had a chance to transform the result, so
  our outcome classification reflects the user-visible outcome.

### Concurrency model

WordPress is single-threaded, but the agentic loop invokes multiple
tools in sequence. Nested calls of the same slug are possible if a
tool dispatches another tool inside its `execute()`. The observer
maintains an **invocation stack** — `before` pushes, `after` pops the
top frame after verifying its slug. On slug mismatch (e.g. a
third-party hook re-orders `after` events) the stack is scanned
top-down for a matching frame; if none is found the duration metric
is skipped but `tool.execution.count` + outcome counters still fire.
This means a slightly-misordered hook never produces a silently-wrong
duration.

### Privacy contract

The context payload passed to `record()` contains **only**:

* `tool_slug` (string)
* `outcome` (`success` or `error`) — only on `after` events
* `assistant_id`, `user_id`, `guest` — if present in the execution
  context

Tool arguments and results are never included, matching the Internal
privacy tier declared for every stock metric. The test suite asserts
this invariant with a string-level scan of the buffered events.

### Outcome classification

| Result | Outcome |
|---|---|
| `WP_Error` instance | `error` |
| `null` | `success` — the REST agentic loop uses `null` for "no result, carry on" |
| anything else | `success` |

The `after` hook currently only receives a materialized result; if a
tool raises an uncaught exception the `after` hook does not fire and
the observer leaves the frame on the stack. A dedicated hook for
"tool raised" is deferred to a later PR.

## Opt-out

```php
add_filter( 'wp_mcp_ai_tool_execution_observer_enabled', '__return_false' );
```

Disables observer installation entirely. Stock metrics are **still
registered** — third parties can call `Metric_Collector::record()`
against them directly.

## Interaction with earlier PRs

| PR | Wiring |
|---|---|
| PR 1 (core) | Observer calls `Metric_Collector::record()` which validates against definitions registered in PR 6's stock set |
| PR 2 (verifiers + rewards) | No direct wiring — verifier/reward execution is independent |
| PR 3 (eval harness + dashboard) | Dashboard reads buffered events; PR 6 is the first source that populates them from live traffic |
| PR 4 (budgets + OTel exporter) | Budgets attached to `tool.execution.*` metric ids will now fire on real traffic. OTel exporter will include these events |
| PR 5 (Pro toolkit) | The Pro budget-guarded reward depends on consumption flowing into budgets — PR 6 is what makes that consumption real |

## Testing

* `tests/measurement/test-stock-metrics.php` (6 cases)
* `tests/measurement/test-tool-execution-observer.php` (9 cases)

Both suites reset the measurement registry and collector in `setUp`,
register stock metrics, and exercise the observer via the real
WordPress action pipeline.

## See also

- [`rollout-plan.md`](rollout-plan.md) — full measurement delivery schedule (PR 6 in context)
- [`privacy-matrix.md`](privacy-matrix.md) — privacy tiers and redaction policy
- [`goodhart-checklist.md`](goodhart-checklist.md) — metric-authoring checklist
- [`budgets.md`](budgets.md) — attaching budgets to tool metrics
- [`otel-exporter.md`](otel-exporter.md) — exporting these events out of WordPress
