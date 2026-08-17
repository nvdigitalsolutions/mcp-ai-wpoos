# [nvoos_content_graph_chat] Shortcode — Design Plan

> **Date:** 2026-08-03
> **Status:** Planning
> **Aligns with:** Pro SPA-v2 (`addons/pro/assets/spa-v2/`) — same REST contract, same SSE protocol

---

## Design Principle: Aligned, Not Copied

The shortcode follows the **same architectural contract** as SPA-v2 (REST endpoint, SSE frames, message shapes, error envelopes) but uses a **different technical implementation** suitable for a lightweight addon:

| Concern | Pro SPA-v2 | `[nvoos_content_graph_chat]` |
|---|---|---|
| Framework | React 19 + TypeScript + esbuild | Vanilla JS (no build step) |
| Bundle size | ~800KB gzipped | ~8KB gzipped |
| Dependencies | react, react-dom, zustand, @ai-sdk/react, react-router, marked, dompurify | None (uses browser APIs) |
| Routing | HashRouter with lazy code splitting | N/A — single widget |
| State | Zustand stores (ui, model, command) | Simple object in closure |
| Transcripts | Server-side via REST + localStorage | sessionStorage only (per-tab, ephemeral) |
| Threads | Server-side CRUD | N/A |
| Agents | Assistant selector with modal CRUD | N/A (Platform addon feature) |
| Command palette | Ctrl+K fuzzy search overlay | N/A |
| HITL/Memory/Media | Full API clients | N/A (Pro features) |
| **SSE adapter** | **Identical protocol** | **Identical protocol** |
| **REST contract** | **Identical** | **Identical** |
| **Message format** | **Identical** | **Identical** |
| **Error shapes** | **Identical** | **Identical** |

---

## Shared Contract (Alignment Points)

### REST Endpoint

```
POST /wp-json/nvoos-content-graph/v1/ai/chat
```

**Request:**
```json
{
  "messages": [
    { "role": "user", "content": "What posts link to my About page?" }
  ],
  "provider": "openai",
  "stream": true
}
```

**Response (non-streaming):**
```json
{
  "success": true,
  "data": { "content": "..." },
  "tool_results": [
    { "name": "nvoos_content_graph_query_graph", "result": { ... }, "cost": 0.002 }
  ],
  "iterations": 2,
  "cost": { "total": 0.004, "currency": "USD" }
}
```

**SSE stream format (same as SPA-v2 NvOosFrame):**
```
type:delta
data:{"type":"text","content":"The"}

type:delta
data:{"type":"text","content":" About"}

type:tool_start
data:{"id":"call_1","name":"nvoos_content_graph_query_graph"}

type:tool_result
data:{"id":"call_1","result":{...}}

type:done
data:{"cost":{...}}
```

### Message Shapes

| Role | Shape | Shared? |
|---|---|---|
| `user` | `{ role: "user", content: string }` | ✅ Identical |
| `assistant` | `{ role: "assistant", content: string, tool_calls?: [...] }` | ✅ Identical |
| `tool` | `{ role: "tool", tool_call_id: string, content: string }` | ✅ Identical |
| `system` | `{ role: "system", content: string }` | ✅ Identical |

### Error Shapes

```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do that.",
  "data": { "status": 403 }
}
```

Both SPA-v2 and the shortcode handle this identically — extract `message`, display in error banner.

---

## Shortcode Design

### PHP — `src/Frontend/ChatShortcode.php`

```php
namespace NvoosContentGraphAi\Frontend;

class ChatShortcode {
    public function register(): void {
        add_shortcode( 'nvoos_content_graph_chat', array( $this, 'render' ) );
    }

    public function render( array $atts ): string {
        $atts = shortcode_atts( array(
            'provider' => '',        // Force a specific provider
            'height'   => '500px',   // Chat container height
            'show_cost' => '1',      // Show cost badge
            'placeholder' => '',     // Custom placeholder text
        ), $atts );

        $containerId = 'nvoos-content-graph-chat-' . wp_unique_id();

        $this->enqueueAssets();

        wp_add_inline_script(
            'nvoos-content-graph-ai-chat-frontend',
            'window.NvoosContentGraphChat_' . $containerId . ' = ' . wp_json_encode( array(
                'container'    => $containerId,
                'restUrl'      => rest_url( 'nvoos-content-graph/v1' ),
                'nonce'        => wp_create_nonce( 'wp_rest' ),
                'provider'     => $atts['provider'],
                'showCost'     => (bool) $atts['show_cost'],
                'placeholder'  => $atts['placeholder'] ?: __( 'Ask about your knowledge graph…', 'nvoos-content-graph-ai' ),
                'isAdmin'      => false,
                'i18n'         => array(
                    'send'         => __( 'Send', 'nvoos-content-graph-ai' ),
                    'thinking'     => __( 'Thinking…', 'nvoos-content-graph-ai' ),
                    'error'        => __( 'Something went wrong.', 'nvoos-content-graph-ai' ),
                    'toolsUsed'    => __( 'Tools used', 'nvoos-content-graph-ai' ),
                    'cost'         => __( 'Cost', 'nvoos-content-graph-ai' ),
                    'clear'        => __( 'Clear', 'nvoos-content-graph-ai' ),
                    'graphQuery'   => __( 'Queried your knowledge graph', 'nvoos-content-graph-ai' ),
                    'noProvider'   => __( 'No AI provider configured. Set an API key in NV Content Graph → AI Providers.', 'nvoos-content-graph-ai' ),
                ),
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ';',
            'before'
        );

        return sprintf(
            '<div id="%s" class="nvoos-content-graph-chat-widget" style="height:%s"></div>',
            esc_attr( $containerId ),
            esc_attr( $atts['height'] )
        );
    }

    private function enqueueAssets(): void {
        wp_enqueue_style(
            'nvoos-content-graph-ai-chat-frontend',
            NVOOS_CONTENT_GRAPH_AI_URL . 'assets/css/content-graph-ai-chat.css',
            array(),
            NVOOS_CONTENT_GRAPH_AI_VERSION
        );

        wp_enqueue_script(
            'nvoos-content-graph-ai-chat-frontend',
            NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/content-graph-ai-chat-frontend.js',
            array(),
            NVOOS_CONTENT_GRAPH_AI_VERSION,
            true
        );
    }
}
```

### Registration in Plugin.php

```php
// In registerFrontend() or a new method:
if ( class_exists( 'NvoosContentGraphAi\Frontend\ChatShortcode' ) ) {
    ( new \NvoosContentGraphAi\Frontend\ChatShortcode() )->register();
}
```

---

### JavaScript — `assets/js/content-graph-ai-chat-frontend.js`

**~250 lines, vanilla JS, no dependencies.**

```
content-graph-ai-chat-frontend.js
├── ChatWidget(config)           ← Constructor — builds DOM, wires events
│   ├── this.messages = []       ← Message history (sessionStorage-backed)
│   ├── this.container           ← DOM root
│   ├── this.messageList         ← Scrollable message area
│   ├── this.inputArea           ← Textarea + send button
│   ├── this.toolbar             ← Provider selector + cost badge + clear
│   └── this.provider            ← Active provider slug
│
├── ChatWidget.prototype.init()  ← Builds DOM, restores history, binds events
├── .buildDOM()                  ← Creates HTML structure (same as ChatInterface)
├── .restoreHistory()            ← Loads messages from sessionStorage
├── .saveHistory()               ← Saves messages to sessionStorage
│
├── .sendMessage()               ← Gets input text, adds user message, calls API
├── .addMessage(role, content)   ← Creates message bubble DOM element
├── .addToolCard(toolResult)     ← Renders tool call result inline
│
├── .callChatAPI(messages)       ← POST /ai/chat with stream=true
│   └── SSE PARSING (IDENTICAL PROTOCOL TO SPA-v2)
│       ├── "type:delta"         → progressive text (typing animation)
│       ├── "type:tool_start"    → show tool indicator
│       ├── "type:tool_result"   → render tool card
│       ├── "type:done"          → finalize message, show cost
│       └── "type:error"         → show error banner
│
├── .handleSSELine(line)         ← Parses SSE data: prefix
├── .handleSSEData(data)         ← Routes by data.type to handlers
├── .handleDelta(data)           ← Appends to streaming message bubble
├── .handleToolStart(data)       ← Shows "Queried knowledge graph…" indicator
├── .handleToolResult(data)      ← Replaces indicator with tool card
├── .handleDone(data)            ← Stops streaming, updates cost badge
├── .handleError(data)           ← Shows error in message list
│
├── .scrollToBottom()            ← Auto-scroll during streaming
├── .clearChat()                 ← Clears messages + sessionStorage
└── .showError(message)          ← Shows error banner
```

### SSE Parsing — Identical to SPA-v2

The SSE parser is the **critical alignment point**. Both SPA-v2 and the shortcode must handle the same frame types:

```javascript
// SPA-v2 sse-adapter.ts:61  →  shortcode equivalent
function parseSSELine(line) {
    if (!line.startsWith('data:')) return null;
    const json = line.slice(5).trim();
    if (json === '[DONE]') return { type: 'done' };
    try { return JSON.parse(json); } catch { return null; }
}

function handleFrame(frame) {
    switch (frame.type) {
        case 'text':     // delta content — append to streaming bubble
        case 'delta':    // alternative delta format (SPA-v2 also handles this)
        case 'tool_start':  // "Queried graph: nvoos_content_graph_query_graph"
        case 'tool_result': // Collapse indicator → show result card
        case 'done':     // Stop streaming indicator, show cost
        case 'error':    // Show error message
        default:         // Ignore unknown types (forward-compat)
    }
}
```

### Tool Card Rendering

When a graph tool executes, show a compact card showing what was queried:

```
┌──────────────────────────────────────────┐
│ 🔍 Queried your knowledge graph          │
│    Tool: nvoos_content_graph_query_graph      │
│    Result: Found 5 nodes, 12 edges       │
│    Cost: $0.002                          │
└──────────────────────────────────────────┘
```

This uses the `tool_results` array from the chat response — identical to how SPA-v2 consumes `tool_results` in `sse-adapter.ts:75-80`.

---

## File Plan

| File | Action | Lines |
|---|---|---|
| `plugins/nvoos-content-graph-ai/src/Frontend/ChatShortcode.php` | **Create** | ~100 |
| `plugins/nvoos-content-graph-ai/assets/js/content-graph-ai-chat-frontend.js` | **Create** | ~250 |
| `plugins/nvoos-content-graph-ai/assets/css/content-graph-ai-chat.css` | **Update** (add frontend styles) | ~50 added |
| `plugins/nvoos-content-graph-ai/src/Plugin.php` | **Edit** — wire `ChatShortcode` in `registerFrontend()` | ~5 |

### Refactor opportunity

The admin `ChatInterface.php` and the new `ChatShortcode.php` share identical chat markup and SSE logic. The admin tester can be refactored to **delegate rendering** to a shared helper, avoiding duplication:

```
src/Frontend/ChatShortcode.php        ← [nvoos_content_graph_chat] shortcode
src/Admin/Sections/ChatInterface.php  ← Admin "Chat Tester" tab
    ↓ both call
assets/js/content-graph-ai-chat.js         ← Single JS file (already exists)
    ↓ handles
SSE parsing + DOM rendering + sessionStorage
```

The existing `content-graph-ai-chat.js` already handles SSE — it just needs:
1. A frontend entry point (check `window.NvoosContentGraphAiChat.isAdmin`)
2. sessionStorage persistence (currently only in-memory)
3. Tool card rendering (currently bare JSON)

---

## What Aligns with SPA-v2 (Contract)

| Contract Element | Alignment |
|---|---|
| POST `/nvoos-content-graph/v1/ai/chat` | ✅ Same endpoint, same request shape |
| SSE frame types (`delta`, `tool_start`, `tool_result`, `done`, `error`) | ✅ Identical protocol — same parser handles both |
| Message format (`{role, content}`) | ✅ Identical |
| Error envelope (`{code, message, data}`) | ✅ Identical |
| `tool_results` array in response | ✅ Identical consumption |
| `cost` object in response | ✅ Identical display |
| `provider` parameter | ✅ Same semantics |
| WordPress nonce auth | ✅ Both use `X-WP-Nonce` header |

## What Stays Different (Scope)

| Feature | SPA-v2 | Shortcode | Reason |
|---|---|---|---|
| Conversations/transcripts | Server-side + localStorage | sessionStorage only | Transcripts need Pro infrastructure |
| Threads | Full CRUD | N/A | Pro feature |
| Agent/assistant selector | Modal CRUD | N/A | Agents live in Platform addon |
| Model selector | Per-provider model list | Provider only (model = default) | No model catalog API in AI addon |
| Command palette | Ctrl+K overlay | N/A | Pro UX feature |
| Memory preferences | Per-user toggles | N/A | Pro memory subsystem |
| HITL approvals | Approval queue | N/A | Pro workflow feature |
| Markdown rendering | marked library | Plain text (or opt-in marked) | Keep bundle small |
| Multi-instance | Multiple chat widgets on one page | Same — containerId is unique | ✅ Same pattern |

---

## Implementation Order

### Step 1: Refactor existing admin chat JS to be context-aware
- `content-graph-ai-chat.js` detects `isAdmin` vs `isFrontend` from config
- Adds sessionStorage persistence
- Adds tool card rendering

### Step 2: Create `ChatShortcode.php`
- Registers `[nvoos_content_graph_chat]`
- Enqueues same JS/CSS as admin
- Passes frontend-specific config

### Step 3: Wire into Plugin.php
- `registerFrontend()` calls `ChatShortcode::register()`

### Step 4: Update CSS for frontend context
- Ensure chat widget works inside post content (width constraints, theme compatibility)

---

## Estimated Effort

| Step | Effort |
|---|---|
| Refactor admin JS | 2 hours |
| Create ChatShortcode.php | 1 hour |
| Wire into Plugin.php | 15 min |
| CSS updates | 30 min |
| **Total** | **~4 hours** |
