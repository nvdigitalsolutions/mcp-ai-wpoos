# History Toggle Icon Fix Documentation

## Issue Summary

When users saved a conversation to CCT (Custom Content Type) and immediately clicked the history toggle icon to view saved conversations, they would encounter a 404 error when trying to load a specific conversation's details.

## Root Cause

The `wp-mcp-ai-chat__history-toggle-icon` was configured to automatically fetch conversation history whenever the history panel was expanded. This created a race condition:

1. User clicks "New Chat" button
2. Current conversation is saved to CCT database
3. Conversation is cleared and new session key generated
4. User clicks history toggle icon
5. **Auto-fetch** loads the conversation list (succeeds because save completed)
6. User clicks on a recently saved conversation
7. Fetch for conversation details returns 404 (race condition - database might not be fully committed/indexed)

## Solution

### 1. Removed Auto-Fetch Behavior

**File:** `assets/js/chat.js`
**Function:** `setHistoryVisibility(state, visible)`

**Before:**
```javascript
function setHistoryVisibility(state, visible) {
    // ...
    updateHistoryToggle(state);
    
    if (state.historyVisible) {
        ensureHistorySessions(state);  // AUTO-FETCH
    }
}
```

**After:**
```javascript
function setHistoryVisibility(state, visible) {
    // ...
    updateHistoryToggle(state);
    
    // Don't auto-fetch when expanding - let users manually refresh via refresh button
    // This avoids race conditions when history is opened immediately after saving
}
```

**Impact:**
- History toggle icon now only expands/collapses the panel
- Users control when to load conversation history via the refresh button
- Eliminates race condition on auto-fetch
- Reduces unnecessary API calls

### 2. Added Retry Logic for 404 Errors

**File:** `assets/js/chat.js`
**Function:** `fetchHistorySessionDetails(state, sessionKey, retryCount)`

**Changes:**
- Added `retryCount` parameter to track retry attempts
- Implements up to 2 retries (3 total attempts) for 404 errors
- 500ms delay between retries
- Enhanced logging to track retry attempts

**Logic:**
```javascript
// Mark 404 errors as potentially retryable (timing issues)
error.retryable = response.status === 404 && attempt < maxRetries;

// Retry with delay if retryable
if (error.retryable && attempt < maxRetries) {
    return new Promise(function(resolve) {
        setTimeout(function() {
            resolve(fetchHistorySessionDetails(state, sessionKey, attempt + 1));
        }, retryDelay);
    });
}
```

**Impact:**
- Handles transient database lag/replication delays
- Provides resilience for race conditions
- Better user experience - fewer errors
- Detailed logging for debugging

## User Experience Changes

### Before Fix
1. Click history toggle → Panel opens + Auto-loads conversations
2. Click on a conversation → May get 404 error
3. User sees error message and must refresh manually

### After Fix
1. Click history toggle → Panel opens (no auto-load)
2. Click refresh button → Loads conversation list
3. Click on a conversation → Loads with retry logic (resilient to timing issues)
4. Success or helpful error message

## Migration Notes

**No Breaking Changes:** The fix is backward compatible. Users just need to click the refresh button when they open the history panel.

**UI Consideration:** Consider adding a message in the history panel like "Click the refresh button to load your conversations" if the panel is empty and history hasn't been loaded yet.

## Testing

### Manual Testing Steps

1. **Test History Toggle:**
   - Click history toggle icon
   - Verify panel expands but doesn't fetch data
   - Click toggle again to collapse
   - No network requests should be made

2. **Test Manual Refresh:**
   - Expand history panel
   - Click refresh button
   - Verify conversations load successfully
   - Check browser console for loading logs

3. **Test Conversation Loading:**
   - Save a conversation
   - Click "New Chat"
   - Expand history panel
   - Click refresh button
   - Click on the recently saved conversation
   - Verify it loads successfully (may retry if needed)

4. **Test Retry Logic:**
   - Use browser DevTools Network tab to throttle/delay responses
   - Try loading a conversation
   - Verify retry attempts in console logs
   - Verify eventual success or clear error message

### Expected Console Logs

```javascript
// On history toggle (no fetch):
// (No logs - just UI update)

// On refresh button click:
[WP oOS] Loading conversation history: {...}

// On conversation click:
[WP oOS] Loading conversation details: {attempt: 1, max_attempts: 3, ...}
[WP oOS] Conversation details response: {status: 200, ok: true, attempt: 1}
[WP oOS] Conversation details loaded successfully: {attempt: 1, message_count: 5}

// On 404 with retry:
[WP oOS] Loading conversation details: {attempt: 1, max_attempts: 3, ...}
[WP oOS] Conversation details response: {status: 404, ok: false, attempt: 1}
[WP oOS] Error fetching conversation details: {attempt: 1, retryable: true}
[WP oOS] Retrying conversation details fetch after delay: {delay_ms: 500, next_attempt: 2}
[WP oOS] Loading conversation details: {attempt: 2, max_attempts: 3, ...}
[WP oOS] Conversation details response: {status: 200, ok: true, attempt: 2}
[WP oOS] Conversation details loaded successfully: {attempt: 2, message_count: 5}
```

## Related Files

- `assets/js/chat.js` - Main changes
- `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` - REST endpoint for transcript retrieval
- `includes/class-wp-mcp-ai-rest.php` - get_transcript_session method
- `includes/repositories/class-wp-mcp-ai-transcript-repository.php` - Database queries

## Future Enhancements

1. **Empty State Message:** Show a helpful message in the history panel when it's empty
2. **Loading Indicator:** Add a loading spinner when refresh is clicked
3. **Optimistic UI:** Show the just-saved conversation immediately in the list
4. **Debounce Refresh:** Prevent rapid refresh button clicks
5. **Keyboard Shortcuts:** Add Ctrl+R or similar to refresh history

## Rollback Plan

If this fix causes issues, revert the changes to `setHistoryVisibility`:

```javascript
function setHistoryVisibility(state, visible) {
    // ... (keep existing code)
    updateHistoryToggle(state);
    
    if (state.historyVisible) {
        ensureHistorySessions(state);  // Restore auto-fetch
    }
}
```

And remove the `retryCount` parameter from `fetchHistorySessionDetails`.

## Support

For issues or questions about this fix:
- Check the GitHub issue tracker
- Review console logs for retry attempts
- Enable debug logging in WordPress (WP_DEBUG)
- Check JetEngine CCT configuration
