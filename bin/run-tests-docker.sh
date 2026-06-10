#!/usr/bin/env bash
# ───────────────────────────────────────────────────────────
# Docker-based PHPUnit test runner for NV oOS
# ───────────────────────────────────────────────────────────
#
# Runs PHPUnit inside the oos-wp Docker container using MySQL
# on the companion oos-wp-db container.  No local PHP, MySQL,
# or WordPress installation is needed — only Docker Desktop.
#
# Prerequisites:
#   docker compose up -d      (start the environment)
#   composer install           (install dev dependencies)
#
# Usage:
#   bin/run-tests-docker.sh                              # all tests
#   bin/run-tests-docker.sh tests/test-admin-settings.php # one file
#   bin/run-tests-docker.sh --filter='test_default_provider' tests/test-admin-settings.php
#
# Windows notes:
#   • Git Bash  →  works out of the box (MSYS_NO_PATHCONV is set automatically)
#   • PowerShell → docker compose up -d; then bash bin/run-tests-docker.sh
#   • WSL        → works natively
#
# The script tolerates being called from any shell; it detects
# Git Bash and sets MSYS_NO_PATHCONV to prevent path mangling.
# ───────────────────────────────────────────────────────────

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

# ── Detect Git Bash on Windows ────────────────────────────
case "$(uname -s 2>/dev/null || echo 'unknown')" in
  MINGW*|MSYS*|CYGWIN*)
    export MSYS_NO_PATHCONV=1
    ;;
esac

# ── Docker preflight ──────────────────────────────────────
CONTAINER="oos-wp"
DB_CONTAINER="oos-wp-db"

if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$CONTAINER"; then
  echo "The '$CONTAINER' container is not running." >&2
  echo "Start it with:  docker compose up -d" >&2
  exit 1
fi

if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$DB_CONTAINER"; then
  echo "The '$DB_CONTAINER' container is not running." >&2
  echo "Start it with:  docker compose up -d" >&2
  exit 1
fi

# ── Ensure test database exists ───────────────────────────
echo "Ensuring test database 'wordpress_test' exists..."
docker exec "$DB_CONTAINER" mysql -u root -pwordpress \
  -e "CREATE DATABASE IF NOT EXISTS wordpress_test" 2>/dev/null || true

# ── Ensure composer autoloader is fresh ───────────────────
# The bind mount can cache stale files; dump-autoload forces a
# fresh classmap that works correctly inside the Linux container.
if [ -f vendor/autoload.php ]; then
  echo "Refreshing Composer autoloader..."
  composer dump-autoload --no-interaction --quiet 2>/dev/null || true
fi

# ── Run PHPUnit ───────────────────────────────────────────
echo "═══════════════════════════════════════════════════════════"
echo "Running: vendor/bin/phpunit $*"
echo "═══════════════════════════════════════════════════════════"

docker exec \
  -e WP_CORE_DIR=/var/www/html \
  -e WP_DB_HOST=db \
  -e WP_DB_NAME=wordpress_test \
  -e WP_DB_USER=wordpress \
  -e WP_DB_PASSWORD=wordpress \
  "$CONTAINER" \
  sh -c "cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php vendor/bin/phpunit $*"
