#!/usr/bin/env bash
#
# check-license-boundary.sh — fail the build if the licence boundary between
# the GPL base, the MIT engine, and the proprietary addons has been breached.
#
# The repo is a single tree holding three differently-licensed products:
#
#   includes/              GPL-3.0-or-later   (base plugin, ships to WP.org)
#   lib/core/src/          MIT                (nvoos/core Composer package)
#   addons/pro/includes/   Proprietary        (commercial addon)
#   addons/canvas/         Proprietary        (commercial addon)
#
# A repo is not a licence unit — a distributed artefact is. This script asserts
# both layers: that each source tree declares the right licence in its file
# headers, AND that the packaging rules keep the trees in separate artefacts.
#
# Direction is checked, not just crossing: MIT may be consumed by GPL and by
# proprietary code, but neither may flow back into lib/core, and an MIT header
# inside a proprietary tree is a relicensing bug even though it is not a
# GPL violation.
#
# Usage:  bin/check-license-boundary.sh
# Exit:   0 = boundary intact, 1 = violation(s) found
#
# @package WP_MCP_AI
# SPDX-License-Identifier: GPL-3.0-or-later

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

FAILED=0

pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; FAILED=1; }

# Print the first few offending files so the failure is actionable.
show_offenders() {
	local list="$1"
	local count
	count=$(echo "$list" | grep -c . || true)
	echo "     $count file(s), first 10:"
	echo "$list" | head -10 | sed 's/^/       - /'
}

echo "Licence boundary check"
echo "======================"

# ---------------------------------------------------------------------------
# 1. Packaging rules: proprietary trees must not leak into source archives.
# ---------------------------------------------------------------------------
echo
echo "Packaging rules"

if grep -qE '^/?addons/?[[:space:]]*$' .distignore 2>/dev/null; then
	pass ".distignore excludes addons/ from the base plugin ZIP"
else
	fail ".distignore no longer excludes addons/ — base ZIP may ship proprietary code"
fi

for tree in "addons/pro/" "addons/canvas/"; do
	if grep -qE "^${tree//\//\\/}[[:space:]]+export-ignore" .gitattributes 2>/dev/null; then
		pass ".gitattributes export-ignores ${tree} (git archive / GitHub source ZIP)"
	else
		fail ".gitattributes does not export-ignore ${tree} — proprietary source leaks into release source ZIPs"
	fi
done

# ---------------------------------------------------------------------------
# 2. File headers: no tree may declare a licence it is not licensed under.
# ---------------------------------------------------------------------------
echo
echo "File header declarations"

check_tree() {
	local tree="$1" bad_pattern="$2" label="$3"
	[ -d "$tree" ] || { pass "$tree absent, skipped"; return; }

	local offenders
	offenders=$(grep -rlE "@license.*${bad_pattern}" --include="*.php" "$tree" 2>/dev/null || true)

	if [ -z "$offenders" ]; then
		pass "$tree — no files declare ${label}"
	else
		fail "$tree contains files declaring ${label}"
		show_offenders "$offenders"
	fi
}

# lib/core is MIT (see lib/core/composer.json + lib/core/LICENSE).
check_tree "lib/core/src" "GPL" "GPL (package is MIT)"

# The base plugin is GPL-3.0-or-later.
check_tree "includes" "Proprietary" "Proprietary (package is GPL)"

# The commercial addons are proprietary.
check_tree "addons/pro/includes" "GPL" "GPL (package is Proprietary)"
check_tree "addons/canvas" "GPL" "GPL (package is Proprietary)"

# Direction matters as much as crossing. A permissive header inside a
# proprietary tree is how a relicensing mistake ships quietly: MIT code may be
# consumed by GPL and by proprietary code, but a proprietary file must not
# grant MIT rights. The GPL-versus-Proprietary rule above would not catch a
# mechanical copy of an MIT file into addons/pro.
check_tree "addons/pro/includes" "MIT" "MIT (package is Proprietary)"
check_tree "addons/canvas" "MIT" "MIT (package is Proprietary)"

# The engine must positively declare MIT, not merely avoid declaring GPL.
require_tree() {
	local tree="$1" want_pattern="$2" label="$3"
	[ -d "$tree" ] || { pass "$tree absent, skipped"; return; }

	local offenders
	offenders=$(grep -rLE "@license.*${want_pattern}" --include="*.php" "$tree" 2>/dev/null || true)

	if [ -z "$offenders" ]; then
		pass "$tree — every file declares ${label}"
	else
		fail "$tree contains files NOT declaring ${label}"
		show_offenders "$offenders"
	fi
}

require_tree "lib/core/src" "MIT" "MIT (package is MIT)"

# ---------------------------------------------------------------------------
# 3. Every source file must declare a licence at all.
# ---------------------------------------------------------------------------
echo
echo "Licence presence"

missing_tree() {
	local tree="$1" label="$2"
	[ -d "$tree" ] || return

	local missing
	missing=$(comm -23 \
		<(find "$tree" -name "*.php" -type f | sort) \
		<(grep -rl "@license" --include="*.php" "$tree" 2>/dev/null | sort) 2>/dev/null || true)

	local count
	count=$(echo -n "$missing" | grep -c . || true)

	if [ "$count" -eq 0 ]; then
		pass "$tree — every file declares a licence"
	else
		fail "$tree — $count file(s) declare no @license (${label})"
		show_offenders "$missing"
	fi
}

missing_tree "lib/core/src" "run bin/add-spdx-headers.php --apply"
missing_tree "includes" "run bin/add-spdx-headers.php --apply"
missing_tree "addons/pro/includes" "run bin/add-spdx-headers.php --apply"
missing_tree "addons/canvas" "run bin/add-spdx-headers.php --apply"

# ---------------------------------------------------------------------------
echo
if [ "$FAILED" -ne 0 ]; then
	echo "❌ Licence boundary VIOLATED — see failures above."
	exit 1
fi

echo "✅ Licence boundary intact."
exit 0
