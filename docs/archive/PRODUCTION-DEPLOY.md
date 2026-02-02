# Production Deployment Guide

## Overview

This repository is configured for production deployment as a WordPress plugin. The vendor dependencies are included in the repository with production-only packages.

## Production Composer Setup

The repository has been prepared with:

```bash
composer install --no-dev --classmap-authoritative
```

### What this does:

1. **`--no-dev`**: Excludes development dependencies
   - Removes packages like phpunit, phpcs, wp-phpunit, etc.
   - Reduces vendor directory size
   - Only includes packages needed for production

2. **`--classmap-authoritative`**: Optimizes autoloading
   - Generates an optimized classmap
   - Improves performance by avoiding filesystem checks
   - Ideal for production environments

## Cloning for Production

To use this plugin in production:

```bash
# Clone the repository
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git

# Move to WordPress plugins directory
mv mcp-ai-wpoos /path/to/wordpress/wp-content/plugins/

# Activate the plugin
# (via WordPress admin or wp-cli)
```

**No need to run `composer install`** - all production dependencies are included!

## Development Setup

If you need to develop on this plugin:

```bash
# Clone the repository
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos

# Install dev dependencies
composer install

# Install npm dependencies
npm install
```

## Vendor Directory Strategy

The `.gitignore` is configured to:
- Include production vendor packages
- Exclude dev packages (phpunit, phpcs, etc.)
- Exclude test directories from vendor packages
- Exclude documentation and CI files from vendor

This ensures a clean, production-ready codebase while keeping the repository size manageable.

## Verifying Production Setup

Check that dev dependencies are not installed:

```bash
# Should NOT show dev packages
ls vendor/ | grep -E "phpunit|dealerdirect|cweagans|phpcodesniffer"

# Should show production packages
ls vendor/ | grep -E "symfony|guzzle|league"
```

Check that classmap is optimized:

```bash
# Should be 80KB+
ls -lh vendor/composer/autoload_classmap.php

# Should be 90KB+
ls -lh vendor/composer/autoload_static.php
```

## Updating Dependencies

To update production dependencies:

```bash
composer update --no-dev --classmap-authoritative
git add vendor/
git commit -m "Update production dependencies"
```

## Benefits

✅ **Fast deployment** - No composer install needed  
✅ **Optimized performance** - Classmap authoritative autoloading  
✅ **Smaller footprint** - No dev dependencies  
✅ **WordPress.org compatible** - Ready for plugin directory submission  
✅ **Enterprise ready** - Production-optimized configuration
