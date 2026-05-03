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

## Roadmap (deferred)

These items from the original gap analysis were intentionally out of scope for
the initial integration to keep the surface area auditable. They are tracked
as follow-up work:

- **G2 Memory Drawer UI** — full panel with Memories / Audit / Scope tabs.
  The REST surface and JS service are already in place to power it.
- **G3 In-chat "memory updated" toast and "memory in use" badge** — the
  detection helper (`isMemoryRetrievalResult`) is shipped on the JS service,
  but the badge is not yet wired into the message renderer.
- **G6 Auto-summarise transcript** at conversation close — the
  `wp_mcp_ai_chat_memory_autosummarize` user-meta toggle exists; the
  end-of-session capture flow itself is deferred.
- **G8 SSE `memory_event` frames** from the agentic loop — would replace
  client-side polling for the badge and toast.
- **G11 Drawer-driven export** — the data is already exposed by the recall
  route; the UI affordance is deferred.

## Related docs

- [`docs/AGENT-MEMORY-COMPLETE-GUIDE.md`](../../AGENT-MEMORY-COMPLETE-GUIDE.md)
- [`.context/chat-ui.md`](../../../.context/chat-ui.md)
- [`docs/rest-api.md`](../../rest-api.md)
