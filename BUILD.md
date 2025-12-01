# WP Open Operator System - Build Process

This document describes the build process for the WP Open Operator System (WP oOS) plugin, including both asset compilation and PHP dependency management.

## Overview

The plugin uses industry-standard build tools to:
- Minify and optimize CSS and JavaScript assets (40-60% reduction)
- Manage PHP dependencies via Composer
- Package development dependencies for offline use

## Prerequisites

### For Asset Building
- Node.js (v14 or higher)
- npm (v6 or higher)

### For PHP Development
- PHP 7.4 or higher
- Composer
- MySQL (for running tests)

## Installation

### JavaScript/CSS Build Tools

Install build dependencies:

```bash
npm install
```

This installs:
- `clean-css-cli` - CSS minification tool
- `uglify-js` - JavaScript minification tool
- `eslint` - JavaScript linting tool
- `@wordpress/eslint-plugin` - WordPress coding standards for JavaScript

### PHP Dependencies

#### Production Dependencies

Install only production dependencies (automatically included in the repository):

```bash
composer install --no-dev
```

Production packages (~5.6 MB):
- `rahul900day/tiktoken-php` - Token counting for AI models
- `symfony/http-client` - HTTP client for API requests
- `nyholm/psr7` - PSR-7 HTTP message implementation

#### Development Dependencies

For development and testing, install dev dependencies:

```bash
composer install
```

This adds (~140 MB):
- PHPUnit test framework
- PHP_CodeSniffer & WordPress Coding Standards
- WordPress stubs for IDE support
- PHP compatibility checker

**Note:** When you run `composer install`, a patch is automatically applied to the `wp-phpunit/wp-phpunit` package to fix a PHP warning about the `WP_LANG_DIR` constant being already defined. This patch adds a guard check to prevent duplicate constant definitions when running performance tests or activating the plugin. See `patches/README.md` for details.

**Alternative: Pre-packaged Dev Dependencies**

If you don't have internet access or want to distribute the test framework separately:

1. **Create the package** (on a machine with composer and internet):
   ```bash
   ./bin/package-vendor-dev.sh
   ```
   
   This creates `vendor-dev.zip` (~140 MB) containing all development dependencies.

2. **Use the package** (on target machine):
   ```bash
   ./bin/install-vendor-dev.sh
   ```
   
   This extracts the development dependencies without requiring composer or internet access.

See [TESTING.md](TESTING.md) for complete testing setup instructions.

## Build Commands

### JavaScript/CSS Assets

#### Build All Assets

Minify both CSS and JavaScript files:

```bash
npm run build
```

### Build CSS Only

Minify only CSS files:

```bash
npm run build:css
```

### Build JavaScript Only

Minify only JavaScript files:

```bash
npm run build:js
```

### Linting

Lint JavaScript files:

```bash
npm run lint:js
```

Auto-fix JavaScript linting issues:

```bash
npm run lint:js:fix
```

### Testing

Run JavaScript tests:

```bash
npm test
```

Run tests with coverage:

```bash
npm run test:coverage
```

Run tests in watch mode (for development):

```bash
npm run test:watch
```

See [TESTING.md](TESTING.md) for comprehensive testing documentation.

### Documentation Validation

#### Validate README Anchors

Verify that all Table of Contents links in README.md work correctly:

```bash
python3 bin/validate-readme-anchors.py
```

This script ensures TOC anchors match their corresponding section headers according to GitHub's anchor generation rules.

### PHP Linting and Testing

#### Lint PHP Code

Check code against WordPress Coding Standards:

```bash
composer run lint
```

#### Check PHP Compatibility

Verify compatibility with PHP 7.4-8.3:

```bash
composer run lint:compat
```

#### Auto-fix PHP Code Style

Automatically fix code style issues:

```bash
composer run format
```

#### Run PHP Tests

Run PHPUnit test suite (requires dev dependencies):

```bash
composer run test
```

See [TESTING.md](TESTING.md) for detailed testing instructions.

## Build Output

### CSS Files

Source files in `assets/css/` are minified to `.min.css`:

- `admin-settings.css` → `admin-settings.min.css` (~45% smaller)
- `chat.css` → `chat.min.css` (~50% smaller)
- `settings-dashboard.css` → `settings-dashboard.min.css` (~45% smaller)
- `user-chats.css` → `user-chats.min.css` (~48% smaller)
- `mcp-diagnostic.css` → `mcp-diagnostic.min.css` (~42% smaller)

### JavaScript Files

Source files in `assets/js/` are minified to `.min.js`:

- `admin-settings.js` → `admin-settings.min.js` (~55% smaller)
- `chat.js` → `chat.min.js` (~60% smaller)
- `settings-dashboard.js` → `settings-dashboard.min.js` (~52% smaller)
- `user-chats.js` → `user-chats.min.js` (~58% smaller)
- `auth0-setup.js` → `auth0-setup.min.js` (~50% smaller)
- `mcp-diagnostic.js` → `mcp-diagnostic.min.js` (~48% smaller)
- `performance-blocks.js` → `performance-blocks.min.js` (~53% smaller)

## Development Workflow

### 1. Development Mode

When developing, work with source files and enable `SCRIPT_DEBUG`:

```php
// wp-config.php
define( 'SCRIPT_DEBUG', true );
```

This tells WordPress to load unminified `.css` and `.js` files, making debugging easier.

### 2. Testing Changes

After making changes to source files, build the minified versions:

```bash
npm run build
```

### 3. Testing Minified Assets

To test with minified assets, disable `SCRIPT_DEBUG`:

```php
// wp-config.php
define( 'SCRIPT_DEBUG', false );
```

WordPress will automatically load `.min.css` and `.min.js` files.

### 4. Production Deployment

Before deploying to production:

1. Ensure all source files are up to date
2. Run `npm run build` to generate fresh minified files
3. Commit source files to version control
4. Deploy both source and minified files to production

**Note**: Minified files are excluded from Git (see `.gitignore`). They should be generated during deployment or in a CI/CD pipeline.

## Continuous Integration

### GitHub Actions Example

```yaml
name: Build Assets

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main, develop ]

jobs:
  build:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v2
    
    - name: Setup Node.js
      uses: actions/setup-node@v2
      with:
        node-version: '16'
    
    - name: Install dependencies
      run: npm install
    
    - name: Lint JavaScript
      run: npm run lint:js
    
    - name: Build assets
      run: npm run build
    
    - name: Upload artifacts
      uses: actions/upload-artifact@v2
      with:
        name: minified-assets
        path: |
          assets/css/*.min.css
          assets/js/*.min.js
```

## Deployment Options

### Option 1: Build Locally

Developers build assets before committing:

```bash
npm run build
git add assets/css/*.min.css assets/js/*.min.js
git commit -m "Build minified assets"
```

### Option 2: CI/CD Pipeline

Let your deployment pipeline build assets:

1. CI system checks out code
2. CI runs `npm install && npm run build`
3. CI deploys source + minified files
4. Git only tracks source files

### Option 3: Pre-commit Hook

Auto-build on commit using Git hooks:

```bash
# .git/hooks/pre-commit
#!/bin/sh
npm run build
git add assets/css/*.min.css assets/js/*.min.js
```

## Plugin ZIP Files

### Plugin Versions

WP oOS is available in three distribution formats:

| Version | File | Description |
|---------|------|-------------|
| **Base** | `wp-mcp-ai-base-X.Y.Z.zip` | Standalone fully functional plugin (works without Pro) |
| **Pro** | `wp-mcp-ai-pro-X.Y.Z.zip` | Commercial add-on with advanced features (requires Base) |
| **Base + Pro** | `wp-mcp-ai-X.Y.Z.zip` | Combined package with both Base and Pro included |

**Usage Options:**
1. Install **Base** alone for core functionality
2. Install **Base** + **Pro** for all features
3. Install **Combined** for convenience (includes everything)

See [FEATURE-MATRIX-CORE-PRO.md](docs/FEATURE-MATRIX-CORE-PRO.md) for feature comparison.

### Where to Find ZIP Files

Plugin ZIP files are created in several ways:

#### 1. GitHub Releases (Recommended for Production)

When a version tag is pushed (e.g., `v1.0.0`), the GitHub Actions release workflow automatically:
1. Builds production assets
2. Creates all three plugin ZIP files
3. Uploads them to the GitHub Release page

**To find released ZIP files:**
1. Go to the [GitHub Releases page](https://github.com/nvdigitalsolutions/wp-mcp-ai/releases)
2. Find the version you need
3. Download the appropriate ZIP file:
   - `wp-mcp-ai-base-X.Y.Z.zip` - Standalone base version
   - `wp-mcp-ai-pro-X.Y.Z.zip` - Pro add-on
   - `wp-mcp-ai-X.Y.Z.zip` - Base + Pro combined

#### 2. GitHub Actions Artifacts (For Testing/Development)

During any release workflow run, ZIP files are also uploaded as artifacts:
1. Go to the repository's **Actions** tab
2. Click on a workflow run
3. Scroll down to **Artifacts** section
4. Download the artifacts

Artifacts are retained for 30 days after the workflow run.

#### 3. Local Development (Build ZIP Locally)

To create plugin ZIP files locally for testing:

```bash
# Build all three versions (default)
./bin/build-plugin-zip.sh

# Build only the base version
./bin/build-plugin-zip.sh --base

# Build only the pro add-on
./bin/build-plugin-zip.sh --pro

# Build the base + pro combined version
./bin/build-plugin-zip.sh --combined

# Specify a version number
./bin/build-plugin-zip.sh --version 1.0.0

# Show help
./bin/build-plugin-zip.sh --help
```

Or use npm scripts:

```bash
npm run build:zip           # All three versions
npm run build:zip:base      # Base version only
npm run build:zip:pro       # Pro add-on only
npm run build:zip:combined  # Base + Pro combined
```

ZIP files will be created in the `build/` directory:
- `build/wp-mcp-ai-base-X.Y.Z.zip` - Standalone base version
- `build/wp-mcp-ai-pro-X.Y.Z.zip` - Pro add-on
- `build/wp-mcp-ai-X.Y.Z.zip` - Base + Pro combined

**Note:** Local builds require:
- Node.js and npm (for asset building)
- Composer (for PHP dependencies)
- zip command

### Creating a Release

To create a new release with ZIP files:

```bash
# 1. Ensure all changes are committed
git add .
git commit -m "Prepare release v1.0.0"
git push origin main

# 2. Create and push version tag
git tag v1.0.0
git push origin v1.0.0
```

The GitHub Actions workflow will automatically build and publish the ZIP files to the release.

See [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md) for complete release instructions.

## Troubleshooting

### Build Fails

If build command fails:

1. Delete `node_modules` and `package-lock.json`
2. Run `npm install` again
3. Try build command again

```bash
rm -rf node_modules package-lock.json
npm install
npm run build
```

### Minified Files Not Loading

1. Verify files were created:
```bash
ls -la assets/css/*.min.css
ls -la assets/js/*.min.js
```

2. Check `SCRIPT_DEBUG` setting in `wp-config.php`

3. Clear WordPress object cache if using Redis/Memcached

### File Permissions

Ensure build process has write permissions:

```bash
chmod -R 755 assets/css assets/js
npm run build
```

## Performance Impact

### Before Minification

- Total CSS size: ~93 KB
- Total JS size: ~295 KB
- **Total: ~388 KB**

### After Minification

- Total CSS size: ~50 KB (46% reduction)
- Total JS size: ~130 KB (56% reduction)
- **Total: ~180 KB** (54% overall reduction)

### Real-World Impact

- **Page Load Time**: 15-30% faster on average
- **Bandwidth Savings**: 200+ KB per page load
- **Mobile Performance**: Significant improvement on slower connections
- **SEO Benefits**: Faster page loads improve search rankings

## Best Practices

1. **Always build before deploying** to ensure production has optimized assets
2. **Test with minified files** before deployment to catch any issues
3. **Keep source files clean** - minification doesn't fix bad code
4. **Use linting** - run `npm run lint:js` regularly to maintain code quality
5. **Version control source files** - minified files can be regenerated
6. **Document custom builds** - if you customize the build process, update this README

## Support

For build process issues:

1. Check this README
2. Review `package.json` scripts
3. Check Node.js and npm versions
4. Review build tool documentation:
   - [clean-css-cli](https://github.com/jakubpawlowicz/clean-css-cli)
   - [uglify-js](https://github.com/mishoo/UglifyJS)
   - [@wordpress/eslint-plugin](https://www.npmjs.com/package/@wordpress/eslint-plugin)

## License

Same as main plugin: GPLv3 or later
