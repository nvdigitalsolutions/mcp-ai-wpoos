# Strip Attachment Data Verification

## Overview
This document explains the changes made to strip display-only data (blob: and data: URLs) from attachment segments before sending to the backend API.

## Changes Made

### 1. New Helper Functions in chat.js

#### `stripSegmentDisplayData(segment)`
- **Purpose**: Removes `url` and `name` fields from attachment segments
- **Preserves**: `type`, `attachment_id`, `display_name`, `caption`, `detail`
- **Strips**: `url`, `name` (display-only fields)

#### `stripContentDisplayData(content)`
- **Purpose**: Handles both string content and arrays of segments
- **Behavior**: 
  - String content passes through unchanged
  - Array content maps each segment through `stripSegmentDisplayData`
  - Text segments pass through unchanged

#### Enhanced `stripMessageDisplayMetadata(message)`
- **Purpose**: Prepares messages for API submission
- **New**: Now calls `stripContentDisplayData(message.content)` to clean attachment data
- **Applied**: 
  - In `sendChat()` at line 8122 (when sending messages to chat API)
  - In `saveConversationToCCT()` at line 1252 (when saving to WordPress)

## Verification

### JavaScript Tests
Created `tests/js/strip-attachment-display-data.test.js` with 13 tests:
- ✅ All tests passing
- Tests cover segment stripping, content stripping, and message metadata stripping
- Tests verify preservation of API-required fields

### Linting
- ✅ ESLint passes with no errors
- ✅ WordPress JavaScript coding standards maintained

### Backend Compatibility

#### Before (with url field):
```javascript
{
  type: 'input_image',
  attachment_id: 123,
  url: 'blob:http://localhost/abc-123',  // Large blob URL or data: URL
  name: 'photo.jpg'
}
```

#### After (url stripped):
```javascript
{
  type: 'input_image',
  attachment_id: 123
  // url and name stripped - only attachment_id remains
}
```

#### PHP Backend Handling
In `includes/class-wp-mcp-ai-message-attachments.php`:

**`prepare_input_image_segment()` flow:**
1. Lines 324-337: Checks for `url` field
   - **Before**: Would find blob:/data: URL and try to validate
   - **After**: No URL present, skips this section ✅
2. Lines 381-425: Checks for `file_id` field
   - Skipped if not present
3. Lines 428-461: Uses `attachment_id` field
   - **Activates**: Registers attachment, uploads to AI provider
   - **Result**: Gets proper file_id from WordPress attachment ✅

**Result**: The backend now correctly processes attachments via WordPress media library instead of trying to use client-side blob: URLs.

## Benefits

### 1. Reduced Payload Size
- Blob URLs can be very large (base64-encoded images)
- Stripping them reduces request size significantly
- Faster API requests, less bandwidth usage

### 2. Correct Backend Processing
- Backend uses `attachment_id` to fetch actual file from WordPress media library
- No more failed validation from blob:/data: URLs
- Proper file upload to AI providers (OpenAI, Gemini, etc.)

### 3. Preserved Display Functionality
- `url` and `name` still exist in local `state.conversation` for UI display
- Only stripped when sending to API
- User sees no difference in UI behavior

### 4. Maintained File Upload Flow
- File upload to WordPress media library unchanged
- Attachment creation and metadata storage unchanged
- Only the API transmission is optimized

## Example Flow

### User Action: Attach Image via Button
1. User clicks `.wp-mcp-ai-chat__attach` button
2. File picker opens, user selects `photo.jpg`
3. `handleFileSelection()` → `uploadAttachment()` called
4. File uploads to WordPress media library via REST API
5. Response includes `attachment_id: 123` and `source_url: "https://..."`
6. Attachment record created with:
   ```javascript
   {
     id: 123,
     fileId: 'wp-attachment-123',
     url: 'https://example.com/wp-content/uploads/photo.jpg',
     name: 'photo.jpg',
     mime: 'image/jpeg',
     isImage: true
   }
   ```

### Creating Message Segment
7. `createSegmentFromAttachment()` called
8. Creates segment:
   ```javascript
   {
     type: 'input_image',
     attachment_id: 123,
     url: 'https://example.com/wp-content/uploads/photo.jpg',  // Added by addAttachmentMetadataToSegment
     name: 'photo.jpg'  // Added by addAttachmentMetadataToSegment
   }
   ```
9. Segment added to message content array
10. Message added to `state.conversation` with display metadata

### Sending to API
11. User submits message
12. `sendChat()` called
13. **NEW**: `stripMessageDisplayMetadata()` processes each message
14. **NEW**: `stripContentDisplayData()` processes message content
15. **NEW**: `stripSegmentDisplayData()` removes url and name:
    ```javascript
    {
      type: 'input_image',
      attachment_id: 123
      // url and name stripped!
    }
    ```
16. Clean messages sent to API endpoint
17. Backend receives clean segment with only `attachment_id`
18. Backend uses `attachment_id` to fetch actual file from WordPress
19. Backend uploads file to AI provider
20. AI processes image correctly ✅

## Testing Checklist

- [x] Created comprehensive JavaScript tests
- [x] All JavaScript tests pass (13/13)
- [x] Linting passes with no errors
- [x] Verified backend compatibility with PHP code
- [x] Documented changes and flow
- [ ] Manual testing with actual file uploads (requires WordPress environment)
- [ ] Code review

## Manual Testing Steps (for QA)

1. **Setup**: WordPress with WP oOS plugin installed
2. **Create**: Assistant with file upload enabled
3. **Test Image Upload**:
   - Open chat widget
   - Click attachment button
   - Upload an image file
   - Send message with image
   - **Verify**: Image appears in chat
   - **Verify**: Image sent to AI successfully
   - **Check**: Network tab shows no large blob: URLs in request payload
4. **Test File Upload**:
   - Upload a PDF document
   - Send message with PDF
   - **Verify**: File appears in chat
   - **Verify**: File sent to AI successfully
5. **Test Mixed Content**:
   - Upload image and file together
   - Add text message
   - Send all together
   - **Verify**: All content sent correctly
6. **Test Conversation Save**:
   - Have conversation with attachments
   - Refresh page
   - **Verify**: Attachments restored from localStorage
   - **Verify**: Display shows images/files correctly

## Conclusion

The changes successfully strip display-only data (blob:/data: URLs) from attachment segments before API transmission while:
- ✅ Preserving file upload functionality
- ✅ Maintaining UI display capabilities
- ✅ Reducing payload size
- ✅ Ensuring correct backend processing
- ✅ Maintaining all existing tests
- ✅ Following WordPress coding standards
