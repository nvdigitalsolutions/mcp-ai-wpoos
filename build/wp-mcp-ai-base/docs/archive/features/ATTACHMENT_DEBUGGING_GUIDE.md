# Chat Attachment Visibility Debugging Guide

## Issue
Attachments appear in the chat UI bubble but the AI may not be able to see or reference them.

## Debugging Steps

### 1. Check Browser Console
Open browser console and look for log messages when sending a message with an attachment:

```
[WP oOS] User clicked send: {message_length, has_attachments, attachment_count, ...}
[WP oOS] Created segment from attachment: {attachment_id, segment_type, segment_attachment_id, ...}
[WP oOS] Created payloadContent: {is_array, segment_count, segments}
[WP oOS] Sending messages to API: [...full JSON payload...]
```

### 2. Verify Segment Creation
Look for:
- `has_attachments: true`
- `attachment_count: 1` (or more)
- `Created segment from attachment` log showing valid `attachment_id`

If you see warnings instead:
- `Failed to create segment from attachment` - the attachment record is missing an ID
- `No valid ID found in attachment` - the upload response didn't include an attachment ID

### 3. Verify API Payload
Check the "Sending messages to API" log to see the final payload:

```json
[
  {
    "role": "user",
    "content": [
      {
        "type": "text",
        "text": "can you tell me a little about this picture?"
      },
      {
        "type": "input_image",
        "attachment_id": 123
      }
    ]
  }
]
```

The attachment segment should:
- Have `type: "input_image"` or `type: "input_file"`
- Have `attachment_id` with a numeric value
- NOT have `url` or `name` fields (intentionally stripped)

### 4. Check Network Tab
In browser DevTools Network tab:
- Find the POST request to `/wp-json/mcp-ai/v1/chat` or `/wp-json/mcp-ai/v1/chat-client`
- Look at the Request Payload
- Verify the message includes the attachment segment with valid attachment_id

### 5. Check Server Response
In the Network tab, check the response:
- If status is 400/403, check the error message
  - "You do not have permission to use the requested attachment" = permission issue
  - "Image segments must include an attachment ID or URL" = attachment_id missing
  - "The attachment file could not be located" = file doesn't exist
- If status is 200, the request was successful

### 6. Check WordPress Debug Log
If WP_DEBUG is enabled, check wp-content/debug.log for errors related to:
- File uploads
- Attachment access
- OpenAI/Gemini file uploads

## Common Issues and Fixes

### Issue 1: Segment Not Created
**Symptom**: Console shows "Failed to create segment from attachment"
**Cause**: attachment.id is missing or invalid
**Fix**: Check normaliseUploadResponse() to ensure it's extracting the ID correctly from the upload response

### Issue 2: Permission Denied
**Symptom**: Server returns 403 error "You do not have permission to use the requested attachment"
**Cause**: User doesn't have read/edit permission on the attachment
**Fix**: 
- Check if attachment post_status is "inherit" (should be for most uploads)
- Check if user is the post_author
- Check if attachment is publicly accessible

### Issue 3: File Upload to AI Provider Fails
**Symptom**: Server returns 500 error or takes very long to respond
**Cause**: OpenAI/Gemini file upload failed
**Fix**:
- Check API keys are valid
- Check file size limits
- Check MIME type is supported
- Check network connectivity to AI provider

### Issue 4: Attachment ID Invalid
**Symptom**: Console shows valid segment with attachment_id, but server says "The attachment file could not be located"
**Cause**: The attachment post doesn't exist or was deleted
**Fix**: Verify the attachment exists in WordPress media library

## Code Flow Reference

### Upload Flow
1. User selects file → `handleFileSelection()`
2. File uploaded to WordPress → `uploadAttachment()`
3. Response normalized → `normaliseUploadResponse()`
4. Attachment record created with `id` and `fileId`
5. Record added to `state.pendingAttachments`

### Submit Flow
1. User clicks send → `handleSubmit()`
2. For each pending attachment → `createSegmentFromAttachment()`
3. Segment created with `type` and `attachment_id`
4. Segments combined into `payloadContent`
5. Message created with content + display metadata
6. Message added to conversation and saved to localStorage

### Send Flow
1. `sendChat()` called
2. Messages cleaned → `stripMessageDisplayMetadata()`
3. Attachment segments have `url`/`name` removed (intentional)
4. Payload sent to `/wp-json/mcp-ai/v1/chat-client`
5. Server validates and processes attachment segments

### Backend Flow
1. REST validator receives request
2. `sanitize_messages()` processes each message
3. For attachment segments → `prepare_input_attachment_segment()`
4. `register_attachment()` called with attachment_id
5. Permission check → `current_user_can_access_attachment()`
6. File upload to AI provider → File Service Factory
7. Returns segment with `file_id` instead of `attachment_id`

## Testing Script

You can use this JavaScript to test segment creation in the browser console:

```javascript
// Simulate a valid attachment record
const attachment = {
    id: 123,
    fileId: 'wp-attachment-123',
    name: 'test-image.jpg',
    url: 'http://example.com/wp-content/uploads/test-image.jpg',
    mime: 'image/jpeg',
    isImage: true
};

// Should return {type: 'input_image', attachment_id: 123, url: '...', name: '...'}
console.log(createSegmentFromAttachment(attachment));
```

## Further Debugging

If the issue persists after following these steps:
1. Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php
2. Check server error logs
3. Test with a simple assistant that only echoes back messages
4. Try uploading different file types/sizes
5. Test with different user roles (admin vs editor vs guest)
