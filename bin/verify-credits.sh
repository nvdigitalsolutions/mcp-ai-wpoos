#!/usr/bin/env bash
# verify-credits.sh
#
# Cross-check that every third-party dependency declared in Composer/npm
# manifests is mentioned in CREDITS.md. Surfaces any package missing an
# attribution entry so contributors can update CREDITS.md before merging.
#
# Usage:
#   bin/verify-credits.sh        # default — exits non-zero on missing entries
#   bin/verify-credits.sh --warn # warning-only mode (always exit 0)
#
# Scope:
#   - composer.lock                  (base PHP packages)
#   - addons/pro/composer.lock       (Pro PHP packages)
#   - package.json                   (base npm dependencies — top-level only)
#   - addons/algorave/package.json
#   - addons/saas-controller/package.json
#   - addons/pro/package.json
#   - assets/js/vendor/*             (vendored JS at base)
#   - addons/*/assets/{js/,}vendor/* (vendored JS in addons)
#
# Build-only deps (devDependencies in package.json) are intentionally
# skipped — they don't ship with the plugin.

set -u

MODE="strict"
if [ "${1:-}" = "--warn" ]; then
  MODE="warn"
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CREDITS="$ROOT/CREDITS.md"

if [ ! -f "$CREDITS" ]; then
  echo "FATAL: $CREDITS is missing." >&2
  exit 2
fi

missing=()

# Helper: check whether a package name appears anywhere in CREDITS.md.
in_credits() {
  local needle="$1"
  # Match either backtick-quoted identifier or a plain mention; case-sensitive.
  grep -F -q -- "$needle" "$CREDITS"
}

extract_composer_packages() {
  local lockfile="$1"
  if [ ! -f "$lockfile" ]; then
    return
  fi
  python3 - "$lockfile" <<'PY'
import json, sys
data = json.load(open(sys.argv[1]))
for p in data.get("packages", []):
    print(p["name"])
PY
}

extract_npm_dependencies() {
  local pkgjson="$1"
  if [ ! -f "$pkgjson" ]; then
    return
  fi
  python3 - "$pkgjson" <<'PY'
import json, sys
pkg = json.load(open(sys.argv[1]))
for k in (pkg.get("dependencies") or {}):
    print(k)
PY
}

list_vendor_dirs() {
  # Print one immediate subdirectory per line.
  local parent="$1"
  if [ -d "$parent" ]; then
    find "$parent" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null
  fi
}

check_list() {
  local label="$1"
  shift
  for name in "$@"; do
    if [ -z "$name" ]; then
      continue
    fi
    if ! in_credits "$name"; then
      missing+=("$label: $name")
    fi
  done
}

# --- Composer (base + Pro) ---
mapfile -t base_php < <(extract_composer_packages "$ROOT/composer.lock")
check_list "composer/base" "${base_php[@]}"

mapfile -t pro_php < <(extract_composer_packages "$ROOT/addons/pro/composer.lock")
check_list "composer/pro" "${pro_php[@]}"

# --- npm dependencies (production only) ---
mapfile -t base_npm < <(extract_npm_dependencies "$ROOT/package.json")
check_list "npm/base" "${base_npm[@]}"

mapfile -t algorave_npm < <(extract_npm_dependencies "$ROOT/addons/algorave/package.json")
check_list "npm/algorave" "${algorave_npm[@]}"

mapfile -t saas_controller_npm < <(extract_npm_dependencies "$ROOT/addons/saas-controller/package.json")
check_list "npm/saas-controller" "${saas_controller_npm[@]}"

mapfile -t pro_npm < <(extract_npm_dependencies "$ROOT/addons/pro/package.json")
check_list "npm/pro" "${pro_npm[@]}"

# --- Vendored JS directories ---
mapfile -t base_vendor < <(list_vendor_dirs "$ROOT/assets/js/vendor")
check_list "vendor/base" "${base_vendor[@]}"

mapfile -t algorave_vendor < <(list_vendor_dirs "$ROOT/addons/algorave/assets/js/vendor")
check_list "vendor/algorave" "${algorave_vendor[@]}"

mapfile -t graphify_vendor < <(list_vendor_dirs "$ROOT/addons/graphify/assets/vendor")
check_list "vendor/graphify" "${graphify_vendor[@]}"

# --- Report ---
if [ "${#missing[@]}" -eq 0 ]; then
  echo "verify-credits: OK — every declared dependency appears in CREDITS.md."
  exit 0
fi

echo "verify-credits: ${#missing[@]} dependency(ies) not found in CREDITS.md:" >&2
for entry in "${missing[@]}"; do
  echo "  - $entry" >&2
done
echo >&2
echo "Add the missing entries to CREDITS.md (and the matching addon README" >&2
echo "or docs/THIRD_PARTY_ASSETS.md surface) before merging." >&2

if [ "$MODE" = "warn" ]; then
  exit 0
fi
exit 1
