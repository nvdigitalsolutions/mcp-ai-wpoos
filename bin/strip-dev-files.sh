#!/usr/bin/env bash
#
# strip-dev-files.sh — remove development, test, and CI files from a
# production deployment of NV oOS (Open Operator System).
#
# Use case: production servers running anti-malware / EDR software
# sometimes flag verbatim attack-payload literals embedded in test
# fixtures (XSS canaries, SQL-injection samples, prompt-injection
# strings, etc.). Because `git clone` always materializes the full
# repository tree, the only way to get a clean tree on a clone-based
# deploy is to remove these files after the clone.
#
# The exclusion list mirrors `.distignore` (used for the WordPress.org
# SVN deployment) and `.gitattributes` `export-ignore` rules (used for
# GitHub-distributed ZIPs). Keep the three lists in sync when adding
# new dev-only directories or files.
#
# Usage:
#   bin/strip-dev-files.sh                # strip in-place
#   bin/strip-dev-files.sh --dry-run      # show what would be removed
#   bin/strip-dev-files.sh --quiet        # suppress per-file output
#   bin/strip-dev-files.sh --help         # show help
#
# Safety:
#   - Idempotent — re-running is a no-op once stripped.
#   - Refuses to run from the repository root unless explicitly invoked.
#   - Refuses to run if the working tree contains uncommitted changes
#     (override with --force) so an accidental run on a developer
#     checkout is recoverable.
#
# Exit codes:
#   0 = success (or nothing to do)
#   1 = invalid invocation / safety check failed
#
# License: GPL-3.0-or-later
# Copyright (c) 2025-2026 NV Digital Solutions

set -euo pipefail

DRY_RUN=0
QUIET=0
FORCE=0

print_help() {
	cat <<'EOF'
strip-dev-files.sh — remove dev/test/docs/CI files from a production NV oOS deploy.

USAGE
    bin/strip-dev-files.sh [OPTIONS]

OPTIONS
    --dry-run     Print what would be removed; do not delete anything.
    --quiet       Suppress per-file output.
    --force       Skip the "uncommitted changes" safety check.
    -h, --help    Show this help and exit.

DESCRIPTION
    Removes development-only files and directories from a production
    deployment of NV oOS that was installed via `git clone`. This is
    intended for production servers where:

      - Anti-malware / EDR software flags test-fixture payload literals.
      - Disk usage or attack surface should be minimized.
      - You do not need to run tests, lint, or build assets on the host.

    Mirrors the exclusion list from `.distignore` and the `export-ignore`
    rules in `.gitattributes`. Re-running the script is idempotent.

EXAMPLE
    cd /var/www/wordpress/wp-content/plugins/mcp-ai-wpoos
    bin/strip-dev-files.sh --dry-run
    bin/strip-dev-files.sh
EOF
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--dry-run)  DRY_RUN=1 ;;
		--quiet)    QUIET=1 ;;
		--force)    FORCE=1 ;;
		-h|--help)  print_help; exit 0 ;;
		*)
			echo "Unknown option: $1" >&2
			echo "Try 'bin/strip-dev-files.sh --help'." >&2
			exit 1
			;;
	esac
	shift
done

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

# Sanity: confirm we are in the NV oOS plugin root by looking for the entry
# point. This avoids accidentally stripping the wrong directory if the
# script is copied elsewhere.
if [[ ! -f "$ROOT_DIR/mcp-ai-wpoos.php" ]]; then
	echo "Error: $ROOT_DIR does not look like the mcp-ai-wpoos plugin root." >&2
	echo "       (mcp-ai-wpoos.php not found.)" >&2
	exit 1
fi

# Safety: refuse to run on a dirty working tree unless --force is given.
# A developer who accidentally runs this on their workstation will lose
# tracked-but-uncommitted edits otherwise.
if [[ $FORCE -eq 0 ]] && command -v git >/dev/null 2>&1 && [[ -d "$ROOT_DIR/.git" ]]; then
	if ! git -C "$ROOT_DIR" diff --quiet --ignore-submodules HEAD -- 2>/dev/null; then
		echo "Error: working tree has uncommitted changes." >&2
		echo "       Commit or stash first, or pass --force to override." >&2
		exit 1
	fi
fi

log() {
	if [[ $QUIET -eq 0 ]]; then
		printf '%s\n' "$*"
	fi
}

# Paths to remove. Each entry is interpreted relative to the plugin root.
# Globs are expanded by Bash; non-matching globs are silently skipped.
# Mirrors .distignore (WordPress.org deploy exclusions) — keep in sync.
DEV_PATHS=(
	# Test infrastructure (the core AV-trigger reduction goal)
	"tests"
	"phpunit.xml"
	"phpunit.xml.dist"
	"coverage"
	".phpunit.result.cache"
	".nyc_output"
	"addons/pro/tests"
	"addons/embedded/tests"

	# Documentation (dev-only; readme.txt / README.md / CHANGELOG.md / CONTRIBUTING.md kept at root)
	"docs"

	# Examples / demos
	"examples"
	"assets/examples"

	# CI / GitHub metadata
	".github"
	".git-branch-info"

	# AI agent and editor configuration (dev-only)
	".agents"
	".bmad"
	".codex"
	".codex-wordpress"
	".context"
	".devcontainer"
	".wordpress-org"
	".idea"
	".vscode"
	".editorconfig"
	".eslintrc.json"
	".eslintignore"
	".browserslistrc"
	".nvmrc"
	".codecov.yml"
	".npmrc"

	# Code-style / lint configs
	"phpcs"
	"phpcs.xml.dist"
	"CODEOWNERS"
	"MAINTAINER_MAP.md"

	# JS/TS build tooling
	"package.json"
	"package-lock.json"
	"babel.config.js"
	"cleancss.config.js"
	"jest.config.js"
	"esbuild.config.js"
	"esbuild.config.pro.js"
	"cosmos.config.json"
	"cosmos.webpack.config.js"
	"webpack.config.js"
	"webpack.config.tma-builder.js"
	"webpack.config.tma.js"
	"webpack.config.workflow.js"
	"tsconfig.json"
	"Makefile"
	"Gruntfile.js"
	"Gulpfile.js"

	# Composer dev metadata (vendor/ itself is kept — production autoload)
	"composer.lock"
	"vendor-dev"

	# JS dependencies (only needed for npm run build)
	"node_modules"

	# Patches and dev scripts
	"patches"
	"patches.lock.json"

	# Source bundles (built artifacts in assets/ are kept)
	"src"

	# Docker (dev-only)
	"docker-compose.yml"
	"Dockerfile"

	# AI-generated archive material (dev-only)
	"archive/wordpress-org-submission"
	"archive/development-phases"
	"archive/production-status"

	# Misc dev artifacts
	"mcp-diagnostic-debug.php"
	"test-storage-service.html"
)

removed=0
skipped=0

remove_path() {
	local path="$1"
	local target="$ROOT_DIR/$path"

	if [[ ! -e "$target" && ! -L "$target" ]]; then
		skipped=$((skipped + 1))
		return 0
	fi

	if [[ $DRY_RUN -eq 1 ]]; then
		log "would remove: $path"
	else
		rm -rf -- "$target"
		log "removed: $path"
	fi
	removed=$((removed + 1))
}

for path in "${DEV_PATHS[@]}"; do
	remove_path "$path"
done

# bin/ is removed last because the running script lives inside it. Bash has
# already loaded this script into memory, so deleting the file on disk does
# not abort execution.
remove_path "bin"

if [[ $DRY_RUN -eq 1 ]]; then
	log ""
	log "Dry run complete: $removed path(s) would be removed, $skipped already absent."
else
	log ""
	log "Strip complete: $removed path(s) removed, $skipped already absent."
fi
