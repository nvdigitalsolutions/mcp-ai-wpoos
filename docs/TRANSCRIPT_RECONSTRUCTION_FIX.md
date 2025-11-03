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
1. Enable logging in WP MCP AI settings
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

## Verification Steps

To verify the fix works:

1. **Database Check**: Query the `wp_jet_cct_ai_chat_transcripts` table to ensure records exist with the correct `session_key`
2. **REST API Check**: Call `/wp-json/mcp-ai/v1/chat-transcripts?session_key=<key>` and verify the response contains all messages
3. **Browser Console**: Check for JavaScript errors when viewing chat history
4. **Message Content**: Verify that assistant messages with tool calls appear in the transcript

## Related Code

- `class-wp-mcp-ai-chat-transcript-recorder.php`: Records transcripts to the database
- `assets/js/user-chats.js`: Frontend JavaScript that fetches and displays transcripts
- `tests/test-chat-transcripts.php`: Existing tests for transcript recording
