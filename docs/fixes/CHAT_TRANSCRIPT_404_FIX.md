# Chat Transcript 404 Error Fix

## Issue Description

When a user saves a conversation via the chat UI:
1. POST `/chat-transcripts` returns `200 OK` with `{"success": true}`
2. Frontend immediately tries to retrieve the conversation
3. GET `/chat-transcripts/{session_key}` returns `404 Not Found`
4. Frontend retries 5 times with exponential backoff (750ms, 1.5s, 3s, 6s)
5. All retry attempts fail with 404

## Root Cause

The JetEngine Custom Content Type (CCT) for `ai_chat_transcripts` had REST API endpoints disabled:

```php
'rest_post_enabled'   => false,  // ❌ Prevented creating new records
'rest_put_enabled'    => false,  // ❌ Prevented updating records
```

### Why This Caused 404 Errors

When the plugin saves transcripts via `WP_MCP_AI_Chat_Transcript_Recorder::record()`:

1. It calls `$handler->update_item($record)` 
2. The handler is `WP_MCP_AI_JetEngine_CCT::get_item_handler()`
3. JetEngine's `update_item()` uses its REST API internally to persist data
4. With REST endpoints disabled, the data was **not written to the database**
5. The save endpoint returned success because no error was thrown
6. When retrieving, the database query found no records → 404

## Solution

Enable the required REST endpoints in the CCT configuration:

```php
'rest_post_enabled'   => true,   // ✅ Allow creating records
'rest_put_enabled'    => true,   // ✅ Allow updating records
```

**File:** `includes/class-wp-mcp-ai-jetengine-cct.php`  
**Lines:** 241-242

## Security Considerations

✅ **Safe to enable** - Permissions are properly configured:

```php
'rest_get_access'     => 'manage_options',  // Admin only
'rest_post_access'    => 'edit_posts',      // Contributor+
'rest_put_access'     => 'edit_posts',      // Contributor+
'rest_delete_enabled' => false,             // Disabled for safety
```

Additionally, the plugin's own REST endpoints (`/mcp-ai/v1/chat-transcripts`) have independent permission checks that validate:
- WordPress nonce
- Assistant credentials
- Auth0 tokens
- Guest tokens

## Testing

### Unit Tests

Created `tests/test-jetengine-chat-transcripts-cct.php` to verify:

```php
// REST endpoints are enabled
$this->assertTrue( $args['rest_post_enabled'] );
$this->assertTrue( $args['rest_put_enabled'] );

// DELETE remains disabled
$this->assertFalse( $args['rest_delete_enabled'] );

// Permissions are correct
$this->assertEquals( 'edit_posts', $args['rest_post_access'] );
$this->assertEquals( 'edit_posts', $args['rest_put_access'] );
```

### Integration Test

The existing test `tests/test-chat-transcript-save-retrieve-cycle.php` verifies:
1. Save conversation via POST → Returns 200 with `success: true`
2. Retrieve conversation via GET → Returns 200 with session data
3. Session contains all messages from the saved conversation

## Alignment with Other CCTs

This fix aligns `ai_chat_transcripts` with other CCTs in the system:

| CCT | rest_post_enabled | rest_put_enabled |
|-----|-------------------|------------------|
| `assistants` | ✅ true | ✅ true |
| `ai_peers` | ✅ true | ✅ true |
| `model_rate_limits` | ✅ true | ✅ true |
| `ai_chat_transcripts` | ✅ true (fixed) | ✅ true (fixed) |
| `performance_metrics` | ✅ true | ❌ false |

## Migration Notes

### For Existing Installations

When the plugin updates, the CCT configuration will be updated automatically on the next page load:

1. `WP_MCP_AI_JetEngine_CCT::bootstrap()` runs on `init` (priority 0)
2. `maybe_register_cct()` checks if CCT exists
3. If CCT exists, it's **not** re-registered (to preserve existing data)

**Important:** Existing CCTs need manual update:

```php
// Option 1: Via JetEngine admin UI
// 1. Go to JetEngine → Custom Content Types
// 2. Edit "AI Chat Transcripts"
// 3. Enable "REST API: Create Items" and "REST API: Update Items"
// 4. Save

// Option 2: Programmatically (one-time migration)
// Add to wp-config.php or run via WP-CLI
add_action('admin_init', function() {
    if (get_option('wp_mcp_ai_cct_rest_updated')) {
        return;
    }
    
    // Force re-registration
    delete_option('jet_cct_' . WP_MCP_AI_JetEngine_CCT::get_slug());
    
    // Mark as updated
    update_option('wp_mcp_ai_cct_rest_updated', true);
}, 99);
```

### For New Installations

The CCT will be created with the correct configuration automatically.

## Verification

After deploying, verify the fix works:

```bash
# 1. Save a conversation
curl -X POST https://yoursite.com/wp-json/mcp-ai/v1/chat-transcripts \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "assistant_id": 123,
    "session_key": "test-session-key",
    "messages": [
      {"role": "user", "content": "Hello"},
      {"role": "assistant", "content": "Hi!"}
    ]
  }'

# Expected: {"success": true, "session_key": "test-session-key"}

# 2. Immediately retrieve the conversation
curl https://yoursite.com/wp-json/mcp-ai/v1/chat-transcripts/test-session-key?user_id=1&assistant_id=123 \
  -H "X-WP-Nonce: YOUR_NONCE"

# Expected: {"session": {"session_key": "test-session-key", "messages": [...]}}
# NOT: {"code": "wp_mcp_ai_transcript_missing", ...}
```

## Related Issues

- Frontend retry logic with exponential backoff (chat.js lines 4377-4396)
- Session key normalization (prevents special characters in keys)
- JetEngine data stores auto-activation

## References

- `includes/class-wp-mcp-ai-jetengine-cct.php` - CCT registration
- `includes/class-wp-mcp-ai-chat-transcript-recorder.php` - Save logic
- `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` - REST endpoints
- `tests/test-jetengine-chat-transcripts-cct.php` - Configuration tests
- `tests/test-chat-transcript-save-retrieve-cycle.php` - Integration test
