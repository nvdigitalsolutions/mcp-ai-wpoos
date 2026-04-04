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
