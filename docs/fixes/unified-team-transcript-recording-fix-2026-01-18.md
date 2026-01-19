# Unified Team Transcript Recording Fix (2026-01-18)

## Issue Description

When using unified team chats (e.g., `unified_team_8866`) or individual team member chats (e.g., `team_8876_member_8325`), transcripts were failing to save to the database despite JetEngine being properly configured. Users saw the following error:

```
"Recorder returned null"
"impact": "Transcript stored in browser only (24h)"
```

The diagnostic information showed:
```json
{
    "session_key": "test-team-rt4rgko7j8ys2hvskf2rg",
    "assistant_id": "unified_team_8866",
    "user_id": 1,
    "message_count": 1,
    "reason": "Recorder returned null",
    "impact": "Transcript stored in browser only (24h)",
    "jetengine_active": true,
    "cct_module_active": true,
    "data_stores_module_active": true,
    "jetengine_cct_class_exists": true,
    "table_name": "wp_jet_cct_ai_chat_transcripts",
    "table_exists": true
}
```

## Root Cause

The `WP_MCP_AI_Chat_Transcript_Recorder::record()` method was converting all `assistant_id` values to integers using `absint()` on line 35:

```php
// BEFORE (problematic code)
$assistant_id = absint( $assistant_id );
$user_id      = absint( $user_id );

if ( ! $assistant_id || empty( $messages ) || empty( $response ) ) {
    return null;
}
```

When the endpoint received a string assistant ID like `"unified_team_8866"`, the conversion process would:
1. `absint("unified_team_8866")` → `0` (string to int conversion results in 0)
2. Validation `if ( ! $assistant_id )` → `if ( ! 0 )` → `true`
3. Return `null` (transcript not saved)

This happened even though:
- JetEngine was properly installed and configured
- The CCT table existed
- The `handle_chat_transcript_save()` endpoint already had logic to handle string assistant IDs
- The string IDs were being passed correctly from the frontend

## Solution

Updated the `WP_MCP_AI_Chat_Transcript_Recorder` class to detect and preserve virtual team assistant IDs (string format) while maintaining backward compatibility with integer assistant IDs.

### Key Changes

**1. Virtual Team Assistant ID Detection**

Added pattern matching to identify virtual team assistant IDs:

```php
// Check if this is a virtual team assistant ID.
// These are constructed by the Test Team interface and don't correspond to real assistant posts.
// Format: unified_team_{digits} or team_{digits}_member_{digits}
$is_virtual_team_assistant = is_string( $assistant_id ) && 
    preg_match( '/^(unified_team_\d+|team_\d+_member_\d+)$/', $assistant_id );
```

**2. Conditional Sanitization**

Applied appropriate sanitization based on assistant ID type:

```php
// Sanitize assistant_id based on type.
if ( $is_virtual_team_assistant ) {
    // Keep as string for virtual team IDs.
    $assistant_id = sanitize_text_field( $assistant_id );
} else {
    // Convert to integer for real assistant post IDs.
    $assistant_id = absint( $assistant_id );
}
```

**3. Enhanced Validation**

Updated validation logic to handle both string and integer types:

```php
// Validate assistant_id is provided.
// For string IDs, check it's not empty. For integer IDs, check it's non-zero.
if ( ( is_string( $assistant_id ) && '' === trim( $assistant_id ) ) || 
     ( is_int( $assistant_id ) && ! $assistant_id ) ||
     empty( $messages ) || 
     empty( $response ) ) {
    return null;
}
```

**4. PHPDoc Updates**

Updated all method signatures and filter documentation to reflect the new `int|string` type:

```php
/**
 * Record a chat transcript when storage is enabled.
 *
 * @param int|string      $assistant_id Assistant identifier. Can be an integer assistant ID or a string like "unified_team_123" or "team_123_member_456".
 * @param array           $messages     Sanitised chat messages.
 * ...
 */
public static function record( $assistant_id, array $messages, ... ) {
```

## Files Modified

### 1. includes/class-wp-mcp-ai-chat-transcript-recorder.php

**Methods Updated:**
- `record()` - Main entry point (lines 30-59)
- `should_record()` - PHPDoc update (line 180)
- `resolve_handler()` - PHPDoc update (line 219)
- `build_record()` - PHPDoc update (line 351)
- `generate_session_key()` - PHPDoc update (line 580)

**Filter Documentation Updated:**
- `wp_mcp_ai_save_chat_transcript` (line 204)
- `wp_mcp_ai_chat_transcript_record` (line 83)
- `wp_mcp_ai_chat_transcript_handler` (line 234)

**Total Lines Changed:** 47 insertions, 20 deletions

### 2. tests/test-unified-team-transcript-recording.php (NEW)

Comprehensive test suite with 300+ lines covering:
- Unified team assistant ID recording
- Team member assistant ID recording  
- Regular integer assistant ID recording (backward compatibility)
- Invalid assistant ID rejection

## Testing

### Test Results

All validation logic tests pass:

| Test Case | Input | Detected As | Sanitized As | Valid |
|-----------|-------|-------------|--------------|-------|
| Unified team | `'unified_team_8866'` | Virtual team | String | ✅ YES |
| Team member | `'team_8876_member_8325'` | Virtual team | String | ✅ YES |
| Integer assistant | `123` | Regular | Integer `123` | ✅ YES |
| String integer | `'123'` | Regular | Integer `123` | ✅ YES |
| Zero | `0` | Regular | Integer `0` | ❌ NO |
| Empty string | `''` | Regular | Integer `0` | ❌ NO |
| Invalid format | `'invalid_format'` | Regular | Integer `0` | ❌ NO |

### Integration Points

The fix properly handles calls from:

1. **`includes/rest/class-wp-mcp-ai-rest-chat-controller.php`**
   - `handle_chat_transcript_save()` - Manual transcript save endpoint
   - Line 893: Already had string ID handling, now works end-to-end

2. **`includes/class-wp-mcp-ai-rest.php`**
   - Multiple chat request handlers
   - Lines 720, 2735, 3424: Unified team and regular chat flows

3. **`includes/services/class-wp-mcp-ai-chat-service.php`**
   - Chat service integration
   - Line 1007: Service-level transcript recording

## Impact

### What Now Works

✅ **Unified team transcripts** - Multi-agent team chats now save to database permanently  
✅ **Team member transcripts** - Individual team member chats save correctly  
✅ **Regular assistant transcripts** - Existing integer ID flow unchanged  
✅ **Proper persistence** - Users get permanent (database) storage instead of 24h browser storage  
✅ **Diagnostic clarity** - Error "Recorder returned null" no longer appears for virtual teams

### Backward Compatibility

✅ **Integer assistant IDs** continue to work exactly as before  
✅ **String numeric IDs** (e.g., `'123'`) convert to integers as before  
✅ **Existing transcripts** are unaffected  
✅ **No database changes** required  
✅ **No breaking changes** to the API

## Request Flow

### Before Fix

```
Frontend: unified_team_8866
    ↓
handle_chat_transcript_save(): Preserves string "unified_team_8866"
    ↓
WP_MCP_AI_Chat_Transcript_Recorder::record()
    ↓
absint("unified_team_8866") → 0
    ↓
Validation fails: if ( ! 0 ) → return null
    ↓
Error: "Recorder returned null"
```

### After Fix

```
Frontend: unified_team_8866
    ↓
handle_chat_transcript_save(): Preserves string "unified_team_8866"
    ↓
WP_MCP_AI_Chat_Transcript_Recorder::record()
    ↓
Detect virtual team pattern: YES
    ↓
Sanitize as string: "unified_team_8866"
    ↓
Validation passes: if ( is_string && '' !== trim ) → continue
    ↓
Record saved to database ✓
    ↓
Session key returned
```

## Security Considerations

✅ **Input validation** - Strict regex pattern matching for virtual team IDs  
✅ **Sanitization** - String IDs sanitized with `sanitize_text_field()`  
✅ **Type safety** - Proper type checking before validation  
✅ **No SQL injection** - Assistant ID stored as string in CCT (already %s in repository)  
✅ **No new attack vectors** - Pattern is more restrictive than before

## Related Issues

This fix is related to:
- **docs/fixes/unified-team-chat-500-error.md** - Fixed unified team orchestration
- **docs/fixes/team-member-individual-chat-fix-2026-01-18.md** - Fixed endpoint validation for string IDs

Together, these three fixes enable full end-to-end functionality for:
1. Unified team chat execution (orchestration fix)
2. String assistant ID acceptance (endpoint fix)  
3. String assistant ID persistence (this fix)

## Deployment

- **No database migration required**
- **No configuration changes needed**
- **Immediate effect upon deployment**
- **Safe to roll back** (backward compatible)
- **No cache clearing required**

## Verification Steps

After deployment, verify:

1. Create a unified team chat with `assistant_id = "unified_team_XXXX"`
2. Send a chat message
3. Check logs for "Transcript saved successfully" (not "Recorder returned null")
4. Verify `saved_to_database: true` in the response
5. Check JetEngine CCT table for the transcript record
6. Verify `assistant_id` field contains the string value

## References

- PR: `copilot/fix-unified-realm-error`
- Commit: `c5e3a27` - "Fix: Support unified team and team member assistant IDs in transcript recorder"
- Issue: Getting "Recorder returned null" error for unified team on test page
- Date: 2026-01-18
- Tested: ✅ Logic validated, syntax checked, integration verified
