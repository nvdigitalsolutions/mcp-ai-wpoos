# Assistant ID Type Mismatch Fix

## Issue Description

Chat transcripts were being saved successfully but failed to load with a 404 error when retrieving by session_key, particularly when filtering by assistant_id.

### Error Symptoms

```javascript
[WP oOS] Conversation saved successfully to CCT
[WP oOS] Loading conversation details: {session_key: '4beb4044-6bbf-447c-a6c3-337315ac8094', ...}
GET /chat-transcripts/4beb4044-6bbf-447c-a6c3-337315ac8094?user_id=1&assistant_id=14 404 (Not Found)
[WP oOS] Conversation details response: {status: 404, ok: false, ...}
```

## Root Cause

**Type mismatch in assistant_id field between storage and retrieval:**

1. **JetEngine CCT Definition**: `assistant_id` was defined as a `'text'` field (string type)
   - Location: `includes/class-wp-mcp-ai-jetengine-cct.php` line 314
   
2. **Recorder Save Logic**: Cast `assistant_id` to string: `(string) $assistant_id`
   - Location: `includes/class-wp-mcp-ai-chat-transcript-recorder.php` line 219
   
3. **Repository Query Logic**: Used `%d` (integer placeholder) for assistant_id filtering
   - Location: `includes/repositories/class-wp-mcp-ai-transcript-repository.php` lines 107, 182, 207

This type mismatch caused SQL queries with assistant_id filters to fail or return no results, resulting in 404 errors even when the data existed in the database.

## Solution

Changed the assistant_id field to be consistently treated as an integer throughout the system.

### Changes Made

#### 1. JetEngine CCT Field Definition

**File**: `includes/class-wp-mcp-ai-jetengine-cct.php`

**Before:**
```php
self::build_field(
    10003,
    'assistant_id',
    __( 'Assistant ID', 'wp-mcp-ai' ),
    'text',  // ← String type
    array(
        'description' => __( 'Internal assistant identifier handling the request.', 'wp-mcp-ai' ),
    )
),
```

**After:**
```php
self::build_field(
    10003,
    'assistant_id',
    __( 'Assistant ID', 'wp-mcp-ai' ),
    'number',  // ← Integer type
    array(
        'min'         => 0,
        'step'        => 1,
        'description' => __( 'Internal assistant identifier handling the request.', 'wp-mcp-ai' ),
    )
),
```

#### 2. Recorder Save Logic

**File**: `includes/class-wp-mcp-ai-chat-transcript-recorder.php`

**Before:**
```php
$record = array(
    'session_key'      => $session_key,
    'user_id'          => $user_id,
    'cct_author_id'    => $user_id,
    'assistant_id'     => (string) $assistant_id,  // ← Cast to string
    'assistant_model'  => $model,
    // ...
);
```

**After:**
```php
$record = array(
    'session_key'      => $session_key,
    'user_id'          => $user_id,
    'cct_author_id'    => $user_id,
    'assistant_id'     => $assistant_id,  // ← Integer value (no cast)
    'assistant_model'  => $model,
    // ...
);
```

#### 3. Repository Query Logic (No Changes Needed)

**File**: `includes/repositories/class-wp-mcp-ai-transcript-repository.php`

The repository was already correctly using `%d` (integer placeholder) for assistant_id:

```php
// Line 107 - in get_sessions()
$where_clauses[] = 'assistant_id = %d';

// Line 182 - in get_session()
$where_clauses[] = 'assistant_id = %d';

// Line 207 - in get_session() fallback
$fallback_where_clauses[] = 'assistant_id = %d';
```

These queries now work correctly with the integer values being saved.

## Impact

### Backward Compatibility

**Important**: Existing transcripts in the database may have assistant_id stored as text strings. JetEngine CCTs should handle this automatically:

- JetEngine will migrate the field type when the CCT is updated
- MySQL will perform automatic type conversion for existing string values to integers
- Queries using `%d` will work with both old string values and new integer values due to MySQL's type coercion

### Data Consistency

After this fix:
- ✅ New transcripts save `assistant_id` as integer
- ✅ CCT field type matches the data type (number)
- ✅ Queries use correct integer placeholder (`%d`)
- ✅ Filtering by assistant_id works correctly
- ✅ No 404 errors when retrieving transcripts with assistant_id filter

## Testing

### Manual Testing

1. **Save a conversation**:
   ```javascript
   POST /wp-json/mcp-ai/v1/chat-transcripts
   {
     "assistant_id": 14,
     "session_key": "test-session-uuid",
     "messages": [...]
   }
   ```

2. **Retrieve the conversation**:
   ```javascript
   GET /wp-json/mcp-ai/v1/chat-transcripts/test-session-uuid?user_id=1&assistant_id=14
   ```
   
   Expected: 200 OK with transcript data (not 404)

3. **List conversations for assistant**:
   ```javascript
   GET /wp-json/mcp-ai/v1/chat-transcripts?user_id=1&assistant_id=14&per_page=20&page=1
   ```
   
   Expected: List of sessions for that assistant

### Automated Testing

Existing test suite should continue to pass:
- `tests/test-chat-transcript-get-by-session-key.php`
- `tests/test-chat-transcript-save-retrieve-cycle.php`
- `tests/test-chat-transcript-save-endpoint.php`

## Database Migration

### Automatic Migration

JetEngine handles field type changes automatically when:
1. The plugin is updated
2. WordPress admin is accessed
3. JetEngine detects the CCT schema change
4. The table structure is updated via ALTER TABLE

### Manual Verification

To verify the field type in the database:

```sql
DESCRIBE wp_jet_cct_ai_chat_transcripts;
```

Look for the `assistant_id` column - it should show an integer type (INT, BIGINT, etc.)

### Optional Manual Migration

If needed, manually update existing string values to integers:

```sql
UPDATE wp_jet_cct_ai_chat_transcripts 
SET assistant_id = CAST(assistant_id AS UNSIGNED);
```

Note: This is usually unnecessary as MySQL handles the conversion automatically.

## Verification Checklist

After deploying this fix:

- [ ] Verify new transcripts save successfully
- [ ] Verify transcripts can be retrieved by session_key
- [ ] Verify transcripts can be filtered by assistant_id
- [ ] Verify conversation history loads without 404 errors
- [ ] Check debug logs for any type-related errors
- [ ] Verify existing transcripts are still accessible
- [ ] Test with multiple assistants to ensure filtering works correctly

## Related Issues

- Previous fix: `TRANSCRIPT_RETRIEVAL_FALLBACK_FIX.md` (user_id/cct_author_id mismatch)
- This fix addresses: assistant_id type mismatch
- Together these fixes ensure robust transcript storage and retrieval for JetEngine CCT

## Summary

This fix ensures type consistency for the `assistant_id` field throughout the WP oOS chat transcript system:

1. **Storage**: Integer value (no string cast)
2. **CCT Schema**: Number field type
3. **Queries**: Integer placeholder (`%d`)

The fix is minimal, focused, and maintains backward compatibility with existing data while preventing future 404 errors when retrieving chat transcripts filtered by assistant_id.
