# Chat Client Attachment Flow

This document explains how the attach file button works in the chat client and how attachments are sent as URLs to save space and server processing.

## Overview

The chat client's attach file button allows users to upload files and images that are sent to the AI assistant. The system is optimized to use URLs directly when possible, minimizing API calls and storage requirements.

## Flow Diagram

```
User Selects File
      ↓
Upload to WordPress Media Library (/wp-json/mcp-ai/v1/upload)
      ↓
Extract URL from Response (source_url or guid.rendered)
      ↓
Store Attachment Record {id, fileId, url, name, mime, size, isImage}
      ↓
Add to Pending Attachments
      ↓
User Clicks Send
      ↓
Create Segment from Attachment
      ↓
Add URL and Name Metadata to Segment
      ↓
Send to Backend (/wp-json/mcp-ai/v1/chat-client)
      ↓
Backend Processing (URL-based or Upload to AI Provider)
      ↓
AI Provider Processes Attachment
```

## Implementation Details

### 1. File Upload (chat.js)

**Function**: `uploadAttachment(state, file)`

When a user selects a file:

```javascript
// Upload file to WordPress
fetch(state.config.uploadEndpoint, {
    method: 'POST',
    headers: {
        'Content-Type': file.type,
        'Content-Disposition': 'attachment; filename="' + file.name + '"'
    },
    body: file
})
```

The endpoint returns a WordPress attachment object with:
- `id`: Attachment post ID
- `source_url`: Direct URL to the file
- `guid.rendered`: Fallback URL
- `title.rendered`: File title
- `mime_type`: MIME type
- `media_details.filesize`: File size in bytes

### 2. URL Extraction (chat.js)

**Function**: `normaliseUploadResponse(data, file)`

Extracts and stores the URL:

```javascript
const url = data.source_url || (data.guid && data.guid.rendered) || '';

return {
    id: data.id,
    fileId: 'wp-attachment-' + data.id,
    url: url,  // ← URL stored here
    name: title || file.name,
    originalName: file.name,
    mime: data.mime_type || file.type,
    size: filesize,
    isImage: mime.indexOf('image/') === 0
};
```

### 3. Segment Creation (chat.js)

**Function**: `createSegmentFromAttachment(attachment)`

Creates a segment with the attachment ID:

```javascript
if (isImage) {
    const segment = {
        type: 'input_image',
        attachment_id: id
    };
    addAttachmentMetadataToSegment(segment, attachment);
    return segment;
}

const segment = {
    type: 'input_file',
    attachment_id: id
};
if (displayName) {
    segment.display_name = displayName;
}
addAttachmentMetadataToSegment(segment, attachment);
return segment;
```

### 4. Metadata Addition (chat.js)

**Function**: `addAttachmentMetadataToSegment(segment, attachment)`

Adds URL and name to the segment:

```javascript
// Include URL and name for server processing and localStorage restoration
// URL allows server to skip database lookups and directly use the attachment URL
if (attachment.url) {
    segment.url = attachment.url;  // ← URL added to segment
}
if (attachment.name) {
    segment.name = attachment.name;
}
```

**Result**: Segment sent to backend includes:
```javascript
{
    type: 'input_image',
    attachment_id: 123,
    url: 'https://example.com/wp-content/uploads/2024/01/image.jpg',  // ← URL included
    name: 'image.jpg'
}
```

### 5. Backend Processing (PHP)

**File**: `includes/class-wp-mcp-ai-message-attachments.php`

#### For Images (`prepare_input_image_segment`)

When a URL is present, the backend uses it directly:

```php
if ( ! empty( $segment['url'] ) ) {
    $url = esc_url_raw( $segment['url'] );
    
    // Validate URL scheme (http/https)
    // ...
    
    $prepared = array(
        'type'      => 'input_image',
        'image_url' => array( 'url' => $url ),  // ← Direct URL usage
    );
    
    return $prepared;
}
```

**Benefits**:
- ✅ No database lookup needed
- ✅ No file upload to OpenAI API needed
- ✅ OpenAI's vision API accepts `image_url` format directly
- ✅ Saves API calls and storage

#### For Files (`prepare_input_file_segment`)

For non-image files, the attachment still needs to be uploaded to the AI provider's File API:

```php
// URL is preserved in segment metadata but file must be uploaded
$prepared_attachment = $this->register_attachment( absint( $segment['attachment_id'] ), 'file' );

return array(
    'type'    => 'input_file',
    'file_id' => $prepared_attachment['file_id'],  // OpenAI file ID from upload
);
```

**Note**: The URL is included in the segment metadata for:
- Display purposes
- Logging
- Alternative AI providers that might support direct URLs
- Future optimization opportunities

## URL Usage by Attachment Type

| Type | URL Included? | URL Used by Backend? | Requires Upload to AI Provider? |
|------|---------------|----------------------|--------------------------------|
| **Images** (`input_image`) | ✅ Yes | ✅ Yes (via `image_url`) | ❌ No |
| **Files** (`input_file`) | ✅ Yes | ⚠️ Metadata only | ✅ Yes (to OpenAI File API) |

## Agentic Workflow Integration

### Image Attachments

When an image is attached, the agentic workflow receives:

```javascript
{
    role: 'user',
    content: [
        {
            type: 'text',
            text: 'Analyze this image'
        },
        {
            type: 'input_image',
            image_url: {
                url: 'https://example.com/wp-content/uploads/2024/01/chart.jpg'
            }
        }
    ]
}
```

The AI provider (OpenAI) fetches the image directly from the URL. No file upload needed.

### File Attachments

When a file is attached, the agentic workflow receives:

```javascript
{
    role: 'user',
    content: [
        {
            type: 'text',
            text: 'Analyze this document'
        },
        {
            type: 'input_file',
            file_id: 'file-abc123xyz',  // OpenAI file ID from upload
            display_name: 'report.pdf'
        }
    ]
}
```

The file was uploaded to OpenAI's File API and the returned `file_id` is used in the request.

## Security Considerations

### URL Validation

All URLs are validated before use:

```php
// Check URL scheme
$allowed_schemes = array( 'http', 'https' );
$parsed_url = wp_parse_url( $url );
$scheme = strtolower( $parsed_url['scheme'] );

if ( ! in_array( $scheme, $allowed_schemes, true ) ) {
    return new WP_Error( 'wp_mcp_ai_unsupported_url_scheme', ... );
}
```

### Access Control

- ✅ Files uploaded through WordPress's media upload system
- ✅ User capabilities checked before upload
- ✅ MIME type validation applied
- ✅ File size limits enforced
- ✅ WordPress nonce verification required

### URL Sanitization

```php
$url = esc_url_raw( $segment['url'] );
```

## Benefits of URL-Based Attachments

### 1. **Performance**
- No database queries to retrieve attachment metadata
- Faster segment processing on the backend
- Reduced server load

### 2. **Storage**
- Images don't need to be uploaded to AI provider
- Saves API storage quota
- Reduces file management overhead

### 3. **Simplicity**
- Direct access to files without additional lookups
- Cleaner code flow
- Easier debugging (URL visible in requests)

### 4. **Flexibility**
- URLs can be logged for audit trails
- Alternative AI providers can use URLs directly
- External URLs can be supported in the future

## Testing

Tests are located in `tests/js/attachment-url-segment.test.js`:

```bash
npm test -- tests/js/attachment-url-segment.test.js
```

Tests cover:
- ✅ URL extraction from upload response
- ✅ URL inclusion in image segments
- ✅ URL inclusion in file segments
- ✅ Graceful handling of missing URLs
- ✅ Backend URL processing

## Future Enhancements

### Potential Optimizations

1. **External URLs**: Allow users to paste image URLs directly (no upload needed)
2. **CDN Support**: Use CDN URLs for better performance
3. **Lazy Upload**: Only upload files when AI provider requires it
4. **Alternative Providers**: Support providers that accept file URLs directly (like Anthropic's vision API)
5. **Caching**: Cache frequently used attachments to avoid repeated fetches

### Backward Compatibility

The current implementation maintains backward compatibility:
- If URL is not in segment, backend falls back to `attachment_id` lookup
- Existing code that doesn't include URLs continues to work
- No breaking changes to public APIs

## Troubleshooting

### URL Not Included in Segment

**Issue**: Segment sent to backend doesn't have `url` field

**Cause**: Upload response didn't include `source_url` or `guid.rendered`

**Solution**: Check upload endpoint response format. Ensure it returns standard WordPress attachment object.

### Image Not Displaying in Chat

**Issue**: Image attachment shows broken image icon

**Cause**: URL is not publicly accessible or has expired

**Solution**: 
1. Verify URL is publicly accessible
2. Check WordPress media library settings
3. Ensure permalinks are configured correctly

### File Upload Fails for Large Files

**Issue**: File upload times out or fails

**Cause**: Server upload limits exceeded

**Solution**:
1. Increase `upload_max_filesize` in php.ini
2. Increase `post_max_size` in php.ini
3. Adjust `max_execution_time` if needed

## Related Files

### JavaScript
- `assets/js/chat.js` - Main chat client implementation
  - `uploadAttachment()` - Handles file uploads
  - `normaliseUploadResponse()` - Extracts URL from response
  - `createSegmentFromAttachment()` - Creates segment with attachment_id
  - `addAttachmentMetadataToSegment()` - Adds URL to segment

### PHP
- `includes/class-wp-mcp-ai-message-attachments.php` - Attachment segment processing
  - `prepare_input_image_segment()` - Processes image segments (uses URL)
  - `prepare_input_file_segment()` - Processes file segments (uploads to AI provider)

### Tests
- `tests/js/attachment-url-segment.test.js` - URL inclusion tests

## Conclusion

The chat client's attach file button is fully functional and optimized to send attachments as URLs. This design:

- ✅ Saves server processing by avoiding database lookups
- ✅ Saves API quota by using URLs directly for images
- ✅ Maintains clean separation between frontend and backend
- ✅ Provides flexibility for future enhancements
- ✅ Works correctly with the agentic workflow

No changes were needed - the system was already working correctly!
