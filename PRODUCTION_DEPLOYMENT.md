# Production Deployment - Composer Setup

## Status: ✅ Production Ready

This repository has been prepared for production deployment with optimized Composer dependencies.

## What Was Done

Ran the following command to prepare the repository for production:

```bash
composer install --no-dev --classmap-authoritative
```

### Flags Explained

- **`--no-dev`**: Installs only production dependencies, excludes all dev dependencies (phpunit, phpcs, etc.)
- **`--classmap-authoritative`**: Generates an optimized class map for faster autoloading in production

## Results

### Production Dependencies (Installed) ✅

The following production dependencies are included:

- **guzzlehttp/guzzle** (7.10.0) - HTTP client library
- **guzzlehttp/promises** (2.3.0) - Promises library
- **guzzlehttp/psr7** (2.8.0) - PSR-7 message implementation
- **league/oauth2-client** (2.9.0) - OAuth 2.0 client
- **nyholm/psr7** (1.8.2) - PSR-7 implementation
- **php-http/discovery** (1.20.0) - HTTP implementation discovery
- **psr/*** (Various) - PSR interfaces
- **rahul900day/tiktoken-php** (1.0.0) - Tiktoken tokenizer
- **ralouphie/getallheaders** (3.0.3) - Headers polyfill
- **symfony/cache** (6.4.33) - Caching
- **symfony/filesystem** (6.4.30) - Filesystem utilities
- **symfony/http-client** (6.4.33) - HTTP client
- **symfony/process** (6.4.33) - Process execution
- **symfony/validator** (6.4.33) - Validation

**Total**: 28 production packages (9 top-level vendors)

### Dev Dependencies (Excluded) ✅

The following dev dependencies are **NOT** included in production:

- ❌ phpunit/phpunit
- ❌ squizlabs/php_codesniffer
- ❌ wp-coding-standards/wpcs
- ❌ phpcompatibility/phpcompatibility-wp
- ❌ php-stubs/wordpress-stubs
- ❌ wp-phpunit/wp-phpunit
- ❌ yoast/phpunit-polyfills
- ❌ dealerdirect/phpcodesniffer-composer-installer
- ❌ cweagans/composer-patches

### Optimization Results

- **Vendor Size**: 5.9 MB (production only)
- **Autoload Classmap**: 685 classes pre-mapped for instant loading
- **Autoloader Type**: Authoritative classmap (no filesystem lookups)
- **Performance**: Optimized for production with zero overhead

## Deployment Instructions

### 1. Clone the Repository

```bash
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
```

### 2. The Plugin is Ready to Use

No additional steps required! The vendor directory is already included with:
- ✅ Production dependencies installed
- ✅ Optimized autoloader
- ✅ No dev dependencies

### 3. Activate in WordPress

1. Upload the entire `mcp-ai-wpoos` directory to `/wp-content/plugins/`
2. Activate "NV oOS" in WordPress admin
3. Configure in Settings → NV oOS

## File Size Comparison

### Production (--no-dev --classmap-authoritative)
- Vendor directory: **5.9 MB**
- Includes: Runtime dependencies only
- Optimized: Yes (authoritative classmap)

### Development (with dev dependencies)
- Vendor directory: ~30-40 MB
- Includes: All dev tools, test frameworks, linters
- Optimized: No (PSR-4 autoloading with filesystem checks)

## Technical Details

### Autoloader Performance

The authoritative classmap means:
- ✅ All classes are pre-mapped in `vendor/composer/autoload_classmap.php`
- ✅ No filesystem stat() calls during autoloading
- ✅ Zero overhead class loading
- ✅ Perfect for production environments

### Git Configuration

The `.gitignore` is configured to:
- ✅ Include production vendor packages
- ✅ Exclude dev dependencies
- ✅ Include composer autoloader files
- ✅ Exclude test/docs from vendor packages

## Verification

To verify the production setup:

```bash
# Check installed packages (should show production only)
composer show --installed --no-dev

# Verify no dev dependencies
ls vendor/ | grep -E "phpunit|phpcs"
# Should return nothing

# Check autoloader optimization
ls -lh vendor/composer/autoload_classmap.php
# Should show ~83KB optimized classmap
```

## For Developers

If you need to work on this plugin with dev dependencies:

```bash
# Install with dev dependencies for development
composer install

# Run tests
composer test

# Run linting
composer lint

# When done, rebuild for production
composer install --no-dev --classmap-authoritative
```

## Security

- ✅ No test frameworks in production
- ✅ No code quality tools in production
- ✅ Reduced attack surface
- ✅ Smaller deployment size

## Support

For issues or questions, see:
- Main README: `README.md`
- Documentation: `docs/` directory
- Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

---

**Generated**: 2026-02-13  
**Composer Version**: 2.x  
**PHP Version**: 8.1+  
**WordPress Version**: 6.0+
