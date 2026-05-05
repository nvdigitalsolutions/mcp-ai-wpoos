#!/usr/bin/env bash
# bin/import-skote.sh — copy a licensed Skote React checkout into addons/skote/src/
#
# Skote is a commercial template (Themesbrand, Envato/ThemeForest) and MUST
# NOT be committed to this repository. Site builders run this script with the
# path to their own licensed Skote checkout, which gets copied into `src/`
# and merged with the addon's integration files (`src/services/wpApi.ts`,
# `src/hooks/*`, `src/index.tsx`, `src/App.tsx`).
#
# Usage:
#   npm run import:skote -- /path/to/skote-react
#   # or
#   bin/import-skote.sh /path/to/skote-react
#
# Idempotent: running again overwrites Skote files but PRESERVES the addon's
# integration files (services/wpApi.ts, hooks/, index.tsx, App.tsx).

set -euo pipefail

if [ "$#" -ne 1 ]; then
	echo "Usage: $0 <path-to-skote-react-checkout>" >&2
	exit 64
fi

SKOTE_SRC="$1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADDON_DIR="$(dirname "$SCRIPT_DIR")"
TARGET_DIR="$ADDON_DIR/src"

if [ ! -d "$SKOTE_SRC" ]; then
	echo "❌ Skote source not found: $SKOTE_SRC" >&2
	exit 66
fi

if [ ! -f "$SKOTE_SRC/package.json" ]; then
	echo "❌ $SKOTE_SRC does not look like a Skote React tree (missing package.json)" >&2
	exit 65
fi

echo "📦 Importing Skote from: $SKOTE_SRC"
echo "    into:                $TARGET_DIR"

# Files we OWN inside src/ — they must survive an import.
PRESERVE=(
	"services/wpApi.ts"
	"hooks/useApps.ts"
	"index.tsx"
	"App.tsx"
)

BACKUP_DIR="$(mktemp -d)"
trap 'rm -rf "$BACKUP_DIR"' EXIT

for rel in "${PRESERVE[@]}"; do
	if [ -f "$TARGET_DIR/$rel" ]; then
		mkdir -p "$BACKUP_DIR/$(dirname "$rel")"
		cp "$TARGET_DIR/$rel" "$BACKUP_DIR/$rel"
	fi
done

# Copy Skote tree.
mkdir -p "$TARGET_DIR"
rsync -a --delete \
	--exclude 'node_modules/' \
	--exclude '.git/' \
	--exclude 'dist/' \
	--exclude 'build/' \
	"$SKOTE_SRC/src/" "$TARGET_DIR/"

# Restore preserved addon files.
for rel in "${PRESERVE[@]}"; do
	if [ -f "$BACKUP_DIR/$rel" ]; then
		mkdir -p "$(dirname "$TARGET_DIR/$rel")"
		cp "$BACKUP_DIR/$rel" "$TARGET_DIR/$rel"
	fi
done

# Drop Skote's fakebackend so a stray import does not silently re-enable mock data.
if [ -d "$TARGET_DIR/helpers/fakeBackend" ]; then
	echo "🗑️  Removing $TARGET_DIR/helpers/fakeBackend"
	rm -rf "$TARGET_DIR/helpers/fakeBackend"
fi
if [ -f "$TARGET_DIR/helpers/fakebackend_helper.js" ]; then
	echo "🗑️  Removing $TARGET_DIR/helpers/fakebackend_helper.js"
	rm -f "$TARGET_DIR/helpers/fakebackend_helper.js"
fi

echo "✅ Skote import complete. Run 'npm install && npm run build' next."
