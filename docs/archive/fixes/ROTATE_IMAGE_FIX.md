# Fix for Rotate Image Tool Chat-Client Failure

## Issue
The rotate_image tool was failing in the chat-client endpoint with a "Fetch failed loading" error in the SSE (Server-Sent Events) stream.

## Root Cause
When the rotate_image tool was called by a guest user (user_id=0) without authentication, it correctly returned a WP_Error object:

```php
return new WP_Error( 
    'wp_mcp_ai_forbidden', 
    __( 'You must be authenticated to rotate images.', 'wp-mcp-ai' ), 
    array( 'status' => rest_authorization_required_code() ) 
);
```

However, this WP_Error object was not being properly converted to a serializable format before being:
1. Passed through sanitization methods
2. JSON-encoded for the SSE stream
3. Sent to the chat client

WP_Error objects contain closures and other non-serializable data that can cause `wp_json_encode()` to fail or produce invalid output, which caused the SSE stream to fail.

## Solution
Added a new `normalize_tool_result()` method in the `WP_MCP_AI_REST` class that converts WP_Error objects to a serializable array format:

```php
protected function normalize_tool_result( $result ) {
    if ( ! is_wp_error( $result ) ) {
        return $result;
    }

    // Convert WP_Error to a serializable array format.
    $error_data = $result->get_error_data();
    $error_array = array(
        'error'   => true,
        'code'    => $result->get_error_code(),
        'message' => $result->get_error_message(),
    );

    // Include error data if available (e.g., HTTP status codes).
    if ( ! empty( $error_data ) ) {
        $error_array['data'] = $error_data;
    }

    return $error_array;
}
```

This normalization is applied immediately after tool execution in both:
- Non-streaming chat path (line 2416)
- Streaming chat path (line 2852)

## Result
Tool errors are now properly serialized and can be:
- Safely JSON-encoded
- Sent through SSE streams without failures
- Displayed to users with appropriate error messages

The chat client's `extractGenericToolResponse()` function extracts the `message` field from the error array and displays it to the user.

## Testing
Created comprehensive unit tests in `tests/test-tool-error-normalization.php` that verify:
1. WP_Error objects are converted to arrays with correct structure
2. Successful results pass through unchanged
3. Normalized errors can be JSON-encoded
4. Errors without additional data are handled correctly

## Files Changed
- `includes/class-wp-mcp-ai-rest.php`: Added `normalize_tool_result()` method and applied it in both chat paths
- `tests/test-tool-error-normalization.php`: New test file for error normalization
