# Production Build Process

This document describes the production build process for the Open Operator System (NV oOS) WordPress plugin.

## Overview

The repository is configured to be cloned and used directly as a production plugin. This is achieved through:
1. Optimized Composer autoloading (configured by default in composer.json)
2. Pre-packaged NPM dependencies in the Pro addon
3. Optimized ZIP distributions that regenerate dependencies on installation

## Distribution Strategy

### Git Repository (Clone)
- **Base Plugin**: Includes all PHP vendor dependencies (~13 packages)
- **Pro Addon**: Includes all PHP vendor + pre-packaged `assets/vendor` directory (~80MB with all 40+ NPM packages including CDN ones)
- **Ready to use**: Works immediately after cloning, no build step required

### ZIP Distributions (Download)
- **Base Plugin ZIP**: ~5.4MB - Includes PHP vendor, excludes development files
- **Pro Addon ZIP**: ~33MB - Includes most vendor packages (~35+ packages), excludes only CDN-loaded packages
- **Installation requirement**: Pro addon ZIP works immediately without npm install; CDN packages load from jsDelivr with fallback
- **Build optimization**: The build process excludes ~23MB of CDN-loaded packages (chart.js, katex, d3, axios, mathjs, prettier) that are loaded from jsDelivr CDN

This hybrid strategy ensures:
- Cloned repositories work immediately with all packages (developers)
- Distributed ZIPs are optimized (~33MB) and work immediately with CDN loading (users)
- All installations end up with the same core functionality
- CDN packages provide better performance and caching across sites

## Composer Production Optimization

### Configuration

As of February 2026, production optimization is **configured by default** in `composer.json`:

```json
{
  "config": {
    "optimize-autoloader": true,
    "classmap-authoritative": true
  }
}
```

This means you can now simply run:

```bash
# Base plugin
composer install --no-dev

# Pro addon
cd addons/pro
composer install --no-dev
```

The `--classmap-authoritative` flag is no longer required (but is still supported for backward compatibility).

### Flags Explained

- **`--no-dev`**: Excludes development dependencies (PHPUnit, coding standards, etc.)
- **`classmap-authoritative` (configured)**: Generates an optimized classmap and disables PSR-0/PSR-4 filesystem scanning

### Benefits

1. **Performance**: Classmap-based autoloading is significantly faster than PSR-4 scanning
2. **Production Ready**: No development dependencies in vendor directory
3. **Smaller Footprint**: Reduced repository size by removing dev packages
4. **Direct Clone**: Repository can be cloned directly into `wp-content/plugins/` and used

## NPM Dependencies (Pro Addon)

The Pro addon uses a hybrid approach for NPM dependencies to balance repository size, user experience, and performance:

### Pre-packaged in Repository

The `addons/pro/assets/vendor` directory (~80MB) is committed to the repository and contains all 40+ pre-built NPM packages. This allows developers who clone the repository to use the plugin immediately without running `npm install`.

**Included packages**: All packages including sharp, pdfkit, cheerio, turndown, stripe, exceljs, docx, fluent-ffmpeg, chart.js, katex, d3, axios, mathjs, prettier, and 30+ more

### Partially Packaged in ZIP Distributions

The build process (`bin/build-plugin-zip.sh`) excludes only CDN-loaded packages from the Pro addon ZIP file to optimize size while maintaining immediate functionality:

- **Repository size**: ~80MB vendor directory (all packages)
- **Pro ZIP size**: ~33MB (excludes 6 CDN packages)
- **Size savings**: ~23MB (CDN packages loaded from jsDelivr)
- **Pre-packaged in ZIP**: ~35+ packages (~57MB)

### Installation Process

When users install the Pro addon from a ZIP file:

**Immediate functionality** - The plugin works immediately without running `npm install`:
- 35+ packages are pre-packaged in `assets/vendor`
- 6 CDN packages load from jsDelivr with automatic fallback
- No build step required

**Optional - For offline installations**:
```bash
cd wp-content/plugins/mcp-ai-wpoos-pro
npm install --production
```

The `postinstall` script runs `addons/pro/scripts/copy-dependencies.js`, which:
1. Checks if CDN packages are already in `assets/vendor`
2. If missing, copies them from `node_modules` to `assets/vendor`
3. Applies cleanup (removes unnecessary files)

### CDN-Loaded Packages

These packages are excluded from the ZIP but loaded from CDN (jsDelivr) for optimal performance:
- **chart.js** (~420KB) - Chart rendering
- **katex** (~3.1MB) - LaTeX math rendering with fonts
- **d3** (~864KB) - Data visualization
- **axios** (~1.6MB) - HTTP client
- **mathjs** (~17MB) - Advanced mathematics library
- **prettier** (~500KB) - Code formatting

**Total CDN savings**: ~23MB

### System-Dependent Packages

These packages require system-level dependencies and are excluded from the ZIP:
- **canvas** (~2-50MB) - Requires libvips (Linux) or Cairo (macOS/Windows) for PDF OCR
- **facebook-nodejs-business-sdk** (~28MB) - Large SDK excluded by default

**Note**: Canvas requires system-level libraries to be installed on the server. It cannot be bundled effectively as it needs to compile native bindings for the specific platform.

### How Excluded Packages Work

**CDN packages**:
- Load faster from CDN (cached across sites)
- Are automatically downloaded when needed
- Have local fallback if CDN is unreachable
- Can be installed locally with `npm install` for offline use

**System-dependent packages**:
- Require `npm install` to compile native bindings
- Need system libraries installed (libvips, Cairo, etc.)
- Cannot be pre-compiled and bundled in ZIP

To disable CDN loading and use only local copies:
- Set `define( 'WP_MCP_AI_PRO_DISABLE_CDN', true );` in `wp-config.php`, OR
- Enable "Disable CDN Loading" in plugin settings, OR
- Run `npm install --production` (copies CDN packages to `assets/vendor`)

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
# Update dependencies (optimization is configured by default)
composer update --no-dev

# For pro addon
cd addons/pro
composer update --no-dev
```

## CI/CD Integration

The production build is automatically maintained in the repository. When dependencies are updated:

1. Run `composer update` in development
2. Run `composer install --no-dev` before committing (optimization is automatic)
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
   composer install --no-dev --classmap-authoritative
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
