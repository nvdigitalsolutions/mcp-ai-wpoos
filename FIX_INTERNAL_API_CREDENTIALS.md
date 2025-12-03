# Fix: Apply credentials: 'omit' to Internal REST API Calls

## Issue

After implementing the `bypass_cookie_check_for_plugin_endpoints` filter in PR #1925 to fix speech generation 403 errors, some JavaScript files were still using `credentials: 'same-origin'` when making internal REST API calls to plugin endpoints. This could cause 403 permission errors like:

```javascript
{
  code: 'wp_mcp_ai_forbidden',
  message: 'You do not have permission to view chat transcripts.',
  data: {status: 403}
}
```

## Root Cause

The issue occurred because:

1. **Recent Fix (PR #1925)**: Changed `chat-audio-service.js` to use `credentials: 'omit'` and added server-side bypass filter
2. **Remaining Issue**: Other JavaScript files (`user-chats.js`, `cron-status-service.js`) still used `credentials: 'same-origin'`
3. **Inconsistency**: Mixed authentication approaches could cause failures in certain scenarios

When using `credentials: 'same-origin'`:
- Cookies are sent with the request
- WordPress's default cookie authentication handler expects matching cookies when `X-WP-Nonce` is present
- The bypass filter helps, but consistency is better

When using `credentials: 'omit'`:
- No cookies are sent
- Authentication relies solely on headers (`X-WP-Nonce`, `X-WP-MCP-AI-Guest`, `Authorization`)
- Works consistently across all scenarios (same-origin, cross-origin, iframes, proxies)

## Solution

Updated all internal REST API calls to use `credentials: 'omit'` for consistency with the header-based authentication model.

### Files Changed

#### 1. `assets/js/user-chats.js` (3 locations)

**Line 620 - Loading chat into current view:**
```javascript
// Before
fetch(url, {
    credentials: 'same-origin',
    headers: buildHeaders()
})

// After
fetch(url, {
    credentials: 'omit',
    headers: buildHeaders()
})
```

**Line 706 - Loading individual session:**
```javascript
// Before
fetch(url, {
    credentials: 'same-origin',
    headers: buildHeaders()
})

// After
fetch(url, {
    credentials: 'omit',
    headers: buildHeaders()
})
```

**Line 769 - Loading chat list:**
```javascript
// Before
fetch(url, {
    credentials: 'same-origin',
    headers: buildHeaders()
})

// After
fetch(url, {
    credentials: 'omit',
    headers: buildHeaders()
})
```

#### 2. `assets/js/cron-status-service.js` (1 location)

**Line 81 - Fetching cron status:**
```javascript
// Before
return fetch(url, {
    method: 'GET',
    headers: headers,
    credentials: 'same-origin',
})

// After
return fetch(url, {
    method: 'GET',
    headers: headers,
    credentials: 'omit',
})
```

### Verification

All plugin JavaScript files now consistently use `credentials: 'omit'`:

- ✅ `assets/js/chat.js` - 15 occurrences (already correct)
- ✅ `assets/js/user-chats.js` - 3 occurrences (fixed)
- ✅ `assets/js/chat-audio-service.js` - 1 occurrence (already correct from PR #1925)
- ✅ `assets/js/cron-status-service.js` - 1 occurrence (fixed)

## How It Works

### Authentication Flow

```
┌─────────────────────────────────────────────────────────────┐
│ Client sends request to REST endpoint                        │
│ fetch(url, {                                                 │
│   credentials: 'omit',  // No cookies sent                  │
│   headers: { 'X-WP-Nonce': nonce }  // Auth via header      │
│ })                                                           │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ rest_authentication_errors filter (priority 5)               │
│ → bypass_cookie_check_for_plugin_endpoints()                │
│   - Checks if request is to /mcp-ai/v1/* namespace          │
│   - Returns true (authentication handled)                    │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ WordPress's default cookie check (priority 100)              │
│ → SKIPPED (filter already returned true)                    │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ REST endpoint permission_callback                            │
│ → WP_MCP_AI_REST_Authenticator::authenticate()             │
│   - Validates X-WP-Nonce header                             │
│   - Or validates bearer token (Authorization header)         │
│   - Or validates guest token (X-WP-MCP-AI-Guest header)     │
│   - Returns auth context or WP_Error                        │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Request processed if authenticated                           │
└─────────────────────────────────────────────────────────────┘
```

## Benefits

1. **Consistency**: All internal API calls use the same authentication approach
2. **Reliability**: Avoids CORS and cookie-related issues
3. **Compatibility**: Works with cross-domain embeds, iframes, and proxies
4. **Simplicity**: Single authentication model based on headers
5. **Security**: Maintains all existing authentication mechanisms

## Testing

### Manual Testing Steps

1. **Chat Transcripts (user-chats.js)**:
   - Navigate to a page with chat transcript history
   - Verify transcripts load without 403 errors
   - Click to load individual conversations
   - Verify no console errors

2. **Cron Status (cron-status-service.js)**:
   - View a page with cron status monitoring
   - Verify cron status loads without errors
   - Check browser console for any fetch failures

3. **Guest Users**:
   - Test as a logged-out user with guest token
   - Verify all API calls work correctly
   - No 403 errors in console

### Expected Behavior

- **Before Fix**: Potential 403 errors, especially in edge cases or with certain browser configurations
- **After Fix**: All API calls work consistently with header-based authentication

## Security Considerations

✅ **No security impact:**

- Authentication still required via headers
- All existing authentication mechanisms continue to work:
  - WordPress nonce (`X-WP-Nonce`)
  - Guest tokens (`X-WP-MCP-AI-Guest`)
  - Bearer tokens (`Authorization`)
- The `credentials: 'omit'` mode is actually more restrictive (doesn't send cookies)
- No changes to server-side permission checks
- The bypass filter only affects WordPress's default cookie check, not the plugin's own authentication

## Backward Compatibility

✅ **Fully backward compatible:**

- Only changes client-side fetch credentials mode
- Server-side authentication logic unchanged
- All existing authentication methods work
- No breaking changes to any APIs or interfaces

## Related Files

This fix complements the following related fixes:

- `FIX_SPEECH_BUTTON_403_ERROR.md` - Changed `chat-audio-service.js` to use `credentials: 'omit'`
- `FIX_COOKIE_CHECK_403_ERROR.md` - Added server-side `bypass_cookie_check_for_plugin_endpoints` filter
- `FIX_403_TOOLS_ENDPOINT.md` - Backend authentication fixes

## References

- MDN: [Fetch API credentials](https://developer.mozilla.org/en-US/docs/Web/API/fetch#credentials)
- WordPress REST API: [Authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)
- Related PR: #1925 - Fix speech generation 403 error

## Date

December 3, 2024
