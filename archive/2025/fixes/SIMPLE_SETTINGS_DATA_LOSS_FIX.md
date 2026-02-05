# Simple Settings Page - Data Loss Bug Fix

**Date:** February 5, 2026  
**Issue:** Critical data loss bug in Simple Settings Page  
**Status:** ✅ FIXED

## Summary

The Simple Settings Page (`options-general.php?page=wp-mcp-ai-simple-settings`) had a critical bug that could wipe out settings from tabs that weren't displayed in the simple interface.

## The Bug

### What Was Happening

1. **Form displayed** only General OR Providers fields (~130 fields from 2 tabs)
2. **Form submitted** with `save_all_tabs=1` hidden field
3. **Handler sanitized** ALL 8 tabs (~350 fields total)
4. **Checkboxes from invisible tabs** (Tools, Orchestration, Advanced, etc.) were not in the form
5. **Section sanitization** treated missing checkboxes as "false" = unchecked
6. **Result:** Settings from Tools, Orchestration, Advanced tabs got cleared!

### Code That Caused The Issue

```php
// includes/admin/class-wp-mcp-ai-simple-settings-page.php (line 187)
<input type="hidden" name="save_all_tabs" value="1" />
```

This told the handler to sanitize ALL tabs, but the form only contained fields from 1 tab.

### Why It Was Dangerous

```
Simple Settings Form (General tab):
┌────────────────────────────┐
│ enable_logging       [✓]   │  ← In form, value submitted
│ default_provider     [▼]   │  ← In form, value submitted
│ request_timeout      [300] │  ← In form, value submitted
└────────────────────────────┘

Tools Tab (NOT in form):
┌────────────────────────────┐
│ enable_quiz_system   [✓]   │  ← NOT in form, treated as unchecked!
│ enable_media_toolkit [✓]   │  ← NOT in form, treated as unchecked!
└────────────────────────────┘

After Save:
- enable_logging ✓ → ✓ (preserved - was in form)
- enable_quiz_system ✓ → ✗ (CLEARED - wasn't in form!)
- enable_media_toolkit ✓ → ✗ (CLEARED - wasn't in form!)
```

## The Fix

### What Changed

Removed the `save_all_tabs=1` hidden field from the Simple Settings form.

```php
// BEFORE (dangerous):
<input type="hidden" name="save_all_tabs" value="1" />

// AFTER (safe):
<!-- Removed save_all_tabs flag to prevent data loss -->
<!-- Simple Settings only displays General OR Providers fields -->
```

### How It Works Now

Both pages now behave consistently:

| Page | Displays | Saves |
|------|----------|-------|
| Main Dashboard | Active tab fields | Active tab only |
| Simple Settings | General OR Providers | Active tab only |

```php
// Main Dashboard saving Tools tab:
$active_tab = 'tools';
$save_all_tabs = false;
// Result: Only Tools tab settings are sanitized and saved

// Simple Settings saving General tab:
$active_tab = 'general';
$save_all_tabs = false;  // Changed from true!
// Result: Only General tab settings are sanitized and saved
```

### Settings from Other Tabs

```php
// In handle_save_settings():
$existing_settings = get_option( 'wp_mcp_ai_settings', array() );
$sanitized_new = $this->sanitize_settings( $posted_settings, $active_tab );
$merged_settings = array_merge( $existing_settings, $sanitized_new );

// This preserves settings from tabs that weren't sanitized
```

## Impact

### Before Fix
❌ Saving General settings cleared Tools settings  
❌ Saving Providers settings cleared Advanced settings  
❌ Users would lose feature toggles and configuration  
❌ Required re-enabling features after each save  

### After Fix
✅ Saving General settings preserves Tools settings  
✅ Saving Providers settings preserves Advanced settings  
✅ All settings from other tabs remain intact  
✅ No data loss  

## Related Files

- **Bug Fix:** `includes/admin/class-wp-mcp-ai-simple-settings-page.php` (line 187)
- **Handler:** `includes/admin/class-wp-mcp-ai-settings-dashboard.php` (lines 268-600)
- **Documentation:** `docs/architecture/SETTINGS_METHODOLOGY.md`
- **Nonce Fix:** `FIX_NONCE_CONFLICT.md`

## Testing

To verify the fix works:

1. **Before Testing:** Note current settings
   ```bash
   wp option get wp_mcp_ai_settings --format=json > before.json
   ```

2. **Test Simple Settings (General tab):**
   - Go to Settings → NV oOS
   - View General tab
   - Change a General setting
   - Save

3. **Verify no data loss:**
   ```bash
   wp option get wp_mcp_ai_settings --format=json > after.json
   diff before.json after.json
   ```
   
4. **Expected Result:**
   - Only General settings changed
   - Tools, Providers, Advanced settings unchanged

## Prevention

To prevent similar issues:

1. **Rule:** Never use `save_all_tabs=1` unless form displays ALL fields from ALL tabs
2. **Rule:** Always match save scope to displayed fields
3. **Rule:** Test checkbox persistence when modifying save logic
4. **Rule:** Document save behavior in code comments

## Related Issues

- **Original Issue:** Pro Features page "link expired" error
- **Root Cause:** Nonce conflict (Settings API vs Admin Post API)
- **Secondary Bug:** Simple Settings data loss (save_all_tabs flag)

Both issues are now fixed.

---

**Status:** ✅ RESOLVED  
**Severity:** Critical (data loss)  
**Fix Complexity:** Low (1 line removed)  
**Testing:** Required before production deployment
