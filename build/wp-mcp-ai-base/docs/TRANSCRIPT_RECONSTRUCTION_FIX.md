# Chat Transcript Reconstruction Fix

## Problem Statement

The chat transcript functionality was experiencing issues where messages were not being properly displayed when viewing previous chat sessions. The problem was identified in the `reconstruct_transcript` flow within the REST API endpoint `/chat-transcripts`.

## Root Causes Identified

### 1. Assistant Messages with Tool Calls Being Skipped

**Location**: `includes/class-wp-mcp-ai-rest.php`, `extract_response_messages()` method (line ~5539)

**Issue**: The logic for including assistant messages in the reconstructed transcript was too strict:

```php
if ( '' !== $content || 'tool' === $role ) {
    $messages[] = array(
        'role'    => $role,
        'content' => $content,
    );
}
```

This condition meant that:
- Assistant messages WITH text content were included ✓
- Tool messages were included (even with empty content) ✓
- Assistant messages WITHOUT content were SKIPPED ✗

**Problem**: When the assistant makes a tool call, OpenAI returns a message with:
- `role: "assistant"`
- `content: null` or `content: ""`
- `tool_calls: [...]` array

The original code would skip this assistant message entirely because it had no text content, even though it had tool_calls.

**Fix**: Modified the condition to also check for the presence of tool_calls:

```php
$has_tool_calls = ! empty( $choice['message']['tool_calls'] ) && is_array( $choice['message']['tool_calls'] );

if ( '' !== $content || 'tool' === $role || $has_tool_calls ) {
    $messages[] = array(
        'role'    => $role,
        'content' => $content,
    );
}
```

Now assistant messages are included if:
- They have content, OR
- They are tool messages, OR
- They have tool_calls (even with empty content)

## Changes Made

### 1. Enhanced `extract_response_messages()` Method

**File**: `includes/class-wp-mcp-ai-rest.php`

- Added check for `tool_calls` before deciding to skip assistant messages
- Added comprehensive debug logging to track message extraction
- Improved error handling for malformed payloads

### 2. Enhanced `extract_request_messages()` Method

**File**: `includes/class-wp-mcp-ai-rest.php`

- Added comprehensive debug logging to track message extraction
- Improved error handling for malformed payloads
- Better logging of skipped messages to aid debugging

### 3. Enhanced `get_transcript_session()` Method

**File**: `includes/class-wp-mcp-ai-rest.php`

- Added detailed logging throughout the reconstruction process
- Tracks row processing, message extraction, and appending
- Helps identify where messages might be lost during reconstruction

### 4. Enhanced `append_new_messages()` Method

**File**: `includes/class-wp-mcp-ai-rest.php`

- Added logging for deduplication logic
- Tracks how many messages are skipped vs. added
- Helps debug potential issues with message ordering

## Debug Logging

The fix includes extensive debug logging using `WP_MCP_AI_Logger::log_event()` to help diagnose issues:

1. **extract_request_messages**: Logs payload structure, skipped messages, and final count
2. **extract_response_messages**: Logs choices processing, tool_calls detection, and message inclusion decisions
3. **get_transcript_session**: Logs row processing, message extraction counts, and reconstruction progress
4. **append_new_messages**: Logs deduplication decisions and final message counts

To view these logs:
1. Enable logging in WP oOS settings
2. Set log level to "debug"
3. Review logs after fetching chat transcripts

## Testing

Created comprehensive test suite in `tests/test-transcript-reconstruction.php` covering:

1. Basic message extraction
2. Assistant messages with tool_calls
3. Empty content handling
4. Malformed JSON handling
5. Message deduplication
6. Edge cases

## Impact

This fix ensures that:
- Chat transcripts correctly display all messages, including those with tool calls
- Assistant responses with tool invocations are not lost
- The conversation flow is preserved in the correct order
- Users can review their complete chat history

## Database Schema Verification

The JetEngine CCT table `wp_jet_cct_ai_chat_transcripts` has the following schema:

| Field | Type | Description |
|-------|------|-------------|
| `id` | auto-increment | Primary key |
| `session_key` | text | Correlation key grouping related messages (max 96 chars) |
| `user_id` | number | WordPress user ID |
| `assistant_id` | text | Internal assistant identifier |
| `assistant_model` | text | Model string (e.g., "gpt-4o-mini") |
| `request_payload` | textarea/JSON | Full request payload with messages array |
| `response_payload` | textarea/JSON | Assistant response with choices array |
| `metadata` | textarea/JSON | Usage, tokens, finish_reasons, etc. |
| `latency_ms` | number | Response time in milliseconds |
| `request_started_at` | datetime-local | When request processing began |
| `response_completed_at` | datetime-local | When response was completed |
| `cct_created` | datetime | Auto-generated creation timestamp |

The database query in `get_transcript_session()` correctly:
- Filters by `session_key` and `user_id`
- Orders by `cct_created ASC, id ASC` for chronological reconstruction
- Retrieves all necessary fields for message extraction

## Frontend JavaScript Verification

The frontend code in `assets/js/user-chats.js` correctly:
- Fetches transcript data from `/wp-json/mcp-ai/v1/chat-transcripts?session_key=<key>&user_id=<id>`
- Handles both the session list and individual session details
- Renders messages from the `session.messages` array
- Displays role labels, content, and timestamps
- Shows appropriate error messages when data is unavailable

The `renderConversation()` function (lines 348-440):
- Checks for `session.messages` array
- Iterates through all messages
- Normalizes roles (user, assistant, tool, system)
- Displays message content and metadata
- Shows "No messages" placeholder when array is empty

## Verification Steps

To verify the fix works:

1. **Database Check**: 
   - Query: `SELECT * FROM wp_jet_cct_ai_chat_transcripts WHERE session_key = '<your-key>'`
   - Verify records exist with correct `session_key` and non-empty payloads
   - Check that `request_payload` and `response_payload` contain valid JSON

2. **REST API Check**: 
   - Call: `GET /wp-json/mcp-ai/v1/chat-transcripts?session_key=<key>&user_id=<id>`
   - Verify response has structure: `{ "session": { "messages": [...], ... } }`
   - Check that all messages (including assistant messages with tool calls) appear
   - Verify message order is chronological

3. **Browser Console**: 
   - Open Developer Tools → Console tab
   - Load the chat history page
   - Check for JavaScript errors or warnings
   - Verify network requests succeed (200 status)

4. **Message Content**: 
   - Verify that assistant messages with tool calls appear in the transcript
   - Check that tool call messages show "Tool call: <function_name>"
   - Ensure message ordering matches the conversation flow

5. **Debug Logging** (if issues persist):
   - Enable debug logging in WP oOS settings
   - Review logs for the extraction process
   - Look for "extract_request_messages" and "extract_response_messages" events
   - Check message counts and any skipped messages

## Related Code

- `class-wp-mcp-ai-chat-transcript-recorder.php`: Records transcripts to the database
- `class-wp-mcp-ai-jetengine-cct.php`: Defines the database schema and CCT registration
- `class-wp-mcp-ai-rest.php`: REST API endpoints and transcript reconstruction logic
- `assets/js/user-chats.js`: Frontend JavaScript that fetches and displays transcripts
- `tests/test-chat-transcripts.php`: Existing tests for transcript recording
- `tests/test-transcript-reconstruction.php`: New tests for message extraction and reconstruction
