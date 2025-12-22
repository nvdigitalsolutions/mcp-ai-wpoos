# Deduplication Fix Verification Guide

This guide explains how to verify that the duplicate playbook deduplication fix is working correctly.

## Problem Being Fixed

Previously, duplicate playbook attachments associated with professions were not being removed in three key scenarios:
1. **Saving a profession page** - Duplicates persisted after saving
2. **Using sync buttons** - The sync operations weren't clearly indicating duplicate removal
3. **Viewing metabox** - The playbook metabox could display duplicate entries

## What Was Fixed

### 1. Automatic Deduplication on Save
**File:** `includes/professions/class-wp-mcp-ai-profession-cpt.php`

When you save a profession, the system now automatically:
- Removes duplicate playbook attachments
- Keeps only the most recent playbook
- Updates the memory files to reflect the single playbook

### 2. Deduplication Before Display
**File:** `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-playbook.php`

When viewing a profession's playbook metabox:
- Duplicates are cleaned up before rendering
- You always see the most current playbook information
- No duplicate entries shown

### 3. Clear Messaging on Sync Buttons
**Files:** `includes/admin/sections/class-wp-mcp-ai-section-advanced.php` and `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`

The sync buttons now:
- Explicitly state they remove duplicates in button descriptions
- Show success messages confirming "Duplicates removed"
- Provide transparency about what operations do

## How to Verify

### Test Scenario 1: Profession Save
1. Navigate to **Professions → All Professions** in WordPress admin
2. Edit any existing profession
3. Check the database or memory files meta to see if duplicates exist (optional)
4. Click **Update** to save the profession
5. **Expected Result:** Duplicates are automatically removed from memory files
6. **Verification:** Check the playbook metabox - should show only one playbook

### Test Scenario 2: Sync Buttons
1. Navigate to **Settings → WP oOS → Advanced → Data Management**
2. Scroll to the **Playbook Management** section
3. Observe the button descriptions:
   - "Sync Changed Playbooks" should say: "Regenerates playbooks where content has changed **and removes duplicates**"
   - "Force Regenerate All Playbooks" should say: "Regenerates all playbooks even if unchanged **and removes duplicates**"
4. Click either sync button
5. **Expected Result:** Success message includes "Duplicates removed"
6. **Verification:** Page reloads showing updated playbook statistics

### Test Scenario 3: Metabox Display
1. Navigate to **Professions → All Professions**
2. Edit any profession
3. Look at the **Professional Playbook** metabox in the sidebar
4. **Expected Result:** Shows only one playbook with accurate information
5. **Verification:** No duplicate playbook entries visible

## Manual Database Check (Advanced)

If you want to verify at the database level:

```sql
-- Check for duplicate playbook attachments
SELECT 
    meta_value as profession_id,
    COUNT(*) as attachment_count
FROM wp_postmeta 
WHERE meta_key = '_wp_mcp_ai_playbook_profession_id'
GROUP BY meta_value
HAVING COUNT(*) > 1;

-- Should return 0 rows after deduplication
```

## Expected Behavior

### Before Fix
- Professions could have multiple playbook attachments
- Memory files meta contained duplicate attachment IDs
- Sync operations didn't clearly indicate duplicate removal
- Duplicates accumulated over time

### After Fix
- Each profession has exactly ONE playbook attachment in memory files
- Older playbooks remain in media library (for history) but are not associated
- Clear messaging about duplicate removal
- Automatic cleanup on multiple triggers

## Troubleshooting

### If Duplicates Still Appear

1. **Clear any caching** - Clear WordPress object cache, transients
2. **Check file permissions** - Ensure the plugin can write to wp-content/uploads
3. **Review error logs** - Check for any PHP errors related to deduplication
4. **Manual cleanup** - Run the cleanup method via WP-CLI:
   ```bash
   wp eval "WP_MCP_AI_Profession_Playbook_Seeder::cleanup_all_duplicates();"
   ```

### If Save Doesn't Trigger Deduplication

1. Verify the nonce is being sent (check browser console)
2. Ensure the `WP_MCP_AI_Profession_Playbook_Seeder` class is loaded
3. Check PHP error logs for ReflectionException

### If Sync Buttons Don't Show Updated Text

1. Clear browser cache
2. Hard refresh the page (Ctrl+Shift+R or Cmd+Shift+R)
3. Verify you're on the correct admin page

## Test Results Checklist

Use this checklist to verify all aspects of the fix:

- [ ] Duplicates removed when saving a profession
- [ ] Sync button descriptions mention "removes duplicates"
- [ ] "Sync Changed Playbooks" success message says "Duplicates removed"
- [ ] "Force Regenerate All Playbooks" success message says "Duplicates removed"
- [ ] Playbook metabox shows only one playbook
- [ ] Memory files meta contains only one attachment ID per profession
- [ ] Old playbook attachments remain in media library (not deleted)
- [ ] Statistics show 1:1 ratio of attachments to professions

## Files Changed

1. `includes/professions/class-wp-mcp-ai-profession-cpt.php` - Save handler
2. `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-playbook.php` - Display
3. `includes/admin/sections/class-wp-mcp-ai-section-advanced.php` - Button descriptions
4. `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` - Success messages
5. `tests/test-profession-playbook-seeder.php` - New test
6. `docs/DUPLICATE_PLAYBOOK_CLEANUP.md` - Updated documentation

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: `docs/DUPLICATE_PLAYBOOK_CLEANUP.md`
