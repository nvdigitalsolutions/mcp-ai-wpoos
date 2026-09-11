# Crawler

## Purpose

Wave E6 port surface (sub-cluster 5 — crawler). The **Crawl4AI background
coordinator** from the base plugin's `includes/crawler/` — schedules,
polls, and reconciles long-running remote crawl tasks via WP-Cron with
the inline-async-tick fast path. Ported into the AI addon per decision
D4 (engine pieces fold into `nvoos-content-graph-ai` under the
`Engine\` namespace), together with its required collaborator, the
inline-async-tick trait.

## Tier

| | |
|---|---|
| **Distribution** | AI addon (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAi\Plugin::registerEngine()` → `Crawler::init()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); the base plugin bootstrap (`WP_MCP_AI_Plugin::bootstrap()` → `WP_MCP_AI_Crawler::init()`) owns the same cron-hook registration monolith |
| **Optional dependencies** | A reachable Crawl4AI service URL + working WP-Cron (runtime, not load-time). The HTTP check itself goes through the base `WP_MCP_AI_Tool_Run_Crawl4AI_Job` (monolith-only) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Engine\Crawler\Crawler` | `Crawler.php` | `Plugin::registerEngine()`; any standalone consumer queuing remote Crawl4AI jobs |
| `NvoosContentGraphAi\Engine\Crawler\Crawler::CRON_HOOK` (`wp_mcp_ai_crawl4ai_poll_task`) | same file | scheduled WP-Cron event |
| `NvoosContentGraphAi\Engine\Crawler\Crawler::JOB_STORAGE_PREFIX` (`wp_mcp_ai_crawl4ai_job_`) | same file | transient-key prefix for queued job state |
| `NvoosContentGraphAi\Engine\Crawler\InlineAsyncTickTrait` | `InlineAsyncTickTrait.php` | `Crawler` — the inline-async-tick primitives (ported as the crawler's required collaborator) |

Internal helpers (`handle_poll_event()`, tick-lock methods inherited from
`InlineAsyncTickTrait`) are not part of the public contract.

## Inputs / Outputs / Neighbors

- **Reads from:** transients keyed by `JOB_STORAGE_PREFIX . md5( $task_id )`
  (job descriptor + scheduling metadata), the `wp_mcp_ai_settings`
  option (poll interval, max runtime), (monolith) the base settings
  registry.
- **Writes to:** the same transient store (status updates), the
  response-attachments cache via (monolith)
  `WP_MCP_AI_Crawl4AI_Local_API::cache_task_result()`, (monolith) the
  base activity log + dead-letter queue + cron manager.
- **Upstream callers:** any standalone consumer of
  `register_remote_job()` / `register_completed_job()` /
  `get_job_status()`; scheduled `wp_mcp_ai_crawl4ai_poll_task` events.
- **Downstream collaborators:** (monolith)
  `WP_MCP_AI_Tool_Run_Crawl4AI_Job::check_remote_task()`,
  `WP_MCP_AI_Crawl4AI_Local_API`, `WP_MCP_AI_Logger`,
  `WP_MCP_AI_Dead_Letter_Queue`, `WP_MCP_AI_Cron_Manager`.
- **Events fired:** `wp_mcp_ai_crawl4ai_job_registered`,
  `wp_mcp_ai_crawl4ai_job_completed`, `wp_mcp_ai_crawl4ai_job_failed`
  (plus the `wp_mcp_ai_crawl4ai_response` filter and the trait's
  `wp_mcp_ai_inline_kick_completed`).
- **Events listened to:** `wp_mcp_ai_crawl4ai_poll_task` (own cron
  hook), `shutdown` (via the inline-async-tick trait).

## Conventions

- **Per-mode discriminator is `defined( 'WP_MCP_AI_PATH' )`** — never
  bare `class_exists()` (the monorepo classmap resolves base classes
  standalone). Monolith collaborators resolve through the base classes;
  standalone degrades through the documented seams (the check tool →
  `wp_mcp_ai_crawl4ai_check_unavailable`, the result cache → dormant
  no-op, logger/DLQ/cron-manager → dormant).
- Job IDs are remote `task_id`s from Crawl4AI — opaque strings
  (containing dots, dashes, etc.), hashed before forming a lock key or
  storage key.
- The trait's cooperative lock (`TICK_LOCK_PREFIX` + per-task hash,
  TTL 30 s) prevents the inline kick and the cron event from racing.
- Byte-identical constants/transient prefixes/hook names/error codes
  with the base; deviations documented in the class docblocks (PSR-4
  class names, require → autoload, text domain, per-mode collaborator
  seams, the standalone-only check-unavailable error code).
- The base's `run_crawl4ai_job` validated tool and the Crawl4AI tool
  family remain base-owned until the Crawl4AI tool wave.

## Tests

- `tests/Ecosystem/test-crawler.php` — registration paths (URL
  validation, defaults/clamps, job shape, cron scheduling), the tick
  lock, poll outcomes (expiry, skip-polling, check-failure retry with
  backoff, permanent failure), storage/scheduling helpers, and the
  trait primitives.

```bash
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai/phpunit-ecosystem.xml.dist plugins/nvoos-content-graph-ai/tests/Ecosystem/test-crawler.php
```

## Also Load

- [`../README.md`](../README.md) — the Engine wave
- [`../../README.md`](../../README.md) — composition root + subsystem index
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — outbound URL validation

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E6 row status
- [`includes/crawler/`](../../../../includes/crawler/) + [`includes/traits/trait-wp-mcp-ai-inline-async-tick.php`](../../../../includes/traits/trait-wp-mcp-ai-inline-async-tick.php) — the base subsystem (the port's origin)
- [`includes/class-wp-mcp-ai-crawl4ai-local-api.php`](../../../../includes/class-wp-mcp-ai-crawl4ai-local-api.php) — the base HTTP/SDK wrapper (monolith collaborator)
