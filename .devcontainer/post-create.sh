#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SRC="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORKSPACE_ROOT="$(cd "${PLUGIN_SRC}/.." && pwd)"
WP_ROOT="${WP_ROOT:-${WORKSPACE_ROOT}/wordpress}"
WP_URL="${WORDPRESS_SITE_URL:-http://localhost}"
WP_TITLE="${WORDPRESS_SITE_TITLE:-WordPress Development Site}"
WP_ADMIN_USER="${WORDPRESS_ADMIN_USER:-admin}"
WP_ADMIN_PASS="${WORDPRESS_ADMIN_PASSWORD:-password}"
WP_ADMIN_EMAIL="${WORDPRESS_ADMIN_EMAIL:-admin@example.com}"
DB_NAME="${WORDPRESS_DB_NAME:-wordpress}"
DB_USER="${WORDPRESS_DB_USER:-root}"
DB_PASS="${WORDPRESS_DB_PASSWORD:-root}"
DB_HOST="${WORDPRESS_DB_HOST:-127.0.0.1}"

WP_FLAGS=()
if [ "$(id -u)" -eq 0 ]; then
  WP_FLAGS+=(--allow-root)
fi

run_wp() {
  wp "${WP_FLAGS[@]}" "$@"
}

install_wp_cli() {
  if command -v wp >/dev/null 2>&1; then
    return
  fi

  echo "Installing WP-CLI..."
  curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /tmp/wp-cli.phar
  chmod +x /tmp/wp-cli.phar
  if command -v sudo >/dev/null 2>&1; then
    sudo mv /tmp/wp-cli.phar /usr/local/bin/wp
  else
    mv /tmp/wp-cli.phar /usr/local/bin/wp
  fi
}

ensure_wordpress_downloaded() {
  mkdir -p "${WP_ROOT}"
  if [ ! -f "${WP_ROOT}/wp-load.php" ]; then
    echo "Downloading WordPress core into ${WP_ROOT}..."
    run_wp core download --path="${WP_ROOT}" --force
  fi
}

create_wp_config() {
  if [ -f "${WP_ROOT}/wp-config.php" ]; then
    return
  fi

  echo "Creating wp-config.php..."
  run_wp config create \
    --path="${WP_ROOT}" \
    --dbname="${DB_NAME}" \
    --dbuser="${DB_USER}" \
    --dbpass="${DB_PASS}" \
    --dbhost="${DB_HOST}" \
    --skip-check
}

install_wordpress() {
  if run_wp core is-installed --path="${WP_ROOT}" >/dev/null 2>&1; then
    return
  fi

  echo "Attempting WordPress installation..."
  if ! run_wp core install \
    --path="${WP_ROOT}" \
    --url="${WP_URL}" \
    --title="${WP_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASS}" \
    --admin_email="${WP_ADMIN_EMAIL}"; then
    echo "WordPress installation skipped (likely because the database is unreachable)."
  fi
}

link_plugin() {
  local plugin_target="${WP_ROOT}/wp-content/plugins/$(basename "${PLUGIN_SRC}")"
  mkdir -p "$(dirname "${plugin_target}")"

  if [ -L "${plugin_target}" ]; then
    return
  fi

  if [ -e "${plugin_target}" ]; then
    echo "Existing plugin directory at ${plugin_target} prevents symlink creation." >&2
    return
  fi

  echo "Symlinking plugin into WordPress: ${plugin_target} -> ${PLUGIN_SRC}"
  ln -s "${PLUGIN_SRC}" "${plugin_target}"
}

install_wp_cli
ensure_wordpress_downloaded
create_wp_config
install_wordpress
link_plugin
