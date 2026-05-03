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
```

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

## Per-user toggles

Two user-meta keys gate the surface for end users:

- `wp_mcp_ai_chat_memory_enabled` (default: `true`) — master toggle for the
  whole bridge. When disabled, every route returns 403.
- `wp_mcp_ai_chat_memory_autosummarize` (default: `false`) — opt-in for
  end-of-conversation transcript summarisation (Phase 6, see roadmap).

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
- **G6 Auto-summarise transcript** at conversation close — **partial (Phase 1
  shipped).** A `pagehide` (+ `visibilitychange→hidden`) handler in the
  Memory Drawer fires a single `storeBeacon()` per page session when both
  the site-wide kill-switch and the per-user `autosummarize` toggle are on.
  The captured payload is the verbatim transcript (truncated to 4 KB,
  keeping the most recent turns) tagged `transcript-summary` /
  `autosummary` with `context_type: 'transcript_summary'`. Reuses
  `POST /chat-memory/store` so the existing permission gates and audit
  trail apply. **Phase 2** (true LLM-side summarisation instead of
  verbatim capture) is still deferred.
- **G8 SSE `memory_event` frames** from the agentic loop — would replace
  client-side polling for the badge and toast.
- **G11 Drawer-driven export** — **shipped.** New "Export" button next to
  Refresh on the Memories tab. Calls `recall()` with the active scope
  (wing/room/query) and a high `limit` (200), wraps the records in a small
  envelope (`exported_at`, `agent_id`, `scope`, `count`, `memories`), and
  triggers a one-shot JSON download. The button is disabled while the request
  is in flight to prevent duplicate downloads. No new REST route required —
  the existing `/recall` endpoint already enforces the kill-switch + per-user
  toggle.

## Related docs

- [`docs/AGENT-MEMORY-COMPLETE-GUIDE.md`](../../AGENT-MEMORY-COMPLETE-GUIDE.md)
- [`.context/chat-ui.md`](../../../.context/chat-ui.md)
- [`docs/rest-api.md`](../../rest-api.md)
