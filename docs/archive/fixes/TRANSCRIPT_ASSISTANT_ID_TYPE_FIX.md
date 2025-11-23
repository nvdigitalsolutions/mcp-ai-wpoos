# Chat Transcript 404 Fix - assistant_id Type Mismatch

## Issue Summary

Users were experiencing 404 errors when trying to retrieve specific chat transcripts by session key, despite the transcripts being successfully saved to the database.

**Error Example:**
```
GET /wp-json/mcp-ai/v1/chat-transcripts/56e42dbe-d60c-4f99-8393-759042a4723e?user_id=1&assistant_id=372
404 (Not Found)
```

## Root Cause

**Type mismatch between save and query operations:**

### How Data is Stored
In `includes/class-wp-mcp-ai-chat-transcript-recorder.php` (line 246):
```php
$record = array(
    'session_key'      => $session_key,
    'user_id'          => $user_id,
    'assistant_id'     => (string) $assistant_id,  // ← Stored as STRING
    // ... other fields
);
```

The `assistant_id` field is explicitly cast to a string before being saved to the database, making the database column a VARCHAR/TEXT type.

### How Data was Queried (BEFORE FIX)
In `includes/repositories/class-wp-mcp-ai-transcript-repository.php`:
```php
// OLD CODE - INCORRECT
if ( $assistant_id > 0 ) {
    $where_clauses[] = 'assistant_id = %d';  // ← Queried as INTEGER
    $where_values[]  = $assistant_id;
}
```

### The Problem
When using `%d` (integer format) in `wpdb::prepare()`:
- **Generated SQL:** `WHERE assistant_id = 372` (unquoted integer)
- **MySQL behavior:** Type coercion fails when comparing unquoted integer to VARCHAR column
- **Result:** No rows matched, even though data exists

## The Fix

Changed all assistant_id queries from `%d` (integer) to `%s` (string) format:

```php
// NEW CODE - CORRECT
if ( $assistant_id > 0 ) {
    $where_clauses[] = 'assistant_id = %s';  // ← Query as STRING
    $where_values[]  = (string) $assistant_id;  // ← Cast to string
}
```

### Why This Works
When using `%s` (string format) in `wpdb::prepare()`:
- **Generated SQL:** `WHERE assistant_id = '372'` (quoted string)
- **MySQL behavior:** Direct string comparison matches VARCHAR column
- **Result:** Rows matched correctly

## Locations Fixed

Fixed 6 locations in `includes/repositories/class-wp-mcp-ai-transcript-repository.php`:

1. **Line 107-109:** `get_sessions()` - main query
2. **Line 144-146:** `get_sessions()` - fallback query (user_id → cct_author_id)
3. **Line 184-186:** `get_sessions()` - total count fallback query
4. **Line 239-241:** `get_session()` - main query
5. **Line 293-295:** `get_session()` - fallback query (user_id → cct_author_id)
6. **Line 456-458:** `build_user_id_fallback_where()` - helper method

## Verification

### SQL Query Comparison

**Before Fix:**
```sql
SELECT * FROM wp_jet_cct_ai_chat_transcripts
WHERE session_key = '56e42dbe-d60c-4f99-8393-759042a4723e'
  AND user_id = 1
  AND assistant_id = 372  -- No quotes = type mismatch!
```

**After Fix:**
```sql
SELECT * FROM wp_jet_cct_ai_chat_transcripts
WHERE session_key = '56e42dbe-d60c-4f99-8393-759042a4723e'
  AND user_id = 1
  AND assistant_id = '372'  -- Quoted = matches VARCHAR column!
```

### Test Coverage

Added `tests/test-transcript-assistant-id-type.php` to verify:
- `wpdb::prepare()` with `%s` produces quoted strings
- `wpdb::prepare()` with `%d` produces unquoted integers
- String casting works correctly

## Impact

This fix resolves the 404 error when:
- Loading a specific conversation from the history panel
- Retrieving transcript details by session key
- Any query that filters by assistant_id

The fix applies to both:
- Main query paths (using `user_id` field)
- Fallback query paths (using `cct_author_id` field)

## Related Documentation

- Previous fix: `TRANSCRIPT_404_FIX.md` - Fixed user_id vs cct_author_id issue
- This fix builds upon that work by addressing the assistant_id type mismatch

## Future Considerations

When working with the transcript repository:
1. **Always use `%s` format for `assistant_id`** in SQL queries
2. **Cast to string** when adding to query values: `(string) $assistant_id`
3. Be aware that `assistant_id` is stored as VARCHAR/TEXT, not as an integer
4. If adding new queries, follow the pattern established in this fix

## Files Changed

1. `includes/repositories/class-wp-mcp-ai-transcript-repository.php` - 6 query locations
2. `tests/test-transcript-assistant-id-type.php` - New test file

## Pull Request

Branch: `copilot/debug-conversation-fetch-error`
Commits:
- Fix assistant_id type mismatch in transcript queries
- Add test for assistant_id type handling in transcript queries
- Fix remaining assistant_id type mismatches in fallback queries
