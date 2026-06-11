#!/bin/sh
# ───────────────────────────────────────────────────────────
# Craft CMS container entrypoint
# ───────────────────────────────────────────────────────────
# 1. Waits for the database to be ready
# 2. Installs Composer dependencies (path repos now mounted)
# 3. Runs Craft CMS setup if not already installed
# 4. Starts the PHP dev server

set -e

echo "[oOS] Craft CMS entrypoint starting..."

# Wait for MySQL (disable SSL — dev only, uses self-signed certs)
echo "[oOS] Waiting for MySQL at ${CRAFT_DB_SERVER:-db}:${CRAFT_DB_PORT:-3306}..."
until mysqladmin ping -h "${CRAFT_DB_SERVER:-db}" -u "${CRAFT_DB_USER:-craft}" -p"${CRAFT_DB_PASSWORD:-craft}" --ssl=0 --silent 2>/dev/null; do
    echo "  ...waiting for database..."
    sleep 2
done
echo "[oOS] Database is ready."

# Install dependencies if vendor doesn't exist
if [ ! -d "vendor" ] || [ composer.json -nt vendor/autoload.php ]; then
    echo "[oOS] Running composer install..."
    composer install --no-interaction --prefer-dist --no-progress
fi

# Run Craft setup if not installed
if [ -f "craft" ] && ! php craft install/check 2>/dev/null; then
    echo "[oOS] Craft CMS is already installed, skipping setup."
else
    echo "[oOS] Running Craft CMS setup..."
    php craft setup/keys 2>/dev/null || true
    php craft setup/db 2>/dev/null || true
    # Attempt install if env vars are set
    if [ -n "${CRAFT_SITE_URL}" ]; then
        php craft install/craft \
            --username="${CRAFT_USERNAME:-admin}" \
            --email="${CRAFT_EMAIL:-admin@oos.local}" \
            --password="${CRAFT_PASSWORD:-password}" \
            --site-name="${CRAFT_SITE_NAME:-oOS Test}" \
            --site-url="${CRAFT_SITE_URL}" \
            --language="${CRAFT_LANGUAGE:-en-US}" 2>/dev/null || true
    fi
fi

echo "[oOS] Starting PHP dev server on port 8080..."
exec php -S 0.0.0.0:8080 -t /var/www/html/web
