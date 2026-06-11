# NV oOS Chat UI Patterns

> **GSD Context File** — Load this when working on frontend chat interface changes.
> Last reviewed: March 2026.

---

## Chat UI Architecture

The frontend chat widget is implemented in:

```
assets/js/chat.js           # Main chat UI logic
assets/css/chat.css         # Chat UI styles
assets/css/chat-dark.css    # Dark mode styles
```

The chat widget renders in two contexts:
1. **Shortcode:** `[mcp_ai_assistant]` — embedded on any page/post
2. **Elementor Widget:** via the Elementor widget integration in `includes/elementor/`

---

## Key JavaScript Patterns

### jQuery Usage (WordPress Compatible)

```javascript
// Use jQuery passed via wp_localize_script or iife wrapper:
( function( $ ) {
    'use strict';

    // DOM ready:
    $( document ).ready( function() {
        // Code here
    } );

} )( jQuery );
```

### Sending Messages

```javascript
function sendMessage( message, assistantId ) {
    var data = {
        message:      message,
        assistant_id: assistantId,
        nonce:        wpMcpAiChat.nonce,
    };

    $.ajax( {
        url:    wpMcpAiChat.ajaxUrl,
        method: 'POST',
        data:   data,
        success: function( response ) {
            if ( response.success ) {
                appendMessage( 'assistant', response.data.message );
            }
        },
    } );
}
```

### SSE Streaming

```javascript
function startStream( sessionId ) {
    var evtSource = new EventSource(
        wpMcpAiChat.sseUrl + '?session_id=' + sessionId + '&nonce=' + wpMcpAiChat.nonce
    );

    evtSource.onmessage = function( event ) {
        if ( event.data === '[DONE]' ) {
            evtSource.close();
            return;
        }
        var data = JSON.parse( event.data );
        appendChunk( data.content );
    };

    evtSource.onerror = function() {
        evtSource.close();
    };
}
```

---

## localStorage Persistence

Chat transcripts are stored in browser `localStorage` with a 24-hour TTL:

```javascript
// Key format:
var storageKey = 'wp_mcp_ai_transcript_' + assistantId;

// Save:
localStorage.setItem( storageKey, JSON.stringify( {
    messages:   messages,
    timestamp:  Date.now(),
} ) );

// Load (check TTL):
var stored = localStorage.getItem( storageKey );
if ( stored ) {
    var data = JSON.parse( stored );
    var age  = Date.now() - data.timestamp;
    if ( age < 24 * 60 * 60 * 1000 ) {
        // Use data.messages
    } else {
        localStorage.removeItem( storageKey );
    }
}
```

---

## Guest Token Handling

For public chat surfaces (no user login):

```javascript
// Guest token is passed via localized data:
var guestToken = wpMcpAiChat.guestToken;

// Include in requests:
headers: {
    'X-WP-MCP-AI-Guest': guestToken,
}
```

---

## Localized Data (PHP Side)

```php
wp_localize_script(
    'wp-mcp-ai-chat',
    'wpMcpAiChat',
    array(
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'sseUrl'       => rest_url( 'mcp-ai/v1/sse' ),
        'nonce'        => wp_create_nonce( 'wp_mcp_ai_chat' ),
        'assistantId'  => absint( $assistant_id ),
        'guestToken'   => $guest_token, // null if not enabled
        'i18n'         => array(
            'sending'  => esc_html__( 'Sending...', 'mcp-ai-wpoos' ),
            'error'    => esc_html__( 'Error sending message.', 'mcp-ai-wpoos' ),
        ),
    )
);
```

---

## DOM Sanitization (Security)

Always sanitize before inserting user content into the DOM:

```javascript
// Use text() not html() for user messages:
$messageEl.text( userMessage );     // ✅ Safe
$messageEl.html( userMessage );     // ❌ XSS risk

// For assistant messages (Markdown rendered):
// Use the nvoos-markdown package which sanitizes output
```

---

## Markdown Rendering

The `packages/nvoos-markdown` package handles Markdown rendering:

```javascript
import { renderMarkdown } from '@nvoos/nvoos-markdown';

// Render and insert (sanitized):
var rendered = renderMarkdown( assistantResponse );
$messageEl.html( rendered );  // Safe — nvoos-markdown sanitizes
```

---

## Translation (i18n)

```javascript
// Use wp.i18n for translatable strings:
var { __ } = wp.i18n;
var label = __( 'Send message', 'mcp-ai-wpoos' );
```

---

## Testing Chat UI Changes

1. Test in both shortcode and Elementor widget contexts
2. Verify `localStorage` persistence works (messages persist on page reload, clear after 24h)
3. Test guest token functionality (public page, no login)
4. Test SSE streaming (verify chunks arrive and render correctly)
5. Test with JavaScript disabled (graceful degradation)
6. ESLint must pass: `npm run lint:js`

---

## Chat ⇄ Memory bridge (since 1.6.0)

The chat client talks to the agent-memory subsystem through a small REST proxy
mounted at `/wp-json/mcp-ai/v1/chat-memory/*` and a global JS service:

- `window.wpMcpAiChatMemory` (`assets/js/chat-memory-service.js`) — `wakeUp`,
  `recall`, `store`, `update`, `remove`, `getPreferences`, `setPreferences`.
- `requestWakeUpContext(state)` (`chat.js`) is called once per widget init
  immediately after `restoreConversationFromStorage`, prepending a wake-up
  system block to the first turn.
- Slash commands `/remember`, `/forget`, `/scope` (see
  `includes/slash-commands/commands/class-wp-mcp-ai-slash-command-memory.php`).
- Per-user toggles: `wp_mcp_ai_chat_memory_enabled`,
  `wp_mcp_ai_chat_memory_autosummarize` (user meta).
- Site-wide kill-switch: `wp_mcp_ai_chat_memory_enabled` filter.

Endpoints are localized into `wpMcpAiChat.memoryEndpoints` by
`WP_MCP_AI_Shortcode::get_chat_memory_endpoints_inline_script()` and the JS
service silently no-ops (rejects with `chat_memory_disabled`) when they are
absent — guests therefore never call the bridge.

### Memory Drawer (Phase 3)

The chat surface ships with a side-panel UI module
(`assets/js/chat-memory-drawer.js`, bundled into `chat-bundle.min.js`) that
auto-attaches to every initialised chat container the moment
`window.wpMcpAiChatMemory.isAvailable()` returns `true`. It exposes:

```js
window.wpMcpAiChatMemoryDrawer = {
    attach(container), attachAll(),
    decorateMessageWithBadge(bubble, toolCalls), // 🧠 badge, idempotent
    announceToast(message, variant),             // 'info' | 'success' | 'error'
    ensureToastRegion(), isAvailable(),
};
```

The drawer is `role="dialog"` + `aria-modal="false"`, ESC-dismissable, and
uses a singleton `#wp-mcp-ai-memory-toasts` ARIA-live region. The chat
container needs `position: relative`; the CSS rule for that lives at the
bottom of `assets/css/chat.css`.

See `docs/features/memory/chat-client-integration.md` for the full reference.

---

## Jobs / Tasks Drawer (cron-status + async-tool jobs)

Long-running tools queue an async job; the chat surface shows progress inline
and in a side drawer. Three JS entry points live in `assets/js/chat.js`:

| Function | Role |
|----------|------|
| `createJobProgressCard( entry, jobId, toolName, state )` | Replaces the plain "Tool is processing…" line with a BEM card (`.wp-mcp-ai-job-card__*` — progress bar, ETA, step list, Cancel / Retry buttons). Feature-gated via `state.config.inlineJobCard === false`. Wired into both `waitForAsyncToolResultSSE` and `waitForAsyncToolResultPolling`. |
| `initTasksDrawer( container, config, cronStatusEndpoint, nonce )` | Activates the right-side drawer when `config.chatTasksDrawer === true`. Persists jobs to `localStorage` under `wp_mcp_ai_tasks_{assistantId}` (max 200 entries; oldest terminal jobs pruned first). |
| `showJobToast( container, type, job )` + `updateTabTitleBadge( delta )` | 6 s auto-dismiss toast on `job:completed` / `job:failed`. `(N)` tab-title prefix while N jobs are running. |

All three subscribe to `window.wpMcpAiJobBus` events:
`job:started`, `job:step`, `job:progress`, `job:completed`, `job:failed`,
`job:cancelled`.

PHP surface:

- The `wp_mcp_ai_chat_tasks_drawer` filter (default **true**) gates whether
  the drawer HTML + JS init code is emitted by the shortcode.
- The REST cancel / retry routes are documented in
  [`.context/rest-api.md`](rest-api.md).
- Five `do_action` hooks at the cron-status REST call sites
  (`wp_mcp_ai_chat_jobs_snapshot`, `wp_mcp_ai_before_chat_jobs_stream`,
  `wp_mcp_ai_after_chat_jobs_stream`, `wp_mcp_ai_chat_jobs_cancel`,
  `wp_mcp_ai_chat_jobs_retry`) are picked up by
  `WP_MCP_AI_Otel_Span_Exporter` and emitted as `nvoos.chat.jobs.*` OTLP
  spans.

CSS lives in:

- `assets/css/chat.css` — `.wp-mcp-ai-job-card`, `.wp-mcp-ai-chat__tasks-btn`,
  `.wp-mcp-ai-chat__tasks-drawer__*`, `.wp-mcp-ai-job-toast`.

References:

- [`docs/features/chat/cron-status-integration.md`](../docs/features/chat/cron-status-integration.md) — architecture, SSE event schema, OTel hooks, REST routes.
- [`docs/features/chat/async-continuation.md`](../docs/features/chat/async-continuation.md) — async chat continuation slices 1–6 (durable store, dispatcher, LLM re-entry, SSE channel, Pro webhook notifier, OTel + Jest).
- [`docs/features/chat/cron-status-tasks-drawer-plan.md`](../docs/features/chat/cron-status-tasks-drawer-plan.md) — Tasks Drawer PR-by-PR plan (A–G).
- [`docs/developer/tool-development/registering-a-job-source.md`](../docs/developer/tool-development/registering-a-job-source.md) — 5-step developer guide for new long-running tools that want to appear in the drawer.

