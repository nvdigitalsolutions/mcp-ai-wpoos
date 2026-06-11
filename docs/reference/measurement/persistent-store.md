# Persistent Metric Store (PR 9)

The persistent store turns the in-memory metric buffer from PRs 3–8
into durable rows on a custom WordPress table so the dashboard,
CLI runner (PR 11), and future alerting pipelines can query
historical data.

## Why this exists

Prior PRs recorded events through
`WP_MCP_AI_Metric_Collector::record()` into a ring buffer. That
buffer serves a single request. The dashboard (PR 4 / 4.1) could
only show whatever was in memory at the moment a listener fired.
PR 9 introduces a custom table — `{prefix}mcp_ai_metric_events` —
that persists events across requests so:

- Dashboards can show time-range sparklines (PR 9.1 / PR 10).
- CLI regression checks can compare runs (PR 11).
- Retention policy is enforced uniformly, per privacy tier.

## Components

| Class | Responsibility |
|-------|---------------|
| `WP_MCP_AI_Metric_Event_Store` | Owns the custom table. Schema install/migration, batched inserts, time-range queries, tier-scoped purge. |
| `WP_MCP_AI_Metric_Persister` | Attaches to `wp_mcp_ai_metric_recorded`. Buffers events per-request; one batched INSERT on `shutdown`. |
| `WP_MCP_AI_Metric_Retention` | Daily cron (`wp_mcp_ai_metric_retention_purge`) that enforces per-tier TTLs. |

## Table schema (v1)

```sql
CREATE TABLE {prefix}mcp_ai_metric_events (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  metric_id VARCHAR(128) NOT NULL,
  metric_value DOUBLE NOT NULL,
  metric_type VARCHAR(32) NOT NULL,
  metric_unit VARCHAR(32) NOT NULL,
  privacy VARCHAR(16) NOT NULL,
  recorded_at DATETIME NOT NULL,
  context LONGTEXT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY metric_recorded (metric_id, recorded_at),
  KEY privacy_recorded (privacy, recorded_at)
);
```

The installed version is tracked in the
`wp_mcp_ai_metric_events_schema_version` option and migrated via
`dbDelta`. Bump `WP_MCP_AI_Metric_Event_Store::SCHEMA_VERSION` to
roll out future changes.

## Privacy invariants enforced by the store

1. **Restricted tier is never persisted.** The `privacy-matrix.md`
   document commits to "Restricted raw events never persisted
   (in-memory + immediate aggregate)". That is stronger than the
   7d retention the rollout plan originally listed, so the plan is
   overridden: Restricted events are dropped at the persister
   barrier *and* defensively dropped again in the event store's
   `insert_batch()`. If a stray Restricted row somehow reaches the
   table (e.g. from a pre-PR-9 bug), the retention cron will
   **not** delete it — leaving it in place is the signal that
   something upstream is misbehaving.
2. **Unknown tiers coerce to `internal`.** Rather than silently
   dropping a metric with a misspelled tier, the store writes it
   as `internal` so the operator retains visibility while they
   fix the classification.
3. **Context JSON is already sanitised.** The collector sanitises
   `context` before firing `wp_mcp_ai_metric_recorded`; the
   persister does not re-read raw payloads.

## Retention defaults

| Tier | TTL (days) | Filterable |
|------|-----------|-----------|
| `public`    | 365 | `wp_mcp_ai_measurement_retention` |
| `internal`  | 90  | same |
| `sensitive` | 30  | same |
| `restricted` | — (never persisted) | — |

Values are clamped to `[1, 3650]` days; out-of-range filter
returns fall back to defaults.

Site owners can override any tier by returning a (possibly partial)
array from the filter:

```php
add_filter(
    'wp_mcp_ai_measurement_retention',
    static function ( $ttls ) {
        $ttls['sensitive'] = 14; // Tighten sensitive retention.
        return $ttls;
    }
);
```

The retention sweep fires `wp_mcp_ai_measurement_retention_completed`
with per-tier deletion counts once the daily cron finishes.

## Performance posture

- **Zero synchronous DB writes on `record()`.** The persister
  appends to an in-memory array; the actual INSERT happens once
  on `shutdown`.
- **Single batched INSERT per request.** All events are committed
  in one round-trip. The batch is chunked into at most
  `WP_MCP_AI_Metric_Event_Store::MAX_BATCH_ROWS` (200) rows per
  statement to stay well under MySQL's default
  `max_allowed_packet`.
- **Bounded per-request buffer.** `wp_mcp_ai_persister_buffer_max`
  (default 2048) caps how many events a pathological request can
  accumulate. Overflow events are dropped at buffer time —
  they still appear in the in-memory collector buffer, and any
  other exporter attached to `wp_mcp_ai_metric_recorded` is
  unaffected.
- **All queries are bounded.** `query_by_metric()` requires a
  LIMIT (default 500, ceiling 5000) and a time range. There is
  no "select everything" code path.

## Hooks

| Hook | Purpose | Default |
|------|---------|---------|
| `wp_mcp_ai_persister_enabled` (filter, bool) | Disable custom-table persistence entirely. | `true` |
| `wp_mcp_ai_persister_should_persist` (filter, `$should`, `$event`) | Per-event veto. | `true` |
| `wp_mcp_ai_persister_buffer_max` (filter, int) | Per-request buffer cap. | `2048` |
| `wp_mcp_ai_measurement_retention` (filter, array) | Per-tier TTL in days. | See defaults table. |
| `wp_mcp_ai_measurement_retention_completed` (action, `$deleted`, `$ttls`) | Fires after a retention sweep. | — |
| `wp_mcp_ai_metric_retention_purge` (cron, daily) | Runs `Metric_Retention::run()`. | scheduled on activation + on `init` as a belt-and-braces |

## Query API (for PR 9.1 dashboard + PR 11 CLI)

```php
$store = WP_MCP_AI_Metric_Event_Store::get_instance();

// Recent tool-execution events over the last hour.
$rows = $store->query_by_metric(
    'tool.execution.count',
    time() - HOUR_IN_SECONDS,
    time(),
    500
);

// Diagnostic: row counts per privacy tier.
$counts = $store->count_by_privacy();
```

## Testing

- `tests/measurement/test-metric-event-store.php` — 12 tests:
  schema install idempotency, roundtrip, Restricted drop barrier,
  unknown-tier coercion, time-bound queries, LIMIT clamping,
  tier-scoped purge, large-batch chunking.
- `tests/measurement/test-metric-persister.php` — 7 tests: buffer
  (no DB write until shutdown), explicit flush, Restricted drop at
  the buffer barrier, veto filter, disabled filter, buffer cap,
  detach.
- `tests/measurement/test-metric-retention.php` — 8 tests:
  defaults, filter + clamping, malformed-filter fallback,
  per-tier purge, completion action, schedule/unschedule
  idempotency, Restricted-tier-not-purged invariant.

## Deferred to PR 9.1

The rollout plan's PR 9 bullet also mentions "Dashboard gains
time-range filter and sparkline renders". That is a UI-heavy
change that bundles poorly with the store. It is tracked as
**PR 9.1** so PR 9's review surface stays focused on data
durability. The query API above already exposes what the
dashboard will need.
