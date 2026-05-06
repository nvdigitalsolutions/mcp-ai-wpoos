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
STRICT_CANVAS=false

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
--strict-canvas)
STRICT_CANVAS=true
shift
;;
-h|--help)
echo "Usage: $0 [--version X.Y.Z] [--skip-canvas] [--strict-canvas]"
echo ""
echo "  --skip-canvas    Skip the canvas addon build entirely."
echo "  --strict-canvas  Exit non-zero if the canvas docker build fails."
echo "                   Default behaviour is to warn and skip canvas while"
echo "                   continuing to build the other addons; canvas has its"
echo "                   own dedicated 'Build Canvas Addon' workflow."
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

# Canvas requires Docker for reproducible linux-x64 native binary compilation
# (matches PR #4441 / build-canvas-addon.yml). When Docker is unavailable, we
# auto-skip canvas with a clear warning instead of failing the whole build —
# canvas has its own dedicated workflow and is not part of the WordPress.org
# distribution. Use --strict-canvas if you need the missing-Docker case to be
# a hard failure (e.g., in a pipeline whose sole purpose is to build canvas).
if [ "$SKIP_CANVAS" = false ] && ! command -v docker >/dev/null 2>&1; then
if [ "$STRICT_CANVAS" = true ]; then
echo "❌ Error: docker is required for reproducible canvas linux-x64 build,"
echo "   and --strict-canvas was set. Install Docker or drop --strict-canvas."
exit 1
fi
echo "ℹ️  Docker not detected — auto-skipping canvas addon build."
echo "   Canvas ZIPs are produced by the dedicated 'Build Canvas Addon' workflow."
echo "   Pass --strict-canvas to make missing Docker a hard failure."
SKIP_CANVAS=true
fi

if [ ! -d "addons/algorave" ] || [ ! -d "addons/fantasy-football" ] || [ ! -d "addons/cornerstone3d" ] || [ ! -d "addons/embedded" ] || [ ! -d "addons/graphify" ] || [ ! -d "addons/saas-controller" ]; then
echo "❌ Error: addons/algorave, addons/fantasy-football, addons/cornerstone3d, addons/embedded, addons/graphify, and addons/saas-controller must exist."
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
SAAS_CONTROLLER_ZIP="${OUTPUT_DIR}/nvoos-saas-controller-v${VERSION}.zip"

rm -f "$ALGORAVE_ZIP" "$FF_ZIP" "$CS3D_ZIP" "$EMBEDDED_ZIP" "$GRAPHIFY_ZIP" "$SAAS_CONTROLLER_ZIP"
if [ "$SKIP_CANVAS" = false ]; then
rm -f "$CANVAS_ZIP"
fi

if [ "$SKIP_CANVAS" = true ]; then
TOTAL_STEPS=6
else
TOTAL_STEPS=7
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

echo "[6/${TOTAL_STEPS}] Building nvoos-saas-controller-v${VERSION}.zip"
# Build the addon's two compiled artifacts (admin UI + Cloudflare Worker)
# from source if Node is available. The release ZIP ships only the built
# artifacts under assets/build/ and worker/dist/ — never node_modules/ or
# the TypeScript / TSX sources.
if [ -d "addons/saas-controller/node_modules" ] || command -v npm >/dev/null 2>&1; then
if [ ! -d "addons/saas-controller/node_modules" ]; then
echo "  ℹ️  Installing saas-controller npm dependencies (npm ci)..."
( cd addons/saas-controller && npm ci --no-audit --no-fund --silent ) || {
echo "⚠️  Warning: npm ci failed for saas-controller — packaging without rebuilt artifacts."
}
fi
if [ -d "addons/saas-controller/node_modules" ]; then
echo "  ℹ️  Building saas-controller artifacts (npm run build)..."
( cd addons/saas-controller && npm run build --silent ) || {
echo "⚠️  Warning: npm run build failed for saas-controller — packaging existing artifacts (if any)."
}
fi
else
echo "  ℹ️  npm not available — packaging existing assets/build/ and worker/dist/ if present."
fi
mkdir -p "${TMP_DIR}/saas-controller-stage/nvoos-saas-controller"
rsync -a "addons/saas-controller/" "${TMP_DIR}/saas-controller-stage/nvoos-saas-controller/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude 'tests/' \
--exclude 'package-lock.json' \
--exclude 'package.json' \
--exclude 'tsconfig.json' \
--exclude 'assets/src/' \
--exclude 'worker/src/' \
--exclude '.wrangler/'
(
cd "${TMP_DIR}/saas-controller-stage"
zip -r -q "${ROOT_DIR}/${SAAS_CONTROLLER_ZIP}" nvoos-saas-controller/
)
SAAS_CONTROLLER_SIZE=$(du -h "$SAAS_CONTROLLER_ZIP" | cut -f1)
echo "✅ ${SAAS_CONTROLLER_ZIP} (${SAAS_CONTROLLER_SIZE})"
echo ""

# Canvas builds a native Linux binary (canvas.node) inside a Docker
# container. This step is best-effort:
#   - Canvas is platform-specific and is NOT part of the WordPress.org
#     distribution.
#   - Canvas has a dedicated workflow (`.github/workflows/build-canvas-addon.yml`)
#     that produces the official linux-x64 / linux-arm64 release artifacts.
#   - When the inner docker build fails (transient apt mirror issue,
#     prebuilt-binary fetch failure, kernel mismatch, etc.), the right
#     behaviour is to skip canvas with a clear diagnostic, NOT to take
#     down the entire `Build Plugin` job along with the other 5 addons,
#     the WordPress.org packages, and the toolkit add-ons.
#
# The `set -e` at the top of this script is intentionally bypassed for
# the docker step using `if ! …; then`, so the main pipeline keeps
# building the remaining packages and exits 0. A non-zero exit can be
# forced with --strict-canvas for environments that require canvas.
canvas_build_failed=0
if [ "$SKIP_CANVAS" = true ]; then
echo "[skipped] Canvas addon build skipped (--skip-canvas flag or Docker unavailable)"
echo "  ℹ️  Use the dedicated 'Build Canvas Addon' workflow to build canvas ZIPs."
echo ""
else
echo "[7/${TOTAL_STEPS}] Building nvoos-canvas-linux-x64-v${VERSION}.zip"

# Defensive re-check: in long-running pipelines the docker daemon may have
# disappeared between the start-of-script check and now. Bail out softly
# rather than producing a misleading "exit code 1 with no output" failure.
if ! command -v docker >/dev/null 2>&1; then
echo "⚠️  Warning: docker no longer available — skipping canvas build."
canvas_build_failed=1
elif ! docker info >/dev/null 2>&1; then
echo "⚠️  Warning: docker daemon not reachable — skipping canvas build."
echo "   (docker is installed but \`docker info\` failed; the build host may"
echo "    not have a running daemon, or the user may lack permission.)"
canvas_build_failed=1
else
mkdir -p "${TMP_DIR}/canvas-work"
rsync -a "addons/canvas/" "${TMP_DIR}/canvas-work/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store'

# Build canvas exactly like PR #4441 workflow: Node 20 Bookworm container.
# Note: apt-get output is no longer redirected to /dev/null — without that,
# CI failures only surface as "Process completed with exit code 1" with
# zero diagnostic context. We keep `-qq` so the log volume stays modest.
CANVAS_LOG="${TMP_DIR}/canvas-build.log"
if docker run --rm --platform linux/amd64 \
	-v "${ROOT_DIR}/${TMP_DIR}/canvas-work:/work" \
	-w /work \
	node:20-bookworm \
	bash -lc "set -e && \
		apt-get update -qq && \
		apt-get install -y -qq --no-install-recommends \
			build-essential \
			libcairo2-dev \
			libpango1.0-dev \
			libjpeg-dev \
			libgif-dev \
			librsvg2-dev \
			pkg-config && \
		npm install --silent canvas@2 && \
		node scripts/copy-canvas.js" \
	>"$CANVAS_LOG" 2>&1; then
	CANVAS_BINARY="${TMP_DIR}/canvas-work/assets/canvas/build/Release/canvas.node"
	if [ ! -f "$CANVAS_BINARY" ]; then
		echo "⚠️  Warning: canvas.node binary not found after build: ${CANVAS_BINARY}"
		echo "   Last 30 lines of canvas build log:"
		tail -n 30 "$CANVAS_LOG" | sed 's/^/     /'
		echo "   Skipping canvas package — use the dedicated 'Build Canvas Addon' workflow."
		canvas_build_failed=1
	else
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
		echo "   Canvas build log: ${CANVAS_LOG}"
	fi
else
	echo "⚠️  Warning: canvas docker build failed (this is non-fatal)."
	echo "   Last 50 lines of canvas build log:"
	tail -n 50 "$CANVAS_LOG" | sed 's/^/     /'
	echo "   Skipping canvas package — use the dedicated 'Build Canvas Addon' workflow."
	canvas_build_failed=1
fi
fi
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
echo "  - ${SAAS_CONTROLLER_ZIP}"
if [ "$SKIP_CANVAS" = false ] && [ "$canvas_build_failed" = 0 ]; then
echo "  - ${CANVAS_ZIP}"
elif [ "$SKIP_CANVAS" = false ] && [ "$canvas_build_failed" = 1 ]; then
echo "  - (canvas BUILD FAILED — use Build Canvas Addon workflow; see log above)"
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
