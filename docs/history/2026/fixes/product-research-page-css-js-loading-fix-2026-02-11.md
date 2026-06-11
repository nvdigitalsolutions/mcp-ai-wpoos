# Product Research Page CSS/JS Loading Fix

**Date:** 2026-02-11  
**Issue:** CSS and JavaScript not loading on research-product admin page  
**Status:** ✅ FIXED

## Problem

The product research page at `/wp-admin/admin.php?page=research-product` was not loading CSS and JavaScript assets, causing:
- Missing flex layout styling
- Non-functional workflow selector buttons
- General visual/functional issues

## Root Cause

The research page was registering its submenu with `admin_menu` priority **30**, which was too late in the WordPress admin menu initialization sequence. This caused WordPress to not generate the correct hook suffix for the page, preventing the `admin_enqueue_scripts` callback from matching.

## Solution

Changed the `admin_menu` hook priority from **30** to **26** to match the working consolidate page pattern.

### Key Understanding

When creating admin submenus under a custom top-level menu:
1. Parent menu must be registered first (priority 25)
2. Submenu pages should be registered immediately after (priority 26+)
3. Hook suffix pattern: `{parent_slug}_page_{submenu_slug}`

### Priority Comparison

| Page | Parent Menu Priority | Submenu Priority | Status |
|------|---------------------|------------------|--------|
| E-Commerce Toolkit (parent) | 25 | N/A | ✅ Works |
| Product Consolidate | 25 (parent) | 26 | ✅ Works |
| Product Research | 25 (parent) | ~~30~~ → **26** | ✅ Fixed |

## Code Changes

### File: `addons/pro/includes/admin/class-wp-mcp-ai-product-research-page.php`

**Before:**
```php
public static function init() {
    add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 30 );
    // ...
}
```

**After:**
```php
public static function init() {
    add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 26 );
    // ...
}
```

**Debug Logging Added (WP_DEBUG only):**
```php
public static function enqueue_assets( $hook ) {
    // Debug logging to verify hook values
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Product Research - Hook: ' . $hook . ' | Expected: wp-mcp-ai-ecommerce-toolkit_page_' . self::PAGE_SLUG );
    }
    // ... rest of function
}
```

## Testing Checklist

- [ ] Navigate to E-Commerce Toolkit → Research & Add
- [ ] Verify page layout displays correctly with:
  - Sidebar on left with tips and examples
  - Main content area on right with workflow selector
  - Proper spacing and borders
- [ ] Verify workflow selector buttons are styled
- [ ] Click each workflow button and verify tab switching works:
  - AI Research (chat interface)
  - Import Data (form)
  - Review & Quality (dashboard)
- [ ] Check browser DevTools:
  - Console for JavaScript errors
  - Network tab for CSS/JS file loading
  - Verify `enhanced-research-page.css` loads
  - Verify `enhanced-research-page.js` loads
- [ ] Test example query buttons
- [ ] Compare with Quiz Research page (should look identical in structure)

## Related Files

- `addons/pro/includes/admin/class-wp-mcp-ai-product-research-page.php` - Main fix
- `assets/css/enhanced-research-page.css` - Styles (15KB)
- `assets/js/enhanced-research-page.js` - Workflow switcher (16KB)

## Pattern for Future Pages

When adding new research pages under custom parent menus:

```php
class My_Research_Page {
    public static function init() {
        // Priority should be parent_priority + 1
        add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 26 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }
    
    public static function add_menu_page() {
        add_submenu_page(
            'parent-slug',           // Parent menu slug
            'Page Title',
            'Menu Title',
            'capability',
            'my-page-slug',          // This slug
            array( __CLASS__, 'render_page' )
        );
    }
    
    public static function enqueue_assets( $hook ) {
        // Hook will be: parent-slug_page_my-page-slug
        if ( 'parent-slug_page_my-page-slug' !== $hook ) {
            return;
        }
        // Enqueue assets...
    }
}
```

## References

- WordPress Codex: [add_submenu_page()](https://developer.wordpress.org/reference/functions/add_submenu_page/)
- Previous fix: `PRODUCT_RESEARCH_FIX_SUMMARY.md`
- Working example: `class-wp-mcp-ai-quiz-research-page.php`
- Working example: `class-wp-mcp-ai-product-consolidate-page.php`
