# Inline-Async-Tick Pattern

> **Status:** Stable. Trait introduced in v1.2.0. First production
> consumer is the Mine Memories job (PR #4916, originally inline-coded;
> refactored to consume the shared trait in this PR alongside the Tool
> Async Executor adoption).

The **inline-async-tick** pattern is the plugin's defence against
WordPress sites where the WP-Cron loopback never fires:

- Hosts with `define( 'DISABLE_WP_CRON', true )` (the common
  managed-WordPress configuration).
- Hosts where `wp-cron.php` cannot be reached over loopback HTTP
  (firewall, hostname resolution, plugin-killing security headers).

Without this pattern, any operator-initiated background job that relies
on `wp_schedule_single_event( time(), … )` to fire its first tick will
sit at `status: queued` (or `status: pending` for async tools) forever
on those hosts — even though the chat client polls for status updates
every 2 seconds. The user sees a perpetually spinning button.

## The four primitives

The pattern is encoded as a reusable trait at
`includes/traits/trait-wp-mcp-ai-inline-async-tick.php`. Host classes
compose its protected static helpers:

| Helper | Purpose |
| --- | --- |
| `inline_async_detach_worker_from_client()` | Calls `ignore_user_abort()` + `fastcgi_finish_request()` (both `function_exists()`-guarded) so the inline worker survives the response flush. CLI / mod_php fall back to a plain shutdown handler. |
| `inline_async_acquire_tick_lock( $key, $group, $ttl )` | Two-layer cooperative lock: `wp_cache_add()` (atomic on persistent object caches) layered on a transient (cross-process safe on default WordPress.org installs). |
| `inline_async_release_tick_lock( $key, $group )` | Idempotent release; safe in `finally`. |
| `inline_async_should_loop( $started_at, $has_work, $budget )` | Returns true only when `DISABLE_WP_CRON` is set, more work remains, and the wall-clock budget (default 20 s) has not been exhausted. Used by the `DISABLE_WP_CRON` inline drain loop. |
| `inline_async_kick_enabled( $job_id, $class )` | Resolves the `wp_mcp_ai_inline_kick_enabled` filter. Operator escape hatch. |
| `inline_async_run_kick( $class, $job_id, $callable )` | Wraps a tick callable with duration measurement and the `wp_mcp_ai_inline_kick_completed` observability action. |

## The four moving parts in a host class

Every consumer of the trait wires the same four moving parts:

1. **Inline kick on enqueue.** Right after `wp_schedule_single_event()`,
   register a `shutdown` action at priority 20 that calls the host
   class's `kick_inline( $job_id )` method. The shutdown action runs
   after the REST/admin response is flushed, so the user is never
   blocked by the inline worker.

2. **`kick_inline( $job_id )`.** Re-reads job state, returns early if
   the cron loopback has already advanced the job, then calls
   `inline_async_detach_worker_from_client()` and delegates to the
   tick handler. Wrapped in `inline_async_run_kick()` for observability.

3. **Cooperative tick lock around the tick handler.** The tick handler
   acquires the per-job lock before doing any work and releases it in a
   `finally`. The lock prevents an inline shutdown worker and a delayed
   cron loopback from double-processing the same job. The handler
   **must** also re-check the job's status field after taking the lock,
   so a late cron run cannot re-execute a job the inline worker has
   already finished.

4. **REST self-heal on stale state.** The REST endpoint that exposes
   job status (typically a 2-second poll loop on the chat client)
   schedules a `shutdown` kick when the job has been stuck past a
   small staleness threshold (5 s for queued; class-specific). The
   response payload is unchanged.

## Active consumers (Tier 1)

| Entry | Host class | Tick hook | Stale threshold |
| --- | --- | --- | --- |
| 1 | `WP_MCP_AI_Transcript_Mining_Job` (Mine Memories) | `wp_mcp_ai_tx_mine_tick` | 5 s |
| 2 | `WP_MCP_AI_Tool_Async_Executor` | `wp_mcp_ai_async_tool_execution` | 5 s |
| 3 | `NVOOS_SaaS_Controller_Apply_Job` (SaaS Controller addon) | `nvoos_saas_controller_apply_tick` | 5 s |
| 4 | `WP_MCP_AI_Crawler` (Crawl4AI background poller) | `wp_mcp_ai_crawl4ai_poll_task` | 5 s (stale threshold; lock TTL 30 s) |
| 5 | `NV_oOS_Docs_Hub_Rebuild_Pipeline` (Docs Hub addon) | `nvoos_docs_hub_rebuild_tick` | n/a (single-rebuild-at-a-time; lock TTL 45 s) |
| 6 | `NV_oOS_Graphify` (Graphify reindex on post save) | `nvoos_graphify_cron_build` | n/a (fixed lock key; lock TTL 60 s) |
| 7 | `WP_MCP_AI_Harness_Eval_Scheduler` (eval tick) | `wp_mcp_ai_harness_eval_tick` | n/a (first-schedule only; lock TTL 120 s) |
| 8 | `WP_MCP_AI_Gemini_Video_Generation_Service` (Veo polling) | `wp_mcp_ai_poll_veo_video` | n/a (per-job lock prefix; lock TTL 30 s) |

## Cross-cutting controls

- **Filter `wp_mcp_ai_inline_kick_enabled`** — global escape hatch.
  Default `true`. A misbehaving host can return `false` (globally or
  per-class/per-job) to disable the entire pattern without uninstalling
  the plugin. Mirrors `DISABLE_WP_CRON` semantics: cron is still the
  fallback path.
- **Action `wp_mcp_ai_inline_kick_completed`** — fires once per kick
  with `( $class, $job_id, $duration_ms, $success )`. The Pro
  measurement bootstrap can subscribe and record
  `inline_kick.duration_ms` and `inline_kick.failure.count` for OTel.

## PHP 7.4 compatibility

The trait targets PHP 7.4+ (the base plugin's floor). No enums, no
`readonly`, no union return types. All members are non-private to keep
trait methods callable from any composing host.

## Testing

Two test fixtures are provided:

- `tests/test-inline-async-tick-trait.php` — exercises the trait in
  isolation against a fake host class. Reusable as a template for
  future trait additions.
- `tests/test-tool-async-executor-inline-async.php` — verifies the
  Tool Async Executor end-to-end (shutdown registration, lock
  contention, REST self-heal staleness threshold, escape-hatch filter).
- `tests/test-crawl4ai-inline-kick.php` — Slice 3: Crawl4AI poller
  (shutdown kick registration, lock contention, filter, skip-polling bail).
- `addons/docs-hub/tests/test-rebuild-pipeline-inline-kick.php` — Slice 4:
  Docs Hub rebuild pipeline (shutdown kick registration, lock contention,
  filter, idle/done bail).
- `tests/graphify/test-graphify-inline-kick.php` — Slice 5a: Graphify reindex
  (shutdown kick on post save, lock contention, filter, draft skip).
- `tests/test-harness-eval-scheduler-inline-kick.php` — Slice 5b: Harness
  eval scheduler (first-schedule shutdown kick, lock contention, filter,
  do_tick no-op on empty site).
- `tests/test-veo-inline-kick.php` — Slice 6: Gemini Veo polling
  (tick-lock constant assertions, lock contention bail, missing-metadata bail,
  filter disable).

The Mine Memories regression suite at
`tests/test-transcript-mining-job.php` covers the original consumer.

## See also

- [`docs/guides/developer/tool-development/async-tool-execution-guide.md`](../guides/developer/tool-development/async-tool-execution-guide.md)
  — async-tool authoring guide; references this pattern.
- [`docs/guides/developer/testing/CRON_TESTING_GUIDE.md`](../guides/developer/testing/CRON_TESTING_GUIDE.md)
  — testing strategy for cron-driven jobs.
- PR #4916 — original Mine Memories implementation that the trait
  generalises.
