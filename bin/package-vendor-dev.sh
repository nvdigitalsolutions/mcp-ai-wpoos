#!/usr/bin/env bash
#
# Package PHPUnit Test Framework and Dev Dependencies
#
# This script creates a vendor-dev.zip archive containing all development
# dependencies (PHPUnit, PHPCS, WordPress stubs, etc.) for offline distribution.
#
# The resulting archive (~140 MB) can be:
# - Downloaded separately from the main plugin
# - Uploaded to a server for testing environments
# - Shared with team members who need to run tests
#
# Usage:
#   ./bin/package-vendor-dev.sh
#
# Output:
#   vendor-dev.zip - Archive containing all dev dependencies
#

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "========================================"
echo "Package PHPUnit Test Framework & Dev Dependencies"
echo "========================================"
echo ""

# Check if composer is installed
if ! command -v composer >/dev/null 2>&1; then
  echo "Error: Composer is required to install development dependencies." >&2
  exit 1
fi

# Check if zip is installed
if ! command -v zip >/dev/null 2>&1; then
  echo "Error: zip command is required to create the archive." >&2
  exit 1
fi

# Install dev dependencies if needed
echo "Step 1: Installing development dependencies..."
if [ ! -d "vendor/phpunit" ] || [ ! -d "vendor/squizlabs" ]; then
  echo "Installing composer dependencies (this may take a few minutes)..."
  composer install --no-interaction --prefer-dist --no-progress
else
  echo "Development dependencies already installed."
fi
echo ""

# Create temporary directory for packaging
TEMP_DIR=$(mktemp -d)
trap 'rm -rf "$TEMP_DIR"' EXIT

echo "Step 2: Preparing vendor-dev package..."

# Copy only dev dependencies to temp directory
mkdir -p "$TEMP_DIR/vendor"

# Copy dev-only packages (exclude production packages already in repo)
# Production packages (nyholm, php-http, psr, rahul900day, symfony) are already
# committed to the repository and will be present on Cloudways/production servers.
DEV_PACKAGES=(
  "dealerdirect"
  "doctrine"
  "myclabs"
  "nikic"
  "phar-io"
  "php-stubs"
  "phpcompatibility"
  "phpcsstandards"
  "phpunit"
  "sebastian"
  "squizlabs"
  "theseer"
  "wp-coding-standards"
  "wp-phpunit"
  "yoast"
)

for package in "${DEV_PACKAGES[@]}"; do
  if [ -d "vendor/$package" ]; then
    echo "  - Including $package"
    cp -r "vendor/$package" "$TEMP_DIR/vendor/"
  fi
done

# Note: We do NOT include vendor/autoload.php or vendor/composer/ 
# because those are already in the repository with production dependencies.
# The install script will run 'composer dump-autoload' to regenerate them.

echo ""

# Create archive
echo "Step 3: Creating vendor-dev.zip archive..."
cd "$TEMP_DIR"
zip -q -r "$ROOT_DIR/vendor-dev.zip" vendor/

cd "$ROOT_DIR"

# Get archive size
ARCHIVE_SIZE=$(du -h vendor-dev.zip | cut -f1)

echo ""
echo "========================================"
echo "✓ Package created successfully!"
echo "========================================"
echo ""
echo "Archive: vendor-dev.zip"
echo "Size: $ARCHIVE_SIZE"
echo ""
echo "This archive contains:"
echo "  - PHPUnit test framework"
echo "  - PHP_CodeSniffer & WordPress Coding Standards"
echo "  - WordPress stubs for IDE support"
echo "  - Other development dependencies"
echo ""
echo "To use this archive:"
echo "  1. Copy vendor-dev.zip to your target environment"
echo "  2. Run: ./bin/install-vendor-dev.sh"
echo ""
echo "Note: This archive is for development/testing only."
echo "Production deployments should use 'composer install --no-dev'"
echo ""
