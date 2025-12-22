# Deployment Checklist: Attachment ID Display Fix

## Pre-Deployment

- [x] Code reviewed and verified with integration tests
- [x] Debug logging added to trace attachment flow
- [x] JavaScript files rebuilt (`npm run build:js`)
- [x] Debug strings verified in minified files
- [x] Documentation created (3 guides)
- [x] Git committed and pushed to branch

## Deployment Steps

### 1. Deploy Branch

```bash
# On server
cd /path/to/wp-content/plugins/mcp-ai-wpoos

# Backup current version (optional but recommended)
cp -r . ../mcp-ai-wpoos-backup-$(date +%Y%m%d)

# Pull latest from branch
git fetch origin
git checkout copilot/display-attachment-metadata-chat
git pull origin copilot/display-attachment-metadata-chat

# Verify files are updated
ls -lh assets/js/chat.min.js
# Should show recent timestamp
```

### 2. Clear Server Caches

```bash
# WordPress cache
wp cache flush

# If using Redis
redis-cli FLUSHALL

# If using Memcached
echo 'flush_all' | nc localhost 11211

# If using WP Super Cache
wp super-cache flush

# If using W3 Total Cache
wp w3-total-cache flush

# Clear OPcache (if enabled)
# Add this to a PHP file and run it:
<?php opcache_reset(); ?>
```

### 3. Verify File Permissions

```bash
# Ensure files are readable
chmod 644 assets/js/chat.min.js
chmod 644 assets/js/chat-bundle.min.js
chmod 644 assets/js/*.map
```

### 4. Browser Cache Clearing

Instruct users to:
- **Hard refresh**: Ctrl+Shift+R (Windows/Linux) or Cmd+Shift+R (Mac)
- **Or** Clear browser cache completely
- **Or** Test in incognito/private browsing mode

## Testing Procedure

### Test 1: Verify Files Loaded

1. Open chat page
2. Open browser DevTools (F12)
3. Go to **Network** tab
4. Reload page (Ctrl+R)
5. Find `chat-bundle.min.js` in network requests
6. Verify:
   - ✅ Status: 200 OK
   - ✅ Size: ~232 KB
   - ✅ Time: Recent (not cached from weeks ago)

### Test 2: Check Console Logs

1. Stay in DevTools
2. Go to **Console** tab
3. Filter for: `[WP oOS]`
4. Click attach file button
5. Upload a test image (JPEG, PNG, etc.)
6. **Expected logs** (4 entries):

```javascript
[WP oOS] normaliseUploadResponse - created record: {...}
[WP oOS] renderPendingAttachments - attachment data: {...}
```

7. Send the message with attachment
8. **Expected logs** (2 more entries):

```javascript
[WP oOS] buildDisplayAttachment - creating display attachment: {...}
[WP oOS] buildAttachmentMeta - ID resolution: {...}
```

### Test 3: Verify UI Display

1. **Pending Attachments** (before sending):
   - Look at attachment in pending list
   - Should show: `219.3 KB • image/jpeg • ID: 2360`
   - ✅ Verify "ID: XXX" is present

2. **Chat Bubble** (after sending):
   - Look at message in chat
   - Should show: `219.3 KB • image/jpeg • ID: 2360`
   - ✅ Verify "ID: XXX" is present

3. **Inspect Element**:
   ```html
   <div class="wp-mcp-ai-chat__attachments-meta">219.3 KB • image/jpeg • ID: 2360</div>
   ```

### Test 4: Tool Functionality

1. Send message: "edit this image to remove background"
2. Wait for AI response
3. Verify `edit_gemini_image` tool:
   - ✅ Executes successfully (no parameter errors)
   - ✅ Returns edited image
   - ✅ No "You must provide attachment_id, file_id, url..." error

## Log Analysis

### ✅ SUCCESS - If logs show:

```javascript
{
  id: 2360,  // ← Number present
  from_data_id: 2360,  // ← Matches
  record_id: 2360,  // ← Preserved
  will_include_id: true,  // ← Should include
  metaText: '219.3 KB • image/jpeg • ID: 2360'  // ← ID included
}
```

**Action:** Issue is resolved! Proceed to cleanup (remove debug logging).

### ❌ FAILURE - If logs show:

```javascript
{
  id: undefined,  // ← Problem here
  from_data_id: undefined,  // ← API not returning ID
  ...
}
```

**Action:** WordPress API issue. Check:
- Upload permissions
- REST API enabled
- Test `/wp/v2/media` endpoint directly

### ❌ NO LOGS APPEAR

**Action:** JavaScript not loading. Check:
- Browser console for errors
- Network tab shows 200 OK for chat-bundle.min.js
- File timestamp is recent
- Clear cache again (hard refresh)

## Troubleshooting

If issues persist, consult:
1. `docs/ATTACHMENT_METADATA_DEBUG_GUIDE.md`
2. `docs/ATTACHMENT_ID_TROUBLESHOOTING.md`
3. `docs/ATTACHMENT_ID_FIX_SUMMARY.md`

### Common Issues

| Symptom | Cause | Solution |
|---------|-------|----------|
| No console logs | Old JS cached | Hard refresh, clear cache |
| `from_data_id: undefined` | API not returning ID | Check WordPress upload endpoint |
| `will_include_id: false` | ID wrong type | Check log details, may need code fix |
| UI doesn't show ID | CSS hiding it | Inspect element, check styles |

## Post-Deployment Cleanup

### Once Issue is Resolved

1. **Remove debug logging** from code:
   - Remove all `console.log` blocks added
   - In: `normaliseUploadResponse`, `renderPendingAttachments`, `buildDisplayAttachment`, `buildAttachmentMeta`

2. **Rebuild without debug**:
   ```bash
   npm run build:js
   ```

3. **Test without logs**:
   - Verify functionality still works
   - Verify no console spam
   - Verify metadata still shows ID

4. **Commit clean version**:
   ```bash
   git add assets/js/
   git commit -m "Remove debug logging after fixing attachment ID display"
   git push
   ```

5. **Update documentation**:
   - Mark issue as resolved in docs
   - Update RECENT_CHANGES_DEC_2025.md
   - Archive debug guides (move to docs/archive/)

## Rollback Plan

If issues occur:

```bash
# Quick rollback
git checkout <previous-commit>
npm run build:js

# Or restore backup
cd /path/to/wp-content/plugins
rm -rf mcp-ai-wpoos
mv mcp-ai-wpoos-backup-YYYYMMDD mcp-ai-wpoos
```

Then clear caches again.

## Success Criteria

- ✅ Attachment ID displays in pending list
- ✅ Attachment ID displays in chat bubble
- ✅ Format: "SIZE • MIME • ID: XXX"
- ✅ `edit_gemini_image` tool works first try
- ✅ No JavaScript errors in console
- ✅ Works across different file types
- ✅ Old messages still display (graceful degradation)

## Sign-Off

- [ ] Files deployed to server
- [ ] Server cache cleared
- [ ] Browser cache cleared
- [ ] Console logs verified
- [ ] UI metadata verified with ID
- [ ] Tool functionality tested
- [ ] Documentation reviewed
- [ ] Issue marked as resolved

## Contact

For issues or questions:
- Check documentation in `docs/ATTACHMENT_ID_*.md`
- Review console logs for diagnostic info
- Collect Network tab screenshots
- Open issue with debug information
