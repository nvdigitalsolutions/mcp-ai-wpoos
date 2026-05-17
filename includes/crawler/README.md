# crawler/

## Purpose

Background coordinator for Crawl4AI jobs — schedules, polls, and reconciles long-running remote crawl tasks via WP-Cron with an inline-async-tick fast path.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (see [`CLAUDE.md`](../../CLAUDE.md)) |
| **Loaded by** | `includes/bootstrap/loader.php` (`require_once .../crawler/class-wp-mcp-ai-crawler.php`) + `WP_MCP_AI_Crawler::init()` in `WP_MCP_AI_Plugin::bootstrap()` |
| **Optional dependencies** | A reachable Crawl4AI service URL (configured in NV oOS settings) and a working WP-Cron — both are runtime, not load-time, dependencies. The HTTP call itself goes through `WP_MCP_AI_Crawl4AI_Local_API` (parent `includes/`). |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Crawler` | `class-wp-mcp-ai-crawler.php` | `WP_MCP_AI_Plugin::bootstrap()`, the Crawl4AI tool family, admin Crawl4AI monitor |
| `WP_MCP_AI_Crawler::CRON_HOOK` (`wp_mcp_ai_crawl4ai_poll_task`) | same file | scheduled WP-Cron event |
| `WP_MCP_AI_Crawler::JOB_STORAGE_PREFIX` (`wp_mcp_ai_crawl4ai_job_`) | same file | transient-key prefix for queued job state |

Internal helpers (`handle_poll_event()`, tick-lock methods inherited from `WP_MCP_AI_Inline_Async_Tick_Trait`) are not part of the public contract.

## Inputs / Outputs / Neighbors

- **Reads from:** Transients keyed by `JOB_STORAGE_PREFIX . $task_id` (job descriptor + scheduling metadata), Crawl4AI HTTP status responses, NV oOS settings (poll interval, max runtime).
- **Writes to:** Same transient store (status updates), `WP_MCP_AI_Logger` activity entries, optional dead-letter queue (`WP_MCP_AI_Dead_Letter_Queue`), the response-attachments store on completion.
- **Upstream callers:** REST job kick-off via `WP_MCP_AI_Crawl4AI_Local_API`, the `run_crawl4ai_job` validated tool, admin monitor "Re-poll now" actions, scheduled `wp_mcp_ai_crawl4ai_poll_task` events.
- **Downstream collaborators:** [`includes/http/`](../http/) and `WP_MCP_AI_HTTP` (outbound polling), `WP_MCP_AI_Crawl4AI_Local_API`, `WP_MCP_AI_Response_Attachments`, `WP_MCP_AI_Job_Notifier`, `WP_MCP_AI_Logger`.
- **Events fired:** `wp_mcp_ai_crawl4ai_task_completed`, `wp_mcp_ai_crawl4ai_task_failed`, `wp_mcp_ai_crawl4ai_task_progress` (filters/actions emitted from `handle_poll_event()`).
- **Events listened to:** `wp_mcp_ai_crawl4ai_poll_task` (own cron hook), `shutdown` (via the inline-async-tick trait), REST self-heal calls.

## Conventions

- Adopts [`WP_MCP_AI_Inline_Async_Tick_Trait`](../traits/) (Slice 3 of the inline-async-tick campaign): the first poll for a freshly-queued job fires inline on `shutdown` instead of waiting up to 30 s for the cron loopback. The trait's cooperative lock (`TICK_LOCK_PREFIX` + per-task hash, TTL 30 s) prevents the inline kick and the cron event from racing.
- Job IDs are remote `task_id`s from Crawl4AI — never assume they are integers; they are treated as opaque strings (containing dots, dashes, etc.) and must be hashed before forming a lock key.
- Newly-queued jobs younger than `STALE_QUEUED_THRESHOLD_SECONDS` (5 s) are skipped by the REST self-heal path to avoid pre-empting the just-scheduled inline tick.
- Single-folder ownership: there is exactly one class here; new crawler-related code should be added as collaborators in `services/` or `repositories/` rather than expanding this folder into a generic crawler framework.

## Tests

```bash
vendor/bin/phpunit tests/test-crawler-coordinator.php
vendor/bin/phpunit tests/test-crawl4ai-inline-kick.php
vendor/bin/phpunit tests/test-crawl4ai-async-compatibility.php
vendor/bin/phpunit tests/test-crawl4ai-local-api.php
vendor/bin/phpunit tests/test-crawl4ai-monitor-ajax.php
vendor/bin/phpunit tests/test-crawl4ai-tool.php
vendor/bin/phpunit tests/test-crawl4ai-json-extraction.php
vendor/bin/phpunit tests/test-crawl4ai-price-lookup-tool.php
vendor/bin/phpunit tests/test-run-crawl4ai-job-validated-tool.php
```

Crawler-specific fixtures live under [`tests/crawler/`](../../tests/crawler/).

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — outbound URL validation, attachment sanitisation (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — for `run_crawl4ai_job` and related tool envelopes
- [`.context/rest-api.md`](../../.context/rest-api.md) — kick-off and status endpoints

## See Also

- Companion class: `includes/class-wp-mcp-ai-crawl4ai-local-api.php` (the HTTP/SDK wrapper)
- Inline-tick trait: [`includes/traits/`](../traits/)
- Admin monitor: `WP_MCP_AI_Admin_Crawl4AI_Monitor` (under `includes/admin/`)
