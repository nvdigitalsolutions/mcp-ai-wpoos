# Chat Transcript Retrieval Fallback Fix

## Issue Description

When a conversation was saved via `POST /chat-transcripts`, it would save successfully and return a success message. However, when attempting to retrieve the same conversation immediately via `GET /chat-transcripts/{session_key}`, the request would return a 404 error indicating the transcript could not be found.

### Error Symptoms

```
[WP oOS] Conversation saved successfully to CCT
GET /chat-transcripts/c9547c44-e89b-4ba6-baf1-98d9c368e28e?user_id=1&assistant_id=331 404 (Not Found)
Error: The requested chat transcript could not be found.
```

## Root Cause

The JetEngine Custom Content Type (CCT) has both:
1. A built-in `cct_author_id` column (similar to WordPress's `post_author`)
2. A custom `user_id` field defined in the CCT meta fields

The retrieval code was querying for `cct_author_id`, but JetEngine's `update_item()` method may be storing the author information in the custom `user_id` column instead of (or in addition to) the built-in `cct_author_id` column.

This mismatch caused the query to find zero rows, resulting in a 404 error.

## Solution

Implemented a fallback query mechanism in both transcript retrieval methods:

### Files Modified

1. `includes/class-wp-mcp-ai-rest.php` - Main REST controller's `get_transcript_session()` method
2. `includes/repositories/class-wp-mcp-ai-transcript-repository.php` - Repository's `get_session()` method

### Implementation Details

The retrieval logic now works as follows:

1. **Primary Query**: First attempt to find the transcript using `cct_author_id`:
   ```sql
   WHERE session_key = ? AND cct_author_id = ? AND assistant_id = ?
   ```

2. **Fallback Query**: If no results are found, retry using `user_id`:
   ```sql
   WHERE session_key = ? AND user_id = ? AND assistant_id = ?
   ```

3. **Error Handling**: If both queries return no results, return a 404 error

### Debug Logging

Added debug logging to trace which query path succeeds:
- `get_transcript_session: no rows found with cct_author_id, trying user_id fallback`
- `get_transcript_session: no rows found in database after fallback`

This helps diagnose which column JetEngine is actually using in production environments.

## Testing

### Existing Tests

The existing test suite uses mock handlers that don't interact with actual JetEngine CCT tables, so the tests continue to pass without modification.

### Production Testing

To verify the fix works correctly:

1. Enable debug logging in WordPress (Settings → WP oOS → Enable Logging)
2. Save a conversation via the chat interface
3. Try to load the conversation from history
4. Check logs to see which query path was taken

### Expected Behavior

After the fix:
- Conversations saved via POST /chat-transcripts should be retrievable immediately
- One of the two query paths (cct_author_id or user_id) should find the data
- Debug logs will show which column JetEngine is using

## Long-term Considerations

### Investigation Needed

Once deployed, monitor the debug logs to determine:
- Which column JetEngine consistently uses
- Whether both columns are being populated
- If there are any edge cases or special authentication scenarios

### Future Improvements

Based on findings, consider:

1. **Standardize Save Logic**: Update the recorder to ensure data is saved to the correct column
2. **Remove Fallback**: Once we confirm which column is correct, remove the fallback for performance
3. **Update Tests**: Add integration tests that verify actual JetEngine behavior
4. **Documentation**: Update JetEngine integration docs with findings

## Related Code

### Recorder Save Logic

The transcript recorder creates records with both fields:

```php
$record = array(
    'session_key'      => $session_key,
    'user_id'          => $user_id,      // Custom CCT field
    'cct_author_id'    => $user_id,      // Built-in JetEngine column
    'assistant_id'     => (string) $assistant_id,
    'request_payload'  => $json_messages,
    'response_payload' => $json_response,
    // ...
);

$handler->update_item( $record );
```

### JetEngine CCT Definition

The CCT is defined with a custom `user_id` field:

```php
self::build_field(
    10002,
    'user_id',
    __( 'User ID', 'wp-mcp-ai' ),
    'number',
    array(
        'min'         => 0,
        'step'        => 1,
        'description' => __( 'Numeric WordPress user ID associated with the session.', 'wp-mcp-ai' ),
    )
),
```

But `cct_author_id` is a built-in column that JetEngine creates automatically for all CCTs.

## References

- Issue: Chat transcript save/retrieve 404 error
- PR: [Add fallback query for transcript retrieval]
- Test File: `tests/test-chat-transcript-save-retrieve-cycle.php`
- JetEngine Integration: `includes/class-wp-mcp-ai-jetengine-cct.php`
