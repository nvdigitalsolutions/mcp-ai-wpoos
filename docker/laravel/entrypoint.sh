#!/bin/sh
# ───────────────────────────────────────────────────────────
# Laravel container entrypoint
# ───────────────────────────────────────────────────────────
set -e

echo "[oOS] Laravel entrypoint starting..."

# ── Wait for MySQL ──────────────────────────
echo "[oOS] Waiting for MySQL at ${DB_HOST:-db}:${DB_PORT:-3306}..."
until mysqladmin ping -h "${DB_HOST:-db}" -u "${DB_USERNAME:-laravel}" -p"${DB_PASSWORD:-laravel}" --ssl=0 --silent 2>/dev/null; do
    echo "  ...waiting for database..."
    sleep 2
done
echo "[oOS] Database is ready."

# ── Bootstrap Laravel if not yet installed ──
if [ ! -f "artisan" ]; then
    echo "[oOS] No Laravel project found — bootstrapping..."
    cd /tmp
    composer create-project laravel/laravel laravel-temp --no-interaction --prefer-dist
    cp -r /tmp/laravel-temp/. /var/www/html/
    rm -rf /tmp/laravel-temp
    cd /var/www/html

    # Add oOS path repos and require packages
    composer config repositories.oos-core path /workspace/oos/lib/core
    composer config repositories.oos-laravel path /workspace/oos/lib/laravel-adapter
    composer require nvoos/core:"*" nvoos/laravel-adapter:"*" --no-interaction

    # Set up .env with DB credentials from Docker environment
    cp .env.example .env 2>/dev/null || true
    for key in DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
      val=""
      case $key in
        DB_CONNECTION) val=mysql ;;
        DB_HOST) val="${DB_HOST:-db}" ;;
        DB_PORT) val="${DB_PORT:-3306}" ;;
        DB_DATABASE) val="${DB_DATABASE:-laravel}" ;;
        DB_USERNAME) val="${DB_USERNAME:-laravel}" ;;
        DB_PASSWORD) val="${DB_PASSWORD:-laravel}" ;;
      esac
      if grep -q "^${key}=" .env 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${val}|" .env
      else
        echo "${key}=${val}" >> .env
      fi
    done
    php artisan key:generate --force
    echo "[oOS] Laravel project bootstrapped."
fi

# ── Install dependencies if needed ──────────
if [ ! -d "vendor" ] || [ composer.json -nt vendor/autoload.php ]; then
    echo "[oOS] Running composer install..."
    composer install --no-interaction --prefer-dist --no-progress
fi

# ── Migrations ──────────────────────────────
echo "[oOS] Running database migrations..."
php artisan migrate --force --no-interaction || true

echo "[oOS] Starting Laravel dev server on port 8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
