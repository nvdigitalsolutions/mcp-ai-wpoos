# PHPUnit Test Framework Packaging - Quick Reference

## Problem
The PHPUnit test framework and development dependencies (~140 MB) are needed for testing but shouldn't be committed to the main repository, making it difficult to set up testing in offline or air-gapped environments.

## Solution
Two scripts that package and extract development dependencies separately from the main plugin, while preserving production dependencies that are already committed to the repository.

### Production Dependencies (Always Available)
The following production dependencies (~5.6 MB) are committed to the repository and available on all environments including Cloudways:
- `vendor/autoload.php` - Composer autoloader
- `vendor/composer/` - Composer core files
- `vendor/nyholm/` - PSR-7 implementation
- `vendor/php-http/` - HTTP client discovery
- `vendor/psr/` - PSR interfaces
- `vendor/rahul900day/` - Tiktoken PHP
- `vendor/symfony/` - Symfony HTTP client

These are required for the plugin to function and are always present.

### 1. Package Script: `bin/package-vendor-dev.sh`
Creates a `vendor-dev.zip` archive (~140 MB) containing ONLY development dependencies (not production dependencies).

**Requirements:**
- Composer installed
- Internet access
- `zip` command available

**Usage:**
```bash
./bin/package-vendor-dev.sh
```

**Output:** `vendor-dev.zip` in the root directory

**Contains (dev-only packages):**
- PHPUnit test framework
- PHP_CodeSniffer & WordPress Coding Standards  
- WordPress stubs for IDE support
- PHP compatibility checker
- Sebastian testing tools
- Yoast PHPUnit polyfills
- Other development dependencies

**Note:** This package does NOT include production dependencies (they're already in the repo).

### 2. Install Script: `bin/install-vendor-dev.sh`
Extracts `vendor-dev.zip` and regenerates the autoloader to include both production and dev dependencies.

**Requirements:**
- `vendor-dev.zip` file in root directory
- `unzip` command available
- `composer` command (for autoloader regeneration)

**Usage:**
```bash
./bin/install-vendor-dev.sh
```

**Result:** 
- Development dependencies installed in `vendor/` directory
- Composer autoloader regenerated to include all dependencies
- Plugin ready for testing

## Workflow

### For Developers with Internet Access
Use composer normally:
```bash
composer install
composer run test
```

### For Air-Gapped/Offline Environments

**Step 1:** On a machine with internet, create the package:
```bash
git clone [repository]
cd mcp-ai-wpoos
composer install
./bin/package-vendor-dev.sh
```

**Step 2:** Transfer `vendor-dev.zip` to target environment

**Step 3:** On target machine, extract the package:
```bash
./bin/install-vendor-dev.sh
```

**Step 4:** Run tests:
```bash
composer run test
```

## Key Points

- ✅ Production dependencies (~5.6 MB) are committed to repository and available on Cloudways/production
- ✅ Plugin loads correctly on Cloudways with production dependencies only
- ✅ Dev dependencies (~140 MB) available as optional download for testing environments
- ✅ No composer or internet needed on target machine (for install script)
- ✅ Autoloader automatically regenerated to include all dependencies
- ✅ Scripts validated with shellcheck
- ✅ .gitignore excludes vendor-dev.zip automatically

## Cloudways Compatibility

The plugin is fully compatible with Cloudways hosting:

1. **Production deployment**: Works out of the box with committed production dependencies
2. **Testing on Cloudways**: Upload `vendor-dev.zip` and run install script
3. **Autoloader**: Automatically handles both production and dev dependencies
4. **No conflicts**: Dev packages don't overwrite production dependencies

The scripts are designed to work seamlessly in managed hosting environments like Cloudways, SiteGround, WP Engine, etc.

## File Sizes

| Component | Size | Committed? |
|-----------|------|------------|
| Production vendor | ~5.6 MB | Yes |
| Dev vendor (vendor-dev.zip) | ~140 MB | No |
| Plugin files (PHP/JS/CSS) | ~7 MB | Yes |

## Documentation

- **[TESTING.md](testing/TESTING.md)** - Complete testing guide
- **[BUILD.md](../../BUILD.md)** - Build and dependency management
- **[README.md](README.md)** - Main plugin documentation

## Troubleshooting

**Q: Package script fails with "Composer is required"**
A: Install composer first: https://getcomposer.org/

**Q: Install script fails with "vendor-dev.zip not found"**
A: Run `./bin/package-vendor-dev.sh` first or obtain the zip file from your distribution source

**Q: Tests fail after installing vendor-dev**
A: Run `composer run test:install` to set up WordPress test environment

**Q: Package size seems wrong**
A: Run `du -sh vendor-dev.zip` to check actual size

## Security

- Scripts use `set -euo pipefail` for safe error handling
- Temporary directories cleaned up on exit
- No secrets or credentials stored
- Shellcheck validated (no warnings)

## License

Same as main plugin: GPLv3 or later
