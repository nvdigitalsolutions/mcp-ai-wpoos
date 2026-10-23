# SSE Stream Hardening: Long-Run Offload — Implementation Plan

**Status:** Implemented and verified (B1–B4 shipped 2026-10-24 on `fix/sse-long-run-offload`; Docker e2e on WP 6.9 + WP 7.1 PHPUnit green, phpcs clean)
**Date:** 2026-10-23
**Parent proposal:** `docs/project/proposals/sse-stream-hardening-long-run-offload-proposal.md` (Phase 4b)
**Branch target:** `fix/sse-long-run-offload` from `alpha-working`

## Goal

Before a chat SSE stream is likely to be reset by proxy idle-read timeouts
(~75 s), hand the remaining agentic work to the background-job machinery and
tell the client to follow the job stream. Fast turns never offload — zero
behavior change under the deadline.

Acceptance criteria (from the proposal):

1. A chat turn whose agentic loop exceeds ~75 s offloads to a job; the client
   keeps showing progress and renders the final answer without a network error.
2. Streams under the deadline never offload.
3. Job failure paths render a terminal status instead of hanging.
4. Tests: deadline decision, snapshot round-trip, worker loop completion with
   tool_calls, `job_offload` SSE emission.

## Verified current-state map (all reuse pieces exist)

| Proposal reference | Verified location | State |
|---|---|---|
| Streaming loop | `includes/class-wp-mcp-ai-rest.php::handle_chat_request_with_streaming()` (~L4791) — keepalives at all four boundaries (4a shipped), `$request_start_timestamp = time()` at L4817 | ✅ — switch to `microtime(true)` |
| Snapshot store | `includes/services/class-wp-mcp-ai-chat-continuation-store.php` — transient `wp_mcp_ai_chat_cont_{job_id}`, TTL 1 day, 512 KB messages cap, LRU 500, per-session cap 32, `normalize_payload()` requires `chat_session_id` | ✅ — needs `iteration`/`max_iterations`/`assistant_config` fields |
| Job queue | `WP_MCP_AI_Async_Job_Queue::queue_job()` (custom table, retries, DLQ) | ✅ — new `job_type` `chat_stream_continuation` |
| Scheduling | `WP_MCP_AI_Async_Scheduler_Bridge::enqueue_job()` (Action Scheduler when present) + dispatcher cron pattern (`wp_schedule_single_event` + `spawn_cron()`) in `includes/services/class-wp-mcp-ai-chat-continuation-dispatcher.php` | ✅ |
| Job progress + heartbeat | `WP_MCP_AI_Job_Notifier` (started/progress/step/completed/failed + SSE emit); client stream `WP_MCP_AI_Job_Notifier_REST::handle_job_stream()` → `WP_MCP_AI_SSE_Stream::stream_job_status()` (15 s heartbeat, 300 s budget, ownership-gated) | ✅ |
| LLM re-entry | `includes/services/class-wp-mcp-ai-chat-continuation-llm-re-entry.php::on_continuation_ready()` — **single LLM call, drops tool_calls** | ⚠️ needs the agentic loop (G5) |
| Final message delivery | `WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session_id, 'chat:resumed', ... )` | ✅ |
| Client job polling | chat.js: `startAsyncToolPolling()`, `wpMcpAiJobBus`, `createJobProgressCard()`, `cron_job_status` SSE handlers | ✅ |
| Client SSE surfaces | chat.js `processSSEStream()` (L14948); spa-v2 `src/sse-adapter.ts::createChatFetch` (AI-SDK translation) consumed by `src/hooks/useChatSpoke.ts`; spa-v2 job stream parsing `src/hooks/useJobBus.ts` | ✅ — both need a `job_offload` branch (G6) |
| SSE primitives | `send_sse_event()`, `send_sse_keepalive()`, `send_sse_done()`, `finish_sse()` on the REST class | ✅ |

## Gap analysis (what must be built)

- **G1 — Deadline detection.** `microtime(true)` at stream start; at each safe
  boundary (before each tool execution, before each in-loop LLM call) compare
  elapsed against `apply_filters( 'wp_mcp_ai_sse_offload_elapsed_seconds', 75 )`;
  0 disables. First boundary past the deadline triggers offload.
- **G2 — Snapshot extension.** `WP_MCP_AI_Chat_Continuation_Store` payload gains
  `iteration`, `max_iterations`, `assistant_config` (tool allowlist + provider
  settings), `agentic_cost_accumulator` (so costs don't vanish mid-turn), and a
  `job_type` discriminator so the worker can tell continuation rows apart.
  Strip `attachments` / `memory_documents` / `tools` (per proposal). If the
  512 KB messages cap would truncate the snapshot, **do not offload** — stay
  on the stream (4a behavior) and log.
- **G3 — Job creation + SSE handoff.** New `job_type` `chat_stream_continuation`;
  queue via `WP_MCP_AI_Async_Job_Queue`, schedule via the AS bridge (cron
  fallback with `spawn_cron()`). Emit
  `send_sse_event( 'job_offload', { job_id, session_key, message } )` then
  `send_sse_done()` + `finish_sse()`. The PHP worker releases well inside the
  proxy window — the network reset is structurally gone.
- **G4 — Worker (server).** New `WP_MCP_AI_Chat_Stream_Offload_Worker`
  (`includes/services/`): loads the snapshot, `wp_set_current_user( $user_id )`,
  runs the **agentic loop** — LLM call → tool_calls → execute via the same
  tool-execution path with the snapshot's `assistant_config` allowlist →
  loop, bounded by remaining `max_iterations - iteration` and a 240 s wall
  clock — reporting steps/heartbeats through `WP_MCP_AI_Job_Notifier`
  (15 s cadence) and attributing costs via `calculate_response_cost` + the
  usage tracker. Buffers the final assistant message through
  `WP_MCP_AI_Chat_Session_Frame_Buffer::push( ..., 'chat:resumed' )` and marks
  the job completed/failed with a terminal status. Extends the re-entry
  pattern (`on_continuation_ready` stays untouched for async-tool resumes).
- **G5 — Client handlers.**
  - chat.js `processSSEStream()`: new `job_offload` event branch — swap the
    bubble to the job-progress card (existing `createJobProgressCard` +
    `wpMcpAiJobBus`), follow the job via the existing job-stream/event-bus
    machinery (same path Veo video jobs use), and render the final message
    from the existing `chat:resumed` session-frame polling.
  - spa-v2 `sse-adapter.ts`: translate `job_offload` into a distinguishable
    stream event; `useChatSpoke` follows the job via `useJobBus` and the
    existing resumed-frame path (verify how spa-v2 renders `chat:resumed`
    frames today — the re-entry already emits them — and reuse).
  - **Stale-job fallback:** if the job doesn't reach a terminal state within
    ~300 s (job-stream budget), the client re-sends the turn.
- **G6 — Failure handling.** Worker failure → `handle_job_failed()` → client
  renders a terminal error card (existing job-card failure UI). Cron latency
  mitigated by `spawn_cron()` + AS bridge; a `resume` request parameter on
  `/chat` (server restores the snapshot from the continuation store) is the
  deep-recovery path — implement only if the fallback proves insufficient
  during B3 testing.
- **G7 — Backpressure interplay (verify, no build).** The offload job counts
  toward `WP_MCP_AI_Load_Guard` (already aggregates the async queue) and tool
  executions inside the worker go through `WP_MCP_AI_Concurrency_Guard_Subscriber`
  — so an offload storm still gets 429-shed rather than jamming the site.

## Phases

### Phase B1 — server handoff path (no client change needed to fix the reset)

Files: `includes/class-wp-mcp-ai-rest.php` (deadline checks + handoff),
`includes/services/class-wp-mcp-ai-chat-continuation-store.php` (fields),
`includes/services/class-wp-mcp-ai-chat-stream-offload-worker.php` (new;
minimal: single-LLM completion reusing the re-entry internals), job queue
`job_type` registration.

Tests (Docker PHPUnit): deadline decision (elapsed < / > threshold),
snapshot round-trip incl. new fields, oversize-snapshot no-offload, SSE
`job_offload` emission, worker single-call + frame buffer push.

Exit criterion: a slow turn emits `job_offload`, closes the stream cleanly,
and the final message lands via the frame buffer — verified with a mock
provider + slow tool in the Docker `oos-wp` stack with the filter set low
(e.g. 1 s) for the test only.

### Phase B2 — worker agentic loop

Extend the worker to run tool_calls with the remaining iteration budget +
240 s cap + cost attribution + `Job_Notifier` steps. Unit tests: tool_calls
completion, budget enforcement, time cap, failure terminal states, cost
attribution (usage tracker updated with assistant/user IDs).

### Phase B3 — client handlers + fallback

chat.js + spa-v2 `job_offload` handling, job-card follow, stale-job re-send.
Both JS bundles rebuilt per the spa-ui skill rebuild map (`assets/js/chat.js`
rebuild + `addons/pro/assets/spa-v2/assets/dist/` and
`examples/nvoos-complete-velocity/dist/` — the console embeds spa-v2).

### Phase B4 — rollout

Default-on at 75 s behind `wp_mcp_ai_sse_offload_elapsed_seconds` (0 = off),
docs (proposal status + changelog + runbook note), full validation matrix
(WP 6.9 + 7.1, phpcs), PR against `alpha-working`.

## Docker e2e recipe (new branch, no new infra)

Uses the existing `oos-wp` stack (per the test-suite skill, one-off runner
with the `mcp-ai-wpoos-vendor` volume; main checkout bind mount):

1. Seed a test assistant whose provider is the mock/test client (returns a
   `tool_calls` response pointing at a slow tool — e.g. a shell `sleep 3`
   via the exec tool, or a test fixture tool).
2. `add_filter( 'wp_mcp_ai_sse_offload_elapsed_seconds', fn() => 1 );` via
   mu-plugin in the container for the test run.
3. `curl -N POST /wp-json/mcp-ai/v1/chat-client` (cookie + nonce flow as in
   the console e2e) → assert an early `job_offload` SSE event with `job_id`,
   then `[DONE]`, stream closes well under 75 s.
4. Force job processing: `wp cron event run wp_mcp_ai_process_async_jobs`
   (or the AS runner) in the container; assert the job reaches `completed`
   via `mcp-ai/v1/job-stream?job_id=…` (15 s heartbeats) and the
   `chat:resumed` frame appears via the session-frame endpoint.
5. Negative paths: fast turn (no offload), worker failure → terminal
   `failed` status, oversize snapshot → stream continues without offload.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Worker needs user context for capability checks | Snapshot carries `user_id`; worker calls `wp_set_current_user()` before tool execution |
| Cost double-count or loss | Snapshot carries the accumulator; worker continues the same accounting; no re-billing of pre-offload iterations |
| Cron latency → job sits | `spawn_cron()` + AS bridge; client stale-job fallback re-sends |
| Worker itself exceeds limits | 240 s wall clock (< 300 s stream budget); DLQ + retry on failure |
| Snapshot too large (512 KB cap) | No-offload fallback (stay on stream), logged |
| Transcript persistence for offloaded turns | Discovery item in B1: mirror the re-entry path (frame buffer) and confirm transcript finalization — if the re-entry doesn't persist transcripts, add it in the worker |
| Two client surfaces drift | `job_offload` event shape fixed once in the proposal; both handlers asserted in their existing JS test suites |

## Open decisions — RESOLVED (2026-10-24)

1. **Default-on at 75 s globally** via the `wp_mcp_ai_sse_offload_elapsed_seconds`
   filter (0 = off). ✅ shipped.
2. Worker **240 s cap** (`wp_mcp_ai_sse_offload_worker_cap_seconds`) and the
   existing 300 s client job budget. ✅ shipped.
3. **Skipped the `resume` request-parameter deep-recovery path** — shipped the
   client re-send fallback instead (the existing job-bus `watchJob` timeout
   and terminal-failure rendering cover it; no dedicated re-send). ✅ decided.

Additional hardening found and fixed during e2e (not in the original plan):

- **Terminal failure contract** — the worker's pre-loop throws bypassed the
  notifier, so the client's `watchJob` would hang its full budget; every
  failure path now records `handle_job_failed()` (with a `WP_Error`) + a
  `chat:error` frame and throws so the queue row is marked failed (returning
  a `WP_Error` would mark it completed).
- **Payload fallbacks** — `job_data` now carries `chat_session_id` /
  `assistant_id` / `user_id` so terminal failures reach the right session
  even when the snapshot is missing/expired.
- **Init-time post-insert warning** — unrelated pre-existing: the Pro module
  registry's `content_format_templates` seeding deferred to `wp_loaded`
  (`wp_insert_post()` on `init` reads a null `$wp_rewrite`).

## Sizing estimate

B1 ≈ 1 working session (server-only, testable end-to-end in Docker). B2 ≈ 1
session. B3 ≈ 1–2 sessions (two JS surfaces + rebuilds). B4 ≈ half a session.
Total ≈ 4–5 sessions including the dual-matrix test + phpcs gates.
