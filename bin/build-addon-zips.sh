#!/bin/bash
#
# Build NV oOS standalone addon ZIPs
#
# Outputs:
#   build/nvoos-canvas-linux-x64-vX.Y.Z.zip
#   build/nvoos-algorave-linux-x64-vX.Y.Z.zip
#   build/nvoos-fantasy-football-vX.Y.Z.zip
#   build/nvoos-cornerstone3d-vX.Y.Z.zip
#   build/nvoos-graphify-vX.Y.Z.zip
#
# Usage:
#   ./bin/build-addon-zips.sh
#   ./bin/build-addon-zips.sh --version 1.0.0
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

VERSION=""
SKIP_CANVAS=false

while [[ $# -gt 0 ]]; do
case $1 in
--version)
VERSION="$2"
shift 2
;;
--skip-canvas)
SKIP_CANVAS=true
shift
;;
-h|--help)
echo "Usage: $0 [--version X.Y.Z] [--skip-canvas]"
exit 0
;;
*)
echo "Unknown option: $1"
exit 1
;;
esac
done

if [ -z "$VERSION" ]; then
VERSION=$(grep -E "^\s*\*\s*Version:" mcp-ai-wpoos.php | sed 's/.*Version:\s*//' | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
VERSION="dev"
fi
fi

if ! command -v zip >/dev/null 2>&1; then
echo "❌ Error: zip is required but not installed."
exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
echo "❌ Error: rsync is required but not installed."
exit 1
fi

if [ "$SKIP_CANVAS" = false ] && ! command -v docker >/dev/null 2>&1; then
echo "❌ Error: docker is required for reproducible canvas linux-x64 build."
echo "   This matches PR #4441 build approach (node:20-bookworm container)."
echo "   Use --skip-canvas to build without the canvas addon."
exit 1
fi

if [ ! -d "addons/algorave" ] || [ ! -d "addons/fantasy-football" ] || [ ! -d "addons/cornerstone3d" ] || [ ! -d "addons/embedded" ] || [ ! -d "addons/graphify" ]; then
echo "❌ Error: addons/algorave, addons/fantasy-football, addons/cornerstone3d, addons/embedded, and addons/graphify must exist."
exit 1
fi

if [ "$SKIP_CANVAS" = false ] && [ ! -d "addons/canvas" ]; then
echo "❌ Error: addons/canvas must exist (or use --skip-canvas to skip it)."
exit 1
fi

OUTPUT_DIR="build"
TMP_DIR="build/.tmp-addon-zips"
mkdir -p "$OUTPUT_DIR"
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"

CANVAS_ZIP="${OUTPUT_DIR}/nvoos-canvas-linux-x64-v${VERSION}.zip"
ALGORAVE_ZIP="${OUTPUT_DIR}/nvoos-algorave-linux-x64-v${VERSION}.zip"
FF_ZIP="${OUTPUT_DIR}/nvoos-fantasy-football-v${VERSION}.zip"
CS3D_ZIP="${OUTPUT_DIR}/nvoos-cornerstone3d-v${VERSION}.zip"
EMBEDDED_ZIP="${OUTPUT_DIR}/nvoos-embedded-v${VERSION}.zip"
GRAPHIFY_ZIP="${OUTPUT_DIR}/nvoos-graphify-v${VERSION}.zip"

rm -f "$ALGORAVE_ZIP" "$FF_ZIP" "$CS3D_ZIP" "$EMBEDDED_ZIP" "$GRAPHIFY_ZIP"
if [ "$SKIP_CANVAS" = false ]; then
rm -f "$CANVAS_ZIP"
fi

if [ "$SKIP_CANVAS" = true ]; then
TOTAL_STEPS=5
else
TOTAL_STEPS=6
fi

echo "=========================================="
echo "Building Standalone Addon ZIPs v${VERSION}"
echo "=========================================="
echo ""

echo "[1/${TOTAL_STEPS}] Building nvoos-algorave-linux-x64-v${VERSION}.zip"
mkdir -p "${TMP_DIR}/algorave-stage/nvoos-algorave"
rsync -a "addons/algorave/" "${TMP_DIR}/algorave-stage/nvoos-algorave/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude 'tests/' \
--exclude 'package-lock.json'
(
cd "${TMP_DIR}/algorave-stage"
zip -r -q "${ROOT_DIR}/${ALGORAVE_ZIP}" nvoos-algorave/
)
ALGORAVE_SIZE=$(du -h "$ALGORAVE_ZIP" | cut -f1)
echo "✅ ${ALGORAVE_ZIP} (${ALGORAVE_SIZE})"
echo ""

echo "[2/${TOTAL_STEPS}] Building nvoos-embedded-v${VERSION}.zip"
mkdir -p "${TMP_DIR}/embedded-stage/nvoos-embedded"
rsync -a "addons/embedded/" "${TMP_DIR}/embedded-stage/nvoos-embedded/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude 'tests/' \
--exclude 'package-lock.json'
(
cd "${TMP_DIR}/embedded-stage"
zip -r -q "${ROOT_DIR}/${EMBEDDED_ZIP}" nvoos-embedded/
)
EMBEDDED_SIZE=$(du -h "$EMBEDDED_ZIP" | cut -f1)
echo "✅ ${EMBEDDED_ZIP} (${EMBEDDED_SIZE})"
echo ""

echo "[3/${TOTAL_STEPS}] Building nvoos-fantasy-football-v${VERSION}.zip"
mkdir -p "${TMP_DIR}/ff-stage/nvoos-fantasy-football"
rsync -a "addons/fantasy-football/" "${TMP_DIR}/ff-stage/nvoos-fantasy-football/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude 'tests/'
(
cd "${TMP_DIR}/ff-stage"
zip -r -q "${ROOT_DIR}/${FF_ZIP}" nvoos-fantasy-football/
)
FF_SIZE=$(du -h "$FF_ZIP" | cut -f1)
echo "✅ ${FF_ZIP} (${FF_SIZE})"
echo ""

echo "[4/${TOTAL_STEPS}] Building nvoos-cornerstone3d-v${VERSION}.zip"
# Build ESM bundles if they don't exist yet.
VENDOR_CORNERSTONE_DIR="addons/pro/assets/vendor/cornerstone"
if [ ! -f "${VENDOR_CORNERSTONE_DIR}/cornerstone-core.esm.js" ]; then
echo "  ℹ️  ESM bundles not found — running bin/vendor-cornerstone.js..."
node bin/vendor-cornerstone.js
fi

mkdir -p "${TMP_DIR}/cs3d-stage/nvoos-cornerstone3d/assets/cornerstone"
# Copy addon PHP files.
rsync -a "addons/cornerstone3d/" "${TMP_DIR}/cs3d-stage/nvoos-cornerstone3d/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude '.gitkeep' \
--exclude 'tests/'

# Copy the vendored ESM bundles into the addon package.
cp "${VENDOR_CORNERSTONE_DIR}/cornerstone-core.esm.js" "${TMP_DIR}/cs3d-stage/nvoos-cornerstone3d/assets/cornerstone/"
cp "${VENDOR_CORNERSTONE_DIR}/cornerstone-tools.esm.js" "${TMP_DIR}/cs3d-stage/nvoos-cornerstone3d/assets/cornerstone/"
cp "${VENDOR_CORNERSTONE_DIR}/cornerstone-dicom-loader.esm.js" "${TMP_DIR}/cs3d-stage/nvoos-cornerstone3d/assets/cornerstone/"
cp "${VENDOR_CORNERSTONE_DIR}/dicom-parser.esm.js" "${TMP_DIR}/cs3d-stage/nvoos-cornerstone3d/assets/cornerstone/"
cp "${VENDOR_CORNERSTONE_DIR}/xmlbuilder2.esm.js" "${TMP_DIR}/cs3d-stage/nvoos-cornerstone3d/assets/cornerstone/"
if [ -f "${VENDOR_CORNERSTONE_DIR}/vendor-meta.json" ]; then
cp "${VENDOR_CORNERSTONE_DIR}/vendor-meta.json" "${TMP_DIR}/cs3d-stage/nvoos-cornerstone3d/assets/cornerstone/"
fi

(
cd "${TMP_DIR}/cs3d-stage"
zip -r -q "${ROOT_DIR}/${CS3D_ZIP}" nvoos-cornerstone3d/
)
CS3D_SIZE=$(du -h "$CS3D_ZIP" | cut -f1)
echo "✅ ${CS3D_ZIP} (${CS3D_SIZE})"
echo ""

echo "[5/${TOTAL_STEPS}] Building nvoos-graphify-v${VERSION}.zip"
mkdir -p "${TMP_DIR}/graphify-stage/nvoos-graphify"
rsync -a "addons/graphify/" "${TMP_DIR}/graphify-stage/nvoos-graphify/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude 'tests/' \
--exclude 'package-lock.json'
(
cd "${TMP_DIR}/graphify-stage"
zip -r -q "${ROOT_DIR}/${GRAPHIFY_ZIP}" nvoos-graphify/
)
GRAPHIFY_SIZE=$(du -h "$GRAPHIFY_ZIP" | cut -f1)
echo "✅ ${GRAPHIFY_ZIP} (${GRAPHIFY_SIZE})"
echo ""

if [ "$SKIP_CANVAS" = true ]; then
echo "[skipped] Canvas addon build skipped (--skip-canvas flag or Docker unavailable)"
echo "  ℹ️  Use the dedicated 'Build Canvas Addon' workflow to build canvas ZIPs."
echo ""
else
echo "[6/${TOTAL_STEPS}] Building nvoos-canvas-linux-x64-v${VERSION}.zip"
mkdir -p "${TMP_DIR}/canvas-work"
rsync -a "addons/canvas/" "${TMP_DIR}/canvas-work/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store'

# Build canvas exactly like PR #4441 workflow: Node 20 Bookworm container.
docker run --rm --platform linux/amd64 \
	-v "${ROOT_DIR}/${TMP_DIR}/canvas-work:/work" \
	-w /work \
	node:20-bookworm \
	bash -lc "apt-get update -qq && \
		apt-get install -y --no-install-recommends \
			build-essential \
			libcairo2-dev \
			libpango1.0-dev \
			libjpeg-dev \
			libgif-dev \
			librsvg2-dev \
			pkg-config >/dev/null && \
		npm install --silent canvas@2 && \
		node scripts/copy-canvas.js"

CANVAS_BINARY="${TMP_DIR}/canvas-work/assets/canvas/build/Release/canvas.node"
if [ ! -f "$CANVAS_BINARY" ]; then
echo "❌ Error: canvas.node binary not found after build: ${CANVAS_BINARY}"
exit 1
fi

mkdir -p "${TMP_DIR}/canvas-work/dist/nvoos-canvas"
rsync -a "${TMP_DIR}/canvas-work/" "${TMP_DIR}/canvas-work/dist/nvoos-canvas/" \
--exclude 'node_modules/' \
--exclude 'scripts/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude 'dist/'

rm -f "${TMP_DIR}/canvas-work/dist/nvoos-canvas/assets/canvas/build/Release/.gitkeep"

(
cd "${TMP_DIR}/canvas-work/dist"
zip -r -q "${ROOT_DIR}/${CANVAS_ZIP}" nvoos-canvas/
)
CANVAS_SIZE=$(du -h "$CANVAS_ZIP" | cut -f1)
echo "✅ ${CANVAS_ZIP} (${CANVAS_SIZE})"
echo ""
fi

echo "=========================================="
echo "Addon ZIP build complete"
echo "=========================================="
echo "  - ${ALGORAVE_ZIP}"
echo "  - ${EMBEDDED_ZIP}"
echo "  - ${FF_ZIP}"
echo "  - ${CS3D_ZIP}"
echo "  - ${GRAPHIFY_ZIP}"
if [ "$SKIP_CANVAS" = false ]; then
echo "  - ${CANVAS_ZIP}"
else
echo "  - (canvas skipped — use Build Canvas Addon workflow)"
fi

# Docker runs as root inside the container, so files created in the mounted
# canvas-work volume are owned by root. Fix ownership before removing so the
# host user can delete them without requiring sudo.
if [ "$SKIP_CANVAS" = false ] && command -v docker >/dev/null 2>&1 && [ -d "${TMP_DIR}/canvas-work" ]; then
docker run --rm \
	-v "${ROOT_DIR}/${TMP_DIR}/canvas-work:/work" \
	node:20-bookworm \
	chown -R "$(id -u):$(id -g)" /work
fi

rm -rf "$TMP_DIR"
