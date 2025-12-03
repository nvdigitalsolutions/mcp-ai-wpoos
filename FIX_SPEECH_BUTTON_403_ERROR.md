# Fix: Speech Button 403 Error

## Issue

The speech generation button in the chat UI was failing with a 403 Forbidden error when attempting to call the REST API tools endpoint:

```
POST https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/tools 403 (Forbidden)
chat-audio-service.js:508 [WP oOS] Speech generation failed: Response
```

This affected the text-to-speech functionality where users could click a button to have AI responses read aloud.

## Root Cause

The problem was caused by a mismatch between using **absolute URLs** for REST API endpoints and the `credentials: 'same-origin'` fetch mode:

```javascript
fetch(state.config.toolsEndpoint, {
    method: 'POST',
    headers: buildJsonHeaders(state),
    credentials: 'same-origin',  // ← This was the problem
    body: JSON.stringify(payload),
})
```

When `toolsEndpoint` is an absolute URL like `https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/tools`, the browser's same-origin policy with `credentials: 'same-origin'` will:

1. Only send credentials (cookies, auth headers) if the URL **exactly** matches the current origin
2. Fail in cross-domain embeds or when accessed through different domains/proxies
3. Cause authentication to fail even for same-site requests in some edge cases

Additionally, the error logging was poor - it only logged the raw Response object which showed as `Response` in the console instead of extracting the actual error message.

## Solution

### 1. Changed Credentials Mode from 'same-origin' to 'omit'

**File**: `assets/js/chat-audio-service.js` (line 349)

```javascript
// Before
credentials: 'same-origin',

// After
credentials: 'omit',
```

**Why this works:**
- Authentication is done via headers (`X-WP-Nonce`, `X-WP-MCP-AI-Guest`), not cookies
- Headers are sent regardless of credentials mode
- `credentials: 'omit'` works with both same-origin and cross-origin requests
- This aligns with how the WordPress REST API is designed to work
- Eliminates CORS-related authentication issues

### 2. Improved Error Message Extraction

**File**: `assets/js/chat-audio-service.js` (lines 359-368)

```javascript
// Before
if (!response.ok) {
    throw response;  // Raw response object - not helpful in logs
}

// After
if (!response.ok) {
    // Extract error message from response body for better error reporting
    const errorMessage = body && body.message ? body.message : 
        (body && body.error ? body.error : 'Speech generation failed');
    const error = new Error(errorMessage);
    error.response = response;
    error.status = response.status;
    error.statusText = response.statusText;
    error.body = body;
    throw error;
}
```

### 3. Enhanced Error Logging

**File**: `assets/js/chat-audio-service.js` (lines 468-484)

```javascript
// Before
console.error('[WP oOS] Speech generation failed:', error);

// After
const errorDetails = {
    message: error && error.message ? error.message : 'Unknown error',
    status: error && error.status ? error.status : undefined,
    statusText: error && error.statusText ? error.statusText : undefined,
    endpoint: state && state.config ? state.config.toolsEndpoint : undefined
};

// Include response body if available for debugging
if (error && error.body) {
    errorDetails.body = error.body;
}

console.error('[WP oOS] Speech generation failed:', errorDetails);
```

**Benefits:**
- Shows the actual error message from the server (e.g., "Authentication required", "Invalid assistant ID")
- Includes HTTP status code and statusText for debugging
- Shows which endpoint failed
- Includes the full response body for detailed diagnostics

## Technical Details

### Credentials Modes Explained

1. **`credentials: 'omit'`** - Never send credentials, even for same-origin requests
   - ✅ Works with both same-origin and cross-origin requests
   - ✅ Perfect when using header-based authentication (like this plugin)
   - ✅ No CORS complications

2. **`credentials: 'same-origin'`** (previous approach) - Only send credentials for exact same-origin requests
   - ❌ Fails with absolute URLs in some scenarios
   - ❌ Doesn't work for cross-domain embeds
   - ❌ Can fail even for same-site requests due to URL matching

3. **`credentials: 'include'`** - Send credentials for all requests
   - Requires CORS headers (`Access-Control-Allow-Credentials`)
   - Unnecessary for header-based auth
   - More complex to configure

### Why Absolute URLs Are Used

The `get_rest_url_path()` method in `WP_MCP_AI_Shortcode` returns absolute URLs (not relative paths) for a specific reason:

> Returns an absolute URL to ensure compatibility with cross-domain embeds (e.g., when the chat widget is embedded on a different domain via iframe or when accessed from external sites).

This is by design to support embedding the chat widget on external sites.

## Files Changed

- `assets/js/chat-audio-service.js`
  - Line 349: Changed `credentials: 'same-origin'` to `credentials: 'omit'`
  - Lines 359-368: Improved error extraction from response
  - Lines 468-484: Enhanced error logging with details

## Testing

### Manual Testing Steps

1. Open a page with the chat widget
2. Send a message to the assistant
3. Click the speech button (play icon) on an AI response
4. Verify the audio is generated and plays successfully

### Expected Behavior

- **Before Fix**: 403 error, no audio, unhelpful error message
- **After Fix**: Audio generates successfully, or if it fails, shows a helpful error message

### Error Message Examples

**Before:**
```
[WP oOS] Speech generation failed: Response
```

**After (successful):**
```
[WP oOS] Speech generated successfully
```

**After (authentication error):**
```
[WP oOS] Speech generation failed: {
  message: "Authentication required",
  status: 403,
  statusText: "Forbidden",
  endpoint: "https://example.com/wp-json/mcp-ai/v1/tools",
  body: { error: "Authentication required", code: "rest_forbidden" }
}
```

## Security Considerations

✅ **No security impact:**
- Authentication is still required via headers
- The `credentials: 'omit'` mode is more restrictive (doesn't send cookies)
- All existing authentication mechanisms (nonce, guest tokens, bearer tokens) continue to work
- No changes to server-side permission checks

## Backward Compatibility

✅ **Fully backward compatible:**
- Only changes the client-side fetch credentials mode
- Server-side authentication logic unchanged
- Works with all existing authentication methods
- No breaking changes to any APIs or interfaces

## Related Issues

This fix resolves speech button functionality that was broken when:
- Chat widget is embedded via iframe
- Site is accessed through different domains or proxies
- Absolute URLs are used for REST endpoints (by design for cross-domain support)

## References

- MDN: [Fetch API credentials](https://developer.mozilla.org/en-US/docs/Web/API/fetch#credentials)
- WordPress REST API: [Authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)
- Related fix documents:
  - `FIX_403_TOOLS_ENDPOINT.md` - Backend authentication fix
  - `FIX_CHAT_UI_BUTTON_403_ERROR.md` - Previous attempt (later reverted to absolute URLs)

## Date

December 2, 2024
