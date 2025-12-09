# Performance Buttons Fix Documentation

## Issue
Performance test buttons on the page `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=performance_monitoring` were not working.

## Root Cause Analysis

### Problem 1: JavaScript Not Enqueued
The `WP_MCP_AI_Section_Performance` class has an `enqueue_assets()` method that was supposed to load the JavaScript:

```php
public function enqueue_assets( $hook ) {
    // Only load on settings page.
    if ( strpos( $hook, 'wp-mcp-ai' ) === false ) {
        return;
    }
    
    wp_enqueue_script(
        'wp-mcp-ai-performance-admin',
        WP_MCP_AI_URL . 'assets/js/performance-admin.js',
        array( 'jquery' ),
        WP_MCP_AI_VERSION,
        true
    );
    
    wp_localize_script(
        'wp-mcp-ai-performance-admin',
        'wpMcpAiPerformance',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wp_mcp_ai_performance' ),
        )
    );
}
```

However, this method was registered in the constructor:
```php
public function __construct() {
    // ...
    if ( is_admin() ) {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }
}
```

**The Issue:** The Performance section was only instantiated during the `render()` phase, which happens AFTER the `admin_enqueue_scripts` hook has already fired. This meant the JavaScript was never enqueued.

### Problem 2: AJAX Handlers Not Registered
Similarly, the AJAX handlers were registered in the constructor:

```php
public function __construct() {
    add_action( 'wp_ajax_wp_mcp_ai_run_performance_test', array( $this, 'ajax_run_test' ) );
    add_action( 'wp_ajax_wp_mcp_ai_get_performance_metrics', array( $this, 'ajax_get_metrics' ) );
    add_action( 'wp_ajax_wp_mcp_ai_export_test_results', array( $this, 'ajax_export_results' ) );
    // ...
}
```

Since the class wasn't instantiated early enough, these handlers were never registered.

## The Fix

### Part 1: Enqueue JavaScript in Dashboard Controller
Instead of relying on the Performance section to enqueue its own JavaScript, we added the enqueue logic to the main dashboard controller (`WP_MCP_AI_Settings_Dashboard`):

```php
// Enqueue performance admin scripts if on advanced tab with performance_monitoring subtab.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
if ( isset( $_GET['tab'] ) && 'advanced' === $_GET['tab'] && isset( $_GET['subtab'] ) && 'performance_monitoring' === $_GET['subtab'] ) {
    $performance_admin_js_path = WP_MCP_AI_PATH . 'assets/js/performance-admin.js';
    wp_enqueue_script(
        'wp-mcp-ai-performance-admin',
        WP_MCP_AI_URL . 'assets/js/performance-admin.js',
        array( 'jquery' ),
        file_exists( $performance_admin_js_path ) ? filemtime( $performance_admin_js_path ) : WP_MCP_AI_VERSION,
        true
    );

    wp_localize_script(
        'wp-mcp-ai-performance-admin',
        'wpMcpAiPerformance',
        array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'wp_mcp_ai_performance' ),
            'runningText' => __( 'Running...', 'wp-mcp-ai' ),
        )
    );
}
```

**Why this works:** The dashboard controller's `enqueue_assets()` method is called on the `admin_enqueue_scripts` hook, which fires at the right time.

### Part 2: Instantiate Performance Section Early
We modified the Pro addon to instantiate the Performance section during initialization:

```php
function wp_mcp_ai_pro_load_admin_sections() {
    // Load Performance section.
    $performance_section_file = WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';
    if ( file_exists( $performance_section_file ) ) {
        require_once $performance_section_file;

        // Instantiate the Performance section to register AJAX handlers.
        // The instance is needed early so AJAX hooks are registered before WordPress processes AJAX requests.
        if ( class_exists( 'WP_MCP_AI_Section_Performance' ) ) {
            new WP_MCP_AI_Section_Performance();
        }
    }
}
```

**Why this works:** This function is called in `wp_mcp_ai_pro_init()` which runs on the `plugins_loaded` hook at priority 15. This is early enough to register the AJAX handlers before WordPress processes any AJAX requests.

## WordPress Hook Order

Understanding the WordPress hook order is crucial to why this fix works:

1. `plugins_loaded` (priority 15) - Pro addon initializes
   - `wp_mcp_ai_pro_init()` is called
   - `wp_mcp_ai_pro_load_admin_sections()` loads and instantiates Performance section
   - AJAX handlers are registered ✓

2. `admin_menu` - Admin menu is registered

3. `admin_init` - Admin initialization

4. `admin_enqueue_scripts` - Scripts are enqueued for admin pages
   - Dashboard controller checks if we're on the performance_monitoring page
   - If yes, enqueues the JavaScript ✓

5. Page rendering begins
   - Performance section's `render()` method is called
   - HTML output is generated

6. AJAX requests (when buttons are clicked)
   - WordPress looks for registered `wp_ajax_*` handlers
   - Finds our handlers because they were registered in step 1 ✓

## Key Takeaways

1. **Don't register hooks in constructors that are called late** - If a class is instantiated during rendering, its constructor runs too late to register hooks that need to fire earlier in the request lifecycle.

2. **Separate concerns** - Asset enqueuing is better handled by a central controller that knows about the page context, rather than individual sections.

3. **Understand hook order** - WordPress has a specific order for firing hooks. Make sure your code runs at the right time.

4. **AJAX handlers must be registered early** - AJAX handlers need to be registered on the `plugins_loaded` or `init` hooks, not during page rendering.

## Testing the Fix

Run the verification script:
```bash
./verify-performance-buttons-fix.sh
```

Or manually test:
1. Navigate to: `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=performance_monitoring`
2. Open browser console (F12)
3. Click any performance test button
4. Verify:
   - Button shows "Running..." text
   - AJAX request appears in Network tab
   - Test results display below buttons
   - No JavaScript errors

## Files Modified

1. `includes/admin/class-wp-mcp-ai-settings-dashboard.php` - Added JavaScript enqueue logic
2. `addons/pro/mcp-ai-wpoos-pro.php` - Instantiate Performance section early
3. `tests/test-performance-buttons-fix.php` - Test suite
4. `verify-performance-buttons-fix.sh` - Verification script
