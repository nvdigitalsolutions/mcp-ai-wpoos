# Federation Directory Checkbox Not Saving - Debug Guide

## Problem Statement
The "Enable Federation Directory" checkbox at `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh` does not persist when saved.

## What Should Happen

When you:
1. Check the "Enable Federation Directory" checkbox
2. Click "Save Settings"
3. Page reloads

Expected: Checkbox remains checked
**Actual: Checkbox becomes unchecked**

## Architecture Overview

### How Settings Are Saved (Subtab-Based Sections)

The Advanced section uses **subtab-based sanitization** to prevent data loss when saving settings from different subtabs. Here's the flow:

1. **User checks checkbox and clicks Save**
2. **JavaScript** (settings-dashboard.js):
   - Updates hidden field `subtab_advanced` to match current URL subtab (`federation_mesh`)
   - Submits form with all field values
3. **PHP Backend** (abstract-wp-mcp-ai-settings-section.php):
   - Reads `$_POST['subtab_advanced']` to determine which subtab is being saved
   - Only sanitizes fields that belong to that subtab
   - Returns sanitized values for **only the active subtab**
4. **Settings Saved**:
   - WordPress merges sanitized values with existing settings
   - Saves to database

### Critical Components

#### 1. Hidden Field (Line 354 in class-wp-mcp-ai-section-advanced.php)
```php
<input type="hidden" name="subtab_<?php echo esc_attr( $this->get_id() ); ?>" value="<?php echo esc_attr( $active_subtab ); ?>" />
```
**Rendered as**: `<input type="hidden" name="subtab_advanced" value="federation_mesh" />`

#### 2. JavaScript Fix (Lines 458-472 in settings-dashboard.js)
```javascript
const urlParams = new URLSearchParams(window.location.search);
const currentSubtab = urlParams.get('subtab');
if (currentSubtab) {
    $form.find('input[type="hidden"][name^="subtab_"]').each(function() {
        const $hiddenField = $(this);
        $hiddenField.val(currentSubtab);  // Ensures value matches URL
    });
}
```

#### 3. Server-Side Validation (Lines 137-143 in abstract-wp-mcp-ai-settings-section.php)
```php
$subtab_field_name = 'subtab_' . $this->get_id();
$submitted_subtab  = isset( $_POST[ $subtab_field_name ] ) ? sanitize_key( $_POST[ $subtab_field_name ] ) : '';
$is_form_submit = ( $submitted_subtab === $active_subtab ) && isset( $subtab_groups[ $submitted_subtab ] );

if ( ! $is_form_submit ) {
    return array();  // Don't process if subtabs don't match!
}
```

#### 4. Checkbox Handling (Lines 204-212 in abstract-wp-mcp-ai-settings-section.php)
```php
if ( 'checkbox' === $type ) {
    if ( $is_form_submit ) {
        // Checkbox is checked if present in input, unchecked otherwise.
        $sanitized[ $key ] = isset( $filtered_input[ $key ] ) ? (bool) $filtered_input[ $key ] : false;
    }
    continue;
}
```

## Debug Steps

### Step 1: Check Browser Console

1. Navigate to `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh`
2. Open browser DevTools (F12)
3. Go to Console tab
4. Check the "Enable Federation Directory" checkbox
5. Click "Save Settings"

**Look for these console messages:**

```
[NV oOS Settings] Form submission initiated
[NV oOS Settings] Updated subtab hidden field: subtab_advanced from [old] to federation_mesh
[NV oOS Settings] Checkbox states: {enable_federation_directory: true, ...}
[NV oOS Settings] Active tab: advanced
[NV oOS Settings] Fields being submitted: X
```

**What to check:**
- ✅ `enable_federation_directory: true` appears in checkbox states
- ✅ `subtab_advanced` is updated to `federation_mesh`
- ✅ Field count is greater than 0

### Step 2: Check Network Request

In DevTools Network tab:
1. Filter for "options.php" or the form action URL
2. Click on the request
3. Go to "Payload" or "Request" tab
4. Check form data

**Look for:**
```
wp_mcp_ai_settings[enable_federation_directory]: 1
subtab_advanced: federation_mesh
```

**If missing:**
- ❌ JavaScript not executing
- ❌ Form not submitting correctly
- ❌ Hidden field not being created

### Step 3: Enable PHP Debug Logging

Add this to `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Then in NV oOS settings, enable logging:
**Settings → General → Enable Logging** 

Try saving the checkbox again, then check `/wp-content/debug.log` for:

```
[NV oOS Subtab Sanitize] Section: advanced, Active: federation_mesh, Submitted: federation_mesh, Is Form Submit: YES
```

**If Is Form Submit = NO:**
- ❌ `$submitted_subtab` doesn't match `$active_subtab`
- ❌ Hidden field value is wrong
- ❌ Subtab doesn't exist in `get_subtab_groups()`

### Step 4: Check Database

After attempting to save, check the database:

```sql
SELECT option_value FROM wp_options WHERE option_name = 'wp_mcp_ai_settings';
```

Search for `enable_federation_directory` in the JSON value.

**What you should see:**
```json
{
  "enable_federation_directory": true,
  "federation_regions": "global",
  ...
}
```

**If it's `false` or missing:**
- The sanitization is working but setting it to `false`
- OR the merge isn't happening correctly

## Common Issues & Solutions

### Issue 1: Hidden field not updating
**Symptom:** Console shows old subtab value  
**Cause:** JavaScript not executing  
**Fix:** Clear browser cache, check for JavaScript errors

### Issue 2: Subtab mismatch in PHP
**Symptom:** Debug log shows "Is Form Submit: NO"  
**Cause:** `$submitted_subtab` != `$active_subtab`  
**Fix:** Verify URL has `subtab=federation_mesh`, check hidden field value

### Issue 3: Checkbox not in POST data
**Symptom:** Network tab doesn't show checkbox value  
**Cause:** Form not capturing checkbox  
**Fix:** Check if checkbox is actually in the `<form>` tags, not outside

### Issue 4: Minified JS not updated
**Symptom:** Console doesn't show new debug messages  
**Cause:** Browser loading old cached JS  
**Fix:** 
```bash
cd /home/runner/work/mcp-ai-wpoos/mcp-ai-wpoos
cp assets/js/settings-dashboard.js assets/js/settings-dashboard.min.js
```
Then hard refresh browser (Ctrl+Shift+R)

## Expected Behavior After Fix

1. ✅ Console logs show correct subtab and checkbox state
2. ✅ Network request includes `enable_federation_directory: 1`
3. ✅ PHP debug log shows "Is Form Submit: YES"
4. ✅ Checkbox remains checked after page reload
5. ✅ AI Peers menu appears in WordPress sidebar (if CPT registered)
6. ✅ Mesh Inbound API Key displays on the page

## Related Files

- **JavaScript**: `assets/js/settings-dashboard.js` (and `.min.js`)
- **Advanced Section**: `includes/admin/sections/class-wp-mcp-ai-section-advanced.php`
- **Base Section**: `includes/admin/sections/abstract-wp-mcp-ai-settings-section.php`
- **Federation Settings**: `includes/class-wp-mcp-ai-federation-settings.php`
- **Settings Dashboard**: `includes/admin/class-wp-mcp-ai-settings-dashboard.php`

## Testing Checklist

After making any fixes, test this complete flow:

- [ ] Navigate to `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh`
- [ ] Verify "Enable Federation Directory" checkbox is unchecked
- [ ] Check the checkbox
- [ ] Click "Save Settings"
- [ ] Verify success message appears
- [ ] Verify page reloads to same subtab
- [ ] **VERIFY CHECKBOX IS STILL CHECKED**
- [ ] Navigate away and come back
- [ ] **VERIFY CHECKBOX IS STILL CHECKED**
- [ ] Uncheck the checkbox
- [ ] Click "Save Settings"
- [ ] **VERIFY CHECKBOX IS UNCHECKED**

## Additional Notes

- The fix in `FIX_SUMMARY.md` claims this was already fixed, but the issue persists
- The JavaScript fix is present in the code
- Need to verify if it's a caching issue or a deeper problem
- Test site shows both Mesh Computing and Federation Directory as "Enabled" in the status section, but checkbox doesn't stay checked
