#!/usr/bin/env bash
#
# Install PHPUnit Test Framework from vendor-dev.zip
#
# This script extracts the vendor-dev.zip archive and makes the development
# dependencies available for testing without requiring composer or internet access.
#
# Prerequisites:
#   - vendor-dev.zip file in the root directory
#   - unzip command installed
#
# Usage:
#   ./bin/install-vendor-dev.sh
#
# The script will:
#   1. Extract vendor-dev.zip
#   2. Merge with existing production vendor dependencies
#   3. Make PHPUnit and other dev tools available
#

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "========================================"
echo "Install PHPUnit Test Framework & Dev Dependencies"
echo "========================================"
echo ""

# Check if vendor-dev.zip exists
if [ ! -f "vendor-dev.zip" ]; then
  echo "Error: vendor-dev.zip not found in the root directory." >&2
  echo "" >&2
  echo "Please download or create vendor-dev.zip first:" >&2
  echo "  - To create: ./bin/package-vendor-dev.sh" >&2
  echo "  - To download: [obtain from your distribution source]" >&2
  echo "" >&2
  exit 1
fi

# Check if unzip is installed
if ! command -v unzip >/dev/null 2>&1; then
  echo "Error: unzip command is required to extract the archive." >&2
  exit 1
fi

# Get archive info
ARCHIVE_SIZE=$(du -h vendor-dev.zip | cut -f1)
echo "Found vendor-dev.zip ($ARCHIVE_SIZE)"
echo ""

# Backup existing vendor directory if it exists
if [ -d "vendor" ]; then
  echo "Existing vendor directory found."
  
  # Check if we already have dev dependencies
  if [ -d "vendor/phpunit" ]; then
    echo "Development dependencies already installed."
    read -p "Do you want to reinstall? (y/N): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
      echo "Installation cancelled."
      exit 0
    fi
  fi
fi

# Extract archive
echo "Extracting vendor-dev.zip..."
unzip -q -o vendor-dev.zip

# Regenerate autoloader to include both production and dev dependencies
echo ""
echo "Regenerating composer autoloader..."
if command -v composer >/dev/null 2>&1; then
  composer dump-autoload --no-interaction
  echo "Autoloader regenerated successfully."
else
  echo "Warning: composer not found. Autoloader may need manual regeneration."
  echo "Run 'composer dump-autoload' when composer is available."
fi

echo ""
echo "========================================"
echo "✓ Installation complete!"
echo "========================================"
echo ""
echo "Development dependencies installed:"
echo "  - PHPUnit test framework"
echo "  - PHP_CodeSniffer & WordPress Coding Standards"
echo "  - WordPress stubs for IDE support"
echo ""
echo "You can now run:"
echo "  - composer run test          # Run PHPUnit tests"
echo "  - composer run lint          # Run PHP_CodeSniffer"
echo "  - composer run lint:compat   # Check PHP compatibility"
echo ""
echo "Note: These are development dependencies only."
echo "They are not needed for production deployments."
echo ""
