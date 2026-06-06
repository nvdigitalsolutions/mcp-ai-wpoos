#!/bin/bash
#
# Build NV oOS Graphify Ecosystem — All Plugin ZIPs
#
# Master build script for the entire nvoos-graphify plugin ecosystem.
# Calls individual build scripts for each plugin/addon in the right order.
#
# Currently builds:
#   1. nvoos-graphify       (standalone graph plugin)
#   2. nvoos-graphify-ai    (AI companion addon)
#
# Output:
#   build/nvoos-graphify-v{version}.zip
#   build/nvoos-graphify-ai-v{version}.zip
#
# Usage:
#   ./bin/build-graphify-ecosystem.sh
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

echo "=========================================="
echo "Building NV oOS Graphify Ecosystem"
echo "=========================================="
echo ""

BUILD_SCRIPTS=(
	"${SCRIPT_DIR}/build-nvoos-graphify.sh"
	"${SCRIPT_DIR}/build-nvoos-graphify-ai.sh"
)

FAILED=()

for script in "${BUILD_SCRIPTS[@]}"; do
	if [ ! -f "$script" ]; then
		echo "⚠️  Skipping missing script: $script"
		continue
	fi

	echo "▶ Running: $(basename "$script")"
	echo ""

	if ! bash "$script"; then
		echo "❌ Failed: $(basename "$script")"
		FAILED+=("$(basename "$script")")
	else
		echo ""
	fi
done

echo "=========================================="
if [ ${#FAILED[@]} -eq 0 ]; then
	echo "🎉 All Graphify ecosystem ZIPs built successfully!"
else
	echo "❌ ${#FAILED[@]} script(s) failed:"
	for f in "${FAILED[@]}"; do
		echo "   - $f"
	done
	exit 1
fi
echo "=========================================="
