# Testing Checklist for Chat Transcript Fixes

## Overview
This checklist helps verify that the chat transcript fixes are working correctly in a real WordPress environment.

## Prerequisites

- [ ] WordPress 6.0+ installed
- [ ] WP oOS plugin installed with these fixes
- [ ] At least one AI assistant configured
- [ ] User account with access to chat interface

## Test Scenarios

### Scenario 1: UUID Session Key Retrieval

**Purpose**: Verify that session keys with hyphens (UUIDs) are preserved and can retrieve transcripts.

**Steps**:
1. Enable debug logging: `Settings → WP oOS → Enable Logging`
2. Start a chat conversation (this generates a UUID session key)
3. Add several messages to the conversation
4. Note the session key from browser console or localStorage
5. Try to retrieve the transcript via:
   - Browser: Check "Show previous conversations"
   - API: `GET /wp-json/mcp-ai/v1/chat-transcripts?session_key=YOUR_UUID`

**Expected Result**:
- ✅ Session key is a UUID format (e.g., `d28ff0cd-0d82-4d6d-b733-cc318b71ac9a`)
- ✅ Conversation appears in history list
- ✅ Clicking on it loads the full transcript
- ✅ API request returns 200 with conversation data

**If it fails**:
- Check logs: `wp option get wp_mcp_ai_recent_errors`
- Verify the session key wasn't truncated or modified
- Ensure user is logged in

---

### Scenario 2: Save Failure Handling (JetEngine Disabled)

**Purpose**: Verify that conversations are not lost when CCT save fails.

**Setup**:
1. Temporarily deactivate JetEngine plugin (or CCT module)
2. Start a chat conversation
3. Add at least 3 messages

**Steps**:
1. Click "Start new conversation" button
2. Confirm the first dialog ("Start new conversation?")
3. Observe the behavior

**Expected Result**:
- ✅ Status message shows "Saving current conversation..."
- ✅ Error prompt appears: "Failed to save conversation: [error]. Do you want to proceed anyway? Your current conversation will be lost."
- ✅ User has two options: OK (proceed) or Cancel

**Test Cancel**:
4. Click "Cancel" in the error prompt

**Expected Result**:
- ✅ Conversation is NOT cleared
- ✅ Previous messages are still visible
- ✅ Can continue chatting
- ✅ Status shows "Conversation not cleared. You can try again later."

**Test Proceed**:
5. Click "Start new conversation" again
6. Confirm first dialog
7. Click "OK" in error prompt

**Expected Result**:
- ✅ Conversation is cleared (user explicitly chose this)
- ✅ New session key is generated
- ✅ Chat interface is reset

---

### Scenario 3: Successful Save (JetEngine Active)

**Purpose**: Verify that conversations save successfully and can be retrieved.

**Setup**:
1. Ensure JetEngine is active with CCT module enabled
2. Start a chat conversation
3. Add several messages

**Steps**:
1. Click "Start new conversation" button
2. Confirm the dialog

**Expected Result**:
- ✅ Status message shows "Saving current conversation..."
- ✅ Success message shows "Conversation saved successfully."
- ✅ Conversation is cleared
- ✅ New session starts
- ✅ No error prompts appear

**Verify Persistence**:
4. Click "Show previous conversations"
5. Find the conversation you just saved

**Expected Result**:
- ✅ Previous conversation appears in the list
- ✅ Shows correct number of messages/turns
- ✅ Shows correct timestamp
- ✅ Can click to view full transcript
- ✅ Can load into current chat

---

### Scenario 4: Session Key Length Consistency

**Purpose**: Verify session keys are handled consistently.

**Steps**:
1. Create a conversation and save it
2. Note the session key (from logs or database)
3. Verify the session key length is ≤ 96 characters
4. Retrieve the conversation using the same session key

**Expected Result**:
- ✅ Session key in POST request matches session key in database
- ✅ Session key in database matches session key in GET request
- ✅ All session keys are ≤ 96 characters
- ✅ UUID session keys (36 chars) work perfectly

---

### Scenario 5: Error Logging and Diagnostics

**Purpose**: Verify debug logging provides helpful information.

**Setup**:
1. Enable debug logging: `WP_MCP_AI_DEBUG = true`
2. Attempt various operations

**Steps**:
1. Try to retrieve a non-existent session key
2. Try to retrieve transcripts while logged out
3. Try to save a conversation with JetEngine disabled

**Expected Result** (in logs):
- ✅ Log entry shows "handle_chat_transcripts: Request parameters"
- ✅ Shows normalized session key vs. raw session key
- ✅ Shows user_id and assistant_id
- ✅ Log entry shows "handle_chat_transcripts: Error retrieving session"
- ✅ Shows error code and message
- ✅ Helps identify the root cause quickly

**Check Logs**:
```bash
wp option get wp_mcp_ai_recent_activity --format=json
wp option get wp_mcp_ai_recent_errors --format=json
```

---

## Browser Console Checks

Open browser DevTools (F12) and check console for:

**During Save**:
- [ ] No JavaScript errors
- [ ] Fetch request to `/chat-transcripts` with POST method
- [ ] Request payload includes: assistant_id, session_key, messages
- [ ] Response is logged (success or error)

**During Retrieval**:
- [ ] No JavaScript errors
- [ ] Fetch request to `/chat-transcripts?session_key=...`
- [ ] Response includes session data or appropriate error

---

## Database Verification

If JetEngine is active, check the database table:

```sql
-- Find the table (prefix may vary)
SHOW TABLES LIKE '%jet_cct_ai_chat_transcripts';

-- Check recent sessions
SELECT 
    session_key,
    user_id,
    assistant_id,
    LENGTH(session_key) as key_length,
    cct_created
FROM wp_jet_cct_ai_chat_transcripts
ORDER BY cct_created DESC
LIMIT 10;

-- Verify session key lengths
SELECT 
    MAX(LENGTH(session_key)) as max_length,
    MIN(LENGTH(session_key)) as min_length,
    AVG(LENGTH(session_key)) as avg_length
FROM wp_jet_cct_ai_chat_transcripts;
```

**Expected Result**:
- ✅ Session keys are stored correctly
- ✅ Max length ≤ 96 characters
- ✅ UUID format session keys preserved with hyphens
- ✅ No truncation or corruption

---

## Edge Cases to Test

### Edge Case 1: Very Long Session Key
Create a session key near the 96 character limit and verify it works.

### Edge Case 2: Special Characters
Session keys should only contain: `a-z`, `A-Z`, `0-9`, `_`, `-`
Verify other characters are stripped.

### Edge Case 3: Empty Conversation
Try to start a new conversation when the current one is empty.
Should clear immediately without save attempt.

### Edge Case 4: Network Failure
Simulate network failure (disable network in DevTools) during save.
Should show error and preserve conversation if user cancels.

---

## Success Criteria

All scenarios should pass with:
- ✅ No data loss under any circumstance (unless user explicitly chooses)
- ✅ Clear error messages guide users
- ✅ UUID session keys work flawlessly
- ✅ Logging helps diagnose issues
- ✅ Backward compatible with existing data

---

## Rollback Plan

If any critical issues are discovered:

1. **Immediate**: Deactivate the plugin update
2. **Restore**: Revert to previous version
3. **Report**: Document the failure scenario
4. **Fix**: Address the issue in development
5. **Retest**: Verify fix works
6. **Redeploy**: Try again

---

## Sign-Off

After completing all tests, fill out:

- [ ] All test scenarios passed
- [ ] No JavaScript errors in console
- [ ] No PHP errors in logs
- [ ] Database queries return expected results
- [ ] User experience is smooth and informative
- [ ] Documentation is accurate

**Tester Name**: ________________  
**Date**: ________________  
**Environment**: ________________ (staging/production)  
**WordPress Version**: ________________  
**PHP Version**: ________________  
**JetEngine Version**: ________________  

**Additional Notes**:
_____________________________________________
_____________________________________________
_____________________________________________

**Approved for Production**: [ ] Yes [ ] No

**Sign-Off**: ________________
