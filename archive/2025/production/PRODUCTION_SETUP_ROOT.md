# Production Composer Setup

This document explains the production-optimized composer setup for the NV oOS WordPress plugin.

## What Was Done

The repository has been configured with production-optimized composer dependencies by running:

```bash
composer install --no-dev --classmap-authoritative
```

## Flags Explained

### `--no-dev`
Excludes development dependencies defined in `require-dev` section of `composer.json`.

**Excluded Dev Dependencies:**
- PHPUnit and testing frameworks
- PHP_CodeSniffer and coding standards
- PHP compatibility checkers
- WordPress stubs
- Other development tools

**Result:** Smaller package size and no dev tools in production.

### `--classmap-authoritative`
Generates an authoritative classmap for maximum autoloader performance.

**How It Works:**
1. Composer scans all classes in all packages
2. Creates a complete class → file mapping (classmap)
3. Configures autoloader to ONLY use the classmap
4. Disables fallback file existence checks

**Performance Benefit:** 
- No filesystem lookups during autoloading
- Faster class loading (especially with many classes)
- Ideal for production environments

## Current State

### Production Dependencies (28 packages)

**HTTP & API Clients:**
- guzzlehttp/guzzle - HTTP client
- guzzlehttp/promises - Promise implementation
- guzzlehttp/psr7 - PSR-7 HTTP messages
- league/oauth2-client - OAuth 2.0 client
- nyholm/psr7 - PSR-7 implementation

**Symfony Components:**
- symfony/cache - Caching system
- symfony/filesystem - File operations
- symfony/http-client - HTTP client
- symfony/process - Process execution
- symfony/validator - Data validation

**PSR Interfaces:**
- psr/cache - Caching interface
- psr/container - Dependency injection
- psr/http-client - HTTP client interface
- psr/http-factory - HTTP factory interface
- psr/http-message - HTTP message interface
- psr/log - Logging interface

**Other:**
- rahul900day/tiktoken-php - Token counting
- ralouphie/getallheaders - Headers polyfill
- php-http/discovery - PSR implementation discovery

### Autoloader Configuration

**File:** `vendor/composer/autoload_real.php`

```php
$loader->setClassMapAuthoritative(true);
```

This ensures the autoloader uses ONLY the generated classmap, with no fallback file checks.

### Classmap Statistics

- **Total Classes:** 685
- **Location:** `vendor/composer/autoload_classmap.php`
- **Mode:** Authoritative (no filesystem fallbacks)

## Verification

To verify the production setup:

```bash
# Check installed packages (production only)
composer show --no-dev

# Verify autoloader works
php -r "require 'vendor/autoload.php'; echo 'OK';"

# Check for dev packages (should be empty)
ls vendor/ | grep -E "(phpunit|phpcs)"
```

## Cloning as Production Plugin

This repository can now be cloned and used as a production WordPress plugin:

```bash
# Clone the repository
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git

# Navigate to plugin directory
cd mcp-ai-wpoos

# That's it! No composer install needed.
# The vendor directory is included with optimized production dependencies.
```

## Benefits

### 1. Performance
- ✅ Faster autoloading (no file existence checks)
- ✅ Optimized classmap
- ✅ Production-ready configuration

### 2. Size
- ✅ Smaller package (no dev dependencies)
- ✅ ~155 lines removed from installed.json
- ✅ No PHPUnit, PHPCS, or other dev tools

### 3. Security
- ✅ No development tools exposed
- ✅ Only production code included
- ✅ Reduced attack surface

### 4. Deployment
- ✅ Ready to use immediately after clone
- ✅ No post-clone composer install required
- ✅ Works in environments without composer

## Development vs Production

### For Development

To restore dev dependencies for development:

```bash
# Install all dependencies (including dev)
composer install

# Run tests
composer test

# Run linting
composer lint
```

### For Production

The current state is already production-optimized. To re-optimize after development:

```bash
# Remove dev dependencies and optimize
composer install --no-dev --classmap-authoritative
```

## .gitignore Configuration

The `.gitignore` is already configured to include production vendor files:

```gitignore
# Ignore all vendor by default
/vendor/*

# But keep production dependencies only (negation)
!/vendor/autoload.php
!/vendor/composer/
!/vendor/guzzlehttp/
!/vendor/league/
!/vendor/nyholm/
!/vendor/php-http/
!/vendor/psr/
!/vendor/rahul900day/
!/vendor/ralouphie/
!/vendor/symfony/
```

This ensures only production dependencies are tracked in git.

## Maintenance

### Updating Dependencies

```bash
# Update composer.lock (with dev dependencies for testing)
composer update

# Run tests to verify updates
composer test

# Re-optimize for production
composer install --no-dev --classmap-authoritative

# Commit changes
git add composer.lock vendor/
git commit -m "Update dependencies"
```

### Adding New Dependencies

1. Add to `composer.json` (in `require` section, NOT `require-dev`)
2. Run `composer update package/name`
3. Test the new dependency
4. Run `composer install --no-dev --classmap-authoritative`
5. Commit changes

## Troubleshooting

### "Class not found" errors

If you get class not found errors after cloning:

```bash
# Regenerate autoloader
composer dump-autoload --classmap-authoritative
```

### Dev dependencies needed

If you need dev dependencies:

```bash
# Install all dependencies
composer install

# This will add PHPUnit, PHPCS, etc.
```

### Performance issues

The authoritative classmap should provide optimal performance. If you experience issues:

```bash
# Regenerate classmap
composer dump-autoload --classmap-authoritative --apcu
```

The `--apcu` flag enables APCu caching for even better performance (requires APCu extension).

## Related Files

- `composer.json` - Dependency definitions
- `composer.lock` - Locked dependency versions
- `vendor/autoload.php` - Main autoloader
- `vendor/composer/autoload_classmap.php` - Generated classmap
- `vendor/composer/autoload_real.php` - Autoloader configuration
- `vendor/composer/installed.json` - Installed package metadata

## Summary

✅ Repository is production-ready  
✅ Composer dependencies optimized  
✅ Authoritative classmap enabled  
✅ Dev dependencies excluded  
✅ Can be cloned and used immediately  

The plugin is now ready for production deployment without requiring `composer install` after cloning.
