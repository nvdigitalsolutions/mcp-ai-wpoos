# Production Composer Setup

## Overview

This repository is configured as a production-ready WordPress plugin that can be cloned and used immediately without requiring `composer install`. The vendor directory contains optimized production dependencies.

## Production Configuration

### Composer Commands Used

```bash
composer install --no-dev --classmap-authoritative
```

### What This Does

1. **`--no-dev`**: Installs only production dependencies from the `require` section, excluding development dependencies from `require-dev` (like PHPUnit, PHPCS, etc.)

2. **`--classmap-authoritative`**: Generates an optimized classmap for all classes. The autoloader will only use the classmap and never scan the filesystem for missing classes. This provides:
   - Faster class loading in production
   - Reduced filesystem I/O
   - Better performance for WordPress plugins

### Benefits

✅ **Clone and Use**: Users can clone the repository and activate it as a WordPress plugin immediately  
✅ **No Build Step**: No need to run `composer install` after cloning  
✅ **Production Performance**: Optimized autoloader with 676+ classes in classmap  
✅ **Smaller Size**: Only production dependencies included, dev tools excluded  

## Development vs Production

### For Development

If you're developing the plugin, you'll want the dev dependencies:

```bash
# Install with dev dependencies
composer install

# This includes:
# - PHPUnit for testing
# - PHPCS for code standards
# - PHPCompatibility for compatibility checks
```

### For Production/Distribution

The repository is already configured for production. If you need to regenerate:

```bash
# Clean and reinstall for production
rm -rf vendor/
composer install --no-dev --classmap-authoritative
```

## .gitignore Configuration

The `.gitignore` file is configured to:
- Commit vendor directory with production dependencies
- Exclude dev dependency packages
- Include only necessary production files
- Exclude test files from vendor packages

Key lines:
```gitignore
# Ignore all vendor by default
/vendor/*

# But keep production dependencies only (negation)
!/vendor/autoload.php
!/vendor/composer/
!/vendor/symfony/
!/vendor/guzzlehttp/
!/vendor/league/
# ... etc
```

## Verification

To verify the production setup is correct:

```bash
# Check that dev flag is false
grep "'dev' => false" vendor/composer/installed.php

# Check no dev dependencies are installed
grep "dev_requirement" vendor/composer/installed.json

# Test autoloader
php -r "require 'vendor/autoload.php'; echo 'OK' . PHP_EOL;"
```

## When to Regenerate

Regenerate the production composer setup when:

1. **Adding new dependencies**: After adding packages to `composer.json` require section
2. **Updating dependencies**: After running `composer update`
3. **Before releases**: To ensure production-ready state
4. **After branch merges**: If composer.json was modified

## Continuous Integration

For CI/CD pipelines, use the development install:

```yaml
# .github/workflows/test.yml
- name: Install dependencies
  run: composer install  # Includes dev dependencies for testing
```

For deployment/release pipelines:

```yaml
# .github/workflows/release.yml
- name: Install production dependencies
  run: composer install --no-dev --classmap-authoritative
```

## WordPress.org SVN Deployment

When deploying to WordPress.org, the vendor directory is included in the plugin ZIP. The production-optimized setup ensures:

- Minimal file size
- Fast autoloading
- No unnecessary development files
- Ready to use immediately after installation

## Troubleshooting

### "Class not found" errors

If you get class not found errors:

```bash
# Reinstall and regenerate autoloader
composer install --no-dev --classmap-authoritative
```

### Dev dependencies needed

If you need dev dependencies back:

```bash
# Install with dev dependencies
composer install
```

### Large vendor directory

The production vendor directory should be around 6-8 MB. If it's larger:

```bash
# Check for dev dependencies
ls -la vendor/ | grep -E "phpunit|phpcs|dealerdirect|cweagans"

# If found, reinstall
rm -rf vendor/
composer install --no-dev --classmap-authoritative
```

## Related Files

- `composer.json` - Defines production and dev dependencies
- `.gitignore` - Controls which vendor files are committed
- `vendor/composer/installed.php` - Shows installed packages and dev flag
- `vendor/composer/autoload_classmap.php` - Optimized classmap

## References

- [Composer Documentation: classmap-authoritative](https://getcomposer.org/doc/03-cli.md#install-i)
- [WordPress Plugin Handbook: Using Composer](https://developer.wordpress.org/plugins/plugin-basics/including-a-composer-dependency/)
