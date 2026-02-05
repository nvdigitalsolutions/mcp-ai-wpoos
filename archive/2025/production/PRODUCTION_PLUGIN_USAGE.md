# Production Plugin Usage Guide

## Overview

This repository is configured to work as a **production-ready WordPress plugin** that can be cloned and activated immediately without any build steps.

## Quick Start (Production Use)

### 1. Clone the Repository

```bash
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
```

### 2. Upload to WordPress

Copy the `mcp-ai-wpoos` folder to your WordPress plugins directory:

```bash
cp -r mcp-ai-wpoos /path/to/wordpress/wp-content/plugins/
```

### 3. Activate

Go to WordPress Admin → Plugins → Activate "NV oOS"

✅ **That's it!** The plugin works immediately.

## What's Already Built

The repository includes all necessary built assets:

✅ **PHP Code**: Ready to run
✅ **JavaScript**: Minified and bundled (22 files)
✅ **CSS**: Compiled and available
✅ **Composer Dependencies**: Included in vendor/
✅ **Built Assets**: All production assets committed

## No Build Steps Required

For production use, you **do NOT need to**:

- ❌ Run `npm install`
- ❌ Run `composer install`
- ❌ Build any assets
- ❌ Run any tests

Everything is ready to use!

## Development Setup (Optional)

If you want to modify the plugin or run tests:

### 1. Install Dependencies

```bash
# Install npm dependencies
npm install

# Install composer dependencies (if needed)
composer install
```

### 2. Available Development Commands

```bash
# Run tests
npm test

# Watch mode for tests
npm test:watch

# Build assets (if modified)
npm run build

# Lint JavaScript
npm run lint:js
```

## Testing

### Production Users

Tests are **optional** and only needed for development. When you run `npm test` without installing dependencies, you'll see:

```
⚠️  Jest not found. Run "npm install" to install dev dependencies for testing.
ℹ️  Tests are optional for production use - the plugin works without them.
```

This is **expected behavior** and not an error. The plugin works perfectly without running tests.

### Developers

After running `npm install`, all test commands work normally:

```bash
npm test              # Run all tests
npm run test:watch    # Watch mode
npm run test:coverage # Coverage report
npm run test:verbose  # Verbose output
```

## Repository Structure

```
mcp-ai-wpoos/
├── assets/           # Built CSS and JS assets
│   ├── css/         # Compiled CSS
│   └── js/          # Minified JavaScript (22 files)
├── includes/         # PHP source code
├── vendor/          # Composer dependencies
├── addons/          # Pro addons
├── tests/           # Test files (development only)
└── package.json     # npm configuration
```

## File Sizes

The repository includes:

- **Built JavaScript**: ~22 minified JS files
- **CSS Files**: All compiled CSS
- **PHP Code**: Complete plugin code
- **Composer Packages**: 18 packages included

## Production Deployment

### Manual Deployment

1. Clone or download the repository
2. Upload to your WordPress plugins folder
3. Activate in WordPress admin

### CI/CD Deployment

The repository is ready for automated deployment:

```bash
# Example deployment script
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
rsync -av --exclude='.git' mcp-ai-wpoos/ /var/www/wordpress/wp-content/plugins/mcp-ai-wpoos/
```

## WordPress.org Compliance

This repository structure follows WordPress.org plugin guidelines:

✅ No build steps required
✅ All assets included
✅ Ready to activate immediately
✅ Professional structure

## FAQ

### Q: Do I need to run npm install?

**A:** No, for production use. Only if you want to develop or run tests.

### Q: Why does npm test show an error?

**A:** It's not an error - it's a helpful message. Tests are optional for production.

### Q: Are the built assets included?

**A:** Yes! All 22 JavaScript files and CSS assets are pre-built and committed.

### Q: Can I use this with WordPress.org?

**A:** Yes, the structure is WordPress.org compliant.

### Q: What about node_modules?

**A:** Not needed for production. Only for development if you want to modify code.

## Support

For issues or questions:

- **GitHub Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Documentation**: See docs/ folder

## Summary

✅ **Production Ready**: Clone and activate immediately
✅ **No Build Steps**: Everything pre-built
✅ **WordPress Standard**: Follows best practices
✅ **Optional Tests**: Development feature only
✅ **Fully Functional**: Works out of the box

**This plugin is designed to work immediately when cloned, with no additional setup required!**
