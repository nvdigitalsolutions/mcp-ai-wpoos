# Streaming Fetch Failure Logging Enhancement

## Summary

Added comprehensive debug logging for streaming chat requests to help diagnose fetch failures and network issues. When a streaming request fails, detailed information is now logged to the browser console to aid in troubleshooting.

## Problem Statement

Users were experiencing streaming request failures with the error message "Fetch failed loading: POST .../chat-client" but had no diagnostic information to help identify the root cause. The debug logs showed:
- "Created streaming message element" (request initiated)
- "Fetch failed loading" (generic browser error)

But there was no information about:
- What request was being made
- What response (if any) was received  
- What specific error occurred
- Where in the streaming pipeline the failure happened

## Solution

Added comprehensive logging at multiple stages of the streaming request lifecycle:

### 1. Request Initiation Logging

**Location:** `sendChatStreaming()` function start
**When:** Before fetch request is sent

Logs:
```javascript
[WP oOS] Starting streaming request: {
    endpoint: "https://example.com/wp-json/mcp-ai/v1/chat-client",
    assistantId: 331,
    messageCount: 2,
    streamEnabled: true,
    hasSessionKey: true
}
```

**Purpose:** Confirms the request parameters being sent

### 2. Response Reception Logging

**Location:** First `.then()` after fetch
**When:** When HTTP response is received (before reading body)

Logs:
```javascript
[WP oOS] Streaming response received: {
    status: 200,
    statusText: "OK",
    ok: true,
    contentType: "text/event-stream; charset=UTF-8",
    headers: {
        "content-type": "text/event-stream; charset=UTF-8",
        "cache-control": "no-cache, no-store, must-revalidate",
        "connection": "keep-alive"
    }
}
```

**Purpose:** Confirms server responded and with what headers

### 3. HTTP Error Logging

**Location:** When `response.ok` is false
**When:** Server returns error status (4xx, 5xx)

Logs:
```javascript
[WP oOS] HTTP error response: {
    status: 403,
    statusText: "Forbidden",
    url: "https://example.com/wp-json/mcp-ai/v1/chat-client"
}
```

**Purpose:** Identifies server-side errors

### 4. Fetch Failure Logging

**Location:** `.catch()` block in `sendChatStreaming()`
**When:** Network error, CORS, or request failure

Logs:
```javascript
[WP oOS] Streaming request failed: {
    errorType: "TypeError",
    errorMessage: "Failed to fetch",
    errorStatus: "N/A",
    errorStatusText: "N/A",
    endpoint: "https://example.com/wp-json/mcp-ai/v1/chat-client",
    assistantId: 331,
    hasResponse: false,
    streamCompleted: false
}
```

If response body is available:
```javascript
[WP oOS] Server response text: "{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":403}}"
```

**Purpose:** Provides detailed error context for debugging

### 5. SSE Stream Processing Logging

**Location:** `processSSEStream()` function
**When:** SSE stream starts and completes

Start:
```javascript
[WP oOS] Starting SSE stream processing
```

Completion:
```javascript
[WP oOS] SSE stream completed: {
    totalContentLength: 1234,
    contentSample: "Here is the response content..."
}
```

**Purpose:** Tracks stream lifecycle

### 6. SSE Parsing Error Logging

**Location:** SSE event parsing catch block
**When:** Malformed JSON in SSE event data

Logs:
```javascript
[WP oOS] Failed to parse SSE event data: {
    eventType: "message",
    eventData: "data: {invalid json...",
    error: "Unexpected token i in JSON at position 0"
}
```

**Purpose:** Identifies malformed server responses

### 7. Stream Reading Error Logging

**Location:** readChunk error handlers
**When:** Error reading from ReadableStream

Logs:
```javascript
[WP oOS] Error reading SSE stream chunk: {
    error: "Stream closed unexpectedly",
    errorType: "DOMException"
}

[WP oOS] SSE stream processing error: {
    error: "Stream closed unexpectedly",
    errorType: "DOMException"
}
```

**Purpose:** Identifies stream interruptions

## Common Failure Scenarios & Debugging

### Scenario 1: CORS Error

**Symptoms:**
```
[WP oOS] Starting streaming request: {...}
[WP oOS] Streaming request failed: {
    errorType: "TypeError",
    errorMessage: "Failed to fetch",
    errorStatus: "N/A"
}
```

**Diagnosis:** Network-level failure before server response
**Common Causes:**
- CORS policy blocking request
- Wrong endpoint URL
- Network connectivity issue
- Firewall/proxy blocking

**Fix:** Check CORS headers, verify endpoint URL, check network

### Scenario 2: Authentication Error

**Symptoms:**
```
[WP oOS] Starting streaming request: {...}
[WP oOS] Streaming response received: {status: 403, statusText: "Forbidden"}
[WP oOS] HTTP error response: {...}
[WP oOS] Server response text: "{"code":"rest_forbidden",...}"
```

**Diagnosis:** Server rejected request due to permissions
**Common Causes:**
- Invalid/expired nonce
- Missing authentication
- Insufficient user permissions
- Guest token expired

**Fix:** Verify authentication headers, check user permissions

### Scenario 3: Malformed SSE Data

**Symptoms:**
```
[WP oOS] Starting streaming request: {...}
[WP oOS] Streaming response received: {status: 200, ...}
[WP oOS] Starting SSE stream processing
[WP oOS] Failed to parse SSE event data: {
    eventType: "message",
    eventData: "data: {broken...",
    error: "Unexpected token..."
}
```

**Diagnosis:** Server sending invalid JSON in SSE events
**Common Causes:**
- PHP error mixed into SSE output
- Corrupted response data
- Wrong content encoding

**Fix:** Check server logs, verify SSE format

### Scenario 4: Stream Interruption

**Symptoms:**
```
[WP oOS] Starting streaming request: {...}
[WP oOS] Streaming response received: {status: 200, ...}
[WP oOS] Starting SSE stream processing
[WP oOS] Error reading SSE stream chunk: {
    error: "Stream closed unexpectedly"
}
```

**Diagnosis:** Connection lost during streaming
**Common Causes:**
- Server timeout
- Proxy timeout
- Network interruption
- Server crash/restart

**Fix:** Check server timeout settings, verify connection stability

## Testing

To test the enhanced logging:

1. **Open DevTools Console**
   - Press F12 or Right-click → Inspect
   - Go to Console tab
   - Clear console (Ctrl+L)

2. **Send a Test Message**
   - Type a message in the chat
   - Click Send
   - Watch console output

3. **Expected Logs (Success)**
   ```
   [WP oOS] User clicked send: {...}
   [WP oOS] Starting streaming request: {...}
   [WP oOS] Created streaming message element
   [WP oOS] Streaming response received: {...}
   [WP oOS] Starting SSE stream processing
   [WP oOS] SSE message event received: {...}
   [WP oOS] Content chunk extracted: "..."
   [WP oOS] updateStreamingMessage called: {...}
   [WP oOS] SSE stream completed: {...}
   ```

4. **Expected Logs (Failure)**
   ```
   [WP oOS] User clicked send: {...}
   [WP oOS] Starting streaming request: {...}
   [WP oOS] Created streaming message element
   [WP oOS] Streaming request failed: {...}
   [WP oOS] Server response text: "..." (if available)
   ```

## Files Modified

### JavaScript
- `assets/js/chat.js` (+95 lines added, 2 modified)
  - Line ~7771-7784: Added request initiation logging
  - Line ~7892-7917: Added response reception logging
  - Line ~8009-8039: Enhanced error logging with details
  - Line ~8028-8035: Added SSE processing start logging
  - Line ~8043-8050: Added SSE completion logging
  - Line ~8300-8308: Enhanced JSON parsing error logging
  - Line ~8313-8330: Added stream reading error logging

## Performance Impact

- **Minimal:** Console logging has negligible performance overhead
- **Only in development:** Logs only appear in DevTools console
- **No production impact:** Users don't see logs
- **Async-safe:** Error extraction (text()) is non-blocking

## Security Considerations

### Data Exposure
- ✅ No sensitive data logged
- ✅ Passwords/tokens not included
- ✅ Only metadata logged (counts, types, status codes)
- ✅ Content samples truncated

### Privacy
- ✅ Message content not logged in full
- ✅ Only first 100 chars of content logged
- ✅ User IDs not logged
- ✅ Session keys not logged in full

### Information Disclosure
- ⚠️ Endpoint URLs logged (acceptable for debugging)
- ⚠️ Assistant IDs logged (acceptable for debugging)
- ⚠️ Error messages logged (acceptable for debugging)

**Recommendation:** Keep DevTools closed in production to prevent console logging overhead.

## Compatibility

### Browser Support
Works in all modern browsers that support:
- ✅ console.log() / console.error()
- ✅ ReadableStream API
- ✅ Fetch API
- ✅ ES5+ JavaScript

### Integration Points
- ✅ **Shortcode:** `[wp_mcp_ai_chat]`
- ✅ **Elementor Widget:** Chat widget
- ✅ **Admin Test Interface:** Assistant testing
- ✅ **Guest Tokens:** Public chat surfaces
- ✅ **Voice Chat Mode:** With auto-play

### AI Providers
Compatible with all supported providers:
- ✅ OpenAI (GPT-3.5, GPT-4, o1)
- ✅ Google Gemini (including Thinking Mode)
- ✅ Ollama (local AI)
- ✅ LM Studio (local AI)

## Backward Compatibility

- ✅ No breaking changes
- ✅ No API changes
- ✅ No configuration changes
- ✅ No database changes
- ✅ Fully backward compatible

## Future Enhancements

Potential improvements:
1. Add log filtering by severity level
2. Add log export functionality
3. Add performance timing metrics
4. Add network waterfall visualization
5. Add automatic error reporting
6. Add log persistence to localStorage
7. Add debug mode toggle in UI

## Troubleshooting Guide

### Logs Not Appearing

**Check:**
1. DevTools console is open
2. Console level includes "Log" messages
3. "Default levels" filter is enabled
4. No console.log polyfills interfering

### Too Many Logs

**Solutions:**
1. Filter by "[WP oOS]" prefix
2. Use DevTools filter: `/\[WP oOS\]/`
3. Collapse verbose entries
4. Clear console between tests

### Missing Error Details

**If you see:**
```
[WP oOS] Streaming request failed: {errorMessage: "Failed to fetch", ...}
```

**But no response text, check:**
1. Error has no response body (network error)
2. Response is not readable (CORS)
3. Response already consumed (unlikely)

## Configuration

No configuration needed. Logging is always enabled when:
- `window.console` exists
- `console.log` / `console.error` available
- DevTools console is open (performance)

## Disabling Logs

To disable logs temporarily:
```javascript
// In browser console, override console methods
const originalLog = console.log;
const originalError = console.error;
console.log = () => {};
console.error = () => {};

// To re-enable:
console.log = originalLog;
console.error = originalError;
```

Or filter in DevTools:
```
-[WP oOS]
```

## Related Documentation

- `STREAMING_TEXT_DEBUG_GUIDE.md` - General streaming debug guide
- `STREAMING_STATUS_IMMEDIATE_UPDATE.md` - Status update enhancements
- `docs/rest-api.md` - REST API documentation
- `docs/troubleshooting.md` - General troubleshooting

## References

- **Issue:** "streaming still not showing there is the debug logs" + "Fetch failed loading"
- **PR Branch:** `copilot/fix-streaming-logs-issue`
- **Related Files:**
  - `assets/js/chat.js`
  - `includes/rest/class-wp-mcp-ai-sse-handler.php`
  - `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`

## Conclusion

This enhancement provides comprehensive diagnostic logging for streaming request failures, making it much easier to identify and resolve issues. Users and developers now have detailed information about:

- What request was made
- What response was received
- What error occurred
- Where the failure happened
- Full error context

This significantly improves the debugging experience and reduces time to resolution for streaming-related issues.
