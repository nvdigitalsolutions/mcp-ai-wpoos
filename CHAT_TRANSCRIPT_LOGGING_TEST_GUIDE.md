# Chat Transcript Logging - Manual Testing Guide

This guide provides step-by-step instructions to manually test and verify that save/load/delete operations are now being logged when done via the chat widget-client.

## Prerequisites

1. WordPress site with WP oOS plugin installed
2. At least one assistant configured
3. Browser with DevTools (Chrome, Firefox, Edge, Safari)
4. Access to server logs or WP-CLI

## Setup

### 1. Enable Logging

**Method A: Via WordPress Admin**
1. Navigate to **Settings → WP oOS**
2. Find the **Enable Logging** option
3. Check the box to enable it
4. Click **Save Changes**

**Method B: Via wp-config.php**
Add this line to your `wp-config.php`:
```php
define( 'WP_MCP_AI_DEBUG', true );
```

### 2. Clear Existing Logs (Optional)

To start with a clean slate:
```bash
wp option delete wp_mcp_ai_recent_errors
wp option delete wp_mcp_ai_recent_activity
```

## Test 1: Save Transcript Logging

### Test Steps

1. **Open Chat Widget**
   - Navigate to a page with the chat widget
   - Open browser DevTools (F12)
   - Go to the Console tab

2. **Start a Conversation**
   - Type a message in the chat widget
   - Send the message
   - Wait for the AI response

3. **Save the Conversation**
   - Click the "Save" button in the chat widget
   - Watch the console output

### Expected Results

**Client-Side (Browser Console):**
```javascript
[WP oOS] Saving conversation to CCT: {
    session_key: "d28ff0cd-0d82-4d6d-b733-cc318b71ac9a",
    assistant_id: 372,
    message_count: 2,
    attempt: 1
}

[WP oOS] Conversation saved successfully to CCT
```

**Server-Side (PHP Error Log):**
```
[WP oOS] DEBUG: handle_chat_transcript_save: Saving transcript {"session_key":"d28ff0cd-...","assistant_id":372,"user_id":1,"message_count":2,"source":"chat_client"}

[WP oOS] INFO: handle_chat_transcript_save: Transcript saved successfully {"session_key":"d28ff0cd-...","assistant_id":372,"user_id":1,"message_count":2}
```

### Verification Commands

**Check Recent Logs:**
```bash
wp option get wp_mcp_ai_recent_activity --format=json | jq '.'
```

**Check PHP Error Log:**
```bash
tail -f /var/log/php-error.log | grep "WP oOS"
```

Or find your PHP error log location:
```bash
php -i | grep error_log
```

## Test 2: Load Conversation History Logging

### Test Steps

1. **Open Chat Widget**
   - Navigate to a page with the chat widget
   - Open browser DevTools (F12)
   - Go to the Console tab

2. **Open History Panel**
   - Click the "Show previous conversations" button
   - Watch the console output

### Expected Results

**Client-Side (Browser Console):**
```javascript
[WP oOS] Loading conversation history: {
    user_id: 1,
    per_page: 20,
    endpoint: "https://example.com/wp-json/mcp-ai/v1/chat-transcripts"
}
```

**Server-Side (PHP Error Log):**
```
[WP oOS] DEBUG: handle_chat_transcripts: Request parameters {"raw_session_key":"","normalized_session_key":"","user_id":1,"assistant_id":372}
```

## Test 3: Load Specific Conversation Logging

### Test Steps

1. **Open History Panel**
   - Click "Show previous conversations" in the chat widget
   - Open browser DevTools Console tab

2. **Load a Conversation**
   - Click on a conversation in the history list
   - Watch the console output

### Expected Results

**Client-Side (Browser Console):**
```javascript
[WP oOS] Loading conversation details: {
    session_key: "d28ff0cd-0d82-4d6d-b733-cc318b71ac9a"
}
```

**Server-Side (PHP Error Log):**
```
[WP oOS] DEBUG: handle_chat_transcripts: Request parameters {"raw_session_key":"d28ff0cd-...","normalized_session_key":"d28ff0cd-...","user_id":1,"assistant_id":372}
```

## Test 4: Clear Conversation Logging

### Test Steps

1. **Have an Active Conversation**
   - Start a conversation with some messages
   - Open browser DevTools Console tab

2. **Clear the Conversation**
   - Click the "New" button to start a new conversation
   - Confirm when prompted
   - Watch the console output

### Expected Results

**Client-Side (Browser Console):**
```javascript
[WP oOS] Clearing conversation: {
    session_key: "d28ff0cd-0d82-4d6d-b733-cc318b71ac9a",
    message_count: 2
}
```

## Test 5: Delete Conversation Logging

### Test Steps

1. **Open History Panel**
   - Click "Show previous conversations"
   - Open browser DevTools Console tab

2. **Delete a Conversation**
   - Hover over a conversation
   - Click the delete/trash icon
   - Confirm deletion
   - Watch the console output

### Expected Results

**Client-Side (Browser Console):**
```javascript
[WP oOS] Deleting conversation: {
    session_key: "d28ff0cd-0d82-4d6d-b733-cc318b71ac9a"
}

[WP oOS] Conversation deleted successfully: {
    session_key: "d28ff0cd-0d82-4d6d-b733-cc318b71ac9a"
}
```

**Server-Side (PHP Error Log):**
```
[WP oOS] DEBUG: handle_chat_transcript_delete: Deleting transcript {"session_key":"d28ff0cd-...","user_id":1,"source":"chat_client"}

[WP oOS] INFO: handle_chat_transcript_delete: Transcript deleted successfully {"session_key":"d28ff0cd-...","user_id":1,"deleted_rows":3}
```

## Test 6: Delete Failure Logging

This test requires special setup to simulate a failure.

### Test Steps

1. **Temporarily Break Database Connection** (for testing only!)
   - Or remove JetEngine if that's your transcript storage
   
2. **Attempt to Delete a Conversation**
   - Try to delete a conversation as in Test 5

### Expected Results

**Server-Side (PHP Error Log):**
```
[WP oOS] ERROR: handle_chat_transcript_delete: Failed to delete transcript {"session_key":"d28ff0cd-...","user_id":1}
```

## Test 7: Logging Disabled Test

### Test Steps

1. **Disable Logging**
   - Go to **Settings → WP oOS**
   - Uncheck **Enable Logging**
   - Save changes

2. **Perform Any Operation**
   - Save, load, or delete a conversation

### Expected Results

- **Browser Console:** Logs still appear (client-side logging is always on)
- **Server Logs:** No new logs should appear (server-side logging is off)

## Troubleshooting

### No Console Logs Appearing

1. **Check Browser Console Filters**
   - Make sure console is not filtered
   - Search for "WP oOS" in the filter box

2. **Check for JavaScript Errors**
   - Look for any JavaScript errors that might prevent execution

### No Server Logs Appearing

1. **Verify Logging is Enabled**
   ```bash
   wp option get wp_mcp_ai_enable_logging
   ```
   Should return: `1`

2. **Check PHP Error Log Location**
   ```bash
   php -i | grep error_log
   ```

3. **Check Error Log Permissions**
   ```bash
   ls -la /path/to/error.log
   ```

4. **Test Logger Directly**
   ```php
   WP_MCP_AI_Logger::log_event('debug', 'Test message', ['test' => 'data']);
   ```

### Logs Not Showing Expected Data

1. **Verify Session Key is Generated**
   - Check that the session_key appears in the logs
   - If empty, there might be an issue with session initialization

2. **Check User ID**
   - Verify you're logged in
   - Check the user_id in the logs matches your user ID

## Success Criteria

✅ All client-side console logs appear with `[WP oOS]` prefix  
✅ All logs include relevant context (session_key, user_id, etc.)  
✅ Server-side logs appear in PHP error log when logging is enabled  
✅ Server-side logs respect the logging enabled setting  
✅ Save operations log both debug and info events  
✅ Delete operations log debug, info, and error events as appropriate  
✅ Load operations log debug events  
✅ Clear operations log to console  

## Automated Test

To run the automated test suite:

```bash
# Install test environment (one time)
composer run test:install

# Run the logging tests
vendor/bin/phpunit tests/test-chat-transcript-logging.php
```

Expected output:
```
PHPUnit 9.x.x by Sebastian Bergmann and contributors.

......                                                              6 / 6 (100%)

Time: 00:01.234, Memory: 50.00 MB

OK (6 tests, 30 assertions)
```

## Log Examples Reference

### Complete Save Operation Log Sequence

**Client → Server flow:**

1. User clicks Save button
2. Client logs save attempt
3. Client sends POST to /wp-json/mcp-ai/v1/chat-transcripts
4. Server logs debug "Saving transcript"
5. Server saves to database
6. Server logs info "Transcript saved successfully"
7. Server responds with success
8. Client logs "Conversation saved successfully"

### Complete Delete Operation Log Sequence

**Client → Server flow:**

1. User clicks delete button
2. Client logs delete request
3. Client sends DELETE to /wp-json/mcp-ai/v1/chat-transcripts/{session_key}
4. Server logs debug "Deleting transcript"
5. Server deletes from database
6. Server logs info "Transcript deleted successfully" (or error if failed)
7. Server responds with success/error
8. Client logs "Conversation deleted successfully" (or error message)

## Notes

- Client-side logs always appear regardless of the server-side logging setting
- Server-side logs only appear when logging is enabled in settings
- All logs include a timestamp automatically
- Session keys are partial (truncated) in examples for readability
- Actual message_count will vary based on conversation length
