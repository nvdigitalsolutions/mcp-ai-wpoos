# Budget Envelopes

Budget envelopes are the anti-Goodhart guard for reward-driven systems.
A reward function can optimize for "pass more cases" without bound; an
envelope makes the operator's constraints explicit so breaches become
observable signals instead of end-of-month invoice surprises.

## What a budget envelope is

A declarative cap on a measurable quantity — cost USD, token count,
error count, latency-minutes — tied to one or more metric ids. When any
observed metric is recorded, its value is added to the envelope's
accumulator. Crossing `warn_ratio × limit` fires a warn signal; crossing
`limit` fires an exceeded signal. Neither signal blocks the collector or
short-circuits a tool call — they are strictly observability hooks.

The **core does not veto work**. Deployments that want hard stops wire
them in via their own `wp_mcp_ai_budget_exceeded` listener (raise a
`WP_Error`, throttle a queue, flip a feature flag). This keeps the core
predictable and gives operators the policy surface without forcing one.

## Registering an envelope

```php
add_action( 'wp_mcp_ai_register_budgets', function ( $registry ) {
    $registry->register( [
        'slug'           => 'daily_model_cost',
        'label'          => 'Daily model cost',
        'metric_ids'     => [ 'model.cost_usd' ],
        'limit'          => 25.00,
        'warn_ratio'     => 0.8,      // warn at $20
        'unit'           => 'usd',
        'scope'          => WP_MCP_AI_Budget_Envelope::SCOPE_PERSISTENT,
        'window_seconds' => DAY_IN_SECONDS,
        'tags'           => [ 'env' => 'prod' ],
    ] );
} );
```

### Required fields

| Field | Meaning |
|-------|---------|
| `slug` | Unique identifier. Snake-case, used in hooks and admin. |
| `metric_ids` | One or more metric ids whose values accumulate against the envelope. Matched case-insensitively, trimmed. |
| `limit` | Positive number. Must be `> 0`. |

### Optional fields

| Field | Default | Meaning |
|-------|---------|---------|
| `label` | slug | Human-readable name for the dashboard. |
| `warn_ratio` | `0.8` | Fraction of `limit` at which warn fires (clamped to `[0, 1]`). |
| `unit` | `''` | Display unit (the numeric limit is unit-agnostic). |
| `scope` | `request` | `request` (per-PHP-request) or `persistent` (option-backed). |
| `window_seconds` | `0` | Persistent-scope rollover window. `0` means no rollover. |
| `tags` | `[]` | Static label map propagated to signal payloads. |

## Scopes

- **`request`** — accumulator lives in memory for the current PHP request.
  Perfect for per-response caps ("this HTTP call must not spend more
  than $0.05") or per-session envelopes in an SSE stream.
- **`persistent`** — accumulator is persisted in a single non-autoloaded
  option (`wp_mcp_ai_budget_accumulators`). Combined with
  `window_seconds`, this gives daily/weekly/monthly budgets. The window
  rolls the next time `get_consumption()` or `consume()` is called after
  the window elapses — there is no cron dependency.

Request-scope is the default because it is the safest: values never
outlive the request, so a bug in a subscriber can't poison a persistent
cap.

## Observable signals

Both actions receive `( WP_MCP_AI_Budget_Envelope $envelope, float $consumed, float $limit )`:

- `wp_mcp_ai_budget_warned` — fires once per scope-window when
  consumption first crosses `warn_threshold`.
- `wp_mcp_ai_budget_exceeded` — fires once per scope-window when
  consumption first crosses `limit`. If consumption jumps straight past
  the warn threshold (e.g. a single big event), `warned` also fires
  synchronously so subscribers that only listen for warn still see it.

Both signals are idempotent within a scope window: listeners won't be
hammered if many metrics land after the breach.

## Manual consumption

For values that don't flow through the metric collector (legacy paths,
external systems), call `consume()` directly:

```php
$registry = WP_MCP_AI_Budget_Registry::get_instance();
$registry->consume( 'daily_model_cost', 0.42 );
```

`consume()` returns the new accumulator value, or a `WP_Error` if the
slug is unknown / the value isn't numeric.

## Dashboard

The read-only measurement dashboard renders a **Budget Envelopes** panel
with per-envelope consumption, limit, utilization percentage, and an
`ok` / `warn` / `exceeded` state pill. Persistent-scope envelopes get a
**Reset** button (nonce + `manage_options`) so operators can zero an
accumulator mid-window without a DB poke.

## Anti-Goodhart notes

- **Envelopes are observability, not enforcement.** They make spend
  visible and notify subscribers. A reward function that's found a way
  to spike `model.cost_usd` will still trip the envelope — but only
  something listening to `wp_mcp_ai_budget_exceeded` can act on it.
- **Zero-valued observations are ignored.** A zero-cost event doesn't
  consume budget, so spurious tool pings can't wake the warn hook.
- **Non-numeric values are ignored.** The listener is defensive against
  shape drift in the event payload — a bug in an unrelated metric
  emitter can't poison budget accounting.
- **Idempotent signals.** `warned`/`exceeded` fire at most once per
  scope-window. An operator listening to these hooks will never get a
  flood of duplicates during a runaway loop.
