# SSE / Stream Instrumentation

Introduced in **PR 8** of the measurement rollout. PR 6 instrumented
individual tool calls; PR 7 instrumented the chat turn that
orchestrates them; PR 8 instruments the streaming delivery layer —
time-to-first-byte, chunk cadence, total duration, and a
cancellation / failure / timeout breakdown that treats
**client cancellation as a first-class outcome, not an error**.

See [`rollout-plan.md`](rollout-plan.md) for the end-to-end schedule.

## What this adds

| Component | File | Role |
|---|---|---|
| `WP_MCP_AI_SSE_Metrics` | `includes/measurement/class-wp-mcp-ai-sse-metrics.php` | Registers the baseline SSE metric definitions on `wp_mcp_ai_register_metrics` |
| `WP_MCP_AI_SSE_Observer` | `includes/measurement/class-wp-mcp-ai-sse-observer.php` | Subscribes to `wp_mcp_ai_sse_stream_started`, `…_chunk_sent`, and `…_stream_ended` and calls `Metric_Collector::record()` |
| SSE lifecycle hooks | `includes/class-wp-mcp-ai-sse-stream.php` | Pure `do_action` notifications fired from the stream dispatcher; no behaviour change |

The three lifecycle hooks are a small, load-bearing addition to the
base plugin. They are pure notification hooks — observers **MUST NOT**
alter stream state (no echoing, no side effects on the buffered
stream). The SSE dispatcher's existing control flow is unchanged.

## Stock metrics registered

| Metric id | Type | Unit | Direction | Counter pair | Privacy tier |
|---|---|---|---|---|---|
| `stream.count` | counter | streams | neutral | `stream.error.count` | internal |
| `stream.error.count` | counter | streams | lower-is-better | `stream.count` | internal |
| `stream.cancelled.count` | counter | streams | neutral | `stream.count` | internal |
| `stream.ttfb_ms` | histogram | ms | lower-is-better | `stream.error.count` | internal |
| `stream.chunk_interval_ms` | histogram | ms | neutral | `stream.error.count` | internal |
| `stream.total_duration_ms` | histogram | ms | lower-is-better | `stream.error.count` | internal |
| `stream.chunks.count` | histogram | chunks | neutral | `stream.error.count` | internal |

Every metric declares a `counter_metric` — asserted by
`Test_WP_MCP_AI_SSE_Metrics::test_every_sse_metric_declares_counter`.

Every metric stays in the **Internal** privacy tier —
asserted by `test_every_sse_metric_is_internal_tier`. Richer payloads
(status JSON, chunk content, HTTP headers) would require
re-classification to Sensitive or Restricted; see
[`privacy-matrix.md`](privacy-matrix.md).

## Cancellation is not an error

This is the load-bearing design invariant of PR 8. The SSE dispatcher
resolves to one of six outcomes:

| Outcome | When | Routed to |
|---|---|---|
| `complete` | Job reached terminal `completed` state | `stream.count` only |
| `failed` | Job reached terminal `failed` state | `stream.count` + **`stream.error.count`** |
| `cancelled_by_job` | Job reached terminal `cancelled` state (server-side cancel) | `stream.count` + **`stream.cancelled.count`** |
| `cancelled_by_client` | `connection_aborted()` returned true | `stream.count` + **`stream.cancelled.count`** |
| `timeout` | `MAX_DURATION` reached | `stream.count` only |
| `iteration_exhausted` | Safety cap tripped (edge case) | `stream.count` only |

Cancellations and timeouts are deliberately kept out of
`stream.error.count` — mixing them would couple the aggregate error
signal to user behaviour (people closing tabs) and operational limits
(maximum stream duration), making it impossible to use
`stream.error.count` as a quality-regression alert.

`Test_WP_MCP_AI_SSE_Observer::test_client_cancellation_is_not_error`
asserts this invariant on live traffic.

## Observer lifecycle

```
wp_mcp_ai_sse_stream_started    (priority 5)
    └── push frame { job_id, started_at, first_chunk_at: null,
                     last_chunk_at: null, chunk_count: 0 }

wp_mcp_ai_sse_stream_chunk_sent (priority 95)
    ├── if first_chunk_at == null:
    │       first_chunk_at = now
    │       record stream.ttfb_ms
    ├── else:
    │       record stream.chunk_interval_ms
    ├── last_chunk_at = now
    └── chunk_count++

wp_mcp_ai_sse_stream_ended      (priority 95)
    ├── record stream.count
    ├── if outcome == 'failed':              record stream.error.count
    ├── elif outcome ∈ {'cancelled_by_*'}:   record stream.cancelled.count
    ├── record stream.total_duration_ms   (from frame, falls back to summary)
    └── record stream.chunks.count        (from frame)
```

Stream frames are keyed by `job_id` (not top-of-stack), because
SSE streams are not constrained to nest in LIFO order — concurrent
streams within the same PHP request (a rare but legal configuration)
resolve independently without confounding each other.

## Privacy: what leaves scope, what does not

The observer passes only the following into the metric context
(under the collector's `attributes` sub-array so it survives the
collector's allowlist sanitisation):

- `job_id` — character-class-stripped to `[a-zA-Z0-9._\-]`, capped at 128 chars
- `outcome` — constrained to a fixed vocabulary; unknown values collapse to `unknown`

The following are **never** recorded:

- SSE chunk content (status payloads, result bodies, messages)
- Job status JSON
- HTTP headers
- Client IP or user-agent
- Connection timing beyond start / chunk / end
- Iteration count (available to the hook but not propagated)

`Test_WP_MCP_AI_SSE_Observer::test_privacy_canary_no_payload_leakage`
enforces that only `job_id` and `outcome` survive into any recorded
metric's context blob. `test_job_id_is_sanitised` asserts the
sanitiser strips injected payload content from the `job_id` itself.

## Filtering the stock set

```php
add_filter( 'wp_mcp_ai_sse_metrics_definitions', function ( $definitions ) {
    // Drop the chunk-interval metric on sites that don't care about cadence.
    return array_values( array_filter( $definitions, static function ( $d ) {
        return 'stream.chunk_interval_ms' !== $d['id'];
    } ) );
} );
```

## Disabling the observer

```php
add_filter( 'wp_mcp_ai_sse_observer_enabled', '__return_false' );
```

Stock definitions remain registered so third-party code can still
emit directly into them through `Metric_Collector::record()`.

## Relationship to PRs 6 and 7

PR 8 follows the exact same pattern as PRs 6 and 7
([`tool-execution.md`](tool-execution.md), [`chat-turn.md`](chat-turn.md)):

- Metrics class is a pure definitions module with a filter-driven
  opt-out and a `register( $registry )` adapter.
- Observer is a singleton with `attach()` / `detach()` /
  `active_streams()` methods.
- Every metric has a Goodhart pairing.
- Every metric stays in the Internal privacy tier.

What's **new** in PR 8:

- **Three new `do_action` hooks** in the base plugin
  (`wp_mcp_ai_sse_stream_started`, `…_chunk_sent`, `…_stream_ended`).
  These are documented here rather than being deferred to a separate
  core PR because the hooks are narrowly scoped (pure notifications,
  no behaviour change) and trivially small. The `tool_raised` hook
  for uncaught exceptions tracked separately in the rollout plan is
  broader in scope and remains deferred.
- **Cancellation is a first-class outcome**, not an error. This is
  enforced by the metric set (dedicated `stream.cancelled.count`) and
  by the observer's outcome routing (only `failed` increments
  `stream.error.count`).
