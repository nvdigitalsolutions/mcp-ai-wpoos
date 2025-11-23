# Fix for Chat Transcript 404 Error After Save

## Problem Statement

When a user saves a conversation to CCT (Custom Content Type) and then immediately tries to load it from the history panel, the request fails with a 404 error:

```
GET /chat-transcripts/65d4c3d7-c733-4530-b165-9d4e56aa8ee1?user_id=1&assistant_id=14
404 (Not Found)

Error: The requested chat transcript could not be found.
```

### Observed Behavior

1. ✅ Conversation saves successfully - "Conversation saved successfully to CCT"
2. ✅ Conversation is cleared from local storage
3. ✅ History list is fetched - GET `/chat-transcripts?user_id=1&assistant_id=14`
4. ❌ Specific session fetch fails with 404 - GET `/chat-transcripts/{session_key}?user_id=1&assistant_id=14`

## Root Cause

**The `cct_author_id` column doesn't exist in the JetEngine Custom Content Type table.**

The transcript repository was using SQL queries like:

```sql
SELECT * FROM wp_jet_cct_ai_chat_transcripts
WHERE session_key = '...' 
  AND cct_author_id = 1  -- This column doesn't exist!
  AND assistant_id = 14
```

Since `cct_author_id` doesn't exist in the table, the query would fail to match any rows, even though the data was successfully saved with the `user_id` field.

## Solution

Changed the primary WHERE clause in both `get_session()` and `get_sessions()` methods to use the `user_id` field instead of `cct_author_id`:

```sql
SELECT * FROM wp_jet_cct_ai_chat_transcripts
WHERE session_key = '...' 
  AND user_id = 1  -- Now using the correct field!
  AND assistant_id = 14
```

The fallback logic was also updated to try `cct_author_id` as a secondary option (in case it exists in some JetEngine configurations).

## Files Changed

### 1. `includes/repositories/class-wp-mcp-ai-transcript-repository.php`

**Changes in `get_session()` method:**
- Line 216: Changed from `'cct_author_id = %d'` to `'user_id = %d'`
- Lines 270-311: Updated fallback logic to try `cct_author_id` as secondary option
- Lines 226-267: Added comprehensive debug logging for queries and results

**Changes in `get_sessions()` method:**
- Line 103: Changed from `'cct_author_id = %d'` to `'user_id = %d'`
- Lines 137-178: Updated fallback logic to try `cct_author_id` as secondary option

### 2. `includes/class-wp-mcp-ai-chat-transcript-recorder.php`

**Changes in `record()` method:**
- Lines 77-115: Added debug logging when saving transcripts
- Logs: session_key, user_id, assistant_id, cct_author_id, and save result

## Debug Logging Added

The fix includes comprehensive debug logging to help diagnose future issues:

### On Save:
```
[debug] Chat Transcript Recorder: Saving transcript
  - session_key: 65d4c3d7-c733-4530-b165-9d4e56aa8ee1
  - user_id: 1
  - assistant_id: 14
  - cct_author_id: 1
  - message_count: 2

[debug] Chat Transcript Recorder: Transcript saved successfully
  - result: ID: 123
```

### On Retrieve:
```
[debug] Transcript Repository: get_session query
  - session_key: 65d4c3d7-c733-4530-b165-9d4e56aa8ee1
  - user_id: 1
  - assistant_id: 14
  - query: SELECT ... WHERE user_id = 1 AND ...

[debug] Transcript Repository: get_session query results
  - row_count: 1
  - wpdb_error: none
```

## Testing

To verify the fix works:

1. **Enable Debug Logging**: 
   - Go to WordPress Admin → Settings → WP oOS
   - Enable "Debug Logging"

2. **Test the Flow**:
   - Start a conversation with an assistant
   - Send a few messages
   - Click "New Conversation" (this saves and clears)
   - Open the History panel
   - Click on the conversation you just had
   - It should load successfully

3. **Check Logs**:
   - Go to WordPress Admin → Settings → WP oOS → Logs
   - Look for the debug entries showing successful save and retrieve

## Backward Compatibility

The fix maintains backward compatibility:

- Primary query uses `user_id` (the field that actually exists)
- Fallback query tries `cct_author_id` (in case some JetEngine setups have it)
- Both `user_id` and `cct_author_id` are set to the same value when saving
- Existing data will continue to work

## Related Code

### JetEngine CCT Field Definition

The `user_id` field is defined in `class-wp-mcp-ai-jetengine-cct.php`:

```php
self::build_field(
    10002,
    'user_id',  // Custom field
    __( 'User ID', 'wp-mcp-ai' ),
    'number',
    array(
        'min'         => 0,
        'step'        => 1,
        'description' => __( 'Numeric WordPress user ID...', 'wp-mcp-ai' ),
    )
),
```

Note: `cct_author_id` is NOT in the custom fields array because it's supposed to be a JetEngine built-in field, but it appears not to be present in the actual table.

### Transcript Save Record

When saving, the recorder sets both fields:

```php
$record = array(
    'session_key'      => $session_key,
    'user_id'          => $user_id,        // Custom field (exists)
    'cct_author_id'    => $user_id,        // Built-in field (may not exist)
    'assistant_id'     => (string) $assistant_id,
    // ... other fields
);
```

## Future Improvements

Consider these enhancements:

1. **Table Structure Verification**: Add a startup check to verify which user ID field exists in the table
2. **Unified Field**: Standardize on either `user_id` or `cct_author_id` based on what JetEngine actually creates
3. **Migration**: If `cct_author_id` becomes available in future JetEngine versions, create a migration to copy data from `user_id`

## References

- Issue: Chat transcript 404 error after save
- Session Key Example: `65d4c3d7-c733-4530-b165-9d4e56aa8ee1`
- Table: `wp_jet_cct_ai_chat_transcripts`
- JetEngine Version: Tested with current version as of this fix
