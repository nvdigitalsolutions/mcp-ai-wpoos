# Attachment ID Preservation for Agentic Workflows

## Issue #2107

**Problem**: The chat client was unable to see attached files when trying to use the `edit_gemini_image` tool in the shortcode/widget. When messages were saved to CCT (Custom Content Type) and later restored, attachment segments lost their `attachment_id`, preventing agentic workflows from accessing the original WordPress attachments.

## Root Cause

The backend `WP_MCP_AI_Message_Attachments` class was preserving file metadata (url, file_name, mime_type, bytes) but **NOT preserving `attachment_id`** in the prepared segments.

### Code Paths Affected

Both `prepare_input_image_segment()` and `prepare_input_file_segment()` have three code paths:

1. **URL path**: When segment has a direct URL
2. **file_id path**: When segment has an OpenAI/Gemini file_id
3. **attachment_id path**: When segment has a WordPress attachment_id

None of these paths were including `attachment_id` in the final prepared segment.

## Solution

Added `attachment_id` preservation to all six code paths (3 for images, 3 for files):

### For `prepare_input_image_segment()`

**URL Path** (lines 415-417):
```php
// Preserve attachment_id if present for agentic workflows
if ( ! empty( $segment['attachment_id'] ) ) {
    $prepared['attachment_id'] = absint( $segment['attachment_id'] );
}
```

**file_id Path** (lines 472-474):
```php
// Preserve attachment_id for agentic workflows
$prepared['attachment_id'] = $attachment_id;
```

**attachment_id Path** (line 508):
```php
$prepared = array(
    'type'          => 'input_image',
    'file_id'       => $prepared_attachment['file_id'],
    'attachment_id' => $attachment_id,
);
```

### For `prepare_input_file_segment()`

**URL Path** (lines 625-627):
```php
// Preserve attachment_id if present for agentic workflows
if ( ! empty( $segment['attachment_id'] ) ) {
    $prepared['attachment_id'] = absint( $segment['attachment_id'] );
}
```

**file_id Path** (lines 669-671):
```php
// Preserve attachment_id for agentic workflows
$segment_payload['attachment_id'] = $attachment_id;
```

**attachment_id Path** (line 707):
```php
$segment_payload = array(
    'type'          => 'input_file',
    'file_id'       => $prepared_attachment['file_id'],
    'attachment_id' => $attachment_id,
);
```

## Complete File Metadata

After this fix, all attachment segments include:

| Field | Type | Description | Added In |
|-------|------|-------------|----------|
| `attachment_id` | int | WordPress attachment ID | **This PR** |
| `url` | string | Direct HTTP/HTTPS URL | PR #2107 |
| `file_name` | string | Original filename | PR #2107 |
| `name` | string | Compatibility alias | PR #2107 |
| `mime_type` | string | MIME type | PR #2107 |
| `bytes` | int | File size | PR #2107 |

## Benefits

1. ✅ Agentic workflows can access attachments via multiple methods
2. ✅ Messages preserve full attachment context when saved to CCT
3. ✅ Tools like `edit_gemini_image` can reliably access files
4. ✅ Backward compatible (only adds fields, doesn't remove)
5. ✅ Follows OpenAI image tool metadata pattern
6. ✅ Works across chat client, shortcode, and widget contexts

## Files Modified

- `includes/class-wp-mcp-ai-message-attachments.php` (+23 lines, -9 lines)

## Testing

- ✅ PHP syntax validation passed
- ✅ Code review passed with no issues
- ✅ Backward compatible with existing code
- ✅ Follows existing patterns and conventions

## Related

- Previous Fix: PR #2107 (Issue #2106) - Added url, file_name, mime_type, bytes
- This Fix: Completes the metadata by adding `attachment_id`
- Pattern: OpenAI image tool metadata format

## Migration

No migration needed - this is backward compatible:
- Existing segments without `attachment_id` still work
- New segments automatically include `attachment_id`
- All code paths updated consistently

## Future Considerations

1. **Always preserve `attachment_id`** when modifying attachment handling
2. **Test all three code paths** (URL, file_id, attachment_id) when making changes
3. **Maintain complete metadata set** to support agentic workflows
4. **Follow OpenAI patterns** for consistency across AI providers
