# Production Setup

This repository is configured for production use with optimized Composer autoloading.

## Production-Ready Configuration

The vendor directory is included in the repository with production-only dependencies and optimized autoloading. This means the plugin can be cloned directly and used without running `composer install`.

### Composer Configuration

The repository uses:
- **`--no-dev`**: Only production dependencies are included (no PHPUnit, PHPCS, etc.)
- **`--classmap-authoritative`**: Optimized autoloader with authoritative classmap for maximum performance

### Benefits

1. **No Composer Required**: Clone and use immediately - no need to run `composer install`
2. **Optimized Performance**: Classmap authoritative mode eliminates filesystem checks
3. **Smaller Size**: Only production dependencies included (~5.9MB vendor directory)
4. **Production Ready**: Configuration matches production deployment requirements

### Vendor Directory

The vendor directory includes:
- Production dependencies only (28 packages)
- Optimized classmap autoloader (685 class mappings)
- All required dependencies for WordPress plugin operation

### Production Dependencies

Core dependencies included:
- `symfony/http-client` - HTTP client for API communication
- `symfony/cache` - Caching layer
- `symfony/validator` - Input validation
- `symfony/filesystem` - File operations
- `symfony/process` - Process management
- `league/oauth2-client` - OAuth authentication
- `guzzlehttp/guzzle` - HTTP client library
- `rahul900day/tiktoken-php` - Token counting

## For Developers

If you need to make changes to dependencies or run tests:

### Install Development Dependencies

```bash
composer install
```

This will install:
- PHPUnit and test dependencies
- PHP CodeSniffer and WordPress coding standards
- Other development tools

### Return to Production Configuration

After development, regenerate the production autoloader:

```bash
composer install --no-dev --classmap-authoritative
```

Then commit the updated vendor files.

## Deployment

For WordPress.org or manual deployment:
1. Clone the repository
2. Plugin is ready to use immediately
3. No build steps required (vendor is pre-optimized)

For deployment pipelines that prefer to exclude vendor:
1. Add vendor to .gitignore
2. Run `composer install --no-dev --classmap-authoritative` in deployment pipeline
3. Package the plugin with optimized vendor directory

## .gitignore Configuration

The `.gitignore` is carefully configured to:
- Include production vendor packages
- Exclude dev dependencies
- Exclude test files and documentation from vendor packages
- Keep essential composer files (autoload.php, installed.php, etc.)

This ensures the repository contains exactly what's needed for production use while remaining reasonably sized.

## Verification

To verify the production configuration:

```bash
# Check for authoritative classmap
grep "setClassMapAuthoritative" vendor/composer/autoload_real.php
# Should output: $loader->setClassMapAuthoritative(true);

# Verify no dev dependencies
composer show --no-dev
# Should only show production packages

# Check vendor size
du -sh vendor/
# Should be around 5-6MB
```

## Support

For questions about the production setup, see:
- [Contributing Guide](../CONTRIBUTING.md)
- [Issue Tracker](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
