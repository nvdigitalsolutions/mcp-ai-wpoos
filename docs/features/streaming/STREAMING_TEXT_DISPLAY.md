# Streaming Text Display Feature

## Overview

The chat client UI now displays text **progressively as it streams** from AI providers (OpenAI, Google Gemini, LM Studio, Ollama). This provides a more responsive and engaging user experience, showing responses character-by-character or chunk-by-chunk as they're generated.

## Features

### 1. Progressive Text Display
- Text appears in real-time as chunks arrive from the backend
- Uses Server-Sent Events (SSE) for efficient streaming
- Supports all configured AI providers

### 2. Dual Streaming Display (NEW)
- **Message Bubble**: Full streaming content with blinking cursor
- **Status Preview**: Truncated preview (first 100 chars) in status area
- Provides immediate feedback in the area users are watching
- See [STREAMING_TEXT_STATUS_PREVIEW.md](STREAMING_TEXT_STATUS_PREVIEW.md) for details

### 3. Visual Streaming Indicator
- Displays a **blinking cursor (▋)** while streaming is active
- Cursor automatically disappears when streaming completes
- CSS animation provides smooth visual feedback
- Appears in both message bubble AND status preview

### 4. Auto-Scrolling
- Automatically scrolls to keep new content visible
- Uses optimized scroll batching to prevent performance issues
- Maintains smooth UX during rapid content updates

### 5. Markdown Rendering
- Plain text displayed during streaming (for security and performance)
- Full markdown rendering applied when streaming completes
- Preserves code blocks, links, formatting, etc.

## Configuration

### Shortcode Usage

Streaming is **enabled by default** in the shortcode:

```php
[mcp_ai_chat assistant="123"]
```

To disable streaming:

```php
[mcp_ai_chat assistant="123" enable_streaming="false"]
```

### Elementor Widget

Streaming is **enabled by default** in the Elementor widget. You can toggle it in the widget settings:
- Navigate to widget settings
- Find "Enable SSE Streaming" toggle
- Toggle to enable/disable

### Programmatic Configuration

```javascript
// Configuration is set via window.wpMcpAiChatInstances
window.wpMcpAiChatInstances['chat-instance-id'] = {
    assistantId: 123,
    enableStreaming: true, // Set to false to disable
    // ... other config
};
```

## How It Works

### Frontend (JavaScript)

1. **Request Initiation**: When `enableStreaming` is true, the chat client adds `stream: true` to the request payload and sets `Accept: text/event-stream` header.

2. **Stream Processing**: 
   ```javascript
   // chat.js - processSSEStream function
   - Reads chunks from response.body.getReader()
   - Parses SSE event format (event: type, data: json)
   - Accumulates content from delta.content
   - Calls updateCallback for each chunk
   ```

3. **Progressive Display**:
   ```javascript
   // chat.js - updateStreamingMessage function
   - Creates placeholder message bubble
   - Updates bubble.textContent with accumulated content
   - Adds streaming class for visual indicator
   - Auto-scrolls to keep content visible
   ```

4. **Finalization**:
   ```javascript
   // When [DONE] received or stream ends:
   - Removes streaming class (hides cursor)
   - Renders full markdown
   - Attaches speech/copy buttons
   - Saves to conversation history
   ```

### Backend (PHP)

The REST API (`/wp-json/mcp-ai/v1/chat-client`) handles streaming:

1. **Request Detection**: `WP_MCP_AI_SSE_Handler::request_wants_event_stream()` checks for `stream` parameter
2. **SSE Setup**: Sets appropriate headers (`Content-Type: text/event-stream`)
3. **Event Streaming**: Sends events in SSE format:
   ```
   event: status
   data: {"message":"Thinking...","type":"thinking"}

   event: message
   data: {"choices":[{"delta":{"content":"Hello"}}]}

   data: [DONE]
   ```

### CSS Styling

```css
/* Blinking cursor indicator */
.wp-mcp-ai-chat__bubble--streaming::after {
    content: '▋';
    display: inline-block;
    margin-left: 2px;
    animation: wp-mcp-ai-cursor-blink 1s step-end infinite;
}

@keyframes wp-mcp-ai-cursor-blink {
    0%, 50% { opacity: 1; }
    51%, 100% { opacity: 0; }
}
```

## Browser Compatibility

### Required Features
- **Fetch API with ReadableStream**: Supported in all modern browsers
- **Server-Sent Events (SSE)**: Supported in all browsers except IE11
- **TextDecoder**: Supported in all modern browsers

### Fallback Behavior
If streaming is not supported or fails:
1. Request automatically falls back to non-streaming mode
2. Full response returned as single JSON payload
3. User sees standard "waiting" state, then complete response

## Performance Considerations

### Optimizations
1. **Scroll Batching**: Uses `requestAnimationFrame` to batch scroll operations
2. **textContent vs innerHTML**: Plain text during streaming to avoid XSS and layout thrashing
3. **Debounced Storage**: Conversation saves are debounced to reduce localStorage writes
4. **Single Message Element**: Reuses the same DOM element during streaming

### Best Practices
- Enable streaming for better perceived performance
- Streaming is especially beneficial for long responses
- Works well with all AI providers (OpenAI, Gemini, LM Studio, Ollama)

## Security

### XSS Prevention
- Uses `textContent` (not `innerHTML`) during streaming
- Markdown rendering only applied after streaming completes
- All user input sanitized on backend

### Rate Limiting
- Same rate limits apply to streaming and non-streaming requests
- Streaming does not increase server load significantly

## Testing

### Unit Tests
```bash
npm test
```

Tests cover:
- CSS class application/removal
- Content updates
- DOM structure creation

### Manual Testing
1. **With LM Studio**:
   ```
   1. Configure LM Studio assistant
   2. Send a chat message
   3. Observe text appearing progressively
   4. Verify blinking cursor during streaming
   5. Verify cursor disappears when complete
   ```

2. **With OpenAI**:
   ```
   1. Configure OpenAI assistant
   2. Send a chat message asking for a story
   3. Watch text stream in real-time
   ```

3. **Disable Streaming**:
   ```
   1. Add enable_streaming="false" to shortcode
   2. Verify response appears all at once
   3. Verify no streaming cursor
   ```

## Troubleshooting

### Issue: Text Not Streaming
**Possible Causes:**
1. Backend not sending SSE format
2. Proxy/CDN buffering responses
3. Browser not supporting ReadableStream

**Solutions:**
1. Check network tab for `text/event-stream` content-type
2. Verify SSE events in network response
3. Check browser console for errors
4. Try with streaming disabled to isolate issue

### Issue: Blinking Cursor Not Appearing
**Possible Causes:**
1. CSS not loaded
2. Class not being applied

**Solutions:**
1. Check browser dev tools for `.wp-mcp-ai-chat__bubble--streaming` class
2. Verify CSS file is loaded
3. Check for CSS conflicts

### Issue: Performance Problems
**Possible Causes:**
1. Very rapid chunk updates
2. Large responses

**Solutions:**
1. Check OPTIMIZATIONS_ENABLED flag
2. Verify scroll batching is active
3. Monitor browser performance tab

## API Reference

### JavaScript Configuration

```javascript
{
    enableStreaming: true,        // Enable/disable streaming
    messagesEndpoint: '/wp-json/mcp-ai/v1/chat-client',
    // ... other config
}
```

### SSE Event Types

#### 1. status
```json
{
    "message": "Thinking...",
    "type": "thinking"
}
```

#### 2. tool_execution
```json
{
    "type": "started",
    "tool_name": "search_web",
    "status": "Searching..."
}
```

#### 3. message
```json
{
    "choices": [{
        "delta": {
            "content": "chunk of text"
        }
    }]
}
```

#### 4. error
```json
{
    "error": "Error message"
}
```

## Migration Guide

### From Previous Versions

**No breaking changes** - streaming was previously available but disabled by default.

If you explicitly set `enable_streaming="false"`, behavior remains unchanged.

If you didn't specify `enable_streaming`, it now defaults to `true` (previously `false`).

### Upgrading

1. Update plugin files
2. Clear browser cache
3. Test chat functionality
4. If issues occur, set `enable_streaming="false"` to restore previous behavior

## Related Documentation

- [REST API Documentation](../../reference/api/rest-api.md)
- [Chat UI Documentation](./chat-ui.md)
- [SSE Handler Tests](../tests/test-sse-handler.php)
- [Tool Reference](../../reference/tools/tool-reference.md)

## Changelog

### Version 1.1.0 (Current)
- **Enabled streaming by default** in shortcode and Elementor widget
- **Added visual streaming cursor** indicator
- **Added auto-scrolling** during streaming
- **Added streaming configuration tests**
- Improved UX for real-time text display

### Version 1.0.0
- Initial streaming support (disabled by default)
- SSE infrastructure
- Backend streaming handlers
