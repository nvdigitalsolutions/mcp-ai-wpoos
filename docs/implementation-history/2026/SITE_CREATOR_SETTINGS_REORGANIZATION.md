# Site Creator Settings Reorganization

## Problem
The Site Creator settings page was showing up as a subtab in the main Tools page at:
```
https://bots.nvdigital.solutions/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=site_creator
```

This should not have been visible there since Site Creator has its own separate admin menu page.

## Root Cause
The `site_creator` subtab was being registered in the Tools section (`includes/admin/sections/class-wp-mcp-ai-section-tools.php`) regardless of whether it should be shown. This created duplicate UI paths for the same functionality.

## Solution
Removed the `site_creator` subtab completely from the Tools page and moved all permission settings to the dedicated Site Creator admin page in the Pro addon.

## Changes Made

### 1. Core Plugin (`includes/admin/sections/class-wp-mcp-ai-section-tools.php`)
- **Removed**: The `site_creator` subtab registration from `get_subtab_groups()` method
- **Kept**: The field definitions for `enable_site_creator` and related permission settings remain in the core plugin, as these settings are used by tools to check permissions

### 2. Pro Addon (`addons/pro/includes/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php`)
- **Added**: New "Permissions" section with a complete settings form
- **Updated**: Configuration section now links to the Features subtab instead of generic Tools page
- **Includes**: All permission checkboxes:
  - Enable Site Creator
  - Allow Plugin Installation
  - Allow Theme Installation
  - Allow Option Updates
  - Allow WP-CLI Tools
  - Allow Elementor Kit Import

### 3. Tests (`tests/test-section-tools.php`)
- **Updated**: `test_all_subtabs_are_defined()` now verifies `site_creator` is NOT in the subtab list
- **Updated**: `test_site_creator_subtab_does_not_exist()` explicitly tests that the subtab doesn't exist
- **Removed**: Previous conditional visibility tests that are no longer applicable

### 4. Verification Script (`bin/verify-site-creator-toolkit-setting.sh`)
- **Updated**: Now checks that subtab is removed and permissions form exists in Pro addon

## User Experience

### Before
- Site Creator toolkit toggle: `Settings → NV oOS → Tools → Features`
- Site Creator permissions: `Settings → NV oOS → Tools → Site Creator` (duplicate path, confusing)

### After
- Site Creator toolkit toggle: `Settings → NV oOS → Tools → Features`
- Site Creator permissions: `Site Creator` menu (separate top-level menu)

## Navigation Flow

1. User enables "Enable Site Creator Toolkit" in `Tools → Features`
2. The separate "Site Creator" admin menu becomes functional
3. User configures permissions in `Site Creator → Overview` (Permissions section)
4. Site Creator tools check these permission settings before executing

## Technical Notes

- Settings are stored in the `wp_mcp_ai_settings` option (same as before)
- No database migration needed
- Settings remain backward compatible
- Tools will continue to work with existing saved settings
- The Pro addon page properly uses `settings_fields()` and `submit_button()` for WordPress standards compliance

## Testing
Run the verification script:
```bash
bash bin/verify-site-creator-toolkit-setting.sh
```

Run the updated unit tests:
```bash
vendor/bin/phpunit tests/test-section-tools.php
```

## Files Modified
1. `includes/admin/sections/class-wp-mcp-ai-section-tools.php` - Removed subtab
2. `addons/pro/includes/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php` - Added permissions form
3. `tests/test-section-tools.php` - Updated tests
4. `bin/verify-site-creator-toolkit-setting.sh` - Updated verification script
