#!/bin/bash
#
# Prepare plugin for WordPress.org deployment
# 
# This script transforms the plugin code to meet WordPress.org requirements
# while keeping the repository code unchanged.
#
# Usage: ./bin/prepare-wordpress-org-deploy.sh [output-dir]

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
OUTPUT_DIR="${1:-/tmp/mcp-ai-wpoos-wporg}"

echo "🔧 Preparing WordPress.org deployment..."
echo "   Source: $PLUGIN_DIR"
echo "   Output: $OUTPUT_DIR"

# Create clean output directory
if [ -d "$OUTPUT_DIR" ]; then
    echo "   Cleaning existing output directory..."
    rm -rf "$OUTPUT_DIR"
fi

mkdir -p "$OUTPUT_DIR"

# Copy plugin files (respecting .distignore)
echo "📦 Copying plugin files..."
rsync -av --exclude-from="$PLUGIN_DIR/.distignore" \
    --exclude='.git' \
    --exclude='.git-branch-info' \
    --exclude='bin/prepare-wordpress-org-deploy.sh' \
    "$PLUGIN_DIR/" "$OUTPUT_DIR/"

# Transform text domain for WordPress.org compliance
echo "🔄 Transforming text domain: mcp-ai-wpoos → nvdigital-open-operator-system-oos"

# Count instances before
BEFORE_COUNT=$(grep -r "'mcp-ai-wpoos'" "$OUTPUT_DIR" --include="*.php" | wc -l)
echo "   Found $BEFORE_COUNT instances in PHP files"

# PHP files
find "$OUTPUT_DIR" -name "*.php" -type f -exec sed -i "s/'mcp-ai-wpoos'/'nvdigital-open-operator-system-oos'/g" {} \;
find "$OUTPUT_DIR" -name "*.php" -type f -exec sed -i 's/"mcp-ai-wpoos"/"nvdigital-open-operator-system-oos"/g' {} \;

# JavaScript files (for wp.i18n)
find "$OUTPUT_DIR" -name "*.js" -type f -exec sed -i "s/'mcp-ai-wpoos'/'nvdigital-open-operator-system-oos'/g" {} \;
find "$OUTPUT_DIR" -name "*.js" -type f -exec sed -i 's/"mcp-ai-wpoos"/"nvdigital-open-operator-system-oos"/g' {} \;

# Count instances after
AFTER_COUNT=$(grep -r "'mcp-ai-wpoos'" "$OUTPUT_DIR" --include="*.php" | wc -l)
echo "   Remaining instances: $AFTER_COUNT"
echo "   Transformed: $((BEFORE_COUNT - AFTER_COUNT)) instances"

# Update POT file if it exists
if [ -f "$OUTPUT_DIR/languages/mcp-ai-wpoos.pot" ]; then
    echo "📝 Renaming translation file..."
    mv "$OUTPUT_DIR/languages/mcp-ai-wpoos.pot" "$OUTPUT_DIR/languages/nvdigital-open-operator-system-oos.pot"
    
    # Update text domain in POT file
    sed -i 's/"Project-Id-Version: mcp-ai-wpoos/"Project-Id-Version: nvdigital-open-operator-system-oos/g' \
        "$OUTPUT_DIR/languages/nvdigital-open-operator-system-oos.pot"
fi

# Verify critical files
echo "✅ Verifying deployment package..."

ERRORS=0

# Check readme.txt
if [ ! -f "$OUTPUT_DIR/readme.txt" ]; then
    echo "   ❌ ERROR: readme.txt missing"
    ERRORS=$((ERRORS + 1))
fi

# Check main plugin file
if [ ! -f "$OUTPUT_DIR/mcp-ai-wpoos.php" ]; then
    echo "   ❌ ERROR: Main plugin file missing"
    ERRORS=$((ERRORS + 1))
fi

# Check that addons directory is excluded
if [ -d "$OUTPUT_DIR/addons" ]; then
    echo "   ❌ ERROR: addons directory should be excluded"
    ERRORS=$((ERRORS + 1))
fi

# Check that node_modules is excluded
if [ -d "$OUTPUT_DIR/node_modules" ]; then
    echo "   ❌ ERROR: node_modules should be excluded"
    ERRORS=$((ERRORS + 1))
fi

# Check text domain transformation
REMAINING=$(grep -r "'mcp-ai-wpoos'" "$OUTPUT_DIR" --include="*.php" --include="*.js" | wc -l)
if [ "$REMAINING" -gt 0 ]; then
    echo "   ⚠️  WARNING: $REMAINING instances of old text domain remain"
    echo "   Showing first 5:"
    grep -r "'mcp-ai-wpoos'" "$OUTPUT_DIR" --include="*.php" --include="*.js" -n | head -5
fi

if [ $ERRORS -gt 0 ]; then
    echo ""
    echo "❌ Deployment preparation failed with $ERRORS errors"
    exit 1
fi

# Calculate package size
SIZE=$(du -sh "$OUTPUT_DIR" | cut -f1)
echo ""
echo "✅ Deployment package ready!"
echo "   Location: $OUTPUT_DIR"
echo "   Size: $SIZE"
echo ""
echo "Next steps:"
echo "  1. Review changes: cd $OUTPUT_DIR && git diff"
echo "  2. Test the plugin: wp plugin install --activate $OUTPUT_DIR"
echo "  3. Deploy to SVN: wp dist-archive $OUTPUT_DIR"
echo ""
echo "Note: Your repository code remains unchanged."
