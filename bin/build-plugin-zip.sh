#!/bin/bash
#
# Build Plugin ZIP
#
# Creates a production-ready ZIP file of the WP oOS plugin.
# This script mirrors the GitHub Actions release workflow for local builds.
#
# Usage:
#   ./bin/build-plugin-zip.sh           # Uses version from wp-mcp-ai.php
#   ./bin/build-plugin-zip.sh 1.0.0     # Uses specified version
#
# Output:
#   build/wp-mcp-ai-X.Y.Z.zip
#
# Requirements:
#   - Node.js and npm (for asset building)
#   - Composer (for PHP dependencies)
#   - zip command
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_SLUG="wp-mcp-ai"

cd "$ROOT_DIR"

# Get version from argument or extract from plugin file
if [ -n "$1" ]; then
    VERSION="$1"
else
    VERSION=$(grep -E "^\s*\*\s*Version:" wp-mcp-ai.php | sed 's/.*Version:\s*//' | tr -d '[:space:]')
    if [ -z "$VERSION" ]; then
        VERSION="dev"
    fi
fi

echo "=========================================="
echo "Building WP oOS Plugin ZIP v${VERSION}"
echo "=========================================="
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

# Step 3: Create build directory
echo "Step 3: Creating plugin package..."
rm -rf "build/${PLUGIN_SLUG}"
mkdir -p "build/${PLUGIN_SLUG}"

# Step 4: Copy plugin files (excluding dev files)
rsync -av --quiet . "build/${PLUGIN_SLUG}/" \
    --exclude '.git' \
    --exclude '.git-branch-info' \
    --exclude '.github' \
    --exclude '.wordpress-org' \
    --exclude '.codex' \
    --exclude '.devcontainer' \
    --exclude 'node_modules' \
    --exclude 'tests' \
    --exclude 'bin' \
    --exclude 'coverage' \
    --exclude 'build' \
    --exclude 'svn-*' \
    --exclude '.eslintrc.json' \
    --exclude '.eslintignore' \
    --exclude '.gitignore' \
    --exclude 'phpunit.xml.dist' \
    --exclude 'composer.json' \
    --exclude 'composer.lock' \
    --exclude 'package.json' \
    --exclude 'package-lock.json' \
    --exclude 'babel.config.js' \
    --exclude 'jest.config.js' \
    --exclude 'esbuild.config.js' \
    --exclude 'docker-compose.yml' \
    --exclude 'patches' \
    --exclude 'RELEASE_CHECKLIST.md' \
    --exclude 'CONTRIBUTING.md' \
    --exclude 'SECURITY.md' \
    --exclude 'BUILD.md' \
    --exclude 'test-*.php' \
    --exclude 'verify-*.sh' \
    --exclude '*.zip' \
    --exclude '*.tar.gz' \
    --exclude '.distignore'

echo "✅ Plugin files copied"
echo ""

# Step 5: Create ZIP archive
echo "Step 4: Creating ZIP archive..."
cd build
rm -f "../${PLUGIN_SLUG}-${VERSION}.zip"
zip -r -q "../${PLUGIN_SLUG}-${VERSION}.zip" "${PLUGIN_SLUG}/" -x "*.DS_Store" -x "*__MACOSX*"
cd ..

# Move ZIP to build directory
mv "${PLUGIN_SLUG}-${VERSION}.zip" "build/"

echo "✅ ZIP archive created"
echo ""

# Show results
ZIP_SIZE=$(du -h "build/${PLUGIN_SLUG}-${VERSION}.zip" | cut -f1)

echo "=========================================="
echo "✅ Build Complete!"
echo "=========================================="
echo ""
echo "📦 Output: build/${PLUGIN_SLUG}-${VERSION}.zip"
echo "📊 Size: ${ZIP_SIZE}"
echo ""
echo "To install:"
echo "  1. Go to WordPress Admin → Plugins → Add New → Upload Plugin"
echo "  2. Upload build/${PLUGIN_SLUG}-${VERSION}.zip"
echo "  3. Click 'Install Now' and then 'Activate'"
echo ""
