#!/usr/bin/env bash
# ───────────────────────────────────────────────────────────
# NV oOS — Install Optional Test Plugins via WP-CLI
# ───────────────────────────────────────────────────────────
#
# Installs free WordPress plugins from wp.org that the oOS
# plugin integrates with, enabling broader integration-test
# coverage in Docker, CI, and dev-container environments.
#
# The script is **idempotent** — it skips plugins that are
# already installed — so it can be called on every container
# start without penalty.
#
# Premium / non-wp.org plugins (JetEngine, WP All Export Pro)
# cannot be auto-installed. If you place their ZIP files in
# tests/fixtures/plugins/, the script will detect and install
# them if WP-CLI is already configured for the site.
#
# Usage:
#   bin/install-test-plugins.sh              # core plugins only
#   bin/install-test-plugins.sh --all        # core + extended plugins
#   bin/install-test-plugins.sh --premium    # core + extended + fixture plugins
#   bin/install-test-plugins.sh --files-only # unpack zips, skip WP-CLI/activation
#
# The PHPUnit environment created by bin/install-wp-tests.sh downloads WordPress
# core but never writes a wp-config.php or runs `wp core install`, so WP-CLI
# cannot drive it and `wp core is-installed` always fails. The PHPUnit bootstrap
# does not need activated plugins either — it `require_once`s each plugin's main
# file straight off disk. `--files-only` (also auto-selected when WordPress is
# present but not installed) therefore just unpacks the zips into
# $WP_CORE_DIR/wp-content/plugins and stops there.
#
# Environment variables:
#   SKIP_PLUGIN_INSTALL=1   skip everything (useful in CI steps
#                           that don't need plugins)
#
# ───────────────────────────────────────────────────────────

set -euo pipefail

# ── Colour helpers ────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color
info()  { echo -e "${BLUE}[test-plugins]${NC} $1"; }
ok()    { echo -e "${GREEN}  ✓${NC} $1"; }
warn()  { echo -e "${YELLOW}  ⚠${NC} $1"; }
fail()  { echo -e "${RED}  ✗${NC} $1"; }

# ── Parse flags ───────────────────────────────────────────
MODE="core"
FILES_ONLY=0
while [[ $# -gt 0 ]]; do
	case "$1" in
		--all)        MODE="extended"; shift ;;
		--premium)    MODE="premium";  shift ;;
		--files-only) FILES_ONLY=1;    shift ;;
		*)            shift ;;
	esac
done

# Honour skip flag (allows CI to short-circuit).
if [[ "${SKIP_PLUGIN_INSTALL:-0}" == "1" ]]; then
	info "SKIP_PLUGIN_INSTALL=1 — skipping all plugin installation."
	exit 0
fi

# ── Locate wp-cli ───────────────────────────────────────
WP_CLI=""
if [ "$FILES_ONLY" -eq 0 ]; then
	if command -v wp >/dev/null 2>&1; then
		WP_CLI="wp"
	elif [ -x /usr/local/bin/wp ]; then
		WP_CLI="/usr/local/bin/wp"
	elif [ -f /tmp/wp-cli.phar ]; then
		WP_CLI="php /tmp/wp-cli.phar"
	fi

	if [ -z "$WP_CLI" ]; then
		info "WP-CLI not found. Downloading..."
		curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
			-o /tmp/wp-cli.phar 2>/dev/null || {
			warn "Could not download WP-CLI — falling back to file-only installation."
			FILES_ONLY=1
		}
		[ "$FILES_ONLY" -eq 0 ] && WP_CLI="php /tmp/wp-cli.phar"
	fi
fi

# Always pass --allow-root when running as UID 0 (Docker containers).
WP_ARGS=()
if [ "$(id -u)" -eq 0 ]; then
	WP_ARGS+=(--allow-root)
fi

run_wp() {
	$WP_CLI "${WP_ARGS[@]}" "$@"
}

# ── Detect WordPress installation ─────────────────────────
# Try standard Docker locations first, then fall back to
# auto-detection via the WP-CLI phar itself.
WP_ROOT=""
for candidate in \
	/var/www/html \
	/workspace/wordpress \
	"${WP_CORE_DIR:-}" \
	"${WORDPRESS_PATH:-}"; do
	if [ -n "$candidate" ] && [ -f "$candidate/wp-load.php" ]; then
		WP_ROOT="$candidate"
		break
	fi
done

# If we still don't have a root and WP-CLI has a --path, let it
# auto-detect. This handles non-standard setups.
if [ -z "$WP_ROOT" ]; then
	if [ "$FILES_ONLY" -eq 1 ]; then
		warn "Could not locate WordPress installation. Skipping plugin install."
		warn "Set WP_CORE_DIR or WORDPRESS_PATH if using a custom location."
		exit 0
	fi

	# Try running wp eval; if it succeeds then WP-CLI knows where WP is.
	if ! run_wp eval 'echo "ok";' >/dev/null 2>&1; then
		warn "Could not locate WordPress installation. Skipping plugin install."
		warn "Set WP_CORE_DIR or WORDPRESS_PATH if using a custom location."
		exit 0
	fi
fi

WP_PATH_ARG=()
if [ -n "$WP_ROOT" ]; then
	WP_PATH_ARG=(--path="$WP_ROOT")
fi

# ── Ensure WordPress is installed ───────────────────────
if [ "$FILES_ONLY" -eq 0 ] && ! run_wp "${WP_PATH_ARG[@]}" core is-installed 2>/dev/null; then
	# The PHPUnit environment has WordPress on disk but no wp-config.php and no
	# installed database, so WP-CLI can never drive it. The test bootstrap only
	# needs the plugin files, so unpack them instead of giving up.
	if [ -n "$WP_ROOT" ]; then
		info "WordPress is present but not installed (PHPUnit environment)."
		info "Switching to file-only installation — plugins will be unpacked, not activated."
		FILES_ONLY=1
	else
		warn "WordPress is not installed yet. Skipping plugin install."
		warn "Run WordPress setup first, then re-run this script."
		exit 0
	fi
fi

# ── Resolve the plugins directory (file-only mode) ─────────
PLUGINS_DIR=""
if [ "$FILES_ONLY" -eq 1 ]; then
	PLUGINS_DIR="${WP_ROOT%/}/wp-content/plugins"
	if ! mkdir -p "$PLUGINS_DIR" 2>/dev/null; then
		warn "Cannot write to $PLUGINS_DIR. Skipping plugin install."
		exit 0
	fi
	if ! command -v unzip >/dev/null 2>&1; then
		warn "The 'unzip' binary is required for file-only installation. Skipping."
		exit 0
	fi
	info "Target plugins directory: $PLUGINS_DIR"
fi

info "Installing test plugins (mode: $MODE)..."

# ── Helper: unpack a plugin zip straight onto disk ─────────
# Used in file-only mode, where there is no WordPress to activate against.
unpack_plugin_files() {
	local slug="$1"
	local zip_file="/tmp/${slug}.zip"

	if [ -d "${PLUGINS_DIR}/${slug}" ]; then
		ok "$slug (already present)"
		return 0
	fi

	if ! curl -fsSL "https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip" -o "$zip_file" 2>/dev/null; then
		rm -f "$zip_file"
		fail "$slug (download failed)"
		return 1
	fi

	if ! unzip -qo "$zip_file" -d "$PLUGINS_DIR" 2>/dev/null; then
		rm -f "$zip_file"
		fail "$slug (unzip failed)"
		return 1
	fi

	rm -f "$zip_file"
	ok "$slug (unpacked)"
	return 0
}

# ── Helper: install a plugin from wp.org if not already present ──
install_plugin() {
	local slug="$1"
	local activate="${2:-1}"  # 1=activate, 0=install only

	if [ "$FILES_ONLY" -eq 1 ]; then
		unpack_plugin_files "$slug"
		return $?
	fi

	if run_wp "${WP_PATH_ARG[@]}" plugin is-installed "$slug" 2>/dev/null; then
		ok "$slug (already installed)"
		return 0
	fi

	if run_wp "${WP_PATH_ARG[@]}" plugin install "$slug" --activate 2>/dev/null; then
		ok "$slug (installed + activated)"
		return 0
	fi

	# Fallback: some PHP images (e.g. wordpress:cli) lack the ZipArchive
	# extension that `wp plugin install` needs for extraction. Download the
	# zip and unpack it with the unzip binary instead.
	if unpack_plugin_zip "https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip" "$slug" "$activate"; then
		return 0
	fi

	fail "$slug (could not install)"
	return 1
}

# ── Helper: download a plugin zip and unpack it with unzip ────────
# Used when WP-CLI cannot extract zips itself (ZipArchive unavailable).
unpack_plugin_zip() {
	local zip_url="$1"
	local slug="$2"
	local activate="${3:-1}"
	local zip_file="/tmp/${slug}.zip"

	if ! command -v unzip >/dev/null 2>&1; then
		return 1
	fi

	if ! curl -fsSL "$zip_url" -o "$zip_file" 2>/dev/null; then
		rm -f "$zip_file"
		return 1
	fi

	if ! unzip -qo "$zip_file" -d "${WP_ROOT:-.}/wp-content/plugins/" 2>/dev/null; then
		rm -f "$zip_file"
		return 1
	fi
	rm -f "$zip_file"

	if [ "$activate" -eq 1 ]; then
		run_wp "${WP_PATH_ARG[@]}" plugin activate "$slug" 2>/dev/null || true
	fi

	ok "$slug (installed via unzip fallback)"
	return 0
}

# ── Helper: install a plugin from a local ZIP ────────────────
install_plugin_from_zip() {
	local zip_path="$1"
	local slug="$2"

	if [ ! -f "$zip_path" ]; then
		return 1
	fi

	if [ "$FILES_ONLY" -eq 1 ]; then
		if [ -d "${PLUGINS_DIR}/${slug}" ]; then
			ok "$slug (already present)"
			return 0
		fi
		if unzip -qo "$zip_path" -d "$PLUGINS_DIR" 2>/dev/null; then
			ok "$slug (unpacked from $zip_path)"
			return 0
		fi
		fail "$slug (could not unpack $zip_path)"
		return 1
	fi

	if run_wp "${WP_PATH_ARG[@]}" plugin is-installed "$slug" 2>/dev/null; then
		ok "$slug (already installed from local ZIP)"
		return 0
	fi

	if run_wp "${WP_PATH_ARG[@]}" plugin install "$zip_path" --activate 2>/dev/null; then
		ok "$slug (installed from $zip_path)"
		return 0
	else
		fail "$slug (could not install from $zip_path)"
		return 1
	fi
}

# ════════════════════════════════════════════════════════════
# CORE plugins — always installed (free, on wp.org)
# ════════════════════════════════════════════════════════════
echo ""
info "── Core test plugins ──"

CORE_PLUGINS=(
	"woocommerce"
	"elementor"
	"seo-by-rank-math"
	"insert-headers-and-footers"   # WPCode
	"simple-jwt-login"
	"jetformbuilder"               # JetFormBuilder
)

INSTALLED=0
FAILED=0
for slug in "${CORE_PLUGINS[@]}"; do
	if install_plugin "$slug"; then
		((INSTALLED++)) || true
	else
		((FAILED++)) || true
	fi
done

# ════════════════════════════════════════════════════════════
# EXTENDED plugins — installed with --all or --premium
# ════════════════════════════════════════════════════════════
if [[ "$MODE" == "extended" || "$MODE" == "premium" ]]; then
	echo ""
	info "── Extended test plugins ──"

	EXTENDED_PLUGINS=(
		"newsletter"
		"wp-all-import"
	)

	for slug in "${EXTENDED_PLUGINS[@]}"; do
		if install_plugin "$slug"; then
			((INSTALLED++)) || true
		else
			((FAILED++)) || true
		fi
	done
fi

# ════════════════════════════════════════════════════════════
# PREMIUM plugins — installed only with --premium flag,
# sourced from tests/fixtures/plugins/
# ════════════════════════════════════════════════════════════
if [[ "$MODE" == "premium" ]]; then
	echo ""
	info "── Premium / fixture plugins ──"

	SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
	FIXTURES_DIR="${SCRIPT_DIR}/../tests/fixtures/plugins"

	if [ -d "$FIXTURES_DIR" ]; then
		# JetEngine (Crocoblock)
		for zip in "$FIXTURES_DIR"/jet-engine*.zip; do
			if [ -f "$zip" ]; then
				if install_plugin_from_zip "$zip" "jet-engine"; then
					((INSTALLED++)) || true
				else
					((FAILED++)) || true
				fi
			fi
		done

		# JetSmartFilters (Crocoblock)
		for zip in "$FIXTURES_DIR"/jet-smart-filters*.zip; do
			if [ -f "$zip" ]; then
				if install_plugin_from_zip "$zip" "jet-smart-filters"; then
					((INSTALLED++)) || true
				else
					((FAILED++)) || true
				fi
			fi
		done

		# WP All Export (Pro)
		for zip in "$FIXTURES_DIR"/wp-all-export*.zip; do
			if [ -f "$zip" ]; then
				if install_plugin_from_zip "$zip" "wp-all-export-pro"; then
					((INSTALLED++)) || true
				else
					((FAILED++)) || true
				fi
			fi
		done
	else
		warn "No fixtures directory at $FIXTURES_DIR"
		warn "Place premium plugin ZIPs there to auto-install them."
		warn "  e.g. tests/fixtures/plugins/jet-engine-3.5.0.zip"
	fi
fi

# ── Summary ───────────────────────────────────────────────
echo ""
echo "──────────────────────────────────────────────────────────"
if [ "$FAILED" -eq 0 ]; then
	info "All $INSTALLED plugins installed successfully."
else
	warn "$INSTALLED installed, $FAILED failed."
fi

# Print plugin status for visibility.
echo ""
if [ "$FILES_ONLY" -eq 1 ]; then
	echo "Plugin directories in $PLUGINS_DIR:"
	ls -1 "$PLUGINS_DIR" 2>/dev/null | head -30 || true
else
	run_wp "${WP_PATH_ARG[@]}" plugin status 2>/dev/null | head -30 || true
fi
echo ""

# Never fail the build just because optional plugins couldn't
# install — the tests gracefully skip what they can't exercise.
exit 0
