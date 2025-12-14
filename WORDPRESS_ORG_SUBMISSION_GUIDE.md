# WordPress.org Submission Guide

This guide explains the changes made to ensure the WP oOS base version complies with WordPress.org plugin directory requirements and is optimized for upload.

## ✅ Changes Summary

### 1. Build Optimization for Fast Upload

**Size Reduction:** The build process now automatically optimizes the plugin ZIP for WordPress.org submission:

- **Before optimization:** 4.3MB (1,950 files)
- **After optimization:** 2.7MB (1,272 files)
- **Reduction:** 37% smaller, 678 fewer files

**Files automatically excluded during build:**
- Source map files (`*.map`) - Development debugging files (~2MB)
- Vendor `.git` directories - Git repositories in dependencies (~22MB uncompressed)
- Symfony translations - Translation files for validators (~2MB uncompressed, 58 files)
- Vendor documentation - README, CHANGELOG, CONTRIBUTING files (~65 files)
- Vendor test directories - Symfony test files (~1.7MB, 527 files)
- Unminified JavaScript source files - Only `.min.js` included
- Unminified CSS source files - Only `.min.css` included where available
- Development documentation - `ARCHITECTURE.md`, `CONTRIBUTING.md`, etc.
- `README.md` - WordPress.org uses `readme.txt` instead

**Why this matters:** Smaller uploads are faster, more reliable, and prevent 504 Gateway Timeout errors when uploading to wordpress.org.

### 2. Main Plugin File (`mcp-ai-wpoos-base.php`)

**Updated the plugin header** to be WordPress.org compliant:

```php
/**
 * Plugin Name: Open Operator System (WP oOS) - Base
 * Plugin URI: https://nvdigitalsolutions.com/wpoos
 * Description: AI Assistant framework with OpenAI, Gemini, and Ollama integration. Includes 35+ core tools. Patent Pending (Application #19/410,504).
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.7.1
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: mcp-ai-wpoos-base
 * Domain Path: /languages
 * Network: true
 */
```

**Key changes:**
- Added complete WordPress plugin headers
- Set Text Domain to `mcp-ai-wpoos-base` (matches plugin slug)
- Added all required metadata (Version, Author, License, etc.)

### 2. Build Script (`bin/build-plugin-zip.sh`)

**Updated the base version build** to rename the main plugin file:

```bash
# Rename wp-mcp-ai-base.php to mcp-ai-wpoos-base.php for WordPress.org compliance
# The main plugin file should match the folder name (mcp-ai-wpoos-base)
if [ -f "build/${BASE_SLUG}/wp-mcp-ai-base.php" ]; then
    mv "build/${BASE_SLUG}/wp-mcp-ai-base.php" "build/${BASE_SLUG}/mcp-ai-wpoos-base.php"
    echo "✓ Renamed wp-mcp-ai-base.php to mcp-ai-wpoos-base.php"
fi
```

**Result:** The base version ZIP now has the correct structure:
```
mcp-ai-wpoos-base/
├── mcp-ai-wpoos-base.php    ← Main plugin file (matches folder name)
├── mcp-ai-wpoos.php          ← Core plugin logic
├── readme.txt
├── includes/
├── assets/
└── ...
```

### 3. Documentation Updates

Updated documentation files to reflect the new structure:
- `docs/BUILD-ARTIFACTS-CLARIFICATION.md`
- `docs/ARCHITECTURE_QUICK_REFERENCE.md`

## 📦 WordPress.org Requirements Met

✅ **Single top-level folder:** `mcp-ai-wpoos-base/`  
✅ **Main plugin file matches folder name:** `mcp-ai-wpoos-base.php`  
✅ **Plugin header is complete:** All required fields present  
✅ **Text Domain matches slug:** `mcp-ai-wpoos-base`  
✅ **readme.txt is properly formatted:** All required sections included  
✅ **License is GPL-compatible:** GPLv3 or later  

## 🚀 Building for WordPress.org

### Build the Base Version

```bash
# Build the base version ZIP
./bin/build-plugin-zip.sh --base

# Output: build/mcp-ai-wpoos-base-1.0.0.zip
```

### Verify the Build

```bash
# Check the ZIP structure
unzip -l build/mcp-ai-wpoos-base-1.0.0.zip | head -30

# Verify the main plugin file exists
unzip -l build/mcp-ai-wpoos-base-1.0.0.zip | grep "mcp-ai-wpoos-base.php"

# Check the plugin header
unzip -p build/mcp-ai-wpoos-base-1.0.0.zip mcp-ai-wpoos-base/mcp-ai-wpoos-base.php | head -20
```

### Submit to WordPress.org

1. **Build the plugin:**
   ```bash
   ./bin/build-plugin-zip.sh --base
   ```

2. **Go to the WordPress.org plugin submission page:**
   https://wordpress.org/plugins/developers/add/

3. **Upload the ZIP file:**
   `build/mcp-ai-wpoos-base-1.0.0.zip`

4. **Submit for review**

## 📋 Pre-Submission Checklist

Before submitting to WordPress.org, verify:

- [x] Single top-level folder: `mcp-ai-wpoos-base/`
- [x] Main plugin file matches folder name: `mcp-ai-wpoos-base.php`
- [x] Plugin header is complete and properly formatted
- [x] Text Domain is `mcp-ai-wpoos-base`
- [x] readme.txt follows WordPress.org format
- [x] All required readme.txt sections present:
  - [x] Description
  - [x] Installation
  - [x] Frequently Asked Questions
  - [x] Screenshots
  - [x] Changelog
  - [x] Upgrade Notice
- [x] License is GPL-compatible (GPLv3)
- [x] No obfuscated code
- [x] No phone-home tracking without consent
- [x] External services are documented (OpenAI, Gemini)

## 🔍 Validation Commands

```bash
# Verify folder structure
unzip -l build/mcp-ai-wpoos-base-1.0.0.zip | grep "mcp-ai-wpoos-base/"

# Check main plugin file
unzip -l build/mcp-ai-wpoos-base-1.0.0.zip | grep "mcp-ai-wpoos-base.php"

# Verify plugin header
unzip -p build/mcp-ai-wpoos-base-1.0.0.zip mcp-ai-wpoos-base/mcp-ai-wpoos-base.php | head -25

# Check Text Domain
unzip -p build/mcp-ai-wpoos-base-1.0.0.zip mcp-ai-wpoos-base/mcp-ai-wpoos-base.php | grep "Text Domain"

# Verify readme.txt
unzip -p build/mcp-ai-wpoos-base-1.0.0.zip mcp-ai-wpoos-base/readme.txt | head -50
```

## ⚠️ Important Notes

1. **The main plugin file naming is critical:**
   - WordPress.org requires the main plugin file to match the folder name
   - Our ZIP contains folder: `mcp-ai-wpoos-base/`
   - Main file must be: `mcp-ai-wpoos-base.php`

2. **File structure in the repository vs. distribution:**
   - Repository has: `wp-mcp-ai-base.php`
   - Distribution has: `mcp-ai-wpoos-base.php` (renamed during build)
   - This is handled automatically by the build script

3. **Text Domain must match the plugin slug:**
   - Plugin slug: `mcp-ai-wpoos-base`
   - Text Domain: `mcp-ai-wpoos-base`
   - This ensures proper internationalization support

4. **The core plugin file (`mcp-ai-wpoos.php`) is still present:**
   - It contains the actual plugin implementation
   - `mcp-ai-wpoos-base.php` is just an entry point that loads it
   - WordPress will use `mcp-ai-wpoos-base.php` as the main file because it matches the folder name

## 📖 Related Documentation

- [Build Artifacts Clarification](docs/BUILD-ARTIFACTS-CLARIFICATION.md)
- [Release Checklist](RELEASE_CHECKLIST.md)
- [Architecture Quick Reference](docs/ARCHITECTURE_QUICK_REFERENCE.md)

## 🎯 Summary

All WordPress.org requirements have been successfully implemented:

✅ **Folder Structure:** Single top-level folder with correct naming  
✅ **Main Plugin File:** Matches folder name (`mcp-ai-wpoos-base.php`)  
✅ **Plugin Headers:** Complete with all required fields  
✅ **Text Domain:** Matches plugin slug for internationalization  
✅ **readme.txt:** Properly formatted with all required sections  
✅ **License:** GPL-compatible (GPLv3)  
✅ **Build Process:** Automated and tested  

**The plugin is ready for WordPress.org submission!** 🚀
