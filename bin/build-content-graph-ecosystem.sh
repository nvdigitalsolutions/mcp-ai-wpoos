#!/bin/bash
#
# Build NV oOS Content Graph Ecosystem — All Plugin ZIPs
#
# Master build script for the entire nvoos-content-graph plugin ecosystem.
# Calls individual build scripts for each plugin/addon in the right order.
#
# Currently builds:
#   1. nvoos-content-graph       (standalone graph plugin)
#   2. nvoos-content-graph-ai    (AI companion addon)
#
# Output:
#   build/nvoos-content-graph-v{version}.zip
#   build/nvoos-content-graph-ai-v{version}.zip
#
# Usage:
#   ./bin/build-content-graph-ecosystem.sh
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

echo "=========================================="
echo "Building NV oOS Content Graph Ecosystem"
echo "=========================================="
echo ""

BUILD_SCRIPTS=(
	"${SCRIPT_DIR}/build-nvoos-content-graph.sh"
	"${SCRIPT_DIR}/build-nvoos-content-graph-ai.sh"
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
	echo "🎉 All Content Graph ecosystem ZIPs built successfully!"
else
	echo "❌ ${#FAILED[@]} script(s) failed:"
	for f in "${FAILED[@]}"; do
		echo "   - $f"
	done
	exit 1
fi
echo "=========================================="
