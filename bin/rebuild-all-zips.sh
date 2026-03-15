#!/bin/bash
#
# Rebuild All ZIPs
#
# Convenience script to rebuild all 8 plugin ZIP files:
#
# Original packages (4):
# - mcp-ai-wpoos-base-{version}.zip      Base version (standalone)
# - mcp-ai-wpoos-pro-{version}.zip       Pro add-on (requires base)
# - mcp-ai-wpoos-{version}.zip           Combined (base + pro)
# - mcp-ai-wpoos-core-{version}.zip      Core plugin (lightweight)
#
# WordPress.org packages (4) — text-domain transformed:
# - nvdigital-open-operator-system-oos-{version}.zip          BASE (WordPress.org)
# - nvdigital-open-operator-system-oos-pro-{version}.zip      PRO  (WordPress.org)
# - nvdigital-open-operator-system-oos-complete-{version}.zip COMPLETE (WordPress.org)
# - nvdigital-open-operator-system-oos-core-{version}.zip     CORE (WordPress.org)
#
# Usage:
#   ./bin/rebuild-all-zips.sh                          # Rebuild all 8 ZIPs
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

# Build all versions using the build-plugin-zip.sh script
# Use --all flag for base, pro, combined, and also add --core-only
"$SCRIPT_DIR/build-plugin-zip.sh" --all --core-only $VERSION_ARG $SKIP_NPM_ARG

echo ""
echo "=========================================="
echo "Building All 4 WordPress.org Packages"
echo "=========================================="
echo ""

# Transform all 4 original packages into WordPress.org-compatible packages
# (text-domain mcp-ai-wpoos* → nvdigital-open-operator-system-oos*)
echo "Transforming text domains for WordPress.org distribution..."
"$SCRIPT_DIR/build-wordpress-org-from-base.sh" --version "$VERSION"

echo ""
echo "=========================================="
echo "✅ All 8 ZIPs rebuilt successfully!"
echo "=========================================="
echo ""

# Determine core version (may differ from main plugin version)
CORE_VERSION=$(grep -E "^\s*\*\s*Version:" core/mcp-ai-wpoos-core.php 2>/dev/null | sed 's/.*Version:\s*//' | tr -d '[:space:]' || echo "1.0.0")

echo "📦 Original packages (4):"
for ZIP in \
    "mcp-ai-wpoos-base-${VERSION}.zip" \
    "mcp-ai-wpoos-pro-${VERSION}.zip" \
    "mcp-ai-wpoos-${VERSION}.zip" \
    "mcp-ai-wpoos-core-${CORE_VERSION}.zip"; do
    if [ -f "$ROOT_DIR/build/$ZIP" ]; then
        SIZE=$(du -h "$ROOT_DIR/build/$ZIP" | cut -f1)
        echo "   build/$ZIP ($SIZE)"
    fi
done
echo ""
echo "📄 WordPress.org packages (4):"
for ZIP in \
    "nvdigital-open-operator-system-oos-${VERSION}.zip" \
    "nvdigital-open-operator-system-oos-pro-${VERSION}.zip" \
    "nvdigital-open-operator-system-oos-complete-${VERSION}.zip" \
    "nvdigital-open-operator-system-oos-core-${CORE_VERSION}.zip"; do
    if [ -f "$ROOT_DIR/build/$ZIP" ]; then
        SIZE=$(du -h "$ROOT_DIR/build/$ZIP" | cut -f1)
        echo "   build/$ZIP ($SIZE)"
    fi
done
echo ""
echo "   See build/WORDPRESS_ORG_SUBMISSION_README.md for submission instructions"
