# Cloudflare Tool_Calls Format Normalization Fix

**Date**: January 10, 2026  
**Commit**: 7dac9e5  
**Issue**: Cloudflare returns tool_calls in simpler format causing "Tool call missing function name" error

## Problem

After implementing the initial capability filtering and validation fixes, a user reported that Cloudflare was still returning errors when trying to use tools. The error message was:

```
"Tool call missing function name."
```

The user's test case showed:
```json
{
    "role": "assistant",
    "content": "",
    "tool_calls": [
        {
            "name": "web_search",
            "arguments": {
                "query": "things you can do"
            }
        }
    ]
}
```

## Root Cause

Cloudflare Workers AI returns tool_calls in **two different formats**:

### Format 1: Cloudflare Simpler Format
```json
{
    "name": "web_search",
    "arguments": {"query": "..."}
}
```

### Format 2: OpenAI-Compatible Format
```json
{
    "function": {
        "name": "web_search",
        "arguments": "{\"query\":\"...\"}"
    }
}
```

The initial validation code only handled Format 2 (OpenAI format) and rejected Format 1 as malformed. When Cloudflare returned Format 1, the tool_calls were being passed through without normalization, causing the `execute_tool_call_internal()` method to fail because it expects `$tool_call['function']['name']` to exist.

## Solution

Added format detection and normalization logic in the `normalize_response()` method of the Cloudflare client.

### Detection Logic

```php
// Check for OpenAI format first (function.name)
if ( isset( $tool_call['function'] ) && 
     is_array( $tool_call['function'] ) && 
     isset( $tool_call['function']['name'] ) && 
     ! empty( $tool_call['function']['name'] ) ) {
    // Already in OpenAI format, use as-is
    $normalized_tool_call = $tool_call;
} 
// Check for Cloudflare simpler format (name at top level)
elseif ( isset( $tool_call['name'] ) && ! empty( $tool_call['name'] ) ) {
    // Normalize to OpenAI format
    // ...
}
```

### Normalization Process

For Cloudflare simpler format:

1. **Extract name** from top level
2. **Convert arguments** from array/object to JSON string:
   ```php
   $arguments = isset( $tool_call['arguments'] ) ? $tool_call['arguments'] : array();
   if ( is_array( $arguments ) || is_object( $arguments ) ) {
       $arguments = wp_json_encode( $arguments );
   }
   ```

3. **Build OpenAI-compatible structure**:
   ```php
   $normalized_tool_call = array(
       'id'       => isset( $tool_call['id'] ) ? $tool_call['id'] : 'call_' . uniqid(),
       'type'     => 'function',
       'function' => array(
           'name'      => $tool_call['name'],
           'arguments' => $arguments,
       ),
   );
   ```

4. **Log transformation** for debugging:
   ```php
   WP_MCP_AI_Logger::log_event(
       'cloudflare_tool_call_normalized',
       'Normalized Cloudflare simpler format to OpenAI format',
       array(
           'original_format' => $tool_call,
           'normalized'      => $normalized_tool_call,
       )
   );
   ```

## Code Changes

**File**: `includes/class-wp-mcp-ai-cloudflare-client.php`

**Lines**: 621-680 (updated validation and normalization logic)

**Key Changes**:
- Added detection for both OpenAI and Cloudflare formats
- Added normalization from Cloudflare format to OpenAI format
- Added argument conversion from object to JSON string
- Added unique ID generation when missing
- Added logging for format transformation

## Test Coverage

**File**: `tests/test-cloudflare-tool-calls-validation.php`

**New Tests Added**:

### 1. `test_cloudflare_simpler_format_normalized`
Tests that Cloudflare's simpler format is properly normalized:
- Input: `{"name": "web_search", "arguments": {"query": "..."}}`
- Validates output has `function.name` structure
- Validates arguments are converted to JSON string
- Verifies finish_reason is set to "tool_calls"

### 2. `test_mixed_cloudflare_and_openai_formats`
Tests handling both formats in the same response:
- First tool_call in Cloudflare format
- Second tool_call in OpenAI format
- Validates both are included in output
- Verifies both have correct structure

**Total Test Coverage**: 14 test methods covering all validation and normalization scenarios

## Impact

### Before Fix
- ❌ Cloudflare simpler format caused "Tool call missing function name" error
- ❌ Users had to use manage_options permission to bypass issue
- ❌ Tools filtered by capability couldn't be called
- ❌ Confusing error messages

### After Fix
- ✅ Both Cloudflare and OpenAI formats handled correctly
- ✅ Automatic normalization to consistent format
- ✅ Capability filtering works as intended
- ✅ Clear logging of format transformations
- ✅ Users can use tools without admin permissions

## Verification

### Manual Testing
User confirmed the fix resolves the issue - tools now work without requiring manage_options permission.

### Automated Testing
- All 14 test methods pass
- Both format variations covered
- Edge cases tested (empty, malformed, mixed)

### Code Quality
- ✅ PHP syntax validated
- ✅ Follows WordPress Coding Standards
- ✅ Comprehensive logging added
- ✅ Clear comments explaining logic

## Related Issues

**Original Issue**: Capability filtering and tool execution problems
**First Fix**: Added capability filtering in `build_tools_payload()`
**This Fix**: Format normalization for Cloudflare tool_calls

## Future Considerations

1. **Monitor Logs**: Watch for `cloudflare_tool_call_normalized` events to see which format Cloudflare uses most often

2. **Documentation**: Update Cloudflare integration docs to mention both supported formats

3. **Performance**: Normalization adds minimal overhead (single pass through tool_calls array)

4. **Compatibility**: Solution maintains backward compatibility with existing OpenAI format responses

## References

- User report: PR comment showing Cloudflare simpler format
- Cloudflare Workers AI documentation (both formats are valid)
- OpenAI function calling format specification
- WordPress coding standards for format conversion
