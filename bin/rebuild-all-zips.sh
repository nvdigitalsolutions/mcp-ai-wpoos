#!/bin/bash
#
# Rebuild All ZIPs
#
# Convenience script to rebuild all plugin ZIP files:
# - Base version (standalone)
# - Pro add-on
# - Combined (base + pro)
# - Core plugin (lightweight)
# - WordPress.org compliant package (with CDN exclusions and text domain transformation)
#
# Usage:
#   ./bin/rebuild-all-zips.sh                          # Rebuild all versions
#   ./bin/rebuild-all-zips.sh --version 1.0.0          # Specify version
#   ./bin/rebuild-all-zips.sh --skip-npm-build         # Use pre-built assets
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Parse arguments
VERSION_ARG=""
SKIP_NPM_ARG=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --version)
            VERSION_ARG="--version $2"
            VERSION="$2"
            shift 2
            ;;
        --skip-npm-build)
            SKIP_NPM_ARG="--skip-npm-build"
            shift
            ;;
        *)
            shift
            ;;
    esac
done

if [ -z "$VERSION" ]; then
    # Get version from plugin file
    VERSION=$(grep -E "^\s*\*\s*Version:" mcp-ai-wpoos.php | sed 's/.*Version:\s*//' | tr -d '[:space:]')
    if [ -z "$VERSION" ]; then
        VERSION="dev"
    fi
fi

echo "=========================================="
echo "Rebuilding All Plugin ZIPs (v${VERSION})"
echo "=========================================="
echo ""

# Step 0: Rebuild all CSS/JS assets (full build including workflow builder and TMA components)
if [ -n "$SKIP_NPM_ARG" ]; then
    echo "Step 0: Skipping CSS/JS rebuild (--skip-npm-build flag set — using pre-built assets)..."
    echo "✅ Using pre-built frontend assets"
else
    echo "Step 0: Rebuilding all CSS/JS assets..."
    npm ci --silent 2>/dev/null || npm install --silent
    npm run build:full
    echo "✅ All CSS/JS assets rebuilt"
fi
echo ""

# Build all versions using the build-plugin-zip.sh script
# Use --all flag for base, pro, combined, toolkits, and also add --core-only
# Always pass --skip-npm-build since we already ran the full build above
"$SCRIPT_DIR/build-plugin-zip.sh" --all --core-only $VERSION_ARG --skip-npm-build

echo ""
echo "=========================================="
echo "Building WordPress.org Compliant Package"
echo "=========================================="
echo ""

# Build WordPress.org package from base build (ensures identical functionality)
echo "Creating WordPress.org package from base build..."
"$SCRIPT_DIR/build-wordpress-org-from-base.sh" --version "$VERSION"

echo ""
echo "=========================================="
echo "✅ All ZIPs rebuilt successfully!"
echo "=========================================="
echo ""
echo "📦 Build output in build/:"
ls -lh "$ROOT_DIR/build/"*.zip | awk '{print "   " $9 " (" $5 ")"}'
echo ""
if [ -d "$ROOT_DIR/build/toolkit-addons" ]; then
    echo "📦 Toolkit add-ons in build/toolkit-addons/:"
    ls -lh "$ROOT_DIR/build/toolkit-addons/"*.zip 2>/dev/null | awk '{print "   " $9 " (" $5 ")"}'
    TOOLKIT_COUNT=$(ls -1 "$ROOT_DIR/build/toolkit-addons/"*.zip 2>/dev/null | wc -l)
    echo "   (${TOOLKIT_COUNT} individual toolkit add-on ZIPs)"
    echo ""
fi
echo "📄 WordPress.org submission package:"
WPORG_ZIP_NAME="nvdigital-open-operator-system-oos-${VERSION}.zip"
WPORG_SIZE=$(du -h "$ROOT_DIR/build/$WPORG_ZIP_NAME" | cut -f1)
echo "   build/$WPORG_ZIP_NAME ($WPORG_SIZE)"
echo "   Built from: mcp-ai-wpoos-base-${VERSION}.zip"
echo "   See build/WORDPRESS_ORG_SUBMISSION_README.md for instructions"
