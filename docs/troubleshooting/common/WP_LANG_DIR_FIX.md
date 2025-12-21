# WP_LANG_DIR Constant Warning Fix

## Problem

During plugin activation and performance test execution, a PHP warning was occurring:

```
PHP Warning: Constant WP_LANG_DIR already defined in /path/to/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php on line 218
```

## Root Cause

The wp-phpunit package's `includes/bootstrap.php` file unconditionally defines the `WP_LANG_DIR` constant without checking if it's already defined:

```php
// Line 218 in wp-phpunit/wp-phpunit/includes/bootstrap.php
define( 'WP_LANG_DIR', realpath( DIR_TESTDATA . '/languages' ) );
```

This causes issues in scenarios where WordPress core has already loaded and defined `WP_LANG_DIR` in `wp-includes/default-constants.php`:

### Scenario 1: Plugin Activation
1. WP-CLI loads WordPress core
2. WordPress core defines `WP_LANG_DIR` in `wp-includes/default-constants.php`
3. Plugin is activated, loads `vendor/autoload.php`
4. Performance tests may be triggered
5. wp-phpunit bootstrap tries to define `WP_LANG_DIR` again → **WARNING**

### Scenario 2: Performance Tests via Admin UI
1. Admin user clicks "Run Performance Tests" in WP oOS admin
2. WordPress is already fully bootstrapped
3. The performance test runner (`includes/admin/sections/class-wp-mcp-ai-section-performance.php`) executes PHPUnit with `--no-configuration` on specific test files
4. Test files extend `WP_UnitTestCase`, requiring wp-phpunit bootstrap
5. wp-phpunit bootstrap tries to define `WP_LANG_DIR` again → **WARNING**

## Solution

Apply a Composer patch to wp-phpunit that adds a guard check before defining the constant:

```php
// Patched version
if ( ! defined( 'WP_LANG_DIR' ) ) {
    define( 'WP_LANG_DIR', realpath( DIR_TESTDATA . '/languages' ) );
}
```

### Implementation

1. **Composer Dependency**: Added `cweagans/composer-patches` to `require-dev`
2. **Patch File**: Created `patches/wp-phpunit-wp-lang-dir-guard.patch`
3. **Configuration**: Added patch configuration to `composer.json`:

```json
{
    "extra": {
        "patches": {
            "wp-phpunit/wp-phpunit": {
                "Add guard check for WP_LANG_DIR constant": "patches/wp-phpunit-wp-lang-dir-guard.patch"
            }
        }
    },
    "config": {
        "allow-plugins": {
            "cweagans/composer-patches": true
        }
    }
}
```

### Automatic Application

The patch is automatically applied when developers run:
- `composer install`
- `composer update`

## Benefits

1. **Eliminates Warning**: No more PHP warnings during plugin activation or performance tests
2. **Backward Compatible**: The guard check doesn't affect normal PHPUnit test execution
3. **Non-Invasive**: Minimal change to wp-phpunit (one `if` statement)
4. **Maintainable**: Clear documentation in `patches/README.md`
5. **Verifiable**: Includes test script (`bin/test-wp-lang-dir-patch.sh`) to verify logic

## Testing

Run the verification script:

```bash
bash bin/test-wp-lang-dir-patch.sh
```

Expected output:
```
=== WP_LANG_DIR Patch Verification Test ===

Step 1: WordPress core defined WP_LANG_DIR: /var/www/html/wp-content/languages
Step 2: wp-phpunit skipped defining WP_LANG_DIR (already defined) ✓

Result: WP_LANG_DIR = /var/www/html/wp-content/languages
✓ No warning emitted! The patch works correctly.
```

## Files Changed

- `composer.json` - Added composer-patches plugin and configuration
- `patches/wp-phpunit-wp-lang-dir-guard.patch` - The actual patch
- `patches/README.md` - Documentation about patches
- `BUILD.md` - Note about automatic patch application
- `CHANGELOG.md` - Entry documenting the fix
- `bin/test-wp-lang-dir-patch.sh` - Verification script
- `tests/wp-tests-config.php` - Reverted incorrect attempt (was adding WP_LANG_DIR definition)

## Upstream Consideration

This fix should ideally be submitted as a pull request to the wp-phpunit project upstream. However, until it's merged and released, we maintain this local patch for compatibility.

The change is minimal, non-breaking, and follows PHP best practices (always check before defining constants).

## Related Issues

- Performance test runner: `includes/admin/sections/class-wp-mcp-ai-section-performance.php`
- Test files that extend WP_UnitTestCase: `tests/performance/test-*.php`
- Plugin activation in codex-startup.sh: `bin/codex-startup.sh` (line 164)

## Date

November 24, 2025
