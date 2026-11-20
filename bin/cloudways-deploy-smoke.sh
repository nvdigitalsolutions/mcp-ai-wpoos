#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────
# Hermetic smoke test for bin/cloudways-deploy.sh.
#
# Proves the deploy script's *artifacts and guards* without a Cloudways server:
# the generated front controller parses, the generated .htaccess keeps the
# `public/` → docroot mapping and the /repo deny rule, the first-run .env gate
# exits 2, and the docroot guards refuse unsafe layouts.
#
# PHP, Composer and rsync are stubbed, so nothing installs, migrates or copies.
# Any existing app-skeleton/.env is moved aside and restored on exit.
#
# Usage: bin/cloudways-deploy-smoke.sh
# ─────────────────────────────────────────────────────────────

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY="$REPO_ROOT/bin/cloudways-deploy.sh"
APP_ROOT="$REPO_ROOT/docker/laravel/app-skeleton"
REAL_PHP="$(command -v php || true)"

TMP="$(mktemp -d)"
ENV_FILE="$APP_ROOT/.env"
ENV_BACKUP=""
HAD_ENV=0
[[ -f "$ENV_FILE" ]] && HAD_ENV=1
PASS=0
FAIL=0

ok() { printf '  PASS  %s\n' "$*"; PASS=$((PASS + 1)); }
bad() { printf '  FAIL  %s\n' "$*"; FAIL=$((FAIL + 1)); }
say() { printf '\n== %s\n' "$*"; }

cleanup() {
	if [[ -n "$ENV_BACKUP" && -f "$ENV_BACKUP" ]]; then
		mv -f "$ENV_BACKUP" "$ENV_FILE"
	elif [[ "$HAD_ENV" -eq 0 ]]; then
		# Never leave the test-created .env behind: it carries a well-known
		# APP_KEY, and a predictable key in a local checkout is a trap.
		rm -f "$ENV_FILE"
	fi
	rm -rf "$TMP"
}
trap cleanup EXIT

# ── Stubs ────────────────────────────────────────────────────────────────────
mkdir -p "$TMP/stub" "$TMP/public_html"

cat >"$TMP/stub/php" <<'STUB'
#!/bin/sh
case "${1:-}" in
	-v) echo "PHP 8.3.14 (cli) (stub)" ;;
	-r) printf '80314' ;;
	*) exit 0 ;;
esac
STUB
printf '#!/bin/sh\nexit 0\n' >"$TMP/stub/composer"
printf '#!/bin/sh\nexit 0\n' >"$TMP/stub/rsync"
chmod +x "$TMP/stub/php" "$TMP/stub/composer" "$TMP/stub/rsync"
export PATH="$TMP/stub:$PATH"

deploy() {
	env NVOOS_PHP_BIN="$TMP/stub/php" \
		NVOOS_COMPOSER_BIN="$TMP/stub/composer" \
		NVOOS_PUBLIC_ROOT="${1:-$TMP/public_html}" \
		bash "$DEPLOY" --no-install --no-migrate "${@:2}"
}

# ── 1. The script parses ─────────────────────────────────────────────────────
say "Script"
if bash -n "$DEPLOY" 2>/dev/null; then ok "bash -n"; else bad "bash -n"; fi
if bash "$DEPLOY" --help | grep -q 'Usage:'; then ok "--help prints usage"; else bad "--help"; fi

# ── 2. The committed lock is portable ────────────────────────────────────────
# A lock written inside the dev container bakes /workspace/oos dist paths that
# exist nowhere else; the deploy script refuses to run with one.
say "composer.lock"
if grep -q '/workspace/oos' "$APP_ROOT/composer.lock" 2>/dev/null; then
	bad "composer.lock contains container-absolute paths"
else
	ok "no container-absolute paths"
fi

# ── 3. First run without .env: artifacts written, exit 2 ─────────────────────
if [[ -f "$ENV_FILE" ]]; then
	ENV_BACKUP="$TMP/env.backup"
	mv "$ENV_FILE" "$ENV_BACKUP"
fi

say "First run (no .env)"
set +e
deploy >"$TMP/first.log" 2>&1
FIRST_EXIT=$?
set -e
if [[ "$FIRST_EXIT" -eq 2 ]]; then ok "exits 2 with a .env checklist"; else bad "expected exit 2, got $FIRST_EXIT"; fi
if [[ -f "$TMP/public_html/index.php" ]]; then ok "writes the front controller"; else bad "front controller missing"; fi
if [[ -f "$TMP/public_html/.htaccess" ]]; then ok "writes .htaccess"; else bad ".htaccess missing"; fi

# The first run created .env from .env.example and the stubbed PHP wrote no
# key, so the second pass must refuse rather than deploy without one.
if grep -q 'Fill in at minimum' "$TMP/first.log"; then
	ok "prints the .env checklist"
else
	bad "expected a .env checklist on the first run"
fi

say "Second run (empty APP_KEY)"
set +e
deploy >"$TMP/gate.log" 2>&1
GATE_EXIT=$?
set -e
if [[ "$GATE_EXIT" -eq 1 ]] && grep -q 'APP_KEY is empty' "$TMP/gate.log"; then
	ok "refuses an empty APP_KEY"
else
	bad "expected an APP_KEY refusal (exit 1), got $GATE_EXIT"
fi

say "Generated front controller"
if [[ -n "$REAL_PHP" ]]; then
	if "$REAL_PHP" -l "$TMP/public_html/index.php" >/dev/null 2>&1; then
		ok "index.php parses"
	else
		bad "index.php does not parse"
	fi
else
	bad "no php binary to lint index.php"
fi

if grep -q "require '${APP_ROOT}/public/index.php';" "$TMP/public_html/index.php"; then
	ok "requires the app's real front controller"
else
	bad "front controller require path is wrong"
fi

if grep -q "SCRIPT_NAME'\] = '/index.php'" "$TMP/public_html/index.php"; then
	ok "normalises SCRIPT_NAME"
else
	bad "SCRIPT_NAME override missing"
fi

say "Generated .htaccess"
if grep -Fq 'RewriteRule ^repo(/|$) - [F]' "$TMP/public_html/.htaccess"; then
	ok "denies /repo"
else
	bad "/repo deny rule missing"
fi

if grep -Fq 'RewriteRule ^ index.php [L]' "$TMP/public_html/.htaccess"; then
	ok "routes to the front controller"
else
	bad "front-controller rewrite missing"
fi

# ── 4. With a key: the deploy completes ──────────────────────────────────────
say "Third run (configured .env)"
printf 'APP_KEY=base64:c21va2UtdGVzdA==\n' >>"$ENV_FILE"
if deploy >"$TMP/deploy.log" 2>&1; then
	ok "exits 0"
else
	bad "expected exit 0"
	sed -n '1,20p' "$TMP/deploy.log"
fi

say "Idempotent"
if deploy >"$TMP/redeploy.log" 2>&1; then
	ok "re-runs cleanly"
else
	bad "re-run failed"
	sed -n '1,20p' "$TMP/redeploy.log"
fi

# ── 5. Guards ────────────────────────────────────────────────────────────────
say "Guards"
mkdir -p "$TMP/checkout-shaped"
printf '<?php\n' >"$TMP/checkout-shaped/artisan"
if out=$(deploy "$TMP/checkout-shaped" 2>&1); then
	bad "accepted a checkout at the docroot root"
elif printf '%s' "$out" | grep -q 'deployment path'; then
	ok "refuses a checkout at the docroot root"
else
	bad "refused for the wrong reason"
fi

if out=$(deploy "$APP_ROOT/public" 2>&1); then
	bad "accepted a docroot inside the application root"
elif printf '%s' "$out" | grep -q 'inside the application root'; then
	ok "refuses a docroot inside the app root"
else
	bad "refused for the wrong reason"
fi

# ── Summary ──────────────────────────────────────────────────────────────────
printf '\n%s passed, %s failed\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
