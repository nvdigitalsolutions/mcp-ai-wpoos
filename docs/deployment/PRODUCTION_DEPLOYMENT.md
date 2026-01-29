# Production Deployment Guide

## Overview

This repository is configured as a **production-ready WordPress plugin** with optimized dependencies and autoloader. It can be cloned directly to a WordPress installation without requiring Composer on the production server.

## Quick Start

### Option 1: Direct Clone (Recommended)

Clone directly into your WordPress plugins directory:

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
```

**No additional steps needed!** The vendor directory is included with production dependencies.

### Option 2: Download ZIP

1. Download the repository as a ZIP file from GitHub
2. Extract to `wp-content/plugins/mcp-ai-wpoos/`
3. Activate the plugin in WordPress admin

## What's Included

### Production Dependencies (5.9MB)

The vendor directory includes only production dependencies:

- **Symfony Components**: HTTP Client, Cache, Validator, Process, Filesystem
- **Guzzle HTTP**: HTTP client library
- **League OAuth2 Client**: OAuth2 authentication
- **Tiktoken PHP**: Token counting for AI models
- **PSR Standards**: HTTP Message, Cache, Container, Log interfaces

### Optimized Autoloader

The plugin uses an optimized classmap autoloader with:

- ✅ 676+ classes pre-mapped for instant loading
- ✅ No runtime scanning or file searching
- ✅ Faster performance than PSR-4 autoloading
- ✅ Generated with `--classmap-authoritative` flag

## Verification

After installation, verify the plugin is working:

```bash
# Check autoloader loads
cd /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/
php -r "require 'vendor/autoload.php'; echo 'OK';"

# Check main plugin file
php -l mcp-ai-wpoos.php
```

## Development vs Production

### This Branch (Production)

```bash
# Already configured for production
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
# Ready to use!
```

### Development Setup

If you need to modify the plugin:

```bash
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos

# Install ALL dependencies (including dev tools)
composer install

# Now you have PHPUnit, PHPCS, etc.
composer run test
composer run lint
```

## Updating Dependencies

If you need to update production dependencies:

```bash
# Update composer.lock
composer update --no-dev

# Regenerate optimized autoloader
composer install --no-dev --classmap-authoritative

# Commit changes
git add vendor/composer/installed.json vendor/composer/installed.php
git commit -m "Update production dependencies"
```

## File Structure

```
mcp-ai-wpoos/
├── mcp-ai-wpoos.php          # Main plugin file
├── includes/                  # Plugin classes
├── assets/                    # JS/CSS assets
├── vendor/                    # Production dependencies (INCLUDED)
│   ├── autoload.php          # Composer autoloader
│   ├── composer/             # Autoloader configuration
│   ├── symfony/              # Symfony components
│   ├── guzzlehttp/           # Guzzle HTTP
│   ├── league/               # OAuth2 client
│   └── ...                   # Other production dependencies
├── composer.json             # Dependency definitions
└── composer.lock             # Locked dependency versions
```

## Performance Benefits

### Load Time Comparison

| Autoloader Type | Class Load Time | Memory Usage |
|----------------|----------------|--------------|
| Standard PSR-4 | ~2-5ms per class | Higher |
| Classmap (this) | ~0.1ms per class | Lower |

### Size Comparison

| Installation Type | Size | Dependencies |
|------------------|------|--------------|
| Development | ~25MB | All packages + dev tools |
| Production (this) | ~5.9MB | Production packages only |

## Security Considerations

✅ **No dev tools exposed** - PHPUnit, PHPCS, etc. not included in production  
✅ **Locked versions** - composer.lock ensures consistent dependency versions  
✅ **Optimized autoloader** - No directory scanning reduces attack surface  
✅ **Minimal footprint** - Only necessary dependencies included  

## Troubleshooting

### "Class not found" errors

```bash
# Regenerate autoloader
composer install --no-dev --classmap-authoritative
```

### Large vendor directory

The production vendor directory should be ~5.9MB. If it's much larger:

```bash
# Check for dev dependencies
ls vendor/ | grep -E "(phpunit|phpcs)"

# Should return nothing. If you see dev packages:
composer install --no-dev --classmap-authoritative
```

### Missing dependencies

If you see "Package not found" errors:

```bash
# Install production dependencies
composer install --no-dev
```

## Support

For issues related to:

- **Plugin functionality**: See README.md
- **Installation**: See this file
- **Development**: See CONTRIBUTING.md
- **Security**: See SECURITY.md

## License

GPL-3.0-or-later - See LICENSE file
