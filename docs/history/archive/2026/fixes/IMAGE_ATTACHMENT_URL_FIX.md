# Image Attachment URL Fix

## Issue Summary
Image attachments sent from the chat client were not visible to AI providers (OpenAI, Gemini) because the actual image URL was not included in the API request. The system was only sending `type: "input_image"` with `attachment_id`, which the AI providers couldn't process.

## Problem Details

### Observed Behavior
When a user attached an image and sent a message like "can you tell me about this image?", the API received:
```json
{
  "role": "user",
  "content": [
    {
      "type": "text",
      "text": "can you tell me about this image?"
    },
    {
      "type": "input_image",
      "attachment_id": 1888
    }
  ]
}
```

The AI couldn't see the image and responded with "I don't see any image attached..."

Then the URL appeared as a separate message:
```json
{
  "role": "user",
  "content": "https://bots.nvdigital.solutions/wp-content/uploads/2025/11/07-2018ZEADESRAW13838_153c88b2-08f9-4ddb-8a39-2525155433be-1.webp"
}
```

### Root Cause
In `includes/class-wp-mcp-ai-message-attachments.php`, the `prepare_input_image_segment()` function:
1. Received an `input_image` segment with `attachment_id`
2. Registered the attachment and obtained a `file_id`
3. Returned a segment with `type: 'input_image'` and `file_id`
4. **BUT did NOT include the actual image URL that OpenAI/Gemini need**

### Why This Mattered
- **OpenAI Chat Completions API**: Requires images in `image_url` format with an actual URL
- **Gemini API**: Converts images to text descriptions that include the URL
- Without the URL, the AI providers couldn't access the image data

## Solution

### Changes Made
**File: `includes/class-wp-mcp-ai-message-attachments.php`**

Modified the `prepare_input_image_segment()` function (lines 432-454):
```php
$attachment_id       = absint( $segment['attachment_id'] );
$prepared_attachment = $this->register_attachment( $attachment_id, 'image' );

if ( is_wp_error( $prepared_attachment ) ) {
    return $prepared_attachment;
}

$prepared = array(
    'type'    => 'input_image',
    'file_id' => $prepared_attachment['file_id'],
);

// Get the image URL for providers that need it (OpenAI Chat Completions, Gemini).
// This is essential for vision models that require direct image URLs.
$image_url = wp_get_attachment_url( $attachment_id );
if ( ! empty( $image_url ) ) {
    $prepared['image_url'] = array( 'url' => esc_url_raw( $image_url ) );
} else {
    // Log warning if URL cannot be retrieved, but continue since we have file_id.
    WP_MCP_AI_Logger::log_error(
        'Could not retrieve URL for image attachment.',
        array(
            'attachment_id' => $attachment_id,
            'file_id'       => $prepared_attachment['file_id'],
        )
    );
}
```

**File: `tests/test-rest-message-attachments.php`**

Updated 3 existing tests to verify the `image_url` field is present:
1. `test_image_attachment_segment_is_prepared` (line 340)
2. `test_single_object_attachment_segment_is_normalised` (line 399)
3. `test_conversation_with_multiple_attachments_is_normalised` (line 576)

Each test now includes:
```php
// Verify that the image URL is included for OpenAI/Gemini compatibility.
$this->assertArrayHasKey( 'image_url', $image_segment );
$this->assertIsArray( $image_segment['image_url'] );
$this->assertArrayHasKey( 'url', $image_segment['image_url'] );
$this->assertNotEmpty( $image_segment['image_url']['url'] );
```

## How It Works Now

### Message Flow
1. **Client Side**: JavaScript sends `{ type: 'input_image', attachment_id: 1888 }`
   - Display URLs (blob:/data:) are stripped by `stripSegmentDisplayData()` function
   - Only the `attachment_id` is sent to the server

2. **Server Side - Message Processing**: 
   - `prepare_input_image_segment()` is called with the segment
   - Attachment is registered and `file_id` is obtained
   - **NEW**: WordPress attachment URL is retrieved via `wp_get_attachment_url()`
   - Segment now includes:
     ```php
     {
       'type': 'input_image',
       'file_id': 'file-abc123',
       'image_url': { 'url': 'https://example.com/wp-content/uploads/image.webp' }
     }
     ```

3. **Provider-Specific Conversion**:
   - **OpenAI**: `convert_image_files_to_image_url()` (line 3045-3070) extracts the URL
   - Creates proper `image_url` segment: `{ type: 'image_url', image_url: { url: '...' } }`
   - **Gemini**: `normalize_segments_to_text()` (line 1494-1495) includes URL in text
   - Creates text description: `[Image: https://example.com/...]`

4. **API Request**: Message sent to AI includes the actual image URL
5. **Result**: AI can now see and analyze the image ✅

## Technical Details

### Security Considerations
- URL is sanitized using `esc_url_raw()` before inclusion
- Only WordPress attachments with valid IDs can be processed
- Attachment permissions are checked during registration

### Error Handling
- If `wp_get_attachment_url()` returns false, a warning is logged
- Processing continues with just the `file_id` (graceful degradation)
- System doesn't fail completely if URL retrieval fails

### Compatibility
- **OpenAI**: Works with Chat Completions API (vision models)
- **Gemini**: Works with multimodal models
- **Backward Compatible**: Doesn't break existing functionality
- **File ID**: Still included for providers that support it

## Testing

### Manual Testing Steps
1. Create a chat assistant with a vision-capable model (e.g., GPT-4 Vision)
2. Open the chat client UI
3. Attach an image to a message
4. Send a message asking about the image
5. **Expected**: AI responds with analysis of the image
6. **Before Fix**: AI says it can't see the image

### Automated Tests
All 3 updated tests verify:
- Segment has `type: 'input_image'`
- Segment has `file_id` field
- Segment has `image_url` field
- `image_url` is an array with a `url` key
- URL is not empty

## Validation

✅ **PHP Syntax**: No syntax errors detected  
✅ **Code Review**: Passed with no issues  
✅ **Security Scan**: No vulnerabilities detected (CodeQL)  
✅ **Tests**: All existing tests updated and pass  
✅ **Minimal Changes**: Only 2 files modified with surgical changes  

## Files Changed
- `includes/class-wp-mcp-ai-message-attachments.php` (12 lines added)
- `tests/test-rest-message-attachments.php` (18 lines added)

## Related Code
- **OpenAI Conversion**: `includes/class-wp-mcp-ai-openai-client.php:3023-3150`
- **Gemini Text Rendering**: `includes/class-wp-mcp-ai-gemini-client.php:1490-1500`
- **JavaScript Stripping**: `assets/js/chat.js:1100-1128`

## Impact
✅ Users can now attach images in chat and AI will see them  
✅ Vision features work as expected  
✅ No breaking changes to existing functionality  
✅ Backward compatible with all segment types  
✅ Graceful degradation if URL cannot be retrieved  

## Future Considerations
- Consider caching attachment URLs for performance
- Monitor logs for URL retrieval failures
- May need similar fix for other attachment types in the future
