# Embedded Client Testing Guide

**Date:** 2026-01-24  
**PR:** Enable logs for embedded client and fix final response handling

## Quick Test Steps

### 1. Single Widget Test

**Setup:**
1. Create an assistant with provider set to "embedded"
2. Set model to a WebLLM model (e.g., `Llama-3.2-1B-Instruct-q4f16_1-MLC`)
3. Add the assistant to a page using shortcode or Elementor widget

**Test:**
1. Open browser console (F12)
2. Send a message: "what can you do?"
3. Observe console logs

**Expected Console Output:**
```
[NV oOS] sendChatEmbedded called for instance: {assistantId}
[NV oOS] Created new embedded client instance: chat-{assistantId}-{timestamp}-{random}
[NV oOS Embedded Client] Created new instance: chat-{assistantId}-{timestamp}-{random}
[NV oOS] sendChatEmbeddedInternal called with client instance: chat-...
[NV oOS] Model not loaded, loading model: Llama-3.2-1B-Instruct-q4f16_1-MLC
[NV oOS Embedded Client] Loading model for instance: {details}
... (model loading progress)
[NV oOS Embedded Client] Model loaded successfully for instance: {details}
[NV oOS] Model loaded successfully, generating completion
[NV oOS] Starting embedded completion generation
[NV oOS] Formatted messages for embedded client: {details}
[NV oOS] Created assistant message bubble with ID: msg-...
[NV oOS] Calling generateStreamingCompletion with options: {details}
[NV oOS Embedded Client] Starting streaming completion for instance: {details}
[NV oOS Embedded Client] Chunk received for instance: {chunkNumber: 1, ...}
[NV oOS] Received chunk callback: {callbackNumber: 1, ...}
... (chunks 2-5 logged)
[NV oOS Embedded Client] Chunk received for instance: {chunkNumber: 5, ...}
[NV oOS] Received chunk callback: {callbackNumber: 5, ...}
... (more chunks - logged every 5th + initial 5)
[NV oOS Embedded Client] Streaming completed for instance: {totalChunks: ..., contentLength: ...}
[NV oOS Embedded Client] Calling onChunk with done=true for instance: chat-...
[NV oOS] Received done chunk: {callbackNumber: ..., finalContentLength: ...}
[NV oOS Embedded Client] Returning final result for instance: {success: true, ...}
[NV oOS] Received final result from generateStreamingCompletion: {success: true, ...}
[NV oOS] Updating final message bubble in DOM
[NV oOS] Attaching usage badges to bubble: {usage}
[NV oOS] Final message bubble updated successfully
[NV oOS] Saving conversation to storage
[NV oOS] Calling finalize()
[NV oOS] Embedded completion generation completed successfully
```

**UI Verification:**
- ✅ Message appears in chat as it's being generated (streaming)
- ✅ Final message is complete and properly formatted (markdown rendered)
- ✅ Usage badge shows "Embedded LLM" and model name
- ✅ Message persists after page reload (storage working)

### 2. Multiple Widgets Test

**Setup:**
1. Create 2 assistants with provider "embedded" but DIFFERENT models:
   - Assistant 1: `Llama-3.2-1B-Instruct-q4f16_1-MLC`
   - Assistant 2: `Qwen2.5-0.5B-Instruct-q4f16_1-MLC`
2. Add both to the same page (side by side)

**Test:**
1. Open browser console
2. Send message to Widget 1: "Hi from widget 1"
3. Send message to Widget 2: "Hi from widget 2"
4. Observe console logs

**Expected Behavior:**
- Each widget creates its own embedded client instance
- Instance IDs are different and appear in all logs
- Models are loaded independently
- No interference between widgets
- Each widget maintains its own conversation state

**Console Verification:**
```
[NV oOS] Created new embedded client instance: chat-1704-{timestamp1}-{random1}
[NV oOS Embedded Client] Created new instance: chat-1704-{timestamp1}-{random1}
... (Widget 1 model loading and generation)

[NV oOS] Created new embedded client instance: chat-1705-{timestamp2}-{random2}
[NV oOS Embedded Client] Created new instance: chat-1705-{timestamp2}-{random2}
... (Widget 2 model loading and generation - different model)
```

**UI Verification:**
- ✅ Both widgets work independently
- ✅ No errors or interference
- ✅ Each shows correct model in usage badge
- ✅ Each maintains separate conversation history

### 3. Final Response Verification

**Setup:**
1. Use a single embedded widget
2. Clear browser console

**Test:**
1. Send a message that generates a long response (50+ chunks)
2. Watch the console throughout the process
3. Verify the final response appears in chat

**Key Checkpoints:**
- ✅ Streaming chunks update the UI progressively
- ✅ "done chunk" logged with `done: true`
- ✅ Final result logged with complete content
- ✅ DOM update logged
- ✅ Final message appears in chat (matches logged content)
- ✅ No "Could not find message bubble" warnings

**If Issues:**
- Check that `assistantMessageId` in logs matches bubble data-message-id attribute
- Verify `result.content` is not empty in final result log
- Ensure no JavaScript errors during streaming

### 4. Error Scenarios

**Test A: Invalid Model**
1. Set model to non-existent model ID
2. Send message
3. Expected: Error message with categorized error

**Test B: WebGPU Not Supported** (if applicable)
1. Use browser without WebGPU
2. Expected: Clear error message about browser compatibility

**Test C: Network Failure During Model Load**
1. Start model loading
2. Disable network mid-load
3. Expected: Network error with retry suggestion

### 5. Edge Cases

**Test A: Rapid Messages**
1. Send 3 messages rapidly before response starts
2. Expected: All messages queued and processed correctly

**Test B: Page Reload During Generation**
1. Start generating response
2. Reload page mid-generation
3. Expected: Conversation history preserved (up to last complete message)

**Test C: Switching Between Widgets**
1. Start generation in Widget 1
2. Immediately send message in Widget 2
3. Expected: Both complete independently without interference

## Debugging Tips

### No Logs Appearing

**Check:**
1. Console filter - ensure no filters are active
2. Browser console level - should include "Info" and "Log"
3. JavaScript errors - check for script loading failures

### Final Response Not Appearing

**Check Console For:**
1. "Received final result" log - is content length > 0?
2. "Updating final message bubble" log - is bubble found?
3. Any errors between "done chunk" and "completed successfully"

**Check DOM:**
1. Inspect message bubble - does it have data-message-id attribute?
2. Check if bubble has any content (even if not visible)
3. Verify markdown service is loaded (window.wpMcpAiChatMarkdown)

### Instance ID Collisions (very rare)

**Symptoms:**
- Different widgets showing same instance ID
- Unexpected model loading in one widget

**Fix:**
- Reload page - new IDs generated
- Check timestamp component in ID - should be different
- If still issues, report bug (should not happen with current implementation)

## Performance Expectations

### Model Loading Time
- Small models (< 1GB): 10-30 seconds on good connection
- Large models (> 2GB): 30-120 seconds on good connection
- Should show progress percentage in status

### Generation Speed
- Depends on model size and device GPU
- Small models: ~20-50 tokens/second
- Large models: ~5-20 tokens/second
- Should see progressive UI updates (not frozen)

### Memory Usage
- Each model instance uses RAM proportional to model size
- 1B model: ~1GB RAM
- 3B model: ~3GB RAM
- Multiple widgets = multiple models = more RAM usage

## Screenshots to Capture

For PR documentation, capture:

1. **Console logs** showing complete flow from start to completion
2. **Chat UI** showing:
   - Streaming in progress
   - Final message with usage badge
   - Multiple widgets side-by-side (if testing multi-widget)
3. **DevTools Network tab** showing WebLLM model download
4. **Usage badge** close-up showing "Embedded LLM" provider

## Success Criteria

All of the following must pass:

- ✅ Logs appear for every step of the process
- ✅ Instance IDs are unique and consistent in logs
- ✅ Streaming chunks appear progressively in chat UI
- ✅ Final response appears complete and properly formatted
- ✅ Usage badges show correct provider and model
- ✅ Conversation persists after page reload
- ✅ Multiple widgets work independently without interference
- ✅ No JavaScript errors in console
- ✅ Error messages are user-friendly (categorized errors)
- ✅ Code passes review without critical issues

## Known Limitations

1. **WebGPU Required**: Only works in Chrome 113+, Edge 113+, Safari 18+ (macOS)
2. **Memory Requirements**: Large models require significant RAM
3. **Initial Load Time**: First model load downloads from CDN
4. **Browser Tab Only**: Model unloads when tab is closed

## Reporting Issues

If any test fails, include in bug report:

1. Complete console log output
2. Browser and version
3. Device specs (RAM, GPU if known)
4. Model being used
5. Steps to reproduce
6. Screenshot showing issue
