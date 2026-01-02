#!/bin/bash
##
# Fix @neplex/vectorizer Vendor Directory
#
# This script fixes the vendor directory structure for @neplex/vectorizer
# by copying native .node files from platform subdirectories to the main
# vectorizer directory where they are expected at runtime.
#
# This is needed when the plugin is cloned without node_modules, as the
# vectorizer/index.js expects to find platform-specific .node files locally
# before falling back to npm packages.
#
# Usage:
#   ./bin/fix-vectorizer-vendor.sh
#
# @package WP_MCP_AI
##

set -e

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(dirname "$SCRIPT_DIR")"
VENDOR_DIR="$PLUGIN_ROOT/assets/js/vendor/neplex-vectorizer"
VECTORIZER_DIR="$VENDOR_DIR/vectorizer"

echo "================================"
echo "Fixing @neplex/vectorizer Vendor"
echo "================================"
echo ""

# Check if vendor directory exists
if [ ! -d "$VENDOR_DIR" ]; then
    echo "❌ Error: Vendor directory not found: $VENDOR_DIR"
    echo ""
    echo "The @neplex/vectorizer library is not installed in the vendor directory."
    echo "Run 'npm install' to install it, or download the plugin with vendor files included."
    exit 1
fi

# Check if vectorizer main directory exists
if [ ! -d "$VECTORIZER_DIR" ]; then
    echo "❌ Error: Vectorizer main directory not found: $VECTORIZER_DIR"
    exit 1
fi

echo "Plugin root: $PLUGIN_ROOT"
echo "Vendor directory: $VENDOR_DIR"
echo "Vectorizer directory: $VECTORIZER_DIR"
echo ""

# Count existing .node files in vectorizer directory
EXISTING_COUNT=$(find "$VECTORIZER_DIR" -maxdepth 1 -name "*.node" -type f 2>/dev/null | wc -l)
echo "Found $EXISTING_COUNT native module(s) already in vectorizer directory"

# Find all .node files in platform subdirectories
NODE_FILES=$(find "$VENDOR_DIR" -maxdepth 2 -name "*.node" -type f 2>/dev/null)

if [ -z "$NODE_FILES" ]; then
    echo ""
    echo "⚠️  Warning: No .node files found in platform subdirectories"
    echo "The vendor directory may be incomplete or corrupted."
    exit 1
fi

# Copy each .node file to the main vectorizer directory
COPIED_COUNT=0
echo ""
echo "Copying native modules to vectorizer directory..."
echo ""

while IFS= read -r node_file; do
    if [ -f "$node_file" ]; then
        filename=$(basename "$node_file")
        target="$VECTORIZER_DIR/$filename"
        
        # Check if file already exists and is identical
        if [ -f "$target" ]; then
            if cmp -s "$node_file" "$target"; then
                echo "  ✓ $filename (already up to date)"
                continue
            else
                echo "  ⟳ $filename (updating)"
            fi
        else
            echo "  + $filename (new)"
        fi
        
        cp "$node_file" "$target"
        COPIED_COUNT=$((COPIED_COUNT + 1))
    fi
done <<< "$NODE_FILES"

echo ""
echo "================================"
echo "Summary"
echo "================================"
echo "Copied/updated: $COPIED_COUNT file(s)"
echo ""

# List all .node files in vectorizer directory
FINAL_COUNT=$(find "$VECTORIZER_DIR" -maxdepth 1 -name "*.node" -type f 2>/dev/null | wc -l)
echo "Total native modules in vectorizer directory: $FINAL_COUNT"
echo ""

if [ $FINAL_COUNT -gt 0 ]; then
    echo "✅ Success! Native modules are now available for the vectorize_image tool."
    echo ""
    echo "Available modules:"
    ls -lh "$VECTORIZER_DIR"/*.node 2>/dev/null | awk '{print "  - " $9 " (" $5 ")"}'
    echo ""
    exit 0
else
    echo "❌ Error: No native modules found after copying."
    echo "The vendor directory may be corrupted."
    exit 1
fi
