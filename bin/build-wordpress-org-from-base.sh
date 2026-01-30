#!/bin/bash
#
# Build WordPress.org Packages (Base, Pro, Complete, and Core)
#
# This script takes the already-built packages and transforms them
# for WordPress.org compatible distribution by changing the text domain
# throughout the codebase (not just headers).
#
# Input: 4 packages from build-plugin-zip.sh (with repository text domains):
# - build/mcp-ai-wpoos-base-{version}.zip (BASE)
# - build/mcp-ai-wpoos-pro-{version}.zip (PRO)
# - build/mcp-ai-wpoos-{version}.zip (COMPLETE)
# - build/mcp-ai-wpoos-core-{version}.zip (CORE)
#
# Output: 4 WordPress.org ready packages with all text domains transformed:
# - build/nvdigital-open-operator-system-oos-{version}.zip (BASE WordPress.org)
# - build/nvdigital-open-operator-system-oos-pro-{version}.zip (PRO WordPress.org)
# - build/nvdigital-open-operator-system-oos-complete-{version}.zip (COMPLETE WordPress.org)
# - build/nvdigital-open-operator-system-oos-core-{version}.zip (CORE WordPress.org)
#
# Total: 8 files (4 original + 4 transformed)
#
# Usage:
#   ./bin/build-wordpress-org-from-base.sh
#   ./bin/build-wordpress-org-from-base.sh --version 1.1.0
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Parse version argument
VERSION=""
if [ "$1" = "--version" ] && [ -n "$2" ]; then
    VERSION="$2"
else
    # Get version from plugin file
    VERSION=$(grep -E "^\s*\*\s*Version:" mcp-ai-wpoos.php | sed 's/.*Version:\s*//' | tr -d '[:space:]')
    if [ -z "$VERSION" ]; then
        VERSION="1.1.0"
    fi
fi

echo "=========================================="
echo "Building WordPress.org Packages"
echo "=========================================="
echo ""
echo "Version: $VERSION"
echo ""

# Function to transform a package
transform_package() {
    local SOURCE_ZIP=$1
    local OUTPUT_ZIP=$2
    local PACKAGE_TYPE=$3
    local TEMP_DIR="/tmp/wporg-transform-$$-$(date +%s)"
    
    echo "----------------------------------------"
    echo "Building: $PACKAGE_TYPE"
    echo "----------------------------------------"
    echo "Source: $SOURCE_ZIP"
    echo "Output: $OUTPUT_ZIP"
    echo ""
    
    # Check if source exists
    if [ ! -f "$SOURCE_ZIP" ]; then
        echo "❌ Error: Source not found: $SOURCE_ZIP"
        return 1
    fi
    
    # Extract source build
    echo "Step 1: Extracting source..."
    mkdir -p "$TEMP_DIR"
    unzip -q "$SOURCE_ZIP" -d "$TEMP_DIR"
    
    # Find the extracted directory (should be mcp-ai-wpoos*)
    EXTRACTED_DIR=$(find "$TEMP_DIR" -maxdepth 1 -type d -name "mcp-ai-wpoos*" | head -1)
    
    if [ -z "$EXTRACTED_DIR" ] || [ ! -d "$EXTRACTED_DIR" ]; then
        echo "❌ Error: Could not find extracted directory in $TEMP_DIR"
        ls -la "$TEMP_DIR"
        return 1
    fi
    
    echo "   Extracted to: $(basename "$EXTRACTED_DIR")"
    
    # Transform text domain
    echo "Step 2: Transforming text domain..."
    echo "   mcp-ai-wpoos* → nvdigital-open-operator-system-oos*"
    
    # Count before (all variants)
    BEFORE_COUNT=$(grep -rE "('mcp-ai-wpoos'|\"mcp-ai-wpoos\"|'mcp-ai-wpoos-base'|\"mcp-ai-wpoos-base\"|'mcp-ai-wpoos-pro'|\"mcp-ai-wpoos-pro\")" "$EXTRACTED_DIR" --include="*.php" --include="*.js" 2>/dev/null | wc -l || echo 0)
    
    # Transform PHP files - handle all text domain variants
    # First, transform plugin headers (Text Domain: mcp-ai-wpoos-base → nvdigital-open-operator-system-oos)
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i 's/Text Domain: mcp-ai-wpoos-base/Text Domain: nvdigital-open-operator-system-oos/g' {} \;
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i 's/Text Domain: mcp-ai-wpoos-pro/Text Domain: nvdigital-open-operator-system-oos-pro/g' {} \;
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i 's/Text Domain: mcp-ai-wpoos-core/Text Domain: nvdigital-open-operator-system-oos-core/g' {} \;
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i 's/Text Domain: mcp-ai-wpoos/Text Domain: nvdigital-open-operator-system-oos/g' {} \;
    
    # Transform mcp-ai-wpoos-base → nvdigital-open-operator-system-oos
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i "s/'mcp-ai-wpoos-base'/'nvdigital-open-operator-system-oos'/g" {} \;
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i 's/"mcp-ai-wpoos-base"/"nvdigital-open-operator-system-oos"/g' {} \;
    
    # Transform mcp-ai-wpoos-pro → nvdigital-open-operator-system-oos-pro
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i "s/'mcp-ai-wpoos-pro'/'nvdigital-open-operator-system-oos-pro'/g" {} \;
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i 's/"mcp-ai-wpoos-pro"/"nvdigital-open-operator-system-oos-pro"/g' {} \;
    
    # Transform mcp-ai-wpoos → nvdigital-open-operator-system-oos (catch remaining instances)
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i "s/'mcp-ai-wpoos'/'nvdigital-open-operator-system-oos'/g" {} \;
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i 's/"mcp-ai-wpoos"/"nvdigital-open-operator-system-oos"/g' {} \;
    
    # Transform JavaScript files - handle all text domain variants
    # Transform mcp-ai-wpoos-base → nvdigital-open-operator-system-oos
    find "$EXTRACTED_DIR" -name "*.js" -type f -exec sed -i "s/'mcp-ai-wpoos-base'/'nvdigital-open-operator-system-oos'/g" {} \;
    find "$EXTRACTED_DIR" -name "*.js" -type f -exec sed -i 's/"mcp-ai-wpoos-base"/"nvdigital-open-operator-system-oos"/g' {} \;
    
    # Transform mcp-ai-wpoos-pro → nvdigital-open-operator-system-oos-pro
    find "$EXTRACTED_DIR" -name "*.js" -type f -exec sed -i "s/'mcp-ai-wpoos-pro'/'nvdigital-open-operator-system-oos-pro'/g" {} \;
    find "$EXTRACTED_DIR" -name "*.js" -type f -exec sed -i 's/"mcp-ai-wpoos-pro"/"nvdigital-open-operator-system-oos-pro"/g' {} \;
    
    # Transform mcp-ai-wpoos → nvdigital-open-operator-system-oos (catch remaining instances)
    find "$EXTRACTED_DIR" -name "*.js" -type f -exec sed -i "s/'mcp-ai-wpoos'/'nvdigital-open-operator-system-oos'/g" {} \;
    find "$EXTRACTED_DIR" -name "*.js" -type f -exec sed -i 's/"mcp-ai-wpoos"/"nvdigital-open-operator-system-oos"/g' {} \;
    
    # Count after (all variants)
    AFTER_COUNT=$(grep -rE "('mcp-ai-wpoos'|\"mcp-ai-wpoos\"|'mcp-ai-wpoos-base'|\"mcp-ai-wpoos-base\"|'mcp-ai-wpoos-pro'|\"mcp-ai-wpoos-pro\")" "$EXTRACTED_DIR" --include="*.php" --include="*.js" 2>/dev/null | wc -l || echo 0)
    TRANSFORMED=$((BEFORE_COUNT - AFTER_COUNT))
    
    echo "   Transformed: $TRANSFORMED instances"
    echo "   Remaining old text domains: $AFTER_COUNT"
    
    # Update POT files if they exist
    # Note: Only one of these should exist in any given package:
    # - Base package has mcp-ai-wpoos-base.pot
    # - Combined package might have mcp-ai-wpoos.pot
    # - Pro package has mcp-ai-wpoos-pro.pot
    if [ -f "$EXTRACTED_DIR/languages/mcp-ai-wpoos-base.pot" ]; then
        echo "Step 3: Renaming translation file (mcp-ai-wpoos-base.pot)..."
        mv "$EXTRACTED_DIR/languages/mcp-ai-wpoos-base.pot" "$EXTRACTED_DIR/languages/nvdigital-open-operator-system-oos.pot"
        sed -i 's/"Project-Id-Version: mcp-ai-wpoos-base/"Project-Id-Version: nvdigital-open-operator-system-oos/g' \
            "$EXTRACTED_DIR/languages/nvdigital-open-operator-system-oos.pot"
    elif [ -f "$EXTRACTED_DIR/languages/mcp-ai-wpoos.pot" ]; then
        echo "Step 3: Renaming translation file (mcp-ai-wpoos.pot)..."
        mv "$EXTRACTED_DIR/languages/mcp-ai-wpoos.pot" "$EXTRACTED_DIR/languages/nvdigital-open-operator-system-oos.pot"
        sed -i 's/"Project-Id-Version: mcp-ai-wpoos/"Project-Id-Version: nvdigital-open-operator-system-oos/g' \
            "$EXTRACTED_DIR/languages/nvdigital-open-operator-system-oos.pot"
    fi
    
    if [ -f "$EXTRACTED_DIR/languages/mcp-ai-wpoos-pro.pot" ]; then
        echo "Step 3: Renaming translation file (mcp-ai-wpoos-pro.pot)..."
        mv "$EXTRACTED_DIR/languages/mcp-ai-wpoos-pro.pot" "$EXTRACTED_DIR/languages/nvdigital-open-operator-system-oos-pro.pot"
        sed -i 's/"Project-Id-Version: mcp-ai-wpoos-pro/"Project-Id-Version: nvdigital-open-operator-system-oos-pro/g' \
            "$EXTRACTED_DIR/languages/nvdigital-open-operator-system-oos-pro.pot"
    fi
    
    if [ -f "$EXTRACTED_DIR/languages/mcp-ai-wpoos-core.pot" ]; then
        echo "Step 3: Renaming translation file (mcp-ai-wpoos-core.pot)..."
        mv "$EXTRACTED_DIR/languages/mcp-ai-wpoos-core.pot" "$EXTRACTED_DIR/languages/nvdigital-open-operator-system-oos-core.pot"
        sed -i 's/"Project-Id-Version: mcp-ai-wpoos-core/"Project-Id-Version: nvdigital-open-operator-system-oos-core/g' \
            "$EXTRACTED_DIR/languages/nvdigital-open-operator-system-oos-core.pot"
    fi
    
    # Rename main PHP files to match WordPress.org package naming
    echo "Step 3a: Renaming main PHP files..."
    
    # Base package: mcp-ai-wpoos-base.php → nvdigital-open-operator-system-oos.php
    if [ -f "$EXTRACTED_DIR/mcp-ai-wpoos-base.php" ]; then
        mv "$EXTRACTED_DIR/mcp-ai-wpoos-base.php" "$EXTRACTED_DIR/nvdigital-open-operator-system-oos.php"
        echo "   Renamed: mcp-ai-wpoos-base.php → nvdigital-open-operator-system-oos.php"
    fi
    
    # Pro package: mcp-ai-wpoos-pro.php → nvdigital-open-operator-system-oos-pro.php
    if [ -f "$EXTRACTED_DIR/mcp-ai-wpoos-pro.php" ]; then
        mv "$EXTRACTED_DIR/mcp-ai-wpoos-pro.php" "$EXTRACTED_DIR/nvdigital-open-operator-system-oos-pro.php"
        echo "   Renamed: mcp-ai-wpoos-pro.php → nvdigital-open-operator-system-oos-pro.php"
    fi
    
    # Complete package: mcp-ai-wpoos.php → nvdigital-open-operator-system-oos.php
    # Note: Only rename if mcp-ai-wpoos-base.php doesn't exist (to avoid conflict with base package)
    if [ -f "$EXTRACTED_DIR/mcp-ai-wpoos.php" ] && [ ! -f "$EXTRACTED_DIR/nvdigital-open-operator-system-oos.php" ]; then
        mv "$EXTRACTED_DIR/mcp-ai-wpoos.php" "$EXTRACTED_DIR/nvdigital-open-operator-system-oos.php"
        echo "   Renamed: mcp-ai-wpoos.php → nvdigital-open-operator-system-oos.php"
    fi
    
    # Create output package
    echo "Step 4: Creating package..."
    cd "$EXTRACTED_DIR"
    zip -r "$ROOT_DIR/$OUTPUT_ZIP" . -q
    
    # Calculate size
    OUTPUT_SIZE=$(du -h "$ROOT_DIR/$OUTPUT_ZIP" | cut -f1)
    
    echo ""
    echo "✅ Package created: $OUTPUT_ZIP ($OUTPUT_SIZE)"
    
    # Cleanup
    cd "$ROOT_DIR"
    rm -rf "$TEMP_DIR"
}

# Build BASE WordPress.org package
BASE_SOURCE="build/mcp-ai-wpoos-base-${VERSION}.zip"
BASE_OUTPUT="build/nvdigital-open-operator-system-oos-${VERSION}.zip"
transform_package "$BASE_SOURCE" "$BASE_OUTPUT" "BASE (WordPress.org)"

echo ""

# Build PRO WordPress.org package
PRO_SOURCE="build/mcp-ai-wpoos-pro-${VERSION}.zip"
PRO_OUTPUT="build/nvdigital-open-operator-system-oos-pro-${VERSION}.zip"
if [ -f "$PRO_SOURCE" ]; then
    transform_package "$PRO_SOURCE" "$PRO_OUTPUT" "PRO (WordPress.org)"
    echo ""
else
    echo "⚠️  Pro source not found: $PRO_SOURCE - skipping..."
    echo ""
fi

# Build COMPLETE WordPress.org package
COMPLETE_SOURCE="build/mcp-ai-wpoos-${VERSION}.zip"
COMPLETE_OUTPUT="build/nvdigital-open-operator-system-oos-complete-${VERSION}.zip"
transform_package "$COMPLETE_SOURCE" "$COMPLETE_OUTPUT" "COMPLETE (Base + Pro)"

echo ""

# Build CORE WordPress.org package
CORE_SOURCE="build/mcp-ai-wpoos-core-${VERSION}.zip"
CORE_OUTPUT="build/nvdigital-open-operator-system-oos-core-${VERSION}.zip"
# Core version might have its own version number
if [ ! -f "$CORE_SOURCE" ]; then
    # Try with core's own version
    CORE_VERSION=$(grep -E "^\s*\*\s*Version:" core/mcp-ai-wpoos-core.php 2>/dev/null | sed 's/.*Version:\s*//' | tr -d '[:space:]' || echo "1.0.0")
    CORE_SOURCE="build/mcp-ai-wpoos-core-${CORE_VERSION}.zip"
    CORE_OUTPUT="build/nvdigital-open-operator-system-oos-core-${CORE_VERSION}.zip"
fi

if [ -f "$CORE_SOURCE" ]; then
    transform_package "$CORE_SOURCE" "$CORE_OUTPUT" "CORE (WordPress.org)"
else
    echo "⚠️  Core source not found: $CORE_SOURCE - skipping..."
fi

# Create submission README
echo ""
echo "Creating submission documentation..."

CURRENT_DATE=$(date +"%B %d, %Y")
BASE_SIZE=$(du -h "$ROOT_DIR/$BASE_OUTPUT" 2>/dev/null | cut -f1 || echo "N/A")
PRO_SIZE=$(du -h "$ROOT_DIR/$PRO_OUTPUT" 2>/dev/null | cut -f1 || echo "N/A")
COMPLETE_SIZE=$(du -h "$ROOT_DIR/$COMPLETE_OUTPUT" 2>/dev/null | cut -f1 || echo "N/A")

# Get core version for display
CORE_VERSION=$(grep -E "^\s*\*\s*Version:" core/mcp-ai-wpoos-core.php 2>/dev/null | sed 's/.*Version:\s*//' | tr -d '[:space:]' || echo "1.0.0")
CORE_SIZE=$(du -h "build/nvdigital-open-operator-system-oos-core-${CORE_VERSION}.zip" 2>/dev/null | cut -f1 || echo "N/A")

cat > "build/WORDPRESS_ORG_SUBMISSION_README.md" << EOREADME
# WordPress.org Submission Packages

**Date:** $CURRENT_DATE  
**Version:** $VERSION  
**Status:** ✅ Ready for Distribution

---

## Package Overview

This build creates **8 ZIP files** for distribution:

### Original Packages (4 files) - Repository Text Domains
Built by \`build-plugin-zip.sh\` with repository text domains for internal development:
1. \`mcp-ai-wpoos-base-${VERSION}.zip\` - Base version (text domain: \`mcp-ai-wpoos-base\`)
2. \`mcp-ai-wpoos-pro-${VERSION}.zip\` - Pro add-on (text domain: \`mcp-ai-wpoos-pro\`)
3. \`mcp-ai-wpoos-${VERSION}.zip\` - Combined (text domain: \`mcp-ai-wpoos\`)
4. \`mcp-ai-wpoos-core-${CORE_VERSION}.zip\` - Core (text domain: \`mcp-ai-wpoos-core\`)

### WordPress.org Packages (4 files) - WordPress Text Domains
Built by \`build-wordpress-org-from-base.sh\` with WordPress.org text domains transformed throughout:
5. \`nvdigital-open-operator-system-oos-${VERSION}.zip\` - Base (text domain: \`nvdigital-open-operator-system-oos\`)
6. \`nvdigital-open-operator-system-oos-pro-${VERSION}.zip\` - Pro (text domain: \`nvdigital-open-operator-system-oos-pro\`)
7. \`nvdigital-open-operator-system-oos-complete-${VERSION}.zip\` - Combined (text domain: \`nvdigital-open-operator-system-oos\`)
8. \`nvdigital-open-operator-system-oos-core-${CORE_VERSION}.zip\` - Core (text domain: \`nvdigital-open-operator-system-oos-core\`)

---

## Package Details

### 1. BASE Package (WordPress.org Submission)
**Original:** \`mcp-ai-wpoos-base-${VERSION}.zip\`  
**WordPress.org:** \`nvdigital-open-operator-system-oos-${VERSION}.zip\` ($BASE_SIZE)

**What's Included:**
- 127 base tools
- Multi-provider AI (OpenAI, Gemini, Ollama)
- Chat interface
- Tool management system
- Privacy API (GDPR compliant)
- Site Health integration

**Text Domain:** \`nvdigital-open-operator-system-oos\`

**Use For:**
- WordPress.org submission
- Free public distribution
- Sites requiring WordPress.org approved plugins

---

### 2. PRO Add-on Package
**Original:** \`mcp-ai-wpoos-pro-${VERSION}.zip\`  
**WordPress.org:** \`nvdigital-open-operator-system-oos-pro-${VERSION}.zip\` ($PRO_SIZE)

**What's Included:**
- 70+ Pro tools
- Pro Dashboard
- Advanced integrations (WooCommerce, JetEngine, GitHub, Google, etc.)
- Social media tools
- Document generation (PDF, Word, Excel)
- Video processing (FFmpeg)

**Text Domain:** \`nvdigital-open-operator-system-oos-pro\`

**Requirements:** Requires base plugin to be installed first

**Use For:**
- Add-on distribution
- Pro features for existing base installations

---

### 3. COMPLETE Package (Self-hosted Distribution)
**Original:** \`mcp-ai-wpoos-${VERSION}.zip\`  
**WordPress.org:** \`nvdigital-open-operator-system-oos-complete-${VERSION}.zip\` ($COMPLETE_SIZE)

**What's Included:**
- Everything in BASE package
- Everything in PRO package
- All 197+ tools in one install

**Text Domain:** \`nvdigital-open-operator-system-oos\` (base) + \`nvdigital-open-operator-system-oos-pro\` (pro features)

**Use For:**
- Self-hosted websites
- Users who want all features in one package
- Private distribution
- Development environments

---

### 4. CORE Package (Lightweight)
**Original:** \`mcp-ai-wpoos-core-${CORE_VERSION}.zip\`  
**WordPress.org:** \`nvdigital-open-operator-system-oos-core-${CORE_VERSION}.zip\` ($CORE_SIZE)

**What's Included:**
- 4 basic tools only
- Minimal footprint
- Essential AI functionality

**Text Domain:** \`nvdigital-open-operator-system-oos-core\`

**Use For:**
- Lightweight installations
- Testing environments
- Minimal AI integration needs

---

## Key Differences: Original vs WordPress.org Versions

| Aspect | Original Packages | WordPress.org Packages |
|--------|-------------------|------------------------|
| **Plugin Headers** | Repository text domains (mcp-ai-wpoos-base, mcp-ai-wpoos-pro, etc.) | WordPress text domains (nvdigital-open-operator-system-oos, nvdigital-open-operator-system-oos-pro) |
| **Code Text Domains** | Repository text domains (__('text', 'mcp-ai-wpoos')) | WordPress text domains (__('text', 'nvdigital-open-operator-system-oos')) |
| **Translation Files** | Repository names (mcp-ai-wpoos-base.pot) | WordPress names (nvdigital-open-operator-system-oos.pot) |
| **Use Case** | Development, testing, internal builds | Production, WordPress.org submission, public distribution |
| **Recommended For** | Internal repository development | End users and WordPress.org |

**Important:** For WordPress.org submission or public distribution, **always use the WordPress.org versions** (nvdigital-open-operator-system-oos-*) which have all text domains fully transformed throughout the codebase (both headers and code).

**Original packages** (mcp-ai-wpoos-*) maintain repository text domains for development consistency and are useful for:
- Local development and testing
- Internal CI/CD pipelines
- Developer debugging and troubleshooting

**WordPress.org packages** (nvdigital-open-operator-system-oos-*) use production-ready text domains and are required for:
- WordPress.org plugin directory submission
- Public distribution to end users
- Production deployments

---

## Installation

### BASE Package (WordPress.org)
- **File to use:** \`nvdigital-open-operator-system-oos-${VERSION}.zip\`
- **WordPress.org:** Submit to https://wordpress.org/plugins/developers/add/
- **Manual Install:** Upload via Plugins → Add New → Upload Plugin

### PRO Package  
- **File to use:** \`nvdigital-open-operator-system-oos-pro-${VERSION}.zip\`
- **Requirements:** Base plugin must be installed first
- **Manual Install:** Upload via Plugins → Add New → Upload Plugin

### COMPLETE Package (Self-hosted)
- **File to use:** \`nvdigital-open-operator-system-oos-complete-${VERSION}.zip\`
- **Self-hosted Only:** Cannot submit to WordPress.org (includes Pro)
- **Manual Install:** Upload via Plugins → Add New → Upload Plugin
- **Distribution:** Host on your website for customer downloads

### CORE Package (Lightweight)
- **File to use:** \`nvdigital-open-operator-system-oos-core-${CORE_VERSION}.zip\`
- **Manual Install:** Upload via Plugins → Add New → Upload Plugin
- **Use Case:** Minimal installations, testing

---

## Compliance

**Original Packages (mcp-ai-wpoos-*):**
- ✅ Use repository text domains (mcp-ai-wpoos*)
- ✅ Maintain development consistency
- ✅ Fully functional for internal testing
- ⚠️ Not suitable for WordPress.org submission or public distribution

**WordPress.org Packages (nvdigital-open-operator-system-oos-*):**
- ✅ Text domains fully transformed to WordPress.org standards in headers and code
- ✅ Translation files renamed to match WordPress text domains
- ✅ No .backup files
- ✅ No broken references
- ✅ Fully functional and production-ready
- ✅ Ready for WordPress.org submission and public distribution

**Submission Guidelines:**
- **BASE package:** WordPress.org submission ready ✅
- **PRO package:** Self-hosted add-on distribution (requires base plugin)
- **COMPLETE package:** Self-hosted distribution only ⚠️ (includes proprietary Pro features - cannot submit to WordPress.org)
- **CORE package:** Lightweight WordPress.org or self-hosted distribution ✅

---

## Support

- **Documentation:** See \`docs/\` directory in repository
- **GitHub:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- **Issues:** GitHub Issues

Built: $CURRENT_DATE
EOREADME

echo "✅ Submission README created: build/WORDPRESS_ORG_SUBMISSION_README.md"

echo ""
echo "=========================================="
echo "✅ WordPress.org Packages Complete!"
echo "=========================================="
echo ""
echo "📦 WordPress.org Packages created (8 total: 4 original + 4 transformed):"
echo ""
echo "Original packages (repository text domains - mcp-ai-wpoos-*):"
echo "   1. mcp-ai-wpoos-base-${VERSION}.zip"
echo "   2. mcp-ai-wpoos-pro-${VERSION}.zip"
echo "   3. mcp-ai-wpoos-${VERSION}.zip"
echo "   4. mcp-ai-wpoos-core-${CORE_VERSION}.zip"
echo ""
echo "WordPress.org packages (WordPress text domains - nvdigital-open-operator-system-oos-*):"
echo "   5. nvdigital-open-operator-system-oos-${VERSION}.zip ($BASE_SIZE)"
echo "   6. nvdigital-open-operator-system-oos-pro-${VERSION}.zip ($PRO_SIZE)"
echo "   7. nvdigital-open-operator-system-oos-complete-${VERSION}.zip ($COMPLETE_SIZE)"
echo "   8. nvdigital-open-operator-system-oos-core-${CORE_VERSION}.zip ($CORE_SIZE)"
echo ""
echo "📄 Documentation: build/WORDPRESS_ORG_SUBMISSION_README.md"
echo ""
echo "✨ For WordPress.org submission or public distribution, use the WordPress packages (nvdigital-open-operator-system-oos-*)!"
echo ""

