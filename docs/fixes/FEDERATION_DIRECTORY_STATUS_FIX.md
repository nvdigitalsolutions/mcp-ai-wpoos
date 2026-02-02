# Federation Directory Status Badge Fix

## Problem
The "Federation Directory" status badge on the Advanced → Federation & Mesh settings page was displaying incorrect status information.

### Symptoms
- Checkbox for "Enable Federation Directory" would be unchecked
- Status badge would show "Enabled" (green)
- Warning message at bottom would say "Directory Service Disabled"
- This contradictory information confused users

## Root Cause
File: `includes/admin/sections/class-wp-mcp-ai-section-advanced.php` line 1774

The status badge was checking the wrong setting variable:
- Was checking: `$federation_enabled` (reads from `enable_federation` in Tools section)
- Should check: `$directory_enabled` (reads from `enable_federation_directory` in Advanced section)

### Why This Happened
There are TWO separate federation-related settings:
1. **`enable_federation`** - Located in Tools → Features, enables general federation features
2. **`enable_federation_directory`** - Located in Advanced → Federation & Mesh, enables the federation directory service

The status badge was incorrectly displaying the status of setting #1 when it should display setting #2.

## Fix Applied
Changed line 1774-1775 from:
```php
<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $federation_enabled ? 'success' : 'warning' ); ?>">
    <?php echo esc_html( $federation_enabled ? __( 'Enabled', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' ) ); ?>
</span>
```

To:
```php
<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $directory_enabled ? 'success' : 'warning' ); ?>">
    <?php echo esc_html( $directory_enabled ? __( 'Enabled', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' ) ); ?>
</span>
```

## Impact
### Before Fix
- Status badge shows "Enabled" when Tools → Federation is enabled, regardless of checkbox state
- Users are confused why checkbox doesn't appear to save
- Status badge and warning message contradict each other

### After Fix
- Status badge correctly reflects the checkbox state
- When checkbox is checked, status shows "Enabled" (green)
- When checkbox is unchecked, status shows "Disabled" (orange)
- Status badge and warning message are now consistent

## Testing
To verify the fix:

1. Navigate to: `wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh`
2. Observe the "Federation Directory:" status badge in the "Current Status" section
3. Check the "Enable Federation Directory" checkbox
4. Click "Save Settings"
5. **Expected**: Status badge shows "Enabled" (green)
6. Uncheck the checkbox
7. Click "Save Settings"  
8. **Expected**: Status badge shows "Disabled" (orange)

## Related Issues
- Checkbox saving functionality: Working correctly (already fixed in previous updates)
- Mesh API key generation: Working correctly (checks `enable_federation_directory`)
- JavaScript subtab handling: Working correctly

## Files Changed
- `includes/admin/sections/class-wp-mcp-ai-section-advanced.php` (line 1774-1775)

## Date Fixed
2025-02-01
