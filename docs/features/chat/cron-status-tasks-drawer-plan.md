# Chat UI — Jobs / Tasks Drawer Enhancement Plan

> **Status:** Planning · **Target:** Minor release after wire-up fixes land  
> **Created:** May 2026 · **Scope:** `assets/js/`, `includes/class-wp-mcp-ai-rest.php`, `includes/services/class-wp-mcp-ai-cron-status-service.php` + related

---

## Background

The current async-job status surface in the chat UI is a slim 4-counter strip
(`queued / running / completed / failed`) fed by a Server-Sent Events endpoint.
It only reflects two backend sources — `WP_MCP_AI_Tool_Async_Executor` and
`WP_MCP_AI_Gemini_Video_Generation_Service` — and lacks cancel / retry / timeline
controls that users of ChatGPT, Claude, Copilot, and Notion AI now expect.

### Industry baseline (2025 audit)

| Pattern | Current state | Target |
|---------|--------------|--------|
| Inline progress card (per-bubble) | Single spinner | Tool name · sub-steps · ETA · % · Cancel |
| Persistent global task list | 4-counter strip | Drawer with filters, batch actions, badge |
| Per-job timeline | Not exposed | Vertical stepper with timestamps + expandable logs |
| Cancel / Retry / Notify me | Missing | Inline controls; capability-checked |
| Completion toast / tab badge | Missing | Float toast + `(N)` tab prefix while running |
| Semantic completion summary | Raw JSON | "Video generated in 1m 32s · 8.2 MB · 1080p" |
| Health pill | Not surfaced in chat | Amber/red warning from `Async_Health_Monitor` |
| Multi-source awareness | 3 hard-coded namespaces | Unified job-source registry via filter |

---

## Phased Delivery

### Phase 0 — Wire-up bug fixes (prerequisite, zero behaviour risk)

Small frontend + backend corrections that must land first.

- **`chat.js`**: read `counts.running` (not `counts.active`) when updating the counter badge.
- **REST controller**: move `system_status` computation into `handle_cron_status_request` (production path), not just the tools-controller helper — so the health dot actually fires.
- **`chat.js`**: publish `cron_job_status` SSE frames into `wpMcpAiJobBus.handleJobUpdate()` so the global status bar reflects per-job state changes.
- **`get_status_summary()`**: split or reorder the shared `limit` so regular cron entries cannot starve assistant-scoped async/video jobs.
- **SSE**: lower the list-endpoint `retry:` directive (e.g. 3 s) and emit a heartbeat `event: ping` every 15 s to prevent the browser `EventSource` from looking frozen.

---

### Phase 1 — Job-source registry (architectural)

> **Status (PR-B, May 2026):** Registry contract, normalized record, and integration into `get_status_summary()` / `get_status_counts()` have landed. Built-in async-tool / Veo / cron-manager collectors continue to feed the pipeline natively; new sources opt in via the filter.
>
> **Status (PR-E, May 2026):** Three base-plugin adapter classes have landed — `WP_MCP_AI_Job_Source_Transcript_Mining`, `WP_MCP_AI_Job_Source_Crawl4AI`, and `WP_MCP_AI_Job_Source_Hitl_Approvals` — loaded by `includes/services/job-sources/job-sources-init.php` (wired into bootstrap). Two addon adapters also landed: `NVOOS_SaaS_Controller_Job_Source` (saas-controller addon) and `NV_oOS_Docs_Hub_Rebuild_Job_Source` (docs-hub addon), each registered via their own addon entry-point filter hook. PHPUnit coverage in `tests/test-job-source-adapters.php` (16 cases). Remaining sources (graphify, harness eval, pro schedule, MCP audit, sub-agent dispatcher, durable runs) deferred to a future PR.

A single filter replaces three hard-coded transient scans.

**New filter:**
```php
apply_filters( 'wp_mcp_ai_cron_status_job_sources', $sources )
```

**Normalized record contract (every source returns this shape):**
```php
array(
    'job_id'       => string,   // unique across all sources
    'kind'         => string,   // 'async_tool' | 'veo' | 'transcript_mine' | 'crawl' | ...
    'status'       => string,   // queued | running | polling | completed | failed | cancelled
    'created_by'   => int,      // WP user ID
    'assistant_id' => int,      // post ID of mcp_ai_assistant
    'started_at'   => int,      // Unix timestamp
    'updated_at'   => int,
    'eta'          => int|null, // Unix timestamp or null
    'progress'     => int|null, // 0–100 or null
    'message'      => string,   // human-readable current step
    'cancellable'  => bool,
    'retryable'    => bool,
    'source'       => string,   // slug identifying the source class
)
```

**Sources to migrate / register:**

| Source | Backend class | Notes |
|--------|--------------|-------|
| Cron manager entries | `WP_MCP_AI_Cron_Manager` | migrate existing |
| Async tool jobs | `WP_MCP_AI_Tool_Async_Executor` | migrate existing |
| Veo video generation | `WP_MCP_AI_Gemini_Video_Generation_Service` | migrate existing |
| Transcript mining | `WP_MCP_AI_Transcript_Mining_Job` | ✅ registered (PR-E) |
| Crawl4AI poller | `WP_MCP_AI_Crawler` | ✅ registered (PR-E) |
| Docs Hub rebuild | `NV_oOS_Docs_Hub_Rebuild_Pipeline` | ✅ registered (PR-E) |
| Graphify reindex | `NV_oOS_Graphify` | deferred |
| Harness eval | `WP_MCP_AI_Harness_Eval_Scheduler` | deferred |
| SaaS Apply jobs | `NVOOS_SaaS_Controller_Apply_Job` | ✅ registered (PR-E) |
| Pro Schedule runs | `WP_MCP_AI_Pro_Schedule_Manager` | deferred |
| HITL approvals | `WP_MCP_AI_Approval_Queue` | ✅ registered (PR-E) |
| Sub-agent dispatcher | `WP_MCP_AI_Sub_Agent_Dispatcher` | deferred |
| Durable runs | `WP_MCP_AI_Durable_Run_Store` | deferred |

Use `WP_MCP_AI_Job_Notifier` as the unifying read view; fall back to per-namespace transient scans only for sources not yet migrated.

---

### Phase 2 — Streaming + structured event schema

> **Slice 2a status (landed):** `WP_MCP_AI_Job_Notifier::record_step()` exists, fires `wp_mcp_ai_job_step`, emits SSE `step` events, dispatches the `step` webhook event; `WP_MCP_AI_SSE_Handler::send_sse_event_with_id()` exists to support `Last-Event-ID` resume in slice 2b. The typed event-schema below is the canonical contract producers and consumers must use.
>
> **Slice 2b status (landed):** `WP_MCP_AI_REST::stream_status_summary_updates()` replaces the one-shot SSE snapshot on `/cron-status` with a real polling loop. It emits the canonical typed `job:*` diff frames driven by `WP_MCP_AI_Cron_Status_Service::classify_job_diff_event()`, attaches a monotonic `id:` line to every frame (back-compat `cron_status` snapshot frame, typed diff frames, and `event: ping` heartbeats every `SSE_JOB_HEARTBEAT_INTERVAL` polls), and resumes the counter from `Last-Event-ID` (`HTTP_LAST_EVENT_ID` header or `last_event_id` query param) on reconnect.

- Replace the one-shot `stream_event_stream_payload()` on the list endpoint with a real polling loop (modeled on `stream_job_status_updates()`): periodic snapshot → diff frames → heartbeat. Caps at existing SSE timeouts.
- **Typed event schema:**
  - `job:queued` · `job:started` · `job:step` · `job:progress`
  - `job:completed` · `job:failed` · `job:cancelled` · `job:retried`
  - Each frame carries the full normalized record from Phase 1.
- **`Job_Notifier`**: add `record_step( $label, $status )` so tools can emit progressive-disclosure steps. ✅
- **`wpMcpAiJobBus`**: becomes the single subscription point in JS; all per-job and list streams publish through it.
- **`Last-Event-ID` resume**: the list stream replays diff frames since the last client-acknowledged ID on reconnect.

---

### Phase 3 — UI surfaces

#### 3a. In-bubble progress card

> **Status (PR-C, May 2026):** `createJobProgressCard()` JS function landed in `assets/js/chat.js`. Wired into both `waitForAsyncToolResultSSE` and `waitForAsyncToolResultPolling`. BEM CSS block `.wp-mcp-ai-job-card__*` added to `assets/css/chat.css`. Feature-gated via `state.config.inlineJobCard`.

Replace the plain "Tool is processing…" line with a compact card:

```
┌──────────────────────────────────────────────┐
│ ⏳  generate_video                  [Cancel]  │
│     Uploading frames…     72%  ~18s left      │
│     ▶ Parsing prompt      ✓  0.3s             │
│     ▶ Generating frames   ✓  14.1s            │
│     ▶ Uploading…         ⏳  running           │
│       [Show full log]                         │
└──────────────────────────────────────────────┘
```

- Steps fed by `job:step` SSE frames.
- ETA from normalized record `eta` field.
- On completion: semantic summary line + existing media/result block.
- On failure: error message + `Retry` button + `Why did this fail?` (deep-links to cron-manager admin URL).

#### 3b. Global "Tasks" drawer

> **Status (PR-D, May 2026):** Tasks drawer landed. `initTasksDrawer()` activates when `config.chatTasksDrawer === true` (PHP filter `wp_mcp_ai_chat_tasks_drawer`, default off). Old 4-counter strip remains the fallback. Drawer anatomy: health pill + filter tabs (All/Running/Pending/Completed/Failed) + batch bar (Cancel/Retry/Dismiss) + scrollable job rows with progress bar, Cancel/Retry buttons. Job IDs persisted in `localStorage` (key `wp_mcp_ai_tasks_{assistantId}`, max 200 entries). Drawer button shows `(N)` running badge. Source: PR-D, `assets/js/chat.js initTasksDrawer()`.

Replace the 4-counter strip with a `Jobs: 2 running` button opening a side drawer above the composer.

**Drawer anatomy:**
- **Header**: health pill (green/amber/red from `Async_Health_Monitor`) + filter tabs (All / Running / Pending / Completed / Failed) + badge.
- **Row**: status icon · tool/job title · elapsed / ETA · `Cancel` · `Retry` · overflow (Notify me · Open in Cron Manager · Copy job ID).
- **Multi-select**: batch Cancel / Retry / Dismiss.
- **Persistence**: job IDs stored in `localStorage`; rehydrated from registry on drawer open.
- **Feature flag**: `wp_mcp_ai_chat_tasks_drawer` filter — the old 4-counter strip remains as fallback when flag is off.

#### 3c. Toast + tab notifications

> **Status (PR-D, May 2026):** `showJobToast(container, type, job)` landed — subscribes via `wpMcpAiJobBus`; displays for 6 s with dismiss button. Tab-title badge (`updateTabTitleBadge(delta)`) increments `(N)` prefix while N jobs are running; decrements on completion/failure/cancel. Both are activated inside `initTasksDrawer()` when `config.chatTasksDrawer` is on.

- Toast component subscribes to `job:completed` / `job:failed` on `wpMcpAiJobBus`; displays for ~6 s with a `View result` link.
- Tab title prefixed with `(N)` while N jobs are running (parity with ChatGPT/Slack).
- Optional **Web Notifications API** opt-in for long-running jobs (Veo, transcript mining, docs-hub) when the tab is hidden.

---

### Phase 4 — Controls (Cancel / Retry / Notify me)

> **Status (PR-C, May 2026):** Cancel and retry routes landed. `cancel_job()`, `retry_job()`, `is_owned_by()` added to `WP_MCP_AI_Tool_Async_Executor`. REST handlers `handle_cancel_job_request()` / `handle_retry_job_request()` + source-dispatch helpers `try_source_cancel()` / `try_source_retry()` added to `WP_MCP_AI_REST_Tools_Controller`. PHPUnit coverage in `tests/rest/test-cron-status-controls.php`.

**New REST routes** under the existing cron-status controller:

| Method | Route | Capability |
|--------|-------|-----------|
| `POST` | `/mcp-ai/v1/cron-status/{job_id}/cancel` | `edit_posts` + source ownership check |
| `POST` | `/mcp-ai/v1/cron-status/{job_id}/retry` | `edit_posts` + source ownership check |
| `POST` | `/mcp-ai/v1/cron-status/{job_id}/notify` | `edit_posts` |

- Each registered source contributes optional `cancel( $job_id )` and `retry( $job_id )` callables returning `true` or `WP_Error` (UI hides button when `WP_Error`).
- `cancel()` implementations: flip status to `cancelled`, abort the next inline-async tick.
- `notify` registers a one-shot `wp_mcp_ai_job_completed_{job_id}` action that dispatches via the existing `WP_MCP_AI_Job_Notifier` hook.

---

### Phase 5 — Health & diagnostics surfacing

> **Status (PR-F, May 2026):** `system_status` is included in every list snapshot response and in every SSE `ping` heartbeat frame. It aggregates `WP_MCP_AI_Async_Health_Monitor::check_async_health()` + `WP_MCP_AI_Orchestration_Health_Service::get_health_status()` behind a defensive try/catch so a broken monitor can never surface a 500 on the chat endpoint.

- ✅ Include `system_status` in every list snapshot response.
- ✅ Drawer header shows health pill from `WP_MCP_AI_Async_Health_Monitor` + `WP_MCP_AI_Orchestration_Health_Service`.
- `Why did this fail?` expands `Job_Notifier::error_data` inline + link:  
  `admin.php?page=wp-mcp-ai-cron-manager&highlight={job_id}`.
- **Accessibility**: drawer is `role="region"` with `aria-live="polite"`; toasts use `role="status"`; timeline steps announce state changes via screen reader.

---

### Phase 6 — Performance & resilience

> **Status (PR-F, May 2026):** OTel spans landed — five new action hooks (`wp_mcp_ai_chat_jobs_snapshot`, `wp_mcp_ai_before/after_chat_jobs_stream`, `wp_mcp_ai_chat_jobs_cancel`, `wp_mcp_ai_chat_jobs_retry`) are fired at the relevant REST handler call-sites; `WP_MCP_AI_Otel_Span_Exporter::register()` subscribes listeners that emit `nvoos.chat.jobs.*` OTLP spans when an endpoint is configured. SSE heartbeat (every `SSE_JOB_HEARTBEAT_INTERVAL` polls) is also live. Tests: `tests/test-chat-jobs-otel-hooks.php` (11 cases).

- **Active-jobs index option** (`wp_mcp_ai_active_jobs`): registry pushes/removes job IDs on state changes → `get_async_tool_jobs()` / `get_video_generation_jobs()` become O(active) reads instead of `LIKE '%transient_%'` full-table scans. _(deferred)_
- **Drawer cap**: max 50 entries; paginated history via `GET /cron-status?page=2`.
- ✅ **SSE heartbeat**: explicit `event: ping` every 15 s + `Last-Event-ID` resume support.
- ✅ **OTel spans**: `chat.jobs.snapshot`, `chat.jobs.stream`, `chat.jobs.cancel`, `chat.jobs.retry` added. `WP_MCP_AI_Otel_Span_Exporter` now also exposes `reset_for_tests()` for test-suite isolation.

---

### Phase 7 — Docs, tests, rollout

> **Status (PR-G, May 2026):** Both docs landed — `docs/features/chat/cron-status-integration.md` (architecture, event schema, OTel hooks) and `docs/guides/developer/tool-development/registering-a-job-source.md` (developer how-to). `wp_mcp_ai_chat_tasks_drawer` filter default flipped to **`true`** (on) as of v1.9.3.

**New documentation:**
- `docs/features/chat/cron-status-integration.md` — architecture, registry contract, event schema. ✅ landed PR-G
- `docs/guides/developer/tool-development/registering-a-job-source.md` — how a new subsystem opts in with one filter registration. ✅ landed PR-G

**New/extended tests:**
- `tests/test-cron-status-service.php` — registry contract coverage.
- `tests/rest/test-cron-status-controls.php` — cancel / retry / notify routes.
- `packages/nvoos-cron-status/tests/` — JS smoke tests for the drawer state machine.

**Rollout:**
- `wp_mcp_ai_chat_tasks_drawer` filter defaults **off** in patch release.
- Flipped to **on** in the next minor release after one patch cycle. ✅ flipped PR-G

---

## Suggested PR Slicing

| PR | Phases | Risk | Reviewer focus |
|----|--------|------|----------------|
| **PR-A** "wire-up fixes" | Phase 0 | Minimal | `chat.js` + `handle_cron_status_request` |
| **PR-B** "job-source registry + stream loop" | Phase 1 + Phase 2 backend | Medium | PHP filter contract, SSE loop |
| **PR-C** "inline progress + cancel/retry (async executor)" | Phase 3a + Phase 4 (executor only) | Medium | JS bubble card, new REST routes | ✅ landed May 2026 |
| **PR-D** "Tasks drawer + toasts" (feature-flagged) | Phase 3b + Phase 3c | Medium | Drawer component, localStorage | ✅ landed May 2026 |
| **PR-E** "register remaining sources" | Phase 1 follow-on | Low | Each source adapter | ✅ landed May 2026 |
| **PR-F** "health + perf + OTel" | Phase 5 + Phase 6 | Low | Index option, OTel spans | ✅ landed May 2026 |
| **PR-G** "docs + flag default-on" | Phase 7 | Minimal | Docs, tests, flag flip | ✅ landed May 2026 |

---

## Files Likely Touched

```
assets/js/chat.js                                           # phases 0, 2, 3a, 3b, 3c
assets/js/cron-status-service.js                           # phases 1, 2 (new or split)
assets/js/tasks-drawer.js                                  # phase 3b (new)
assets/js/job-bus.js                                       # phase 2 (new or merged)
assets/css/chat.css                                        # phases 3a, 3b, 3c
includes/class-wp-mcp-ai-rest.php                          # phases 0, 4
includes/services/class-wp-mcp-ai-cron-status-service.php  # phases 0, 1, 2, 6
includes/services/class-wp-mcp-ai-job-notifier.php         # phases 1, 2
includes/services/class-wp-mcp-ai-tool-async-executor.php  # phases 1, 4
includes/services/class-wp-mcp-ai-gemini-video-generation-service.php # phase 1
includes/services/class-wp-mcp-ai-transcript-mining-job.php # phase 1 (E)
includes/crawler/class-wp-mcp-ai-crawler.php               # phase 1 (E)
addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php # phase 1 (E)
addons/pro/includes/mcp-servers/class-wp-mcp-ai-toolkit-mcp-rest-controller.php # phase 1 (E)
tests/test-cron-status-service.php                         # phase 7
tests/rest/test-cron-status-controls.php                   # phase 7 (new)
docs/features/chat/cron-status-integration.md              # phase 7 (new)
docs/guides/developer/tool-development/registering-a-job-source.md # phase 7 (new)
```

---

## References

- ChatGPT "Tasks" sidebar (OpenAI, 2024–2025)
- Claude agent timeline + cancel/retry (Anthropic, 2025)
- Gemini deferred notification model (Google, 2025)
- Vercel AI SDK streaming + `useChat` / `useCompletion` (2025)
- Notion AI step-card progress (Notion, 2025)
- GitHub Copilot inline task status (GitHub, 2025)
- `docs/architecture/inline-async-tick-pattern.md`
- `docs/ADR_002_toolkit_mcp_servers.md`
- `includes/services/class-wp-mcp-ai-job-notifier.php`
- `includes/services/class-wp-mcp-ai-cron-status-service.php`
