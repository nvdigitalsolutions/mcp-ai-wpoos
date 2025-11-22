# Manual Testing Guide for Streaming Fetch Logging

## Purpose
This guide helps verify that the enhanced streaming fetch logging is working correctly.

## Prerequisites
- WordPress site with WP oOS plugin installed
- Browser with DevTools (Chrome, Firefox, Edge, or Safari)
- At least one configured assistant with streaming enabled

## Test Procedure

### Test 1: Successful Streaming Request

**Goal:** Verify logging appears for successful requests

**Steps:**
1. Open the site in browser
2. Open DevTools (F12)
3. Go to Console tab
4. Clear console (Ctrl+L or Cmd+K)
5. Navigate to a page with chat widget
6. Type a simple message: "Hello, how are you?"
7. Click Send

**Expected Logs (in order):**
```
[WP oOS] User clicked send: {message_length: 18, has_attachments: false, ...}
[WP oOS] Starting streaming request: {endpoint: "https://...", assistantId: 123, messageCount: 1, streamEnabled: true, hasSessionKey: true}
[WP oOS] Created streaming message element
[WP oOS] Streaming response received: {status: 200, statusText: "OK", ok: true, headers: {...}}
[WP oOS] Starting SSE stream processing
[WP oOS] SSE message event received: {hasChoices: true, hasDelta: true, hasContent: true}
[WP oOS] Content chunk extracted: "Hello! I'm doing well..."
[WP oOS] updateStreamingMessage called: {contentLength: 50, ...}
[WP oOS] SSE stream completed: {totalContentLength: 234, ...}
```

**Pass Criteria:**
- ✅ All logs appear in sequence
- ✅ No errors in console
- ✅ Message streams successfully
- ✅ Response details logged correctly

### Test 2: Network Error (Offline Mode)

**Goal:** Verify logging for network failures

**Steps:**
1. Open DevTools
2. Go to Network tab
3. Enable "Offline" mode (throttling dropdown)
4. Go to Console tab
5. Clear console
6. Send a message

**Expected Logs:**
```
[WP oOS] User clicked send: {...}
[WP oOS] Starting streaming request: {...}
[WP oOS] Created streaming message element
[WP oOS] Streaming request failed: {
    errorType: "TypeError",
    errorMessage: "Failed to fetch",
    errorStatus: "N/A",
    errorStatusText: "N/A",
    endpoint: "...",
    assistantId: 123,
    hasResponse: false,
    streamCompleted: false
}
```

**Pass Criteria:**
- ✅ Error details logged
- ✅ errorType is "TypeError"
- ✅ errorMessage is "Failed to fetch"
- ✅ No undefined/null errors in logs

### Test 3: Authentication Error

**Goal:** Verify logging for 403 Forbidden errors

**Steps:**
1. Open DevTools → Application → Cookies
2. Delete the WordPress auth cookie
3. Or, use a private/incognito window without logging in
4. Clear console
5. Send a message

**Expected Logs:**
```
[WP oOS] User clicked send: {...}
[WP oOS] Starting streaming request: {...}
[WP oOS] Created streaming message element
[WP oOS] Streaming response received: {status: 403, statusText: "Forbidden", ok: false, ...}
[WP oOS] HTTP error response: {status: 403, statusText: "Forbidden", url: "..."}
[WP oOS] Streaming request failed: {
    errorType: "Response",
    errorMessage: undefined,
    errorStatus: 403,
    errorStatusText: "Forbidden",
    ...
}
[WP oOS] Server response text: "{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.",...}"
```

**Pass Criteria:**
- ✅ HTTP error response logged
- ✅ Status 403 captured
- ✅ Server response text extracted
- ✅ JSON response shown

### Test 4: Malformed SSE Response

**Goal:** Verify JSON parsing error logging

**Setup:**
This test requires server-side modification to send malformed JSON. Skip if not feasible.

**Expected Logs:**
```
[WP oOS] Starting SSE stream processing
[WP oOS] Failed to parse SSE event data: {
    eventType: "message",
    eventData: "data: {broken json...",
    error: "Unexpected token..."
}
```

**Pass Criteria:**
- ✅ Parsing error caught
- ✅ Malformed data logged
- ✅ Error message descriptive

### Test 5: Stream Interruption

**Goal:** Verify logging when stream is interrupted

**Steps:**
1. Send a message to start streaming
2. While streaming, disable network in DevTools
3. Or, close the browser tab immediately

**Expected Logs:**
```
[WP oOS] Starting SSE stream processing
[WP oOS] Error reading SSE stream chunk: {
    error: "Stream closed unexpectedly",
    errorType: "DOMException"
}
```

**Pass Criteria:**
- ✅ Stream error caught
- ✅ Error type identified
- ✅ No unhandled promise rejections

## Verification Checklist

For each test, verify:

- [ ] Console shows "[WP oOS]" prefixed logs
- [ ] Logs appear in chronological order
- [ ] Error objects have type and message
- [ ] No "undefined" or "null" in error logs
- [ ] No JavaScript errors (red text in console)
- [ ] Request details include endpoint URL
- [ ] Response details include status code
- [ ] Error logs include full context

## Common Issues

### Logs Not Appearing

**Possible Causes:**
- Console filter hiding logs
- Wrong log level selected
- Browser blocking console output
- Code not loaded properly

**Fix:**
1. Clear all console filters
2. Ensure "Default levels" checked
3. Hard refresh page (Ctrl+Shift+R)
4. Check browser console settings

### Partial Logs

**Possible Causes:**
- Request fails before all stages
- Error thrown before logging completes
- Network interrupted mid-request

**Fix:**
This is expected for error scenarios. The last log shows where failure occurred.

### Too Verbose

**Possible Causes:**
- Normal behavior - comprehensive logging
- Multiple chat instances on page
- Rapid message sending

**Fix:**
1. Filter by "[WP oOS]"
2. Collapse log groups
3. Use console.clear() between tests

## Browser-Specific Notes

### Chrome/Edge
- Best developer tools
- Full SSE support
- Clear logging

### Firefox
- Good developer tools
- SSE supported
- May show CORS differently

### Safari
- Limited dev tools
- SSE supported
- May need Web Inspector enabled

## Expected Behavior Summary

| Scenario | Request Log | Response Log | Error Log | SSE Log |
|----------|------------|--------------|-----------|---------|
| Success | ✅ | ✅ | ❌ | ✅ |
| Network Error | ✅ | ❌ | ✅ | ❌ |
| HTTP Error | ✅ | ✅ | ✅ | ❌ |
| SSE Parse Error | ✅ | ✅ | ⚠️ | ✅ |
| Stream Interrupt | ✅ | ✅ | ✅ | ⚠️ |

✅ = Should appear
❌ = Should not appear
⚠️ = May appear

## Automated Testing

While manual testing is recommended for console logging, you can also:

1. **Use Playwright/Puppeteer:**
   ```javascript
   page.on('console', msg => {
       if (msg.text().includes('[WP oOS]')) {
           console.log(msg.text());
       }
   });
   ```

2. **Check console.log calls:**
   ```javascript
   const originalLog = console.log;
   const logs = [];
   console.log = (...args) => {
       logs.push(args);
       originalLog(...args);
   };
   ```

3. **Use browser extensions:**
   - Console Exporter
   - Logger++
   - Debug Bear

## Reporting Issues

If logging doesn't work as expected, report:

1. **Browser & Version:** e.g., "Chrome 120"
2. **Test Scenario:** Which test failed
3. **Expected Logs:** What should have appeared
4. **Actual Logs:** What actually appeared
5. **Console Screenshot:** Screenshot of DevTools console
6. **Network Tab:** Screenshot of failed request (if applicable)

## Success Criteria

All 5 tests should pass with:
- ✅ Appropriate logs for each scenario
- ✅ No undefined/null errors
- ✅ Consistent log format
- ✅ Clear error messages
- ✅ Full context in error logs

## Next Steps

After manual testing confirms logging works:
1. ✅ Mark this feature as tested
2. ✅ Document in user guide
3. ✅ Update troubleshooting docs
4. ✅ Close related issues

## Related Documentation

- `STREAMING_FETCH_LOGGING.md` - Full feature documentation
- `STREAMING_TEXT_DEBUG_GUIDE.md` - General debugging guide
- `docs/troubleshooting.md` - User troubleshooting guide
