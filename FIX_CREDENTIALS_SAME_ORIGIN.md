# Fix: Use credentials: 'same-origin' for WordPress Nonce Authentication

## Issue

After the changes in PR #1926 that switched all REST API calls to use `credentials: 'omit'`, the chat functionality and other features began failing with 401 Unauthorized errors:

```
chat.js:215 [WP oOS] HTTP error response: {status: 401, statusText: '', url: 'https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/chat-client'}
chat.js:245 [WP oOS] Server response text: {"code":"rest_invalid_nonce","message":"Could not verify the request nonce.","actions":{"refresh_nonce":"Refresh your WordPress session to obtain a fresh nonce and retry the request."},"data":{"status":401}}
```

This affected:
- Chat messaging functionality
- Speech generation button
- Chat transcript loading
- Any other nonce-authenticated REST API calls

## Root Cause

The problem occurred due to a misunderstanding of how WordPress nonces work:

### WordPress Nonce Validation Process

WordPress's `wp_verify_nonce()` function performs these steps:

1. Calls `wp_get_session_token()` to retrieve the user's session token
2. `wp_get_session_token()` reads from the `$_COOKIE` array (specifically `wordpress_logged_in_*` cookie)
3. Constructs a hash from: `nonce_tick|action|user_id|session_token`
4. Compares the hash against the provided nonce value

**Critical Point**: Without cookies, `wp_get_session_token()` returns an empty string, causing the hash to mismatch and nonce validation to FAIL.

### The Misunderstanding

PR #1926 and the associated documentation (`FIX_SPEECH_BUTTON_403_ERROR.md`, `FIX_INTERNAL_API_CREDENTIALS.md`) incorrectly stated:

> **Why this works:**
> - Authentication is done via headers (`X-WP-Nonce`, `X-WP-MCP-AI-Guest`), not cookies
> - Headers are sent regardless of credentials mode
> - `credentials: 'omit'` works with both same-origin and cross-origin requests

This is **INCORRECT** for WordPress nonce authentication. While the nonce is sent via header, **WordPress requires cookies to validate that nonce**.

### What Actually Happened

```javascript
// Client-side (credentials: 'omit')
fetch(endpoint, {
    headers: { 'X-WP-Nonce': nonce },
    credentials: 'omit'  // ← No cookies sent!
})

// Server-side WordPress
wp_verify_nonce($nonce, 'wp_rest') {
    $session_token = wp_get_session_token(); // Returns empty (no cookies!)
    $expected = hash(..., $user_id, $session_token); // Wrong hash
    return $nonce === $expected; // FALSE - validation fails
}
```

## Solution

Changed `credentials: 'omit'` back to `credentials: 'same-origin'` for all same-origin WordPress REST API calls.

### Files Changed

#### 1. `assets/js/chat.js` (15 locations)

All fetch calls to WordPress REST API endpoints:
- `/chat-client` - Chat messaging
- `/chat-transcripts` - Transcript save/load
- `/tools` - Tool execution
- `/speech` - Text-to-speech
- `/audio` - Audio transcription
- Crawl4AI endpoints

**Change:**
```javascript
// Before (incorrect)
fetch(state.config.messagesEndpoint, {
    method: 'POST',
    headers: buildJsonHeaders(state),
    credentials: 'omit',  // ← WRONG
    body: JSON.stringify(payload),
})

// After (correct)
fetch(state.config.messagesEndpoint, {
    method: 'POST',
    headers: buildJsonHeaders(state),
    credentials: 'same-origin',  // ✓ Sends cookies for nonce validation
    body: JSON.stringify(payload),
})
```

#### 2. `assets/js/user-chats.js` (3 locations)

Chat transcript history operations:
- Load chat into current view (line 620)
- Load individual session (line 706)
- Load chat list (line 769)

**Change:** Same as above - `'omit'` → `'same-origin'`

#### 3. `assets/js/chat-audio-service.js` (1 location)

Speech generation tool calls (line 349):

**Change:** Same as above - `'omit'` → `'same-origin'`

#### 4. `assets/js/cron-status-service.js` (1 location)

Cron status fetching (line 81):

**Change:** Same as above - `'omit'` → `'same-origin'`

## How It Works Now

### Authentication Flow with credentials: 'same-origin'

```
┌─────────────────────────────────────────────────────────────┐
│ Client sends request to same-origin REST endpoint           │
│ fetch(url, {                                                 │
│   credentials: 'same-origin',  // Cookies sent              │
│   headers: { 'X-WP-Nonce': nonce }                          │
│ })                                                           │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Browser sends request with:                                  │
│ - X-WP-Nonce header                                          │
│ - wordpress_logged_in_* cookie ✓                            │
│ - Other WordPress cookies ✓                                  │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ rest_authentication_errors filter (priority 5)               │
│ → bypass_cookie_check_for_plugin_endpoints()                │
│   - Returns true to skip WordPress default cookie check     │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ REST endpoint permission_callback                            │
│ → permissions_check()                                        │
│   - Calls wp_verify_nonce($nonce, 'wp_rest')               │
│   - wp_verify_nonce() reads session from cookies ✓          │
│   - Nonce validates successfully ✓                           │
│   - User is authenticated ✓                                  │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Request processed successfully                               │
└─────────────────────────────────────────────────────────────┘
```

## Why credentials: 'same-origin' is Correct

### For WordPress Nonce Authentication (Logged-in Users)

- ✅ **Sends cookies** - Required for `wp_get_session_token()`
- ✅ **Sends nonce header** - Provides the nonce value
- ✅ **Same-origin requests** - Works with WordPress REST API on same domain
- ✅ **WordPress standard** - This is how WordPress REST API is designed to work

### For Guest Token Authentication (Public Chat)

- ✅ **Still works** - Guest tokens don't require cookies
- ✅ **Backward compatible** - Sending cookies doesn't break guest auth
- ✅ **No negative impact** - Cookies are simply ignored when guest token is used

### Security Considerations

- ✅ **No security regression** - Same security as before PR #1926
- ✅ **CSRF protection maintained** - Nonces work properly with cookies
- ✅ **Session validation** - User sessions properly validated
- ✅ **Same-origin policy** - Only sends cookies to same origin

## When to Use credentials: 'omit'

The `credentials: 'omit'` mode should ONLY be used for:

1. **Cross-origin requests** - When calling external APIs
2. **Bearer token ONLY auth** - When using bearer tokens without nonces
3. **Guest token ONLY scenarios** - When NO nonce is involved

For WordPress REST API endpoints that support nonce authentication, **ALWAYS use `credentials: 'same-origin'`**.

## Related Documentation (Now Incorrect)

The following documents contain incorrect information about using `credentials: 'omit'` and should be considered superseded by this document:

- ❌ `FIX_INTERNAL_API_CREDENTIALS.md` - Incorrectly recommends `credentials: 'omit'`
- ❌ `FIX_SPEECH_BUTTON_403_ERROR.md` - Incorrectly claims nonces work without cookies
- ❌ `FIX_COOKIE_CHECK_403_ERROR.md` - Describes bypass filter but doesn't explain that `wp_verify_nonce()` still needs cookies

## Testing

### Manual Testing Steps

1. **Chat Functionality (Logged-in User)**:
   - Log in to WordPress
   - Open a page with a chat interface
   - Send a message
   - ✓ Message should send successfully (no 401 errors)
   - Check browser console for errors

2. **Speech Button (Logged-in User)**:
   - Log in to WordPress  
   - Send a message and get AI response
   - Click the speech/play button on the response
   - ✓ Text-to-speech should work (no 403/401 errors)

3. **Guest Chat (Public)**:
   - Log out or use incognito mode
   - Open a page with guest chat enabled
   - Send a message
   - ✓ Message should send successfully (using guest token)

4. **Chat Transcripts (Logged-in User)**:
   - Log in to WordPress
   - Navigate to chat history
   - Click to load a previous conversation
   - ✓ Transcript should load (no 401 errors)

### Expected Behavior

- **Before Fix**: 401 "rest_invalid_nonce" errors for logged-in users
- **After Fix**: All requests work correctly with proper authentication

## Conclusion

WordPress nonce authentication **requires cookies** and cannot work with `credentials: 'omit'`. For same-origin WordPress REST API calls:

- ✅ Use `credentials: 'same-origin'`
- ❌ Do NOT use `credentials: 'omit'`

This is the WordPress standard and the correct approach for nonce-based authentication.

## Date

December 3, 2025
