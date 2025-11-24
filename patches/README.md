# Composer Patches

This directory contains patches applied to vendor packages via the `cweagans/composer-patches` plugin.

## wp-phpunit-wp-lang-dir-guard.patch

**Package:** `wp-phpunit/wp-phpunit`  
**Issue:** Fixes "Constant WP_LANG_DIR already defined" warning during plugin activation and performance tests

### Background

The wp-phpunit package's `includes/bootstrap.php` unconditionally defines the `WP_LANG_DIR` constant without checking if it's already defined. This causes PHP warnings in scenarios where:

1. WordPress core has already loaded and defined `WP_LANG_DIR` in `wp-includes/default-constants.php`
2. Performance tests run via the WP oOS admin interface, which executes PHPUnit in an already-bootstrapped WordPress environment
3. The plugin is activated via WP-CLI during provisioning/startup scripts

### Solution

This patch adds a guard check (`if ( ! defined( 'WP_LANG_DIR' ) )`) before defining the constant, preventing the duplicate definition warning while maintaining backward compatibility.

### Application

The patch is automatically applied when running:
```bash
composer install
composer update
```

The patch is defined in `composer.json` under `extra.patches`.

### Upstream

This fix should ideally be submitted upstream to the wp-phpunit project. However, until merged and released, we maintain this local patch for compatibility.
