# Pro Workflow Builder Menu Restoration - Fix Summary

**Date:** February 5, 2026  
**Issue:** Pro Workflow Builder page missing from "NV oOS Pro" admin menu  
**Status:** ✅ FIXED - Ready for Manual Testing

---

## Problem

The Pro Workflow Builder admin page was not appearing under the "NV oOS Pro" menu after recent PRs, despite the code existing and being loaded.

**Expected URL:** `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`  
**Expected Menu Location:** WP Admin → NV oOS Pro → Pro Workflows

---

## Root Cause

The Pro Workflow Builder class was being instantiated on the `admin_init` hook, which fires AFTER WordPress processes the `admin_menu` hook.

### Problematic Code (Before Fix)

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`

**Lines 389-395 (BEFORE):**
```php
function wp_mcp_ai_pro_init_workflow_builder_page() {
	if ( ! is_admin() || ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) ) {
		return;
	}
	new WP_MCP_AI_Pro_Workflow_Builder_Page();
}
add_action( 'admin_init', 'wp_mcp_ai_pro_init_workflow_builder_page', 10 );
```

### Why It Failed

**Incorrect WordPress Hook Order (as documented in old code):**
```
1. plugins_loaded (priority 15) - Pro plugin loads, includes this file
2. plugins_loaded (priority 20) - Toolkit Enhancement loads Pattern classes
3. admin_init (priority 10) - Function runs, instantiates class ❌ 
4. admin_menu (priority 26) - Class tries to register menu ❌
```

**Actual WordPress Hook Order:**
```
1. plugins_loaded
2. init
3. admin_menu    ← Menus register here!
4. admin_init    ← Too late! Menu hook already fired!
```

Since the class constructor registers an `admin_menu` hook (line 45), but the class isn't instantiated until `admin_init`, the menu registration never happens.

---

## Solution

Changed instantiation from a hooked function to immediate execution when the file is loaded.

### Fixed Code (After)

**Lines 371-388 (AFTER):**
```php
/**
 * Initialize the pro workflow builder page.
 *
 * Instantiated immediately when file is loaded (during plugins_loaded) to ensure
 * the admin_menu hook registration in the constructor happens before WordPress
 * processes the admin_menu action.
 *
 * Correct WordPress Hook Order:
 * 1. plugins_loaded - Pro plugin loads, includes this file, instantiates class
 * 2. admin_menu (priority 25) - Parent menu 'nvoos-pro-dashboard' registers
 * 3. admin_menu (priority 26) - This class registers its submenu page
 * 4. admin_init - Other initialization (too late for menu registration)
 *
 * @since 2.0.0
 */
if ( is_admin() && ! ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) ) {
	new WP_MCP_AI_Pro_Workflow_Builder_Page();
}
```

### Correct Execution Flow

1. **plugins_loaded (priority 15):** 
   - `wp_mcp_ai_pro_init()` called
   - `wp_mcp_ai_pro_load_admin_sections()` called
   - Workflow builder class file required
   - **Class instantiated immediately ✅**
   - Constructor registers `admin_menu` hook (priority 26)

2. **admin_menu (priority 25):**
   - Pro Dashboard parent menu registers

3. **admin_menu (priority 26):**
   - Pro Workflow Builder submenu registers ✅

4. **admin_init:**
   - Other admin initialization

---

## Files Modified

1. **addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php**
   - Removed `wp_mcp_ai_pro_init_workflow_builder_page()` function
   - Removed `add_action( 'admin_init', ... )` hook
   - Added direct instantiation at bottom of file
   - Updated documentation to reflect correct hook order

---

## Pattern Match

This fix follows the exact same pattern used by other working Pro admin pages:

### Remote Sites Admin (Already Working) ✅

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`

**Bottom of file (lines 140-142):**
```php
if ( is_admin() ) {
    new WP_MCP_AI_Pro_Remote_Sites_Admin();
}
```

**Why it works:** Instantiates immediately when file loads during `plugins_loaded`.

### Pro Orchestration Dashboard (Already Working) ✅

**File:** `addons/pro/mcp-ai-wpoos-pro.php` (lines 343-345)

```php
if ( is_admin() ) {
    require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-orchestration-dashboard.php';
    new WP_MCP_AI_Orchestration_Dashboard();
}
```

**Why it works:** Instantiates immediately when file loads during `plugins_loaded`.

---

## Built Assets Verified

The following built assets exist and are ready to load:

**Directory:** `addons/pro/build/workflow-builder/`

- ✅ `workflow-builder.js` (187 KB)
- ✅ `workflow-builder.css` (13 KB)
- ✅ `workflow-builder.asset.php` (dependency manifest)

**Dependencies declared:**
- `react`
- `react-dom`
- `wp-element`
- `wp-i18n`

---

## Expected Menu Structure

After fix, the menu structure should be:

```
WP Admin
└── NV oOS Pro (nvoos-pro-dashboard)
    ├── Overview
    ├── Pro Workflows ✅ (nvoos-pro-workflow-builder) ← Restored!
    ├── Orchestration Monitor (mcp-ai-orchestration-pro)
    ├── Remote Sites
    ├── Asset Inventory
    ├── Security Audits
    ├── Security Training
    └── Supplier Security
```

---

## Testing Instructions

### 1. Verify Menu Appears

1. Log in to https://bots.nvdigital.solutions/wp-admin
2. Navigate to **NV oOS Pro** menu
3. **Verify:** "Pro Workflows" submenu item appears

### 2. Verify Page Loads

1. Click **NV oOS Pro → Pro Workflows**
2. **Verify:** URL is `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`
3. **Verify:** Page title shows "Pro Workflow Builder"
4. **Verify:** React component renders (workflow builder interface appears)

### 3. Verify Assets Load

1. Open browser DevTools (F12)
2. Go to **Network** tab
3. Refresh the page
4. **Verify:** `workflow-builder.js` loads (Status 200)
5. **Verify:** `workflow-builder.css` loads (Status 200)
6. Go to **Console** tab
7. **Verify:** No JavaScript errors

### 4. Verify Functionality

1. **Verify:** Workflow list appears (if any workflows exist)
2. **Verify:** "New Workflow" or similar button is visible
3. **Verify:** Workflow templates are available (dropdown or list)
4. **Verify:** UI is interactive (buttons clickable, forms work)

### 5. Screenshot

Take a screenshot showing:
- WordPress admin with "NV oOS Pro" menu expanded
- "Pro Workflows" menu item visible
- Pro Workflow Builder page rendered with React components

---

## Related Issues

This is the same root cause as the Asset Inventory menu fix from February 5, 2026:

- **Asset Inventory:** Fixed by moving delegate instantiation from `admin_init` to immediate
- **Pro Workflow Builder:** Fixed by moving instantiation from `admin_init` to immediate

Both issues were caused by instantiating classes too late, after the `admin_menu` hook had already fired.

---

## Testing Results

**Code Review:** ✅ PASSED
- PHP syntax valid
- Follows working patterns from other Pro pages
- Correct WordPress hook order
- Built assets verified

**Manual Testing:** ⏳ PENDING
- Awaiting deployment to https://bots.nvdigital.solutions
- Requires admin access to verify menu and page rendering

---

## Rollback Instructions

If the fix causes issues, rollback by reverting the commit:

```bash
git revert a67f30a
```

Then restore the original `admin_init` hook pattern.

---

## References

- **Pro Workflow Builder Class:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`
- **Pro Plugin Main File:** `addons/pro/mcp-ai-wpoos-pro.php`
- **Working Example:** Remote Sites Admin (`addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`)
- **Related Fix:** Asset Inventory (`docs/fixes/asset-inventory-menu-missing-analysis-2026-02-05.md`)
- **WordPress Hook Order:** https://codex.wordpress.org/Plugin_API/Action_Reference

---

## Commit

**Commit Hash:** a67f30a  
**Branch:** copilot/restore-pro-workflow-builder  
**Message:** Fix Pro Workflow Builder menu registration timing issue
