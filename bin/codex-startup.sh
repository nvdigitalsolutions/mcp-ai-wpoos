#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  COMPOSE_COMMAND=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_COMMAND=(docker-compose)
else
  echo "Docker Compose is required (docker compose or docker-compose)." >&2
  exit 1
fi

compose() {
  "${COMPOSE_COMMAND[@]}" "$@"
}

SITE_URL=${WORDPRESS_URL:-http://localhost:8000}
SITE_TITLE=${WORDPRESS_TITLE:-"WP MCP AI Codex"}
ADMIN_USER=${WORDPRESS_ADMIN_USER:-admin}
ADMIN_PASSWORD=${WORDPRESS_ADMIN_PASSWORD:-password}
ADMIN_EMAIL=${WORDPRESS_ADMIN_EMAIL:-admin@example.com}
DB_ROOT_PASSWORD=${WORDPRESS_DB_ROOT_PASSWORD:-wordpress}
WAIT_TIMEOUT=${WORDPRESS_STARTUP_TIMEOUT:-120}

echo "Starting WordPress services with Docker Compose..."
compose up -d

echo "Waiting for the database to accept connections..."
for (( second=1; second<=WAIT_TIMEOUT; second++ )); do
  if compose exec -T db mysqladmin ping -u root -p"${DB_ROOT_PASSWORD}" --silent >/dev/null 2>&1; then
    DB_READY=1
    break
  fi
  sleep 1
  DB_READY=0
  if (( second % 10 == 0 )); then
    echo "  Still waiting (${second}s elapsed)..."
  fi

done

if [[ ${DB_READY:-0} -ne 1 ]]; then
  echo "Database did not become ready within ${WAIT_TIMEOUT} seconds." >&2
  exit 1
fi

echo "Ensuring WordPress files are provisioned..."
for (( second=1; second<=WAIT_TIMEOUT; second++ )); do
  if compose exec -T wordpress test -f /var/www/html/wp-load.php >/dev/null 2>&1; then
    WP_READY=1
    break
  fi
  sleep 1
  WP_READY=0
  if (( second % 10 == 0 )); then
    echo "  WordPress files not ready yet (${second}s elapsed)..."
  fi

done

if [[ ${WP_READY:-0} -ne 1 ]]; then
  echo "WordPress container did not finish provisioning within ${WAIT_TIMEOUT} seconds." >&2
  exit 1
fi

if compose run --rm wp-cli core is-installed >/dev/null 2>&1; then
  echo "WordPress is already installed."
else
  echo "Installing WordPress via WP-CLI..."
  compose run --rm wp-cli core install \
    --url="${SITE_URL}" \
    --title="${SITE_TITLE}" \
    --admin_user="${ADMIN_USER}" \
    --admin_password="${ADMIN_PASSWORD}" \
    --admin_email="${ADMIN_EMAIL}" \
    --skip-email
fi

echo "Activating the WP MCP AI plugin and setting defaults..."
compose run --rm wp-cli plugin activate wp-mcp-ai >/dev/null
compose run --rm wp-cli rewrite structure '/%postname%/' --hard >/dev/null
compose run --rm wp-cli option update blogdescription 'WordPress Codex test environment for WP MCP AI' >/dev/null

compose run --rm wp-cli cache flush >/dev/null

cat <<SUMMARY

WordPress is ready for testing.
URL:      ${SITE_URL}
Username: ${ADMIN_USER}
Password: ${ADMIN_PASSWORD}

SUMMARY
