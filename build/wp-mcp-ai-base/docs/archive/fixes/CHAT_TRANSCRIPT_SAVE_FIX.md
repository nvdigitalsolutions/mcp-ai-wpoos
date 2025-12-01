# Chat Transcript Save/Retrieve Fix

## Issue Summary

Users reported that chat transcripts appeared to save successfully via the "New Chat" button (which calls POST `/chat-transcripts`), but when attempting to retrieve the saved conversation from the history panel, they received the error:

> "The requested chat transcript could not be found."

## Root Cause

The `handle_chat_transcript_save` endpoint in `/includes/rest/class-wp-mcp-ai-rest-chat-controller.php` was calling `WP_MCP_AI_Chat_Transcript_Recorder::record()` but **not checking the return value**.

The recorder returns:
- The `session_key` string if successful
- `null` if it fails (e.g., JetEngine not available)

The save endpoint would **always return HTTP 200 with `success: true`**, even when the recorder failed and returned `null`. This created a false positive where:

1. Frontend sends POST request to save conversation
2. Recorder fails (returns null) because JetEngine handler is unavailable
3. Endpoint ignores null return and responds with success
4. Frontend thinks save succeeded
5. User clears conversation and starts a new one
6. User opens history panel and clicks on saved session
7. GET request to retrieve session finds no database records
8. Returns 404 "transcript not found" error

## Solution

Modified `handle_chat_transcript_save` to:

1. **Capture the return value** from `recorder::record()`
2. **Check if it's null**
3. **Return WP_Error with HTTP 500** if recorder failed
4. **Log the failure** with context for debugging
5. **Return the session_key from recorder** in success response

```php
// Before (buggy code):
if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
    WP_MCP_AI_Chat_Transcript_Recorder::record(
        $assistant_id,
        $clean_messages,
        array( 'model' => $model ),
        $response,
        $request,
        $user_id,
        $context
    );
}

return rest_ensure_response(
    array(
        'success'     => true,
        'session_key' => $session_key,
        'message'     => __( 'Transcript saved successfully.', 'wp-mcp-ai' ),
    )
);

// After (fixed code):
$recorded_session_key = null;
if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
    $recorded_session_key = WP_MCP_AI_Chat_Transcript_Recorder::record(
        $assistant_id,
        $clean_messages,
        array( 'model' => $model ),
        $response,
        $request,
        $user_id,
        $context
    );
}

// Check if recording failed.
if ( null === $recorded_session_key ) {
    WP_MCP_AI_Logger::log_event(
        'error',
        'handle_chat_transcript_save: Failed to save transcript',
        array(
            'session_key'   => $session_key,
            'assistant_id'  => $assistant_id,
            'user_id'       => $user_id,
            'message_count' => count( $clean_messages ),
            'reason'        => 'Recorder returned null',
        )
    );

    return new WP_Error(
        'wp_mcp_ai_transcript_save_failed',
        __( 'Failed to save transcript. Please ensure JetEngine Custom Content Types is active and properly configured.', 'wp-mcp-ai' ),
        array( 'status' => 500 )
    );
}

return rest_ensure_response(
    array(
        'success'     => true,
        'session_key' => $recorded_session_key,
        'message'     => __( 'Transcript saved successfully.', 'wp-mcp-ai' ),
    )
);
```

## Why the Recorder Might Fail

The recorder returns `null` in these cases:

1. **JetEngine not active**: If the JetEngine plugin is not installed or activated
2. **No handler**: The `wp_mcp_ai_chat_transcript_handler` filter returns null/empty
3. **CCT not configured**: JetEngine Custom Content Types module is not properly set up
4. **should_record returns false**: The `wp_mcp_ai_save_chat_transcript` filter returns false
5. **update_item fails**: JetEngine's `update_item()` returns WP_Error

## Impact

### Before Fix
- ❌ Silent failures - users think saves succeeded when they didn't
- ❌ Confusion - "saved" conversations not appearing in history
- ❌ Difficult debugging - no error logs or messages
- ❌ False positives - endpoint returns success when it fails

### After Fix
- ✅ Accurate feedback - frontend knows if save actually succeeded
- ✅ Clear error messages - users informed about JetEngine requirement
- ✅ Better logging - failures are logged with context
- ✅ Easier debugging - error response includes helpful information
- ✅ No false positives - endpoint only returns success when it truly succeeds

## Testing

Created two comprehensive test files:

1. **`tests/test-chat-transcript-save-retrieve-cycle.php`**
   - Tests successful save and immediate retrieve
   - Uses mock handler to avoid JetEngine dependency
   - Verifies saved messages can be retrieved with correct session_key
   - Validates message content is preserved

2. **`tests/test-chat-transcript-save-without-jetengine.php`**
   - Tests error handling when JetEngine is unavailable
   - Verifies 500 error is returned when recorder fails
   - Validates error message mentions JetEngine configuration
   - Confirms success vs failure response structures

## User Experience Improvement

### Before
```
User: *clicks "New Chat"*
Browser: ✓ Conversation saved successfully to CCT
User: *clicks on session in history panel*
Browser: ✗ Error: The requested chat transcript could not be found
User: 🤔 "What? It just said it saved!"
```

### After
```
User: *clicks "New Chat"*
Browser: ✗ Failed to save conversation. Please ensure JetEngine Custom Content Types is active and properly configured.
User: 💡 "Ah, I need to install JetEngine!"

-- OR --

User: *clicks "New Chat"* (with JetEngine active)
Browser: ✓ Conversation saved successfully to CCT
User: *clicks on session in history panel*
Browser: ✓ *loads conversation*
User: 😊 "Perfect!"
```

## Deployment Checklist

For site admins who experience this issue:

- [ ] Ensure **JetEngine** plugin is installed and activated
- [ ] Verify **Custom Content Types** module is enabled in JetEngine
- [ ] Check that `/wp-json/jet-cct/ai_chat_transcripts` endpoint exists
- [ ] Test saving a conversation via "New Chat" button
- [ ] Verify saved conversation appears in history panel
- [ ] Test loading a saved conversation from history

## Related Files

- **Modified**: `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` (lines 612-665)
- **Added**: `tests/test-chat-transcript-save-retrieve-cycle.php`
- **Added**: `tests/test-chat-transcript-save-without-jetengine.php`

## Security Considerations

- No new security vulnerabilities introduced (CodeQL analysis clean)
- Error messages don't expose sensitive information
- User authentication/authorization still enforced via existing permission checks
- Input validation unchanged (still using existing sanitization)

## Backward Compatibility

- ✅ No breaking changes to API contract
- ✅ Success response structure unchanged (added recorder session_key)
- ✅ Existing code relying on save endpoint continues to work
- ✅ Only difference: failures now return error instead of false success

## Future Enhancements

Potential improvements for future PRs:

1. Add retry logic for transient JetEngine failures
2. Implement fallback storage mechanism when JetEngine unavailable
3. Add admin notice if JetEngine not configured
4. Create setup wizard for JetEngine CCT configuration
5. Add health check endpoint for transcript storage status
