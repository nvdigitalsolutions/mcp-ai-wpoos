#!/bin/bash
#
# Rebuild All Assets and ZIPs
#
# Comprehensive rebuild script that:
# 1. Rebuilds all frontend assets (CSS + JavaScript for base and pro)
# 2. Installs/updates PHP dependencies (Composer)
# 3. Creates all plugin ZIP files:
#    - Base version (standalone)
#    - Pro add-on
#    - Combined (base + pro)
#    - Core plugin (lightweight)
#
# Usage:
#   ./bin/rebuild-all-zips.sh                  # Rebuild all assets and ZIPs
#   ./bin/rebuild-all-zips.sh --version 1.0.0  # Specify version
#   ./bin/rebuild-all-zips.sh --clean          # Clean build directories first
#
# What gets rebuilt:
#   Assets: CSS minification, JavaScript bundling (esbuild), Pro addon scripts
#   ZIPs: mcp-ai-wpoos-base, mcp-ai-wpoos-pro, mcp-ai-wpoos (combined), mcp-ai-wpoos-core
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Parse arguments
VERSION_ARG=""
CLEAN_BUILD=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --version)
            VERSION_ARG="--version $2"
            shift 2
            ;;
        --clean)
            CLEAN_BUILD=true
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Rebuild all frontend assets and plugin ZIP files."
            echo ""
            echo "Options:"
            echo "  --version X.Y.Z   Specify version number"
            echo "  --clean           Clean build directories before rebuilding"
            echo "  -h, --help        Show this help message"
            echo ""
            echo "This script will:"
            echo "  1. Rebuild CSS (minification)"
            echo "  2. Rebuild JavaScript (base plugin - esbuild)"
            echo "  3. Rebuild Pro add-on scripts (Node.js bundling)"
            echo "  4. Install production PHP dependencies (Composer)"
            echo "  5. Create all ZIP files (base, pro, combined, core)"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

echo "=========================================="
echo "Rebuilding All Assets and Plugin ZIPs"
echo "=========================================="
echo ""

# Optional: Clean build directory
if [ "$CLEAN_BUILD" = true ]; then
    echo "🧹 Cleaning build directory..."
    rm -rf build
    echo "✅ Build directory cleaned"
    echo ""
fi

# Build all versions using the build-plugin-zip.sh script
# This script handles:
# - Asset building (npm run build -> CSS + JS base + JS pro)
# - Composer dependencies (composer install --no-dev)
# - ZIP file creation
echo "📦 Building all versions..."
echo "   This will rebuild:"
echo "   • All CSS files (minified)"
echo "   • All JavaScript files (bundled with esbuild)"
echo "   • Pro add-on Node.js scripts"
echo "   • All ZIP packages (base, pro, combined, core)"
echo ""

"$SCRIPT_DIR/build-plugin-zip.sh" --all --core-only $VERSION_ARG

echo ""
echo "=========================================="
echo "✅ All Assets and ZIPs Rebuilt!"
echo "=========================================="
echo ""
echo "Build artifacts are in the build/ directory:"
ls -lh build/*.zip 2>/dev/null | awk '{print "  📦 " $NF " (" $5 ")"}'
echo ""
