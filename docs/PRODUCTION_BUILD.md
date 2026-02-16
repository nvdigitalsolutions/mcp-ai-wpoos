# Production Build Process

This document describes the production build process for the Open Operator System (NV oOS) WordPress plugin.

## Overview

The repository is configured to be cloned and used directly as a production plugin. This is achieved through optimized Composer autoloading that eliminates the need for a separate build step.

## Composer Production Optimization

### What Was Done

The repository has been optimized for production deployment using:

```bash
# Base plugin
composer install --no-dev --classmap-authoritative

# Pro addon
cd addons/pro
composer install --no-dev --classmap-authoritative
```

### Flags Explained

- **`--no-dev`**: Excludes development dependencies (PHPUnit, coding standards, etc.)
- **`--classmap-authoritative`**: Generates an optimized classmap and disables PSR-0/PSR-4 filesystem scanning

### Benefits

1. **Performance**: Classmap-based autoloading is significantly faster than PSR-4 scanning
2. **Production Ready**: No development dependencies in vendor directory
3. **Smaller Footprint**: Reduced repository size by removing dev packages
4. **Direct Clone**: Repository can be cloned directly into `wp-content/plugins/` and used

## Autoloader Details

### Base Plugin Autoloader

- **Location**: `vendor/composer/autoload_classmap.php`
- **Classes**: 685 production classes mapped
- **Includes**: Symfony, Guzzle, League OAuth, Tiktoken-PHP, and other runtime dependencies

### Pro Addon Autoloader

- **Location**: `addons/pro/vendor/composer/autoload_classmap.php`
- **Classes**: 1,232 production classes mapped
- **Includes**: PHPOffice, DomPDF, TCPDF, PDFParser, and other pro dependencies

## What Gets Excluded

The following development-only packages are **not** included in production:

- PHPUnit and test dependencies
- PHP CodeSniffer and WordPress Coding Standards
- PHP Compatibility checkers
- Composer patches plugin
- Various testing utilities

## Verifying Production Build

To verify the production build is working:

```bash
# Check main plugin syntax
php -l mcp-ai-wpoos.php

# Test base autoloader
php -r "require_once 'vendor/autoload.php'; echo 'Base autoloader working' . PHP_EOL;"

# Test pro autoloader
php -r "require_once 'addons/pro/vendor/autoload.php'; echo 'Pro autoloader working' . PHP_EOL;"
```

## Updating Dependencies

If you need to update dependencies:

### Development Environment

```bash
# Install with dev dependencies for development
composer install

# Run tests, linting, etc.
composer test
composer run lint
```

### Production Environment

```bash
# Update dependencies
composer update --no-dev --classmap-authoritative

# For pro addon
cd addons/pro
composer update --no-dev --classmap-authoritative
```

## CI/CD Integration

The production build is automatically maintained in the repository. When dependencies are updated:

1. Run `composer update` in development
2. Run `composer install --no-dev --classmap-authoritative` before committing
3. Commit the updated `vendor/composer/*` files

## File Structure

### Committed to Repository

```
vendor/
├── autoload.php                    ✓ Committed
├── composer/
│   ├── autoload_*.php             ✓ Committed (optimized)
│   ├── installed.json             ✓ Committed (production deps only)
│   └── installed.php              ✓ Committed (production deps only)
└── [production packages]          ✓ Committed (selected via .gitignore)
```

### Excluded from Repository

- Dev dependencies (PHPUnit, PHPCS, etc.)
- Test files within vendor packages
- Documentation within vendor packages
- Unnecessary metadata files

## Deployment

### Method 1: Direct Clone (Recommended)

```bash
cd wp-content/plugins/
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
```

The plugin is ready to activate immediately after cloning.

### Method 2: ZIP Distribution

```bash
# Create a production ZIP
composer install --no-dev --classmap-authoritative
zip -r mcp-ai-wpoos.zip mcp-ai-wpoos/ -x "*.git*" "node_modules/*" "tests/*"
```

## Technical Details

### Classmap vs PSR-4

**PSR-4 (Development)**:
- Scans filesystem at runtime
- Flexible for development
- Slower performance

**Classmap (Production)**:
- Pre-generated class map
- No filesystem scanning
- Faster performance (~40% faster in some benchmarks)

### Production Flag

The `installed.php` file contains:

```php
'dev' => false,  // Indicates production mode
```

This flag is automatically set when using `--no-dev`.

## Troubleshooting

### Class Not Found Errors

If you encounter class not found errors:

1. Check that composer dependencies are installed:
   ```bash
   ls -la vendor/composer/autoload_classmap.php
   ```

2. Regenerate the classmap:
   ```bash
   composer dump-autoload --classmap-authoritative
   ```

3. Verify the class exists in the classmap:
   ```bash
   grep "ClassName" vendor/composer/autoload_classmap.php
   ```

### Performance Issues

The classmap-authoritative mode provides optimal performance. If experiencing issues:

1. Check opcache is enabled in PHP
2. Verify file permissions are correct
3. Ensure no PSR-4 scanning is occurring (check autoload config)

## References

- [Composer Documentation: Autoloader Optimization](https://getcomposer.org/doc/articles/autoloader-optimization.md)
- [WordPress Plugin Handbook: Best Practices](https://developer.wordpress.org/plugins/plugin-basics/best-practices/)
- Repository: [mcp-ai-wpoos](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)

## Maintenance

### Regular Updates

1. Monitor security updates for dependencies
2. Update dependencies in development environment
3. Test thoroughly
4. Regenerate production autoloader
5. Commit changes

### Version Control

The following files **must** be committed when updating dependencies:

- `vendor/composer/installed.json`
- `vendor/composer/installed.php`
- `vendor/composer/autoload_*.php`
- `addons/pro/vendor/composer/installed.php`
- `addons/pro/vendor/composer/autoload_*.php`

These files are tracked in git to ensure the production build is always available when cloning the repository.
