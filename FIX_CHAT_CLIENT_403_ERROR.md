# Fix: Chat Client 403 Forbidden Error

## Issue

The chat client was experiencing 403 Forbidden errors when making POST requests to the `/wp-json/mcp-ai/v1/tools` endpoint and other REST API endpoints. The error manifested as:

```
POST https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/tools 403 (Forbidden)
Response {type: 'basic', url: '...', status: 403, ok: false, ...}
```

This affected multiple features in the chat client including:
- Speech generation (text-to-speech)
- Tool execution
- Message sending
- File uploads
- Transcript management
- Chat history

## Root Cause

The problem was caused by using `credentials: 'same-origin'` in fetch requests when the REST API endpoints are configured to use **absolute URLs** for cross-domain compatibility.

### Technical Explanation

When `credentials: 'same-origin'` is used with an absolute URL like `https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/tools`, the browser's same-origin policy will:

1. Only send credentials (cookies, auth headers) if the URL **exactly** matches the current origin
2. Fail in cross-domain embeds or when accessed through different domains/proxies
3. Cause authentication to fail even for same-site requests in some edge cases

This is particularly problematic because:
- The plugin uses absolute URLs by design to support cross-domain embeds (e.g., when the chat widget is embedded on a different domain via iframe)
- Authentication is done via **headers** (`X-WP-Nonce`, `X-WP-MCP-AI-Guest`, `Authorization`), not cookies
- The `credentials: 'same-origin'` mode can block header-based authentication in certain scenarios

## Solution

Changed all fetch requests in `assets/js/chat.js` from `credentials: 'same-origin'` to `credentials: 'omit'`.

### Why This Works

1. **Authentication is header-based**: The plugin uses headers for authentication:
   - `X-WP-Nonce` for WordPress nonce authentication
   - `X-WP-MCP-AI-Guest` for guest token authentication
   - `Authorization: Bearer` for bearer token authentication
   
2. **Headers are sent regardless of credentials mode**: HTTP headers specified in the `headers` object are always sent, even when `credentials: 'omit'` is used.

3. **`credentials: 'omit'` works with both same-origin and cross-origin requests**: It's the most permissive mode and eliminates CORS-related authentication issues.

4. **Consistency with previous fixes**: This aligns with the fix already applied to `chat-audio-service.js` (see `FIX_SPEECH_BUTTON_403_ERROR.md`).

## Changes Made

### File: `assets/js/chat.js`

Changed **15 instances** of `credentials: 'same-origin'` to `credentials: 'omit'` across the following endpoints:

1. **Line 1625**: `transcriptsEndpoint` (POST) - Saving chat transcripts
2. **Line 1943**: `speechEndpoint` (POST) - Text-to-speech generation
3. **Line 3092**: `uploadEndpoint` (POST) - Audio file upload (voice chat)
4. **Line 3153**: `audioEndpoint` (POST) - Audio transcription
5. **Line 4765**: `deleteUrl` (DELETE) - Deleting chat history
6. **Line 5027**: History fetch (GET) - Loading chat history list
7. **Line 5100**: Conversation details (GET) - Loading specific conversation
8. **Line 5515**: `uploadEndpoint` (POST) - File upload
9. **Line 8018**: Task status (GET) - Checking async task status
10. **Line 8880**: Assistant info (GET) - Fetching assistant information
11. **Line 8941**: Job status (GET) - Checking job status
12. **Line 9282**: Test transcript (GET) - Testing transcript retrieval
13. **Line 10367**: `messagesEndpoint` (POST) - Non-streaming message sending
14. **Line 10515**: `messagesEndpoint` (POST) - Streaming message sending
15. **Line 14739**: `toolsEndpoint` (POST) - Direct tool execution

### Built Files

The following minified/bundled files were regenerated:
- `assets/js/chat.min.js` (183.8 KB minified)
- `assets/js/chat-bundle.min.js` (227.0 KB minified, bundles 9 files)
- `assets/js/chat-bundle.min.js.map` (source map)

## Testing

### Manual Testing Steps

1. Open a page with the chat widget
2. Send a message to verify message sending works
3. Upload a file to verify file upload works
4. Click the speech button on a response to verify TTS works
5. Use voice chat to verify audio transcription works
6. Save a conversation to verify transcript saving works
7. Load chat history to verify history loading works

### Expected Behavior

**Before Fix:**
- 403 Forbidden errors on various REST API calls
- Features like speech generation, tool execution, and file upload failing
- Console errors showing failed requests

**After Fix:**
- All REST API calls succeed (200 OK or appropriate status)
- All chat features work properly
- No 403 Forbidden errors in console

## Security Considerations

✅ **No security impact:**
- Authentication is still required via headers (unchanged)
- The `credentials: 'omit'` mode is **more restrictive** (doesn't send cookies)
- All existing authentication mechanisms continue to work:
  - WordPress nonce (`X-WP-Nonce`)
  - Guest tokens (`X-WP-MCP-AI-Guest`)
  - Bearer tokens (`Authorization: Bearer`)
  - Mesh API keys (`X-WP-MCP-AI-Mesh-Key`)
- No changes to server-side permission checks
- Headers are validated server-side as before

## Backward Compatibility

✅ **Fully backward compatible:**
- Only changes the client-side fetch credentials mode
- Server-side authentication logic unchanged
- Works with all existing authentication methods
- No breaking changes to any APIs or interfaces
- Existing chat instances continue to work

## Related Fixes

This fix completes the resolution of the 403 Forbidden issue across the entire chat client:

1. **FIX_403_TOOLS_ENDPOINT.md** - Backend authentication fix (added `authenticate()` method to `WP_MCP_AI_REST_Authenticator`)
2. **FIX_SPEECH_BUTTON_403_ERROR.md** - Frontend fix for `chat-audio-service.js` (changed to `credentials: 'omit'`)
3. **This fix** - Frontend fix for `chat.js` (changed to `credentials: 'omit'`)

Together, these fixes ensure:
- The backend properly authenticates all request types
- The frontend sends requests in a way that works with both same-origin and cross-origin scenarios
- All chat features work reliably regardless of how the chat widget is embedded

## References

- MDN: [Fetch API credentials](https://developer.mozilla.org/en-US/docs/Web/API/fetch#credentials)
- WordPress REST API: [Authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)
- Related fix documents:
  - `FIX_403_TOOLS_ENDPOINT.md` - Backend authentication fix
  - `FIX_SPEECH_BUTTON_403_ERROR.md` - Previous frontend fix for chat-audio-service.js

## Date

December 3, 2024
