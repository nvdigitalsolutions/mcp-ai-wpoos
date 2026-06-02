# OpenAI Image Agentic Loop Fix

## Problem
When the `generate_openai_image` tool was used in an agentic loop, the generated images were not visible to the vision model in subsequent iterations. The images were being created and stored correctly in the Media Library, but the vision model couldn't "see" them to analyze or reference them.

## Root Cause
The issue was in how tool results were formatted for the agentic loop:

1. **Tool Execution**: The `generate_openai_image` tool correctly generated images and returned results with base64 data
2. **Sanitization**: The `sanitize_for_llm` method stripped base64 data to save tokens (correct behavior)
3. **Missing Image Reference**: The sanitized result didn't include an `image_url` structure
4. **Vision Limitation**: Tool messages (role: "tool") are not processed as visual content by vision models

## Solution
The fix involves two key changes:

### 1. Add `image_url` to Sanitized Results
Modified `WP_MCP_AI_Tool_Generate_OpenAI_Image::sanitize_for_llm()` to include an `image_url` structure:

```php
// Add image_url structure for the agentic loop.
// This allows vision models to "see" the generated image in subsequent iterations.
if ( isset( $result['url'] ) && '' !== $result['url'] ) {
    $sanitized['image_url'] = array(
        'url' => $result['url'],
    );
}
```

### 2. Extract Images and Create User Message
Added `WP_MCP_AI_Chat_Service::extract_images_from_tool_results()` to:
- Parse tool result JSON content
- Extract `image_url` structures
- Create a user message with `image_url` content blocks
- Append this message to the conversation after tool results

```php
// Example user message created:
array(
    'role' => 'user',
    'content' => array(
        array(
            'type' => 'text',
            'text' => 'Here are the generated images from the tool execution:',
        ),
        array(
            'type' => 'image_url',
            'image_url' => array(
                'url' => 'https://example.com/generated-image.png',
            ),
        ),
    ),
)
```

## How It Works

### Before the Fix
```
User: Generate an image of a sunset
  ↓
Assistant: [calls generate_openai_image tool]
  ↓
Tool Result (role: "tool"):
  content: '{"attachment_id":123,"url":"...","text":"Image created"}'
  ↓
Assistant: The image has been created. [Can't see the image]
```

### After the Fix
```
User: Generate an image of a sunset
  ↓
Assistant: [calls generate_openai_image tool]
  ↓
Tool Result (role: "tool"):
  content: '{"attachment_id":123,"url":"...","image_url":{"url":"..."},"text":"Image created"}'
  ↓
User Message (automatically added):
  content: [
    {type: "text", text: "Here are the generated images..."},
    {type: "image_url", image_url: {url: "https://..."}}
  ]
  ↓
Assistant: [Can now see and analyze the sunset image]
```

## Files Modified

1. **includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php**
   - Modified `sanitize_for_llm()` to include `image_url` structure

2. **includes/services/class-wp-mcp-ai-chat-service.php**
   - Added `extract_images_from_tool_results()` method
   - Modified agentic loop to call the new method after tool execution

3. **tests/test-openai-image-agentic-loop.php** (New)
   - Comprehensive tests for the new functionality

## Testing
The fix includes tests for:
- `sanitize_for_llm` correctly adding `image_url` structure
- `extract_images_from_tool_results` creating proper user messages
- Edge cases (no URL, no images found)

## Backwards Compatibility
- ✅ No breaking changes
- ✅ Gemini image generation continues to work (it already had this functionality)
- ✅ Existing OpenAI image generation still works for non-agentic use cases
- ✅ The user message is only added when images are detected in tool results

## Future Enhancements
Potential improvements for future consideration:
1. Support for multiple image formats (currently URL-based)
2. Image detail level control (low/high) from tool arguments
3. Option to disable automatic image injection for specific assistants
4. Support for other media types (audio, video) using similar pattern
