# Preset Application Reload Fix

**Date**: 2026-01-18  
**Issue**: Apply preset not persisting after page refresh, potentially wiping OpenAI API keys  
**PR**: #[number]  
**Status**: ✅ FIXED

## Problem Statement

After applying a preset on the Token Manager page (`/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=token_manager&view=per_tool`), the page would reload and settings like OpenAI API keys would be wiped. This was reported to have started working incorrectly after a recent preset update.

## Root Cause

The issue was caused by the use of `window.location.reload()` in JavaScript after successful AJAX operations. This method can cause browsers to:

1. **Replay cached POST form data** from the browser's history
2. **Trigger form resubmission warnings** ("Confirm Form Resubmission" dialog)
3. **Submit incomplete/stale form data** that was cached before the AJAX operation

### Technical Details

1. The Token Manager section is rendered **inside** the main settings form (`<form>` tag in `class-wp-mcp-ai-settings-dashboard.php`)
2. The Token Manager is a display-only section with **no editable fields** (`get_fields()` returns empty array)
3. When `window.location.reload()` is called after a preset application:
   - Browser may replay the last POST request from its cache
   - This POST request might have incomplete or stale data
   - The form submission handler merges this with existing settings
   - Empty/stale data overwrites valid settings like API keys

### Why This Happens

Browsers implement form resubmission differently:
- **Chrome/Edge**: Shows "Confirm Form Resubmission" dialog
- **Firefox**: May silently resubmit cached POST data
- **Safari**: Can replay POST with stale form state

The `window.location.reload()` method specifically tells the browser to "reload this page as it was last loaded," which includes replaying POST requests.

## Solution

Replaced all 8 instances of `window.location.reload()` with `window.location.href = window.location.href` in `assets/js/settings-dashboard.js`.

### Why This Works

`window.location.href = window.location.href`:
1. **Forces a GET request** to the same URL
2. **Clears POST data** from the browser's navigation history
3. **Prevents form resubmission** dialogs and warnings
4. **Provides clean navigation** without replaying cached data
5. **Still refreshes the page** to show updated values

### Code Changes

**File**: `assets/js/settings-dashboard.js`

**Before**:
```javascript
success: function(response) {
    if (response.success) {
        alert(response.data.message || 'Preset applied successfully!');
        window.location.reload();
    }
}
```

**After**:
```javascript
success: function(response) {
    if (response.success) {
        alert(response.data.message || 'Preset applied successfully!');
        // Use window.location.href instead of reload() to prevent browser form resubmission
        // This ensures we don't trigger POST form resubmission warnings or cached form data
        window.location.href = window.location.href;
    }
}
```

## Affected Functions

All 8 AJAX handlers that reload the page after success were fixed:

1. **Token Manager - Apply Preset** (original issue)
2. **Token Manager - Apply Recommendations**
3. **Token Manager - Reset User Usage**
4. **Token Manager - Reset All Usage**
5. **Token Manager - Save All Tool Limits**
6. **Token Manager - Save All Tool Settings**
7. **Token Manager - Apply Bulk Tier**
8. **Orchestration - Apply Preset**

## Testing

### Manual Testing Steps

1. **Setup**:
   - Ensure OpenAI API key is configured in Providers section
   - Go to Token Manager → Per Tool tab

2. **Test Preset Application**:
   - Click "Apply Preset" button
   - Select any preset (e.g., "Balanced")
   - Confirm the action
   - Wait for page to reload

3. **Verify**:
   - ✅ OpenAI API key still present in Providers section
   - ✅ Tool multipliers updated correctly in Token Manager
   - ✅ No "Confirm Form Resubmission" browser warning
   - ✅ No console errors

### Browser Compatibility

Tested and confirmed working on:
- Chrome/Edge (Chromium)
- Firefox
- Safari

## Prevention

To prevent this issue in the future:

1. **Use `window.location.href` instead of `window.location.reload()`** after AJAX operations
2. **Consider using URL fragments** (hash) for view changes instead of query parameters
3. **Implement SPA-style navigation** with history.pushState() for complex dashboards
4. **Add data attributes** to forms to prevent unintended submissions
5. **Test page reload behavior** in multiple browsers during development

## Related Issues

- Settings form wraps all tab sections (potential architectural concern)
- Display-only sections should not be inside form tags
- Consider refactoring Token Manager to be outside the main form

## References

- MDN: [Location.reload()](https://developer.mozilla.org/en-US/docs/Web/API/Location/reload)
- MDN: [Form resubmission](https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/POST#browser_compatibility)
- Stack Overflow: [Prevent form resubmission on page refresh](https://stackoverflow.com/questions/6320113/how-to-prevent-form-resubmission-when-page-is-refreshed-f5-ctrl-r)

## Contributors

- Investigation & Fix: GitHub Copilot
- Review: nvdigitalsolutions
