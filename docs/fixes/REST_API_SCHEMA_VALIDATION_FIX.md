# REST API Schema Validation Fix

## Problem

The WordPress REST API endpoints `/chat-client` and `/chat-transcripts` were returning 400 errors with the message:

```
"messages[7][content] does not match any of the expected formats"
```

This was causing chat functionality to break for users.

## Root Cause

The issue was in how the message schema was defined in `class-wp-mcp-ai-rest-chat-controller.php`. The `content` field used a `oneOf` constraint to allow multiple types:

```php
'content' => array(
    'description' => __( 'Message content...', 'wp-mcp-ai' ),
    'oneOf'       => array(
        array( 'type' => 'string' ),
        array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
        array( 'type' => 'null' ),
    ),
),
```

**The problem:** WordPress REST API's built-in `rest_validate_value_from_schema()` function has known issues with `oneOf` validation, particularly when dealing with mixed types (string, array, null). The validation runs BEFORE custom validation callbacks, so valid messages were being rejected before our custom validator could process them.

## Solution

We removed the `oneOf` constraint from the schema definition and moved all content type validation to the custom `validate_messages_array()` callback in `class-wp-mcp-ai-rest-validator.php`.

### Changes Made

1. **Schema Definition** (`class-wp-mcp-ai-rest-chat-controller.php`):
   - Removed the `oneOf` constraint
   - Added a comment explaining why we can't use `oneOf`
   - Content validation now relies entirely on the custom callback

2. **Custom Validator** (`class-wp-mcp-ai-rest-validator.php`):
   - Added explicit type validation for the `content` field
   - Validates that content is string, array, or null
   - For array content, validates that each part is an object/array
   - Provides clear error messages for invalid types

3. **Tests** (`test-rest-validator.php`):
   - Added test for string content (most common case)
   - Added test for array content (multimodal messages)
   - Added test for null content (assistant with tool_calls)
   - Added tests for invalid types (number, object)
   - Added test for malformed array content

## Valid Message Content Types

The `content` field can be one of three types:

### 1. String (Plain Text)
Most common for simple text messages:
```php
array(
    'role'    => 'user',
    'content' => 'Hello, how are you?',
)
```

### 2. Array (Multimodal Content)
Used for messages with images or other media:
```php
array(
    'role'    => 'user',
    'content' => array(
        array(
            'type' => 'text',
            'text' => 'What is in this image?',
        ),
        array(
            'type'      => 'image_url',
            'image_url' => array(
                'url' => 'https://example.com/image.jpg',
            ),
        ),
    ),
)
```

### 3. Null (Assistant with Tool Calls)
Used when assistant message contains only tool calls:
```php
array(
    'role'       => 'assistant',
    'content'    => null,
    'tool_calls' => array(
        array(
            'id'       => 'call_123',
            'type'     => 'function',
            'function' => array(
                'name'      => 'get_weather',
                'arguments' => '{"location":"London"}',
            ),
        ),
    ),
)
```

## Testing

Run the validator tests:
```bash
composer run test -- tests/test-rest-validator.php
```

The tests verify:
- ✓ String content is accepted
- ✓ Array content with proper structure is accepted
- ✓ Null content for assistant messages is accepted
- ✓ Invalid types (number, object) are rejected
- ✓ Malformed array content is rejected with helpful error messages

## Impact

This fix resolves the 400 errors on:
- `/wp-json/mcp-ai/v1/chat-client` (browser client chat)
- `/wp-json/mcp-ai/v1/chat-transcripts` (transcript saving)

Both endpoints now properly validate messages without false rejections.

## Lessons Learned

1. **Don't use `oneOf` in WordPress REST API schemas** - Use custom validation callbacks instead
2. **Custom validators run AFTER built-in validation** - If built-in validation fails, custom callbacks never run
3. **Always test with real-world data** - The issue only appeared with certain message types
4. **Provide clear error messages** - Help developers understand what went wrong

## Related Files

- `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` - Schema definition
- `includes/rest/class-wp-mcp-ai-rest-validator.php` - Custom validation logic
- `tests/test-rest-validator.php` - Comprehensive test coverage

## Future Considerations

If WordPress core improves `oneOf` support in future versions, we could revisit this approach. However, the current custom validation approach is more reliable and provides better error messages, so it may be preferable regardless.
