# Production Composer Setup Guide

## Overview

This repository is configured for production deployment with optimized Composer dependencies.

**Last Updated**: February 17, 2026  
**Status**: ✅ Production-Ready

## Current Configuration

The repository has been optimized with:
```bash
composer install --no-dev --classmap-authoritative --no-interaction
```

### What This Means

- **No Dev Dependencies**: Development tools (PHPUnit, PHPCS, etc.) are excluded from vendor/
- **Authoritative Classmap**: Optimized autoloader (685 classes) for faster class loading
- **Production Flag**: The `dev` flag in `installed.php` is set to `false`
- **Reduced Size**: 155 lines removed from composer metadata files

## Production Dependencies (18 Packages)

The following production packages are installed and committed:

### HTTP & Networking
- **guzzlehttp/guzzle** (7.10.0) - HTTP client library
- **guzzlehttp/promises** (2.3.0) - Promise library
- **guzzlehttp/psr7** (2.8.0) - PSR-7 HTTP message implementation
- **symfony/http-client** (6.4.33) - Symfony HTTP client

### OAuth & Authentication
- **league/oauth2-client** (2.9.0) - OAuth 2.0 client

### PSR Standards
- **nyholm/psr7** (1.8.2) - Fast PSR-7 implementation
- **psr/cache** (3.0.0) - Caching interface
- **psr/container** (2.0.2) - Container interface
- **psr/http-client** (1.0.3) - HTTP client interface
- **psr/http-factory** (1.1.0) - HTTP factory interface
- **psr/http-message** (2.0) - HTTP message interface
- **psr/log** (3.0.2) - Logging interface

### Symfony Components
- **symfony/cache** (6.4.33) - Caching component
- **symfony/filesystem** (6.4.30) - Filesystem utilities
- **symfony/process** (6.4.33) - Process execution
- **symfony/validator** (6.4.33) - Validation component
- **symfony/polyfills** (various) - PHP compatibility polyfills

### Utilities
- **rahul900day/tiktoken-php** (1.0.0) - Token counting for AI
- **ralouphie/getallheaders** (3.0.3) - HTTP header polyfill
- **php-http/discovery** (1.20.0) - HTTP client discovery

## Dev Dependencies Removed

The following packages are **NOT** included in production:

```
cweagans/composer-patches              - Composer patches plugin
cweagans/composer-configurable-plugin  - Configuration plugin
dealerdirect/phpcodesniffer-composer-installer - PHPCS installer
phpcompatibility/phpcompatibility-wp   - PHP compatibility checker
php-stubs/wordpress-stubs              - WordPress type stubs
squizlabs/php_codesniffer             - PHP CodeSniffer
wp-coding-standards/wpcs              - WordPress coding standards
phpunit/phpunit                       - Testing framework
wp-phpunit/wp-phpunit                 - WordPress test suite
yoast/phpunit-polyfills               - PHPUnit compatibility
```

## For Developers

### Cloning for Production

When you clone this repository, it's immediately ready for production use:

```bash
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
# No composer install needed - dependencies are committed!
```

### Local Development Setup

If you need to work on the code and run tests:

```bash
# Install dev dependencies for local development
composer install --dev

# This will add:
# - PHPUnit (testing framework)
# - PHP_CodeSniffer (linting)
# - WPCS (WordPress coding standards)
# - PHPCompatibility (PHP version compatibility checks)
# - WordPress test suite
```

### Re-optimizing for Production

After development, re-optimize for production before committing:

```bash
# Remove dev dependencies and optimize
composer install --no-dev --classmap-authoritative

# Commit the changes
git add vendor/composer/installed.json vendor/composer/installed.php
git commit -m "Re-optimize vendor for production"
```

## Autoloader Optimization

### Classmap Authoritative

The `--classmap-authoritative` flag provides:

- **Faster Class Loading**: Classes are loaded directly from the classmap (685 entries)
- **Production Performance**: Ideal for production where code doesn't change
- **No PSR-4 Fallback**: Once a class is not found in the classmap, it won't be searched for

### Performance Benefits

- ✅ Reduced filesystem I/O
- ✅ Faster initial page loads  
- ✅ Better opcache utilization
- ✅ Optimized for WordPress hosting environments
- ✅ ~155 lines removed from composer metadata

## Files Modified

```
vendor/composer/installed.json  (-131 lines)
vendor/composer/installed.php   (dev flag: false, -24 lines)
```

## Verification

Check if your setup is production-ready:

```bash
# Should show "dev: false"
grep -A 2 "'dev'" vendor/composer/installed.php

# Should only show production packages (no phpunit, phpcs, etc.)
composer show --no-dev

# Count packages
php -r "echo 'Production packages: ' . count(json_decode(file_get_contents('vendor/composer/installed.json'))->packages) . PHP_EOL;"
```

Expected output:
- dev flag: `false`
- Package count: 18 production packages
- No dev tools (phpunit, phpcs, etc.)

## CI/CD Integration

### GitHub Actions - Testing Pipeline

For CI/CD pipelines that need to run tests:

```yaml
# .github/workflows/test.yml
- name: Install dependencies with dev tools
  run: composer install --dev
  
- name: Run tests
  run: composer test
```

### GitHub Actions - Deployment Pipeline

For production deployments:

```yaml
# .github/workflows/deploy.yml
- name: Optimize for production
  run: composer install --no-dev --classmap-authoritative --no-interaction
  
- name: Deploy to production
  run: |
    git add vendor/composer/installed.json vendor/composer/installed.php
    git commit -m "Optimize vendor for production"
```

## Troubleshooting

### "Class not found" Errors

If you encounter class not found errors after pulling changes:

```bash
# Reinstall and regenerate the autoloader
composer install --no-dev --classmap-authoritative
```

### Mixing Dev and Production

**Important**: Don't mix development and production setups. Choose one:

- **Production**: `composer install --no-dev --classmap-authoritative`
- **Development**: `composer install --dev`

### Restoring Dev Dependencies

To switch back to development mode:

```bash
# Install dev dependencies
composer install --dev

# This will restore phpunit, phpcs, and other dev tools
```

## Best Practices

1. ✅ **Always commit vendor/ files** after running production optimization
2. ✅ **Use `--no-dev`** for all production deployments
3. ✅ **Use `--classmap-authoritative`** for production performance
4. ✅ **Test in production-like environment** before deploying
5. ✅ **Document the production optimization** in your deployment process
6. ❌ **Never commit dev dependencies** to production branches
7. ❌ **Don't run `composer update`** without reviewing changes first

## Git Tracking Strategy

The repository uses a selective vendor tracking strategy (see `.gitignore`):

### Tracked
- `vendor/autoload.php` - Main autoloader
- `vendor/composer/` - All composer files (classmap, metadata)
- Production dependencies (guzzlehttp, symfony, league, etc.)

### Ignored
- Dev-only packages
- Test directories
- Documentation files
- Temporary files

This approach allows:
- ✅ Immediate production use after cloning
- ✅ No composer install required for end users
- ✅ Consistent dependency versions
- ✅ Smaller repository size (no dev tools)

## Additional Resources

- [Composer Documentation: Autoloader Optimization](https://getcomposer.org/doc/articles/autoloader-optimization.md)
- [WordPress Plugin Best Practices](https://developer.wordpress.org/plugins/plugin-basics/best-practices/)
- Project Documentation: `docs/deployment/`

---

**Status**: ✅ Production-Ready  
**Last Optimized**: February 17, 2026  
**Composer Version**: 2.x  
**PHP Compatibility**: 7.4 - 8.3
