# Chat-Turn Instrumentation

Introduced in **PR 7** of the measurement rollout. PR 6 instrumented
individual tool calls; PR 7 instruments the chat turn that
orchestrates them — prompt/completion tokens, realised cost (USD),
wall-clock duration, and success/error outcome.

See [`rollout-plan.md`](rollout-plan.md) for the end-to-end schedule.

## What this adds

| Component | File | Role |
|---|---|---|
| `WP_MCP_AI_Chat_Turn_Metrics` | `includes/measurement/class-wp-mcp-ai-chat-turn-metrics.php` | Registers the baseline chat-turn metric definitions on `wp_mcp_ai_register_metrics` |
| `WP_MCP_AI_Chat_Turn_Observer` | `includes/measurement/class-wp-mcp-ai-chat-turn-observer.php` | Subscribes to `wp_mcp_ai_before_chat_request`, `wp_mcp_ai_after_chat_response`, and `wp_mcp_ai_cost_calculated` and calls `Metric_Collector::record()` |

## Stock metrics registered

| Metric id | Type | Unit | Direction | Counter pair | Privacy tier |
|---|---|---|---|---|---|
| `chat.turn.count` | counter | turns | neutral | `chat.turn.error.count` | internal |
| `chat.turn.error.count` | counter | turns | lower-is-better | `chat.turn.count` | internal |
| `chat.turn.duration_ms` | histogram | ms | lower-is-better | `chat.turn.error.count` | internal |
| `token_usage.prompt_tokens` | histogram | tokens | lower-is-better | `chat.turn.count` | internal |
| `token_usage.completion_tokens` | histogram | tokens | neutral | `token_usage.total_cost_usd` | internal |
| `token_usage.total_cost_usd` | histogram | usd | lower-is-better | `chat.turn.error.count` | internal |
| `chat.agentic.iterations` | histogram | iterations | neutral | `chat.turn.error.count` | internal |

Every metric declares a `counter_metric` — asserted by
`Test_WP_MCP_AI_Chat_Turn_Metrics::test_every_chat_turn_metric_declares_counter`.

Every metric stays in the **Internal** privacy tier —
asserted by `test_every_chat_turn_metric_is_internal_tier`. Richer
payloads (prompt fragments, user ids on a per-request basis, etc.)
would require re-classification to Sensitive or Restricted; see
[`privacy-matrix.md`](privacy-matrix.md).

## `chat.agentic.iterations` emission (PR 7.1)

The chat-turn observer listens on
`wp_mcp_ai_agentic_iteration_complete`, which the base plugin's REST
agentic loop fires after each iteration in both the non-streaming
and streaming (SSE) paths. During a turn the observer keeps the
maximum iteration count it has seen per `assistant_id`. When the
matching `wp_mcp_ai_after_chat_response` fires it emits **one**
histogram sample carrying that maximum and clears the counter.

Turns with no tool calls (the LLM returned content on the first
pass) never fire the iteration hook, so they never emit a sample —
this keeps the histogram free of synthetic zeros that would skew
p50/p95 towards zero.

Nested assistant calls (assistant A invokes assistant B mid-flight)
are disambiguated by `assistant_id` so each pops its own iteration
count.

## Observer lifecycle

```
wp_mcp_ai_before_chat_request (priority 5)
    └── push { assistant_id, started_at, redacted_options }

wp_mcp_ai_agentic_iteration_complete (priority 10)
    └── update per-assistant max iteration count

wp_mcp_ai_after_chat_response  (priority 95)
    ├── pop matching frame (scan top-down if top doesn't match)
    ├── record chat.turn.count
    ├── record chat.turn.error.count  (if WP_Error or response.error)
    ├── record chat.turn.duration_ms  (if frame was popped)
    ├── record chat.agentic.iterations (if iteration count > 0)
    ├── record token_usage.prompt_tokens     (if response.usage.prompt_tokens > 0)
    └── record token_usage.completion_tokens (if response.usage.completion_tokens > 0)

wp_mcp_ai_cost_calculated      (priority 95)
    └── record token_usage.total_cost_usd    (if cost_usd > 0)
```

The invocation stack is keyed by `assistant_id`. Nested calls with
different assistants (for example, an assistant fan-out orchestrator)
pop correctly regardless of completion order. A `before` without a
matching `after` times out naturally with the next request lifecycle.
An `after` without a matching `before` still emits the count and
outcome metrics but skips the duration — the observer never produces
a silently-wrong duration.

## Privacy: what leaves scope, what does not

The observer passes only the following into the metric context:

- `assistant_id` (numeric or key-sanitised string)
- `provider` (key-sanitised: `openai`, `gemini`, `ollama`, …)
- `model` (whitelisted character set)
- `user_id` (integer, only for cost hook)
- `guest` (boolean, only if the request is a guest request)
- `outcome` (`success` or `error`)

The following are **never** recorded:

- Prompt text / user messages
- Assistant completions / thinking text
- System messages
- API keys or auth headers
- Attachments / file contents
- Tool arguments and results

A string-scan test
(`Test_WP_MCP_AI_Chat_Turn_Observer::test_context_payload_stays_internal_tier`)
injects canary values into both the messages array and the options
array (including `api_key` and `system_message`) and asserts none of
them appear in any recorded metric's context blob.

## Filtering the stock set

```php
add_filter( 'wp_mcp_ai_chat_turn_metrics_definitions', function ( $definitions ) {
    // Drop the cost metric on sites that handle billing elsewhere.
    return array_values( array_filter( $definitions, static function ( $d ) {
        return 'token_usage.total_cost_usd' !== $d['id'];
    } ) );
} );
```

## Disabling the observer

```php
add_filter( 'wp_mcp_ai_chat_turn_observer_enabled', '__return_false' );
```

Stock definitions remain registered so third-party code can still
emit directly into them through `Metric_Collector::record()`.

## Relationship to PR 6

PR 7 follows the exact same pattern as PR 6
([`tool-execution.md`](tool-execution.md)):

- Metrics class is a pure definitions module with a filter-driven
  opt-out and a `register( $registry )` adapter.
- Observer is a singleton with `attach()` / `detach()` / `depth()`
  methods and an invocation stack to survive nested calls.
- Both observer hooks are attached at `before` priority 5 and
  `after` priority 95 to surround third-party short-circuits.

Sharing the pattern keeps the cognitive load per new observer low
and makes future additions (SSE observer in PR 8, agentic-iteration
observer in PR 7.1) near-mechanical.
