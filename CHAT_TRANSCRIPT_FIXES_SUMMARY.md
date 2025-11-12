# Chat Transcript Fixes - Summary

## Overview

This PR addresses two critical issues with chat transcript functionality:

1. **Error retrieving chat transcripts** via `/wp-json/mcp-ai/v1/chat-transcripts?session_key=...`
2. **Previous conversations clearing** when starting a new chat, resulting in data loss

## Problems Identified

### Problem 1: Session Key Length Inconsistency

**Issue**: The POST endpoint accepted session keys up to 100 characters, while the GET endpoint and database storage limited keys to 96 characters (from `WP_MCP_AI_Chat_Transcript_Recorder::MAX_SESSION_KEY_LENGTH`).

**Impact**: Session keys between 96-100 characters would save successfully but fail to retrieve, causing "transcript not found" errors.

**Solution**: Standardized both endpoints to use the same `MAX_SESSION_KEY_LENGTH` constant (96 characters).

### Problem 2: Silent Save Failures Leading to Data Loss

**Issue**: When starting a new conversation, the current conversation is saved to CCT (Custom Content Types) before clearing localStorage. However, if the save failed (e.g., JetEngine not active, network error, permission issue), the error was silently swallowed and the conversation was cleared anyway, resulting in permanent data loss.

**Impact**: Users would lose their conversations without any warning or opportunity to retry.

**Solution**: 
- Modified `saveConversationToCCT()` to return a success/failure status object
- Updated `startNewConversation()` to check the save result
- If save fails, prompt user with the error and ask whether to proceed or keep the conversation
- Display success/failure messages to provide feedback

### Problem 3: Limited Error Diagnostics

**Issue**: When transcript retrieval failed, there was minimal logging to help diagnose the root cause.

**Impact**: Difficult to troubleshoot common issues like:
- User not logged in
- JetEngine not installed
- Session not found in database
- Permission issues

**Solution**: Added comprehensive debug logging at key points in the retrieval flow.

## Files Changed

### Backend (PHP)

#### `includes/rest/class-wp-mcp-ai-rest-validator.php`
- **Lines 680-693**: Updated `sanitize_session_key_param()` to use `MAX_SESSION_KEY_LENGTH` instead of hard-coded 100
- Ensures consistency between POST and GET endpoints
- Preserves UUID session keys correctly (e.g., `d28ff0cd-0d82-4d6d-b733-cc318b71ac9a`)

#### `includes/class-wp-mcp-ai-rest.php`
- **Lines 939-954**: Added logging when no user ID is available
- **Lines 955-967**: Added logging of request parameters (session_key, user_id, assistant_id)
- **Lines 968-981**: Added logging of errors during session retrieval
- Improved error message clarity for users not logged in

### Frontend (JavaScript)

#### `assets/js/chat.js`
- **Lines 170-219**: Rewrote `saveConversationToCCT()` to return status object `{success: boolean, error?: string, skipped?: boolean}`
- **Lines 1643-1690**: Enhanced `startNewConversation()` to handle save failures:
  - Check save result before clearing conversation
  - Show success message when save succeeds
  - Prompt user when save fails with option to proceed or cancel
  - Keep conversation if user cancels
  - Display informative status messages

### Tests

#### `tests/test-session-key-normalization.php` (NEW)
Comprehensive test suite with 10 test cases:
- UUID preservation test
- Invalid character removal test
- Length limit enforcement test
- POST/GET consistency test
- Various UUID format tests
- Allowed character tests (hyphens, underscores)
- Empty/null value handling
- Numeric session key handling

### Documentation

#### `docs/chat-transcript-troubleshooting.md` (NEW)
Complete troubleshooting guide covering:
- Common error scenarios and solutions
- Debugging steps with code examples
- Configuration requirements
- Error message reference table
- Best practices for avoiding data loss
- Testing instructions

## Technical Details

### Session Key Normalization

Both POST and GET endpoints now use the same regex pattern:
```php
preg_replace( '/[^a-zA-Z0-9_-]/', '', $key )
```

This allows:
- Letters: `a-z`, `A-Z`
- Numbers: `0-9`
- Underscore: `_`
- Hyphen: `-`

Maximum length: 96 characters (consistent with database storage)

### Error Handling Flow

**Before (Broken)**:
```
User clicks "Start new conversation"
→ Confirm dialog
→ saveConversationToCCT() (may fail silently)
→ performConversationClear() (always executes)
→ Conversation lost if save failed
```

**After (Fixed)**:
```
User clicks "Start new conversation"
→ Confirm dialog
→ saveConversationToCCT()
  ├─ Success → performConversationClear() → New conversation starts
  ├─ Skipped (no messages) → performConversationClear() → New conversation starts
  └─ Failed → Show error → User chooses:
      ├─ Proceed anyway → performConversationClear() → Conversation lost (user's choice)
      └─ Cancel → Keep conversation → Can try again later
```

### Logging Added

**Request logging**:
```php
WP_MCP_AI_Logger::log_event(
    'debug',
    'handle_chat_transcripts: Request parameters',
    array(
        'raw_session_key'        => $request->get_param( 'session_key' ),
        'normalized_session_key' => $session_key,
        'user_id'                => $user_id,
        'assistant_id'           => $assistant_id,
    )
);
```

**Error logging**:
```php
WP_MCP_AI_Logger::log_event(
    'debug',
    'handle_chat_transcripts: Error retrieving session',
    array(
        'error_code'    => $session->get_error_code(),
        'error_message' => $session->get_error_message(),
        'session_key'   => $session_key,
        'user_id'       => $user_id,
    )
);
```

## Testing

### Automated Tests

Run the new test suite:
```bash
vendor/bin/phpunit tests/test-session-key-normalization.php
```

Expected: All 10 tests pass, confirming:
- UUID session keys are preserved
- Session keys are truncated to 96 chars
- Invalid characters are removed
- Empty/null values are handled
- Both POST and GET use consistent limits

### Manual Testing

1. **Test save failure handling**:
   - Temporarily deactivate JetEngine
   - Start a conversation with multiple messages
   - Click "Start new conversation"
   - Expected: Error prompt asking whether to proceed
   - Cancel the prompt
   - Expected: Conversation is preserved

2. **Test successful save**:
   - Ensure JetEngine is active
   - Start a conversation
   - Click "Start new conversation"
   - Expected: Success message, then new conversation starts
   - Open "Show previous conversations"
   - Expected: Previous conversation appears in list

3. **Test UUID session keys**:
   - Enable debug logging
   - Start a chat (generates UUID session key)
   - Add messages
   - Save conversation
   - Retrieve via API with the UUID session key
   - Expected: Conversation retrieved successfully

## Error Message Improvements

### Before
```
"A valid user is required to query chat transcripts."
```

### After
```
"A valid user is required to query chat transcripts. Please log in to view your chat history."
```

More actionable and user-friendly.

## Backward Compatibility

✅ **Fully backward compatible**

- Existing session keys continue to work
- Database schema unchanged
- API endpoint signatures unchanged
- Only adds new functionality (error handling) and fixes bugs

## Security Considerations

✅ **No security issues introduced**

- Session key sanitization still enforced (removes dangerous characters)
- User authentication still required for transcript access
- No new capabilities or permissions added
- Logging does not expose sensitive data

## Performance Impact

✅ **Minimal to negligible**

- Added logging only occurs when debug mode is enabled
- Promise handling in JavaScript adds negligible overhead
- No additional database queries
- No change to caching behavior

## Dependencies

**No new dependencies added**

All fixes use existing:
- WordPress REST API
- WP_MCP_AI_Logger (existing)
- WP_MCP_AI_Chat_Transcript_Recorder (existing)
- Browser fetch API (existing)

## Deployment Notes

1. **Enable logging** to monitor for issues:
   ```php
   define( 'WP_MCP_AI_DEBUG', true );
   ```

2. **JetEngine required** for full functionality:
   - Without JetEngine, transcripts only stored in browser localStorage
   - localStorage expires after 24 hours
   - Users should be informed about this limitation

3. **No database migrations needed**

4. **Clear browser cache** recommended after update to ensure new JavaScript is loaded

## Known Limitations

1. **localStorage limit**: Each assistant stores only ONE active conversation in browser localStorage. Previous conversations must be retrieved from CCT.

2. **JetEngine dependency**: Full transcript persistence requires JetEngine CCT. Without it:
   - Conversations saved to localStorage only
   - 24-hour expiry
   - Lost if browser data is cleared

3. **Session key length**: Maximum 96 characters. Longer keys are truncated (rare edge case).

## Future Improvements (Out of Scope)

- Add option to store multiple conversations in localStorage
- Implement fallback storage mechanism when JetEngine not available
- Add batch transcript retrieval for performance
- Add transcript export functionality
- Add search/filter capabilities for transcript history

## Checklist

- [x] Code changes implemented
- [x] Tests created and passing
- [x] Documentation updated
- [x] No syntax errors
- [x] Backward compatible
- [x] No security issues
- [x] Minimal performance impact
- [x] User-facing error messages improved
- [x] Debug logging added
- [ ] Tested in live WordPress environment
- [ ] User confirmation that issues are resolved

## References

- **Issue**: Error retrieving chat transcripts + previous conversations clearing
- **Files Changed**: 4 files modified, 2 files created
- **Lines Changed**: ~150 lines added/modified
- **Tests Added**: 10 new test cases
