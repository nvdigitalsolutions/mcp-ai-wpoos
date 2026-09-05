# Queues

## Purpose

Wave E2 port surface. `AsyncJobQueue` is an aligned port of the base
plugin's `WP_MCP_AI_Async_Job_Queue`: byte-identical table schema
(`mcp_ai_job_queue`), priority/status/type constants, cron hook names,
error codes, and envelopes. It powers background command/workflow/tool
execution for the platform in standalone installs.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::register()` — standalone-only (`! defined('WP_MCP_AI_PATH')`) |
| **Optional dependencies** | Action Scheduler bridge, DLQ, job notifier (dormant seams until they port) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Queues\AsyncJobQueue` | `AsyncJobQueue.php` | `Plugin::registerQueues()` — queue lifecycle + cron wiring |

## Inputs / Outputs / Neighbors

- **Reads from:** job rows in `wp_*mcp_ai_job_queue`, the
  `wp_mcp_ai_queue_worker_dedicated` option (RabbitMQ gating), the
  `nvoos_content_graph_ai_platform/async_job_executors` filter
- **Writes to:** job rows, cron events (`wp_mcp_ai_process_job_queue`,
  `wp_mcp_ai_cleanup_job_queue`), the `minute` cron interval,
  `wp_mcp_ai_emit_sse_event` (byte-identical action)
- **Upstream callers:** WP-Cron ticks, future platform subsystems
  (workflow engine E1 registers executors via the filter)
- **Downstream collaborators (dormant until their waves):** Action
  Scheduler bridge, Dead Letter Queue, Job Notifier (E2), base logger

## Conventions

- Standalone-only registration — the base plugin owns the same table and
  hooks in monolith installs.
- Byte-identical constants/hooks/error codes with the base; deviations
  documented in the class docblock (minute interval registration,
  method_exists guards, executor filter seam).
- Protected seams (`*_available()`, `emit_sse_event`, `log_event`) let
  tests exercise dormant collaborators without global stubs.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping

## See Also

- Parent: [`../`](../) — src root
- Bootstrap wiring: [`../Plugin.php`](../Plugin.php)
- Tracker: `docs/project/ecosystem-port-tracker.md` (Wave E rows)
