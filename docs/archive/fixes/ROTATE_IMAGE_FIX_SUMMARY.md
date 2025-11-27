# Rotate Image Tool Fix - Summary

## Issue Resolved
Fixed SSE (Server-Sent Events) streaming failures when the rotate_image tool (or any tool) returns a WP_Error object in the chat-client endpoint.

## Error Manifestation
```
[WP oOS] Streaming response received: {status: 200, statusText: '', ok: true, headers: {…}}
[WP oOS] Created streaming message element
[WP oOS] Starting SSE stream processing
Fetch failed loading: POST "https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/chat-client"
[WP oOS] SSE stream completed: {totalContentLength: 0, contentSample: ''}
```

## Root Cause
When a guest user (user_id=0) attempted to use the rotate_image tool, the tool correctly returned a WP_Error for authentication failure. However, this WP_Error object:
- Contains closures and non-serializable data
- Cannot be safely JSON-encoded
- Caused wp_json_encode() to fail or produce invalid output
- Resulted in SSE stream corruption and "Fetch failed loading" errors

## Solution Implemented

### 1. Added normalize_tool_result() Method
Location: `includes/class-wp-mcp-ai-rest.php:8319-8338`

Converts WP_Error objects to serializable arrays:
```php
array(
    'error'   => true,
    'code'    => 'wp_mcp_ai_forbidden',
    'message' => 'You must be authenticated to rotate images.',
    'data'    => array( 'status' => 401 )
)
```

### 2. Applied Normalization in Both Chat Paths
- **Non-streaming**: Line 2416
- **Streaming (SSE)**: Line 2852

Both paths now normalize tool results immediately after execution, before sanitization and JSON encoding.

### 3. Frontend Compatibility
The normalized error format is fully compatible with the frontend's `extractGenericToolResponse()` function, which extracts the `message` field for display to users.

## Benefits

1. **Prevents SSE Stream Failures**: Tool errors no longer corrupt the event stream
2. **Consistent Error Handling**: All tools that return WP_Error are automatically handled
3. **User-Friendly Messages**: Error messages are properly displayed in the chat UI
4. **Maintainable**: Future tools don't need special error handling code

## Testing

### Unit Tests
- `tests/test-tool-error-normalization.php`: 4 tests covering:
  - WP_Error conversion to arrays
  - Successful results passing through unchanged
  - JSON encoding of normalized errors
  - Errors with and without additional data

### Integration Tests
- `tests/test-tool-error-chat-integration.php`: 3 tests covering:
  - rotate_image error normalization in chat context
  - Successful tool results remain unchanged
  - Frontend message extraction compatibility
  - JSON round-trip data preservation

## Files Modified

1. **includes/class-wp-mcp-ai-rest.php**
   - Added `normalize_tool_result()` method (33 lines)
   - Applied normalization in non-streaming path (3 lines)
   - Applied normalization in streaming path (3 lines)

2. **tests/test-tool-error-normalization.php** (NEW)
   - 124 lines of unit tests

3. **tests/test-tool-error-chat-integration.php** (NEW)
   - 144 lines of integration tests

4. **ROTATE_IMAGE_FIX.md** (NEW)
   - 71 lines of documentation

## Impact Analysis

### Positive Impacts
- ✅ Fixes rotate_image tool failure in chat-client
- ✅ Fixes potential failures in ALL tools that return WP_Error
- ✅ Improves error visibility for users
- ✅ Prevents SSE stream corruption
- ✅ No breaking changes to existing functionality

### Backward Compatibility
- ✅ Successful tool results pass through unchanged
- ✅ Existing error handling code continues to work
- ✅ Frontend code requires no changes
- ✅ No API contract changes

## Security Considerations
- Error messages are properly sanitized before display
- HTTP status codes preserved in error data
- No sensitive information exposed
- Authentication errors properly communicated

## Performance Impact
- Negligible: Single `is_wp_error()` check per tool execution
- No additional database queries
- No network requests
- Minimal memory overhead

## Future Recommendations

1. **Tool Development**: Always return WP_Error for error conditions, normalization handles it automatically
2. **Error Messages**: Include clear, user-friendly messages in WP_Error objects
3. **Error Data**: Use error data parameter for HTTP status codes and debugging info
4. **Testing**: Include authentication failure tests for tools that require authentication

## Related Issues
- Similar issue could affect other tools requiring authentication:
  - edit_gemini_image
  - generate_gemini_image
  - generate_openai_image
  - All tools extending WP_MCP_AI_Tool_Image_Base

These tools now benefit from the same fix without modification.
