# Attachment Metadata Preservation for Agentic Workflows

## Issue #2106

**Problem**: File attachments in the chat client were not working properly for agentic workflows. When users attached files via the attach file button, the AI models couldn't "see" or access the files. Re-pasting the URL would work, but initial submission and mid-conversation attachments failed.

## Root Cause

1. **Frontend (JavaScript)**: The chat client correctly added file metadata (url, file_name, mime_type, bytes) to attachment segments via `addAttachmentMetadataToSegment()`.

2. **Backend (PHP)**: The `WP_MCP_AI_Message_Attachments` class was stripping this metadata during segment processing:
   - `prepare_input_image_segment()` only preserved minimal fields (type, file_id, caption)
   - `prepare_input_file_segment()` only preserved minimal fields (type, file_id, display_name)
   - The complete file context was lost before reaching AI models

3. **Impact**: Without metadata, AI models couldn't:
   - Access the actual file content
   - Understand file type/format
   - Know file size for processing decisions
   - Generate appropriate responses about the file

## Solution

Enhanced the backend to preserve complete file metadata across all code paths in `includes/class-wp-mcp-ai-message-attachments.php`:

### 1. Updated `prepare_input_image_segment()`

Added metadata preservation for all three code paths:

**A. URL-based path** (lines 379-397):
```php
// Preserve file metadata for agentic workflow
$prepared['file_name'] = $segment['file_name'] ?? $segment['name'];
$prepared['mime_type'] = $segment['mime_type'];
$prepared['bytes'] = $segment['bytes'];
$prepared['url'] = $url; // Direct URL access
```

**B. file_id resolution path** (lines 448-471):
```php
// Get URL from attachment_id
if (isset($resolved['attachment_id'])) {
    $image_url = wp_get_attachment_url($attachment_id);
    $prepared['url'] = $image_url;
}
// Extract from metadata
$prepared['file_name'] = $metadata['filename'];
$prepared['mime_type'] = $metadata['mime_type'];
$prepared['bytes'] = $metadata['bytes'];
```

**C. attachment_id registration path** (lines 528-536):
```php
$prepared['url'] = wp_get_attachment_url($attachment_id);
$prepared['file_name'] = $prepared_attachment['filename'];
$prepared['mime_type'] = $prepared_attachment['mime_type'];
$prepared['bytes'] = $prepared_attachment['bytes'];
```

### 2. Updated `prepare_input_file_segment()`

Added URL-based path for consistency and metadata preservation:

**A. NEW: URL-based path** (lines 548-606):
```php
if (!empty($segment['url'])) {
    $prepared = [
        'type' => 'input_file',
        'url' => $url,
        'file_name' => $segment['file_name'] ?? $segment['name'],
        'name' => $segment['file_name'] ?? $segment['name'], // Compatibility
        'mime_type' => $segment['mime_type'],
        'bytes' => $segment['bytes'],
    ];
}
```

**B. file_id resolution path** (lines 619-663):
```php
$segment_payload['url'] = wp_get_attachment_url($attachment_id);
$segment_payload['file_name'] = $metadata['filename'];
$segment_payload['name'] = $metadata['filename']; // Compatibility
$segment_payload['mime_type'] = $metadata['mime_type'];
$segment_payload['bytes'] = $metadata['bytes'];
```

**C. attachment_id registration path** (lines 686-709):
```php
$segment_payload['url'] = wp_get_attachment_url($attachment_id);
$segment_payload['file_name'] = $prepared_attachment['filename'];
$segment_payload['name'] = $prepared_attachment['filename']; // Compatibility
$segment_payload['mime_type'] = $prepared_attachment['mime_type'];
$segment_payload['bytes'] = $prepared_attachment['bytes'];
```

### 3. Updated `register_attachment()`

Enhanced return value to include complete metadata (lines 717-727, 901-911):
```php
return [
    'file_id' => $file_id,
    'title' => $title,
    'caption' => $caption,
    'filename' => $filename,
    'mime_type' => $mime_type,
    'bytes' => $bytes,
    'metadata' => $metadata, // Full metadata array
];
```

## Metadata Fields Added

For both `input_image` and `input_file` segments:

| Field | Type | Description | Purpose |
|-------|------|-------------|---------|
| `url` | string | Direct HTTP(S) URL to file | Allows AI to download/access file |
| `file_name` | string | Original filename | Identifies file in prompts/responses |
| `name` | string | Alias for file_name | Compatibility with different providers |
| `mime_type` | string | MIME type (e.g., "image/jpeg") | Tells AI what type of file it is |
| `bytes` | int | File size in bytes | Helps AI make processing decisions |

## Provider Compatibility

### OpenAI
- Uses `image_url` field for vision models (Chat Completions API)
- Uses `file_id` for Assistants API
- Now receives complete metadata for better context

### Gemini
- Uses `url` and `name` fields (already supported)
- Now receives `mime_type` and `bytes` for enhanced processing

### Ollama
- Processes segments with enhanced metadata
- Compatible with all added fields

## Testing

Created comprehensive test suite in `tests/test-attachment-metadata-preservation.php`:

1. **test_image_segment_includes_complete_metadata**: Verifies image attachments have all metadata
2. **test_file_segment_includes_complete_metadata**: Verifies file attachments have all metadata
3. **test_image_segment_with_url_preserves_metadata**: Tests URL-based image path
4. **test_file_segment_with_url_preserves_metadata**: Tests URL-based file path

## Migration Path

No migration needed - this is backward compatible:
- Existing segments with minimal metadata still work
- New segments automatically include enhanced metadata
- Frontend already sends metadata (was just being stripped)

## Future Considerations

1. **Always preserve these fields** when modifying attachment handling
2. **Follow OpenAI image tool pattern** for consistency
3. **Test all three code paths** (URL, file_id, attachment_id) when making changes
4. **Verify with all providers** (OpenAI, Gemini, Ollama) for compatibility

## Benefits

1. ✅ AI models can access and understand attached files
2. ✅ Works on initial submission and throughout conversation
3. ✅ Compatible with all providers
4. ✅ Follows industry best practices (OpenAI pattern)
5. ✅ No breaking changes to existing functionality
6. ✅ Comprehensive test coverage

## Files Modified

- `includes/class-wp-mcp-ai-message-attachments.php` (+170 lines)
- `tests/test-attachment-metadata-preservation.php` (+206 lines, new file)

## References

- Issue: #2106
- PR: copilot/add-file-metadata-attachment-segments
- Pattern: OpenAI image tool metadata format
- Frontend: `assets/js/chat.js` (addAttachmentMetadataToSegment function)
