# Admin Hook Suffix Fix Summary

## Problem Statement
The submenu pages in WP oOS were using incorrect hook suffixes in their `enqueue_assets()` methods, which could prevent assets from loading correctly on those pages.

## Issues Found

### 1. Incorrect Hook Suffix Patterns
Several submenu pages were using hardcoded or incorrect hook suffix strings:

- **Auth0 Setup** (`class-wp-mcp-ai-auth0-setup.php`):
  - ❌ Used: `'wp-oos_page_' . self::PAGE_SLUG`
  - This was using the menu title "WP oOS" instead of the parent slug
  - Also had a fallback to `'wp-mcp-ai-dashboard_page_' . self::PAGE_SLUG` which was hardcoded

- **MCP Server Diagnostic** (`class-wp-mcp-ai-mcp-server-diagnostic.php`):
  - ❌ Used: `'wp-mcp-ai-dashboard_page_wp-mcp-ai-mcp-diagnostic'`
  - Hardcoded hook suffix

- **Cron Manager** (`class-wp-mcp-ai-admin-cron-manager.php`):
  - ❌ Used: `'wp-mcp-ai-dashboard_page_' . self::PAGE_SLUG`
  - Hardcoded parent slug

- **Provider Diagnostics** (`class-wp-mcp-ai-provider-diagnostics.php`):
  - ❌ Used: `'wp-mcp-ai-dashboard_page_wp-mcp-ai-provider-diagnostic'`
  - Hardcoded hook suffix

### 2. Wrong Default Tab
The settings dashboard was defaulting to the "Overview" tab instead of "General" settings.

## Solution

### 1. Store and Use Hook Suffix from `add_submenu_page()`

WordPress's `add_submenu_page()` function returns the actual hook suffix that will be used by `admin_enqueue_scripts`. The correct approach is to:

1. Store the return value in a property
2. Use that stored value for the hook check

**Implementation:**

```php
class WP_MCP_AI_Example_Page {
    /**
     * Page hook suffix.
     *
     * @var string
     */
    private $page_hook = '';

    public function register_page() {
        // Store the return value
        $this->page_hook = add_submenu_page(
            'wp-mcp-ai-dashboard',
            __( 'Example Page', 'wp-mcp-ai' ),
            __( 'Example', 'wp-mcp-ai' ),
            'manage_options',
            'wp-mcp-ai-example',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        // Use the stored hook suffix
        if ( $this->page_hook !== $hook ) {
            return;
        }
        // Enqueue assets...
    }
}
```

### 2. Reorder Tabs to Make General First

Updated the tabs array in `WP_MCP_AI_Settings_Registry` to put 'general' first:

```php
private static $tabs = array(
    'general'        => array(
        'title' => 'General',
        'icon'  => 'dashicons-admin-settings',
    ),
    'overview'       => array(
        'title' => 'Overview',
        'icon'  => 'dashicons-dashboard',
    ),
    // ... other tabs
);
```

And updated the default fallback in `WP_MCP_AI_Settings_Dashboard::get_active_tab()`:

```php
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

if ( ! isset( $tabs[ $active_tab ] ) ) {
    $active_tab = 'general';
}
```

## Files Modified

1. `includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php`
2. `includes/admin/class-wp-mcp-ai-auth0-setup.php`
3. `includes/admin/class-wp-mcp-ai-admin-cron-manager.php`
4. `includes/admin/class-wp-mcp-ai-provider-diagnostics.php`
5. `includes/admin/class-wp-mcp-ai-settings-dashboard.php`
6. `includes/admin/class-wp-mcp-ai-settings-registry.php`

## Tests Added

### 1. `tests/test-admin-hook-suffixes.php`
Tests that verify:
- All submenu pages properly store hook suffixes
- Hook suffixes are not empty after registration
- Hook suffixes contain the expected page slug
- Hook suffixes are unique across pages
- The `enqueue_assets` method respects the correct hook

### 2. `tests/test-settings-dashboard-tab-order.php`
Tests that verify:
- General is the first tab in the tabs array
- Default active tab is 'general' when no parameter is provided
- Invalid tab parameters fall back to 'general'
- Valid tab parameters are respected
- All tabs are in the expected order

## Benefits

1. **Reliability**: Assets will always enqueue on the correct pages, regardless of changes to WordPress internals
2. **Maintainability**: No hardcoded hook suffixes to update if menu structure changes
3. **Consistency**: All submenu pages follow the same pattern
4. **Better UX**: Users see General settings first, which is more intuitive
5. **Test Coverage**: Comprehensive tests ensure the fix works and prevents regressions

## WordPress Best Practices

This fix follows WordPress best practices as documented in the [Plugin Handbook](https://developer.wordpress.org/plugins/administration-menus/):

> "The `add_submenu_page()` function returns a string called a **hook suffix**. This hook suffix is used with the `admin_enqueue_scripts` action hook to conditionally enqueue scripts and styles on that admin page only."

## Verification

To verify the fix works:

1. **Visual Check**: 
   - Go to WP Admin → WP oOS
   - Verify "General" tab is selected by default
   - Navigate to submenu pages and verify they load correctly

2. **Developer Tools**:
   - Open browser DevTools
   - Go to WP Admin → WP oOS → Auth0 Setup
   - Check Network tab - CSS/JS files should load
   - Check Console - no 404 errors for assets

3. **Run Tests**:
   ```bash
   composer test tests/test-admin-hook-suffixes.php
   composer test tests/test-settings-dashboard-tab-order.php
   ```

## Migration Notes

No migration or database changes needed. This is purely a code fix that improves how admin pages register and check their hook suffixes.
