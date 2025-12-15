# Attachment ID Not Displaying - Troubleshooting Guide

## Expected Behavior

When a file is uploaded via the attach button, the metadata should display:
```
219.3 KB • image/jpeg • ID: 2360
```

## Current Issue

The ID is missing from the metadata display:
```
219.3 KB • image/jpeg
```

## Code Verification

✅ **The code logic is correct** - Integration tests confirm that:
- `normaliseUploadResponse` extracts the ID from WordPress API
- `buildAttachmentMeta` includes the ID in the output
- Both pending attachments list and chat bubbles use the same logic
- The full flow from upload to display preserves the ID

## Possible Root Causes

### 1. Old JavaScript Files (Most Likely)

**Symptoms:**
- No console debug logs appear when attaching files
- Metadata missing ID despite code being correct

**Why this happens:**
- Browser cached old minified files
- WordPress cached old plugin files
- CDN hasn't updated files
- Build files weren't deployed to production

**Solutions:**
```bash
# On server: Rebuild JavaScript
cd /path/to/plugin
npm install
npm run build:js

# Clear WordPress cache
wp cache flush

# Clear object cache if using Redis/Memcached
wp cache flush

# Version bump to force cache busting
# Update version in plugin header and rebuild
```

**For users:**
```
1. Hard refresh browser: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
2. Clear browser cache completely
3. Open in incognito/private window to test
4. Check Network tab - verify chat-bundle.min.js is being loaded
5. Check file timestamp in Network tab - should be recent
```

### 2. WordPress API Not Returning ID

**Symptoms:**
- Console log shows: `from_data_id: undefined`
- Upload succeeds but no attachment appears in pending list

**Why this happens:**
- WordPress REST API permissions issue
- Custom upload endpoint configuration
- Plugin conflict interfering with /wp/v2/media

**Solutions:**
```bash
# Test WordPress media endpoint directly
curl -X POST https://your-site.com/wp-json/wp/v2/media \
  -H "Content-Type: image/jpeg" \
  -H "Content-Disposition: attachment; filename=test.jpg" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --data-binary @test.jpg

# Should return JSON with "id" field
# Example: {"id": 123, "source_url": "...", ...}
```

Check response structure:
- Is `id` a number in the root object?
- Or is it in `data.id`? (error response format)
- Is the response a 403/401 error?

**Fix:**
- Verify user has `upload_files` capability
- Check .htaccess isn't blocking REST API
- Disable conflicting plugins

### 3. Field Name Mismatch

**Symptoms:**
- Console shows record has data but `record_id: undefined`
- `record_keys` in debug log doesn't include 'id'

**Why this happens:**
- Custom WordPress setup returns ID in different field
- Modified REST API response structure

**Solutions:**

Update `normaliseUploadResponse` to check additional fields:

```javascript
let id = data.id || data.attachment_id || data.ID;
if (!id && data.data) {
    id = data.data.id || data.data.attachment_id || data.data.ID;
}
```

### 4. ID is Zero or Invalid

**Symptoms:**
- Console shows: `record_id: 0` or `record_id: ""` (empty string)

**Why this happens:**
- Database issue
- Upload failed but returned success
- Draft/temp attachment not finalized

**Solutions:**
- Check WordPress uploads table: `SELECT * FROM wp_posts WHERE post_type='attachment' ORDER BY ID DESC LIMIT 10;`
- Verify file actually exists in uploads directory
- Check for WordPress errors in debug.log

## Debug Process

### Step 1: Check Console Logs

After deploying this branch, attach a file and check console for these logs:

**A. Upload normalization:**
```javascript
[WP oOS] normaliseUploadResponse - created record: {
  id: 2360,  // ← Should be a number
  from_data_id: 2360,
  from_data_data_id: 'N/A'
}
```

**B. Pending attachments rendering:**
```javascript
[WP oOS] renderPendingAttachments - attachment data: {
  id: 2360,  // ← Should match upload
  metaText: '219.3 KB • image/jpeg • ID: 2360'  // ← Should include ID
}
```

**C. Display attachment creation:**
```javascript
[WP oOS] buildDisplayAttachment - creating display attachment: {
  record_id: 2360,  // ← Should be present
  found_in_library: true,  // ← Should be true
  meta: '219.3 KB • image/jpeg • ID: 2360'  // ← Should include ID
}
```

**D. ID resolution:**
```javascript
[WP oOS] buildAttachmentMeta - ID resolution: {
  record_id: 2360,
  record_id_type: 'number',
  resolved_attachmentId: 2360,
  will_include_id: true,  // ← Should be true
  record_keys: ['id', 'fileId', 'name', 'size', 'mime', ...]
}
```

### Step 2: Identify Where ID Is Lost

| Log Entry | ID Present? | Next Step |
|-----------|-------------|-----------|
| normaliseUploadResponse | ❌ No | Fix WordPress API response |
| normaliseUploadResponse | ✅ Yes | Check next log |
| renderPendingAttachments | ❌ No | Check state.pendingAttachments |
| renderPendingAttachments | ✅ Yes | Check UI rendering |
| buildDisplayAttachment | ❌ No | Check attachmentLibrary |
| buildDisplayAttachment | ✅ Yes | Check buildAttachmentMeta |
| buildAttachmentMeta | will_include_id: false | Check ID type/value |
| buildAttachmentMeta | will_include_id: true | Check UI display |

### Step 3: Verify UI Display

If logs show ID is included but UI doesn't show it:

1. **Inspect HTML element**
   ```html
   <div class="wp-mcp-ai-chat__attachments-meta">219.3 KB • image/jpeg • ID: 2360</div>
   ```
   - Is the ID in the HTML source?
   - Is it hidden by CSS?
   - Is JavaScript modifying it after render?

2. **Check for interference**
   - Other plugins modifying DOM
   - Custom CSS hiding content
   - Browser extensions affecting display

## Quick Fix Checklist

- [ ] Clear browser cache (Ctrl+Shift+R)
- [ ] Verify chat-bundle.min.js timestamp is recent
- [ ] Check console for debug logs
- [ ] Test in incognito mode
- [ ] Disable other plugins
- [ ] Test upload directly via WordPress Media Library
- [ ] Check Network tab for API response structure
- [ ] Verify file exists in uploads directory
- [ ] Check WordPress debug.log for errors

## Still Not Working?

If none of the above helps, gather this information:

1. **Browser console logs** - Copy all `[WP oOS]` messages
2. **Network tab** - Screenshot of /wp/v2/media response
3. **Inspect element** - HTML of the attachment metadata div
4. **WordPress info**:
   - WordPress version
   - PHP version
   - Active plugins
   - Theme name
5. **File info**:
   - File type/size being uploaded
   - Does it work for some files but not others?

## Expected Timeline

Once correct code is deployed with cache cleared:
- ✅ Should work immediately
- ✅ No database migrations needed
- ✅ No settings changes required
- ✅ Works for all new uploads
- ✅ Old messages without ID continue to work (graceful degradation)
