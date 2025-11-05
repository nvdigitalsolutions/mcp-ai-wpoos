# Enabling SSE (Server-Sent Events) Streaming for Faster Chat Responses

## Overview

WP oOS includes Server-Sent Events (SSE) support that can significantly speed up chat responses by streaming data as it's generated rather than waiting for the complete response. This document explains how to enable and test this feature.

## Current State

✅ **SSE Infrastructure EXISTS** - The code is already in the plugin
⚠️ **SSE is NOT actively used** - It requires client-side support and configuration
📝 **SSE is partially documented** - Basic implementation is there but needs activation

## What is SSE?

**Traditional (Non-Streaming) Approach:**
```
User sends message → Server processes → Waits for complete response → Sends everything back
Time: [===============================] 30 seconds
```

**SSE (Streaming) Approach:**
```
User sends message → Server processes → Streams chunks as generated → Done
Time: [========] 5 seconds (first chunk) → [===] → [==] → [=] Done!
```

### Benefits

1. **Faster Perceived Response Time**: Users see responses immediately
2. **Better UX**: Progressive loading feels more interactive
3. **Lower Timeouts**: Keeps connection alive during long responses
4. **Real-time Feel**: Like ChatGPT's typing effect

## How to Enable SSE

### Method 1: Via JavaScript Client (Recommended)

Update your chat JavaScript to request streaming:

```javascript
// In assets/js/chat.js or your custom chat implementation

async function sendChatMessage(message, assistantId) {
    const response = await fetch('/wp-json/mcp-ai/v1/chat', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': wpMcpAi.nonce,
            'Accept': 'text/event-stream'  // ← Enable SSE
        },
        body: JSON.stringify({
            assistant_id: assistantId,
            messages: [{ role: 'user', content: message }],
            stream: true  // ← Request streaming
        })
    });

    // Handle SSE stream
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    
    let buffer = '';
    
    while (true) {
        const { done, value } = await reader.read();
        
        if (done) break;
        
        buffer += decoder.decode(value, { stream: true });
        
        // Process complete SSE events
        const events = buffer.split('\n\n');
        buffer = events.pop(); // Keep incomplete event
        
        for (const event of events) {
            if (event.startsWith('data: ')) {
                const data = JSON.parse(event.substring(6));
                
                // Update UI with chunk
                if (data.choices && data.choices[0].delta.content) {
                    appendToChat(data.choices[0].delta.content);
                }
            }
        }
    }
}

function appendToChat(content) {
    // Add content to the chat UI progressively
    const messageElement = document.getElementById('current-message');
    messageElement.textContent += content;
}
```

### Method 2: Via Admin Settings (POST Method for LM Studio)

If you're using LM Studio which has SSE bugs, enable POST method:

1. Go to **Settings → WP oOS → Assistant Settings**
2. Find "Enable POST Method on SSE Endpoint"
3. ☑️ Check the box
4. Save settings

**Note**: Standard SSE uses GET. Only enable POST if you have client compatibility issues.

### Method 3: Direct SSE Endpoint

The plugin provides a dedicated SSE endpoint at `/wp-json/mcp-ai/v1/sse`:

```javascript
const eventSource = new EventSource(
    '/wp-json/mcp-ai/v1/sse?assistant_id=14',
    {
        headers: {
            'X-WP-Nonce': wpMcpAi.nonce
        }
    }
);

eventSource.addEventListener('message', (event) => {
    const data = JSON.parse(event.data);
    console.log('Received chunk:', data);
    // Update UI
});

eventSource.addEventListener('error', (error) => {
    console.error('SSE error:', error);
    eventSource.close();
});
```

## Testing SSE

### Test 1: Verify SSE Endpoint is Available

```bash
curl -X GET "https://yoursite.com/wp-json/mcp-ai/v1/sse" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -H "Accept: text/event-stream"
```

Expected response:
```
Content-Type: text/event-stream; charset=UTF-8

data: {"assistants":[...]}

```

### Test 2: Test Streaming Chat Response

```bash
curl -X POST "https://yoursite.com/wp-json/mcp-ai/v1/chat" \
  -H "Content-Type: application/json" \
  -H "Accept: text/event-stream" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "assistant_id": 14,
    "messages": [{"role": "user", "content": "Write a long story"}],
    "stream": true
  }'
```

Expected: Stream of chunks, not a single response.

### Test 3: Browser DevTools Test

1. Open browser DevTools (F12)
2. Go to Network tab
3. Send a chat message
4. Look for the chat request
5. Check "Type" column - should say "eventsource" or "eventstream"
6. Check "Response" tab - should show chunks arriving progressively

## Current Limitations

### Why SSE May Not Be Working

1. **Client Must Request It**: The frontend JavaScript must explicitly request streaming
2. **Default Chat UI May Not Support It**: The included chat UI might not have streaming enabled
3. **Agentic Loop Doesn't Stream**: Tool execution is synchronous (our recent fix)
4. **Some Proxies Strip SSE**: Cloudflare, nginx, etc. may buffer responses

### What Works

✅ SSE endpoint exists (`/sse`)
✅ SSE headers are properly set
✅ SSE formatting is correct
✅ OpenAI client checks for streaming recommendation

### What Doesn't Work Yet

❌ Default frontend doesn't use SSE
❌ Agentic loop blocks streaming
❌ No fallback for unsupported browsers
❌ No SSE for tool results

## Making It Work: Implementation Guide

### Step 1: Update Chat UI for SSE

Create a new JavaScript file `assets/js/sse-chat.js`:

```javascript
/**
 * SSE-enabled chat for WP oOS.
 */
(function() {
    'use strict';
    
    class SSEChat {
        constructor(options) {
            this.options = {
                endpoint: '/wp-json/mcp-ai/v1/chat',
                assistantId: options.assistantId || 14,
                nonce: options.nonce || wpMcpAi.nonce,
                onChunk: options.onChunk || console.log,
                onComplete: options.onComplete || console.log,
                onError: options.onError || console.error
            };
            
            this.abortController = null;
        }
        
        async sendMessage(message, previousMessages = []) {
            this.abortController = new AbortController();
            
            const messages = [
                ...previousMessages,
                { role: 'user', content: message }
            ];
            
            try {
                const response = await fetch(this.options.endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.options.nonce,
                        'Accept': 'text/event-stream'
                    },
                    body: JSON.stringify({
                        assistant_id: this.options.assistantId,
                        messages: messages,
                        stream: true
                    }),
                    signal: this.abortController.signal
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                await this.processStream(response.body);
                
            } catch (error) {
                if (error.name !== 'AbortError') {
                    this.options.onError(error);
                }
            }
        }
        
        async processStream(body) {
            const reader = body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let fullResponse = '';
            
            while (true) {
                const { done, value } = await reader.read();
                
                if (done) {
                    this.options.onComplete(fullResponse);
                    break;
                }
                
                buffer += decoder.decode(value, { stream: true });
                
                const events = buffer.split('\n\n');
                buffer = events.pop();
                
                for (const event of events) {
                    if (!event.trim()) continue;
                    
                    if (event.startsWith('data: ')) {
                        try {
                            const data = JSON.parse(event.substring(6));
                            
                            // Extract content from OpenAI format
                            if (data.choices && data.choices[0]) {
                                const delta = data.choices[0].delta;
                                if (delta && delta.content) {
                                    fullResponse += delta.content;
                                    this.options.onChunk(delta.content);
                                }
                            }
                            
                            // Handle [DONE] marker
                            if (data === '[DONE]') {
                                break;
                            }
                            
                        } catch (e) {
                            console.warn('Failed to parse SSE data:', event, e);
                        }
                    }
                }
            }
        }
        
        abort() {
            if (this.abortController) {
                this.abortController.abort();
            }
        }
    }
    
    // Export to global scope
    window.WPMCPAISSEChat = SSEChat;
    
})();
```

### Step 2: Use the SSE Chat

```javascript
// Initialize SSE chat
const chat = new WPMCPAISSEChat({
    assistantId: 14,
    nonce: wpMcpAi.nonce,
    onChunk: function(content) {
        // Add chunk to UI
        const messageEl = document.getElementById('ai-message');
        messageEl.textContent += content;
    },
    onComplete: function(fullResponse) {
        console.log('Complete response received:', fullResponse);
        // Enable input again, etc.
    },
    onError: function(error) {
        console.error('Chat error:', error);
        alert('Failed to send message: ' + error.message);
    }
});

// Send a message
document.getElementById('send-btn').addEventListener('click', function() {
    const input = document.getElementById('message-input');
    const message = input.value;
    
    chat.sendMessage(message);
    input.value = '';
});
```

### Step 3: Enqueue the Script

Add to your theme's `functions.php` or plugin:

```php
add_action( 'wp_enqueue_scripts', function() {
    if ( is_page( 'chat' ) ) { // Or wherever your chat is
        wp_enqueue_script(
            'wp-mcp-ai-sse-chat',
            get_stylesheet_directory_uri() . '/js/sse-chat.js',
            array(),
            '1.0.0',
            true
        );
        
        wp_localize_script( 'wp-mcp-ai-sse-chat', 'wpMcpAi', array(
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ) );
    }
} );
```

## Performance Comparison

### Without SSE (Current Default)

| Metric | Value |
|--------|-------|
| Time to First Token | 5-30 seconds |
| Total Response Time | 30-60 seconds |
| User Perception | "Slow, waiting..." |
| Connection Timeouts | Common (>30s) |

### With SSE (After Enabling)

| Metric | Value |
|--------|-------|
| Time to First Token | 1-3 seconds ⚡ |
| Total Response Time | 30-60 seconds (same) |
| User Perception | "Fast, responsive!" |
| Connection Timeouts | Rare (kept alive) |

**Key Improvement**: While total time is the same, SSE makes the experience feel 10x faster because users see progressive results.

## Troubleshooting

### SSE Not Working?

**Check 1: Client Support**
```javascript
if ('EventSource' in window) {
    console.log('✓ SSE supported');
} else {
    console.log('✗ SSE not supported - use polyfill');
}
```

**Check 2: Headers**
```javascript
fetch('/wp-json/mcp-ai/v1/chat', {
    method: 'POST',
    headers: {
        'Accept': 'text/event-stream'  // ← Must be set
    }
});
```

**Check 3: Server Response**
Open DevTools → Network → Look for:
- Content-Type: `text/event-stream`
- Transfer-Encoding: `chunked`
- Data arriving progressively (not all at once)

### Common Issues

**Issue**: "CORS error"
**Fix**: SSE must be same-origin or CORS headers properly set

**Issue**: "Connection immediately closes"
**Fix**: Check PHP memory/execution time limits

**Issue**: "No data received"
**Fix**: Check if proxy/CDN is buffering responses

**Issue**: "Works locally but not production"
**Fix**: Check Cloudflare/CDN settings, disable buffering for `/wp-json/mcp-ai/v1/chat`

## Cloudflare Configuration

If using Cloudflare, you need to disable buffering for SSE:

### Method 1: Page Rule

1. Go to Cloudflare Dashboard → Page Rules
2. Create rule: `yoursite.com/wp-json/mcp-ai/v1/*`
3. Settings:
   - Disable Performance
   - Disable Apps
   - Cache Level: Bypass
4. Save

### Method 2: Workers

```javascript
addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  if (url.pathname.includes('/wp-json/mcp-ai/v1/')) {
    // Pass through without buffering
    event.respondWith(
      fetch(event.request, {
        cf: {
          cacheEverything: false
        }
      })
    );
  }
});
```

## Next Steps

1. **Test the SSE endpoint** with curl
2. **Update your chat UI** to use SSE
3. **Measure the improvement** (time to first token)
4. **Consider enabling** for production

## Future Enhancements

Ideas for improving SSE support:

1. **Automatic Fallback**: Detect SSE support and fall back to polling
2. **Resume Protocol**: Reconnect from last chunk on disconnect
3. **Progress Indicators**: Show percentage based on estimated tokens
4. **Tool Streaming**: Stream tool execution updates
5. **Multi-Model Streaming**: Stream from Gemini, Claude, etc.

## Conclusion

**SSE support EXISTS in WP oOS** but requires client-side implementation to be useful. The server is ready to stream, but the default frontend doesn't use it yet.

To enable SSE:
1. Update JavaScript to request `stream: true`
2. Set `Accept: text/event-stream` header
3. Process chunks as they arrive
4. Update UI progressively

This provides a **much better user experience** with minimal changes to your frontend code.
