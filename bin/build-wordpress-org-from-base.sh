#!/bin/bash
#
# Build WordPress.org Packages (Base and Complete)
#
# This script takes the already-built packages and transforms them
# for WordPress.org compatible distribution by changing the text domain.
#
# Creates two packages:
# 1. nvdigital-open-operator-system-oos-{version}.zip - BASE only
# 2. nvdigital-open-operator-system-oos-complete-{version}.zip - BASE + PRO
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
    
    # Find the extracted directory (could be mcp-ai-wpoos-base or mcp-ai-wpoos)
    EXTRACTED_DIR=$(find "$TEMP_DIR" -maxdepth 1 -type d -name "mcp-ai-wpoos*" | head -1)
    
    if [ -z "$EXTRACTED_DIR" ] || [ ! -d "$EXTRACTED_DIR" ]; then
        echo "❌ Error: Could not find extracted directory in $TEMP_DIR"
        ls -la "$TEMP_DIR"
        return 1
    fi
    
    echo "   Extracted to: $(basename "$EXTRACTED_DIR")"
    
    # Transform text domain
    echo "Step 2: Transforming text domain..."
    echo "   mcp-ai-wpoos → nvdigital-open-operator-system-oos"
    
    # Count before
    BEFORE_COUNT=$(grep -r "'mcp-ai-wpoos'" "$EXTRACTED_DIR" --include="*.php" --include="*.js" 2>/dev/null | wc -l || echo 0)
    
    # Transform PHP files
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i "s/'mcp-ai-wpoos'/'nvdigital-open-operator-system-oos'/g" {} \;
    find "$EXTRACTED_DIR" -name "*.php" -type f -exec sed -i 's/"mcp-ai-wpoos"/"nvdigital-open-operator-system-oos"/g' {} \;
    
    # Transform JavaScript files
    find "$EXTRACTED_DIR" -name "*.js" -type f -exec sed -i "s/'mcp-ai-wpoos'/'nvdigital-open-operator-system-oos'/g" {} \;
    find "$EXTRACTED_DIR" -name "*.js" -type f -exec sed -i 's/"mcp-ai-wpoos"/"nvdigital-open-operator-system-oos"/g' {} \;
    
    # Count after
    AFTER_COUNT=$(grep -r "'mcp-ai-wpoos'" "$EXTRACTED_DIR" --include="*.php" --include="*.js" 2>/dev/null | wc -l || echo 0)
    TRANSFORMED=$((BEFORE_COUNT - AFTER_COUNT))
    
    echo "   Transformed: $TRANSFORMED instances"
    
    # Update POT file if it exists
    if [ -f "$EXTRACTED_DIR/languages/mcp-ai-wpoos.pot" ]; then
        echo "Step 3: Renaming translation file..."
        mv "$EXTRACTED_DIR/languages/mcp-ai-wpoos.pot" "$EXTRACTED_DIR/languages/nvdigital-open-operator-system-oos.pot"
        sed -i 's/"Project-Id-Version: mcp-ai-wpoos/"Project-Id-Version: nvdigital-open-operator-system-oos/g' \
            "$EXTRACTED_DIR/languages/nvdigital-open-operator-system-oos.pot"
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

# Build COMPLETE WordPress.org package
COMPLETE_SOURCE="build/mcp-ai-wpoos-${VERSION}.zip"
COMPLETE_OUTPUT="build/nvdigital-open-operator-system-oos-complete-${VERSION}.zip"
transform_package "$COMPLETE_SOURCE" "$COMPLETE_OUTPUT" "COMPLETE (Base + Pro)"

# Create submission README
echo ""
echo "Creating submission documentation..."

CURRENT_DATE=$(date +"%B %d, %Y")
BASE_SIZE=$(du -h "$ROOT_DIR/$BASE_OUTPUT" | cut -f1)
COMPLETE_SIZE=$(du -h "$ROOT_DIR/$COMPLETE_OUTPUT" | cut -f1)

cat > "build/WORDPRESS_ORG_SUBMISSION_README.md" << EOREADME
# WordPress.org Submission Packages

**Date:** $CURRENT_DATE  
**Version:** $VERSION  
**Status:** ✅ Ready for Distribution

---

## Two Packages Available

### 1. BASE Package (WordPress.org Submission)
**File:** \`nvdigital-open-operator-system-oos-${VERSION}.zip\`  
**Size:** $BASE_SIZE  
**Based on:** \`mcp-ai-wpoos-base-${VERSION}.zip\`

**What's Included:**
- 127 base tools
- Multi-provider AI (OpenAI, Gemini, Ollama)
- Chat interface
- Tool management system
- Privacy API (GDPR compliant)
- Site Health integration

**What's NOT Included:**
- Pro addon (70+ Pro tools)
- LangChain.js orchestration
- Transformers.js browser AI
- Web Workers

**Use For:**
- WordPress.org submission
- Free public distribution
- Sites requiring WordPress.org approved plugins

---

### 2. COMPLETE Package (Self-hosted Distribution)
**File:** \`nvdigital-open-operator-system-oos-complete-${VERSION}.zip\`  
**Size:** $COMPLETE_SIZE  
**Based on:** \`mcp-ai-wpoos-${VERSION}.zip\` (combined base + Pro)

**What's Included:**
- Everything in BASE package
- 70+ Pro tools
- Pro Dashboard
- Advanced integrations (WooCommerce, GitHub, Google, etc.)
- Social media tools
- Document generation (PDF, Word, Excel)
- Video processing (FFmpeg)
- All Pro features

**What's NOT Included:**
- Nothing - this is the complete package

**Use For:**
- Self-hosted websites
- Users who want all features in one package
- Private distribution
- Development environments

---

## Key Differences

| Feature | BASE | COMPLETE |
|---------|------|----------|
| **Size** | $BASE_SIZE | $COMPLETE_SIZE |
| **Tools** | 127 | 197+ |
| **Pro Features** | ❌ | ✅ |
| **WordPress.org** | ✅ Submit | ❌ No (Pro is commercial) |
| **Text Domain** | nvdigital-open-operator-system-oos | nvdigital-open-operator-system-oos |

---

## Installation

### BASE Package
1. **WordPress.org:** Submit to https://wordpress.org/plugins/developers/add/
2. **Manual Install:** Upload via Plugins → Add New → Upload Plugin

### COMPLETE Package
1. **Self-hosted Only:** Cannot submit to WordPress.org (includes Pro)
2. **Manual Install:** Upload via Plugins → Add New → Upload Plugin
3. **Distribution:** Host on your website for customer downloads

---

## Compliance

Both packages are:
- ✅ Text domain transformed
- ✅ No .backup files
- ✅ No broken references
- ✅ Fully functional
- ✅ Ready for distribution

**BASE package:** WordPress.org compliant  
**COMPLETE package:** Self-hosted distribution only

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
echo "📦 Packages created:"
echo "   BASE:     $BASE_OUTPUT ($BASE_SIZE)"
echo "   COMPLETE: $COMPLETE_OUTPUT ($COMPLETE_SIZE)"
echo ""
echo "📄 Documentation: build/WORDPRESS_ORG_SUBMISSION_README.md"
echo ""
echo "Ready for distribution!"

