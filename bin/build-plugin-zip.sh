#!/bin/bash
#
# Build Plugin ZIP
#
# Creates production-ready ZIP files of the WP oOS plugin.
# Supports building base, pro, or combined (base + pro) versions.
#
# Usage:
#   ./bin/build-plugin-zip.sh                    # Builds all three versions
#   ./bin/build-plugin-zip.sh --base             # Builds base version only
#   ./bin/build-plugin-zip.sh --pro              # Builds pro add-on only
#   ./bin/build-plugin-zip.sh --combined         # Builds base + pro combined
#   ./bin/build-plugin-zip.sh --version 1.0.0    # Specify version number
#
# Output:
#   build/mcp-ai-wpoos-base-X.Y.Z.zip       (standalone base version - works alone)
#   build/mcp-ai-wpoos-pro-X.Y.Z.zip        (pro add-on - requires base)
#   build/mcp-ai-wpoos-X.Y.Z.zip            (base + pro combined)
#
# Requirements:
#   - Node.js and npm (for asset building)
#   - Composer (for PHP dependencies)
#   - zip command
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Default values
BUILD_BASE=false
BUILD_PRO=false
BUILD_COMBINED=false
VERSION=""

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --base|--core)
            BUILD_BASE=true
            shift
            ;;
        --pro)
            BUILD_PRO=true
            shift
            ;;
        --combined|--full)
            BUILD_COMBINED=true
            shift
            ;;
        --version)
            VERSION="$2"
            shift 2
            ;;
        --all)
            BUILD_BASE=true
            BUILD_PRO=true
            BUILD_COMBINED=true
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --base, --core    Build base version (free, WordPress.org compatible)"
            echo "  --pro             Build pro add-on (requires base plugin)"
            echo "  --combined        Build base + pro combined package"
            echo "  --all             Build all three versions (default)"
            echo "  --version X.Y.Z   Specify version number"
            echo "  -h, --help        Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                       # Build all three versions"
            echo "  $0 --base                # Build only base version"
            echo "  $0 --pro                 # Build only pro add-on"
            echo "  $0 --combined            # Build base + pro combined"
            echo "  $0 --version 1.0.0       # Specify version"
            exit 0
            ;;
        *)
            # Assume it's a version number for backwards compatibility
            VERSION="$1"
            shift
            ;;
    esac
done

# If no build type specified, build all three
if [ "$BUILD_BASE" = false ] && [ "$BUILD_PRO" = false ] && [ "$BUILD_COMBINED" = false ]; then
    BUILD_BASE=true
    BUILD_PRO=true
    BUILD_COMBINED=true
fi

# Get version if not specified
if [ -z "$VERSION" ]; then
    VERSION=$(grep -E "^\s*\*\s*Version:" mcp-ai-wpoos.php | sed 's/.*Version:\s*//' | tr -d '[:space:]')
    if [ -z "$VERSION" ]; then
        VERSION="dev"
    fi
fi

echo "=========================================="
echo "Building WP oOS Plugin ZIP v${VERSION}"
echo "=========================================="
echo ""
echo "Build targets:"
[ "$BUILD_BASE" = true ] && echo "  ✓ Base version (mcp-ai-wpoos-base) - standalone"
[ "$BUILD_PRO" = true ] && echo "  ✓ Pro add-on (mcp-ai-wpoos-pro)"
[ "$BUILD_COMBINED" = true ] && echo "  ✓ Base + Pro combined (mcp-ai-wpoos)"
echo ""

# Check requirements
echo "Checking requirements..."

if ! command -v npm &> /dev/null; then
    echo "❌ Error: npm is required but not installed."
    exit 1
fi

if ! command -v composer &> /dev/null; then
    echo "❌ Error: composer is required but not installed."
    exit 1
fi

if ! command -v zip &> /dev/null; then
    echo "❌ Error: zip is required but not installed."
    exit 1
fi

echo "✅ All requirements met"
echo ""

# Step 1: Install and build frontend assets
echo "Step 1: Building frontend assets..."
npm ci --silent 2>/dev/null || npm install --silent
npm run build
echo "✅ Frontend assets built"
echo ""

# Step 2: Install production Composer dependencies
echo "Step 2: Installing production PHP dependencies..."
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet
echo "✅ Production dependencies installed"
echo ""

# Clean build directory
rm -rf build
mkdir -p build

# ============================================================================
# Build Base Version (Standalone, fully functional without Pro)
# ============================================================================
if [ "$BUILD_BASE" = true ]; then
    echo "Step 3a: Building Base version (standalone)..."
    
    BASE_SLUG="mcp-ai-wpoos-base"
    mkdir -p "build/${BASE_SLUG}"
    
    # Copy full plugin files EXCEPT pro addons
    # This creates a fully functional standalone plugin
    rsync -av --quiet . "build/${BASE_SLUG}/" \
        --exclude '.git' \
        --exclude '.git-branch-info' \
        --exclude '.github' \
        --exclude '.wordpress-org' \
        --exclude '.codex' \
        --exclude '.devcontainer' \
        --exclude '.vscode' \
        --exclude 'node_modules' \
        --exclude 'tests' \
        --exclude 'bin' \
        --exclude 'coverage' \
        --exclude 'build' \
        --exclude 'svn-*' \
        --exclude '.eslintrc.json' \
        --exclude '.eslintignore' \
        --exclude '.gitignore' \
        --exclude '.editorconfig' \
        --exclude '.nvmrc' \
        --exclude 'CODEOWNERS' \
        --exclude 'phpunit.xml.dist' \
        --exclude 'composer.json' \
        --exclude 'composer.lock' \
        --exclude 'package.json' \
        --exclude 'package-lock.json' \
        --exclude 'patches.lock.json' \
        --exclude 'babel.config.js' \
        --exclude 'jest.config.js' \
        --exclude 'esbuild.config.js' \
        --exclude 'docker-compose.yml' \
        --exclude 'patches' \
        --exclude 'docs' \
        --exclude 'core' \
        --exclude 'shared' \
        --exclude 'ARCHITECTURE.md' \
        --exclude 'RELEASE_CHECKLIST.md' \
        --exclude 'CONTRIBUTING.md' \
        --exclude 'SECURITY.md' \
        --exclude 'BUILD.md' \
        --exclude 'test-*.php' \
        --exclude 'verify-*.sh' \
        --exclude '*.zip' \
        --exclude '*.tar.gz' \
        --exclude '.distignore' \
        --exclude 'addons/pro' \
        --exclude 'assets/examples' \
        --exclude 'wp-mcp-ai-base.php'
    
    # Copy and rename wp-mcp-ai-base.php to mcp-ai-wpoos-base.php for WordPress.org compliance
    # The main plugin file should match the folder name (mcp-ai-wpoos-base)
    if [ -f "wp-mcp-ai-base.php" ]; then
        cp "wp-mcp-ai-base.php" "build/${BASE_SLUG}/mcp-ai-wpoos-base.php"
        echo "✓ Copied wp-mcp-ai-base.php as mcp-ai-wpoos-base.php"
    fi
    
    # Create ZIP
    cd build
    zip -r -q "${BASE_SLUG}-${VERSION}.zip" "${BASE_SLUG}/" -x "*.DS_Store" -x "*__MACOSX*"
    cd ..
    
    BASE_SIZE=$(du -h "build/${BASE_SLUG}-${VERSION}.zip" | cut -f1)
    echo "✅ Base version created: build/${BASE_SLUG}-${VERSION}.zip (${BASE_SIZE})"
    echo ""
fi

# ============================================================================
# Build Pro Add-on
# ============================================================================
if [ "$BUILD_PRO" = true ]; then
    echo "Step 3b: Building Pro add-on..."
    
    PRO_SLUG="mcp-ai-wpoos-pro"
    mkdir -p "build/${PRO_SLUG}"
    
    # Copy pro addon files
    if [ -d "addons/pro" ]; then
        rsync -av --quiet addons/pro/ "build/${PRO_SLUG}/" \
            --exclude '.git' \
            --exclude '.vscode' \
            --exclude 'node_modules' \
            --exclude 'tests' \
            --exclude '*.zip' \
            --exclude 'assets/examples'
        
        # Create ZIP
        cd build
        zip -r -q "${PRO_SLUG}-${VERSION}.zip" "${PRO_SLUG}/" -x "*.DS_Store" -x "*__MACOSX*"
        cd ..
        
        PRO_SIZE=$(du -h "build/${PRO_SLUG}-${VERSION}.zip" | cut -f1)
        echo "✅ Pro add-on created: build/${PRO_SLUG}-${VERSION}.zip (${PRO_SIZE})"
    else
        echo "⚠️  Pro add-on directory (addons/pro) not found, skipping..."
    fi
    echo ""
fi

# ============================================================================
# Build Base + Pro Combined Version
# ============================================================================
if [ "$BUILD_COMBINED" = true ]; then
    echo "Step 3c: Building Base + Pro combined version..."
    
    COMBINED_SLUG="mcp-ai-wpoos"
    mkdir -p "build/${COMBINED_SLUG}"
    
    # Copy all plugin files (includes both base and pro)
    rsync -av --quiet . "build/${COMBINED_SLUG}/" \
        --exclude '.git' \
        --exclude '.git-branch-info' \
        --exclude '.github' \
        --exclude '.wordpress-org' \
        --exclude '.codex' \
        --exclude '.devcontainer' \
        --exclude '.vscode' \
        --exclude 'node_modules' \
        --exclude 'tests' \
        --exclude 'bin' \
        --exclude 'coverage' \
        --exclude 'build' \
        --exclude 'svn-*' \
        --exclude '.eslintrc.json' \
        --exclude '.eslintignore' \
        --exclude '.gitignore' \
        --exclude '.editorconfig' \
        --exclude '.nvmrc' \
        --exclude 'CODEOWNERS' \
        --exclude 'phpunit.xml.dist' \
        --exclude 'composer.json' \
        --exclude 'composer.lock' \
        --exclude 'package.json' \
        --exclude 'package-lock.json' \
        --exclude 'patches.lock.json' \
        --exclude 'babel.config.js' \
        --exclude 'jest.config.js' \
        --exclude 'esbuild.config.js' \
        --exclude 'docker-compose.yml' \
        --exclude 'patches' \
        --exclude 'docs' \
        --exclude 'core' \
        --exclude 'shared' \
        --exclude 'ARCHITECTURE.md' \
        --exclude 'RELEASE_CHECKLIST.md' \
        --exclude 'CONTRIBUTING.md' \
        --exclude 'SECURITY.md' \
        --exclude 'BUILD.md' \
        --exclude 'test-*.php' \
        --exclude 'verify-*.sh' \
        --exclude '*.zip' \
        --exclude '*.tar.gz' \
        --exclude '.distignore' \
        --exclude 'assets/examples'
    
    # Create ZIP
    cd build
    zip -r -q "${COMBINED_SLUG}-${VERSION}.zip" "${COMBINED_SLUG}/" -x "*.DS_Store" -x "*__MACOSX*"
    cd ..
    
    COMBINED_SIZE=$(du -h "build/${COMBINED_SLUG}-${VERSION}.zip" | cut -f1)
    echo "✅ Combined version created: build/${COMBINED_SLUG}-${VERSION}.zip (${COMBINED_SIZE})"
    echo ""
fi

# ============================================================================
# Summary
# ============================================================================
echo "=========================================="
echo "✅ Build Complete!"
echo "=========================================="
echo ""
echo "📦 Output files in build/:"
ls -lh build/*.zip 2>/dev/null | awk '{print "   " $NF " (" $5 ")"}'
echo ""
echo "To install:"
echo "  1. Go to WordPress Admin → Plugins → Add New → Upload Plugin"
echo "  2. Upload the appropriate ZIP file:"
[ "$BUILD_BASE" = true ] && echo "     - mcp-ai-wpoos-base-${VERSION}.zip (Standalone base plugin)"
[ "$BUILD_PRO" = true ] && echo "     - mcp-ai-wpoos-pro-${VERSION}.zip (Pro add-on, requires base)"
[ "$BUILD_COMBINED" = true ] && echo "     - mcp-ai-wpoos-${VERSION}.zip (Base + Pro combined)"
echo "  3. Click 'Install Now' and then 'Activate'"
echo ""
