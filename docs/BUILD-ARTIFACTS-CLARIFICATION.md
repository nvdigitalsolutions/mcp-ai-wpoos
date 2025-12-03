# Build Artifacts Clarification

## Overview

This document clarifies the terminology and build artifacts for the WP Open Operator System (WP oOS) plugin.

## Three Build Artifacts

The build process creates **three ZIP files**:

1. **wp-mcp-ai-base-X.Y.Z.zip** - Base/Core Version (Standalone)
2. **wp-mcp-ai-pro-X.Y.Z.zip** - Pro Add-on (Requires Base)
3. **wp-mcp-ai-X.Y.Z.zip** - Combined Package (Base + Pro)

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
| **Base/Core Plugin** | Free standalone version | `wp-mcp-ai-base-X.Y.Z.zip` |
| **Pro Add-on** | Commercial extension | `wp-mcp-ai-pro-X.Y.Z.zip` |
| **Combined/Full** | Base + Pro together | `wp-mcp-ai-X.Y.Z.zip` |

## Base/Core Plugin Features

The base/core plugin (`wp-mcp-ai-base-X.Y.Z.zip`):

- ✅ **Fully functional** - Works standalone without Pro
- ✅ **35+ tools** included by default
- ✅ Uses `wp-mcp-ai.php` as the main entry point
- ✅ Excludes `wp-mcp-ai-base.php` (not needed for standalone base)
- ✅ Excludes `addons/pro/` directory
- ✅ Compatible with WordPress.org (GPL-3.0-or-later)
- ✅ Optional integrations (JetEngine, Elementor, etc.) work when those plugins are installed

## Build Script Usage

```bash
# These commands produce the SAME output:
./bin/build-plugin-zip.sh --base
./bin/build-plugin-zip.sh --core

# Both create: build/wp-mcp-ai-base-X.Y.Z.zip
```

## The wp-mcp-ai-base.php File

The `wp-mcp-ai-base.php` file is a special file that:

- **NOT included** in `wp-mcp-ai-base-X.Y.Z.zip` (standalone base)
- **IS included** in `wp-mcp-ai-X.Y.Z.zip` (combined version)
- Sets the `WP_MCP_AI_BASE_VERSION` constant
- Used only in custom deployments where you want to explicitly disable Pro features
- Not needed for most users

From the file's own documentation:
> "This file is NOT included in standalone base distributions to prevent WordPress from detecting two plugins. The standalone base distribution uses wp-mcp-ai.php directly."

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
| `wp-mcp-ai-base-X.Y.Z.zip` | Base plugin only | Free WordPress.org distribution |
| `wp-mcp-ai-pro-X.Y.Z.zip` | Pro add-on only | Commercial add-on (requires base) |
| `wp-mcp-ai-X.Y.Z.zip` | Base + Pro + base.php | Convenience package with everything |

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
- ❌ Ignore `build/wp-mcp-ai/` (extracted directory)
- ❌ Ignore `build/wp-mcp-ai-base/` (extracted directory)
- ❌ Ignore `build/wp-mcp-ai-pro/` (extracted directory)
- ❌ Ignore `*.zip` files everywhere else

This allows release ZIP files to persist in the repository while keeping the working directory clean.

## Conclusion

**Base = Core = Same Plugin**

The terms "base plugin" and "core plugin" are used interchangeably throughout the codebase and documentation. They both refer to the same artifact: the free, standalone version of WP oOS that works without any commercial add-ons.

The build script accepts both `--base` and `--core` flags to accommodate both terminologies, but they produce the exact same output file.
