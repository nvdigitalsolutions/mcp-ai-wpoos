# Streaming Text Rendering - Debug Guide

## Problem
Streaming text is not visible in the chat bubble during response generation. The bubble exists with correct classes, but contains no text content.

## Complete Flow

### Server-Side (PHP)
1. Client sends message to `/wp-json/mcp-ai/v1/chat` with `stream` parameter
2. Server makes **blocking** call to OpenAI API via `wp_remote_post()` 
3. Server waits for **complete** response (5-30 seconds)
4. Server receives full text response
5. Server **chunks** the text into 50-character pieces
6. Server sends chunks via SSE:
   ```json
   event: message
   data: {"choices":[{"delta":{"content":"chunk text here"}}]}
   ```
7. Server sends 10ms delay between chunks (via `usleep()`)
8. Server sends final complete response with `data` field
9. Server sends `[DONE]` marker

### Client-Side (JavaScript)
1. Browser receives SSE stream via `fetch()` + `response.body.getReader()`
2. `processSSEStream()` parses SSE events
3. For each `message` event:
   - Extract `contentChunk` from `data.choices[0].delta.content`
   - Accumulate in `fullContent`
   - Call `updateStreamingMessage(fullContent)`
4. `updateStreamingMessage()` executes:
   - Create streaming bubble if doesn't exist
   - Set `streamingMessageElement.textContent = safeContent`
   - Add `.wp-mcp-ai-chat__bubble--streaming` class
   - Scroll to bottom
5. User sees text appear progressively with blinking cursor

## Debug Logging

### What to Look For

Open browser DevTools console and send a message. You should see:

```
[WP oOS] Created streaming message element
[WP oOS] SSE message event received: {hasChoices: true, hasDelta: true, hasContent: true}
[WP oOS] Content chunk extracted: "Jamaica is located in the Caribbean Sea, sou..."
[WP oOS] updateStreamingMessage called: {contentLength: 50, contentSample: "Jamaica is located in the Caribbean Sea, sou...", elementExists: true, elementInDOM: true}
[WP oOS] After setting textContent: {elementTextContent: "Jamaica is located in the Caribbean Sea, sou...", elementInnerHTML: "Jamaica is located in the Caribbean Sea, sou...", elementOuterHTML: "<div class=\"wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--assistant wp-mcp-ai-chat__bubble--streaming\">Jamaica is located in the Caribbean Sea, sou...</div>"}
```

### Failure Scenarios

#### Scenario 1: No SSE Events Received
```
// Console shows NOTHING after "Generating response…"
```
**Problem**: SSE connection failed
**Possible Causes**:
- Network issue
- CORS problem
- Server not sending SSE headers
- Browser doesn't support SSE

#### Scenario 2: Events Received, No Chunks
```
[WP oOS] SSE message event received: {hasChoices: false, hasDelta: false, hasContent: false}
```
**Problem**: Server sending wrong format
**Possible Causes**:
- Server not chunking (check if `$text_content` is empty)
- Wrong event structure
- PHP error preventing chunk loop

#### Scenario 3: Chunks Extracted, Callback Not Called
```
[WP oOS] Content chunk extracted: "text here"
// But NO "updateStreamingMessage called" log
```
**Problem**: `updateCallback` not working
**Possible Causes**:
- Callback reference lost
- JavaScript error before callback
- Conditional preventing callback execution

#### Scenario 4: Everything Logged, Text Not Visible
```
[WP oOS] After setting textContent: {elementTextContent: "text here", ...}
// But inspecting element shows empty: <div class="..."></div>
```
**Problem**: DOM update overridden or text hidden
**Possible Causes**:
- CSS hiding text (color: transparent, display: none)
- Another script clearing content
- Browser rendering issue
- Element reference stale

## How to Test

1. **Enable Logging** (already enabled in latest code)

2. **Clear Cache**:
   - Hard refresh: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
   - Or clear browser cache completely

3. **Open DevTools**:
   - Press F12 or Right-click → Inspect
   - Go to Console tab
   - Keep it open during test

4. **Send Test Message**:
   - Type a simple question: "Where is Jamaica?"
   - Click Send
   - Watch console logs

5. **Inspect HTML During Streaming**:
   - While "Generating response…" is showing
   - Right-click the empty bubble → Inspect Element
   - Check if textContent exists in HTML

6. **Share Results**:
   - Copy all console logs
   - Take screenshot of inspected element
   - Note which scenario matches (1-4 above)

## Quick Checks

### Is SSE Working?
```javascript
// Run in console while streaming is active
console.log(document.querySelector('.wp-mcp-ai-chat__bubble--streaming'));
// Should show: <div class="...">text content here</div>
// NOT: <div class="..."></div>
```

### Is textContent Set?
```javascript
// Run in console while streaming
const bubble = document.querySelector('.wp-mcp-ai-chat__bubble--streaming');
console.log('textContent:', bubble?.textContent);
console.log('innerHTML:', bubble?.innerHTML);
// Both should show the accumulated text
```

### Are Events Arriving?
```javascript
// Check Network tab in DevTools
// Look for request to: /wp-json/mcp-ai/v1/chat
// Type should be: eventsource or text/event-stream
// Status should be: 200
// Should see "Data" column with chunks
```

## Expected vs Actual

### Expected Behavior
- User sends message
- Status changes to "Generating response…"
- Empty bubble appears immediately
- Text appears character-by-character with blinking cursor
- After complete, cursor disappears and formatting is applied

### Actual Behavior (Current Issue)
- ✅ User sends message
- ✅ Status changes to "Generating response…"
- ✅ Empty bubble appears
- ❌ Text does NOT appear during streaming
- ❌ Bubble remains empty
- ? After streaming completes...?

## Chunking Conditions

Chunking happens ONLY when:
- ✅ Request uses SSE (`stream` parameter present)
- ✅ LLM response is complete
- ✅ All tool calls executed
- ✅ Response has text content
- ✅ Content is a string

Chunking does NOT happen when:
- ❌ Non-streaming request (no `stream` parameter)
- ❌ Response is error
- ❌ Response has no text content
- ❌ Content is not a string

## Files Modified

1. `assets/js/chat.js`:
   - Line ~7832: Added logging to `updateStreamingMessage`
   - Line ~7853: Log element state after `textContent` assignment
   - Line ~8088: Log SSE message event received
   - Line ~8095: Log content chunk extraction

## Next Steps

Based on test results:
1. Identify which scenario matches (1-4 above)
2. Implement targeted fix
3. Test fix
4. Verify streaming works
5. Remove debug logging (or keep behind DEBUG_MODE flag)
6. Update documentation

## Alternative: True LLM Streaming

If simulated streaming continues to have issues, we can implement **true streaming** from OpenAI:

1. Add `stream: true` to OpenAI API payload
2. Handle SSE response from OpenAI using PHP streams
3. Forward chunks to client in real-time
4. Benefits: Lower latency, lower memory, better UX

This requires replacing `wp_remote_post()` with stream-capable HTTP client (curl with callbacks or fsockopen).
