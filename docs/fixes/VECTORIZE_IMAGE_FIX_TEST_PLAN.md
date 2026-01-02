# Vectorize Image SSE Streaming Fix - Test Plan

## Issue Fixed
The `vectorize_image` tool was not returning responses to the chat client when executed in SSE (Server-Sent Events) streaming mode. The client would show:
```
[NV oOS] SSE stream completed: {totalContentLength: 0, contentSample: ''}
Fetch failed loading: POST "https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/chat-client"
```

## Changes Summary
1. Added exception handling around LLM calls and tool execution
2. Added data normalization to ensure JSON serializability
3. Added fallback URL generation for SVG attachments
4. Improved error logging for debugging

## Manual Test Scenarios

### Test 1: Basic Vectorization (Happy Path)
**Prerequisites:**
- WordPress site with plugin activated
- Assistant configured with `vectorize_image` tool enabled
- Chat interface with streaming enabled
- Node.js installed on server (required by vectorizer)

**Steps:**
1. Open chat interface
2. Upload a PNG or JPEG image
3. Send message: "Please vectorize this image to SVG format"
4. Wait for response

**Expected Behavior:**
- ✅ Status indicator shows "Tool is processing…"
- ✅ Tool execution event appears in console
- ✅ Final message contains SVG information:
  - Attachment ID
  - File URL
  - Success message
- ✅ Image preview shows vectorized SVG
- ✅ No "Fetch failed" errors in console

### Test 2: URL-based Vectorization
**Steps:**
1. Send message: "Vectorize this image: https://example.com/test-image.png"
2. Wait for response

**Expected Behavior:**
- ✅ Tool downloads image from URL
- ✅ Vectorization completes successfully
- ✅ Response includes SVG attachment data
- ✅ SSE stream completes properly

### Test 3: Error Handling - Invalid Image
**Steps:**
1. Send message: "Vectorize this non-existent image: https://example.com/does-not-exist.png"
2. Wait for response

**Expected Behavior:**
- ✅ Tool returns error message (not exception)
- ✅ SSE stream completes with error event
- ✅ Chat shows friendly error message
- ✅ No PHP fatal errors
- ✅ Subsequent messages work normally

### Test 4: Error Handling - Large Image
**Steps:**
1. Upload very large image (>10MB)
2. Request vectorization
3. Wait for response

**Expected Behavior:**
- ✅ Tool handles memory limits gracefully
- ✅ Either succeeds or returns descriptive error
- ✅ SSE stream completes properly
- ✅ No connection timeout

### Test 5: Permission Issues
**Steps:**
1. Temporarily set upload directory to read-only (if possible)
2. Attempt vectorization
3. Restore permissions

**Expected Behavior:**
- ✅ Tool returns permission error (not fatal error)
- ✅ Error is logged properly
- ✅ SSE stream completes
- ✅ Fallback URL generation is attempted

### Test 6: Other Tools Still Work
**Purpose:** Ensure changes don't break existing functionality

**Steps:**
1. Test another image tool (e.g., `generate_openai_image`)
2. Test text-based tool (e.g., `search_posts`)
3. Test tool with attachments

**Expected Behavior:**
- ✅ All tools execute normally
- ✅ Streaming responses work as before
- ✅ Tool results display correctly

## Browser Console Verification

### Success Indicators
Look for these console logs:
```javascript
[NV oOS] SSE message event received: {
  isFinalMessage: true,
  hasToolResults: true,
  ...
}

[NV oOS] SSE stream completed: {
  totalContentLength: > 0,  // Should be greater than 0
  contentSample: "..."      // Should contain content
}
```

### Error Indicators (Fixed)
These should NO LONGER appear:
```javascript
❌ [NV oOS] SSE stream completed: {totalContentLength: 0, contentSample: ''}
❌ Fetch failed loading: POST "..."
```

## Server-Side Verification

### Check PHP Error Log
Look for new error log entries if issues occur:
```bash
tail -f /path/to/php-error.log | grep "WP_MCP_AI"
```

### Check Plugin Logs
If debugging is enabled (Settings → WP oOS → Enable Logging):
```bash
# Via WP-CLI
wp option get wp_mcp_ai_recent_errors --format=json
wp option get wp_mcp_ai_recent_activity --format=json
```

Look for these success indicators:
- `sse_final_message_with_tools` event logged
- `tool_result_count` > 0
- No `sse_llm_exception` or `sse_tool_exception` errors

## Automated Testing (Optional)

If you have PHPUnit set up:
```bash
composer install
composer run test tests/test-vectorize-image-tool.php
composer run test tests/test-sse-agentic-integration.php
composer run test tests/test-streaming-tool-result-attachments.php
```

## Regression Testing Checklist

- [ ] Vectorize image with PNG source
- [ ] Vectorize image with JPEG source
- [ ] Vectorize image with WebP source
- [ ] Vectorize with custom quality settings
- [ ] Test with streaming enabled
- [ ] Test with streaming disabled
- [ ] Verify other image tools work
- [ ] Verify error handling works
- [ ] Check browser console for errors
- [ ] Check server error logs
- [ ] Verify attachment URLs are accessible

## Known Limitations

1. **Node.js Requirement**: The vectorize tool requires Node.js with @neplex/vectorizer installed
2. **File Size**: Very large images may still timeout depending on server configuration
3. **SVG Support**: WordPress must allow SVG uploads (may require plugin or custom code)

## Rollback Plan

If issues are discovered:
1. Revert commits: `git revert <commit-hash>`
2. Or disable tool temporarily in assistant configuration
3. Report issue with browser console logs and PHP error logs

## Success Criteria

The fix is successful if:
✅ Tool executes without connection failures
✅ SVG data is returned to chat client
✅ SSE stream completes properly (contentLength > 0)
✅ Error scenarios are handled gracefully
✅ No PHP fatal errors occur
✅ Existing tools continue to work
