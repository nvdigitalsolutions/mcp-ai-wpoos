# Chat-Client File Attachment Fix

## Issue Summary
Chat-client file attachments (images, PDFs, etc.) were appearing in the UI but not being passed to OpenAI. Users had to manually re-provide files, breaking the agentic workflow.

## Root Cause
The REST API validator (`includes/rest/class-wp-mcp-ai-rest-validator.php`) was missing `input_image` and `input_file` from its list of processable segment types.

### Flow Before Fix
1. User attaches image in chat-client
2. JavaScript sends message with `input_image` segment containing `attachment_id`
3. REST validator skips `input_image` segment (not in allowed types)
4. Message sent to OpenAI without file reference
5. OpenAI cannot see the attachment

### Flow After Fix
1. User attaches image in chat-client
2. JavaScript sends message with `input_image` segment containing `attachment_id`
3. REST validator processes `input_image` segment ✅
4. `attachment_id` resolved to OpenAI `file_id` via `prepare_input_attachment_segment()`
5. Message sent to OpenAI with file reference
6. OpenAI can analyze the attachment ✅

## Technical Details

### The Fix
**File:** `includes/rest/class-wp-mcp-ai-rest-validator.php`  
**Line:** 553  
**Change:**
```php
// Before
} elseif ( in_array( $segment_type, array( 'image_url', 'image_file', 'audio', 'file' ), true ) ) {

// After
} elseif ( in_array( $segment_type, array( 'image_url', 'image_file', 'input_image', 'audio', 'file', 'input_file' ), true ) ) {
```

### Segment Types
The codebase uses different segment type naming conventions:
- **Client-facing types:** `input_image`, `input_file` (sent from JavaScript)
- **API types:** `image_url`, `image_file`, `file`, `audio` (alternative formats)

Both sets are now properly handled.

### Attachment Resolution Process
1. **Client sends:** `{ type: 'input_image', attachment_id: 123 }`
2. **Validator calls:** `prepare_input_attachment_segment(segment)`
3. **Attachment helper:**
   - Validates user has access to attachment
   - Checks file exists and is valid MIME type
   - Uploads to OpenAI/Gemini if needed
   - Returns: `{ type: 'input_image', file_id: 'file-abc123' }`
4. **Provider receives:** OpenAI-compatible file reference

## Testing
Two new tests were added to verify the fix:
1. `test_sanitize_messages_processes_input_image_segments()` - Image attachments
2. `test_sanitize_messages_processes_input_file_segments()` - File attachments

Both tests verify:
- Segments are processed (not skipped)
- `attachment_id` is resolved to `file_id`
- Proper segment structure is maintained

## Files Changed
- `includes/rest/class-wp-mcp-ai-rest-validator.php` (1 line)
- `tests/test-rest-validator.php` (109 lines added)
- `tests/fixtures/test.pdf` (new file)

## Validation
✅ PHP syntax check passed  
✅ Code review - no issues found  
✅ CodeQL security scan - no vulnerabilities  
✅ Minimal change principle followed  

## Related Code
- **Attachment helper:** `includes/class-wp-mcp-ai-message-attachments.php`
- **JavaScript client:** `assets/js/chat.js` (lines 1100-1128 for segment stripping)
- **Existing tests:** `tests/test-rest-message-attachments.php`

## Impact
- ✅ Users can now attach files in chat-client and AI will see them
- ✅ Agentic workflow preserved - no manual re-uploading needed
- ✅ No breaking changes to existing functionality
- ✅ Backward compatible with all segment types
