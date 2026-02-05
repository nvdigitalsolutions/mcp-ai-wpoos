# Pro Workflow Builder URL Format Fix

**Date:** 2026-02-04  
**Issue:** Pro Workflow Builder menu link using incorrect URL format

## Problem

The Pro Workflow Builder menu item in the WordPress admin was generating an incorrect URL:

- ❌ **Incorrect:** `https://bots.nvdigital.solutions/wp-admin/wp-mcp-ai-pro-workflow-builder`
- ✅ **Correct:** `https://bots.nvdigital.solutions/wp-admin/admin.php?page=nvoos-pro-workflow-builder`

When users clicked on "Pro Workflows" in the NV oOS Pro admin menu, they would get a 404 error because WordPress was treating the slug as a direct file path instead of a query parameter for `admin.php`.

## Root Cause

The page slug `wp-mcp-ai-pro-workflow-builder` was causing WordPress to interpret it as a PHP file in the `/wp-admin/` directory rather than as an admin page parameter. This happened because:

1. The slug started with `wp-` (WordPress core file naming convention)
2. The slug contained multiple hyphens, making it look like a file name
3. WordPress's menu system has special handling for certain slug patterns

## Solution

Changed the `PAGE_SLUG` constant in `class-wp-mcp-ai-pro-workflow-builder-page.php`:

```php
// Before
const PAGE_SLUG = 'wp-mcp-ai-pro-workflow-builder';

// After
const PAGE_SLUG = 'nvoos-pro-workflow-builder';
```

This aligns with the naming convention used by other Pro Dashboard submenu pages:
- `nvoos-pro-dashboard-audits`
- `nvoos-pro-chart-settings`
- `nvoos-pro-dashboard-suppliers`
- `nvoos-architect-agent-toolkit`

## Impact

### User-Facing Changes
- The Pro Workflows menu item now correctly links to `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`
- Users can successfully access the Pro Workflow Builder interface

### Technical Changes
- **File Modified:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`
- **Lines Changed:** 1 line (line 29)
- **Backward Compatibility:** No database changes needed; workflow data is stored independently of the page slug

### Testing
Created `tests/test-pro-workflow-builder-menu.php` to verify:
1. The menu is registered correctly under the Pro Dashboard parent
2. The slug follows the correct naming convention (`nvoos-` prefix)
3. The generated URL uses the `admin.php?page=` format

## Verification

To verify the fix is working:

1. Log in to WordPress admin as an administrator
2. Navigate to the **NV oOS Pro** menu in the sidebar
3. Click on **Pro Workflows**
4. Verify the URL in the browser is: `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`
5. Verify the page loads successfully (shows "Pro Workflow Builder" heading)

## Related Files

- `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php` - Main change
- `tests/test-pro-workflow-builder-menu.php` - New test file
- `addons/pro/mcp-ai-wpoos-pro.php` - Loads the workflow builder class (no changes needed)

## References

- WordPress Codex: [add_submenu_page()](https://developer.wordpress.org/reference/functions/add_submenu_page/)
- WordPress Codex: [Admin Menu URL Generation](https://developer.wordpress.org/reference/functions/menu_page_url/)
