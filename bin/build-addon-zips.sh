#!/bin/bash
#
# Build NV oOS standalone addon ZIPs
#
# Each addon ZIP is versioned from its own plugin header (e.g. "Version: 0.6.0"),
# independent of the base plugin version. Only the base and pro packages share
# a version number.
#
# Outputs (version read from each addon's PHP header):
#   build/nvoos-canvas-linux-x64-v<canvas-version>.zip
#   build/nvoos-algorave-linux-x64-v<algorave-version>.zip
#   build/nvoos-fantasy-football-v<fantasy-football-version>.zip
#   build/nvoos-cornerstone3d-v<cornerstone3d-version>.zip
#   build/nvoos-graphify-v<graphify-version>.zip
#   build/nvoos-embedded-v<embedded-version>.zip
#   build/nvoos-saas-controller-v<saas-controller-version>.zip
#   build/nvoos-comic-reader-v<comic-reader-version>.zip
#   build/nvoos-chat-spa-v<chat-spa-version>.zip
#   build/nvoos-librechat-v<librechat-version>.zip
#   build/nvoos-cloudways-dashboard-v<cloudways-dashboard-version>.zip
#   build/nvoos-funiq-bridge-v<funiq-bridge-version>.zip
#   build/nvoos-crocoblock-ds-v<crocoblock-ds-version>.zip
#   build/nvoos-page-agent-v<page-agent-version>.zip
#   build/nvoos-fleet-operator-v<fleet-operator-version>.zip
#   build/nvoos-checkout-api-v<checkout-api-version>.zip (vendor-side,
#     proprietary — built here so it ships with every addon release batch,
#     but gitignored: never committed to the repository)
#   build/nvoos-fleet-operator-v<fleet-operator-version>.zip
#   build/nvoos-schedule-anything-platform-v<schedule-anything-platform-version>.zip
#
# Usage:
#   ./bin/build-addon-zips.sh
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# ---------------------------------------------------------------------------
# WSL auto-detection: when running natively on Windows (Git Bash / MSYS2)
# without a working rsync, automatically re-execute inside WSL.
# ---------------------------------------------------------------------------
_wsl_rerun_if_needed() {
	case "$(uname -s)" in
		MINGW*|MSYS*) ;;
		*) return 0 ;;
	esac
	if rsync --version >/dev/null 2>&1; then
		return 0
	fi
	if ! command -v wsl >/dev/null 2>&1; then
		return 0
	fi
	_wsl_root="$(echo "$ROOT_DIR" | sed 's|^/\([a-zA-Z]\)/|/mnt/\1/|')"
	_wsl_script="$(echo "$0" | sed 's|\\|/|g')"
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

SKIP_CANVAS=false
STRICT_CANVAS=false

while [[ $# -gt 0 ]]; do
case $1 in
--skip-canvas)
SKIP_CANVAS=true
shift
;;
--strict-canvas)
STRICT_CANVAS=true
shift
;;
-h|--help)
echo "Usage: $0 [--skip-canvas] [--strict-canvas]"
echo ""
echo "  Each addon ZIP is versioned from its own plugin header, independent"
echo "  of the base plugin version."
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

# Read each standalone addon's version from its own plugin header.
_read_addon_version() {
grep -E "^\s*\*\s*Version:" "$1" | sed 's/.*Version:\s*//' | tr -d '[:space:]'
}

ALGORAVE_VERSION=$(_read_addon_version "addons/algorave/nvoos-algorave.php")
ALGORAVE_VERSION=${ALGORAVE_VERSION:-dev}

FF_VERSION=$(_read_addon_version "addons/fantasy-football/nvoos-fantasy-football.php")
FF_VERSION=${FF_VERSION:-dev}

CS3D_VERSION=$(_read_addon_version "addons/cornerstone3d/nvoos-cornerstone3d.php")
CS3D_VERSION=${CS3D_VERSION:-dev}

EMBEDDED_VERSION=$(_read_addon_version "addons/embedded/nvoos-embedded.php")
EMBEDDED_VERSION=${EMBEDDED_VERSION:-dev}

GRAPHIFY_VERSION=$(_read_addon_version "addons/graphify/nvoos-graphify.php")
GRAPHIFY_VERSION=${GRAPHIFY_VERSION:-dev}

SAAS_VERSION=$(_read_addon_version "addons/saas-controller/nvoos-saas-controller.php")
SAAS_VERSION=${SAAS_VERSION:-dev}

CANVAS_VERSION=$(_read_addon_version "addons/canvas/nvoos-canvas.php")
CANVAS_VERSION=${CANVAS_VERSION:-dev}

DOCS_HUB_VERSION=$(_read_addon_version "addons/docs-hub/nvoos-docs-hub.php")
DOCS_HUB_VERSION=${DOCS_HUB_VERSION:-dev}

COMIC_READER_VERSION=$(_read_addon_version "addons/comic-reader/nvoos-comic-reader.php")
COMIC_READER_VERSION=${COMIC_READER_VERSION:-dev}

CHAT_SPA_VERSION=$(_read_addon_version "addons/chat-spa/nvoos-chat-spa.php")
CHAT_SPA_VERSION=${CHAT_SPA_VERSION:-dev}

LIBRECHAT_VERSION=$(_read_addon_version "addons/librechat/nvoos-librechat.php")
LIBRECHAT_VERSION=${LIBRECHAT_VERSION:-dev}

CW_DASHBOARD_VERSION=$(_read_addon_version "addons/cloudways-dashboard/nvoos-cloudways-dashboard.php")
CW_DASHBOARD_VERSION=${CW_DASHBOARD_VERSION:-dev}

FUNIQ_BRIDGE_VERSION=$(_read_addon_version "addons/funiq-bridge/funiq-bridge.php")
FUNIQ_BRIDGE_VERSION=${FUNIQ_BRIDGE_VERSION:-dev}

SAP_VERSION=$(_read_addon_version "addons/schedule-anything-platform/schedule-anything-platform.php")
SAP_VERSION=${SAP_VERSION:-dev}

CDS_VERSION=$(_read_addon_version "addons/crocoblock-ds/nvoos-crocoblock-ds.php")
CDS_VERSION=${CDS_VERSION:-dev}

PAGE_AGENT_VERSION=$(_read_addon_version "addons/page-agent/nvoos-page-agent.php")
PAGE_AGENT_VERSION=${PAGE_AGENT_VERSION:-dev}

FLEET_OPERATOR_VERSION=$(_read_addon_version "addons/fleet-operator/fleet-operator.php")
FLEET_OPERATOR_VERSION=${FLEET_OPERATOR_VERSION:-dev}

CHECKOUT_API_VERSION=$(_read_addon_version "addons/checkout-api/nvoos-checkout-api.php")
CHECKOUT_API_VERSION=${CHECKOUT_API_VERSION:-dev}

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

if [ ! -d "addons/algorave" ] || [ ! -d "addons/fantasy-football" ] || [ ! -d "addons/cornerstone3d" ] || [ ! -d "addons/embedded" ] || [ ! -d "addons/graphify" ] || [ ! -d "addons/docs-hub" ] || [ ! -d "addons/saas-controller" ] || [ ! -d "addons/comic-reader" ] || [ ! -d "addons/chat-spa" ] || [ ! -d "addons/librechat" ] || [ ! -d "addons/cloudways-dashboard" ] || [ ! -d "addons/funiq-bridge" ] || [ ! -d "addons/schedule-anything-platform" ] || [ ! -d "addons/crocoblock-ds" ] || [ ! -d "addons/page-agent" ] || [ ! -d "addons/fleet-operator" ] || [ ! -d "addons/checkout-api" ]; then
echo "❌ Error: addons/algorave, addons/fantasy-football, addons/cornerstone3d, addons/embedded, addons/graphify, addons/docs-hub, addons/saas-controller, addons/comic-reader, addons/chat-spa, addons/librechat, addons/cloudways-dashboard, addons/funiq-bridge, addons/schedule-anything-platform, addons/crocoblock-ds, addons/page-agent, addons/fleet-operator, and addons/checkout-api must exist."
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

CANVAS_ZIP="${OUTPUT_DIR}/nvoos-canvas-linux-x64-v${CANVAS_VERSION}.zip"
ALGORAVE_ZIP="${OUTPUT_DIR}/nvoos-algorave-linux-x64-v${ALGORAVE_VERSION}.zip"
FF_ZIP="${OUTPUT_DIR}/nvoos-fantasy-football-v${FF_VERSION}.zip"
CS3D_ZIP="${OUTPUT_DIR}/nvoos-cornerstone3d-v${CS3D_VERSION}.zip"
EMBEDDED_ZIP="${OUTPUT_DIR}/nvoos-embedded-v${EMBEDDED_VERSION}.zip"
GRAPHIFY_ZIP="${OUTPUT_DIR}/nvoos-graphify-v${GRAPHIFY_VERSION}.zip"
DOCS_HUB_ZIP="${OUTPUT_DIR}/nvoos-docs-hub-v${DOCS_HUB_VERSION}.zip"
SAAS_CONTROLLER_ZIP="${OUTPUT_DIR}/nvoos-saas-controller-v${SAAS_VERSION}.zip"
COMIC_READER_ZIP="${OUTPUT_DIR}/nvoos-comic-reader-v${COMIC_READER_VERSION}.zip"
CHAT_SPA_ZIP="${OUTPUT_DIR}/nvoos-chat-spa-v${CHAT_SPA_VERSION}.zip"
LIBRECHAT_ZIP="${OUTPUT_DIR}/nvoos-librechat-v${LIBRECHAT_VERSION}.zip"
CW_DASHBOARD_ZIP="${OUTPUT_DIR}/nvoos-cloudways-dashboard-v${CW_DASHBOARD_VERSION}.zip"
FUNIQ_BRIDGE_ZIP="${OUTPUT_DIR}/nvoos-funiq-bridge-v${FUNIQ_BRIDGE_VERSION}.zip"
SAP_ZIP="${OUTPUT_DIR}/nvoos-schedule-anything-platform-v${SAP_VERSION}.zip"
CDS_ZIP="${OUTPUT_DIR}/nvoos-crocoblock-ds-v${CDS_VERSION}.zip"
PAGE_AGENT_ZIP="${OUTPUT_DIR}/nvoos-page-agent-v${PAGE_AGENT_VERSION}.zip"
FLEET_OPERATOR_ZIP="${OUTPUT_DIR}/nvoos-fleet-operator-v${FLEET_OPERATOR_VERSION}.zip"
CHECKOUT_API_ZIP="${OUTPUT_DIR}/nvoos-checkout-api-v${CHECKOUT_API_VERSION}.zip"

# Remove any previously built ZIPs for these slugs (they may carry a stale version stamp).
rm -f "$OUTPUT_DIR"/nvoos-algorave-linux-x64-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-fantasy-football-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-cornerstone3d-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-embedded-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-graphify-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-docs-hub-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-saas-controller-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-comic-reader-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-chat-spa-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-librechat-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-cloudways-dashboard-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-funiq-bridge-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-schedule-anything-platform-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-crocoblock-ds-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-page-agent-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-fleet-operator-v*.zip
rm -f "$OUTPUT_DIR"/nvoos-checkout-api-v*.zip
if [ "$SKIP_CANVAS" = false ]; then
rm -f "$OUTPUT_DIR"/nvoos-canvas-linux-x64-v*.zip
fi

if [ "$SKIP_CANVAS" = true ]; then
TOTAL_STEPS=17
else
TOTAL_STEPS=18
fi

echo "=========================================="
echo "Building Standalone Addon ZIPs"
echo "=========================================="
echo ""

echo "[1/${TOTAL_STEPS}] Building nvoos-algorave-linux-x64-v${ALGORAVE_VERSION}.zip"
mkdir -p "${TMP_DIR}/algorave-stage/nvoos-algorave"
rsync -a "addons/algorave/" "${TMP_DIR}/algorave-stage/nvoos-algorave/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude '.distignore' \
--exclude 'tests/' \
--exclude 'package-lock.json'
(
cd "${TMP_DIR}/algorave-stage"
zip -r -q "${ROOT_DIR}/${ALGORAVE_ZIP}" nvoos-algorave/
)
ALGORAVE_SIZE=$(du -h "$ALGORAVE_ZIP" | cut -f1)
echo "✅ ${ALGORAVE_ZIP} (${ALGORAVE_SIZE})"
echo ""

echo "[2/${TOTAL_STEPS}] Building nvoos-embedded-v${EMBEDDED_VERSION}.zip"
mkdir -p "${TMP_DIR}/embedded-stage/nvoos-embedded"
rsync -a "addons/embedded/" "${TMP_DIR}/embedded-stage/nvoos-embedded/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude 'tests/' \
--exclude 'package-lock.json'
(
cd "${TMP_DIR}/embedded-stage"
zip -r -q "${ROOT_DIR}/${EMBEDDED_ZIP}" nvoos-embedded/
)
EMBEDDED_SIZE=$(du -h "$EMBEDDED_ZIP" | cut -f1)
echo "✅ ${EMBEDDED_ZIP} (${EMBEDDED_SIZE})"
echo ""

echo "[3/${TOTAL_STEPS}] Building nvoos-fantasy-football-v${FF_VERSION}.zip"
mkdir -p "${TMP_DIR}/ff-stage/nvoos-fantasy-football"
rsync -a "addons/fantasy-football/" "${TMP_DIR}/ff-stage/nvoos-fantasy-football/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude '.distignore' \
--exclude 'tests/'
(
cd "${TMP_DIR}/ff-stage"
zip -r -q "${ROOT_DIR}/${FF_ZIP}" nvoos-fantasy-football/
)
FF_SIZE=$(du -h "$FF_ZIP" | cut -f1)
echo "✅ ${FF_ZIP} (${FF_SIZE})"
echo ""

echo "[4/${TOTAL_STEPS}] Building nvoos-cornerstone3d-v${CS3D_VERSION}.zip"
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

echo "[5/${TOTAL_STEPS}] Building nvoos-graphify-v${GRAPHIFY_VERSION}.zip"
mkdir -p "${TMP_DIR}/graphify-stage/nvoos-graphify"
rsync -a "addons/graphify/" "${TMP_DIR}/graphify-stage/nvoos-graphify/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude 'tests/' \
--exclude 'package-lock.json'
(
cd "${TMP_DIR}/graphify-stage"
zip -r -q "${ROOT_DIR}/${GRAPHIFY_ZIP}" nvoos-graphify/
)
GRAPHIFY_SIZE=$(du -h "$GRAPHIFY_ZIP" | cut -f1)
echo "✅ ${GRAPHIFY_ZIP} (${GRAPHIFY_SIZE})"
echo ""

echo "[6/${TOTAL_STEPS}] Building nvoos-docs-hub-v${DOCS_HUB_VERSION}.zip"
# Build the React SPA if Node is available, then package.
if [ -d "addons/docs-hub/node_modules" ] || command -v npm >/dev/null 2>&1; then
if [ ! -d "addons/docs-hub/node_modules" ]; then
echo "  ℹ️  Installing docs-hub npm dependencies (npm ci)..."
( cd addons/docs-hub && npm ci --no-audit --no-fund --silent ) || {
echo "⚠️  Warning: npm ci failed for docs-hub — packaging without rebuilt artifacts."
}
fi
if [ -d "addons/docs-hub/node_modules" ]; then
echo "  ℹ️  Building docs-hub artifacts (npm run build)..."
( cd addons/docs-hub && npm run build --silent ) || {
echo "⚠️  Warning: npm run build failed for docs-hub — packaging existing artifacts (if any)."
}
fi
else
echo "  ℹ️  npm not available — packaging existing assets/dist/ if present."
fi
mkdir -p "${TMP_DIR}/docs-hub-stage/nvoos-docs-hub"
rsync -a "addons/docs-hub/" "${TMP_DIR}/docs-hub-stage/nvoos-docs-hub/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude 'tests/' \
--exclude 'package-lock.json' \
--exclude 'package.json' \
--exclude 'tsconfig.json' \
--exclude 'esbuild.config.js' \
--exclude 'eslint.config.js' \
--exclude 'vitest.config.ts' \
--exclude 'src/'
(
cd "${TMP_DIR}/docs-hub-stage"
zip -r -q "${ROOT_DIR}/${DOCS_HUB_ZIP}" nvoos-docs-hub/
)
DOCS_HUB_SIZE=$(du -h "$DOCS_HUB_ZIP" | cut -f1)
echo "✅ ${DOCS_HUB_ZIP} (${DOCS_HUB_SIZE})"
echo ""

echo "[7/${TOTAL_STEPS}] Building nvoos-saas-controller-v${SAAS_VERSION}.zip"
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
--exclude '.gitignore' \
--exclude '.distignore' \
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

echo "[8/${TOTAL_STEPS}] Building nvoos-comic-reader-v${COMIC_READER_VERSION}.zip"
# Build the React SPA if Node is available, then package.
if [ -d "addons/comic-reader/node_modules" ] || command -v npm >/dev/null 2>&1; then
if [ ! -d "addons/comic-reader/node_modules" ]; then
echo "  ℹ️  Installing comic-reader npm dependencies (npm ci)..."
( cd addons/comic-reader && npm ci --no-audit --no-fund --silent ) || {
echo "⚠️  Warning: npm ci failed for comic-reader — packaging without rebuilt artifacts."
}
fi
if [ -d "addons/comic-reader/node_modules" ]; then
echo "  ℹ️  Building comic-reader artifacts (npm run build)..."
( cd addons/comic-reader && npm run build --silent ) || {
echo "⚠️  Warning: npm run build failed for comic-reader — packaging existing artifacts (if any)."
}
fi
else
echo "  ℹ️  npm not available — packaging existing assets/dist/ if present."
fi
mkdir -p "${TMP_DIR}/comic-reader-stage/nvoos-comic-reader"
rsync -a "addons/comic-reader/" "${TMP_DIR}/comic-reader-stage/nvoos-comic-reader/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude 'tests/' \
--exclude 'package-lock.json' \
--exclude 'package.json' \
--exclude 'tsconfig.json' \
--exclude 'vitest.config.ts' \
--exclude 'esbuild.config.cjs' \
--exclude 'src/'
(
cd "${TMP_DIR}/comic-reader-stage"
zip -r -q "${ROOT_DIR}/${COMIC_READER_ZIP}" nvoos-comic-reader/
)
COMIC_READER_SIZE=$(du -h "$COMIC_READER_ZIP" | cut -f1)
echo "✅ ${COMIC_READER_ZIP} (${COMIC_READER_SIZE})"
echo ""

echo "[9/${TOTAL_STEPS}] Building nvoos-chat-spa-v${CHAT_SPA_VERSION}.zip"
# Build the React SPA if Node is available, then package.
if [ -d "addons/chat-spa/node_modules" ] || command -v npm >/dev/null 2>&1; then
if [ ! -d "addons/chat-spa/node_modules" ]; then
echo "  ℹ️  Installing chat-spa npm dependencies (npm ci)..."
( cd addons/chat-spa && npm ci --no-audit --no-fund --silent ) || {
echo "⚠️  Warning: npm ci failed for chat-spa — packaging without rebuilt artifacts."
}
fi
if [ -d "addons/chat-spa/node_modules" ]; then
echo "  ℹ️  Building chat-spa artifacts (npm run build)..."
( cd addons/chat-spa && npm run build --silent ) || {
echo "⚠️  Warning: npm run build failed for chat-spa — packaging existing artifacts (if any)."
}
fi
else
echo "  ℹ️  npm not available — packaging existing assets/dist/ if present."
fi
mkdir -p "${TMP_DIR}/chat-spa-stage/nvoos-chat-spa"
rsync -a "addons/chat-spa/" "${TMP_DIR}/chat-spa-stage/nvoos-chat-spa/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude 'tests/' \
--exclude 'package-lock.json' \
--exclude 'package.json' \
--exclude 'tsconfig.json' \
--exclude 'vitest.config.ts' \
--exclude 'esbuild.config.cjs' \
--exclude 'eslint.config.js' \
--exclude 'src/'
(
cd "${TMP_DIR}/chat-spa-stage"
zip -r -q "${ROOT_DIR}/${CHAT_SPA_ZIP}" nvoos-chat-spa/
)
CHAT_SPA_SIZE=$(du -h "$CHAT_SPA_ZIP" | cut -f1)
echo "✅ ${CHAT_SPA_ZIP} (${CHAT_SPA_SIZE})"
echo ""

echo "[10/${TOTAL_STEPS}] Building nvoos-librechat-v${LIBRECHAT_VERSION}.zip"
# Build the React SPA if Node is available, then package.
if [ -d "addons/librechat/node_modules" ] || command -v npm >/dev/null 2>&1; then
if [ ! -d "addons/librechat/node_modules" ]; then
echo "  ℹ️  Installing librechat npm dependencies (npm ci)..."
( cd addons/librechat && npm ci --no-audit --no-fund --silent ) || {
echo "⚠️  Warning: npm ci failed for librechat — packaging without rebuilt artifacts."
}
fi
if [ -d "addons/librechat/node_modules" ]; then
echo "  ℹ️  Building librechat artifacts (npm run build)..."
( cd addons/librechat && npm run build --silent ) || {
echo "⚠️  Warning: npm run build failed for librechat — packaging existing artifacts (if any)."
}
fi
else
echo "  ℹ️  npm not available — packaging existing assets/dist/ if present."
fi
mkdir -p "${TMP_DIR}/librechat-stage/nvoos-librechat"
rsync -a "addons/librechat/" "${TMP_DIR}/librechat-stage/nvoos-librechat/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude 'tests/' \
--exclude 'package-lock.json' \
--exclude 'package.json' \
--exclude 'tsconfig.json' \
--exclude 'esbuild.config.cjs' \
--exclude 'src/'
(
cd "${TMP_DIR}/librechat-stage"
zip -r -q "${ROOT_DIR}/${LIBRECHAT_ZIP}" nvoos-librechat/
)
LIBRECHAT_SIZE=$(du -h "$LIBRECHAT_ZIP" | cut -f1)
echo "✅ ${LIBRECHAT_ZIP} (${LIBRECHAT_SIZE})"
echo ""

echo "[11/${TOTAL_STEPS}] Building nvoos-cloudways-dashboard-v${CW_DASHBOARD_VERSION}.zip"
# Build the React SPA if Node is available, then package.
if [ -d "addons/cloudways-dashboard/node_modules" ] || command -v npm >/dev/null 2>&1; then
if [ ! -d "addons/cloudways-dashboard/node_modules" ]; then
echo "  ℹ️  Installing cloudways-dashboard npm dependencies (npm ci)..."
( cd addons/cloudways-dashboard && npm ci --no-audit --no-fund --silent ) || {
echo "⚠️  Warning: npm ci failed for cloudways-dashboard — packaging without rebuilt artifacts."
}
fi
if [ -d "addons/cloudways-dashboard/node_modules" ]; then
echo "  ℹ️  Building cloudways-dashboard artifacts (npm run build)..."
( cd addons/cloudways-dashboard && npm run build --silent ) || {
echo "⚠️  Warning: npm run build failed for cloudways-dashboard — packaging existing artifacts (if any)."
}
fi
else
echo "  ℹ️  npm not available — packaging existing assets/dist/ if present."
fi
mkdir -p "${TMP_DIR}/cw-dashboard-stage/nvoos-cloudways-dashboard"
rsync -a "addons/cloudways-dashboard/" "${TMP_DIR}/cw-dashboard-stage/nvoos-cloudways-dashboard/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude 'tests/' \
--exclude 'package-lock.json' \
--exclude 'package.json' \
--exclude 'tsconfig.json' \
--exclude 'esbuild.config.cjs' \
--exclude 'eslint.config.js' \
--exclude 'src/'
(
cd "${TMP_DIR}/cw-dashboard-stage"
zip -r -q "${ROOT_DIR}/${CW_DASHBOARD_ZIP}" nvoos-cloudways-dashboard/
)
CW_DASHBOARD_SIZE=$(du -h "$CW_DASHBOARD_ZIP" | cut -f1)
echo "✅ ${CW_DASHBOARD_ZIP} (${CW_DASHBOARD_SIZE})"
echo ""

echo "[12/${TOTAL_STEPS}] Building nvoos-funiq-bridge-v${FUNIQ_BRIDGE_VERSION}.zip"
# Build the React SPA if Node is available, then package.
if [ -d "addons/funiq-bridge/node_modules" ] || command -v npm >/dev/null 2>&1; then
if [ ! -d "addons/funiq-bridge/node_modules" ]; then
echo "  ℹ️  Installing funiq-bridge npm dependencies (npm ci)..."
( cd addons/funiq-bridge && npm ci --no-audit --no-fund --silent ) || {
echo "⚠️  Warning: npm ci failed for funiq-bridge — packaging without rebuilt artifacts."
}
fi
if [ -d "addons/funiq-bridge/node_modules" ]; then
echo "  ℹ️  Building funiq-bridge artifacts (npm run build)..."
( cd addons/funiq-bridge && npm run build --silent ) || {
echo "⚠️  Warning: npm run build failed for funiq-bridge — packaging existing artifacts (if any)."
}
fi
else
echo "  ℹ️  npm not available — packaging existing assets/dist/ if present."
fi
mkdir -p "${TMP_DIR}/funiq-bridge-stage/nvoos-funiq-bridge"
rsync -a "addons/funiq-bridge/" "${TMP_DIR}/funiq-bridge-stage/nvoos-funiq-bridge/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude 'tests/' \
--exclude 'package-lock.json' \
--exclude 'package.json' \
--exclude 'tsconfig.json' \
--exclude 'esbuild.config.cjs' \
--exclude 'eslint.config.js' \
--exclude 'src/'
(
cd "${TMP_DIR}/funiq-bridge-stage"
zip -r -q "${ROOT_DIR}/${FUNIQ_BRIDGE_ZIP}" nvoos-funiq-bridge/
)
FUNIQ_BRIDGE_SIZE=$(du -h "$FUNIQ_BRIDGE_ZIP" | cut -f1)
echo "✅ ${FUNIQ_BRIDGE_ZIP} (${FUNIQ_BRIDGE_SIZE})"
echo ""

echo "[13/${TOTAL_STEPS}] Building nvoos-schedule-anything-platform-v${SAP_VERSION}.zip"
mkdir -p "${TMP_DIR}/sap-stage/nvoos-schedule-anything-platform"
rsync -a "addons/schedule-anything-platform/" "${TMP_DIR}/sap-stage/nvoos-schedule-anything-platform/" \
--exclude 'node_modules/' \
--exclude '.git/' \
--exclude '.DS_Store' \
--exclude '.gitignore' \
--exclude 'tests/' \
--exclude 'package-lock.json'
(
cd "${TMP_DIR}/sap-stage"
zip -r -q "${ROOT_DIR}/${SAP_ZIP}" nvoos-schedule-anything-platform/
)
SAP_SIZE=$(du -h "$SAP_ZIP" | cut -f1)
echo "✅ ${SAP_ZIP} (${SAP_SIZE})"
echo ""

echo "[14/${TOTAL_STEPS}] Building nvoos-crocoblock-ds-v${CDS_VERSION}.zip"
# Pure PHP addon — no JS/npm build step required.
mkdir -p "${TMP_DIR}/cds-stage/nvoos-crocoblock-ds"
rsync -a "addons/crocoblock-ds/" "${TMP_DIR}/cds-stage/nvoos-crocoblock-ds/" \
	--exclude 'node_modules/' \
	--exclude '.git/' \
	--exclude '.DS_Store' \
	--exclude '.gitignore' \
	--exclude 'tests/' \
	--exclude 'package-lock.json'
(
	cd "${TMP_DIR}/cds-stage"
	zip -r -q "${ROOT_DIR}/${CDS_ZIP}" nvoos-crocoblock-ds/
)
CDS_SIZE=$(du -h "$CDS_ZIP" | cut -f1)
echo "✅ ${CDS_ZIP} (${CDS_SIZE})"
echo ""

echo "[15/${TOTAL_STEPS}] Building nvoos-page-agent-v${PAGE_AGENT_VERSION}.zip"
# Build the JavaScript bundles if Node is available, then package.
if [ -d "addons/page-agent/node_modules" ] || command -v npm >/dev/null 2>&1; then
	if [ ! -d "addons/page-agent/node_modules" ]; then
		echo "  ℹ️  Installing page-agent npm dependencies (npm ci)..."
		( cd addons/page-agent && npm ci --no-audit --no-fund --silent ) || {
			echo "⚠️  Warning: npm ci failed for page-agent — packaging without rebuilt artifacts."
		}
	fi
	if [ -d "addons/page-agent/node_modules" ]; then
		echo "  ℹ️  Building page-agent artifacts (npx esbuild --prod)..."
		( cd addons/page-agent && node esbuild.config.js --prod ) || {
			echo "⚠️  Warning: esbuild failed for page-agent — packaging existing artifacts (if any)."
		}
	fi
else
	echo "  ℹ️  npm not available — packaging existing assets/js/ if present."
fi
mkdir -p "${TMP_DIR}/page-agent-stage/nvoos-page-agent"
rsync -a "addons/page-agent/" "${TMP_DIR}/page-agent-stage/nvoos-page-agent/" \
	--exclude 'node_modules/' \
	--exclude '.git/' \
	--exclude '.DS_Store' \
	--exclude '.gitignore' \
	--exclude 'tests/' \
	--exclude 'package-lock.json' \
	--exclude 'src/'
(
	cd "${TMP_DIR}/page-agent-stage"
	zip -r -q "${ROOT_DIR}/${PAGE_AGENT_ZIP}" nvoos-page-agent/
)
PAGE_AGENT_SIZE=$(du -h "$PAGE_AGENT_ZIP" | cut -f1)
echo "✅ ${PAGE_AGENT_ZIP} (${PAGE_AGENT_SIZE})"
echo ""

echo "[16/${TOTAL_STEPS}] Building nvoos-fleet-operator-v${FLEET_OPERATOR_VERSION}.zip"
# Pure PHP addon — no JS/npm build step required. `.context/` holds agent
# context notes only and is not shipped in the distribution ZIP.
mkdir -p "${TMP_DIR}/fleet-operator-stage/nvoos-fleet-operator"
rsync -a "addons/fleet-operator/" "${TMP_DIR}/fleet-operator-stage/nvoos-fleet-operator/" \
	--exclude 'node_modules/' \
	--exclude '.git/' \
	--exclude '.DS_Store' \
	--exclude '.gitignore' \
	--exclude 'tests/' \
	--exclude '.context/'
(
	cd "${TMP_DIR}/fleet-operator-stage"
	zip -r -q "${ROOT_DIR}/${FLEET_OPERATOR_ZIP}" nvoos-fleet-operator/
)
FLEET_OPERATOR_SIZE=$(du -h "$FLEET_OPERATOR_ZIP" | cut -f1)
echo "✅ ${FLEET_OPERATOR_ZIP} (${FLEET_OPERATOR_SIZE})"
echo ""

echo "[17/${TOTAL_STEPS}] Building nvoos-checkout-api-v${CHECKOUT_API_VERSION}.zip"
# Vendor-side proprietary addon — pure PHP, no JS/npm build step. Built
# alongside the distributed addons so every release batch produces a
# deployable vendor ZIP, but the ZIP is gitignored (never committed).
# The dedicated 'Build NV oOS Checkout API Plugin' workflow mirrors these
# excludes and additionally runs the addon's PHPUnit suite.
mkdir -p "${TMP_DIR}/checkout-api-stage/nvoos-checkout-api"
rsync -a "addons/checkout-api/" "${TMP_DIR}/checkout-api-stage/nvoos-checkout-api/" \
	--exclude '.git/' \
	--exclude '.gitignore' \
	--exclude '.github/' \
	--exclude '.distignore' \
	--exclude '.DS_Store' \
	--exclude 'node_modules/' \
	--exclude 'tests/' \
	--exclude 'phpcs.xml.dist'
(
	cd "${TMP_DIR}/checkout-api-stage"
	zip -r -q "${ROOT_DIR}/${CHECKOUT_API_ZIP}" nvoos-checkout-api/
)
CHECKOUT_API_SIZE=$(du -h "$CHECKOUT_API_ZIP" | cut -f1)
echo "✅ ${CHECKOUT_API_ZIP} (${CHECKOUT_API_SIZE}) — vendor-side, gitignored"
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
echo "[18/${TOTAL_STEPS}] Building nvoos-canvas-linux-x64-v${CANVAS_VERSION}.zip"

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
echo "  - ${COMIC_READER_ZIP}"
echo "  - ${CHAT_SPA_ZIP}"
echo "  - ${LIBRECHAT_ZIP}"
echo "  - ${CW_DASHBOARD_ZIP}"
echo "  - ${FUNIQ_BRIDGE_ZIP}"
echo "  - ${SAP_ZIP}"
echo "  - ${CDS_ZIP}"
echo "  - ${PAGE_AGENT_ZIP}"
echo "  - ${FLEET_OPERATOR_ZIP}"
echo "  - ${CHECKOUT_API_ZIP} (vendor-side, gitignored)"
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
