#!/bin/bash
#
# Build Optional Component ZIPs
#
# Creates ZIP files for optional components that are downloaded on-demand:
#   1. knowledge-base.zip      – Profession playbooks, documents, categories, teams
#   2. neplex-vectorizer.zip   – Placeholder (now bundled with base plugin)
#
# Usage:
#   ./bin/build-optional-components.sh <version>
#
# Example:
#   ./bin/build-optional-components.sh 1.1.21
#
# Output:
#   build/optional-components/knowledge-base-<version>.zip
#   build/optional-components/neplex-vectorizer-<version>.zip
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# ---------------------------------------------------------------------------
# Validate arguments
# ---------------------------------------------------------------------------
VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    echo "❌ Error: Version argument is required."
    echo "   Usage: $0 <version>"
    echo "   Example: $0 1.1.21"
    exit 1
fi

echo "=========================================="
echo "Building Optional Component ZIPs"
echo "             Version: $VERSION"
echo "=========================================="
echo ""

# ---------------------------------------------------------------------------
# Check requirements
# ---------------------------------------------------------------------------
if ! command -v zip &> /dev/null; then
    echo "❌ Error: 'zip' is required but not installed."
    exit 1
fi

# ---------------------------------------------------------------------------
# Create output directory
# ---------------------------------------------------------------------------
OUTPUT_DIR="build/optional-components"
mkdir -p "$OUTPUT_DIR"

# ---------------------------------------------------------------------------
# Clean previous builds
# ---------------------------------------------------------------------------
echo "🧹 Cleaning previous builds..."
rm -f "$OUTPUT_DIR"/*.zip
echo ""

# ---------------------------------------------------------------------------
# Create .gitkeep in output directory (so it's tracked even when empty)
# ---------------------------------------------------------------------------
if [ ! -f "$OUTPUT_DIR/.gitkeep" ]; then
    touch "$OUTPUT_DIR/.gitkeep"
    echo "📄 Created $OUTPUT_DIR/.gitkeep"
    echo ""
fi

# ============================================================================
# 1. Build knowledge-base.zip
# ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📦 Building knowledge-base-${VERSION}.zip"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

KB_SOURCE="includes/knowledge-base"
KB_ZIP="knowledge-base-${VERSION}.zip"

if [ ! -d "$KB_SOURCE" ]; then
    echo "⚠️  Warning: Knowledge base source directory not found: $KB_SOURCE"
    echo "   Skipping knowledge-base.zip"
else
    # Create a temp directory for staging
    KB_TEMP="$(mktemp -d)"
    trap "rm -rf $KB_TEMP" EXIT

    # --- profession-playbooks/professions/*.txt ---
    if [ -d "$KB_SOURCE/profession-playbooks/professions" ]; then
        echo "  → Copying profession-playbooks/professions/*.txt..."
        mkdir -p "$KB_TEMP/knowledge-base/profession-playbooks/professions"
        cp "$KB_SOURCE/profession-playbooks/professions/"*.txt \
           "$KB_TEMP/knowledge-base/profession-playbooks/professions/" 2>/dev/null || true
        PLAYBOOK_COUNT=$(ls -1 "$KB_TEMP/knowledge-base/profession-playbooks/professions/"*.txt 2>/dev/null | wc -l)
        echo "         $PLAYBOOK_COUNT playbook .txt files copied"
    else
        echo "  ⚠️  profession-playbooks/professions/ not found, skipping"
    fi

    # --- profession-playbooks/categories/*.txt ---
    if [ -d "$KB_SOURCE/profession-playbooks/categories" ]; then
        echo "  → Copying profession-playbooks/categories/*.txt..."
        mkdir -p "$KB_TEMP/knowledge-base/profession-playbooks/categories"
        cp "$KB_SOURCE/profession-playbooks/categories/"*.txt \
           "$KB_TEMP/knowledge-base/profession-playbooks/categories/" 2>/dev/null || true
        CAT_COUNT=$(ls -1 "$KB_TEMP/knowledge-base/profession-playbooks/categories/"*.txt 2>/dev/null | wc -l)
        echo "         $CAT_COUNT category .txt files copied"
    else
        echo "  ⚠️  profession-playbooks/categories/ not found, skipping"
    fi

    # --- profession-playbooks/global.txt ---
    if [ -f "$KB_SOURCE/profession-playbooks/global.txt" ]; then
        echo "  → Copying profession-playbooks/global.txt..."
        cp "$KB_SOURCE/profession-playbooks/global.txt" \
           "$KB_TEMP/knowledge-base/profession-playbooks/global.txt"
    else
        echo "  ⚠️  profession-playbooks/global.txt not found, skipping"
    fi

    # --- profession-playbooks/manifest.json ---
    if [ -f "$KB_SOURCE/profession-playbooks/manifest.json" ]; then
        echo "  → Copying profession-playbooks/manifest.json..."
        cp "$KB_SOURCE/profession-playbooks/manifest.json" \
           "$KB_TEMP/knowledge-base/profession-playbooks/manifest.json"
    else
        echo "  ⚠️  profession-playbooks/manifest.json not found, skipping"
    fi

    # --- profession-playbooks/README.md ---
    if [ -f "$KB_SOURCE/profession-playbooks/README.md" ]; then
        echo "  → Copying profession-playbooks/README.md..."
        cp "$KB_SOURCE/profession-playbooks/README.md" \
           "$KB_TEMP/knowledge-base/profession-playbooks/README.md"
    else
        echo "  ⚠️  profession-playbooks/README.md not found, skipping"
    fi

    # --- profession-documents/*.txt ---
    if [ -d "$KB_SOURCE/profession-documents" ]; then
        echo "  → Copying profession-documents/*.txt..."
        mkdir -p "$KB_TEMP/knowledge-base/profession-documents"
        cp "$KB_SOURCE/profession-documents/"*.txt \
           "$KB_TEMP/knowledge-base/profession-documents/" 2>/dev/null || true
        DOC_COUNT=$(ls -1 "$KB_TEMP/knowledge-base/profession-documents/"*.txt 2>/dev/null | wc -l)
        echo "         $DOC_COUNT document .txt files copied"
    else
        echo "  ⚠️  profession-documents/ not found, skipping"
    fi

    # --- profession-documents/README.md ---
    if [ -f "$KB_SOURCE/profession-documents/README.md" ]; then
        echo "  → Copying profession-documents/README.md..."
        cp "$KB_SOURCE/profession-documents/README.md" \
           "$KB_TEMP/knowledge-base/profession-documents/README.md"
    else
        echo "  ⚠️  profession-documents/README.md not found, skipping"
    fi

    # --- professions/*.json (18 category JSON files) ---
    if [ -d "$KB_SOURCE/professions" ]; then
        echo "  → Copying professions/*.json..."
        mkdir -p "$KB_TEMP/knowledge-base/professions"
        cp "$KB_SOURCE/professions/"*.json \
           "$KB_TEMP/knowledge-base/professions/" 2>/dev/null || true
        PROF_COUNT=$(ls -1 "$KB_TEMP/knowledge-base/professions/"*.json 2>/dev/null | wc -l)
        echo "         $PROF_COUNT profession .json files copied"
    else
        echo "  ⚠️  professions/ not found, skipping"
    fi

    # --- teams/*.json (26 team JSON files) ---
    if [ -d "$KB_SOURCE/teams" ]; then
        echo "  → Copying teams/*.json..."
        mkdir -p "$KB_TEMP/knowledge-base/teams"
        cp "$KB_SOURCE/teams/"*.json \
           "$KB_TEMP/knowledge-base/teams/" 2>/dev/null || true
        TEAM_COUNT=$(ls -1 "$KB_TEMP/knowledge-base/teams/"*.json 2>/dev/null | wc -l)
        echo "         $TEAM_COUNT team .json files copied"
    else
        echo "  ⚠️  teams/ not found, skipping"
    fi

    echo ""

    # --- Create the ZIP ---
    echo "  → Compressing into $KB_ZIP..."
    cd "$KB_TEMP"
    zip -r -q "$ROOT_DIR/$OUTPUT_DIR/$KB_ZIP" knowledge-base/
    cd "$ROOT_DIR"

    KB_SIZE=$(du -h "$OUTPUT_DIR/$KB_ZIP" | cut -f1)
    echo "  ✅ $KB_ZIP created ($KB_SIZE)"
    echo "       Contains: profession-playbooks/, profession-documents/, professions/, teams/"
fi

echo ""

# ============================================================================
# 2. Build neplex-vectorizer.zip (placeholder)
# ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📦 Building neplex-vectorizer-${VERSION}.zip (placeholder)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

VECTORIZER_ZIP="neplex-vectorizer-${VERSION}.zip"
VEC_TEMP="$(mktemp -d)"

# Create the directory structure with a README.txt
mkdir -p "$VEC_TEMP/neplex-vectorizer"

cat > "$VEC_TEMP/neplex-vectorizer/README.txt" << 'READMEEOF'
Neplex Vectorizer – Now Bundled with the Base Plugin
=====================================================

The neplex-vectorizer library is now included directly in the base
plugin distribution.  This optional-component archive exists only
as a placeholder for compatibility with older download URLs.

If you are running a recent version of the plugin you do not need
to download or install this archive.
READMEEOF

cd "$VEC_TEMP"
zip -r -q "$ROOT_DIR/$OUTPUT_DIR/$VECTORIZER_ZIP" neplex-vectorizer/
cd "$ROOT_DIR"

VEC_SIZE=$(du -h "$OUTPUT_DIR/$VECTORIZER_ZIP" | cut -f1)
echo "  ✅ $VECTORIZER_ZIP created ($VEC_SIZE)"
echo ""

# ---------------------------------------------------------------------------
# Clean up temp directories
# ---------------------------------------------------------------------------
rm -rf "$KB_TEMP" "$VEC_TEMP" 2>/dev/null || true

# ============================================================================
# Also create version-free copies for GitHub release upload.
# The download URL uses /v{VERSION}/ in the path, so the filename
# must be exactly 'knowledge-base.zip' and 'neplex-vectorizer.zip'.
# ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📋 Creating version-free copies for GitHub releases"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

cp "$OUTPUT_DIR/$KB_ZIP" "$OUTPUT_DIR/knowledge-base.zip"
cp "$OUTPUT_DIR/$VECTORIZER_ZIP" "$OUTPUT_DIR/neplex-vectorizer.zip"

echo "  ✅ knowledge-base.zip (copy of $KB_ZIP)"
echo "  ✅ neplex-vectorizer.zip (copy of $VECTORIZER_ZIP)"
echo ""

# ============================================================================
# Summary
# ============================================================================
echo "=========================================="
echo "✅ Build Complete!"
echo "=========================================="
echo ""
echo "Optional component ZIPs for v$VERSION:"
echo ""
ls -lh "$OUTPUT_DIR"/*.zip 2>/dev/null || echo "  (no ZIPs created)"
echo ""
echo "Output directory: $ROOT_DIR/$OUTPUT_DIR/"
echo ""
echo "To create a GitHub release, upload these files as release assets:"
echo "  $OUTPUT_DIR/knowledge-base.zip"
echo "  $OUTPUT_DIR/neplex-vectorizer.zip"
echo ""
