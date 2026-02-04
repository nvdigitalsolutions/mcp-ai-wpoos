# Pro Workflow Builder Double Instantiation Fix

**Date:** 2026-02-04  
**Issue:** Users being redirected to wrong Pro Workflow Builder URL  
**Fix:** Remove duplicate class instantiation

## Problem

Users were being redirected to an incorrect URL when accessing the Pro Workflow Builder:
- **Incorrect URL:** `/wp-admin/nvoos-pro-workflow-builder` (404 error)
- **Expected URL:** `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`

## Root Cause

The `WP_MCP_AI_Pro_Workflow_Builder_Page` class was being instantiated **twice**:

1. **First instantiation:** In `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php` at line 312
   ```php
   // Initialize the pro workflow builder page if pro version is enabled.
   if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION ) {
       new WP_MCP_AI_Pro_Workflow_Builder_Page();
   }
   ```

2. **Second instantiation:** In `addons/pro/mcp-ai-wpoos-pro.php` at line 153 (now removed)
   ```php
   // REMOVED - This was causing duplicate instantiation
   if ( class_exists( 'WP_MCP_AI_Pro_Workflow_Builder_Page' ) ) {
       new WP_MCP_AI_Pro_Workflow_Builder_Page();
   }
   ```

### Impact of Double Instantiation

When a class is instantiated twice, its constructor runs twice, which means:
- The `admin_menu` action hook is registered **twice**
- WordPress's menu system processes the same menu registration **twice**
- This can cause menu conflicts and incorrect URL generation

## Solution

**Removed** the duplicate instantiation from `addons/pro/mcp-ai-wpoos-pro.php`.

The class now instantiates itself only once at the bottom of its own file, following the pattern used by other pro admin page classes like `WP_MCP_AI_Architect_Agent_Settings_Page`.

### Changed Code

**File:** `addons/pro/mcp-ai-wpoos-pro.php`

**Before:**
```php
// Load Pro Workflow Builder (Phase 2.0.0 - Visual workflow builder with ReactFlow).
$workflow_builder_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php';
if ( file_exists( $workflow_builder_file ) ) {
    require_once $workflow_builder_file;
    
    // Instantiate the Workflow Builder to register its Admin page.
    if ( class_exists( 'WP_MCP_AI_Pro_Workflow_Builder_Page' ) ) {
        new WP_MCP_AI_Pro_Workflow_Builder_Page();
    }
}
```

**After:**
```php
// Load Pro Workflow Builder (Phase 2.0.0 - Visual workflow builder with ReactFlow).
$workflow_builder_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php';
if ( file_exists( $workflow_builder_file ) ) {
    require_once $workflow_builder_file;
    // Note: Class instantiates itself at the bottom of the file.
}
```

## Technical Details

### WordPress Admin Menu Registration

WordPress uses the `add_submenu_page()` function to register admin pages. When called with specific slug patterns, WordPress generates URLs in two different formats:

**Format 1: Direct File Path** (Incorrect for this use case)
```
/wp-admin/wp-mcp-ai-pro-workflow-builder
```
- Used when slug starts with `wp-` or matches WordPress core patterns
- WordPress looks for an actual PHP file at this path
- Results in 404 if file doesn't exist

**Format 2: Query Parameter** (Correct)
```
/wp-admin/admin.php?page=nvoos-pro-workflow-builder
```
- Used when slug doesn't match WordPress core patterns
- WordPress routes through `admin.php` with `page` parameter
- Works correctly with custom admin pages

### Page Slug Configuration

The Pro Workflow Builder uses the correct slug format:

```php
const PAGE_SLUG = 'nvoos-pro-workflow-builder';
```

This `nvoos-` prefix ensures WordPress generates Format 2 URLs (query parameter format).

### Menu Registration

```php
public function register_page() {
    add_submenu_page(
        'nvoos-pro-dashboard',                        // Parent menu
        __( 'Pro Workflow Builder', 'mcp-ai-wpoos' ), // Page title
        __( 'Pro Workflows', 'mcp-ai-wpoos' ),        // Menu title
        'manage_options',                             // Capability
        self::PAGE_SLUG,                              // Slug: 'nvoos-pro-workflow-builder'
        array( $this, 'render_page' )                 // Callback
    );
}
```

## Verification

### Code Verification
- [x] PHP syntax check passed: `php -l addons/pro/mcp-ai-wpoos-pro.php`
- [x] No duplicate instantiations found
- [x] PAGE_SLUG is correct: `nvoos-pro-workflow-builder`
- [x] Parent slug is correct: `nvoos-pro-dashboard`
- [x] No hardcoded URLs in JavaScript/CSS

### Testing Checklist
- [ ] Install plugin on test WordPress instance
- [ ] Navigate to **NV oOS Pro → Pro Workflows**
- [ ] Verify URL is: `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`
- [ ] Verify page loads without 404 error
- [ ] Verify React app loads correctly
- [ ] Run PHPUnit tests: `vendor/bin/phpunit tests/test-pro-workflow-builder-menu.php`

## Related Files

- **Main Fix:** `addons/pro/mcp-ai-wpoos-pro.php`
- **Class File:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`
- **Tests:** `tests/test-pro-workflow-builder-menu.php`
- **Previous Fix Docs:**
  - `docs/fixes/pro-workflow-builder-url-fix-2026-02-04.md`
  - `WORKFLOW_BUILDER_URL_ANALYSIS.md`
  - `QUICK_FIX_SUMMARY.md`

## Deployment Notes

After deploying this fix:

1. **Clear WordPress caches:**
   ```bash
   wp cache flush
   wp eval-file wp-content/plugins/mcp-ai-wpoos/bin/clear-admin-menu-cache.php
   ```

2. **Restart PHP-FPM:**
   ```bash
   sudo systemctl restart php-fpm
   ```

3. **Clear browser cache** or test in incognito mode

4. **Verify the fix:**
   - Log into WordPress admin
   - Go to **NV oOS Pro → Pro Workflows**
   - Check URL is: `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`
   - Verify page loads successfully

## Prevention

To prevent similar issues in the future:

1. **One Instantiation Per Class:** Each admin page class should only be instantiated once
2. **Consistent Pattern:** Follow the pattern of instantiating at the bottom of the class file
3. **Loader Comments:** Add comments in loader files to clarify instantiation is handled by the class itself
4. **Code Review:** Check for duplicate instantiations during code review

## References

- WordPress Codex: [Administration Menus](https://developer.wordpress.org/reference/functions/add_submenu_page/)
- Previous fix attempt: PR #3563
- Related issue: Menu URL generation for admin pages with custom slugs
