#!/bin/bash
#
# Build Pro Add-on Standalone Package
#
# Creates a standalone Pro add-on package for commercial distribution.
# This is NOT for WordPress.org submission - it's for self-hosted/commercial use.
#
# The Pro addon requires the base plugin to be installed first.
#
# Usage:
#   ./bin/build-pro-standalone.sh                # Build Pro addon
#   ./bin/build-pro-standalone.sh --version 1.0.0  # Specify version
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Parse version argument if provided
VERSION=""
if [ "$1" = "--version" ] && [ -n "$2" ]; then
    VERSION="$2"
else
    # Get version from Pro plugin file
    VERSION=$(grep "WP_MCP_AI_PRO_VERSION" addons/pro/mcp-ai-wpoos-pro.php | grep -o "'[0-9.]*'" | tr -d "'")
    if [ -z "$VERSION" ]; then
        VERSION="1.0.0"
    fi
fi

echo "=========================================="
echo "Building Pro Add-on Standalone (v${VERSION})"
echo "=========================================="
echo ""

# Check requirements
if ! command -v zip &> /dev/null; then
    echo "❌ Error: zip is required but not installed."
    exit 1
fi

echo "✅ Requirements met"
echo ""

# Create temporary build directory
PRO_BUILD_DIR="/tmp/pro-build-$$"
PRO_SLUG="nvdigital-oos-pro"

echo "Step 1: Preparing Pro add-on directory..."
mkdir -p "$PRO_BUILD_DIR"

# Copy Pro addon files with exclusions
echo "Step 2: Copying Pro add-on files (excluding tests, docs, dev files)..."
rsync -av --quiet addons/pro/ "$PRO_BUILD_DIR/" \
    --exclude 'node_modules' \
    --exclude 'tests' \
    --exclude 'test' \
    --exclude 'Test' \
    --exclude 'Tests' \
    --exclude '.npmrc' \
    --exclude '.git' \
    --exclude '.gitignore' \
    --exclude 'package-lock.json' \
    --exclude 'composer.lock' \
    --exclude '*.md' \
    --exclude '.DS_Store' \
    --exclude 'Thumbs.db' \
    --exclude 'vendor/*/tests' \
    --exclude 'vendor/*/test' \
    --exclude 'vendor/*/Test' \
    --exclude 'vendor/*/Tests' \
    --exclude 'vendor/*/*/tests' \
    --exclude 'vendor/*/*/test' \
    --exclude 'vendor/*/*/Test' \
    --exclude 'vendor/*/*/Tests' \
    --exclude 'vendor/*/docs' \
    --exclude 'vendor/*/doc' \
    --exclude 'vendor/*/Docs' \
    --exclude 'vendor/*/examples' \
    --exclude 'vendor/*/example' \
    --exclude 'vendor/*/*/docs' \
    --exclude 'vendor/*/*/doc' \
    --exclude 'vendor/*/*/Docs' \
    --exclude 'vendor/*/*/examples' \
    --exclude 'vendor/*/*/example' \
    --exclude 'vendor/*/README*' \
    --exclude 'vendor/*/CHANGELOG*' \
    --exclude 'vendor/*/CONTRIBUTING*' \
    --exclude 'vendor/*/LICENSE*' \
    --exclude 'vendor/*/*/README*' \
    --exclude 'vendor/*/*/CHANGELOG*' \
    --exclude 'vendor/*/*/CONTRIBUTING*' \
    --exclude 'vendor/*/*/LICENSE*' \
    --exclude 'vendor/*/.travis.yml' \
    --exclude 'vendor/*/.circleci' \
    --exclude 'vendor/*/.github' \
    --exclude 'vendor/*/*/.travis.yml' \
    --exclude 'vendor/*/*/.circleci' \
    --exclude 'vendor/*/*/.github' \
    --exclude 'vendor/*/phpunit.xml*' \
    --exclude 'vendor/*/phpstan.neon*' \
    --exclude 'vendor/*/psalm.xml*' \
    --exclude 'vendor/*/.php-cs-fixer*' \
    --exclude 'vendor/*/*/phpunit.xml*' \
    --exclude 'vendor/*/*/phpstan.neon*' \
    --exclude 'vendor/*/*/psalm.xml*' \
    --exclude 'vendor/*/*/.php-cs-fixer*' \
    --exclude 'vendor/*/Makefile' \
    --exclude 'vendor/*/*/Makefile'

echo "   Excluded: tests, docs, examples, README files, CI configs, QA tools"

# Transform text domain for consistency
echo "Step 3: Transforming text domain..."
BEFORE_COUNT=$(grep -r "'mcp-ai-wpoos-pro'\|'mcp-ai-pro'\|'wp-mcp-ai-pro'" "$PRO_BUILD_DIR" --include="*.php" 2>/dev/null | wc -l)

# Transform Pro text domains to WordPress.org compliant version (base + -pro)
# Transform 'mcp-ai-wpoos-pro' → 'nvdigital-open-operator-system-oos-pro'
find "$PRO_BUILD_DIR" -name "*.php" -type f -exec sed -i "s/'mcp-ai-wpoos-pro'/'nvdigital-open-operator-system-oos-pro'/g" {} \;
find "$PRO_BUILD_DIR" -name "*.php" -type f -exec sed -i 's/"mcp-ai-wpoos-pro"/"nvdigital-open-operator-system-oos-pro"/g' {} \;

# Transform 'mcp-ai-pro' → 'nvdigital-open-operator-system-oos-pro'
find "$PRO_BUILD_DIR" -name "*.php" -type f -exec sed -i "s/'mcp-ai-pro'/'nvdigital-open-operator-system-oos-pro'/g" {} \;
find "$PRO_BUILD_DIR" -name "*.php" -type f -exec sed -i 's/"mcp-ai-pro"/"nvdigital-open-operator-system-oos-pro"/g' {} \;

# Transform 'wp-mcp-ai-pro' → 'nvdigital-open-operator-system-oos-pro'
find "$PRO_BUILD_DIR" -name "*.php" -type f -exec sed -i "s/'wp-mcp-ai-pro'/'nvdigital-open-operator-system-oos-pro'/g" {} \;
find "$PRO_BUILD_DIR" -name "*.php" -type f -exec sed -i 's/"wp-mcp-ai-pro"/"nvdigital-open-operator-system-oos-pro"/g' {} \;

# Transform base text domain 'mcp-ai-wpoos' → 'nvdigital-open-operator-system-oos' (for any base references)
find "$PRO_BUILD_DIR" -name "*.php" -type f -exec sed -i "s/'mcp-ai-wpoos'/'nvdigital-open-operator-system-oos'/g" {} \;
find "$PRO_BUILD_DIR" -name "*.php" -type f -exec sed -i 's/"mcp-ai-wpoos"/"nvdigital-open-operator-system-oos"/g' {} \;

# Transform JavaScript files
find "$PRO_BUILD_DIR" -name "*.js" -type f -exec sed -i "s/'mcp-ai-wpoos-pro'/'nvdigital-open-operator-system-oos-pro'/g" {} \;
find "$PRO_BUILD_DIR" -name "*.js" -type f -exec sed -i 's/"mcp-ai-wpoos-pro"/"nvdigital-open-operator-system-oos-pro"/g' {} \;
find "$PRO_BUILD_DIR" -name "*.js" -type f -exec sed -i "s/'mcp-ai-pro'/'nvdigital-open-operator-system-oos-pro'/g" {} \;
find "$PRO_BUILD_DIR" -name "*.js" -type f -exec sed -i 's/"mcp-ai-pro"/"nvdigital-open-operator-system-oos-pro"/g' {} \;
find "$PRO_BUILD_DIR" -name "*.js" -type f -exec sed -i "s/'mcp-ai-wpoos'/'nvdigital-open-operator-system-oos'/g" {} \;
find "$PRO_BUILD_DIR" -name "*.js" -type f -exec sed -i 's/"mcp-ai-wpoos"/"nvdigital-open-operator-system-oos"/g' {} \;

AFTER_COUNT=$(grep -r "'mcp-ai-wpoos-pro'\|'mcp-ai-pro'\|'wp-mcp-ai-pro'" "$PRO_BUILD_DIR" --include="*.php" 2>/dev/null | wc -l)
echo "   Transformed: $((BEFORE_COUNT - AFTER_COUNT)) instances"
echo "   Pro text domain: nvdigital-open-operator-system-oos-pro"
echo "   Base text domain: nvdigital-open-operator-system-oos"

# Add plugin header to make it a standalone plugin
echo "Step 4: Adding plugin header..."
cat > "$PRO_BUILD_DIR/mcp-ai-wpoos-pro-temp.php" << 'EOPHP'
<?php
/**
 * Plugin Name: NV Digital Open Operator System (oOS) - Pro Add-on
 * Plugin URI: https://nvdigitalsolutions.com/wpoos-pro
 * Description: Professional add-on for NV Digital Open Operator System. Adds 70+ advanced tools including WooCommerce, social media, GitHub, Google services, FFmpeg, and multi-agent orchestration. REQUIRES base plugin to be installed first.
 * Version: VERSION_PLACEHOLDER
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: Proprietary
 * Text Domain: nvdigital-open-operator-system-oos-pro
 * Domain Path: /languages
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if base plugin is active
if ( ! defined( 'WP_MCP_AI_VERSION' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>';
		echo '<strong>NV oOS Pro:</strong> Base plugin is required. ';
		echo 'Please install and activate "NV Digital Open Operator System (oOS)" first.';
		echo '</p></div>';
	} );
	return;
}

EOPHP

# Replace version placeholder
sed -i "s/VERSION_PLACEHOLDER/${VERSION}/g" "$PRO_BUILD_DIR/mcp-ai-wpoos-pro-temp.php"

# Append the rest of the original file (skip the first 50 lines that are comments)
tail -n +51 "$PRO_BUILD_DIR/mcp-ai-wpoos-pro.php" >> "$PRO_BUILD_DIR/mcp-ai-wpoos-pro-temp.php"
mv "$PRO_BUILD_DIR/mcp-ai-wpoos-pro-temp.php" "$PRO_BUILD_DIR/mcp-ai-wpoos-pro.php"

# Create README for Pro
echo "Step 5: Creating README..."
cat > "$PRO_BUILD_DIR/README.txt" << 'EOREADME'
=== NV Digital Open Operator System (oOS) - Pro Add-on ===
Contributors: nvdigitalsolutions
Tags: ai, pro, woocommerce, social-media, automation
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: VERSION_PLACEHOLDER
License: Proprietary
License URI: https://nvdigitalsolutions.com/wpoos-pro/license

Professional add-on for NV Digital Open Operator System. Adds 70+ advanced tools.

== Description ==

**REQUIRES BASE PLUGIN**

This is the Pro add-on for NV Digital Open Operator System (oOS). You must have the base plugin installed first.

Get the base plugin from:
- WordPress.org: https://wordpress.org/plugins/nvdigital-open-operator-system-oos/
- GitHub: https://github.com/nvdigitalsolutions/mcp-ai-wpoos

= Pro Features =

**70+ Additional Tools:**

* WooCommerce Integration (3 tools)
* Social Media Analytics (Facebook, LinkedIn, Twitter)
* GitHub Integration (repository management, code search)
* Google Services (Drive, Sheets, Calendar, Analytics)
* FFmpeg Video Processing
* Multi-Agent Orchestration
* Advanced Document Generation (PDF, Word, Excel)
* Email Marketing Integration
* CRM Integration
* And much more...

= Installation =

1. Install and activate the base plugin first
2. Upload this Pro add-on
3. Activate the Pro add-on
4. Pro tools will appear in your assistant tool list

= Support =

For support, please visit: https://nvdigitalsolutions.com/support

= License =

This is proprietary software. All rights reserved.
Patent Pending (Application #19/410,504).

EOREADME

# Replace version in README
sed -i "s/VERSION_PLACEHOLDER/${VERSION}/g" "$PRO_BUILD_DIR/README.txt"

# Create ZIP
echo "Step 6: Creating Pro add-on ZIP..."
cd "$PRO_BUILD_DIR"
mkdir -p "$ROOT_DIR/build"
zip -r "$ROOT_DIR/build/${PRO_SLUG}-${VERSION}.zip" . -q

# Calculate size
PRO_SIZE=$(du -h "$ROOT_DIR/build/${PRO_SLUG}-${VERSION}.zip" | cut -f1)

echo "✅ Pro add-on created: build/${PRO_SLUG}-${VERSION}.zip ($PRO_SIZE)"

# Cleanup
rm -rf "$PRO_BUILD_DIR"

# Create installation instructions
cat > "$ROOT_DIR/build/PRO_ADDON_INSTALLATION.md" << 'EOINSTALL'
# Pro Add-on Installation Instructions

**Package:** nvdigital-oos-pro-VERSION.zip  
**Version:** VERSION  
**Type:** Commercial/Self-hosted Distribution  
**Requires:** Base plugin installed and activated

---

## Prerequisites

**IMPORTANT:** The base plugin MUST be installed first!

1. Install base plugin from WordPress.org:
   - Go to: https://wordpress.org/plugins/nvdigital-open-operator-system-oos/
   - OR: Plugins → Add New → Search "NV Digital Open Operator System"

2. Activate the base plugin

3. Verify base plugin is working:
   - Go to: WordPress Admin → NV oOS → Settings
   - Configure at least one AI provider (OpenAI, Gemini, or Ollama)
   - Test chat functionality

---

## Installation Steps

### Method 1: WordPress Admin (Recommended)

1. Go to: WordPress Admin → Plugins → Add New → Upload Plugin
2. Click "Choose File"
3. Select: `nvdigital-oos-pro-VERSION.zip`
4. Click "Install Now"
5. Click "Activate Plugin"
6. Verify: Go to NV oOS → Tools Manager (should see 70+ additional Pro tools)

### Method 2: Manual Upload

1. Extract `nvdigital-oos-pro-VERSION.zip`
2. Upload the extracted folder to: `/wp-content/plugins/`
3. Go to: WordPress Admin → Plugins
4. Find "NV Digital Open Operator System (oOS) - Pro Add-on"
5. Click "Activate"

### Method 3: WP-CLI

```bash
wp plugin install nvdigital-oos-pro-VERSION.zip --activate
```

---

## Verification

After activation, verify Pro features are available:

1. **Tool Count:**
   - Go to: NV oOS → Tools Manager
   - Should see 197+ total tools (127 base + 70 Pro)

2. **Pro Tools Visible:**
   - WooCommerce tools (if WooCommerce installed)
   - Social media tools (Facebook, Twitter, LinkedIn)
   - GitHub integration tools
   - Google services tools
   - Document generation tools (PDF, Word, Excel)

3. **Pro Badge:**
   - Look for "Pro" badges on tools in the tool manager
   - Pro tools have yellow/gold badge indicator

---

## Troubleshooting

### "Base plugin is required" Error

**Problem:** You see an error message about base plugin being required.

**Solution:**
1. Make sure base plugin is installed: `NV Digital Open Operator System (oOS)`
2. Make sure base plugin is activated
3. Deactivate and reactivate Pro add-on
4. Clear any caching plugins

### Pro Tools Not Showing

**Problem:** Only 127 tools showing, Pro tools missing.

**Solution:**
1. Deactivate Pro add-on
2. Reactivate Pro add-on
3. Go to: NV oOS → Settings → Advanced → Clear tool cache
4. Refresh page
5. Check tool count: should be 197+ tools

### Version Mismatch Warning

**Problem:** Warning about base/Pro version compatibility.

**Solution:**
1. Update base plugin to latest version
2. Update Pro add-on to matching version
3. Pro version should match or be compatible with base version

---

## Support

For technical support:
- Email: support@nvdigitalsolutions.com
- Documentation: https://nvdigitalsolutions.com/wpoos-pro/docs
- Issue Tracker: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

---

## License

This is proprietary software. All rights reserved.
Patent Pending (Application #19/410,504).

Use is subject to the license agreement provided with your purchase.

EOINSTALL

# Replace version in installation instructions
CURRENT_DATE=$(date +"%B %d, %Y")
sed -i "s/VERSION/${VERSION}/g" "$ROOT_DIR/build/PRO_ADDON_INSTALLATION.md"

echo "✅ Installation instructions created: build/PRO_ADDON_INSTALLATION.md"

echo ""
echo "=========================================="
echo "✅ Pro Add-on Build Complete!"
echo "=========================================="
echo ""
echo "📦 Package Details:"
echo "   File: build/${PRO_SLUG}-${VERSION}.zip"
echo "   Size: $PRO_SIZE"
echo "   Version: $VERSION"
echo ""
echo "📄 Documentation:"
echo "   build/PRO_ADDON_INSTALLATION.md"
echo ""
echo "⚠️  IMPORTANT:"
echo "   - This is NOT for WordPress.org submission"
echo "   - This is for commercial/self-hosted distribution"
echo "   - Requires base plugin installed first"
echo "   - Text domain transformed to match base plugin"
echo ""
echo "📤 Distribution Options:"
echo "   1. Self-hosted download on your website"
echo "   2. Email to customers"
echo "   3. Private plugin repository"
echo "   4. License management system integration"
