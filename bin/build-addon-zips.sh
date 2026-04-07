#!/bin/bash
#
# Build NV oOS standalone addon ZIPs
#
# Outputs:
#   build/nvoos-canvas-linux-x64-vX.Y.Z.zip
#   build/nvoos-algorave-linux-x64-vX.Y.Z.zip
#   build/nvoos-fantasy-football-vX.Y.Z.zip
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

while [[ $# -gt 0 ]]; do
case $1 in
--version)
VERSION="$2"
shift 2
;;
-h|--help)
echo "Usage: $0 [--version X.Y.Z]"
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

if ! command -v docker >/dev/null 2>&1; then
echo "❌ Error: docker is required for reproducible canvas linux-x64 build."
echo "   This matches PR #4441 build approach (node:20-bookworm container)."
exit 1
fi

if [ ! -d "addons/canvas" ] || [ ! -d "addons/algorave" ] || [ ! -d "addons/fantasy-football" ]; then
echo "❌ Error: addons/canvas, addons/algorave, and addons/fantasy-football must exist."
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

rm -f "$CANVAS_ZIP" "$ALGORAVE_ZIP" "$FF_ZIP"

echo "=========================================="
echo "Building Standalone Addon ZIPs v${VERSION}"
echo "=========================================="
echo ""

echo "[1/3] Building nvoos-algorave-linux-x64-v${VERSION}.zip"
mkdir -p "${TMP_DIR}/algorave-stage/nvoos-algorave"
rsync -a "addons/algorave/" "${TMP_DIR}/algorave-stage/nvoos-algorave/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude 'tests/'
(
cd "${TMP_DIR}/algorave-stage"
zip -r -q "${ROOT_DIR}/${ALGORAVE_ZIP}" nvoos-algorave/
)
ALGORAVE_SIZE=$(du -h "$ALGORAVE_ZIP" | cut -f1)
echo "✅ ${ALGORAVE_ZIP} (${ALGORAVE_SIZE})"
echo ""

echo "[2/3] Building nvoos-fantasy-football-v${VERSION}.zip"
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

echo "[3/3] Building nvoos-canvas-linux-x64-v${VERSION}.zip"
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

echo "=========================================="
echo "Addon ZIP build complete"
echo "=========================================="
echo "  - ${ALGORAVE_ZIP}"
echo "  - ${FF_ZIP}"
echo "  - ${CANVAS_ZIP}"

rm -rf "$TMP_DIR"
