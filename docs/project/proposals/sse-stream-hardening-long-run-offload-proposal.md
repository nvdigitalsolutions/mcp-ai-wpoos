# SSE Stream Hardening: Long-Run Offload Proposal

**Status:** Proposal (Phase 4a shipped separately; 4b pending)
**Date:** 2026-10-04
**Context:** nirmanawellness (Cloudways nginx → Apache, Cloudflare front) chat
client failures — `net::ERR_HTTP2_PROTOCOL_ERROR` / `SSE stream processing
error: network error` during long agentic tool runs.

## Problem

The chat endpoint (`/mcp-ai/v1/chat-client`) streams the agentic loop over a
single SSE connection. PHP is single-threaded: while a tool executes (e.g.
`ocr_pdf_text` at ~74 s) no bytes reach the client. Proxies between the
browser and PHP — Cloudflare (free/pro ~100 s read timeout) and nginx
(`proxy_read_timeout`, commonly 60 s on Cloudways) — reset connections they
consider idle. The reset surfaces in the browser as an HTTP/2 protocol error
mid-stream, losing the whole turn.

Two distinct failure shapes:

1. **Idle windows between steps** — the stream emits nothing before/after tool
   calls and LLM calls. Fixed by **Phase 4a** (inter-step keepalive comment
   frames), already shipped.
2. **A single blocking step longer than the proxy read timeout** — no
   in-process mechanism can emit bytes during a blocking tool call, so 4a
   cannot help. This needs **Phase 4b** (long-run offload) or a server-side
   timeout increase.

## Phase 4a (shipped)

`WP_MCP_AI_REST::handle_chat_request_with_streaming()` now emits an SSE
keepalive comment frame (via the new `send_sse_keepalive()` helper delegating
to `WP_MCP_AI_SSE_Handler::send_sse_comment()`) at four boundaries:

1. immediately after the SSE headers,
2. before the initial LLM call,
3. after `tool_start` and before `execute_tool_call_internal()`,
4. before each in-loop LLM call.

Comment frames are ignored by EventSource clients, flush through nginx and
Cloudflare, and reset idle read-timeout counters. Tests:
`tests/rest/test-sse-stream-keepalive.php`.

**Limits:** a single tool call that exceeds the proxy's idle timeout still
kills the stream. 4a reduces the silent windows to zero but cannot help while
PHP is blocked inside one long tool.

## Phase 4b — deadline-triggered offload to the job stream

### Goal

Before the stream is likely to be reset (~75 s into the request), hand the
*remaining* agentic work to the existing background-job machinery and tell the
client to follow the job stream, which already polls with 15 s heartbeats and
a 300 s budget (`WP_MCP_AI_SSE_Stream::stream_job_status()`).

### Design

1. **Deadline detection.** Record `microtime( true )` at request start
   (`$request_start_timestamp` exists; switch to microtime). At each safe
   boundary — before each tool execution and before each in-loop LLM call —
   compare elapsed time against
   `apply_filters( 'wp_mcp_ai_sse_offload_elapsed_seconds', 75 )`. Skip the
   check when the filter returns 0 (feature off).
2. **State snapshot.** Reuse the `WP_MCP_AI_Chat_Continuation_Store` payload
   shape (messages incl. all tool messages so far, options minus attachments/
   memory_documents/tools, provider, model, assistant_id, user_id,
   chat_session_id). Add `iteration` and `max_iterations` so the worker can
   resume the loop where it stopped.
3. **Job creation.** Create a job through `WP_MCP_AI_Async_Job_Queue` (new
   `job_type`, e.g. `chat_stream_continuation`) and schedule it via the
   existing Action Scheduler bridge (`WP_MCP_AI_Async_Scheduler_Bridge`), or
   follow the `WP_MCP_AI_Chat_Continuation_Dispatcher` cron pattern
   (`wp_schedule_single_event` + `spawn_cron()`). Report progress through
   `WP_MCP_AI_Job_Notifier` (steps + heartbeat, 15 s cadence already
   implemented).
4. **SSE handoff.** Emit a `job_offload` event
   (`{ type: 'job_offload', job_id, session_key, message }`) followed by
   `send_sse_done()` + `finish_sse()`. The client (chat.js / Pro SPA) needs a
   handler that switches to the existing job-status stream/polling for that
   job_id — the same machinery used for Veo video generation.
5. **Worker completion.** The background worker resumes the loop:
   - call the LLM with the snapshot messages;
   - if the response contains tool_calls, execute them (bounded by the
     remaining iteration budget and a worker time cap) — this extends
     `WP_MCP_AI_Chat_Continuation_LLM_Re_Entry`, which currently performs a
     single LLM call and would drop tool_calls;
   - buffer the final assistant message via
     `WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session_id, 'chat:resumed',
     ... )` so it renders through the client's existing session-frame polling;
   - mark the job completed (or failed, with a terminal status the client can
     render).
6. **Failure handling.** Cron may not run promptly: keep `spawn_cron()`, cap
   the worker duration (~240 s), and let the client fall back to re-sending
   the turn if the job goes stale (the continuation store already holds the
   snapshot; a `resume` chat request parameter can restore it server-side).

### Reuse map

| Piece | Existing code |
| --- | --- |
| Snapshot persistence | `WP_MCP_AI_Chat_Continuation_Store` |
| Scheduling | `WP_MCP_AI_Async_Scheduler_Bridge` / `WP_MCP_AI_Chat_Continuation_Dispatcher` |
| Job progress + heartbeat | `WP_MCP_AI_Job_Notifier` + `WP_MCP_AI_SSE_Stream::stream_job_status()` |
| Client job polling | chat.js `startAsyncToolPolling()` / `wpMcpAiJobBus` |
| Final message delivery | `WP_MCP_AI_Chat_Session_Frame_Buffer::push( ..., 'chat:resumed' )` |

### Open questions

- Should offload be opt-in per assistant (a config flag) or global with the
  filter as the off switch? Recommendation: global default-on at 75 s,
  filterable.
- The background worker executing tools needs the assistant tool allowlist and
  user context — the snapshot must carry them (assistant_config).
- Cost tracking: background LLM/tool costs must still be attributed to the
  assistant/user (reuse `calculate_response_cost` + usage tracker in the
  worker).

### Interim server-side mitigations (no code)

Until 4b ships, sites with long tool runs can reduce resets:

- **Cloudflare:** raise the origin read timeout (paid plans:
  `proxy_read_timeout` is not customer-tunable on free/pro — on paid plans
  submit a support request), or grey-cloud the chat route.
- **Cloudways nginx:** raise `proxy_read_timeout` for the
  `/wp-json/mcp-ai/v1/chat-client` location (managed platform — submit a
  support ticket with the route; typical default is 60 s).
- Keep PHP `max_execution_time` and the plugin's 300 s `set_time_limit()` as
  is; the plugin-side headers are already correct for HTTP/2.

## Acceptance criteria (4b)

- A chat turn whose agentic loop exceeds ~75 s offloads to a job; the client
  continues to show progress and renders the final answer without a network
  error.
- Streams under the deadline never offload (no behavior change for fast
  turns).
- Job failure paths render a terminal status instead of hanging.
- Tests: deadline-decision unit tests (elapsed < / > threshold), offload
  snapshot round-trip, worker loop completion with tool_calls, SSE
  `job_offload` event emission.
