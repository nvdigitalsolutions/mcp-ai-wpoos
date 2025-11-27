#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

WORK_DIR="$ROOT_DIR/.codex-wordpress"
mkdir -p "$WORK_DIR"

for cmd in php curl unzip; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "The '$cmd' command is required to provision WordPress without Docker." >&2
    exit 1
  fi
done

COMPOSER_CMD=(composer)
if command -v composer >/dev/null 2>&1; then
  :
else
  COMPOSER_PHAR="$WORK_DIR/composer.phar"
  if [[ ! -f "$COMPOSER_PHAR" ]]; then
    echo "Composer is not available; installing a local copy..."
    INSTALLER="$WORK_DIR/composer-setup.php"
    EXPECTED_SIGNATURE="$(curl -fsSL https://composer.github.io/installer.sig)"
    if [[ -z "$EXPECTED_SIGNATURE" ]]; then
      echo "Unable to retrieve Composer installer signature." >&2
      exit 1
    fi

    php -r "copy('https://getcomposer.org/installer', '$INSTALLER');"
    ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', '$INSTALLER');")"
    if [[ "$ACTUAL_SIGNATURE" != "$EXPECTED_SIGNATURE" ]]; then
      echo "Composer installer signature mismatch." >&2
      rm -f "$INSTALLER"
      exit 1
    fi

    php "$INSTALLER" --install-dir="$WORK_DIR" --filename="composer.phar"
    rm -f "$INSTALLER"
  fi
  COMPOSER_CMD=(php "$COMPOSER_PHAR")
fi

install_composer_deps() {
  echo "$1"
  "${COMPOSER_CMD[@]}" install --no-interaction --prefer-dist --working-dir="$ROOT_DIR"
}

if [[ ! -f "$ROOT_DIR/vendor/autoload.php" ]]; then
  install_composer_deps "Installing Composer dependencies..."
elif [[ ! -d "$ROOT_DIR/vendor/phpunit" ]]; then
  install_composer_deps "Dev dependencies missing (installed with --no-dev). Installing full dependencies..."
fi

if [[ -n "${WP_MCP_AI_STARTUP_EXIT_AFTER_COMPOSER:-}" ]]; then
  exit 0
fi

WP_PATH="$WORK_DIR/wordpress"
WP_CLI_PHAR="$WORK_DIR/wp-cli.phar"
SERVER_PID_FILE="$WORK_DIR/server.pid"
SERVER_LOG_FILE="$WORK_DIR/wp-server.log"
PHP_MEMORY_LIMIT=${WORDPRESS_PHP_MEMORY_LIMIT:-512M}

if [[ ! -f "$WP_CLI_PHAR" ]]; then
  echo "Downloading WP-CLI..."
  TMP_PHAR="$WP_CLI_PHAR.tmp"
  curl -fsSL "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" -o "$TMP_PHAR"
  mv "$TMP_PHAR" "$WP_CLI_PHAR"
  chmod +x "$WP_CLI_PHAR"
fi

wp() {
  php -d "memory_limit=${PHP_MEMORY_LIMIT}" "$WP_CLI_PHAR" --path="$WP_PATH" --allow-root "$@"
}

SITE_URL=${WORDPRESS_URL:-http://localhost:8000}
SITE_TITLE=${WORDPRESS_TITLE:-"WP MCP AI Codex"}
ADMIN_USER=${WORDPRESS_ADMIN_USER:-admin}
ADMIN_PASSWORD=${WORDPRESS_ADMIN_PASSWORD:-password}
ADMIN_EMAIL=${WORDPRESS_ADMIN_EMAIL:-admin@example.com}
SERVER_PORT=${WORDPRESS_PORT:-8000}

if [[ ! -d "$WP_PATH" ]]; then
  echo "Downloading WordPress core..."
  mkdir -p "$WP_PATH"
  wp core download --force
fi

if [[ ! -f "$WP_PATH/wp-config.php" ]]; then
  echo "Creating wp-config.php for SQLite..."
  wp config create \
    --dbname=wordpress \
    --dbuser=unused \
    --dbpass=unused \
    --dbhost=localhost \
    --dbprefix=wp_ \
    --skip-check \
    --extra-php <<'PHP'
define( 'DB_TYPE', 'sqlite' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' );
@ini_set( 'display_errors', '0' );
PHP

  wp config shuffle-salts
fi

SQLITE_PLUGIN_DIR="$WP_PATH/wp-content/plugins/sqlite-database-integration"
if [[ ! -d "$SQLITE_PLUGIN_DIR" ]]; then
  echo "Installing SQLite integration plugin..."
  SQLITE_ZIP="$WORK_DIR/sqlite-database-integration.zip"
  curl -fsSL "https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip" -o "$SQLITE_ZIP"
  unzip -qo "$SQLITE_ZIP" -d "$WP_PATH/wp-content/plugins"
  rm -f "$SQLITE_ZIP"
fi

if [[ ! -f "$WP_PATH/wp-content/db.php" ]]; then
  if [[ -f "$SQLITE_PLUGIN_DIR/db.php" ]]; then
    cp "$SQLITE_PLUGIN_DIR/db.php" "$WP_PATH/wp-content/db.php"
  elif [[ -f "$SQLITE_PLUGIN_DIR/db.copy" ]]; then
    cp "$SQLITE_PLUGIN_DIR/db.copy" "$WP_PATH/wp-content/db.php"
  else
    echo "Could not locate the SQLite drop-in within the plugin." >&2
    exit 1
  fi
fi

PLUGIN_TARGET="$WP_PATH/wp-content/plugins/wp-mcp-ai"
if [[ -e "$PLUGIN_TARGET" && ! -L "$PLUGIN_TARGET" ]]; then
  rm -rf "$PLUGIN_TARGET"
fi
ln -sfn "$ROOT_DIR" "$PLUGIN_TARGET"

VENDOR_TESTS_DIR="$ROOT_DIR/vendor/wp-phpunit/wp-phpunit"
LOCAL_TESTS_DIR="$WORK_DIR/wordpress-tests-lib"
TMP_TESTS_DIR="${TMPDIR:-/tmp}/wordpress-tests-lib"
SQLITE_TESTS_DB_DIR="$WORK_DIR/tests-database"

if [[ ! -d "$SQLITE_TESTS_DB_DIR" ]]; then
  mkdir -p "$SQLITE_TESTS_DB_DIR"
fi

echo "Ensuring WordPress test suite is installed..."
if TMPDIR="$WORK_DIR" WP_CORE_DIR="$WP_PATH" WP_TESTS_DIR="$LOCAL_TESTS_DIR" \
    bash "$ROOT_DIR/bin/install-wp-tests.sh" wordpress_test root '' localhost latest true; then
  if [[ -d "$VENDOR_TESTS_DIR" && ! -e "$TMP_TESTS_DIR" ]]; then
    ln -sfn "$VENDOR_TESTS_DIR" "$TMP_TESTS_DIR"
  fi
else
  echo "Failed to provision the WordPress test suite. Tests may not run in this environment." >&2
fi

if wp core is-installed >/dev/null 2>&1; then
  echo "WordPress is already installed."
else
  echo "Installing WordPress via WP-CLI..."
  wp core install \
    --url="$SITE_URL" \
    --title="$SITE_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASSWORD" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
fi

echo "Activating the WP MCP AI plugin and setting defaults..."
wp plugin activate wp-mcp-ai >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null
wp option update blogdescription 'WordPress Codex test environment for WP MCP AI' >/dev/null
wp cache flush >/dev/null

if [[ -f "$SERVER_PID_FILE" ]]; then
  if kill -0 "$(cat "$SERVER_PID_FILE")" >/dev/null 2>&1; then
    SERVER_RUNNING=1
  else
    rm -f "$SERVER_PID_FILE"
    SERVER_RUNNING=0
  fi
else
  SERVER_RUNNING=0
fi

if [[ ${SERVER_RUNNING:-0} -ne 1 ]]; then
  echo "Starting WordPress development server on port ${SERVER_PORT}..."
  nohup php -d "memory_limit=${PHP_MEMORY_LIMIT}" "$WP_CLI_PHAR" --path="$WP_PATH" --allow-root server --host=0.0.0.0 --port="$SERVER_PORT" \
    >"$SERVER_LOG_FILE" 2>&1 &
  echo $! > "$SERVER_PID_FILE"
else
  echo "WordPress development server already running (PID $(cat "$SERVER_PID_FILE"))."
fi

cat <<SUMMARY

WordPress is ready for testing.
URL:      ${SITE_URL}
Username: ${ADMIN_USER}
Password: ${ADMIN_PASSWORD}

Logs:     ${SERVER_LOG_FILE}

SUMMARY
