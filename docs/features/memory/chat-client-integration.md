# Chat Client ⇄ Memory System Integration

> Status: Phase 1 (Wire-up) + Phase 2 (Session-boot recall) + Phase 4 (Slash commands) + Phase 5 (Tests + docs) — shipped in NV oOS 1.6.0.
> Phase 3 (Memory Drawer UI) and SSE memory-event frames are tracked as follow-ups.

## What this is

This document describes how the **chat client** (the JavaScript front-end loaded
by the chat shortcode, block, Elementor widget, and chat-bubble) talks to the
**agent memory system** (`store_agent_context`, `recall_memory`,
`retrieve_agent_memory`, `wake_up_context`, `manage_context_lifecycle`,
`WP_MCP_AI_Memory_Capture_Service`).

Before this integration the chat client was effectively memory-blind: it sent
only the rolling `maxHistoryMessages: 8` window, never called
`wake_up_context`, never showed users which memories the assistant referenced,
and never gave users a way to *say* "remember this" without phrasing a natural-
language instruction.

## Architecture

```
┌──────────────────────────────────────────────────────────────────────────┐
│                               Browser                                      │
│                                                                          │
│  chat.js ──┐                                                             │
│            │                                                             │
│  chat-memory-service.js ────── fetch ──┐                                 │
│            │                            │                                │
│  /remember /forget /scope ─────────┐    │                                │
└────────────────────────────────────┼────┼────────────────────────────────┘
                                     │    │ X-WP-Nonce
                                     ▼    ▼
        ┌────────────────────────────────────────────────────────┐
        │ WP_MCP_AI_REST_Chat_Memory_Controller                  │
        │   /mcp-ai/v1/chat-memory/{preferences,wake-up,        │
        │                          recall,store,<context_id>}    │
        │                                                        │
        │   • capability-checked (edit_posts for writes)         │
        │   • per-user kill-switch (user meta + filter)          │
        │   • no-guests policy                                   │
        │   • input sanitised with wp_kses_post / sanitize_*     │
        └────────────────────────────────────────────────────────┘
                                     │
                ┌────────────────────┼─────────────────────┐
                ▼                    ▼                     ▼
   wake_up_context        recall_memory /          store_agent_context
                          retrieve_agent_memory    manage_context_lifecycle
                          │
                          ▼
              ┌────────────────────────────────────────┐
              │ transient context index (fast path)    │
              │       ∪                                │
              │ {prefix}jet_cct_ai_agent_memories      │
              │  via WP_MCP_AI_Agent_Memory_CCT_Reader │
              │ (durable fallback so the drawer keeps  │
              │  rendering after object-cache flush)   │
              └────────────────────────────────────────┘
```

> **Read path (1.6.1+):** `GET /chat-memory/recall` routes to `recall_memory`
> only when a `wing` is supplied — `recall_memory` hard-requires the wing for
> MemPalace semantics. When the drawer's no-scope case sends an empty wing,
> the controller falls through to `retrieve_agent_memory`, which lists every
> memory for the agent. Both tools now consult
> `WP_MCP_AI_Agent_Memory_CCT_Reader`: it hooks
> `wp_mcp_ai_recall_memory_candidates` to hydrate `recall_memory` from the
> durable CCT mirror, and exposes a static `get_transient_shaped_records_for_agent()`
> helper that `retrieve_agent_memory` calls as a fallback when its per-agent
> transient index is empty. The transient layer remains the primary read path;
> the CCT is consulted only as a backstop. The candidate cap defaults to 500
> and is tunable via `apply_filters( 'wp_mcp_ai_agent_memory_cct_reader_limit', 500, $agent_id )`.

## v1.1.61 — Agent Identity Bridging

The memory store and the drawer used to bucket records by `md5(agent_id)`.
When the storing side passed a virtual / non-numeric key (e.g.
`nvoos-pro-spa-memory-drawer`, `virtual_planner_1`) while the UI recalled by
the canonical assistant post ID, records landed in a different bucket and
the drawer looked empty. v1.1.61 bridges the two sides:

### Store side (`store_agent_context`)

- `WP_MCP_AI_Agent_Identity_Resolver::resolve()` canonicalises a non-numeric
  agent key using the `assistant_id` carried in the tool execution context
  (or a previously recorded alias).
- The alias mapping persists in the `wp_mcp_ai_agent_id_aliases` site
  option — bounded to 200 entries, never autoloaded, every value sanitised.
- The tool envelope and the `wp_mcp_ai_memory_stored` action payload echo
  `original_agent_id` and `agent_id_resolved` so callers can detect
  "saved under X, drawer watches Y" immediately.

### Recall side (`GET /chat-memory/recall`)

- Unscoped recall merges buckets for every alias mapped to the requested
  agent (up to 3 aliases + the canonical ID). Each merged record carries a
  `stored_under` stamp; the envelope carries `merged_sources` and an updated
  `count`. Wing/room-scoped (`recall_memory`) recall keeps single-bucket
  semantics.
- Default limit is 25 when none is supplied (capped at 50).

### Drawer UI

- Wing/room scope chips on every item, with an explicit **Unscoped** chip
  when a memory has no scope, and a `stored under: <bucket>` chip for
  merged records.
- The panel header shows the exact agent ID the drawer recalls under
  (`data-testid="wp-mcp-ai-memory-agent-id"`).
- A show-all-scopes toggle flips between scoped and unscoped views.
- Open drawers refresh in place when a `memory_event` store SSE frame
  arrives.

### Graceful degradation

- `wake_up_context` catches any graph-bridge failure (`WP_Error`, throwable,
  malformed rows) and falls back to the transient retrieval path.
- A scoped wake-up or recall that errors retries once without the
  wing/room scope before the error surfaces.

## REST surface

All routes live under `/wp-json/mcp-ai/v1/chat-memory/`. Authentication uses
the standard `X-WP-Nonce` header (or any auth method already supported by the
plugin's REST controller base).

| Method | Route                     | Capability   | Description                                          |
|--------|---------------------------|--------------|------------------------------------------------------|
| GET    | `/preferences`            | logged-in    | Read per-user `enabled` and `autosummarize` toggles. |
| POST   | `/preferences`            | logged-in    | Update those toggles.                                |
| GET    | `/wake-up`                | logged-in    | Build a wake-up system block for a given agent.      |
| GET    | `/recall`                 | logged-in    | Hierarchical recall (wing/room/query).               |
| POST   | `/store`                  | `edit_posts` | Store a verbatim user-driven memory.                 |
| PUT    | `/<context_id>`           | `edit_posts` | Update an existing memory (title/content/tags/etc.). |
| DELETE | `/<context_id>`           | `edit_posts` | Delete an existing memory.                           |

Guests are denied (HTTP 403). The site-wide kill-switch
`apply_filters( 'wp_mcp_ai_chat_memory_enabled', $enabled, $user_id )` lets
hardened deployments disable the surface entirely.

## Per-user toggles and site-wide gate

Three gates control access to the chat-memory bridge:

### Gate 1 — Site-wide admin toggle (v1.1.15+)

A **Enable Chat-Client Memory** checkbox is available in **NV oOS → Orchestration → Settings**. When unchecked, chat-memory is disabled for the entire site regardless of the filter or per-user meta below. This is the recommended way to disable chat-memory for a whole site without writing code.

### Gate 2 — Site-wide filter

```php
// Disable chat memory entirely (e.g. GDPR region).
add_filter( 'wp_mcp_ai_chat_memory_enabled', '__return_false' );
```

When this filter returns `false`, **every** `/mcp-ai/v1/chat-memory/*` route returns HTTP 403 regardless of user state.

### Gate 3 — Per-user meta

Two user-meta keys gate the surface for individual users:

- `wp_mcp_ai_chat_memory_enabled` (default: `true`) — master toggle for the
  whole bridge for this user. When disabled, every route returns 403.
- `wp_mcp_ai_chat_memory_autosummarize` (default: `false`) — opt-in for
  end-of-conversation transcript summarisation (G6 auto-capture).

Read/written via the `/preferences` endpoint or programmatically:

```php
$prefs = WP_MCP_AI_REST_Chat_Memory_Controller::get_preferences( $user_id );
```

## JavaScript service

`assets/js/chat-memory-service.js` exposes the bridge as a small global:

```js
const memory = window.wpMcpAiChatMemory;

if ( memory.isAvailable() ) {
    memory.wakeUp( { agentId: 42 } ).then( ( res ) => { /* … */ } );
    memory.recall( 'last release', { agentId: 42, wing: 'oss', limit: 10 } );
    memory.store( { agentId: 42, content: 'Ship date is May 30', verbatim: true } );
    memory.update( 'ctx_abc123', { agentId: 42, importance: 'high' } );
    memory.remove( 'ctx_abc123', { agentId: 42 } );
    memory.getPreferences().then( ( prefs ) => { /* … */ } );
    memory.setPreferences( { autosummarize: true } );
}
```

`isAvailable()` returns `false` when the localized config omits the
`memoryEndpoints` block (i.e. guest sessions or kill-switched users); every
operation rejects with `chat_memory_disabled` in that case so callers can
degrade gracefully.

## Session-boot wake-up

`chat.js` calls `requestWakeUpContext()` from its widget initialiser
immediately after restoring conversation history from `localStorage`. The
result is cached on `state.wakeUpSystemBlock` and prepended to the first
outgoing system prompt. Errors are logged at `console.debug` level and never
disrupt the chat.

## Slash commands

Three commands map to the bridge:

| Command                                                      | Effect                                                                 |
|--------------------------------------------------------------|------------------------------------------------------------------------|
| `/remember <text> [--tag=…] [--importance=…] [--wing=…] [--room=…] [--summary]` | Stores the text as a verbatim memory (`store_agent_context`). |
| `/forget <context_id>`                                       | Deletes the memory by id (`manage_context_lifecycle action=delete`).   |
| `/scope [<wing> [<room>]]`                                   | Updates the active wing/room scope chip. Omit args to clear scope.     |

All three require `edit_posts`. They re-use the chat-memory bridge's
kill-switch checks so a disabled site or user receives a friendly error.

## Privacy and compliance

- **Guest sessions never store memories.** The REST controller rejects
  unauthenticated requests with HTTP 403, and the JS service silently no-ops
  when the localized config omits the `memoryEndpoints` block.
- **Capability gates.** Reads require a logged-in user; writes require
  `edit_posts`.
- **Sanitisation.** `content` and `title` are filtered through
  `wp_kses_post()`; `wing`, `room`, `tags`, and `context_type` use the
  appropriate `sanitize_text_field()` / `sanitize_key()` helpers; `context_id`
  is restricted to `[A-Za-z0-9_-]`.
- **Auditability.** The bridge delegates to existing tools, so the Phase-4
  `wp_mcp_ai_memory_audit` action fires exactly as it does for AI-initiated
  writes.
- **i18n.** All strings use `__( …, 'mcp-ai-wpoos' )`; the JS service ships
  with no user-visible strings.

## Memory Drawer UI (Phase 3)

A self-contained side panel ships with the chat surface and auto-attaches
to every initialised chat container the moment the bridge reports as
available. The drawer module lives at
[`assets/js/chat-memory-drawer.js`](../../../assets/js/chat-memory-drawer.js)
and is bundled into `chat-bundle.min.js`. It degrades gracefully — no toggle
button is rendered when the bridge is missing (e.g. on a Base build with no
`recall_memory` tool, or for guests).

### Surface

- **Toggle button** — `🧠` button injected into the chat's
  `.wp-mcp-ai-chat__transcript-controls` row.
- **Side panel** — `role="dialog"`, `aria-modal="false"` (chat remains
  interactive behind it), labelled by an explicit heading. ESC closes the
  drawer and returns focus to the toggle. Tab cycles inside the dialog.
- **Tabs**:
  - **Memories** — paginated list of recent records (calls
    `recall_memory` with the user's filter input and the active scope), with
    inline edit/delete. Empty / error / loading states are announced.
  - **Scope** — wing/room form. Submitting writes
    `state.config.memoryWing` and `state.config.memoryRoom` and re-runs the
    recall.
  - **Audit** — lazy-loads `GET /mcp-ai/v1/chat-memory/audit` for recent
    create/update/delete/access events.
  - **Session Replay** — lazy-loads
    `GET /mcp-ai/v1/chat-memory/sessions/{session_id}` and renders buffered
    chronology for the supplied session ID.
- **ARIA-live toasts** — a singleton `#wp-mcp-ai-memory-toasts` region is
  appended to `<body>` and used to announce memory store/update/delete
  results to assistive tech (`aria-live="polite"`, `role="status"`).
  Respects `prefers-reduced-motion`.

### Public API

The drawer module exposes a small surface on `window` for adapters and tests:

```js
window.wpMcpAiChatMemoryDrawer = {
    attach(container),                       // attach to one container
    attachAll(),                             // scan + attach to all initialised chats
    decorateMessageWithBadge(bubble, calls), // add 🧠 Memory badge when tool calls touched memory
    announceToast(message, variant),         // 'info' | 'success' | 'error'
    ensureToastRegion(),                     // returns the singleton ARIA-live region
    isAvailable(),                           // mirrors window.wpMcpAiChatMemory.isAvailable()
};
```

### In-chat "🧠 Memory" badge (auto-wired)

`appendMessage()` in `chat.js` automatically calls
`wpMcpAiChatMemoryDrawer.decorateMessageWithBadge( entry, payload.tool_calls )`
on every assistant bubble whose `payload.tool_calls` is a non-empty array. The
decorator itself is idempotent and a no-op when no memory-related tool was
invoked, so the only requirement is that `payload.tool_calls` reaches
`appendMessage`:

- **Live agentic-loop path** — `chat.js` copies `message.tool_calls` onto
  `assistantDisplay.tool_calls` before the `appendMessage` call (search for
  `assistantDisplay.tool_calls = message.tool_calls`).
- **Restore-from-storage path** — the existing `assistantPayload.tool_calls
  = display.tool_calls` line preserves the data across reloads.

A regression test (`tests/js/chat-memory-badge-wiring.test.js`) pins both
call sites and the try/catch guard so the wiring can't be silently dropped.

The badge appears whenever a message's `tool_calls` include any of these
memory-touching tools:

- `recall_memory`
- `wake_up_context`
- `semantic_context_search`
- `retrieve_agent_memory`
- `store_agent_context`
- `update_agent_memory`
- `capture_memory`

The decorator is also exposed publicly on `window.wpMcpAiChatMemoryDrawer`
for downstream renderers. It is **idempotent** (skips bubbles already
decorated), accepts both `{ tool: '…' }` and OpenAI-shaped
`{ function: { name: '…' } }` records, and prefers attaching to a
`.wp-mcp-ai-chat__message-header` / `.wp-mcp-ai-chat__message-meta` if
present.

### Accessibility checklist

- `role="dialog"` + `aria-labelledby` on the drawer.
- `role="tablist"` + `aria-selected` on the tab buttons.
- `role="status"` + `aria-live="polite"` on the toast region.
- Focus is moved into the dialog on open; ESC closes and restores focus.
- A simple Tab/Shift-Tab cycle keeps focus inside the dialog.
- All strings use `wp.i18n.__( …, 'mcp-ai-wpoos' )`.
- `prefers-reduced-motion` short-circuits the slide-in and toast fade.



## Roadmap (deferred)

These items from the original gap analysis were intentionally out of scope
for the initial integration to keep the surface area auditable. They are
tracked as follow-up work:

- **G2 Audit tab** inside the drawer — **shipped.** Lazy-loads
  `GET /mcp-ai/v1/chat-memory/audit` (proxy → `memory_audit_trail` tool with
  `action=get_audit_log`) on first activation. Includes an action-type filter
  (`create` / `update` / `delete` / `access`), a Refresh button, and renders
  each event as `[timestamp] action — context_id`. Permission gate is the
  same `permissions_check_logged_in` callback used by `/recall`, so the
  per-user toggle and site-wide kill-switch both apply.
- **G6 Auto-summarise transcript** at conversation close — **shipped.** A
  `pagehide` (+ `visibilitychange→hidden`) handler in the Memory Drawer
  fires a single `storeBeacon()` per page session when both the site-wide
  kill-switch and the per-user `autosummarize` toggle are on. The captured
  payload is the verbatim transcript (truncated to 4 KB, keeping the most
  recent turns) tagged `transcript-summary` / `autosummary` with
  `context_type: 'transcript_summary'`. Reuses `POST /chat-memory/store`
  so the existing permission gates and audit trail apply.

  **Phase 2 — true LLM-side summarisation.** The auto-capture payload now
  sets `summarize: true`, which opts the request into server-side
  summarisation in the `/chat-memory/store` controller. When the OpenAI
  key is configured, the controller calls `gpt-4o-mini` with a terse
  "summarise this transcript" system prompt, replaces the verbatim
  content with the resulting paragraph, appends a `summarized` tag, and
  records `{ original_length, summary_length, model }` in
  `context_data.summary_metadata` so the drawer can display "summarised
  from N bytes". The helper is a graceful enhancement — every failure
  path (no API key, content under {@see SUMMARIZE_MIN_INPUT_BYTES} bytes,
  HTTP error, non-200, malformed JSON, blank summary, `WP_Error` from
  `wp_remote_post`) returns `null` and the verbatim 4 KB capture is
  stored as before, so a user's transcript is never lost. Inputs are
  hard-capped at {@see SUMMARIZE_MAX_INPUT_BYTES} bytes before sending
  to defend against malicious callers running up an unbounded token bill.
- **G8 SSE `memory_event` frames** — **shipped.** Two paths now feed the
  same "🧠 Memory" toast:
  1. **End-of-stream (inline metadata).** `decorateMessageWithBadge` inspects
     `payload.tool_calls` on the assistant bubble and announces a single
     toast — "🧠 Used long-term memory.", "🧠 Saved a memory.", or
     "🧠 Used and saved long-term memory." Idempotent per bubble via
     `data-wp-mcp-ai-memory-toast="1"` so streaming re-decorations never
     double-announce. Used for non-streaming responses.
  2. **Mid-stream (server SSE).** `handle_chat_request_with_streaming` now
     emits a `memory_event` SSE frame (`{ action, tool_name, tool_id }`)
     immediately after each `tool_execution` event whose tool is classified
     by `classify_memory_tool_action()` as a retriever
     (`recall_memory`, `wake_up_context`, `semantic_context_search`,
     `retrieve_agent_memory`) or a writer (`store_agent_context`,
     `update_agent_memory`, `capture_memory`). The chat.js SSE router
     forwards the frame to `wpMcpAiChatMemoryDrawer.handleSseMemoryEvent()`,
     which fires the toast immediately and bumps a small pending counter.
     The end-of-stream decorator drains that counter and skips its own
     toast for the same turn (the badge is still drawn) so users never see
     a duplicate notification.
- **G11 Drawer-driven export** — **shipped.** New "Export" button next to
  Refresh on the Memories tab. Calls `recall()` with the active scope
  (wing/room/query) and a high `limit` (200), wraps the records in a small
  envelope (`exported_at`, `agent_id`, `scope`, `count`, `memories`), and
  triggers a one-shot JSON download. The button is disabled while the request
  is in flight to prevent duplicate downloads. No new REST route required —
  the existing `/recall` endpoint already enforces the kill-switch + per-user
  toggle.
- **G12 Session Replay tab** — **shipped.** Adds
  `GET /mcp-ai/v1/chat-memory/sessions/{session_id}` to the chat-memory REST
  bridge and wires a Session Replay tab in the Memory Drawer. The endpoint is
  read-only, uses the same `permissions_check_logged_in` gate as `/recall`,
  and returns bounded frame chronology from
  `WP_MCP_AI_Chat_Session_Frame_Buffer`.

## Related docs

- [`docs/AGENT-MEMORY-COMPLETE-GUIDE.md`](../../AGENT-MEMORY-COMPLETE-GUIDE.md)
- [`docs/features/memory/transcript-mining.md`](./transcript-mining.md)
- [`.context/chat-ui.md`](../../../.context/chat-ui.md)
- [`docs/rest-api.md`](../../rest-api.md)
