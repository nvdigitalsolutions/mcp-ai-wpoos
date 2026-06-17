# Registering a Job Source for the Chat Tasks Drawer

This guide explains how to make a new background-task subsystem visible in the
NV oOS Chat Tasks Drawer and the `/mcp-ai/v1/cron-status` REST endpoint.

---

## Overview

The Tasks Drawer aggregates jobs from multiple subsystems (async tools, Crawl4AI,
transcript mining, HITL approvals, etc.) through a single WordPress filter:

```
wp_mcp_ai_cron_status_job_sources
```

Any code — base plugin, addon, or third-party plugin — can register a **job-source
adapter** on this filter. The adapter must implement
`Interface_WP_MCP_AI_Cron_Status_Job_Source`.

---

## Step 1 — Create the adapter class

Create a PHP class that implements the two-method interface:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Example job-source adapter.
 *
 * @implements Interface_WP_MCP_AI_Cron_Status_Job_Source
 */
class My_Plugin_Job_Source implements Interface_WP_MCP_AI_Cron_Status_Job_Source {

    /**
     * Unique source slug (snake_case, lowercase).
     *
     * Appears as the `source` field on every record this adapter returns.
     * Choose a name that won't collide with other sources — prefix with your
     * plugin slug if in doubt (e.g. `myplugin_background_task`).
     *
     * @return string
     */
    public function get_slug() {
        return 'my_plugin_task';
    }

    /**
     * Return current jobs from your backing store.
     *
     * The returned array MUST be keyed by job_id. Each value MUST be an
     * array containing at least `job_id`, `kind`, and `status`. All other
     * fields are defaulted by WP_MCP_AI_Cron_Status_Service::normalize_source_record().
     *
     * Scope your results to $user_id when possible; the service applies a
     * defensive second-pass filter regardless.
     *
     * IMPORTANT: Do NOT throw. If your backing store is unavailable, return
     * an empty array and log internally.
     *
     * @param int             $user_id      Requesting user (0 = current user).
     * @param int|string|null $assistant_id Optional assistant scope.
     * @return array<string,array<string,mixed>>
     */
    public function get_jobs( $user_id = 0, $assistant_id = null ) {
        $jobs = My_Plugin_Background_Task::get_active_jobs( $user_id );

        $records = array();
        foreach ( $jobs as $job ) {
            $job_id = (string) $job['id'];
            $records[ $job_id ] = array(
                'job_id'       => $job_id,
                'kind'         => 'my_plugin_task',
                'status'       => $job['status'],           // queued|running|completed|failed|cancelled
                'created_by'   => (int) $job['user_id'],
                'assistant_id' => '',                       // omit if not applicable
                'started_at'   => (int) $job['created_at'],
                'updated_at'   => (int) $job['updated_at'],
                'eta'          => null,                     // Unix timestamp or null
                'progress'     => $job['percent'] ?? null,  // 0–100 or null
                'message'      => $job['label'] ?? '',
                'cancellable'  => in_array( $job['status'], array( 'queued', 'running' ), true ),
                'retryable'    => ( 'failed' === $job['status'] ),
                'source'       => 'my_plugin_task',
            );
        }
        return $records;
    }
}
```

### Allowed `status` values

| Value | Meaning |
|-------|---------|
| `queued` | Waiting in a queue, not yet dispatched |
| `pending` | Accepted but not yet started |
| `running` | Actively processing |
| `polling` | Waiting on a remote API (e.g. video generation) |
| `completed` | Finished successfully |
| `failed` | Terminated with an error |
| `cancelled` | Stopped by the user |

Any other value is normalized to `pending` by the service.

---

## Step 2 — Register the adapter on the filter

Hook onto `wp_mcp_ai_cron_status_job_sources` **at priority 10 or later** so
the base-plugin sources (registered at priority 10) are already present. Use a
higher number to run after them; use a lower number to run before.

```php
add_filter( 'wp_mcp_ai_cron_status_job_sources', 'my_plugin_register_job_source', 20, 1 );

function my_plugin_register_job_source( array $sources ) {
    $sources['my_plugin_task'] = new My_Plugin_Job_Source();
    return $sources;
}
```

**Addon pattern** — register inside your addon's entry-point file so the filter
only fires when the addon is active:

```php
// Inside addons/my-addon/my-addon.php (loaded by the plugin):
if ( class_exists( 'Interface_WP_MCP_AI_Cron_Status_Job_Source' ) ) {
    require_once __DIR__ . '/includes/class-my-addon-job-source.php';
    add_filter( 'wp_mcp_ai_cron_status_job_sources', function( array $sources ) {
        $sources['my_addon_task'] = new My_Addon_Job_Source();
        return $sources;
    }, 20 );
}
```

The `class_exists` guard ensures graceful degradation when the base plugin is
absent or an older version that predates the interface.

---

## Step 3 — Optional: support cancel / retry

If your backing store supports cancellation or retry, implement those methods
and wire them up to the filter-dispatched cancel/retry pipeline.

The REST controller calls `try_source_cancel( $job_id, $user_id )` and
`try_source_retry( $job_id, $user_id )` before falling back to the async-tool
executor. These helpers iterate the registered sources looking for a method
named `cancel_job` or `retry_job`. You can add these methods to your adapter:

```php
/**
 * Cancel an active job.
 *
 * Called by the REST controller when the user clicks Cancel.
 * Return true on success, false if the job_id doesn't belong to this source.
 *
 * @param string $job_id  Job ID to cancel.
 * @param int    $user_id Requesting user.
 * @return bool
 */
public function cancel_job( $job_id, $user_id ) {
    return My_Plugin_Background_Task::cancel( $job_id, $user_id );
}

/**
 * Retry a failed job.
 *
 * @param string $job_id  Job ID to retry.
 * @param int    $user_id Requesting user.
 * @return bool
 */
public function retry_job( $job_id, $user_id ) {
    return My_Plugin_Background_Task::retry( $job_id, $user_id );
}
```

The `Interface_WP_MCP_AI_Cron_Status_Job_Source` interface does **not** require
these methods — they are discovered via `method_exists()` at runtime.

---

## Step 4 — Optional: push step updates

If your job has meaningful sub-steps, push them as they happen using
`WP_MCP_AI_Job_Notifier::record_step()`. This places a step record in the job's
`steps[]` ring-buffer and emits an immediate `job:step` SSE frame to all
connected clients:

```php
WP_MCP_AI_Job_Notifier::record_step(
    $job_id,
    'Chunk 3 of 10 processed',   // human-readable label
    'running',                    // pending|running|completed|failed
    array( 'chunk' => 3 )         // optional metadata array
);
```

---

## Step 5 — Write PHPUnit tests

Cover the key contract requirements:

```php
class Test_My_Plugin_Job_Source extends WP_UnitTestCase {

    public function test_get_slug_returns_snake_case_string() {
        $source = new My_Plugin_Job_Source();
        $this->assertSame( 'my_plugin_task', $source->get_slug() );
    }

    public function test_get_jobs_returns_array() {
        $source = new My_Plugin_Job_Source();
        $this->assertIsArray( $source->get_jobs() );
    }

    public function test_records_are_keyed_by_job_id() {
        // Stub a job in your backing store ...
        $source  = new My_Plugin_Job_Source();
        $records = $source->get_jobs( get_current_user_id() );

        foreach ( $records as $key => $record ) {
            $this->assertSame( $key, $record['job_id'], 'Array key must equal job_id' );
        }
    }

    public function test_registered_via_filter() {
        add_filter( 'wp_mcp_ai_cron_status_job_sources', 'my_plugin_register_job_source', 20 );
        $sources = apply_filters( 'wp_mcp_ai_cron_status_job_sources', array() );
        $this->assertArrayHasKey( 'my_plugin_task', $sources );
        $this->assertInstanceOf( 'My_Plugin_Job_Source', $sources['my_plugin_task'] );
        remove_filter( 'wp_mcp_ai_cron_status_job_sources', 'my_plugin_register_job_source', 20 );
    }

    public function test_get_jobs_does_not_throw_when_backing_store_unavailable() {
        // Simulate unavailability and assert no exception is raised.
        $source  = new My_Plugin_Job_Source();
        $records = $source->get_jobs();
        $this->assertIsArray( $records );
    }
}
```

---

## Checklist

- [ ] Implement `Interface_WP_MCP_AI_Cron_Status_Job_Source` (`get_slug()` + `get_jobs()`).
- [ ] Every record returned by `get_jobs()` contains `job_id`, `kind`, and `status`.
- [ ] Array is keyed by `job_id`.
- [ ] `get_jobs()` never throws — catches all exceptions internally.
- [ ] Filter registered at priority ≥ 10 (prefer 20 for addons).
- [ ] (Optional) `cancel_job()` / `retry_job()` implemented if the backing store supports them.
- [ ] (Optional) `WP_MCP_AI_Job_Notifier::record_step()` called for granular progress.
- [ ] PHPUnit test covers `get_slug()`, `get_jobs()` return shape, filter registration, and no-throw guarantee.

---

## Reference

- Interface: [`includes/interfaces/interface-wp-mcp-ai-cron-status-job-source.php`](../../../../includes/interfaces/interface-wp-mcp-ai-cron-status-job-source.php)
- Built-in adapters: [`includes/services/job-sources/`](../../../../includes/services/job-sources/)
- Service (normalization): [`includes/services/class-wp-mcp-ai-cron-status-service.php`](../../../../includes/services/class-wp-mcp-ai-cron-status-service.php)
- Architecture overview: [`docs/features/chat/cron-status-integration.md`](../../features/chat/cron-status-integration.md)
