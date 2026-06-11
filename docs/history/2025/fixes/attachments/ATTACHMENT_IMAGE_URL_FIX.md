# Fix: Add image_url Structure to Attachment Segments for Agentic Workflows

## Issue #2108

**Problem**: When users attach files via the "attach file" button in the shortcode chat client with OpenAI provider, the AI models couldn't "see" the attached images. This prevented agentic workflows from working properly with user-attached files.

## Background

When the OpenAI image generation tool (`generate_openai_image`) creates images, it returns a result with an `image_url` structure:

```php
// From WP_MCP_AI_Tool_Generate_OpenAI_Image::sanitize_for_llm()
if ( isset( $result['url'] ) && '' !== $result['url'] ) {
    $sanitized['image_url'] = array(
        'url' => $result['url'],
    );
}
```

This `image_url` structure is what allows OpenAI's vision models to "see" the generated image in subsequent agentic loop iterations.

## Root Cause

The `WP_MCP_AI_Message_Attachments::prepare_input_image_segment()` method processes image segments through three code paths:

1. **URL path** (lines 384-420): When segment has a direct URL → ✅ Already had `image_url` structure
2. **file_id path** (lines 438-494): When segment has an OpenAI/Gemini file_id → ❌ **Missing** `image_url` structure
3. **attachment_id path** (lines 497-562): When segment has a WordPress attachment_id → ✅ Already had `image_url` structure

The file_id path was missing the `image_url` structure, which prevented vision models from accessing attached images when they were processed through this code path.

## Solution

Added the `image_url` structure to the file_id path in `includes/class-wp-mcp-ai-message-attachments.php` (lines 478-480):

```php
// Add file metadata for agentic workflow (following OpenAI image tool pattern).
if ( isset( $resolved['attachment_id'] ) && $resolved['attachment_id'] > 0 ) {
    $attachment_id = absint( $resolved['attachment_id'] );
    // Preserve attachment_id for agentic workflows
    $prepared['attachment_id'] = $attachment_id;
    $image_url                 = wp_get_attachment_url( $attachment_id );
    if ( ! empty( $image_url ) ) {
        $prepared['url'] = esc_url_raw( $image_url );
        // Add image_url structure for agentic workflows (same as OpenAI image tool)
        // This allows vision models to "see" the image in the agentic loop
        $prepared['image_url'] = array( 'url' => esc_url_raw( $image_url ) );
    }
}
```

## Complete Segment Structure

After this fix, all image segments processed through any of the three code paths include:

| Field | Type | Description | Added In |
|-------|------|-------------|----------|
| `type` | string | Segment type ('input_image') | Core |
| `file_id` | string | Provider-specific file ID | Core |
| `image_url` | array | Vision model access structure | **This PR** |
| `image_url['url']` | string | Direct HTTP/HTTPS URL | **This PR** |
| `url` | string | Direct URL (compatibility) | PR #2107 |
| `attachment_id` | int | WordPress attachment ID | PR #2107 |
| `file_name` | string | Original filename | PR #2106 |
| `mime_type` | string | MIME type | PR #2106 |
| `bytes` | int | File size | PR #2106 |
| `caption` | string | Image caption (optional) | Core |
| `detail` | string | Vision detail level (optional) | Core |

## Benefits

1. ✅ **Agentic workflows work**: AI models can now "see" user-attached images
2. ✅ **Consistent behavior**: All three code paths return the same structure
3. ✅ **Follows established pattern**: Matches OpenAI image tool behavior exactly
4. ✅ **Backward compatible**: Only adds fields, doesn't remove any
5. ✅ **Provider agnostic**: Works with OpenAI, Gemini, and other vision models
6. ✅ **Minimal change**: Only 3 lines added to fix the issue

## Testing

Created comprehensive test suite in `tests/test-attachment-image-url-structure.php`:

1. **test_image_segment_with_attachment_id_includes_image_url**: Verifies attachment_id path
2. **test_image_segment_with_url_includes_image_url**: Verifies URL path
3. **test_image_segment_with_file_id_includes_image_url**: Verifies file_id path (the fix)
4. **test_image_url_structure_matches_openai_pattern**: Ensures consistency
5. **test_attached_file_creates_openai_compatible_structure**: Integration test

All tests verify:
- `image_url` structure is present
- `image_url` contains `url` field
- URL matches the attachment URL
- Structure is OpenAI-compatible

## Usage Example

### Before (Broken)

```javascript
// Frontend sends when user clicks "attach file"
{
    type: 'input_image',
    attachment_id: 123,
    url: 'https://example.com/image.jpg',
    file_name: 'image.jpg',
    mime_type: 'image/jpeg',
    bytes: 12345
}

// Backend processed (file_id path) - MISSING image_url
{
    type: 'input_image',
    file_id: 'file-abc123',
    url: 'https://example.com/image.jpg',
    attachment_id: 123,
    // ❌ No image_url - AI can't see the image!
}
```

### After (Fixed)

```javascript
// Frontend sends (same as before)
{
    type: 'input_image',
    attachment_id: 123,
    url: 'https://example.com/image.jpg',
    file_name: 'image.jpg',
    mime_type: 'image/jpeg',
    bytes: 12345
}

// Backend processed (file_id path) - NOW HAS image_url
{
    type: 'input_image',
    file_id: 'file-abc123',
    url: 'https://example.com/image.jpg',
    attachment_id: 123,
    image_url: {
        url: 'https://example.com/image.jpg'
    }
    // ✅ AI can now see the image!
}
```

## OpenAI Message Format

The processed segment is compatible with OpenAI's Chat Completions API:

```php
// User message with attached image
$message = array(
    'role' => 'user',
    'content' => array(
        array(
            'type' => 'text',
            'text' => 'What is in this image?',
        ),
        array(
            'type' => 'image_url',
            'image_url' => array(
                'url' => 'https://example.com/image.jpg',
            ),
        ),
    ),
);
```

## Files Modified

- `includes/class-wp-mcp-ai-message-attachments.php` (+3 lines)
- `tests/test-attachment-image-url-structure.php` (+246 lines, new file)

## Validation

- ✅ PHP syntax check passed
- ✅ Code review passed with no issues
- ✅ Security scan (CodeQL) passed
- ✅ Backward compatible
- ✅ Minimal surgical change

## Related Issues & PRs

- **This PR**: Issue #2108 - Add `image_url` structure to file_id path
- **Previous**: PR #2107 (Issue #2107) - Added `attachment_id` preservation
- **Previous**: PR #2106 (Issue #2106) - Added `url`, `file_name`, `mime_type`, `bytes` preservation
- **Pattern Source**: `WP_MCP_AI_Tool_Generate_OpenAI_Image::sanitize_for_llm()` (line 859-865)

## Migration

No migration needed - this is backward compatible:
- Existing segments without `image_url` in file_id path still work
- New segments automatically include `image_url`
- All three code paths now behave consistently

## Future Considerations

1. **Always include `image_url`** when modifying image segment processing
2. **Test all three code paths** (URL, file_id, attachment_id) when making changes
3. **Follow OpenAI patterns** for consistency across tools and segment processing
4. **Verify with vision models** (OpenAI GPT-4V, Gemini Vision) for compatibility

## Security Considerations

- URLs are sanitized with `esc_url_raw()`
- Only adds data structure, doesn't expose new endpoints
- Follows existing security patterns
- No SQL queries or file system access introduced

## Performance Impact

**Negligible** - only adds one array assignment per image segment:
- No database queries
- No file system operations
- No external API calls
- Memory: ~100 bytes per image segment (trivial)

## Summary

This fix completes the attachment metadata preservation work started in PRs #2106 and #2107. Users can now successfully use the "attach file" button in the shortcode chat client with OpenAI provider, and the AI will be able to "see" and analyze the attached images in agentic workflows.

The fix is minimal (3 lines), surgical, backward compatible, and follows the established pattern from the OpenAI image generation tool.
