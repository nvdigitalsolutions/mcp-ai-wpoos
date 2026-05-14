# Chat Jobs / Tasks Drawer — Integration Architecture

> **Status:** All phases landed (PR-A through PR-G, May 2026).  
> **Feature flag:** `wp_mcp_ai_chat_tasks_drawer` — defaults **on** as of v1.9.3.

This document describes the end-to-end architecture of the Chat Jobs / Tasks Drawer — the system that surfaces async job progress directly in the chat UI.

---

## Overview

When the AI assistant triggers a long-running tool (transcript mining, Crawl4AI polling, HITL approvals, SaaS controller jobs, Docs Hub rebuilds, etc.) the chat client no longer shows a plain "Tool is processing…" placeholder. Instead it renders a live **inline progress card** and an optional **side Tasks Drawer** that aggregates all active and recent jobs for the current user.

The system has two main surfaces:

| Surface | Feature flag | Default |
|---------|-------------|---------|
| **Inline job-progress card** (replaces "Tool is processing…") | `config.inlineJobCard` (always on) | On |
| **Tasks Drawer** (side panel + toasts + tab badge) | `wp_mcp_ai_chat_tasks_drawer` filter | **On** (v1.9.3+) |

---

## Architecture

```
Browser                       WordPress REST API
──────────────────────────    ────────────────────────────────────────────────
chat.js                       GET /mcp-ai/v1/cron-status (SSE)
  │                             │
  ├─ initTasksDrawer()          ├─ stream_status_summary_updates()
  │    │                        │     │
  │    ├─ EventSource open ───► │     ├─ initial cron_status snapshot frame
  │    │                        │     ├─ per-poll job:* diff frames
  │    └─ wpMcpAiJobBus ◄────── │     └─ event: ping heartbeat (every 15 s)
  │                             │
  ├─ createJobProgressCard()    ├─ POST /mcp-ai/v1/cron-status/{id}/cancel
  │                             └─ POST /mcp-ai/v1/cron-status/{id}/retry
  └─ showJobToast()
```

### Data flow

1. A tool call returns `{"status":"pending","job_id":"..."}` from the async executor.
2. The chat client opens an SSE stream to `/mcp-ai/v1/cron-status` (or falls back to polling).
3. The server polls all registered **job sources** once per `SSE_JOB_POLL_INTERVAL` (3 s), diffs the snapshot against the previous one, and emits typed `job:*` frames for every changed job.
4. The browser routes each frame through `wpMcpAiJobBus` (a simple pub/sub event bus) to all subscribed UI components: the inline card, the drawer row, and the toast notifier.

---

## SSE Event Schema

Every typed event carries the full job record so receivers can update without maintaining local state beyond what they already have.

| Event name | When emitted |
|-----------|-------------|
| `job:queued` | New job seen in `queued` / `pending` status |
| `job:started` | Status transitions into `running` / `polling` |
| `job:progress` | Progress % or `updated_at` changed while already running |
| `job:step` | `WP_MCP_AI_Job_Notifier::record_step()` fired a step update |
| `job:completed` | Status → `completed` |
| `job:failed` | Status → `failed` |
| `job:cancelled` | Status → `cancelled` |
| `job:retried` | Status transitions from a terminal state back to running/queued |
| `cron_status` | Initial snapshot on SSE connection open |
| `ping` | Heartbeat every `SSE_JOB_HEARTBEAT_INTERVAL` polls (≈ 15 s) |

All frames carry a monotonic `id:` line for `Last-Event-ID` resume support — the browser automatically reconnects and the server resumes from where it left off.

### Job record shape

Every `job:*` frame's `data` payload is a JSON object with these fields:

```json
{
  "job_id":      "async_tool_abc123",
  "kind":        "transcript_mine",
  "status":      "running",
  "created_by":  42,
  "assistant_id": "101",
  "started_at":  1716000000,
  "updated_at":  1716000060,
  "eta":         null,
  "progress":    35,
  "message":     "Processed 7 of 20 transcripts",
  "cancellable": true,
  "retryable":   false,
  "source":      "transcript_mining",
  "steps":       []
}
```

| Field | Type | Notes |
|-------|------|-------|
| `job_id` | `string` | Unique within this WordPress installation. |
| `kind` | `string` | Slug identifying the job type (e.g. `transcript_mine`, `crawl4ai`, `async_tool`). |
| `status` | `string` | One of: `queued`, `pending`, `running`, `polling`, `completed`, `failed`, `cancelled`. |
| `created_by` | `int` | WordPress user ID of the job owner; `0` = system. |
| `assistant_id` | `string` | Post ID of the owning assistant, or empty. |
| `started_at` | `int` | Unix timestamp when the job was created/queued. |
| `updated_at` | `int` | Unix timestamp of the most recent state change. |
| `eta` | `int\|null` | Estimated Unix completion timestamp; `null` if unknown. |
| `progress` | `int\|null` | Completion percentage 0–100; `null` if indeterminate. |
| `message` | `string` | Human-readable status message. |
| `cancellable` | `bool` | Whether `POST /cron-status/{id}/cancel` is allowed. |
| `retryable` | `bool` | Whether `POST /cron-status/{id}/retry` is allowed. |
| `source` | `string` | Slug of the `Interface_WP_MCP_AI_Cron_Status_Job_Source` that produced this record. |
| `steps` | `array` | Ring-buffer of the last 50 `record_step()` entries (empty for sources that don't use `Job_Notifier`). |

---

## Job-Source Registry

The cron-status endpoint aggregates jobs from all registered **job sources** via the WordPress filter `wp_mcp_ai_cron_status_job_sources`. This decouples each subsystem from the REST layer.

```php
// Filter signature:
// param  array $sources  slug → source map (keys are source slugs).
// return array           Updated slug → source map.
add_filter( 'wp_mcp_ai_cron_status_job_sources', function( array $sources ) {
    $sources['my_source'] = new My_Job_Source();
    return $sources;
} );
```

### Built-in sources (always active)

| Source slug | Class | Location |
|-------------|-------|----------|
| `async_tool` | `WP_MCP_AI_Tool_Async_Executor` | Built into the service (no adapter needed) |
| `veo_polling` | `WP_MCP_AI_Gemini_Video_Generation_Service` | Built into the service |
| `transcript_mining` | `WP_MCP_AI_Job_Source_Transcript_Mining` | `includes/services/job-sources/` |
| `crawl4ai` | `WP_MCP_AI_Job_Source_Crawl4AI` | `includes/services/job-sources/` |
| `hitl_approvals` | `WP_MCP_AI_Job_Source_Hitl_Approvals` | `includes/services/job-sources/` |

### Addon sources

| Source slug | Adapter class | Where registered |
|-------------|--------------|-----------------|
| `saas_apply` | `NVOOS_SaaS_Controller_Job_Source` | `addons/saas-controller` entry-point |
| `docs_hub_rebuild` | `NV_oOS_Docs_Hub_Rebuild_Job_Source` | `addons/docs-hub` entry-point |

For the full developer guide on writing a new source adapter, see  
[`docs/guides/developer/tool-development/registering-a-job-source.md`](../../guides/developer/tool-development/registering-a-job-source.md).

---

## Cancel / Retry REST Routes

```
POST /wp-json/mcp-ai/v1/cron-status/{job_id}/cancel
POST /wp-json/mcp-ai/v1/cron-status/{job_id}/retry
```

Both routes require the requesting user to own the job (or have `manage_options`). The handler first tries each registered source's `cancel_job()` / `retry_job()` method (if it exposes one); if no source claims the job it falls back to `WP_MCP_AI_Tool_Async_Executor::cancel_job()` / `retry_job()`.

Actions fired on success:

| Action | Args |
|--------|------|
| `wp_mcp_ai_job_cancelled` | `$job_id, $user_id` |
| `wp_mcp_ai_job_retried` | `$job_id, $user_id` |

---

## Step Notifications

Any subsystem can push a granular step update into a job's `steps[]` ring-buffer:

```php
WP_MCP_AI_Job_Notifier::record_step(
    $job_id,           // string
    'Processed chunk 3 of 10',  // human-readable label
    'running',         // step status: pending|running|completed|failed
    array( 'chunk' => 3, 'total' => 10 )  // optional metadata
);
```

`record_step()` also:
- Fires the `wp_mcp_ai_job_step` action (3 args: `$job_id`, `$step_record`, `$full_status`).
- Emits a `job:step` SSE frame immediately via `WP_MCP_AI_SSE_Handler::send_sse_event_with_id()`.
- Dispatches a `step` webhook event if a webhook endpoint is configured.

The ring-buffer cap is `WP_MCP_AI_Job_Notifier::MAX_STEPS_PER_JOB` (50).

---

## OTel Instrumentation

Five action hooks are fired at REST handler call-sites. `WP_MCP_AI_Otel_Span_Exporter::register()` subscribes listeners that emit OTLP spans when an endpoint is configured under **Tools → Connections**.

| Action hook | Span name | When |
|-------------|-----------|------|
| `wp_mcp_ai_chat_jobs_snapshot` | `nvoos.chat.jobs.snapshot` | After list snapshot response assembled |
| `wp_mcp_ai_before_chat_jobs_stream` | `nvoos.chat.jobs.stream` (start) | SSE stream open |
| `wp_mcp_ai_after_chat_jobs_stream` | `nvoos.chat.jobs.stream` (end) | SSE stream close |
| `wp_mcp_ai_chat_jobs_cancel` | `nvoos.chat.jobs.cancel` | Cancel request handled |
| `wp_mcp_ai_chat_jobs_retry` | `nvoos.chat.jobs.retry` | Retry request handled |

---

## Tasks Drawer UI

The drawer is rendered by `initTasksDrawer(container, config, cronStatusEndpoint, nonce)` in `assets/js/chat.js` and is activated when `config.chatTasksDrawer === true`.

### PHP feature flag

```php
// Flip to false to disable the drawer sitewide.
add_filter( 'wp_mcp_ai_chat_tasks_drawer', '__return_false' );
```

Default: **`true`** (on) as of v1.9.3.

### Job persistence

Jobs are persisted to `localStorage` under the key `wp_mcp_ai_tasks_{assistantId}` with a cap of 200 entries. When the cap is reached the oldest terminal jobs (completed / failed / cancelled) are pruned first.

### Toast notifications

`showJobToast(container, type, job)` fires a 6-second auto-dismiss toast on `job:completed` and `job:failed` events. A dismiss button lets users close it early.

### Tab-title badge

`updateTabTitleBadge(delta)` prefixes the tab title with `(N)` while N jobs are running, mirroring the pattern used in ChatGPT and Slack.

---

## Key Files

| File | Purpose |
|------|---------|
| `includes/interfaces/interface-wp-mcp-ai-cron-status-job-source.php` | Source adapter contract |
| `includes/services/class-wp-mcp-ai-cron-status-service.php` | Registry, normalization, diff classification |
| `includes/services/job-sources/` | Built-in source adapter classes |
| `includes/services/job-sources/job-sources-init.php` | Registers base-plugin adapters on the filter |
| `includes/class-wp-mcp-ai-rest.php` | SSE streaming loop, snapshot handler |
| `includes/rest/class-wp-mcp-ai-rest-tools-controller.php` | Cancel / retry REST routes |
| `includes/class-wp-mcp-ai-job-notifier.php` | `record_step()`, direct SSE emission |
| `includes/rest/class-wp-mcp-ai-sse-handler.php` | `send_sse_event_with_id()`, `emit_sse_frame()` |
| `includes/class-wp-mcp-ai-shortcode.php` | `chatTasksDrawer` config flag |
| `includes/services/class-wp-mcp-ai-otel-span-exporter.php` | OTel hook listeners |
| `assets/js/chat.js` | `initTasksDrawer`, `createJobProgressCard`, `showJobToast`, `updateTabTitleBadge` |
| `assets/css/chat.css` | `.wp-mcp-ai-job-card__*`, `.wp-mcp-ai-chat__tasks-drawer__*`, `.wp-mcp-ai-job-toast` |

---

## Related Documents

- [`cron-status-tasks-drawer-plan.md`](cron-status-tasks-drawer-plan.md) — original phased implementation plan
- [`async-tool-execution-guide.md`](async-tool-execution-guide.md) — async tool execution patterns
- [`registering-a-job-source.md`](../../guides/developer/tool-development/registering-a-job-source.md) — developer how-to for adding a new source
