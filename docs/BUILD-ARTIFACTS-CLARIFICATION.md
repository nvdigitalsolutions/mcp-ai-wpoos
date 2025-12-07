# Build Artifacts Clarification

## Overview

This document clarifies the terminology and build artifacts for the Open Operator System (WP oOS) plugin.

## Three Build Artifacts

The build process creates **three ZIP files**:

1. **mcp-ai-wpoos-base-X.Y.Z.zip** - Base/Core Version (Standalone)
2. **mcp-ai-wpoos-pro-X.Y.Z.zip** - Pro Add-on (Requires Base)
3. **mcp-ai-wpoos-X.Y.Z.zip** - Combined Package (Base + Pro)

## Base vs Core - They Are the Same

**Yes, the "base" plugin and the "core" plugin are the same thing.** The terminology is used interchangeably:

### In the Build Script
- The command line accepts both `--base` and `--core` as aliases (see `bin/build-plugin-zip.sh` line 42)
- Both flags build the same artifact: `wp-mcp-ai-base-X.Y.Z.zip`

### In Documentation
- **"Base Version"** is used in BUILD.md and build scripts
- **"Core Plugin"** is used in FEATURE-MATRIX-CORE-PRO.md and README.md
- Both terms refer to the same thing: the free, standalone plugin

### Technical Details

| Term | Meaning | File |
|------|---------|------|
| **Base/Core Plugin** | Free standalone version | `mcp-ai-wpoos-base-X.Y.Z.zip` |
| **Pro Add-on** | Commercial extension | `mcp-ai-wpoos-pro-X.Y.Z.zip` |
| **Combined/Full** | Base + Pro together | `mcp-ai-wpoos-X.Y.Z.zip` |

## Base/Core Plugin Features

The base/core plugin (`mcp-ai-wpoos-base-X.Y.Z.zip`):

- ✅ **Fully functional** - Works standalone without Pro
- ✅ **35+ tools** included by default
- ✅ Uses `mcp-ai-wpoos-base.php` as the main entry point (renamed from `wp-mcp-ai-base.php` during build)
- ✅ Includes `mcp-ai-wpoos.php` (core plugin logic)
- ✅ Excludes `addons/pro/` directory
- ✅ Compatible with WordPress.org (GPL-3.0-or-later)
- ✅ Optional integrations (JetEngine, Elementor, etc.) work when those plugins are installed

## Build Script Usage

```bash
# These commands produce the SAME output:
./bin/build-plugin-zip.sh --base
./bin/build-plugin-zip.sh --core

# Both create: build/mcp-ai-wpoos-base-X.Y.Z.zip
```

## The wp-mcp-ai-base.php File

The `wp-mcp-ai-base.php` file is the main entry point for the base version:

- **IS included** in `mcp-ai-wpoos-base-X.Y.Z.zip` (renamed to `mcp-ai-wpoos-base.php`)
- **IS included** in `mcp-ai-wpoos-X.Y.Z.zip` (combined version)
- Contains full WordPress plugin headers for the base version
- Sets the `WP_MCP_AI_BASE_VERSION` constant
- Loads the core plugin file `mcp-ai-wpoos.php`

During the build process for the base version:
1. The file `wp-mcp-ai-base.php` is copied to the build directory
2. It's renamed to `mcp-ai-wpoos-base.php` to match WordPress.org naming conventions
3. This ensures the main plugin file matches the folder name (`mcp-ai-wpoos-base/mcp-ai-wpoos-base.php`)

## Version Constant

The `WP_MCP_AI_BASE_VERSION` constant controls optional integrations:

```php
// In wp-config.php or custom deployment
define( 'WP_MCP_AI_BASE_VERSION', true );  // Disable Pro features even if Pro is installed
define( 'WP_MCP_AI_BASE_VERSION', false ); // Enable all features (default for combined version)
```

For the standalone base distribution (`wp-mcp-ai-base-X.Y.Z.zip`), this constant is not needed because the Pro files are not present.

## Build Outputs Summary

| ZIP File | Contains | Use Case |
|----------|----------|----------|
| `mcp-ai-wpoos-base-X.Y.Z.zip` | Base plugin only | Free WordPress.org distribution |
| `mcp-ai-wpoos-pro-X.Y.Z.zip` | Pro add-on only | Commercial add-on (requires base) |
| `mcp-ai-wpoos-X.Y.Z.zip` | Base + Pro + base.php | Convenience package with everything |

## Building All ZIP Files

To build all three versions at once:

```bash
# Option 1: Build script (default builds all)
./bin/build-plugin-zip.sh

# Option 2: Build script with explicit flag
./bin/build-plugin-zip.sh --all

# Option 3: NPM script
npm run build:zip
```

All three ZIP files are created in the `build/` directory and can be committed to the repository for distribution.

## Git Configuration

The `.gitignore` file is configured to:

- ✅ Track `build/*.zip` files (release artifacts)
- ❌ Ignore `build/mcp-ai-wpoos/` (extracted directory)
- ❌ Ignore `build/wp-mcp-ai-base/` (extracted directory)
- ❌ Ignore `build/wp-mcp-ai-pro/` (extracted directory)
- ❌ Ignore `*.zip` files everywhere else

This allows release ZIP files to persist in the repository while keeping the working directory clean.

## Conclusion

**Base = Core = Same Plugin**

The terms "base plugin" and "core plugin" are used interchangeably throughout the codebase and documentation. They both refer to the same artifact: the free, standalone version of WP oOS that works without any commercial add-ons.

The build script accepts both `--base` and `--core` flags to accommodate both terminologies, but they produce the exact same output file.
