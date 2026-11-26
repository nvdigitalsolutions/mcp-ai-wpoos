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
# The stubs model the two facts the ordering bug depends on:
#   - `php artisan` cannot run before `composer install` (no vendor/autoload.php)
#   - `composer install` is what creates it
# Every invocation is logged so the test can assert the order, not just the exit.
# A sentinel models `vendor/autoload.php` existing: the checkout may already have
# a vendor/ directory (dev machines, CI after `composer install`), so probing the
# real path would make the bare-host case untestable.
mkdir -p "$TMP/stub" "$TMP/public_html"
: >"$TMP/stub.log"
export CW_STUB_LOG="$TMP/stub.log"
export CW_STUB_VENDOR="$TMP/vendor-ready"

cat >"$TMP/stub/php" <<'STUB'
#!/bin/sh
printf 'php %s\n' "$*" >> "$CW_STUB_LOG"
case "${1:-}" in
	-v) echo "PHP 8.3.14 (cli) (stub)" ;;
	-r) printf '80314' ;;
	artisan)
		if [ ! -f "$CW_STUB_VENDOR" ]; then
			echo "PHP Fatal error: Failed opening required 'vendor/autoload.php'" >&2
			exit 1
		fi
		;;
esac
exit 0
STUB

cat >"$TMP/stub/composer" <<'STUB'
#!/bin/sh
printf 'composer %s\n' "$*" >> "$CW_STUB_LOG"
case " $* " in
	*" install "*) : >"$CW_STUB_VENDOR" ;;
esac
exit 0
STUB

printf '#!/bin/sh\nexit 0\n' >"$TMP/stub/rsync"
chmod +x "$TMP/stub/php" "$TMP/stub/composer" "$TMP/stub/rsync"
export PATH="$TMP/stub:$PATH"

deploy() {
	env NVOOS_PHP_BIN="$TMP/stub/php" \
		NVOOS_COMPOSER_BIN="$TMP/stub/composer" \
		NVOOS_PUBLIC_ROOT="${1:-$TMP/public_html}" \
		bash "$DEPLOY"
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

# ── 3. First run without .env: config gate only ──────────────────────────────
# Configuration is a precondition for building, and nothing may touch the
# docroot before the operator has filled .env in.
if [[ -f "$ENV_FILE" ]]; then
	ENV_BACKUP="$TMP/env.backup"
	mv "$ENV_FILE" "$ENV_BACKUP"
fi

say "First run (no .env)"
: >"$TMP/stub.log"
set +e
deploy >"$TMP/first.log" 2>&1
FIRST_EXIT=$?
set -e
if [[ "$FIRST_EXIT" -eq 2 ]]; then ok "exits 2 with a .env checklist"; else bad "expected exit 2, got $FIRST_EXIT"; fi
if grep -q 'Fill in at minimum' "$TMP/first.log"; then
	ok "prints the .env checklist"
else
	bad "expected a .env checklist on the first run"
fi
if [[ -f "$TMP/public_html/index.php" || -f "$TMP/public_html/.htaccess" ]]; then
	bad "published to the docroot before .env was configured"
else
	ok "leaves the docroot untouched before configuration"
fi
if grep -q '^composer install' "$TMP/stub.log"; then
	bad "ran composer install before .env was configured"
else
	ok "does not build before .env is configured"
fi

# ── 4. Ordering: dependencies before any artisan call ────────────────────────
# The bug this test exists for: the first-run branch called
# `artisan key:generate` before `composer install`, so on a bare host artisan
# died on the missing vendor/autoload.php (exit 1, no checklist printed).
say "Second run (empty APP_KEY)"
: >"$TMP/stub.log"
set +e
deploy >"$TMP/gate.log" 2>&1
GATE_EXIT=$?
set -e
if [[ "$GATE_EXIT" -eq 1 ]] && grep -q 'APP_KEY is empty' "$TMP/gate.log"; then
	ok "refuses an empty APP_KEY"
else
	bad "expected an APP_KEY refusal (exit 1), got $GATE_EXIT"
fi

install_line=$(grep -n '^composer install' "$TMP/stub.log" | head -1 | cut -d: -f1 || true)
artisan_line=$(grep -n '^php artisan' "$TMP/stub.log" | head -1 | cut -d: -f1 || true)
key_line=$(grep -n '^php artisan key:generate' "$TMP/stub.log" | head -1 | cut -d: -f1 || true)

if [[ -n "$install_line" && -n "$artisan_line" && "$install_line" -lt "$artisan_line" ]]; then
	ok "composer install runs before any php artisan"
else
	bad "artisan ran before composer install (install=${install_line:-none}, artisan=${artisan_line:-none})"
fi
if [[ -n "$install_line" && -n "$key_line" && "$install_line" -lt "$key_line" ]]; then
	ok "key:generate runs after install"
else
	bad "key:generate ran before install (install=${install_line:-none}, key=${key_line:-none})"
fi

# ── 5. With a key: the deploy completes and publishes ────────────────────────
say "Third run (configured .env)"
printf 'APP_KEY=base64:c21va2UtdGVzdA==\n' >>"$ENV_FILE"
: >"$TMP/stub.log"
if deploy >"$TMP/deploy.log" 2>&1; then
	ok "exits 0"
else
	bad "expected exit 0"
	sed -n '1,20p' "$TMP/deploy.log"
fi
if [[ -f "$TMP/public_html/index.php" ]]; then ok "writes the front controller"; else bad "front controller missing"; fi
if [[ -f "$TMP/public_html/.htaccess" ]]; then ok "writes .htaccess"; else bad ".htaccess missing"; fi

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
