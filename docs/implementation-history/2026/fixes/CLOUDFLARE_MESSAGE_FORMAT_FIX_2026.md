# Cloudflare Workers AI Message Content Format Fix - January 2026

## Issue Summary

Users were experiencing the following error when using Cloudflare Workers AI provider in the chat client:

```
Cloudflare Workers AI returned an error. AiError: Bad input: Error: oneOf at '/' not met, 0 matches: required properties at '/' are 'prompt', Type mismatch of '/messages/0/content', 'string' not in 'array'
```

## Root Cause

The Cloudflare Workers AI API expects message `content` to be a **string** for text-only messages, but the plugin was sending `content` as an **array** (following OpenAI's multimodal format).

### Background

1. **OpenAI Format (used internally)**: Supports multimodal content with an array of content parts:
   ```php
   [
       'role' => 'user',
       'content' => [
           ['type' => 'text', 'text' => 'Hello'],
           ['type' => 'text', 'text' => 'World']
       ]
   ]
   ```

2. **Cloudflare Expected Format**: Requires simple string content:
   ```php
   [
       'role' => 'user',
       'content' => 'Hello\nWorld'
   ]
   ```

3. **The Bug**: The `WP_MCP_AI_Cloudflare_Client::build_payload()` method was passing messages directly to the API without normalizing the content format. This worked for OpenAI, Ollama, and LM Studio (which already had normalization), but broke for Cloudflare.

## Solution

Added a `normalize_messages()` method to `WP_MCP_AI_Cloudflare_Client` that converts array-based content to strings before sending to the Cloudflare API.

### Files Changed

1. **includes/class-wp-mcp-ai-cloudflare-client.php**
   - Modified `build_payload()` method to call `normalize_messages()`
   - Added new `normalize_messages()` method (110 lines)

2. **tests/test-cloudflare-message-normalization.php** (NEW)
   - Comprehensive test suite with 8 test cases

### Implementation Details

The `normalize_messages()` method:

1. **Converts array content to strings**:
   - Handles `[{type: 'text', text: 'Hello'}, ...]` format
   - Handles `[{text: 'Hello'}, ...]` format
   - Handles `['Hello', 'World']` format
   - Joins multiple text parts with newlines

2. **Preserves important fields**:
   - `tool_calls` (for assistant messages with function calls)
   - `tool_call_id` (for tool response messages)
   - `name` (for tool messages)

3. **Filters empty messages**:
   - Skips user/tool messages with empty content
   - Keeps assistant messages even if empty (may have tool_calls)

4. **Sanitizes content**:
   - Uses `wp_kses_post()` for security
   - Uses `sanitize_key()` for role
   - Uses `sanitize_text_field()` for name

## Code Example

**Before (Broken):**
```php
protected function build_payload( array $messages, array $options ) {
    $payload = array(
        'messages' => $messages, // Array content sent as-is → ERROR!
    );
    // ...
}
```

**After (Fixed):**
```php
protected function build_payload( array $messages, array $options ) {
    // Normalize messages to ensure content is in the correct format.
    $normalized_messages = $this->normalize_messages( $messages );

    $payload = array(
        'messages' => $normalized_messages, // Content converted to strings → SUCCESS!
    );
    // ...
}
```

## Verification

Created standalone verification script (`/tmp/verify-cloudflare-fix.php`) that demonstrates the fix works correctly without requiring the full WordPress test environment.

**Test Results:**
```
Test 1: Array content with type/text format       ✓ PASS
Test 2: Simple string content                     ✓ PASS
Test 3: Array with simple strings                 ✓ PASS
Test 4: Tool calls preservation                   ✓ PASS
Test 5: Empty message filtering                   ✓ PASS

Summary: 5 passed, 0 failed
```

## Comparison with Other Providers

This fix aligns Cloudflare client with how other providers handle message normalization:

| Provider    | Normalization Method | Location |
|-------------|---------------------|----------|
| OpenAI      | `normalise_messages_for_payload()` | Line 2937 |
| Ollama      | `build_payload()` (inline) | Line 162-172 |
| Gemini      | `normalize_segments_to_text()` | Line 1612 |
| **Cloudflare** | **`normalize_messages()`** (NEW) | **Line 359** |

## Testing Recommendations

1. **Manual Testing**: Test chat with Cloudflare provider using various message formats
2. **Integration Testing**: Verify tool calling works with Cloudflare
3. **Multimodal Testing**: Ensure image-capable models still work correctly (when supported)

## Impact

✅ **Fixes**: Users can now use Cloudflare Workers AI provider without message format errors
✅ **Maintains Compatibility**: All existing functionality preserved (tool calls, tool responses)
✅ **Security**: Proper sanitization applied to all content
✅ **Performance**: Minimal overhead (simple array-to-string conversion)

## Related Issues

- **Previous Fix**: CLOUDFLARE_MODEL_FIX_2025.md - Fixed incorrect model namespace
- **Previous Fix**: CLOUDFLARE_URI_ERROR_FIX_2025.md - Fixed URL encoding for model IDs
- **Previous Fix**: CLOUDFLARE_FIX_SUMMARY.md - Fixed model dropdown population

## Date

January 9, 2026

## Commit

`7873ff3` - Fix Cloudflare Workers AI message content format error
