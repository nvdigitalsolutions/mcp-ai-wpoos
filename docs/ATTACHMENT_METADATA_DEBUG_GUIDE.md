# Attachment Metadata Display - Debug Guide

## Problem Statement

Attachment ID is not being displayed in the chat UI when files are attached using the attach file button. The metadata shows:
```
219.3 KB • image/jpeg
```

But should show:
```
219.3 KB • image/jpeg • ID: 2360
```

## Debug Logging Added

We've added comprehensive debug logging to trace the attachment metadata flow. When you attach a file and send a message, you should see console logs like:

### 1. Upload Response Normalization
```javascript
[WP oOS] normaliseUploadResponse - created record: {
  id: 2360,
  fileId: 'wp-attachment-2360',
  name: '81R4xIDAR1L._SL1500_',
  size: 224563,
  mime: 'image/jpeg',
  from_data_id: 2360,
  from_data_data_id: 'N/A'
}
```

**What to check:**
- Is `id` a number (not undefined, null, or string)?
- Is `size` present and numeric?
- Is `mime` the correct MIME type?

### 2. Pending Attachments Rendering
```javascript
[WP oOS] renderPendingAttachments - attachment data: {
  id: 2360,
  fileId: 'wp-attachment-2360',
  name: '81R4xIDAR1L._SL1500_',
  size: 224563,
  mime: 'image/jpeg',
  metaText: '219.3 KB • image/jpeg • ID: 2360'
}
```

**What to check:**
- Is `metaText` including the ID?
- If not, check if `id` is present in the attachment object

### 3. Display Attachment Creation (on send)
```javascript
[WP oOS] buildDisplayAttachment - creating display attachment: {
  attachment_id: 2360,
  attachment_fileId: 'wp-attachment-2360',
  record_id: 2360,
  record_fileId: 'wp-attachment-2360',
  found_in_library: true,
  meta: '219.3 KB • image/jpeg • ID: 2360'
}
```

**What to check:**
- Is `record_id` present?
- Is `found_in_library` true?
- Is `meta` including the ID?

## Common Issues and Solutions

### Issue 1: ID is undefined in normaliseUploadResponse

**Symptoms:**
```javascript
from_data_id: undefined
```

**Possible causes:**
- WordPress REST API error
- Incorrect response structure
- Permission issues

**Solution:**
1. Check the raw WordPress API response in Network tab
2. Look for `data.id` or `data.data.id` in the response
3. Verify upload permissions

### Issue 2: ID is present but metaText doesn't include it

**Symptoms:**
```javascript
id: 2360,
metaText: '219.3 KB • image/jpeg'  // Missing ID
```

**Possible causes:**
- `buildAttachmentMeta` not finding the ID field
- ID is in wrong format (string vs number)

**Solution:**
1. Check if ID is stored as `id` or `attachment_id`
2. Verify the `buildAttachmentMeta` function logic
3. Ensure ID is number or non-empty string

### Issue 3: ID lost between upload and display

**Symptoms:**
```javascript
// Upload: id: 2360 ✓
// Pending: id: undefined ✗
```

**Possible causes:**
- Attachment object not properly stored in pendingAttachments
- attachmentLibrary not updated correctly

**Solution:**
1. Check if `state.pendingAttachments.push(record)` succeeded
2. Verify `state.attachmentLibrary[record.fileId] = record` succeeded
3. Look for any code that might modify or replace the attachment object

### Issue 4: ID present in library but not found during display

**Symptoms:**
```javascript
found_in_library: false,
record_id: undefined
```

**Possible causes:**
- fileId mismatch
- State object not passed correctly

**Solution:**
1. Verify `attachment.fileId` matches the library key
2. Check if `state.attachmentLibrary` is accessible
3. Ensure fileId format is `'wp-attachment-' + id`

## Testing Steps

1. **Clear browser cache** and reload the page
2. **Open browser console** (F12)
3. **Filter console** for "[WP oOS]" messages
4. **Attach a file** using the attach button
5. **Check the normaliseUploadResponse log** - verify ID is present
6. **Check the renderPendingAttachments log** - verify metaText includes ID
7. **Look at the pending attachments UI** - verify ID is visible
8. **Send the message**
9. **Check the buildDisplayAttachment log** - verify ID is present
10. **Look at the message bubble** - verify ID is visible

## WordPress REST API Response Format

The expected WordPress media upload response format is:

```json
{
  "id": 2360,
  "date": "2025-12-15T04:00:00",
  "slug": "81r4xidarlsl1500",
  "type": "attachment",
  "link": "https://...",
  "title": {
    "rendered": "81R4xIDAR1L._SL1500_"
  },
  "author": 1,
  "mime_type": "image/jpeg",
  "media_details": {
    "width": 1500,
    "height": 1500,
    "filesize": 224563
  },
  "source_url": "https://bots.nvdigital.solutions/wp-content/uploads/2025/12/81R4xIDAR1L._SL1500_.jpg",
  "guid": {
    "rendered": "https://..."
  }
}
```

The key fields we extract:
- `id` - The attachment post ID (required)
- `source_url` - The file URL
- `mime_type` - The MIME type
- `media_details.filesize` - The file size in bytes
- `title.rendered` or `slug` - The file name

## Next Steps

After reviewing the console logs:

1. **If ID is missing from upload response:**
   - Check WordPress configuration
   - Verify REST API is working correctly
   - Check for plugin conflicts

2. **If ID is present but not displayed:**
   - Issue is in `buildAttachmentMeta` logic
   - Check field name mapping

3. **If ID is lost between steps:**
   - Issue is in state management
   - Check attachment object copying/transformation

4. **If everything looks correct in logs but UI doesn't show ID:**
   - Issue might be with minified file not being loaded
   - Clear WordPress cache
   - Hard refresh browser (Ctrl+Shift+R)
