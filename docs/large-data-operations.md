# Large-Data Operations

> **Phases 1 + 2 + 3 of the [Massive-Data Hardening plan](#)** — primitives,
> first wave of tool refactors, and the agentic-loop output guard. Phases
> 4–5 (Action Scheduler integration, `wp mcp-ai migrate` command) ship in
> follow-up PRs.

This document describes the reusable primitives every NV oOS tool, async
job, or migration script should use when iterating over potentially large
datasets:

- `WP_MCP_AI_Memory_Manager` (memory hygiene + throttling)
- `WP_MCP_AI_Batch_Iterator` (batched / seek-based iteration with
  checkpointing and DLQ failure isolation)
- `WP_MCP_AI_Tool_Artifact_Helper` (Phase 2 — stream oversized tool
  results to artifacts instead of inlining them in the LLM context)
- `WP_MCP_AI_Tool_Bulk_Operation_Interface` (Phase 2 — opt-in marker so
  the tool registry can auto-dispatch heavy calls to the async queue)
- `WP_MCP_AI_Data_Budget_Tracker` (Phase 3 — cumulative byte-budget guard
  for the agentic loop)

These primitives apply the operational principles from the
[Delicious Brains massive-data-migrations playbook](https://deliciousbrains.com/building-custom-wp-cli-commands-massive-data-migrations/)
and the WP-CLI / WordPress.com VIP guides.

---

## When to use which mode

| Scenario | Use |
|---|---|
| `WP_Query` / `get_posts` over a known post type | `paged_iterate()` |
| Custom table, postmeta, FHIR / vector store, or **very large** WP tables (>100k rows) | `seek_iterate()` |

`seek_iterate()` is preferred for very large tables because it uses
`WHERE id > $last_id ORDER BY id ASC LIMIT $n` instead of `OFFSET` — it stays
fast at any depth, and is stable when rows are inserted/deleted mid-run.

---

## `WP_MCP_AI_Memory_Manager`

```php
// Reset $wpdb->queries, in-memory object cache, run gc_collect_cycles().
WP_MCP_AI_Memory_Manager::stop_the_insanity();

// True if memory_get_usage() crosses the configured % of WP_MAX_MEMORY_LIMIT.
if ( WP_MCP_AI_Memory_Manager::should_throttle( 75 ) ) {
    // pause / abort / sleep
}

// Sleep + recheck; returns false if the caller should abort.
if ( ! WP_MCP_AI_Memory_Manager::throttle_or_abort() ) {
    return new WP_Error( 'memory_pressure', 'Aborting run.' );
}
```

### Filters & actions

| Hook | Type | Purpose |
|---|---|---|
| `wp_mcp_ai_memory_threshold` | filter | Override the throttle threshold percentage. |
| `wp_mcp_ai_memory_suspend_cache_addition` | filter | Default: false. When true, calls `wp_suspend_cache_addition( true )` after each cleanup. |
| `wp_mcp_ai_memory_after_cleanup` | action | Hook in custom flushes (WP Rocket, Yoast indexable, Elasticsearch bulk indexer). |
| `wp_mcp_ai_post_batch_hook` | action | Per-batch cleanup hook for caller-specific state. |

---

## `WP_MCP_AI_Batch_Iterator`

### Paged mode

```php
$iterator = new WP_MCP_AI_Batch_Iterator( 'rebuild-thumbnails-2026-05' );

foreach ( $iterator->paged_iterate(
    array( 'post_type' => 'attachment', 'post_status' => 'inherit' ),
    100
) as $batch ) {
    foreach ( $batch as $post ) {
        $iterator->process_item( $post->ID, function () use ( $post ) {
            // ... do work; throw or return WP_Error on failure ...
        } );
    }
}

$iterator->complete(); // Deletes the checkpoint when done.
```

### Seek mode (preferred for very large tables)

```php
global $wpdb;

$iterator = new WP_MCP_AI_Batch_Iterator( 'reindex-vectors' );

foreach ( $iterator->seek_iterate(
    $wpdb->posts,
    'ID',
    "post_type = 'product'",
    500
) as $rows ) {
    foreach ( $rows as $row ) {
        $iterator->process_item( (int) $row->ID, $callback );
    }
}
```

### Resuming after a failure

```php
$iterator = WP_MCP_AI_Batch_Iterator::resume( $run_id );
// Continues from the persisted last_id / processed / errors.
```

### Filters

| Filter | Default | Purpose |
|---|---|---|
| `wp_mcp_ai_batch_size` | (caller arg) | Override per-batch chunk size. |
| `wp_mcp_ai_batch_sleep_us` | 0 | Microseconds to sleep between batches. |
| `wp_mcp_ai_iterator_max_items` | 0 | Hard ceiling on items processed in one run. |
| `wp_mcp_ai_memory_threshold` | 75 | Memory-usage % at which to throttle. |

### Failure isolation

`process_item()` catches `Exception` and `Throwable` and forwards the failure
to `WP_MCP_AI_Dead_Letter_Queue` (type `job_queue`) with the run id, item id,
context, and exception message. The loop continues so a single bad row never
aborts a 10k-row migration. Disable via the constructor option
`'dlq_enabled' => false`.

### Checkpointing

Each iterator persists `{run_id, last_id, processed, errors, started, updated}`
to the option `wp_mcp_ai_migration_checkpoint_{run_id}` (autoload=no) after
every batch. Call `complete()` on success to delete it.

---

## Optional `WP_MCP_AI_Tool_Bulk_Operation_Interface`

Tools that operate on potentially large datasets can implement this interface
so the registry / migration runner can detect them and route calls through
the async queue when the estimated total exceeds
`wp_mcp_ai_bulk_async_threshold`. (Auto-dispatch wiring lands in Phase 2.)

```php
interface WP_MCP_AI_Tool_Bulk_Operation_Interface {
    public function get_batch_size();
    public function is_resumable();
    public function get_checkpoint_key( $arguments );
    public function estimate_total( $arguments );
}
```

---

## Phase 2 — `WP_MCP_AI_Tool_Artifact_Helper`

When a tool produces a result with hundreds (or thousands) of rows, returning
the full payload to the LLM blows up token usage, SSE message size, and
client-side render time. The artifact helper persists the full payload to a
24-hour transient and returns a small envelope:

```php
$rows = []; // produced by your iterator loop

if ( WP_MCP_AI_Tool_Artifact_Helper::should_stream_to_artifact( count( $rows ), $this->get_slug() ) ) {
    $artifact = WP_MCP_AI_Tool_Artifact_Helper::stream_to_artifact( $rows, $this->get_slug() );
    return array(
        'success'           => true,
        'rows_summary'      => array_slice( $rows, 0, 20 ),
        'rows_artifact'     => $artifact, // { summary, count, truncated, artifact_id, artifact_url, original_bytes, expires_at }
    );
}
```

### Filters

| Filter | Default | Purpose |
|--------|---------|---------|
| `wp_mcp_ai_max_inline_rows` | `100` | Row count above which results stream to an artifact instead of being inlined. |
| `wp_mcp_ai_tool_max_items` | per-tool | Per-tool ceiling for `max_items` arguments. Pass `( $value, $tool_slug )`. |
| `wp_mcp_ai_tool_artifact_stored` | (action) | Fires after a payload is persisted; mirror to S3 / CCT here. |

### `resolve_max_items()` recipe

Tools that accept a `max_items` parameter should call:

```php
$max_items = WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items(
    $this->get_slug(),
    isset( $arguments['max_items'] ) ? absint( $arguments['max_items'] ) : 0,
    /* hard default */ 500
);
```

This resolves the user-supplied value, falls back to the hard default, and
applies the `wp_mcp_ai_tool_max_items` filter — so site owners can clamp
specific tools without code changes.

---

## Phase 2 — Auto-async dispatch

When a tool implements `WP_MCP_AI_Tool_Bulk_Operation_Interface` **and**
`estimate_total( $arguments ) >= wp_mcp_ai_bulk_async_threshold` (default
1000), `WP_MCP_AI_Tool_Registry::execute_tool()` will queue the call via
`WP_MCP_AI_Async_Job_Queue` and return a job-handle envelope instead of
running inline:

```php
array(
    'success'        => true,
    'async'          => true,
    'job_id'         => 42,
    'tool_slug'      => 'media_library_optimizer',
    'estimated_rows' => 12500,
    'message'        => 'media_library_optimizer call (~12500 rows) queued as async job #42.',
)
```

Auto-dispatch is **off by default** (the Phase 4 Action Scheduler integration
is required for the worker side). Enable explicitly:

```php
define( 'WP_MCP_AI_BULK_AUTO_ASYNC', true );
// or:
add_filter( 'wp_mcp_ai_bulk_auto_async_enabled', '__return_true' );
```

The threshold is filterable per tool:

```php
add_filter( 'wp_mcp_ai_bulk_async_threshold', function ( $threshold, $slug ) {
    return 'export_fhir_data' === $slug ? 5000 : $threshold;
}, 10, 2 );
```

---

## Phase 3 — Agentic-loop output guard

Tool results come from many places (custom REST endpoints, third-party
APIs, external scrapers) and existing per-message limits
(`WP_MCP_AI_REST_Validator::TOOL_RESULT_MAX_BYTES`, default 64 KiB) only
cap a *single* message. Long agentic loops can still flood the LLM
context by chaining many medium-sized tool calls.

`WP_MCP_AI_Data_Budget_Tracker` provides a cumulative byte budget per
chat request. The agentic loop in `WP_MCP_AI_REST::handle_chat_request()`
and `handle_chat_request_with_streaming()` constructs a tracker per
request and consults it *after* `sanitize_tool_result_for_llm()` for
each tool result.

When `should_spill( $bytes )` returns true (either because the single
message exceeds the per-message ceiling or the cumulative request budget
would be blown), the loop replaces the tool message body with a small
JSON envelope returned by
`WP_MCP_AI_Tool_Artifact_Helper::wrap_oversized_tool_result()`:

```json
{
  "truncated": true,
  "reason": "agentic_output_budget_exceeded",
  "tool_name": "expensive_tool",
  "preview": "first 256 bytes…",
  "artifact_id": "expensive_tool_e3a1…",
  "artifact_url": "https://site.test/wp-json/mcp-ai/v1/artifacts/expensive_tool_e3a1…",
  "original_bytes": 192345,
  "expires_at": 1730000000
}
```

The full payload is persisted as a transient artifact (24 h TTL by
default) and the LLM continues with the bounded envelope. The streaming
branch additionally emits a `tool_output_truncated` SSE frame so chat
UIs can surface the spill.

Filters:

- `wp_mcp_ai_agentic_loop_byte_budget` — overall per-request ceiling
  (default `1 MiB`, floor `1 KiB`).
- `wp_mcp_ai_agentic_loop_per_message_byte_budget` — per-message
  ceiling (default `64 KiB`, floor `512 B`).

Action: `wp_mcp_ai_tool_output_truncated` fires with
`( $tool_name, $original_bytes, $artifact_id, $context )` whenever a
spill happens — useful for OTel exporters or audit logs.

## Phase 4 — Action Scheduler integration

Phase 4 introduces `WP_MCP_AI_Async_Scheduler_Bridge`, a thin bridge
that dispatches queued bulk-tool jobs through [Action
Scheduler](https://actionscheduler.org) instead of waiting for the
once-per-minute WP-Cron tick that drives
`WP_MCP_AI_Async_Job_Queue::process_queue()`.

When Action Scheduler is detected at runtime
(`function_exists( 'as_enqueue_async_action' )`):

1. `WP_MCP_AI_Async_Job_Queue::queue_job()` immediately calls
   `WP_MCP_AI_Async_Scheduler_Bridge::enqueue_job( $job_id )`, which
   posts an async action under the `wp-mcp-ai-jobs` group.
2. Action Scheduler invokes
   `WP_MCP_AI_Async_Scheduler_Bridge::run_job( $job_id )` on the next
   runner tick, which delegates to
   `WP_MCP_AI_Async_Job_Queue::process_specific_job( $job_id )`.
3. Failures and retries are re-enqueued through the bridge so retries
   do not regress to minute-resolution polling.
4. `WP_MCP_AI_Tool_Registry::maybe_dispatch_async_bulk()` flips its
   default to **on** when the bridge is available — bulk tools above
   `wp_mcp_ai_bulk_async_threshold` (default `1000` rows) auto-dispatch
   without further opt-in.

When Action Scheduler is **not** loaded the bridge is a no-op:
`is_available()` returns `false`, `enqueue_job()` returns `false`, and
`Tool_Registry` keeps the legacy default-off behaviour. Existing sites
that rely on WP-Cron continue to work unchanged.

Hooks:

- `wp_mcp_ai_async_scheduler_bridge_enabled` (filter, `bool`) — global
  on/off override even when Action Scheduler is loaded.
- `wp_mcp_ai_async_scheduler_group` (filter, `string`) — Action
  Scheduler group name. Default `'wp-mcp-ai-jobs'`.
- `wp_mcp_ai_bulk_auto_async_enabled` (filter, `bool`) — per-tool
  override that runs **after** the bridge default is computed.
- `WP_MCP_AI_BULK_AUTO_ASYNC` (constant, `bool`) — site-wide kill
  switch that takes priority over the bridge default. Define it as
  `false` to force inline execution everywhere even when Action
  Scheduler is loaded.

## Phase 5 — `wp mcp-ai bulk` WP-CLI command

Phase 5 ships an operations-focused WP-CLI surface backed by
`WP_MCP_AI_CLI_Bulk_Command` (registered as `mcp-ai bulk`). It exposes
four subcommands that work against the Phase 1–4 infrastructure:

| Command | Purpose |
|---------|---------|
| `wp mcp-ai bulk audit` | Lists every tool implementing `WP_MCP_AI_Tool_Bulk_Operation_Interface` with the threshold resolved by `wp_mcp_ai_bulk_async_threshold`. |
| `wp mcp-ai bulk status` | Summarises `WP_MCP_AI_Async_Job_Queue` — counts by status, oldest queued row, and Action Scheduler bridge availability. |
| `wp mcp-ai bulk dispatch <slug> [--args=<json>] [--dry-run]` | Enqueues a bulk-interface tool with a JSON payload through the same `WP_MCP_AI_Async_Job_Queue::queue_job()` path the agentic loop uses, so it honours the AS bridge. |
| `wp mcp-ai bulk cleanup-artifacts [--dry-run]` | Walks the options table for expired `_transient_timeout_wp_mcp_ai_artifact_*` rows and deletes both the timeout and value siblings. |

All subcommands accept the standard `--format=table|json|yaml|csv`
output flags inherited from `WP_MCP_AI_CLI_Base_Command`. `dispatch`
and `cleanup-artifacts` additionally accept `--dry-run` so retro-runs
and cleanups can be previewed before mutating state.
