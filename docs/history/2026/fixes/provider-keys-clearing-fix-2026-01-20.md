# Provider Keys Clearing Fix - Implementation Summary

**Date:** January 20, 2026  
**Issue:** Provider API keys were being cleared when navigating between tabs  
**Status:** ✅ FIXED

## Problem Description

When navigating from the Providers tab (e.g., with Gemini configured) to the Advanced → Settings Management tab, provider API keys (`gemini_api_key`, `openai_api_key`, etc.) were being cleared. The Settings Health check would then show:

```
Warning: No AI providers configured. At least one provider is required.
```

However, the Backup & Restore section showed "179 fields configured", indicating data was in the database but getting cleared during navigation.

## Root Cause Analysis

The issue was caused by **double-sanitization** in the WordPress Settings API:

### The Problem Flow

1. `register_setting()` was called with a `sanitize_callback`:
   ```php
   register_setting(
       'wp_mcp_ai_settings_group',
       WP_MCP_AI_Admin_Settings::OPTION_NAME,
       array(
           'type' => 'array',
           'sanitize_callback' => array( $this, 'sanitize_settings' ),
       )
   );
   ```

2. When a user saves settings:
   - `handle_save_settings()` correctly sanitizes with proper tab/subtab context
   - Calls `update_option($merged_settings)` to save to database
   - **WordPress automatically triggers the `sanitize_callback` AGAIN**
   - This second sanitization has **NO form context** (no `$_POST` data)

3. During the second sanitization:
   - `sanitize_settings()` is called with only `$input` parameter
   - `$active_tab` defaults to empty string `''`
   - Gets ALL sections from ALL tabs
   - Providers section's `sanitize_with_subtabs()` checks for `$_POST['subtab_providers']`
   - Finds nothing (no POST data during `update_option()`)
   - `$is_form_submit` becomes `false`
   - Returns empty array `array()`
   - Password fields not in the submission get **cleared**!

### Why Provider Keys Were Lost

The subtab protection logic in `abstract-wp-mcp-ai-settings-section.php` (lines 134-170) correctly prevents cross-subtab data loss **during actual form submissions**. However, when WordPress's sanitize callback triggers during `update_option()`:

- There's no `$_POST['subtab_providers']` field
- `$submitted_subtab` is empty `''`
- `$is_form_submit` check fails (line 143)
- Returns empty array to "protect" other subtabs
- But this causes legitimate saved data to be lost!

## The Fix

**Remove the `sanitize_callback` from `register_setting()`:**

```php
/**
 * Register settings with WordPress.
 *
 * IMPORTANT: We do NOT register a sanitize_callback here because:
 * 1. We manually sanitize in handle_save_settings() with proper context
 * 2. WordPress would call the callback on EVERY update_option(), causing double-sanitization
 * 3. The callback has no POST context during update_option(), breaking subtab protection
 * 4. This would cause provider keys to be cleared when navigating tabs
 */
public function register_settings() {
    register_setting(
        'wp_mcp_ai_settings_group',
        WP_MCP_AI_Admin_Settings::OPTION_NAME,
        array(
            'type' => 'array',
            // No sanitize_callback - we handle sanitization manually in handle_save_settings().
        )
    );
}
```

## Why This Fix Works

1. **Single Sanitization Point:** Settings are sanitized **only** in `handle_save_settings()` with full form context
2. **Proper Context:** Sanitization has access to `$_POST` data, tab, and subtab information
3. **No Double-Processing:** `update_option()` saves data directly without re-sanitizing
4. **Subtab Protection Works:** The subtab protection logic now only runs during actual form submissions

## Safety Considerations

### Is this safe?

**Yes**, because:

1. **We still sanitize:** Settings are thoroughly sanitized in `handle_save_settings()` before any save
2. **All paths covered:** Every save operation goes through `handle_save_settings()` which manually calls `sanitize_settings()`
3. **Defense in depth:** 
   - Section-level sanitization (`WP_MCP_AI_Settings_Section::sanitize()`)
   - Field-level sanitization (password, text, URL, etc.)
   - Validation layer (`WP_MCP_AI_Settings_Section::validate()`)
   - Sensitive key protection (lines 352-391 in settings dashboard)

### What about other update_option() calls?

The codebase has several `update_option()` calls for the settings:
- **Import settings** (line 1072): Calls `sanitize_settings()` manually before saving
- **Reset settings** (line 1137): Uses default settings (pre-sanitized)
- **Settings Registry** (lines 159, 178): Uses merge with existing (preserves sanitized data)

All paths are safe because they either:
- Call `sanitize_settings()` manually before `update_option()`, or
- Save pre-sanitized default values, or
- Merge with existing sanitized settings

## Files Changed

1. **includes/admin/class-wp-mcp-ai-settings-dashboard.php**
   - Removed `'sanitize_callback'` from `register_setting()` (line 135)
   - Added comprehensive documentation explaining why (lines 121-127)

2. **tests/test-provider-keys-tab-navigation.php** (NEW)
   - Test that provider keys persist when navigating tabs
   - Test that Settings Health check doesn't trigger unwanted saves
   - Test that register_setting callback doesn't run on GET requests
   - Test navigating between provider subtabs

3. **bin/verify-provider-keys-fix.sh** (NEW)
   - Automated verification script to ensure fix is in place

## Testing

### Automated Tests

```bash
# Run new test suite
vendor/bin/phpunit tests/test-provider-keys-tab-navigation.php

# Run existing provider subtab tests
vendor/bin/phpunit tests/test-provider-subtab-settings.php
vendor/bin/phpunit tests/test-subtab-cross-contamination.php

# Verify fix is applied
bin/verify-provider-keys-fix.sh
```

### Manual Testing Steps

1. **Configure provider keys:**
   - Go to NV oOS → AI Providers → Gemini
   - Enter a Gemini API key: `AIza-test-key-12345`
   - Save Changes

2. **Navigate to Advanced tab:**
   - Click "Advanced" tab
   - Click "Settings Management" subtab
   - Click "Check Settings Health"

3. **Verify keys persist:**
   - Go back to AI Providers → Gemini
   - Verify API key field still shows the key
   - Settings Health should show "configured providers: 1" (or more)

4. **Test form saves:**
   - Make a change to any setting
   - Save
   - Verify change persisted
   - Verify other settings not affected

## Related Issues

This fix resolves the same root cause as documented in:
- `docs/implementation-history/2026/settings/HISTORICAL_SETTINGS_FIXES_CONSOLIDATED.md`
  - "SUBTAB_FIX_SUMMARY.md" section (lines 120-189)
  - Previous fixes addressed the merge logic but not the double-sanitization

## Prevention

To prevent similar issues in the future:

1. **Never register sanitize callbacks** for complex multi-tab/subtab settings
2. **Always sanitize manually** in the save handler with full context
3. **Test tab navigation** after any settings changes
4. **Monitor `update_option()` calls** - ensure they don't inadvertently trigger sanitization

## References

- WordPress Settings API: https://developer.wordpress.org/apis/settings/
- sanitize_callback behavior: Triggers on every `update_option()` via `sanitize_option()` filter
- Subtab protection logic: `includes/admin/sections/abstract-wp-mcp-ai-settings-section.php` lines 114-171

---

**Implementation Date:** January 20, 2026  
**Fixed By:** GitHub Copilot Agent  
**Verified:** Automated tests + verification script
