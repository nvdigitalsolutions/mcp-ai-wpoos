# Large-Data Operations

> **Phase 1 of the [Massive-Data Hardening plan](#)** — primitives only.
> Phases 2–5 (tool refactors, agentic-loop output guard, Action Scheduler
> integration, `wp mcp-ai migrate` command) ship in follow-up PRs.

This document describes the two reusable primitives every NV oOS tool, async
job, or migration script should use when iterating over potentially large
datasets:

- `WP_MCP_AI_Memory_Manager` (memory hygiene + throttling)
- `WP_MCP_AI_Batch_Iterator` (batched / seek-based iteration with
  checkpointing and DLQ failure isolation)

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
