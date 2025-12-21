# Fix: Edit Gemini Image with Attached Files

## Problem Statement

When using the chat client with OpenAI as the provider, users were unable to:
1. Attach an image file to their message
2. Request edits to that attached image using the `edit_gemini_image` tool

The issue occurred because the `edit_gemini_image` tool's parameter schema was incomplete - it was missing the `file_id` and `url` parameters that the internal resolution logic actually supported.

## Root Cause

The `WP_MCP_AI_Tool_Edit_Gemini_Image` class had internal code that called `$this->resolve_attachment_id()` to resolve images from multiple sources (attachment_id, file_id, url), but:

1. **The trait was not included**: The class didn't use the `WP_MCP_AI_Attachment_File_Resolver` trait that provides this method
2. **Parameters not exposed**: The `file_id` and `url` parameters were not declared in `get_parameters_schema()`
3. **LLM couldn't discover them**: The AI model had no way to know it could pass attachment URLs to the tool

This created a mismatch between what the tool could do (handle URLs and file IDs) and what the LLM thought it could do (only attachment IDs and image_data).

## Solution Implemented

### 1. Added the Trait

```php
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';

class WP_MCP_AI_Tool_Edit_Gemini_Image ... {
    use WP_MCP_AI_Attachment_File_Resolver;
    // ...
}
```

This provides the `resolve_attachment_id()` method and helper methods for parameter schemas.

### 2. Exposed Missing Parameters

Added to the tool's parameter schema:

```php
'file_id' => $this->get_file_id_parameter_schema( 
    __( 'OpenAI or Gemini file identifier. Use this when the image was uploaded via the files endpoint.', 'wp-mcp-ai' ) 
),
'url' => $this->get_url_parameter_schema( 
    'image', 
    __( 'URL of the image to edit. Can be a WordPress media URL (e.g., from user attachments) or external URL.', 'wp-mcp-ai' ) 
),
```

### 3. Enhanced Descriptions

- **Tool description**: Now explicitly mentions it can edit images from user attachments
- **Shortcut payloads**: Updated to guide the LLM to use the `url` parameter when users attach images
- **Parameter descriptions**: Made clearer which parameter to use in which context

## How It Works Now

### User Workflow

1. **User attaches image** in chat interface:
   ```
   Attachment: https://example.com/wp-content/uploads/2025/12/photo.jpg
   ```

2. **User requests edit**:
   ```
   "edit Gemini image to remove the background"
   ```

3. **LLM extracts attachment URL** and calls tool:
   ```json
   {
     "tool": "edit_gemini_image",
     "arguments": {
       "prompt": "remove background, make transparent",
       "url": "https://example.com/wp-content/uploads/2025/12/photo.jpg"
     }
   }
   ```

4. **Tool processes**:
   - Resolves URL to attachment ID (if local WordPress image)
   - Or downloads image from URL (if remote)
   - Sends to Gemini API for editing
   - Saves edited result to Media Library

5. **User receives**:
   - New edited image in Media Library
   - Visible in chat with attachment info

### Technical Flow

```
User Message (with attachment URL)
    ↓
OpenAI LLM reads message + tool schema
    ↓
LLM sees "url" parameter is available
    ↓
LLM extracts URL from user's attachment
    ↓
edit_gemini_image tool called with url parameter
    ↓
resolve_attachment_id() processes URL:
  - Check if local WordPress URL → get attachment_id
  - Or prepare for remote download
    ↓
Tool downloads/reads image data
    ↓
Gemini API edits the image
    ↓
Result saved to WordPress Media Library
    ↓
Response sent back to chat
```

## Parameter Priority Order

The tool now accepts images in this priority order:

1. **attachment_id** (integer): Direct WordPress attachment ID
2. **file_id** (string): OpenAI/Gemini file identifier
3. **url** (string): Image URL (local or remote) - **Recommended for chat attachments**
4. **image_url** (string): Legacy parameter (use `url` instead)
5. **image_data** (string): Base64-encoded blob data

## Cross-Provider Compatibility

**Key Feature**: This works even when **OpenAI is the chat provider** because:

- The chat conversation uses OpenAI's API
- But the `edit_gemini_image` tool uses Gemini's API
- The tool has `model_requirements` that specify it needs Gemini:

```php
'model_requirements' => array(
    'providers' => array( 'gemini' ),
    'models'    => array( 'gemini-2.5-flash-image', ... ),
    'required'  => true,
),
```

This ensures proper API routing in multi-provider environments.

## Testing

### Manual Testing Steps

1. **Setup**: Configure both OpenAI and Gemini API keys in plugin settings
2. **Create assistant** with OpenAI as the provider
3. **Open chat client** with that assistant
4. **Attach an image** using the attach button
5. **Send message**: "edit this image to remove the background"
6. **Verify**: Tool should successfully edit and return result

### Expected Behavior

- ✅ LLM recognizes the attached image
- ✅ LLM calls `edit_gemini_image` with `url` parameter
- ✅ Tool downloads/resolves the image
- ✅ Gemini API processes the edit
- ✅ Result appears in chat
- ✅ New image saved to Media Library

### Troubleshooting

If it doesn't work:

1. **Check API keys**: Both OpenAI and Gemini must be configured
2. **Check URL accessibility**: Image URL must be publicly accessible
3. **Check logs**: Enable debug logging in plugin settings
4. **Check tool availability**: Ensure `edit_gemini_image` is enabled for the assistant
5. **Check permissions**: User must have capability to upload files

## Related Files

- `includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php` - Main tool implementation
- `includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php` - Trait for multi-source resolution
- `docs/edit-gemini-image-blob-usage.md` - Updated usage documentation
- `tests/test-edit-gemini-image-local-url.php` - Tests for URL handling
- `tests/test-edit-gemini-image-blob.php` - Tests for blob data handling

## Future Enhancements

Potential improvements:

1. **Auto-detect image in message**: Could parse user message for image references automatically
2. **Multi-image support**: Edit multiple attached images at once
3. **Preview before save**: Show preview of edited image before committing to Media Library
4. **Undo support**: Allow reverting edits by keeping original image linked
5. **Batch operations**: Apply same edit to multiple images

## See Also

- [Tool Reference](reference/tools/tool-reference.md) - Complete list of all tools
- [Attachment Resolution](../includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php) - Trait documentation
- [REST API](reference/api/rest-api.md) - API endpoint documentation
- [Chat Client](../assets/js/chat.js) - Frontend implementation
