#!/bin/bash
#
# Rebuild All ZIPs
#
# Convenience script to rebuild all plugin ZIP files:
# - Base version (standalone)
# - Pro add-on
# - Combined (base + pro)
# - Core plugin (lightweight)
# - WordPress.org compliant package (with CDN exclusions and text domain transformation)
# - Standalone add-ons (canvas + algorave + embedded + fantasy-football + cornerstone3d + graphify + page-agent)
#
# Usage:
#   ./bin/rebuild-all-zips.sh                          # Rebuild all versions
#   ./bin/rebuild-all-zips.sh --version 1.0.0          # Specify version
#   ./bin/rebuild-all-zips.sh --skip-npm-build         # Use pre-built assets
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# ---------------------------------------------------------------------------
# WSL auto-detection: when running natively on Windows (Git Bash / MSYS2)
# without a working rsync, automatically re-execute inside WSL where the
# full Linux toolchain (rsync, zip, php) is available.
# ---------------------------------------------------------------------------
_wsl_rerun_if_needed() {
	# Only applies to Windows-native shells (MINGW / MSYS)
	case "$(uname -s)" in
		MINGW*|MSYS*) ;;
		*) return 0 ;;
	esac

	# Already running inside WSL? Skip (WSL uname reports "Linux")
	# If rsync is already working natively, skip
	if rsync --version >/dev/null 2>&1; then
		return 0
	fi

	# Check if WSL is available
	if ! command -v wsl >/dev/null 2>&1; then
		return 0
	fi

	# Build WSL-safe paths from the current Git Bash absolute paths.
	# Git Bash gives /f/project; WSL needs /mnt/f/project.
	_wsl_root="$(echo "$ROOT_DIR" | sed 's|^/\([a-zA-Z]\)/|/mnt/\1/|')"
	_wsl_script="$(echo "$0" | sed 's|\\|/|g')"
	# If $0 is a relative path, make it absolute
	case "$_wsl_script" in
		/*) ;;
		*) _wsl_script="$_wsl_root/$_wsl_script" ;;
	esac
	_wsl_script="$(echo "$_wsl_script" | sed 's|^/\([a-zA-Z]\)/|/mnt/\1/|')"

	# Build a safely-escaped argument string for the re-exec
	_wsl_args=""
	for _arg in "$@"; do
		_wsl_args="$_wsl_args $(printf '%q' "$_arg")"
	done
	echo "ℹ️  Windows detected without working rsync → re-executing via WSL..."
	echo ""
	exec wsl bash -c "export PATH=/usr/bin:/bin:/usr/local/bin:$PATH; cd '$_wsl_root' && bash '$_wsl_script' $_wsl_args"
}
_wsl_rerun_if_needed "$@"

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

# Step 0: Rebuild all CSS/JS assets (full build including workflow builder and TMA components)
if [ -n "$SKIP_NPM_ARG" ]; then
    echo "Step 0: Skipping CSS/JS rebuild (--skip-npm-build flag set — using pre-built assets)..."
    echo "✅ Using pre-built frontend assets"
else
    echo "Step 0: Rebuilding all CSS/JS assets..."
    npm ci --silent 2>/dev/null || npm install --silent
    npm run build:full
    echo "✅ All CSS/JS assets rebuilt"
fi
echo ""

# Build all versions using the build-plugin-zip.sh script
# Use --all flag for base, pro, combined, toolkits, and also add --core-only
# Always pass --skip-npm-build since we already ran the full build above
"$SCRIPT_DIR/build-plugin-zip.sh" --all --core-only $VERSION_ARG --skip-npm-build

echo ""
echo "=========================================="
echo "Building Standalone Add-on Packages"
echo "=========================================="
echo ""

# Auto-detect Docker availability: canvas addon requires Docker for native binary
# compilation. When Docker is unavailable (CI runners, lightweight environments),
# skip the canvas build — it has its own dedicated workflow (build-canvas-addon.yml).
ADDON_SKIP_CANVAS=""
if ! command -v docker >/dev/null 2>&1; then
echo "ℹ️  Docker not available — skipping canvas addon build."
echo "   Canvas ZIPs are built by the dedicated 'Build Canvas Addon' workflow."
echo ""
ADDON_SKIP_CANVAS="--skip-canvas"
fi
"$SCRIPT_DIR/build-addon-zips.sh" $ADDON_SKIP_CANVAS

echo ""
echo "=========================================="
echo "Building SPA Add-on ZIPs"
echo "=========================================="
echo ""

# Build remaining SPA addon ZIPs (canvas-toolkit, document-editor, media-studio,
# toolkit-shell) that are NOT covered by build-addon-zips.sh.
if command -v node >/dev/null 2>&1; then
    echo "Running build-spa-zips.js..."
    node "$SCRIPT_DIR/build-spa-zips.js"
else
    echo "⚠️  Node.js not available — skipping SPA addon ZIPs."
fi

echo ""
echo "=========================================="
echo "Building WordPress.org Compliant Package"
echo "=========================================="
echo ""

# Build WordPress.org package from base build (ensures identical functionality)
echo "Creating WordPress.org package from base build..."
"$SCRIPT_DIR/build-wordpress-org-from-base.sh" --version "$VERSION"

echo ""
echo "=========================================="
echo "✅ All ZIPs rebuilt successfully!"
echo "=========================================="
echo ""
echo "📦 Build output in build/:"
ls -lh "$ROOT_DIR/build/"*.zip | awk '{print "   " $9 " (" $5 ")"}'
echo ""
if [ -d "$ROOT_DIR/build/toolkit-addons" ]; then
    echo "📦 Toolkit add-ons in build/toolkit-addons/:"
    ls -lh "$ROOT_DIR/build/toolkit-addons/"*.zip 2>/dev/null | awk '{print "   " $9 " (" $5 ")"}'
    TOOLKIT_COUNT=$(ls -1 "$ROOT_DIR/build/toolkit-addons/"*.zip 2>/dev/null | wc -l)
    echo "   (${TOOLKIT_COUNT} individual toolkit add-on ZIPs)"
    echo ""
fi
if ls "$ROOT_DIR/build"/nvoos-*-linux-x64-v*.zip >/dev/null 2>&1; then
    echo "📦 Standalone add-ons (traditional):"
    ls -lh "$ROOT_DIR/build"/nvoos-*-linux-x64-v*.zip | awk '{print "   " $9 " (" $5 ")"}'
    echo ""
fi
if ls "$ROOT_DIR/build"/nvoos-*-toolkit-v*.zip >/dev/null 2>&1 || \
   ls "$ROOT_DIR/build"/nvoos-document-editor-v*.zip >/dev/null 2>&1 || \
   ls "$ROOT_DIR/build"/nvoos-media-studio-v*.zip >/dev/null 2>&1 || \
   ls "$ROOT_DIR/build"/nvoos-toolkit-shell-v*.zip >/dev/null 2>&1; then
    echo "📦 SPA add-ons (canvas-toolkit, document-editor, media-studio, toolkit-shell):"
    ls -lh "$ROOT_DIR/build"/nvoos-*-toolkit-v*.zip 2>/dev/null | awk '{print "   " $9 " (" $5 ")"}'
    ls -lh "$ROOT_DIR/build"/nvoos-document-editor-v*.zip 2>/dev/null | awk '{print "   " $9 " (" $5 ")"}'
    ls -lh "$ROOT_DIR/build"/nvoos-media-studio-v*.zip 2>/dev/null | awk '{print "   " $9 " (" $5 ")"}'
    ls -lh "$ROOT_DIR/build"/nvoos-toolkit-shell-v*.zip 2>/dev/null | awk '{print "   " $9 " (" $5 ")"}'
    echo ""
fi
echo "📄 WordPress.org submission package:"
WPORG_ZIP_NAME="nvdigital-open-operator-system-oos-${VERSION}.zip"
WPORG_SIZE=$(du -h "$ROOT_DIR/build/$WPORG_ZIP_NAME" | cut -f1)
echo "   build/$WPORG_ZIP_NAME ($WPORG_SIZE)"
echo "   Built from: mcp-ai-wpoos-base-${VERSION}.zip"
echo "   See build/WORDPRESS_ORG_SUBMISSION_README.md for instructions"
