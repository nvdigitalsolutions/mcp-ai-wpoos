#!/bin/sh
# ─────────────────────────────────────────────────────────────
# NV oOS Laravel spike entrypoint.
#
# Roles:
#   setup  — bootstrap the app, install dependencies, migrate, exit
#   app    — start Octane (FrankenPHP)
#   worker — start the Redis queue worker
# ─────────────────────────────────────────────────────────────

set -eu

ROLE="${CONTAINER_ROLE:-app}"
APP_DIR=/app
SKELETON=/workspace/oos/docker/laravel/app-skeleton

echo "[spike] role=${ROLE}"

if [ ! -f "$APP_DIR/artisan" ]; then
    echo "[spike] copying app skeleton into $APP_DIR"
    cp -R "$SKELETON"/. "$APP_DIR"/
    # Never use host-installed dependencies inside the container. The
    # vendor directory is a mounted volume, so clear its contents rather
    # than removing the mount point.
    if [ -d "$APP_DIR/vendor" ]; then
        find "$APP_DIR/vendor" -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
    fi
    rm -rf "$APP_DIR/node_modules" 2>/dev/null || true
fi

mkdir -p \
    "$APP_DIR/storage/app/public" \
    "$APP_DIR/storage/framework/cache/data" \
    "$APP_DIR/storage/framework/sessions" \
    "$APP_DIR/storage/framework/views" \
    "$APP_DIR/storage/logs" \
    "$APP_DIR/bootstrap/cache"

chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true

cd "$APP_DIR"

# Point the path repositories at the mounted monorepo (the committed
# composer.json uses host-relative paths).
composer config repositories.nvoos-core '{"type":"path","url":"/workspace/oos/lib/core","options":{"versions":{"nvoos/core":"1.0.0"}}}' >/dev/null
composer config repositories.nvoos-laravel '{"type":"path","url":"/workspace/oos/lib/laravel-adapter","options":{"versions":{"nvoos/laravel-adapter":"1.0.0"}}}' >/dev/null

if [ ! -f vendor/autoload.php ]; then
    echo "[spike] installing Composer dependencies"
    if ! composer install --no-interaction --prefer-dist; then
        echo "[spike] retrying with path-package update"
        composer update nvoos/core nvoos/laravel-adapter --with-dependencies --no-interaction --prefer-dist
    fi
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

echo "[spike] running migrations"
php artisan migrate --force --no-interaction

# Serve uploaded media from the public disk (idempotent).
php artisan storage:link >/dev/null 2>&1 || true

if [ "$ROLE" = "setup" ]; then
    echo "[spike] setup complete"
    exit 0
fi

if [ "$ROLE" = "worker" ]; then
    echo "[spike] starting queue worker"
    exec php artisan queue:work redis \
        --queue=oos \
        --tries=3 \
        --backoff=5 \
        --max-time=3600 \
        --max-jobs=500
fi

echo "[spike] starting Octane/FrankenPHP"
exec php artisan octane:frankenphp \
    --host=0.0.0.0 \
    --port=8000 \
    --workers="${OCTANE_WORKERS:-2}" \
    --max-requests="${OCTANE_MAX_REQUESTS:-1}"
