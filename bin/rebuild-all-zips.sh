#!/bin/bash
#
# Rebuild All ZIPs
#
# Convenience script to rebuild all plugin ZIP files:
# - Base version (standalone)
# - Pro add-on
# - Combined (base + pro)
# - Core plugin (lightweight)
#
# Usage:
#   ./bin/rebuild-all-zips.sh                # Rebuild all versions
#   ./bin/rebuild-all-zips.sh --version 1.0.0  # Specify version
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Parse version argument if provided
VERSION_ARG=""
if [ "$1" = "--version" ] && [ -n "$2" ]; then
    VERSION_ARG="--version $2"
fi

echo "=========================================="
echo "Rebuilding All Plugin ZIPs"
echo "=========================================="
echo ""

# Build all versions using the build-plugin-zip.sh script
# Use --all flag for base, pro, combined, and also add --core-only
"$SCRIPT_DIR/build-plugin-zip.sh" --all --core-only $VERSION_ARG

echo ""
echo "=========================================="
echo "✅ All ZIPs rebuilt successfully!"
echo "=========================================="
