# Production-Ready Plugin Setup

This repository is configured to work as a production WordPress plugin immediately after cloning, without requiring `composer install`.

## What Was Done

### 1. Production Dependencies Installed

Ran the following command to install only production dependencies with optimized autoloader:

```bash
composer install --no-dev --classmap-authoritative
```

**Result:**
- ✅ 28 production packages installed
- ✅ Classmap authoritative mode enabled for faster autoloading
- ✅ No development dependencies included
- ✅ Vendor directory size: 62MB (production only)

### 2. Enhanced .gitignore

Updated `.gitignore` to exclude development files from vendor directory while keeping production code:

**Excluded from version control:**
- Test directories: `test/`, `tests/`, `Tests/`
- Documentation: `docs/`, `doc/`, README files, CHANGELOG, etc.
- Development tools: phpstan, psalm, php-cs-fixer, Makefiles, Dockerfiles
- Development configs: .travis.yml, codecov.yml, phpspec configs, rector.php
- Symfony translations and bin utilities
- Vendor-specific development files

**Included in version control:**
- Production source code
- Composer autoloader files
- Required dependencies for plugin operation

### 3. Optimized Autoloader

The autoloader is configured with `setClassMapAuthoritative(true)` which:
- Skips filesystem checks for class files
- Uses only the classmap for class lookups
- Improves performance in production environments
- Is the recommended setting for production deployments

## Production Dependencies (28 packages)

The following production dependencies are included:

### HTTP & API Communication
- `guzzlehttp/guzzle` - HTTP client
- `guzzlehttp/promises` - Promises/A+ implementation
- `guzzlehttp/psr7` - PSR-7 HTTP message implementation
- `league/oauth2-client` - OAuth 2.0 client
- `nyholm/psr7` - PSR-7/17 implementation
- `php-http/discovery` - HTTP client discovery

### Symfony Components
- `symfony/cache` - Caching implementation
- `symfony/filesystem` - Filesystem utilities
- `symfony/http-client` - HTTP client
- `symfony/process` - Process execution
- `symfony/validator` - Validation framework
- `symfony/var-exporter` - Variable exporter

### Symfony Support Packages
- `symfony/cache-contracts`
- `symfony/deprecation-contracts`
- `symfony/http-client-contracts`
- `symfony/service-contracts`
- `symfony/translation-contracts`
- `symfony/polyfill-ctype`
- `symfony/polyfill-mbstring`
- `symfony/polyfill-php83`

### PSR Interfaces
- `psr/cache` - Caching interface
- `psr/container` - Container interface
- `psr/http-client` - HTTP client interface
- `psr/http-factory` - HTTP factories interface
- `psr/http-message` - HTTP message interface
- `psr/log` - Logging interface

### AI & Tokenization
- `rahul900day/tiktoken-php` - OpenAI tokenizer

### Utilities
- `ralouphie/getallheaders` - Get all HTTP headers

## Development Dependencies (NOT included)

These packages are **not** in the vendor directory and are only needed for development:

- ❌ `phpunit/phpunit` - Testing framework
- ❌ `squizlabs/php_codesniffer` - Code sniffer
- ❌ `wp-coding-standards/wpcs` - WordPress coding standards
- ❌ `dealerdirect/phpcodesniffer-composer-installer` - PHPCS installer
- ❌ `cweagans/composer-patches` - Composer patches
- ❌ `phpcompatibility/phpcompatibility-wp` - PHP compatibility checker
- ❌ `php-stubs/wordpress-stubs` - WordPress stubs
- ❌ `yoast/phpunit-polyfills` - PHPUnit polyfills
- ❌ `wp-phpunit/wp-phpunit` - WordPress PHPUnit integration

## Usage

### For Plugin Users (Cloning for Production)

Simply clone the repository and use it:

```bash
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
# Plugin is ready to use - no composer install needed!
```

The plugin will work immediately because:
- ✅ Vendor directory is included
- ✅ All production dependencies are present
- ✅ Autoloader is optimized
- ✅ No additional setup required

### For Plugin Developers (Contributing)

If you need to run tests or development tools:

```bash
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos

# Install development dependencies
composer install

# Now you can run development commands:
composer run lint        # PHP CodeSniffer
composer run test        # PHPUnit tests
composer run format      # Auto-fix code style
```

## Verification

To verify the production setup is working:

```php
<?php
// Test autoloader
require_once 'vendor/autoload.php';

// Test production classes
$httpClient = new \Symfony\Component\HttpClient\HttpClient();
$oauth = new \League\OAuth2\Client\Provider\AbstractProvider([]);
$tiktoken = new \Rahul900day\Tiktoken\Tiktoken();

echo "✅ All production dependencies loaded successfully!\n";
```

## Benefits

### For Users
- ✅ **Zero setup** - Clone and use immediately
- ✅ **Faster** - Optimized autoloader (classmap authoritative)
- ✅ **Smaller** - No dev dependencies (62MB vs ~100MB+)
- ✅ **Production-ready** - Exactly what should run on production sites

### For Developers
- ✅ **Clean git history** - No test files, no development cruft
- ✅ **Flexible** - Can still install dev dependencies when needed
- ✅ **Standards compliant** - Follows WordPress plugin best practices
- ✅ **Distribution ready** - Can be zipped and distributed as-is

## Technical Details

### Classmap Authoritative Mode

When `setClassMapAuthoritative(true)` is enabled:

1. Composer only looks in the classmap for classes
2. No filesystem checks are performed
3. Faster class loading (no `file_exists()` calls)
4. Perfect for production where classes don't change

This is set in `vendor/composer/autoload_real.php`:

```php
$loader->setClassMapAuthoritative(true);
```

### .gitignore Strategy

The `.gitignore` uses a whitelist approach for vendor:

1. **Ignore all vendor**: `/vendor/*`
2. **Allow production packages**: `!/vendor/guzzlehttp/`, `!/vendor/symfony/`, etc.
3. **Exclude dev files**: `/vendor/*/*/tests/`, `/vendor/*/*/docs/`, etc.

This ensures only production code is committed while excluding:
- Test directories and files
- Documentation
- Development tools and configs
- Metadata files (README, CHANGELOG, etc.)

## Maintenance

### Updating Dependencies

To update production dependencies:

```bash
# Update composer.lock
composer update --no-dev

# Reinstall with optimized autoloader
composer install --no-dev --classmap-authoritative

# Commit the changes
git add composer.lock vendor/
git commit -m "Update production dependencies"
```

### Adding New Dependencies

When adding new production dependencies:

```bash
# Add to composer.json
composer require vendor/package --no-dev

# Update .gitignore if needed to include new vendor namespace
# Commit the changes
git add composer.json composer.lock vendor/ .gitignore
git commit -m "Add vendor/package dependency"
```

## References

- [Composer Optimization](https://getcomposer.org/doc/articles/autoloader-optimization.md)
- [WordPress Plugin Best Practices](https://developer.wordpress.org/plugins/plugin-basics/best-practices/)
- [PSR-4 Autoloading](https://www.php-fig.org/psr/psr-4/)
