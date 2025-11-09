# PHP Version Compatibility - Defensive Programming

## Overview

This document explains the defensive PHP version checking strategy implemented in the WP Open Operator System (WP oOS) plugin to prevent parse errors on servers running PHP versions older than 7.4.

## Problem Statement

While the plugin declares `Requires PHP: 7.4` in its header and implements a main version check in `wp-mcp-ai.php`, there are edge cases where individual class files could be loaded by external systems (like WooCommerce's error logging mechanism) before the main plugin initialization runs.

If these files contain PHP 7.4+ syntax features and are loaded on PHP < 7.4, the PHP parser will fail with errors like:
- "Parse error: syntax error, unexpected token 'private'"
- "Parse error: syntax error, unexpected token 'protected'"

## Solution: Defensive Version Guards

### Implementation

Each file that could potentially be loaded independently includes a defensive version check immediately after the `ABSPATH` guard:

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
    return;
}
```

### Why This Works

1. **Early Exit**: The version check happens before any class definitions or modern PHP syntax is encountered
2. **No Parse Errors**: Since `version_compare()` and the `if` statement are compatible with PHP 5.6+, the file can be parsed and executed
3. **Graceful Degradation**: The file simply returns without defining its classes, preventing fatal errors

### Files Protected

The following WooCommerce integration files have defensive version guards:

- `includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php`
- `includes/tools/class-wp-mcp-ai-tool-create-woo-product.php`
- `includes/tools/class-wp-mcp-ai-tool-get-woo-products.php`
- `includes/admin/class-wp-mcp-ai-admin-woocommerce.php`
- `includes/admin/sections/class-wp-mcp-ai-section-woocommerce.php`

## When to Add Version Guards

Add defensive version guards to files that:

1. **Could be loaded by third-party plugins** (e.g., WooCommerce, JetEngine)
2. **Contain integration code** with external systems
3. **Are referenced in error logs** or debugging output
4. **Use PHP 7.4+ features** like:
   - Typed properties (`private string $property`)
   - Arrow functions (`fn() => $value`)
   - Null coalescing assignment operator (`$var ??= 'default'`)
   - Union types (PHP 8.0+: `string|int $param`)

## Testing

To verify version guards are working:

```bash
# Check all WooCommerce files have version guards
grep -l "version_compare.*PHP_VERSION.*7.4" includes/tools/*woo*.php includes/admin/*woo*.php
```

Expected output should list all 5 WooCommerce-related files.

## Best Practices

1. **Main Plugin Check**: The primary version check in `wp-mcp-ai.php` (lines 33-61) is the first line of defense
2. **Defensive Guards**: Individual file checks are a safety net for edge cases
3. **Consistent Placement**: Always place version guards:
   - After `ABSPATH` check
   - Before any `require_once` statements
   - Before any class definitions
4. **Clear Comments**: Include explanatory comments to help future maintainers understand why the check exists

## Related Documentation

- Main plugin PHP version check: `wp-mcp-ai.php` lines 25-61
- Plugin requirements: `readme.txt` and plugin header
- Deployment troubleshooting: `docs/deployment-troubleshooting.md`

## Version History

- **2025-11-09**: Added defensive version guards to WooCommerce integration files
- **2025-10-23**: Initial plugin release with main version check

## References

- [PHP version_compare() documentation](https://www.php.net/manual/en/function.version-compare.php)
- [WordPress plugin header requirements](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/)
- Issue: "Older syntax errors in WooCommerce fatal log tied to the wpos plugin files"
