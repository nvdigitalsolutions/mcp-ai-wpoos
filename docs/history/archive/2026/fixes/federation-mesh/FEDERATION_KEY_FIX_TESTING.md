# Federation Directory Mesh API Key Fix - Testing Guide

## Problem Fixed
When enabling "Federation Directory" in the WordPress admin settings, the mesh_inbound_api_key was not being automatically generated. This prevented federation from working properly.

## What Was Changed

### Code Change
File: `includes/admin/class-wp-mcp-ai-admin-settings-base.php`

The `sanitize_settings()` method now checks for **both** `enable_mesh` and `enable_federation_directory` when deciding whether to generate the mesh API key:

```php
// Before (only checked enable_mesh):
if ( isset( $settings['enable_mesh'] ) && ! empty( $settings['enable_mesh'] ) ) {
    if ( empty( $sanitized['mesh_inbound_api_key'] ) ) {
        $sanitized['mesh_inbound_api_key'] = $this->generate_mesh_api_key();
    }
}

// After (checks both enable_mesh OR enable_federation_directory):
$needs_mesh_key = ( isset( $settings['enable_mesh'] ) && ! empty( $settings['enable_mesh'] ) ) ||
                  ( isset( $settings['enable_federation_directory'] ) && ! empty( $settings['enable_federation_directory'] ) );

if ( $needs_mesh_key ) {
    if ( empty( $sanitized['mesh_inbound_api_key'] ) ) {
        $sanitized['mesh_inbound_api_key'] = $this->generate_mesh_api_key();
    }
}
```

## How to Test Manually

### Test 1: Enable Federation Directory (Primary Fix)
1. Log into WordPress admin
2. Navigate to: **NV oOS → Advanced → Federation & Mesh** (or `wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh`)
3. Check the **"Enable Federation Directory"** checkbox
4. Click **"Save Settings"**
5. **Expected Result**: 
   - Page reloads
   - You should see a section titled "Mesh Inbound API Key" with a generated key
   - The key should start with `mesh_` and be approximately 69 characters long
   - The warning message "Mesh Inbound API Key Not Generated" should NOT appear

### Test 2: Backward Compatibility (Enable Mesh Computing)
1. Navigate to: **NV oOS → Tools → Features**
2. Find and enable **"Mesh Computing"**
3. Click **"Save Settings"**
4. Return to: **NV oOS → Advanced → Federation & Mesh**
5. **Expected Result**: 
   - Mesh API key should be visible (same as Test 1)
   - This confirms the original functionality still works

### Test 3: Key Persistence
1. After completing Test 1, copy the generated mesh API key
2. Make another change in the Federation & Mesh settings (e.g., change "Federation Regions" to "us-east")
3. Click **"Save Settings"**
4. **Expected Result**:
   - The mesh API key should remain the same (not regenerated)
   - The key you copied should match the displayed key

### Test 4: Disable Federation Directory
1. Uncheck **"Enable Federation Directory"**
2. Click **"Save Settings"**
3. **Expected Result**:
   - The mesh API key section may be hidden
   - If you have enable_mesh enabled, the key should still be visible
   - The existing key should NOT be deleted

## Console Verification

If you have logging enabled in the plugin, you can verify the fix by checking the browser console when saving settings:

1. Open browser Developer Tools (F12)
2. Go to the Console tab
3. Enable Federation Directory and save
4. Look for console logs showing the form submission with field names

You should see logs similar to:
```
[NV oOS Settings] Field names: enable_federation_directory, federation_regions, federation_data_tags, federation_qps, federation_burst, federation_jwks_keys, federation_price_hints, mesh_inbound_api_key, mesh_peer_sites
```

Note that `mesh_inbound_api_key` is included in the saved fields.

## Automated Tests

Run the PHPUnit tests to verify all scenarios:

```bash
# Run specific test file
vendor/bin/phpunit tests/test-mesh-api-key-generation.php

# Or run all tests
composer run test
```

The test file covers:
- ✅ Key generation when federation directory is enabled
- ✅ Key generation when mesh computing is enabled (backward compatibility)
- ✅ Key persistence across saves
- ✅ No generation when both are disabled
- ✅ Generation when both are enabled
- ✅ Proper key format validation

## Troubleshooting

### Issue: Key Not Generating
**Symptoms**: After saving, the warning "Mesh Inbound API Key Not Generated" still appears

**Possible Causes**:
1. Form submission not working properly
2. JavaScript error preventing form submission
3. Server-side error during sanitization

**Debugging Steps**:
1. Check browser console for JavaScript errors
2. Enable WordPress debug mode: `define('WP_DEBUG', true);` in wp-config.php
3. Check PHP error log for any sanitization errors
4. Verify the checkbox is actually being checked (inspect HTML element)

### Issue: Key Changes on Every Save
**Symptoms**: The API key is different every time you save

**Possible Cause**: The existing key is not being preserved properly

**Debugging Steps**:
1. Check if the `mesh_inbound_api_key` field is being submitted in the form
2. Verify the `if ( empty( $sanitized['mesh_inbound_api_key'] ) )` condition is working correctly
3. Enable logging to see what values are being processed

## Related Files

- **Core Fix**: `includes/admin/class-wp-mcp-ai-admin-settings-base.php`
- **Tests**: `tests/test-mesh-api-key-generation.php`
- **Settings Section**: `includes/admin/sections/class-wp-mcp-ai-section-advanced.php`
- **Frontend UI**: Look for the "Federation & Mesh" subtab rendering in the Advanced section

## Impact

This fix ensures that:
1. Federation Directory can function independently of Mesh Computing
2. The mesh API key is automatically generated when needed
3. Backward compatibility with the existing Mesh Computing feature is maintained
4. Users don't need to manually generate or input API keys

## Support

If you encounter issues with this fix, please provide:
1. Screenshots of the settings page before and after clicking save
2. Browser console logs (with any JavaScript errors)
3. PHP error logs (if WP_DEBUG is enabled)
4. WordPress version and plugin version
