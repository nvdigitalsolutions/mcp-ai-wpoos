#!/usr/bin/env bash
# One-way sync of the Windows repo into a WSL ext4 working copy.
#
# Purpose: PHPUnit runs on the Windows 9p mount (/mnt/f) suffer a file-stat
# storm (thousands of newfstatat calls across addons/pro/includes/tools/**),
# which intermittently makes a run hang for minutes. Running the same suite
# from an ext4 copy is ~100x faster and stable.
#
# Usage from Windows (sh or PowerShell):
#   wsl -d Ubuntu -- bash -c '~/sync-wpoos.sh'
# Or from inside WSL:
#   ~/sync-wpoos.sh
#
# Then run tests inside WSL:
#   wsl -d Ubuntu -- bash -c 'cd ~/mcp-ai-wpoos-ws && php vendor/bin/phpunit tests/test-foo.php'
#
# Idempotent and incremental; only changed files are transferred.

set -euo pipefail

SRC='/mnt/f/GITHUB/mcp-ai-wpoos'
DEST="$HOME/mcp-ai-wpoos-ws"

if [[ ! -d "$SRC" ]]; then
	echo "Source not mounted at $SRC. Is the F: drive available in WSL?" >&2
	exit 1
fi

mkdir -p "$DEST"

# 1) Repo tree (exclude the giant .git, node_modules, and the Codex env).
rsync -a --no-perms --no-owner --no-group --omit-dir-times \
	--exclude='.git/' --exclude='node_modules/' --exclude='.codex-wordpress/' \
	"$SRC/" "$DEST/"

# 2) WordPress core used by the test bootstrap (SQLite-based, no MySQL).
mkdir -p "$DEST/.codex-wordpress"
rsync -a --no-perms --no-owner --no-group --omit-dir-times \
	--exclude='wp-content/plugins/wp-mcp-ai' \
	--exclude='wp-content/database' \
	--exclude='wp-content/debug.log' \
	"$SRC/.codex-wordpress/wordpress/" "$DEST/.codex-wordpress/wordpress/"

# 3) Point the WP install's plugin dir at the WSL copy (for the dev server).
ln -sfn "$DEST" "$DEST/.codex-wordpress/wordpress/wp-content/plugins/wp-mcp-ai"

echo "SYNCED: $DEST"
