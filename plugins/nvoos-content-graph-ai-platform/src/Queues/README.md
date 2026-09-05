# Queues

## Purpose

Wave E2 port surface. `AsyncJobQueue` is an aligned port of the base
plugin's `WP_MCP_AI_Async_Job_Queue`: byte-identical table schema
(`mcp_ai_job_queue`), priority/status/type constants, cron hook names,
error codes, and envelopes. It powers background command/workflow/tool
execution for the platform in standalone installs. `QueueManager` is
the aligned port of `WP_MCP_AI_Queue_Manager`: byte-identical
mode/priority constants, capability-flag semantics, and the deferred
result envelope — the tool-execution orchestration layer. `JobQueueManager`
is the aligned port of `WP_MCP_AI_Job_Queue_Manager`: byte-identical
`mcp_ai_concurrent_jobs` schema, priority/status constants, atomic
claiming, retry/fail handling, stale-job cleanup, and queue stats — the
concurrency layer the queue-processing cron path consumes.
`DeadLetterQueue` is the aligned port of `WP_MCP_AI_Dead_Letter_Queue`:
byte-identical `mcp_ai_dead_letters` schema, type/limit/retention
constants, error codes, retry dispatch, and stats — the failure sink the
queue managers forward exhausted retries to. `RateLimitManager` is the
aligned port of `WP_MCP_AI_Rate_Limit_Manager`: byte-identical retry
constants, backoff multiplier, retriable status/timeout tables, and the
`execute_with_retry()` loop — the API rate-limit resilience layer.
`OutboundWebhook` is the aligned port of `WP_MCP_AI_Outbound_Webhook`:
byte-identical `wp_mcp_ai_outbound_webhooks` option, subscription
lifecycle, signed non-blocking dispatch, signature verification, and
core event listeners — the webhook delivery layer for workflow and
approval events. `CronManager` is the aligned port of
`WP_MCP_AI_Cron_Manager`: byte-identical `wp_mcp_ai_cron_jobs` option,
argument normalisation, record/remove lifecycle, retention-window
pruning, and stable job-ID generation — the tracked cron-event layer
for the plugin's scheduling tools.
`SlaManager` is the aligned port of `WP_MCP_AI_SLA_Manager`:
byte-identical tier/priority/SLA-target/concurrency constants,
capability-flag tier inference, Little's Law capacity math, tuning
recommendations, and compliance tracking/statistics (same
`wp_mcp_ai_sla_compliance_log` option) — the prioritization layer the
job queue managers consume.

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
| `NvoosContentGraphAiPlatform\Queues\QueueManager` | `QueueManager.php` | `Plugin::registerQueueManager()` — tool-execution orchestration (filter + AJAX status) |
| `NvoosContentGraphAiPlatform\Queues\JobQueueManager` | `JobQueueManager.php` | Queue-processing cron path + CLI (static utility; no hooks of its own — wiring lands with the scheduler bridge) |
| `NvoosContentGraphAiPlatform\Queues\DeadLetterQueue` | `DeadLetterQueue.php` | `Plugin::registerDeadLetterQueue()` — table + weekly cleanup cron; consumed by `JobQueueManager` failure forwarding |
| `NvoosContentGraphAiPlatform\Queues\RateLimitManager` | `RateLimitManager.php` | API callers (static utility; no hooks of its own — consumed directly) |
| `NvoosContentGraphAiPlatform\Queues\OutboundWebhook` | `OutboundWebhook.php` | `Plugin::registerOutboundWebhook()` — event-listener registration; consumed by the eventual workflow (E1) / approvals (E3) ports + notifier |
| `NvoosContentGraphAiPlatform\Queues\CronManager` | `CronManager.php` | `Plugin::registerCronManager()` — `init` prune hook; consumed by the plugin's cron tools |
| `NvoosContentGraphAiPlatform\Queues\SlaManager` | `SlaManager.php` | `JobQueueManager` (tier limits + enqueue priorities) + analytics/dashboard consumers (static utility; no hooks of its own) |

## Inputs / Outputs / Neighbors

- **Reads from:** job rows in `wp_*mcp_ai_job_queue`, the
  `wp_mcp_ai_queue_worker_dedicated` option (RabbitMQ gating), the
  `nvoos_content_graph_ai_platform/async_job_executors` filter;
  `RateLimitManager` reads/writes `wp_mcp_ai_retry_*` transients;
  `OutboundWebhook` reads/writes the `wp_mcp_ai_outbound_webhooks` option
  and POSTs to subscribed URLs (non-blocking, signed); `CronManager`
  reads/writes the `wp_mcp_ai_cron_jobs` option and reads
  `wp_mcp_ai_settings['cron_job_retention_period']`;
  `SlaManager` reads `wp_mcp_ai_settings` (`sla_prioritization_enabled`,
  `sla_*_concurrent`) and reads/writes `wp_mcp_ai_sla_compliance_log`
- **Writes to:** job rows, cron events (`wp_mcp_ai_process_job_queue`,
  `wp_mcp_ai_cleanup_job_queue`), the `minute` cron interval,
  `wp_mcp_ai_emit_sse_event` (byte-identical action)
- **Upstream callers:** WP-Cron ticks, future platform subsystems
  (workflow engine E1 registers executors via the filter)
- **Downstream collaborators (dormant until their waves):** Action
  Scheduler bridge, Job Notifier (E2), base logger;
  `QueueManager` resolves the RabbitMQ client + tool registry per install
  mode (base classes monolith / AI addon + CoreBridge standalone);
  `JobQueueManager` resolves SLA per install mode (base
  `WP_MCP_AI_SLA_Manager` monolith / this package's `SlaManager`
  standalone — both probes gated on `defined( 'WP_MCP_AI_PATH' )`),
  resource/logging through dormant seams; `DeadLetterQueue` resolves the
  retry dispatchers per install mode (base manager/notifier/executor
  monolith — boot-gated probes — platform `JobQueueManager` standalone);
  `RateLimitManager` targets the base logger through a dormant seam;
  `SlaManager` resolves queue statistics per install mode (base
  `WP_MCP_AI_Job_Queue_Manager` monolith / platform `JobQueueManager`
  standalone)

## Conventions

- Standalone-only registration — the base plugin owns the same table and
  hooks in monolith installs.
- Byte-identical constants/hooks/error codes with the base; deviations
  documented in the class docblock (minute interval registration,
  method_exists guards, executor filter seam; `JobQueueManager` and
  `DeadLetterQueue` drop the base's deprecated option-fallback storage —
  custom table only).
- Protected seams (`*_available()`, `emit_sse_event`, `log_event`) let
  tests exercise dormant collaborators without global stubs.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping

## See Also

- Parent: [`../`](../) — src root
- Bootstrap wiring: [`../Plugin.php`](../Plugin.php)
- Tracker: `docs/project/ecosystem-port-tracker.md` (Wave E rows)
