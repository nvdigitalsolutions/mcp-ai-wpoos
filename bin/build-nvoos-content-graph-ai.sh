#!/bin/bash
#
# Build NV oOS Content Graph AI Addon Plugin ZIP
#
# Produces a distribution ZIP of the nvoos-content-graph-ai plugin
# from plugins/nvoos-content-graph-ai/. This is a companion addon that
# adds AI chat, providers, AI tools, embeddings, and agent memory
# to the standalone nvoos-content-graph plugin. Includes vendor/autoload.php
# for PSR-4 class mapping.
#
# Outputs:
#   build/nvoos-content-graph-ai-v{version}.zip
#
# Usage:
#   ./bin/build-nvoos-content-graph-ai.sh
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# ---------------------------------------------------------------------------
# Read version from plugin header
# ---------------------------------------------------------------------------
_read_plugin_version() {
	grep -E "^\s*\*\s*Version:" "$1" | sed 's/.*Version:\s*//' | tr -d '[:space:]'
}

PLUGIN_DIR="plugins/nvoos-content-graph-ai"
PLUGIN_FILE="${PLUGIN_DIR}/nvoos-content-graph-ai.php"

if [ ! -f "$PLUGIN_FILE" ]; then
	echo "❌ Error: $PLUGIN_FILE not found."
	exit 1
fi

VERSION=$(_read_plugin_version "$PLUGIN_FILE")
VERSION="${VERSION:-dev}"

# ---------------------------------------------------------------------------
# Install composer dependencies BEFORE WSL handoff — composer lives in the
# Windows PATH but may not be visible inside the minimal WSL environment.
# Even when require{} lists only php, composer install --no-dev generates
# vendor/autoload.php, which is necessary for PSR-4 class mapping.
# ---------------------------------------------------------------------------
_composer_install() {
	if [ ! -f "${PLUGIN_DIR}/composer.json" ]; then
		return 0
	fi
	if ! command -v composer >/dev/null 2>&1; then
		echo "⚠️  composer not found — skipping dependency install (vendor/ may be stale)"
		return 0
	fi
	echo "📦 Installing Composer dependencies (no-dev)..."
	( cd "$PLUGIN_DIR" && composer install --no-dev --no-interaction --prefer-dist --quiet )
	echo "✅ Composer dependencies installed."
	echo ""
}
_composer_install

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
	_wsl_args=""
	for _arg in "$@"; do
		_wsl_args="$_wsl_args $(printf '%q' "$_arg")"
	done
	echo "ℹ️  Windows detected without working rsync → re-executing via WSL..."
	echo ""
	exec wsl bash -c "export PATH=/usr/bin:/bin:/usr/local/bin; cd '$_wsl_root' && bash '$_wsl_script' $_wsl_args"
}
_wsl_rerun_if_needed "$@"

echo "=========================================="
echo "Building NV oOS Content Graph AI Addon Plugin"
echo "Version: ${VERSION}"
echo "=========================================="
echo ""

# ---------------------------------------------------------------------------
# Prerequisites (post-WSL — rsync and zip must be available now)
# ---------------------------------------------------------------------------
if ! command -v zip >/dev/null 2>&1; then
	echo "❌ Error: zip is required but not installed."
	exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
	echo "❌ Error: rsync is required but not installed."
	exit 1
fi

# ---------------------------------------------------------------------------
# Second composer pass (no-op if vendor/ already exists from pre-WSL run)
# ---------------------------------------------------------------------------
_composer_install

OUTPUT_DIR="build"
TMP_DIR="build/.tmp-content-graph-ai"
ARTIFACT="nvoos-content-graph-ai-v${VERSION}"
ZIP_FILE="${OUTPUT_DIR}/${ARTIFACT}.zip"

mkdir -p "$OUTPUT_DIR"
rm -rf "$TMP_DIR"
mkdir -p "${TMP_DIR}/${ARTIFACT}/nvoos-content-graph-ai"

# ---------------------------------------------------------------------------
# Remove any previously built ZIPs for this slug
# ---------------------------------------------------------------------------
rm -f "${OUTPUT_DIR}"/nvoos-content-graph-ai-v*.zip
rm -f "${OUTPUT_DIR}"/nvoos-content-graph-ai-v*.sha256

# ---------------------------------------------------------------------------
# Assemble plugin directory for ZIP
# ---------------------------------------------------------------------------
echo "📁 Assembling plugin directory..."
rsync -a "${PLUGIN_DIR}/" "${TMP_DIR}/${ARTIFACT}/nvoos-content-graph-ai/" \
	--exclude '.git/' \
	--exclude '.gitignore' \
	--exclude '.github/' \
	--exclude '.distignore' \
	--exclude 'tests/' \
	--exclude 'phpcs.xml.dist' \
	--exclude 'phpunit.xml.dist' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'README.md' \
	--exclude '.DS_Store'

echo "📁 Plugin directory contents:"
find "${TMP_DIR}/${ARTIFACT}/nvoos-content-graph-ai" -type f | sort
echo ""

# ---------------------------------------------------------------------------
# Verify vendor/autoload.php is present
# ---------------------------------------------------------------------------
if [ ! -f "${TMP_DIR}/${ARTIFACT}/nvoos-content-graph-ai/vendor/autoload.php" ]; then
	echo "❌ Error: vendor/autoload.php is missing — PSR-4 class mapping will not work."
	exit 1
fi

# ---------------------------------------------------------------------------
# Create ZIP
# ---------------------------------------------------------------------------
echo "📦 Creating ${ZIP_FILE}..."
(
	cd "${TMP_DIR}/${ARTIFACT}"
	zip -r -q "${ROOT_DIR}/${ZIP_FILE}" nvoos-content-graph-ai/
)
SIZE=$(du -h "$ZIP_FILE" | cut -f1)
echo "✅ ${ZIP_FILE} (${SIZE})"
echo ""

# ---------------------------------------------------------------------------
# Generate SHA-256 checksum
# ---------------------------------------------------------------------------
if command -v sha256sum >/dev/null 2>&1; then
	( cd "$OUTPUT_DIR" && sha256sum "${ARTIFACT}.zip" > "${ARTIFACT}.zip.sha256" )
	echo "🔐 SHA-256: $(cat "${OUTPUT_DIR}/${ARTIFACT}.zip.sha256")"
elif command -v shasum >/dev/null 2>&1; then
	( cd "$OUTPUT_DIR" && shasum -a 256 "${ARTIFACT}.zip" > "${ARTIFACT}.zip.sha256" )
	echo "🔐 SHA-256: $(cat "${OUTPUT_DIR}/${ARTIFACT}.zip.sha256")"
fi
echo ""

# ---------------------------------------------------------------------------
# Cleanup
# ---------------------------------------------------------------------------
rm -rf "$TMP_DIR"

echo "🎉 NV oOS Content Graph AI addon plugin ZIP built successfully!"
echo "   ${ZIP_FILE}"
