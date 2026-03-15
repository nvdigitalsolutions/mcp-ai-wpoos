#!/bin/bash
#
# Rebuild All ZIPs
#
# Convenience script to rebuild all 8 plugin ZIP files:
#
# Original packages (repository text domains - mcp-ai-wpoos-*):
#   1. mcp-ai-wpoos-base-{version}.zip        - Base version (standalone)
#   2. mcp-ai-wpoos-pro-{version}.zip         - Pro add-on
#   3. mcp-ai-wpoos-{version}.zip             - Combined (base + pro)
#   4. mcp-ai-wpoos-core-{version}.zip        - Core plugin (lightweight)
#
# WordPress.org packages (WordPress text domains - nvdigital-open-operator-system-oos-*):
#   5. nvdigital-open-operator-system-oos-{version}.zip          - BASE (WP.org submission ready)
#   6. nvdigital-open-operator-system-oos-pro-{version}.zip      - PRO (self-hosted add-on)
#   7. nvdigital-open-operator-system-oos-complete-{version}.zip - COMPLETE (self-hosted only)
#   8. nvdigital-open-operator-system-oos-core-{version}.zip     - CORE (lightweight, WP.org ready)
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
echo "Building WordPress.org Compliant Packages"
echo "=========================================="
echo ""

# Build all 4 WordPress.org packages with fully transformed text domains
"$SCRIPT_DIR/build-wordpress-org-from-base.sh" --version "$VERSION"

echo ""
echo "=========================================="
echo "✅ All 8 ZIPs rebuilt successfully!"
echo "=========================================="
echo ""
echo "📦 Original packages (repository text domains):"
for ZIP in \
    "mcp-ai-wpoos-base-${VERSION}.zip" \
    "mcp-ai-wpoos-pro-${VERSION}.zip" \
    "mcp-ai-wpoos-${VERSION}.zip" \
    "mcp-ai-wpoos-core-${VERSION}.zip"; do
    if [ -f "$ROOT_DIR/build/$ZIP" ]; then
        SIZE=$(du -h "$ROOT_DIR/build/$ZIP" | cut -f1)
        echo "   build/$ZIP ($SIZE)"
    fi
done
echo ""
echo "📦 WordPress.org packages (production text domains):"
for ZIP in \
    "nvdigital-open-operator-system-oos-${VERSION}.zip" \
    "nvdigital-open-operator-system-oos-pro-${VERSION}.zip" \
    "nvdigital-open-operator-system-oos-complete-${VERSION}.zip" \
    "nvdigital-open-operator-system-oos-core-${VERSION}.zip"; do
    if [ -f "$ROOT_DIR/build/$ZIP" ]; then
        SIZE=$(du -h "$ROOT_DIR/build/$ZIP" | cut -f1)
        echo "   build/$ZIP ($SIZE)"
    fi
done
echo ""
echo "📄 See build/WORDPRESS_ORG_SUBMISSION_README.md for submission instructions"
