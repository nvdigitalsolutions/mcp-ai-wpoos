#!/bin/bash
#
# Publish @nvdigitalsolutions/nvoos-mcp-bridge to the PUBLIC npm registry.
#
# Companion to .github/workflows/npm-publish-nvoos-mcp-bridge.yml — the same
# gates (sync drift, unit tests, tarball inspection) run locally first, so a
# GitHub Actions run should never fail on a gate.
#
# Usage:
#   ./bin/publish-mcp-bridge.sh                        # gates only
#   ./bin/publish-mcp-bridge.sh --version 0.1.0-alpha.1 --tag alpha --publish
#   ./bin/publish-mcp-bridge.sh --trigger 0.1.0-alpha.1   # run via GitHub Actions
#
# The public registry is deliberate: `npx -y` must work with zero consumer
# config (GitHub Packages requires .npmrc + PAT). See the workflow file for
# the full rationale.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
PKG_DIR="$ROOT_DIR/packages/nvoos-mcp-bridge"

cd "$ROOT_DIR"

VERSION=""
TAG="alpha"
PUBLISH=false
TRIGGER=false

while [[ $# -gt 0 ]]; do
	case $1 in
		--version) VERSION="$2"; shift 2 ;;
		--tag) TAG="$2"; shift 2 ;;
		--publish) PUBLISH=true; shift ;;
		--trigger) TRIGGER=true; shift ;;
		-h|--help)
			echo "Usage: $0 [--version X.Y.Z] [--tag alpha|latest] [--publish] [--trigger]"
			exit 0
			;;
		*) echo "Unknown argument: $1"; exit 2 ;;
	esac
done

if [ "$TRIGGER" = true ]; then
	if ! command -v gh &> /dev/null; then
		echo "❌ GitHub CLI (gh) is not installed." >&2
		exit 1
	fi
	if [ -z "$VERSION" ]; then
		echo "Usage: $0 --trigger <version>"
		exit 2
	fi
	gh workflow run npm-publish-nvoos-mcp-bridge.yml -f version="$VERSION" -f tag="$TAG"
	echo "✅ Workflow triggered. Watch: gh run list --workflow=npm-publish-nvoos-mcp-bridge.yml"
	exit 0
fi

echo "════════════════════════════════════════════════"
echo "nvoos-mcp-bridge → public npm registry"
echo "════════════════════════════════════════════════"

# Gate 1: sync drift check.
echo ""
echo "▶ Gate 1/3 — sync drift (package bin/ ↔ repo bin/ byte-identity)"
(cd "$PKG_DIR" && npm run sync -- --check)

# Gate 2: hermetic unit tests.
echo ""
echo "▶ Gate 2/3 — unit tests"
(cd "$PKG_DIR" && npm test)

# Gate 3: tarball inspection.
echo ""
echo "▶ Gate 3/3 — tarball contents"
(cd "$PKG_DIR" && npm pack --dry-run)

if [ "$PUBLISH" = false ]; then
	echo ""
	echo "✅ All gates passed. Re-run with --publish (and --version/--tag) to publish."
	exit 0
fi

if [ -z "$VERSION" ]; then
	echo "❌ --publish requires --version <semver>." >&2
	exit 2
fi

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9.-]+)?$ ]]; then
	echo "❌ Invalid semver: $VERSION" >&2
	exit 2
fi

if ! npm whoami --registry=https://registry.npmjs.org &> /dev/null; then
	echo "❌ Not logged in to registry.npmjs.org. Run: npm login" >&2
	exit 1
fi

(cd "$PKG_DIR" && npm pkg set version="$VERSION")
(cd "$PKG_DIR" && npm publish --access public --tag "$TAG")

echo ""
echo "✅ Published @nvdigitalsolutions/nvoos-mcp-bridge@$VERSION (tag: $TAG)"
echo "   Test it: npx -y @nvdigitalsolutions/nvoos-mcp-bridge@$TAG"
