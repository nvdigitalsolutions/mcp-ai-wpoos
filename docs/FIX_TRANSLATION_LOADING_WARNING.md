# Fix for Early Translation Loading Warning in Cloned Repository

## Problem Statement

When using a cloned repository (base + Pro addon combined), WordPress 6.7+ displays this warning:

```
Notice: Function _load_textdomain_just_in_time was called incorrectly. 
Translation loading for the mcp-ai-wpoos domain was triggered too early. 
This is usually an indicator for some code in the plugin or theme running too early. 
Translations should be loaded at the init action or later.
```

## Root Cause

The Pro addon uses the text domain `'mcp-ai-wpoos-pro'` in its custom post type registrations (Projects, Tasks, Events, Places). When the Pro addon is bundled with the base plugin (as in a cloned repository), it doesn't have a WordPress plugin header, so WordPress 6.7+ cannot automatically load translations via just-in-time loading.

The CPT registration happens on the `init` hook, and when translation functions like `__()` are called without the text domain being loaded first, WordPress triggers this warning.

## Solution Overview

We added a function to manually load the Pro addon's text domain on the `init` hook at priority 1 (before CPT registration at priority 10). This ensures translations are loaded at the correct time according to WordPress 6.7+ requirements.

## Implementation Details

### 1. Text Domain Loading Function

Added `wp_mcp_ai_pro_load_textdomain()` in `addons/pro/mcp-ai-wpoos-pro.php`:

```php
function wp_mcp_ai_pro_load_textdomain() {
    // Only load if Pro is bundled (no plugin header)
    // Check active plugins without requiring admin context
    $active_plugins = (array) get_option( 'active_plugins', array() );
    $pro_plugin     = plugin_basename( WP_MCP_AI_PRO_FILE );
    $is_pro_active  = in_array( $pro_plugin, $active_plugins, true );
    
    // For multisite, also check network active plugins
    if ( is_multisite() ) {
        $network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
        $is_pro_active  = $is_pro_active || isset( $network_active[ $pro_plugin ] );
    }
    
    if ( ! $is_pro_active ) {
        // Pro is bundled - load text domain manually
        $languages_dir = wp_normalize_path( WP_MCP_AI_PRO_PATH . 'languages' );
        $plugin_dir    = wp_normalize_path( WP_PLUGIN_DIR );
        $relative_path = str_replace( trailingslashit( $plugin_dir ), '', $languages_dir );
        
        load_plugin_textdomain(
            'mcp-ai-wpoos-pro',
            false,
            $relative_path
        );
    }
}
```

### 2. Hook Registration

The function is registered on the `init` hook at priority 1:

```php
add_action( 'init', 'wp_mcp_ai_pro_load_textdomain', 1 );
```

This ensures it runs BEFORE CPT registration (which uses default priority 10).

### 3. Languages Directory

Created `addons/pro/languages/` directory to hold translation files.

## Key Features

✅ **Context-Independent**: Works in admin, frontend, and AJAX contexts
✅ **Multisite Compatible**: Handles both single-site and network installations
✅ **Cross-Platform**: Uses `wp_normalize_path()` for Windows/Linux/Mac compatibility
✅ **Smart Detection**: Only loads when Pro is bundled, not when installed separately
✅ **Priority Ordering**: Ensures text domain loads before CPT registration
✅ **Zero Impact**: No changes to existing functionality or translations

## Testing & Verification

### Automated Tests
- PHPUnit test suite validates all aspects of the fix
- Verification script confirms proper implementation

### Manual Verification

Run the verification script:
```bash
bash bin/verify-pro-textdomain-fix.sh
```

Expected output:
```
✅ All checks passed!

The fix ensures that:
  1. Pro addon text domain loads on 'init' at priority 1
  2. CPT registration happens on 'init' at default priority 10
  3. Text domain loading occurs BEFORE CPT registration
  4. This prevents WordPress 6.7+ early translation warnings
```

## Impact Assessment

### What Changes
- Translation loading timing for Pro addon when bundled

### What Doesn't Change
- Any existing functionality
- Translation strings
- Plugin behavior
- Performance
- Separate Pro addon installations (still use auto-loading)

## Deployment

**Safe to deploy immediately:**
- No database migrations
- No configuration changes
- Backward compatible
- No user action required

## Files Modified

1. `addons/pro/mcp-ai-wpoos-pro.php` - Added text domain loading
2. `addons/pro/languages/.gitkeep` - Created languages directory
3. `tests/test-pro-textdomain-loading.php` - Added test coverage
4. `bin/verify-pro-textdomain-fix.sh` - Added verification script

## Result

After this fix, the WordPress 6.7+ warning will no longer appear when using a cloned repository (base + Pro combined). The warning was just a notice about timing, not an actual error, but this fix ensures best practices compliance.
