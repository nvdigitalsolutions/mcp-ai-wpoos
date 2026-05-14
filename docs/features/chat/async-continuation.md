# Async Chat Continuation

> **Status (May 2026):** All slices 1–6 complete. The full continuation pipeline is shipped: store → dispatcher → LLM re-entry → SSE channel → chat.js integration → Pro webhook notifier → OTel observability. See "Roadmap" at the bottom.

## Problem

When a long-running async tool — Veo video generation, Sora, image batches,
browser automation, deep research — returns
`{ async: true, status: 'pending', job_id: '…' }`, the agentic loop exits
immediately. Today the conversation `messages[]` array is dropped on the
floor: nothing persistent links it to the `job_id`, so when
`wp_mcp_ai_job_completed` fires several minutes later there is no way to
re-engage the LLM or even know *which chat session started the job*.

The user-visible symptom: Veo renders inline (good) but the assistant
never says "Here's your video — it shows X. Want me to refine it?" The
chat looks half-finished.

## Solution overview

```
[Tool returns async_pending]
        │
        ▼
WP_MCP_AI_Chat_Continuation_Store
  ├── persists { messages[], assistant_id, user_id, tool_call_id,
  │             tool_name, provider, model, options, harness_profile }
  │   keyed by job_id (transient, 24 h TTL, LRU-capped at 500)
  └── secondary index: session_id → [ job_ids ]
        │
        ▼
[wp_mcp_ai_job_completed | failed | cancelled fires (existing)]
        │
        ▼
WP_MCP_AI_Chat_Continuation_Dispatcher  (priority 20 — after Job_Notifier)
  ├── reads the snapshot
  ├── schedules WP-Cron: wp_mcp_ai_resume_chat_after_job ( job_id )
  └── cron worker:
        1. acquires processing lock (idempotent vs cron retries)
        2. appends a tool-result message to messages[]
        3. fires wp_mcp_ai_chat_continuation_ready    ← LLM re-entry hook
        4. fires wp_mcp_ai_chat_continuation_dispatched ← OTel + webhooks
        5. releases lock
```

The dispatcher deliberately does **not** call the LLM itself.
Re-entering the agentic loop is the responsibility of the downstream
subscriber (the upcoming `WP_MCP_AI_REST::resume_chat_after_job()` in
Slice 2 step 3). Separating dispatch from delivery keeps every slice
independently shippable — exactly the pattern Stripe / GitHub use:
events → queue → workers.

## Why this matches industry practice

| Pattern | Where it's used | Match here |
|---|---|---|
| Durable correlation between async run + conversation | OpenAI Responses API (`background=true`, `response.id`), Anthropic batches, LangGraph checkpoints | `Chat_Continuation_Store` keyed by `job_id` |
| Push-first, polling-fallback | Stripe webhooks, A2A `pushNotificationConfig`, OpenAI `response.completed` webhook | Existing per-job webhooks plus the upcoming chat-session SSE channel (Slice 3) |
| In-thread posting of async results | Slack / Teams bots, MCP `notifications/progress` | Resumed assistant message will be appended to the persisted transcript |
| Signed callbacks + idempotency keys | Stripe, GitHub, A2A | `processing_at` lock on the continuation row; `(session_id, job_id)` is the natural idempotency key |
| `Last-Event-ID` resume | RFC 6202 / HTML5 SSE | Already used by `cron-status`; will be extended to chat-session SSE in Slice 3 |

## Storage layout

| Key | Type | Contents |
|---|---|---|
| `_transient_wp_mcp_ai_chat_cont_{job_id}` | transient (24 h) | Snapshot row (see schema below) |
| `wp_mcp_ai_chat_continuation_index` | option (not autoloaded) | `{ session_id: [ job_id, … ] }` secondary index |
| `wp_mcp_ai_chat_continuation_lru` | option (not autoloaded) | LRU-ordered list of `{ job_id, created_at }` |

**Snapshot schema** (`WP_MCP_AI_Chat_Continuation_Store::normalize_payload()`):

```php
array(
    'job_id'           => 'gemini_video_…',
    'chat_session_id'  => 'sess_uuid',
    'assistant_id'     => 42,
    'user_id'          => 7,
    'guest_token'      => '',
    'tool_call_id'     => 'call_abc',
    'tool_name'        => 'generate_veo_video',
    'provider'         => 'gemini',
    'model'            => 'gemini-1.5-pro',
    'options'          => array( /* provider options */ ),
    'harness_profile'  => array( /* harness layers / weights */ ),
    'messages'         => array( /* OpenAI-format conversation */ ),
    'created_at'       => 1715600000,
    'expires_at'       => 1715686400,
    // Added by the dispatcher when the job terminates:
    'terminal_status'  => 'completed' | 'failed' | 'cancelled',
    'terminal_result'  => array( /* tool result */ ),
    'terminal_at'      => 1715600090,
    // Added by the cron worker:
    'processing_at'    => 1715600091,
    'processing_ttl'   => 300,
);
```

Limits:

| Filter | Default | Purpose |
|---|---|---|
| `wp_mcp_ai_chat_continuation_enabled` | `true` | Master kill switch |
| `wp_mcp_ai_chat_continuation_ttl` | `DAY_IN_SECONDS` | Snapshot lifetime |
| `wp_mcp_ai_chat_continuation_max_total` | `500` | Site-wide LRU cap |
| `wp_mcp_ai_chat_continuation_max_per_session` | `32` | Per-session cap |
| `wp_mcp_ai_chat_continuation_max_messages_size` | `524_288` bytes | Guard against oversized snapshots |
| `wp_mcp_ai_chat_continuation_cron_delay` | `1` | Seconds delay before cron worker runs |

## Hook reference

### Filters

- `wp_mcp_ai_chat_session_id_generated` — override the UUID generator
  when minting a chat session id. Signature: `( string $id, array $context )`.
- `wp_mcp_ai_chat_continuation_enabled` — master kill switch.
  Signature: `( bool $enabled, string $job_id, string $terminal_status )`.
- `wp_mcp_ai_chat_continuation_should_dispatch` — late opt-out for a
  specific continuation. Signature:
  `( bool $should, array $snapshot, string $terminal_status, array $result )`.
- `wp_mcp_ai_chat_continuation_message` — rewrite the injected
  tool-result message before LLM re-entry. Signature:
  `( array $message, array $snapshot, string $terminal_status, array $result )`.
- `wp_mcp_ai_chat_continuation_ttl` — override snapshot TTL.
- `wp_mcp_ai_chat_continuation_max_total` — override global LRU cap.
- `wp_mcp_ai_chat_continuation_max_per_session` — override per-session cap.
- `wp_mcp_ai_chat_continuation_max_messages_size` — override messages
  size guard (bytes).
- `wp_mcp_ai_chat_continuation_cron_delay` — override cron scheduling
  delay (seconds).

### Actions

- `wp_mcp_ai_chat_continuation_stored` — fired after a snapshot is
  persisted. Signature: `( string $job_id, array $snapshot )`.
- `wp_mcp_ai_chat_continuation_ready` — fired by the cron worker after
  the tool-result message has been injected. **This is the seam where
  the LLM re-entry path attaches in Slice 2 step 3.** Signature:
  `( array $snapshot, string $terminal_status, array $terminal_result )`.
- `wp_mcp_ai_chat_continuation_dispatched` — fired after the
  continuation has been driven to completion. Signature:
  `( string $job_id, array $snapshot, string $terminal_status )`.

## Integration points

### REST chat handler

`WP_MCP_AI_REST::snapshot_chat_continuation_on_async_pending()` is
called at both `if ( $has_async_pending_result ) break;` exits in
`includes/class-wp-mcp-ai-rest.php` (non-streaming and streaming
agentic loops). It writes one continuation row per pending `job_id`.

### Job notifier

The dispatcher hooks `wp_mcp_ai_job_completed`, `wp_mcp_ai_job_failed`,
and `wp_mcp_ai_job_cancelled` at priority `20` — *after* the existing
`WP_MCP_AI_Job_Notifier` cache update (priority `10`) — so the cron
worker reads a consistent terminal view. No change to existing
notifier semantics.

### WP-Cron

A single-event cron hook (`wp_mcp_ai_resume_chat_after_job`) drives the
resume. The cron worker is idempotent on the continuation row's
`processing_at` lock; concurrent retries no-op.

## Backwards compatibility

- All new behaviour is gated by `wp_mcp_ai_chat_continuation_enabled`
  (default `true`).
- No change to existing `wp_mcp_ai_job_completed` semantics — the
  dispatcher is purely additive.
- No change to the inline video render path (`displayAsyncToolResult`
  + `wpMcpAiJobBus 'job:completed'` in `chat.js` continue to work
  unchanged). The JS de-dupe with `chat:resumed` arrives in Slice 4.

## Roadmap

| Slice | Status | PR |
|---|---|---|
| 1 — Continuation store + correlation ID | ✅ landed | this PR |
| 2 core — Dispatcher skeleton + ready/dispatched hooks | ✅ landed | this PR |
| 2 step 3 — `WP_MCP_AI_Chat_Continuation_LLM_Re_Entry` (LLM re-entry) | ✅ landed | this PR |
| 3 — `GET /mcp-ai/v1/chat-sessions/{id}/stream` SSE channel | ✅ landed | this PR |
| 4 — `assets/js/chat.js` integration | ✅ landed | this PR |
| 5 — Pro multi-channel webhook notifier | ✅ landed | this PR |
| 6 — OTel observability + JS unit tests | ✅ landed | this PR |

## Testing

- `tests/test-chat-continuation-store.php` — CRUD, TTL, LRU, per-session
  cap, session-index lookup, oversize guard, `stored` action, lock
  exclusivity, `session_id` filter.
- `tests/test-chat-continuation-dispatcher.php` —
  `job_completed/failed/cancelled` → cron → `ready` + `dispatched`
  action chain, idempotency lock, message filter.

## Related

- `docs/features/chat/cron-status-integration.md` — sibling subsystem
  (cron-status SSE + Tasks Drawer) that already implements
  Last-Event-ID resume; Slice 3 will reuse its `WP_MCP_AI_SSE_Handler`.
- `docs/hooks-reference.md` — full hook catalogue.
