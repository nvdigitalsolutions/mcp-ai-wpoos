#!/bin/bash
#
# Build Optional Component ZIPs
#
# Creates ZIP files for optional components that are downloaded on-demand:
#   1. neplex-vectorizer.zip - Image vectorization library
#   2. knowledge-base.zip - Complete profession playbooks (218 files)
#
# These components are excluded from the base plugin ZIP to reduce download size.
# They are downloaded automatically on plugin activation or can be downloaded manually.
#
# Usage:
#   ./bin/build-optional-components.sh
#
# Output:
#   build/optional-components/neplex-vectorizer.zip
#   build/optional-components/knowledge-base.zip
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

echo "=========================================="
echo "Building Optional Component ZIPs"
echo "=========================================="
echo ""

# Check requirements
if ! command -v zip &> /dev/null; then
    echo "❌ Error: zip is required but not installed."
    exit 1
fi

# Create output directory
OUTPUT_DIR="build/optional-components"
mkdir -p "$OUTPUT_DIR"

# Clean previous builds
rm -f "$OUTPUT_DIR"/*.zip

# ============================================================================
# Build neplex-vectorizer.zip
# ============================================================================
echo "Building neplex-vectorizer.zip..."

VECTORIZER_SOURCE="assets/js/vendor/neplex-vectorizer"

if [ ! -d "$VECTORIZER_SOURCE" ]; then
    echo "❌ Error: Vectorizer source directory not found: $VECTORIZER_SOURCE"
    exit 1
fi

# Create ZIP from the vectorizer directory
# The ZIP should extract to create a 'neplex-vectorizer' directory
cd "assets/js/vendor"
zip -r -q "$ROOT_DIR/$OUTPUT_DIR/neplex-vectorizer.zip" neplex-vectorizer/
cd "$ROOT_DIR"

VECTORIZER_SIZE=$(du -h "$OUTPUT_DIR/neplex-vectorizer.zip" | cut -f1)
echo "✅ neplex-vectorizer.zip created ($VECTORIZER_SIZE)"
echo ""

# ============================================================================
# Build knowledge-base.zip
# ============================================================================
echo "Building knowledge-base.zip..."

KB_SOURCE="includes/knowledge-base/profession-playbooks"

if [ ! -d "$KB_SOURCE" ]; then
    echo "❌ Error: Knowledge base source directory not found: $KB_SOURCE"
    exit 1
fi

# Create ZIP from the profession-playbooks directory
# The ZIP should extract to create a 'profession-playbooks' directory
cd "includes/knowledge-base"
zip -r -q "$ROOT_DIR/$OUTPUT_DIR/knowledge-base.zip" profession-playbooks/
cd "$ROOT_DIR"

KB_SIZE=$(du -h "$OUTPUT_DIR/knowledge-base.zip" | cut -f1)
PROFESSION_COUNT=$(ls -1 "$KB_SOURCE"/professions/*.txt 2>/dev/null | wc -l)
echo "✅ knowledge-base.zip created ($KB_SIZE, $PROFESSION_COUNT professions)"
echo ""

# ============================================================================
# Summary
# ============================================================================
echo "=========================================="
echo "Build Complete!"
echo "=========================================="
echo ""
echo "Optional component ZIPs created in: $OUTPUT_DIR/"
ls -lh "$OUTPUT_DIR"/*.zip
echo ""
echo "These ZIPs can be:"
echo "  1. Uploaded to GitHub releases for production"
echo "  2. Used with dev-working URL for development/testing"
echo "  3. Manually installed by extracting to the appropriate directories"
echo ""
