# Production Deployment Guide

## Overview

This repository is configured as a **production-ready WordPress plugin** with optimized dependencies and autoloader. It can be cloned directly to a WordPress installation without requiring Composer on the production server.

## Quick Start

### Option 1: Shallow Clone (Recommended for Cloudways and shared hosting)

Use a **shallow clone** to avoid downloading the full git history. This dramatically reduces disk usage — especially on servers like Cloudways where full git histories can grow to many gigabytes.

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone --depth=1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
```

**Why shallow clone?**
- Downloads only the latest commit instead of the full git history
- Reduces cloned repository disk usage by over 90%
- All plugin files are still fully present and functional
- Avoids the multi-GB `.git/objects` directories caused by historically committed binary artifacts

To update an existing shallow clone:

```bash
cd /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/
git pull --depth=1
```

### Option 2: Download Release ZIP (Recommended for simple deployments)

Download a pre-built release ZIP directly — no git history at all:

```bash
# Download latest release (replace X.X.X with the current version)
wget https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/latest/download/mcp-ai-wpoos.zip

# Extract to WordPress plugins directory
unzip mcp-ai-wpoos.zip -d /path/to/wordpress/wp-content/plugins/

# Activate in WordPress admin
```

Or visit the [GitHub Releases page](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases) and download the ZIP manually.

### Option 3: Full Clone (Development only)

A full clone is only needed if you plan to contribute or inspect the complete git history:

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
```

> ⚠️ **Do not use a full clone on production servers.** The git history contains large binary artifacts (build archives, vendor test fixtures) from historical commits that inflate `.git/objects` to many gigabytes. Use `--depth=1` instead.

**No additional steps needed!** The vendor directory is included with production dependencies.

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

### Production (Cloudways / Shared Hosting)

```bash
# Shallow clone — minimal disk usage, full plugin functionality
git clone --depth=1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
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
