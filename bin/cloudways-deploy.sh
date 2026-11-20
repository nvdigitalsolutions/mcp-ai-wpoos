#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────
# NV oOS Laravel runtime — deploy to a Cloudways "Flexible PHP" app.
#
# Cloudways' "Deployment via Git" clones into a path *relative to the
# docroot*, so this checkout normally lives at `public_html/repo/`. Laravel
# needs the docroot to be its `public/` directory, so this script publishes the
# front controller and static assets into the docroot, installs the monorepo's
# path-repository dependencies, migrates, and warms the production caches.
#
# Usage:
#   bin/cloudways-deploy.sh [options]
#
#   --dry-run     Print every action without changing anything.
#   --no-install  Skip `composer install` (rerun after editing .env only).
#   --no-migrate  Skip `php artisan migrate --force`.
#   --help        Show this help.
#
# Environment:
#   NVOOS_PUBLIC_ROOT    Docroot. Default: the Cloudways Git layout
#                        (`public_html/repo/…`) resolves to `public_html`;
#                        otherwise `<parent of the checkout>/public_html`.
#   NVOOS_PHP_BIN        PHP CLI binary. Cloudways ships several PHP versions
#                        and `php` is not always the app's version.
#   NVOOS_COMPOSER_BIN   Composer binary (default `composer`).
#
# Runbook: docker/laravel/README.md → "Deploying to Cloudways".
#
# Exit codes: 0 deployed · 1 preflight/deploy failure · 2 .env needs values.
# ─────────────────────────────────────────────────────────────

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_ROOT="$REPO_ROOT/docker/laravel/app-skeleton"
PHP_BIN="${NVOOS_PHP_BIN:-php}"
COMPOSER_BIN="${NVOOS_COMPOSER_BIN:-composer}"

DRY_RUN=0
DO_INSTALL=1
DO_MIGRATE=1

usage() {
	sed -n '2,29p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

for arg in "$@"; do
	case "$arg" in
		--dry-run) DRY_RUN=1 ;;
		--no-install) DO_INSTALL=0 ;;
		--no-migrate) DO_MIGRATE=0 ;;
		--help | -h) usage; exit 0 ;;
		*) printf 'Unknown option: %s (try --help)\n' "$arg" >&2; exit 1 ;;
	esac
done

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[33m    WARN  %s\033[0m\n' "$*"; }
die() {
	printf '\033[31m    FAIL  %s\033[0m\n' "$*" >&2
	exit 1
}
run() {
	if ((DRY_RUN)); then
		printf '    [dry-run] %s\n' "$*"
	else
		"$@"
	fi
}

# ── Resolve the docroot ──────────────────────────────────────────────────────
# Cloudways' deployment path is relative to the docroot: an empty path means
# `public_html/`, and `repo/` means `public_html/repo/`. Both layouts are
# supported, plus an explicit override for any other host.
if [[ -n "${NVOOS_PUBLIC_ROOT:-}" ]]; then
	PUBLIC_ROOT="$NVOOS_PUBLIC_ROOT"
elif [[ "$(basename "$REPO_ROOT")" == "repo" && "$(basename "$(dirname "$REPO_ROOT")")" == "public_html" ]]; then
	PUBLIC_ROOT="$(dirname "$REPO_ROOT")"
else
	PUBLIC_ROOT="$(dirname "$REPO_ROOT")/public_html"
fi

say "NV oOS Laravel deploy"
info "checkout   $REPO_ROOT"
info "app root   $APP_ROOT"
info "docroot    $PUBLIC_ROOT"
info "php        $("$PHP_BIN" -v 2>/dev/null | head -n1 || echo 'not found')"
if ((DRY_RUN)); then
	info "mode       dry run"
fi

# ── Preflight ────────────────────────────────────────────────────────────────
[[ -f "$APP_ROOT/artisan" ]] || die "No artisan at $APP_ROOT — run this script from the repository's bin/ directory."
[[ -d "$PUBLIC_ROOT" ]] || die "Docroot not found: $PUBLIC_ROOT (set NVOOS_PUBLIC_ROOT)."
command -v rsync >/dev/null 2>&1 || die "rsync is required (Cloudways servers ship it)."

if [[ -f "$PUBLIC_ROOT/artisan" || -d "$PUBLIC_ROOT/.git" ]]; then
	die "The checkout is deployed at the docroot root. Set the Cloudways Git deployment path to 'repo/' (it is relative to public_html) and re-run."
fi

REAL_APP="$(cd "$APP_ROOT" && pwd -P)"
REAL_PUBLIC="$(cd "$PUBLIC_ROOT" && pwd -P)"
case "$REAL_PUBLIC/" in
	"$REAL_APP/"*) die "Docroot is inside the application root; publishing would copy public/ into itself." ;;
esac

case "$APP_ROOT" in
	*\'*) die "The application path contains a single quote; the generated front controller cannot quote it safely." ;;
esac

PHP_ID="$("$PHP_BIN" -r 'echo PHP_VERSION_ID;' 2>/dev/null || true)"
case "${PHP_ID:-0}" in
	'' | *[!0-9]*) die "Cannot run $PHP_BIN. Set NVOOS_PHP_BIN to the app's PHP 8.3 binary." ;;
	*) ((PHP_ID >= 80200)) || die "PHP >= 8.2 required (composer.json). $PHP_BIN is too old — set NVOOS_PHP_BIN." ;;
esac

# A composer.lock written inside the dev container bakes absolute dist paths
# that exist nowhere else.
if grep -q '/workspace/oos' "$APP_ROOT/composer.lock" 2>/dev/null; then
	die "composer.lock contains container-absolute paths (/workspace/oos). Regenerate it outside Docker before deploying."
fi

if command -v "$COMPOSER_BIN" >/dev/null 2>&1; then
	say "Checking platform requirements against composer.lock"
	"$COMPOSER_BIN" check-platform-reqs --lock --no-dev --working-dir="$APP_ROOT" ||
		die "Missing PHP extensions (listed above). Enable them in Cloudways → Application Settings → PHP extensions."
else
	warn "Composer not found on PATH; skipping platform check."
fi

cd "$APP_ROOT"

# ── Publish the front controller and static assets ───────────────────────────
say "Publishing public/ into $PUBLIC_ROOT"
run rsync -a --delete \
	--exclude 'index.php' \
	--exclude '.htaccess' \
	--exclude 'storage' \
	--exclude '.well-known' \
	--exclude 'repo' \
	"$APP_ROOT/public/" "$PUBLIC_ROOT/"

run mkdir -p "$APP_ROOT/storage/app/public"

if [[ -d "$PUBLIC_ROOT/storage" && ! -L "$PUBLIC_ROOT/storage" ]]; then
	warn "$PUBLIC_ROOT/storage is a real directory; not replacing it with a symlink."
else
	run ln -sfn "$APP_ROOT/storage/app/public" "$PUBLIC_ROOT/storage"
fi

if ((DRY_RUN)); then
	info "[dry-run] write $PUBLIC_ROOT/index.php (front controller shim)"
	info "[dry-run] write $PUBLIC_ROOT/.htaccess"
else
	# The real front controller lives inside the checkout, so SCRIPT_NAME would
	# otherwise carry that path and every generated URL (panel links, /health,
	# redirects) would point into the source tree.
	cat >"$PUBLIC_ROOT/index.php" <<PHP
<?php

/**
 * Generated by bin/cloudways-deploy.sh — do not edit on the server.
 *
 * Requires the real front controller and normalises SCRIPT_NAME so that
 * Request::root() is the site root rather than the checkout path.
 */
\$_SERVER['SCRIPT_NAME'] = '/index.php';
\$_SERVER['SCRIPT_FILENAME'] = __FILE__;
\$_SERVER['PHP_SELF'] = '/index.php';

require '${APP_ROOT}/public/index.php';
PHP

	cat >"$PUBLIC_ROOT/.htaccess" <<'HTACCESS'
# Generated by bin/cloudways-deploy.sh — do not edit on the server.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Never serve the application source tree. Cloudways deploys Git into a
    # subfolder of the docroot, and that folder holds .env, vendor/ and lib/.
    RewriteRule ^repo(/|$) - [F]

    # Dotfiles (.env lives at repo/docker/laravel/app-skeleton/.env).
    RewriteRule (^|/)\.(?!well-known) - [F]

    # Forward auth headers (from the framework's own public/.htaccess).
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Existing files (css/js/fonts/storage) are served directly.
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # Everything else reaches Laravel.
    RewriteRule ^ index.php [L]
</IfModule>

<IfModule mod_negotiation.c>
    Options -MultiViews -Indexes
</IfModule>
HTACCESS
	info "wrote index.php and .htaccess"
fi

# ── .env ─────────────────────────────────────────────────────────────────────
ENV_FILE="$APP_ROOT/.env"
env_get() { grep -E "^$1=" "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '"' || true; }

if [[ ! -f "$ENV_FILE" ]]; then
	say "Creating .env from .env.example"
	run cp "$APP_ROOT/.env.example" "$ENV_FILE"
	run "$PHP_BIN" artisan key:generate --force
	cat <<'NEXT'

    Fill in at minimum (see docker/laravel/README.md for the full block):

      APP_ENV=production
      APP_DEBUG=false
      APP_URL=https://<host>
      DB_CONNECTION=mysql  DB_HOST  DB_PORT  DB_DATABASE  DB_USERNAME  DB_PASSWORD
      SANCTUM_STATEFUL_DOMAINS=<host>     (only needed for extra origins)
      NVOOS_PRO_ENTITLEMENT_DRIVER=config | license

    Then re-run this script.

NEXT
	exit 2
fi

[[ -n "$(env_get APP_KEY)" ]] || die "APP_KEY is empty in $ENV_FILE — run: $PHP_BIN artisan key:generate --force"

APP_ENV_VALUE="$(env_get APP_ENV)"
if [[ "$APP_ENV_VALUE" == "production" ]]; then
	if [[ "$(env_get APP_DEBUG)" == "true" ]]; then
		warn "APP_DEBUG=true with APP_ENV=production leaks configuration on errors."
	fi
	if [[ "$(env_get APP_URL)" == http://* ]]; then
		warn "APP_URL is http:// on a production deploy; generated URLs and the secure session cookie will be wrong."
	fi

	case "$(env_get NVOOS_PRO_ENTITLEMENT_DRIVER)" in
		'' | allow) warn "NVOOS_PRO_ENTITLEMENT_DRIVER defaults to 'allow', which entitles every feature. Use 'config' or 'license' in production." ;;
	esac
fi

# ── Dependencies ─────────────────────────────────────────────────────────────
if ((DO_INSTALL)); then
	say "Installing Composer dependencies (no-dev, optimised autoloader)"
	if ((DRY_RUN)); then
		info "[dry-run] COMPOSER_MEMORY_LIMIT=-1 $COMPOSER_BIN install --no-dev --no-interaction --prefer-dist --optimize-autoloader"
	else
		# The monorepo's path repositories resolve relatively, so this must run
		# in place rather than from a copied app root.
		COMPOSER_MEMORY_LIMIT=-1 "$COMPOSER_BIN" install \
			--no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
	fi
fi

# ── Schema ───────────────────────────────────────────────────────────────────
if ((DO_MIGRATE)); then
	say "Running migrations"
	run "$PHP_BIN" artisan migrate --force --no-interaction
fi

# ── Production caches ────────────────────────────────────────────────────────
# optimize = config:cache + event:cache + route:cache + view:cache. Route
# caching is safe here: Laravel >= 11 serialises closure routes, and this app
# has no named routes to collide.
say "Publishing Filament assets and warming caches"
run "$PHP_BIN" artisan filament:assets
run "$PHP_BIN" artisan filament:optimize
run "$PHP_BIN" artisan optimize

# ── Long-running processes ───────────────────────────────────────────────────
say "Signalling workers and the scheduler"
run "$PHP_BIN" artisan queue:restart
run "$PHP_BIN" artisan schedule:interrupt || true

# ── Done ─────────────────────────────────────────────────────────────────────
say "Deployed"
cat <<NEXT
    Verify:
      curl -fsS https://<host>/health        # ok:true, abspath:false
      curl -fsS https://<host>/api/v1/features
      $PHP_BIN artisan schedule:list

    One minute-cron (Cloudways → Application → Cron Job Manager):
      cd $APP_ROOT && $PHP_BIN artisan schedule:run >> /dev/null 2>&1

    Queue worker (Cloudways → Application → Supervisor, if available):
      $PHP_BIN $APP_ROOT/artisan queue:work --queue=oos --tries=3 --backoff=5 --max-time=3600

    /ops needs an operator estate (production never seeds one) and /dev/* is
    disabled outside local/testing. See docker/laravel/README.md.

NEXT
