# Pro Tools Loading Fix

## Problem
Pro tools were not appearing in the settings sections when the combined plugin was used without any flags set in wp-config.php.

## Root Cause
The Pro addon's tools failed to register due to an action hook timing issue:

1. `includes/tools-init.php` was loaded early in mcp-ai-wpoos.php (line 450)
2. This file registered the Tool Registry initialization at `plugins_loaded:20`
3. The Pro addon was loaded later during `wp_mcp_ai_bootstrap()` (also at `plugins_loaded:20`)
4. By the time the Pro addon tried to hook into `wp_mcp_ai_register_tools`, the action had already fired

**Execution Flow (BEFORE FIX):**
```
plugins_loaded:20 fires
  ↓
Tool Registry init() runs (first because registered earlier)
  ↓
do_action('wp_mcp_ai_register_tools') fires
  ↓
wp_mcp_ai_bootstrap() runs
  ↓
wp_mcp_ai_maybe_load_pro_addon() loads Pro addon
  ↓
Pro addon tries to add_action('wp_mcp_ai_register_tools') ❌ TOO LATE!
```

## Solution
Load the Pro addon BEFORE `tools-init.php` so its hooks are registered before the action fires.

**Execution Flow (AFTER FIX):**
```
mcp-ai-wpoos.php loads (during initial file loading)
  ↓
Tool Registry class loaded (line 447)
  ↓
Pro addon loaded (lines 454-459) ✅
  ↓
Pro addon init() runs immediately
  ↓
add_action('wp_mcp_ai_register_tools', ...) registered ✅
  ↓
tools-init.php loaded (line 461)
  ↓
Tool Registry init registered at plugins_loaded:20
  ↓
... later ...
  ↓
plugins_loaded:20 fires
  ↓
Tool Registry init() runs
  ↓
do_action('wp_mcp_ai_register_tools') fires
  ↓
Pro addon's hook runs ✅
  ↓
Pro tools registered ✅
```

## Changes Made

### 1. mcp-ai-wpoos.php (lines 451-459)
Added Pro addon loading between Tool Registry and tools-init.php:

```php
require_once WP_MCP_AI_PATH . 'includes/class-tool-registry.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcodes.php';

// Load Pro addon early so it can register tool hooks before tool registry initializes.
if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
	$pro_addon_file = WP_MCP_AI_PATH . 'addons/pro/wp-mcp-ai-pro.php';
	if ( file_exists( $pro_addon_file ) ) {
		require_once $pro_addon_file;
	}
}

require_once WP_MCP_AI_PATH . 'includes/tools-init.php';
```

### 2. addons/pro/wp-mcp-ai-pro.php (lines 412-423)
Added smart initialization to handle both inline loading (combined plugin) and separate plugin loading:

```php
// Initialize Pro addon.
if ( did_action( 'plugins_loaded' ) || doing_action( 'plugins_loaded' ) ) {
	// Already in plugins_loaded or after - init immediately (combined plugin scenario).
	wp_mcp_ai_pro_init();
} else {
	// Not yet at plugins_loaded - schedule for later (separate plugin scenario).
	add_action( 'plugins_loaded', 'wp_mcp_ai_pro_init', 15 );
}
```

### 3. mcp-ai-wpoos.php wp_mcp_ai_bootstrap()
Updated comment to document that Pro addon is now loaded earlier:

```php
// Note: Pro addon is now loaded earlier (before tools-init.php) to ensure
// Pro tools can register hooks before tool registry initialization.
```

## Verification

### Automated Verification
Run the verification script:

```bash
bin/verify-pro-tools-fix.sh
```

Expected output:
```
✓ Pro addon file exists
✓ Correct loading order: Tool Registry → Pro Addon → Tools Init
✓ Pro addon has smart initialization
✓ Found 6 Pro tools
✓ Pro addon registers tool group map filter
```

### Manual Verification
1. Check that no flags are set in wp-config.php (WP_MCP_AI_BASE_VERSION undefined or false)
2. Load WordPress admin and go to WP oOS → Settings → Tools
3. Verify Pro tools appear in the settings sections:
   - Product Actualization
   - Lookup Product Price
   - WooCommerce Products
   - WooCommerce Orders
   - JetEngine Tools
   - Elementor Tools

### PHPUnit Tests
Run the test suite:

```bash
vendor/bin/phpunit tests/test-pro-tools-loading.php
```

## Pro Tools Included

All 6 Pro tools are now properly loaded when the combined plugin is used:

| Tool Slug | Group | Requires |
|-----------|-------|----------|
| `product_actualization` | external-tools | imagick or GD extension |
| `lookup_product_price` | external-tools | Crawl4AI |
| `woo_products` | wordpress-plugins | WooCommerce |
| `woo_orders` | wordpress-plugins | WooCommerce |
| `jetengine` | wordpress-plugins | JetEngine |
| `elementor` | wordpress-plugins | Elementor |

**Note:** Individual Pro tools will only register if their dependencies are available. This is expected behavior and not a bug.

## Impact

- ✅ Pro tools now show in settings sections without requiring wp-config.php flags
- ✅ Pro tools appear in tool registry for assistants
- ✅ Pro tools can be used by AI assistants
- ✅ Full version mode works as expected (all tools loaded by default)
- ✅ Backward compatible with separate Pro plugin installation

## Testing Checklist

- [x] Pro addon loads in combined plugin
- [x] Pro tools register when dependencies are met
- [x] Pro tools appear in tool group map
- [x] Pro tools show in settings UI
- [x] No wp-config.php flags needed
- [x] Separate Pro plugin still works
- [x] Base version mode still works (when flag is set)

## Related Files

- `mcp-ai-wpoos.php` - Main plugin file with Pro addon loading
- `addons/pro/wp-mcp-ai-pro.php` - Pro addon with smart initialization
- `includes/tools-init.php` - Tool registry initialization
- `includes/class-wp-mcp-ai-tool-registry.php` - Tool registry class
- `tests/test-pro-tools-loading.php` - PHPUnit tests
- `bin/verify-pro-tools-fix.sh` - Verification script
