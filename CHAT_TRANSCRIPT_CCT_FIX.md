# Fix: Chat Transcript Recording to CCT

## Issue Summary
Messages were not being saved to the JetEngine Custom Content Type (CCT) database, preventing users from seeing their chat history in the chat-client list.

## Root Cause
The `WP_MCP_AI_Chat_Service::save_chat_transcript()` method had three critical bugs:

1. **Incorrect instantiation**: Attempted to create an instance with `new WP_MCP_AI_Chat_Transcript_Recorder()` when the class only contains static methods
2. **Non-existent method call**: Called `$recorder->record_transcript()` which doesn't exist (the actual method is the static `record()`)
3. **Wrong parameters**: The method signature and parameters didn't match what `WP_MCP_AI_Chat_Transcript_Recorder::record()` expects

## Files Changed

### `includes/services/class-wp-mcp-ai-chat-service.php`

**Before:**
```php
private function save_chat_transcript( $assistant_id, $messages, $response, $transcript_context ) {
    if ( ! class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
        return;
    }

    $recorder = new WP_MCP_AI_Chat_Transcript_Recorder(); // ❌ Wrong: class has only static methods
    
    $session_key = $transcript_context['session_key'] ?? '';
    $duration    = 0;
    
    if ( isset( $transcript_context['request_started_at'], $transcript_context['response_completed_at'] ) ) {
        $duration = $transcript_context['response_completed_at'] - $transcript_context['request_started_at'];
    }
    
    $recorder->record_transcript( // ❌ Wrong: method doesn't exist
        $assistant_id,
        $messages,
        $response,
        $session_key,
        $duration
    );
}
```

**After:**
```php
private function save_chat_transcript( $assistant_id, $messages, $options, $response, $transcript_context, $user_id, $request ) {
    if ( ! class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
        return;
    }

    // If no request object provided, we cannot save the transcript.
    if ( ! $request instanceof WP_REST_Request ) {
        WP_MCP_AI_Logger::log_error(
            'Cannot save chat transcript without WP_REST_Request object',
            array(
                'assistant_id' => $assistant_id,
                'user_id'      => $user_id,
            )
        );
        return;
    }

    // Call the static record method with correct parameters. ✅
    WP_MCP_AI_Chat_Transcript_Recorder::record(
        $assistant_id,
        $messages,
        $options,
        $response,
        $request,
        $user_id,
        $transcript_context
    );
}
```

**Additional changes:**
- Updated `process_chat_request()` method signature to accept `WP_REST_Request $request` parameter
- Updated the call to `save_chat_transcript()` to pass the new required parameters

## Testing

Created comprehensive test suite in `tests/test-chat-service-transcript-recording.php`:

1. **test_chat_service_saves_transcript_correctly**: Verifies that transcripts are saved with correct data structure
2. **test_chat_service_handles_missing_request_gracefully**: Ensures the service logs an error but doesn't crash when request object is missing

## Impact

This fix ensures that:
- ✅ Chat messages are properly saved to the JetEngine CCT database
- ✅ Users can see their chat history in the chat-client list
- ✅ The internal API correctly persists conversation data
- ✅ Session keys, user IDs, and timestamps are properly recorded
- ✅ Graceful error handling when WP_REST_Request is not available

## Related Code

The correct usage pattern is already implemented in `WP_MCP_AI_REST` class:

```php
if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
    WP_MCP_AI_Chat_Transcript_Recorder::record(
        $assistant_id,
        $messages,
        $options,
        $response,
        $request,
        $user_id,
        $transcript_context
    );
}
```

## Notes for Future Development

When calling `WP_MCP_AI_Chat_Service::process_chat_request()`, ensure you pass the `WP_REST_Request` object as the 8th parameter if you want transcripts to be saved:

```php
$chat_service->process_chat_request(
    $assistant_id,
    $messages,
    $options,
    $assistant_config,
    $transcript_context,
    $user_id,
    $max_iterations,
    $request // ← Required for transcript saving
);
```
