# Chat Transcript Troubleshooting Guide

## Common Issues and Solutions

### Issue 1: "Error retrieving chat transcripts" when accessing `/wp-json/mcp-ai/v1/chat-transcripts?session_key=...`

#### Possible Causes:

1. **User Not Logged In**
   - **Symptom**: HTTP 400 or 403 error with message "A valid user is required to query chat transcripts"
   - **Solution**: Ensure the user is logged into WordPress before attempting to retrieve transcripts
   - **Check**: Verify `is_user_logged_in()` returns true

2. **JetEngine Custom Content Types Not Active**
   - **Symptom**: HTTP 404 with message "Chat transcripts are not available. Ensure JetEngine Custom Content Types is active..."
   - **Solution**: 
     - Install and activate JetEngine plugin
     - Ensure the Custom Content Types (CCT) module is enabled
     - Verify the `/wp-json/jet-cct/ai_chat_transcripts` endpoint loads successfully
   - **Note**: Chat transcripts require JetEngine CCT for server-side storage

3. **Session Not Found in Database**
   - **Symptom**: HTTP 404 with message "The requested chat transcript could not be found"
   - **Solution**:
     - Verify the session_key is correct
     - Check that the conversation was previously saved to CCT (not just localStorage)
     - Ensure the user_id matches the session owner
     - Check database table exists: `wp_jet_cct_ai_chat_transcripts`

4. **Session Key Normalization Issues**
   - **Symptom**: Session key with special characters fails to retrieve
   - **Solution**: 
     - Session keys should only contain: `a-z`, `A-Z`, `0-9`, `_`, `-`
     - UUID format (e.g., `d28ff0cd-0d82-4d6d-b733-cc318b71ac9a`) is fully supported
     - Maximum length: 96 characters (as of this fix)

#### Debugging Steps:

1. **Enable Debug Logging**:
   ```php
   // In wp-config.php or via WordPress admin
   define( 'WP_MCP_AI_DEBUG', true );
   ```
   Or via WordPress admin: **Settings → WP oOS → Enable Logging**

2. **Check Logs**:
   ```bash
   # Via WP-CLI
   wp option get wp_mcp_ai_recent_errors --format=json
   wp option get wp_mcp_ai_recent_activity --format=json
   ```

3. **Verify Database Table**:
   ```sql
   SHOW TABLES LIKE 'wp_jet_cct_ai_chat_transcripts';
   SELECT * FROM wp_jet_cct_ai_chat_transcripts WHERE session_key = 'YOUR_SESSION_KEY';
   ```

4. **Test Endpoint Directly**:
   ```bash
   # As logged-in user
   curl -H "Cookie: wordpress_logged_in_XXX=..." \
        "https://yoursite.com/wp-json/mcp-ai/v1/chat-transcripts?session_key=d28ff0cd-0d82-4d6d-b733-cc318b71ac9a"
   ```

### Issue 2: "Previous conversations clear when adding a new chat"

#### Root Cause:
Each assistant stores **only ONE active conversation** in browser localStorage at a time. When starting a new conversation:
1. Current conversation is saved to CCT (server-side storage)
2. localStorage is cleared
3. New session key is generated

**If the CCT save fails**, the previous conversation would be lost from localStorage without being persisted to the database.

#### Solution (Fixed in this PR):
The chat client now:
1. Attempts to save the conversation to CCT before clearing
2. Checks if the save succeeded
3. If save **fails**, prompts the user:
   > "Failed to save conversation: [error]. Do you want to proceed anyway? Your current conversation will be lost."
4. User can choose to:
   - **Proceed**: Clear localStorage anyway (conversation lost)
   - **Cancel**: Keep the conversation in localStorage and try again later

#### How to Access Previous Conversations:

1. **From Chat History Widget** (if JetEngine active):
   - Click "Show previous conversations" in the chat interface
   - Select a previous session to view the full transcript
   - Click "Load into chat" to restore it to the active chat

2. **Via REST API**:
   ```javascript
   // List all sessions for current user
   GET /wp-json/mcp-ai/v1/chat-transcripts

   // Get specific session
   GET /wp-json/mcp-ai/v1/chat-transcripts?session_key=SESSION_KEY
   ```

3. **From User Dashboard**:
   - Use the `[wp_mcp_ai_user_chats]` shortcode
   - Or the Elementor "Dashboard: User Chats" widget

#### Best Practices:

1. **Ensure JetEngine is Active**: Without it, conversations are only stored in browser localStorage (24h expiry)

2. **Don't Clear Browser Data**: LocalStorage contains the current active conversation

3. **Regular Saves**: The plugin automatically saves conversations:
   - After each AI response
   - When starting a new conversation (if no errors)
   - You can also trigger manual saves before closing the browser

4. **Monitor for Errors**: Watch for save failure messages in the chat interface

### Issue 3: Session Key Length Mismatch

#### Problem:
Prior to this fix, there was an inconsistency:
- POST endpoint accepted session keys up to 100 characters
- GET endpoint and database storage limited to 96 characters
- This could cause sessions to save successfully but fail to retrieve

#### Solution:
Both endpoints now use the same `MAX_SESSION_KEY_LENGTH` constant (96 characters).

UUID session keys (36 characters) are well within this limit and fully supported.

## Configuration Requirements

### Minimum Requirements (Base Version):
- WordPress 6.0+
- PHP 7.4+
- User must be logged in to save/retrieve transcripts

### For Full Transcript Features:
- **JetEngine Plugin** with Custom Content Types module
- Database table: `{prefix}_jet_cct_ai_chat_transcripts`
- Endpoint available: `/wp-json/jet-cct/ai_chat_transcripts`

## Error Messages Reference

| Error Code | HTTP Status | Meaning | Solution |
|------------|-------------|---------|----------|
| `wp_mcp_ai_transcripts_missing_user` | 400 | No user ID provided | Log in to WordPress |
| `wp_mcp_ai_transcripts_unavailable` | 404 | JetEngine CCT not active | Install/activate JetEngine |
| `wp_mcp_ai_transcript_missing` | 404 | Session not found | Check session_key and user_id |
| `wp_mcp_ai_forbidden` | 403 | Insufficient permissions | User lacks required capabilities |
| `wp_mcp_ai_transcripts_missing_assistant` | 400 | No assistant_id in POST | Include assistant_id parameter |
| `wp_mcp_ai_transcripts_missing_session` | 400 | No session_key in POST | Include session_key parameter |
| `wp_mcp_ai_transcripts_missing_messages` | 400 | No messages in POST | Include messages array |

## Testing Changes

To verify the fixes work correctly:

1. **Test Session Key Normalization**:
   ```bash
   vendor/bin/phpunit tests/test-session-key-normalization.php
   ```

2. **Test Save/Retrieve Flow**:
   ```bash
   vendor/bin/phpunit tests/test-chat-transcript-save-endpoint.php
   ```

3. **Test in Browser**:
   - Start a chat conversation
   - Add several messages
   - Click "Start new conversation"
   - Verify:
     - Save status message appears
     - If save succeeds, conversation is cleared and new one starts
     - If save fails, you get a prompt to proceed or cancel
   - Check "Show previous conversations" to verify the previous chat was saved

## Additional Resources

- **Plugin Documentation**: `docs/DOCUMENTATION_INDEX.md`
- **REST API Reference**: `docs/rest-api.md`
- **Tool Reference**: `docs/tool-reference.md`
- **Deployment Troubleshooting**: `docs/deployment-troubleshooting.md`
