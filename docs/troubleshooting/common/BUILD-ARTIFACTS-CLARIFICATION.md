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

## The mcp-ai-wpoos-base.php File

The `mcp-ai-wpoos-base.php` file is the main entry point for the base version:

- **In the repository**: Does NOT contain a WordPress plugin header (to prevent duplicate plugin detection when cloning)
- **During base build**: Build script ADDS the plugin header to make it the main plugin file
- **IS included** in `mcp-ai-wpoos-base-X.Y.Z.zip` (base version only, with header added)
- **NOT included** in `mcp-ai-wpoos-X.Y.Z.zip` (combined version - to prevent duplicate plugin detection)
- **NOT included** in `mcp-ai-wpoos-pro-X.Y.Z.zip` (pro add-on)
- Sets the `WP_MCP_AI_BASE_VERSION` constant to `true`
- Loads the core plugin file `mcp-ai-wpoos.php`

### Repository vs Distribution

**In the GitHub repository:**
- `mcp-ai-wpoos.php` - Has plugin header (Pro version - this is what gets activated)
- `mcp-ai-wpoos-base.php` - NO plugin header (build script adds it for WordPress.org)
- Result: Only ONE plugin appears in WordPress when cloning the repo (the Pro version)

**During the build process:**
1. **Base version build**: 
   - Adds plugin header to `mcp-ai-wpoos-base.php`
   - Removes plugin header from `mcp-ai-wpoos.php`
   - Result: Only base version plugin is detected
2. **Combined version build**: 
   - Excludes `mcp-ai-wpoos-base.php` entirely
   - Keeps `mcp-ai-wpoos.php` with its plugin header
   - Result: Only one plugin is detected (Pro version with all features)

This ensures WordPress only detects one plugin in both scenarios.

## Version Constant

The `WP_MCP_AI_BASE_VERSION` constant controls optional integrations:

```php
// In wp-config.php or custom deployment
define( 'WP_MCP_AI_BASE_VERSION', true );  // Disable Pro features even if Pro is installed
define( 'WP_MCP_AI_BASE_VERSION', false ); // Enable all features (default for combined version)
```

For the standalone base distribution (`wp-mcp-ai-base-X.Y.Z.zip`), this constant is not needed because the Pro files are not present.

## Build Outputs Summary

| ZIP File | Contains | Main Plugin File | Use Case |
|----------|----------|------------------|----------|
| `mcp-ai-wpoos-base-X.Y.Z.zip` | Base plugin only | `mcp-ai-wpoos-base.php` | Free WordPress.org distribution |
| `mcp-ai-wpoos-pro-X.Y.Z.zip` | Pro add-on only | `mcp-ai-wpoos-pro.php` | Commercial add-on (requires base) |
| `mcp-ai-wpoos-X.Y.Z.zip` | Base + Pro combined | `mcp-ai-wpoos.php` | Convenience package with everything |

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

### GitHub Release Workflow

The GitHub Actions release workflow (`.github/workflows/release.yml`) automatically builds a **combined version** (Base + Pro) when a release tag is pushed. The workflow:

1. Builds frontend assets and installs production dependencies
2. Creates a combined ZIP with the same exclusions as the local build script
3. Ensures pro tools from `addons/pro` are included
4. Excludes `mcp-ai-wpoos-base.php` to prevent duplicate plugin detection
5. Excludes dev files (`docs/`, `core/`, `shared/`, etc.) to reduce ZIP size
6. Uploads the combined ZIP to the GitHub release

The release workflow exclusions are kept in sync with `bin/build-plugin-zip.sh` to ensure consistency between local and CI builds.

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
