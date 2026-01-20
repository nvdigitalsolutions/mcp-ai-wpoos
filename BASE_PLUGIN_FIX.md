# Base Plugin Activation Fix

## Problem

Users were unable to activate the base plugin from the ZIP file, receiving a fatal error during activation. The activation URL showed `?action=activate&plugin=mcp-ai-wpoos-base`, indicating WordPress was trying to activate `mcp-ai-wpoos-base/mcp-ai-wpoos-base.php`, but the plugin structure was incorrect.

## Root Cause

The build script was creating a ZIP file with the folder name `mcp-ai-wpoos-base/` instead of `mcp-ai-wpoos/`, and trying to use `mcp-ai-wpoos-base.php` as the main plugin file. This violated WordPress plugin naming conventions.

### WordPress Plugin Structure Convention

WordPress expects:
- Folder name: `mcp-ai-wpoos/`
- Main plugin file: `mcp-ai-wpoos/mcp-ai-wpoos.php`
- Activation path: `mcp-ai-wpoos/mcp-ai-wpoos.php`

The ZIP filename can be different (e.g., `mcp-ai-wpoos-base-1.1.0.zip`), but the folder inside must match the main plugin file name prefix.

## Solution

### 1. Repository File Changes

**File: `mcp-ai-wpoos-base.php`**
- Added proper WordPress plugin header
- This file is now usable for direct activation from repository clones
- It includes the main plugin file (`mcp-ai-wpoos.php`) and sets `WP_MCP_AI_BASE_VERSION = true`
- **Note**: This file is removed from the base version ZIP (not needed for distribution)

### 2. Build Script Changes

**File: `bin/build-plugin-zip.sh`**

Changed base version build process:

```bash
# OLD (WRONG)
BASE_SLUG="mcp-ai-wpoos-base"    # Created folder: mcp-ai-wpoos-base/
                                  # Tried to use: mcp-ai-wpoos-base.php as main file

# NEW (CORRECT)
BASE_SLUG="mcp-ai-wpoos"          # Creates folder: mcp-ai-wpoos/
BASE_ZIP_NAME="mcp-ai-wpoos-base" # ZIP filename: mcp-ai-wpoos-base-X.Y.Z.zip
                                   # Uses: mcp-ai-wpoos.php as main file
```

Key changes:
1. Folder inside ZIP is now `mcp-ai-wpoos/` (not `mcp-ai-wpoos-base/`)
2. Removes `mcp-ai-wpoos-base.php` from the ZIP
3. Uses `mcp-ai-wpoos.php` as the main plugin file
4. Updates plugin header to remove "Complete" from the name
5. Keeps `WP_MCP_AI_BASE_VERSION` constant as `false` to load all base features

## Final Structure

### Base Version
```
mcp-ai-wpoos-base-1.1.0.zip        ← ZIP filename
└── mcp-ai-wpoos/                  ← Folder name
    ├── mcp-ai-wpoos.php           ← Main plugin file (has header)
    ├── includes/
    ├── assets/
    └── ...
```

**WordPress Activation Path**: `mcp-ai-wpoos/mcp-ai-wpoos.php` ✅

### Pro Add-on (Unchanged)
```
mcp-ai-wpoos-pro-1.1.0.zip
└── mcp-ai-wpoos-pro/
    ├── mcp-ai-wpoos-pro.php       ← Main plugin file
    └── ...
```

### Combined Version (Unchanged)
```
mcp-ai-wpoos-1.1.0.zip
└── mcp-ai-wpoos/
    ├── mcp-ai-wpoos.php           ← Main plugin file
    ├── addons/pro/
    └── ...
```

## Plugin Header Differences

### Base Version (`mcp-ai-wpoos.php` in base ZIP)
```php
/**
 * Plugin Name: NV Digital Open Operator System (oOS)
 * Description: AI Assistant framework with OpenAI, Gemini, and Ollama integration. 
 *              Works standalone with optional third-party plugin integrations.
 */
// ...
define( 'WP_MCP_AI_BASE_VERSION', false ); // ← false to load all base features
```

**Note**: `WP_MCP_AI_BASE_VERSION` must be `false` in the base version to ensure all included integration files are loaded. Setting it to `true` would skip loading files that are present in the ZIP, causing fatal errors.

### Complete Version (`mcp-ai-wpoos.php` in combined ZIP)
```php
/**
 * Plugin Name: NV Digital Open Operator System Complete (oOS)
 * Description: Complete AI Assistant framework with OpenAI, Gemini, and Ollama integration. 
 *              Includes 109 tools with base features and Pro add-on...
 */
// ...
define( 'WP_MCP_AI_BASE_VERSION', false ); // ← Same as base, Pro addon is the differentiator
```

## Testing

Automated tests verified:
- ✅ Folder structure: `mcp-ai-wpoos/`
- ✅ Main file present: `mcp-ai-wpoos.php`
- ✅ Repository file removed: `mcp-ai-wpoos-base.php` not in ZIP
- ✅ Plugin header updated: No "Complete" in name
- ✅ BASE_VERSION constant: Kept as `false` to load all base features

## Building the Base Version

```bash
# Build base version only
./bin/build-plugin-zip.sh --base --version 1.1.0

# Output:
# build/mcp-ai-wpoos-base-1.1.0.zip
```

## Backward Compatibility

This change **does not** affect:
- Existing installations (plugins already activated)
- The combined version structure
- The pro add-on structure
- Repository development workflow

Users with old base version ZIPs should:
1. Deactivate and delete the old plugin
2. Upload and activate the new base version ZIP

## Related Files

- `mcp-ai-wpoos-base.php` - Repository-only entry point (has header now)
- `mcp-ai-wpoos.php` - Main plugin file (always used in ZIPs)
- `bin/build-plugin-zip.sh` - Build script with corrected logic

## Date

Fixed: 2026-01-20
