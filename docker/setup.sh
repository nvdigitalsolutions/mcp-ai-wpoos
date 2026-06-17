#!/usr/bin/env bash
# ───────────────────────────────────────────────────────────────
# oOS Engine → Framework Integration Setup
# ───────────────────────────────────────────────────────────────
#
# Bootstraps either a Laravel or Craft CMS test application
# inside Docker, linking the local oOS core + adapter packages
# via Composer path repositories for live-edit development.
#
# Usage:
#   docker/setup.sh laravel   # bootstrap Laravel integration env
#   docker/setup.sh craft     # bootstrap Craft CMS integration env
#   docker/setup.sh laravel --reset   # wipe and re-create
#
# Requirements:
#   - Docker 24+ with Docker Compose plugin
#   - Bash (Git Bash or WSL on Windows)

set -euo pipefail

FRAMEWORK="${1:-}"
RESET=false
[[ "${2:-}" == "--reset" ]] && RESET=true

if [[ "$FRAMEWORK" != "laravel" && "$FRAMEWORK" != "craft" ]]; then
    echo "Usage: docker/setup.sh <laravel|craft> [--reset]"
    exit 1
fi

cd "$(dirname "$0")/.."

# ── Colour helpers ───────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color
info()  { echo -e "${GREEN}[oOS]${NC} $1"; }
warn()  { echo -e "${YELLOW}[oOS]${NC} $1"; }

# ── Reset (optional) ─────────────────────────────────────────
if $RESET; then
    warn "Resetting $FRAMEWORK environment..."
    docker compose -f "docker/docker-compose.$FRAMEWORK.yml" down -v 2>/dev/null || true
    rm -rf "docker/$FRAMEWORK/app/"* 2>/dev/null || true
fi

# ── Build & start services ───────────────────────────────────
info "Building Docker image for $FRAMEWORK..."
docker compose -f "docker/docker-compose.$FRAMEWORK.yml" build

info "Starting containers..."
docker compose -f "docker/docker-compose.$FRAMEWORK.yml" up -d

# ── Bootstrap the framework app ──────────────────────────────
if [ "$FRAMEWORK" == "laravel" ]; then
    info "Bootstrapping Laravel application..."
    if [ ! -f "docker/laravel/app/artisan" ]; then
        docker compose -f docker/docker-compose.laravel.yml exec -T app sh -c '
            cd /tmp && composer create-project laravel/laravel laravel-temp --no-interaction --prefer-dist
            cp -r /tmp/laravel-temp/. /var/www/html/
            rm -rf /tmp/laravel-temp
        '
        # Add path repositories to the project composer.json
        docker compose -f docker/docker-compose.laravel.yml exec -T app sh -c '
            composer config repositories.oos-core path /workspace/oos/lib/core --working-dir=/var/www/html
            composer config repositories.oos-laravel path /workspace/oos/lib/laravel-adapter --working-dir=/var/www/html
            composer require nvoos/core:"*" nvoos/laravel-adapter:"*" --no-interaction
        '
        # Publish oOS config & run migrations
        docker compose -f docker/docker-compose.laravel.yml exec -T app sh -c '
            php artisan vendor:publish --tag=oos-config --force
            php artisan vendor:publish --tag=oos-migrations --force
            php artisan migrate --force
        '
    fi
fi

if [ "$FRAMEWORK" == "craft" ]; then
    info "Bootstrapping Craft CMS application..."
    if [ ! -f "docker/craft/app/craft" ]; then
        docker compose -f docker/docker-compose.craft.yml exec -T app sh -c '
            cd /tmp && composer create-project craftcms/craft craft-temp --no-interaction --prefer-dist
            cp -r /tmp/craft-temp/. /var/www/html/
            rm -rf /tmp/craft-temp
        '
        # Add path repositories and require oOS packages
        docker compose -f docker/docker-compose.craft.yml exec -T app sh -c '
            composer config repositories.oos-core path /workspace/oos/lib/core --working-dir=/var/www/html
            composer config repositories.oos-craft path /workspace/oos/lib/craft-adapter --working-dir=/var/www/html
            composer require nvoos/core:"*" nvoos/craft-adapter:"*" --no-interaction
        '
        # Run Craft setup
        docker compose -f docker/docker-compose.craft.yml exec -T app sh -c '
            php craft setup/keys
            php craft setup/db-creds
            php craft install --interactive=0 \
                --username=admin \
                --email=admin@oos.local \
                --password=password \
                --site-name="oOS Test" \
                --site-url=http://localhost:8002 \
                --language=en-US
        '
        # Create the oOS config file
        docker compose -f docker/docker-compose.craft.yml exec -T app sh -c '
            mkdir -p /var/www/html/config
            cp /workspace/oos/docker/craft/app-skeleton/config/oos.php /var/www/html/config/oos.php 2>/dev/null || true
        '
        # Register oOS module in Craft config
        docker compose -f docker/docker-compose.craft.yml exec -T app sh -c '
            cp /workspace/oos/docker/craft/app-skeleton/config/app.php /var/www/html/config/app.php 2>/dev/null || true
        '
    fi
fi

echo ""
info "✅ $FRAMEWORK integration environment is ready!"
echo ""

if [ "$FRAMEWORK" == "laravel" ]; then
    echo "   Laravel:      http://localhost:8001"
    echo "   Redis:         localhost:6379  (on oos-laravel-redis)"
    echo ""
    echo "   Shell in:      docker compose -f docker/docker-compose.laravel.yml exec app bash"
    echo "   Artisan:       docker compose -f docker/docker-compose.laravel.yml exec app php artisan"
    echo "   Logs:          docker compose -f docker/docker-compose.laravel.yml logs -f app"
fi

if [ "$FRAMEWORK" == "craft" ]; then
    echo "   Craft CMS:     http://localhost:8002"
    echo "   Control Panel: http://localhost:8002/admin  (admin / password)"
    echo "   Redis:         localhost:6379  (on oos-craft-redis)"
    echo ""
    echo "   Shell in:      docker compose -f docker/docker-compose.craft.yml exec app bash"
    echo "   Craft CLI:     docker compose -f docker/docker-compose.craft.yml exec app php craft"
    echo "   Logs:          docker compose -f docker/docker-compose.craft.yml logs -f app"
fi
