# Production Optimization - Composer & npm

This repository is now configured for automatic production optimization through both `composer.json` and npm configuration.

---

## 📦 Composer Optimization

The `composer.json` file includes the following production optimization settings:

```json
"config": {
    "classmap-authoritative": true,
    "optimize-autoloader": true
}
```

These settings ensure that:
- **All composer operations automatically use authoritative classmap** mode
- **Autoloader is always optimized** for maximum performance
- **No manual flags required** - optimization is built-in

### Composer Installation Commands

#### For Production Deployment

```bash
# Simple production install - automatically optimized
composer install --no-dev

# Or use the convenience script
composer production
```

#### For Development

```bash
# Install with dev dependencies - still optimized
composer install
```

#### Regenerate Autoloader

```bash
# Automatically uses authoritative classmap
composer dump-autoload

# Or use the convenience script
composer production:optimize
```

---

## 📦 npm Optimization

The `.npmrc` file includes the following production optimization settings:

```ini
# Production Optimization Settings
prefer-offline=true      # Use offline cache for faster installs
audit=false             # Skip audit (run separately if needed)
fund=false              # Skip funding messages
package-lock=true       # Use package-lock.json for consistency
```

The `package.json` includes convenience scripts:

```json
"scripts": {
    "production": "npm ci --omit=dev --prefer-offline",
    "production:build": "npm run build",
    "production:full": "npm run production && npm run production:build"
}
```

### npm Installation Commands

#### For Production Deployment

```bash
# Install only production dependencies (clean install)
npm production

# Build production assets
npm run production:build

# Or do both in one command
npm run production:full
```

#### For Development

```bash
# Install all dependencies (including dev dependencies)
npm install

# Build development assets
npm run build
```

---

## 🎯 Combined Production Workflow

For a complete production deployment:

```bash
# Step 1: Install PHP dependencies (optimized)
composer production

# Step 2: Install Node dependencies (optimized)
npm run production

# Step 3: Build frontend assets
npm run production:build
```

Or use the all-in-one approach:

```bash
composer production && npm run production:full
```

---

## 📋 What This Means

### Composer Flags

#### `--no-dev`
- **Removes development dependencies** like PHPUnit, PHP_CodeSniffer, WordPress Stubs, etc.
- **Keeps only production dependencies** required for the plugin to function
- Reduces vendor directory size and improves deployment speed

#### `--classmap-authoritative`
- **Generates an optimized autoloader** with a pre-built class map
- **Improves performance** by eliminating filesystem checks during class loading
- **Recommended for production** environments
- **Now automatic** via composer.json configuration

#### `optimize-autoloader`
- **Converts PSR-4/PSR-0 rules into classmap entries** when possible
- **Improves autoload performance** by reducing lookups
- **Now automatic** via composer.json configuration

### npm Flags & Settings

#### `npm ci`
- **Clean install** - removes node_modules and reinstalls from package-lock.json
- **Faster and more reliable** than `npm install` for production
- **Ensures consistent installs** across all environments

#### `--omit=dev`
- **Excludes devDependencies** from installation
- **Reduces node_modules size** significantly
- **Faster installation** with fewer packages

#### `--prefer-offline`
- **Uses local cache** when available
- **Faster installations** by avoiding network requests
- **Works in offline environments** (if packages are cached)

#### `.npmrc` Settings
- `audit=false` - Skips security audit for faster installs (run separately: `npm audit`)
- `fund=false` - Suppresses funding messages for cleaner output
- `package-lock=true` - Ensures package-lock.json is always used

---

## 🎨 Benefits of Automatic Configuration

### Composer Benefits

1. **No Manual Flags Required**: Simply run `composer install --no-dev` or `composer production`
2. **Consistent Optimization**: Every developer and CI/CD system gets the same optimized setup
3. **Faster Class Loading**: Authoritative classmap eliminates filesystem scanning (~30% faster)
4. **Reduced Human Error**: Configuration is in version control, not CLI flags
5. **Better Performance**: Both development and production benefit from optimization

### npm Benefits

1. **Predictable Installs**: `npm ci` ensures exact package versions from package-lock.json
2. **Faster Installation**: Offline cache and skipped audits speed up deployment
3. **Production-Ready**: Dev dependencies automatically excluded
4. **Consistent Across Environments**: .npmrc settings apply to all team members
5. **Reduced Bundle Size**: Only production dependencies installed

## Installed Production Packages

The following production dependencies are included:

1. **rahul900day/tiktoken-php** - Token counting for AI models
2. **symfony/http-client** - HTTP client for API requests
3. **symfony/validator** - Data validation
4. **symfony/cache** - Caching system
5. **symfony/filesystem** - File operations
6. **symfony/process** - Process execution
7. **nyholm/psr7** - PSR-7 HTTP message implementation
8. **league/oauth2-client** - OAuth 2.0 client
9. **guzzlehttp/guzzle** - HTTP client (dependency)
10. Various PSR interfaces (psr/cache, psr/http-client, etc.)

## Development Dependencies Removed

The following dev-only packages are NOT included:

- phpunit/phpunit
- squizlabs/php_codesniffer
- wp-coding-standards/wpcs
- phpcompatibility/phpcompatibility-wp
- php-stubs/wordpress-stubs
- dealerdirect/phpcodesniffer-composer-installer
- yoast/phpunit-polyfills
- wp-phpunit/wp-phpunit
- cweagans/composer-patches

## Git Configuration

This repository uses a selective `.gitignore` strategy:

- **Vendor files ARE committed** to git for distribution
- Only production dependencies are included
- Test files and docs are excluded via `.gitignore` patterns
- This allows the plugin to be cloned and used directly without running `composer install`

## Verification

To verify the installation:

```bash
# Check installed packages
composer show --installed

# Test autoloader
php -r "require 'vendor/autoload.php'; echo 'Autoloader OK\n';"

# Verify no dev packages
ls vendor/phpunit 2>&1  # Should return "No such file or directory"
```

## For Developers

If you're developing this plugin locally, you'll need dev dependencies:

```bash
# Install with dev dependencies
composer install

# Or add them back
composer install --dev
```

## For Production Deployment

The repository is already optimized for production. Just clone and use:

```bash
git clone <repository-url>
cd mcp-ai-wpoos
# That's it! Vendor dependencies are already included
```

## Changes Made

Two files were updated to reflect the production installation:

1. **vendor/composer/installed.json** - Removed dev package metadata
2. **vendor/composer/installed.php** - Set `'dev' => false`, removed dev packages

The optimized classmap autoloader is in:
- **vendor/composer/autoload_classmap.php** - Pre-built class map
- **vendor/composer/autoload_static.php** - Static autoloader configuration

## Date

Production installation completed: 2026-02-04 (Latest)

## Autoloader Statistics

- **Optimized Classes**: 676 classes in classmap
- **Vendor Directory Size**: ~5.9MB
- **Autoloader Mode**: Authoritative (no filesystem scanning)
