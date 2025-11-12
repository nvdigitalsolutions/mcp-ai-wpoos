#!/usr/bin/env bash
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
#
# Downloads and configures the WordPress testing framework.

set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
VENDOR_TESTS_DIR="$ROOT_DIR/vendor/wp-phpunit/wp-phpunit"
USING_VENDOR_TESTS=0

if [ "$#" -lt 3 ]; then
    echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]" >&2
    exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
DEFAULT_WP_TESTS_DIR="${TMPDIR}/wordpress-tests-lib"

if [ -z "${WP_TESTS_DIR:-}" ]; then
    WP_TESTS_DIR=$DEFAULT_WP_TESTS_DIR
fi

WP_CORE_DIR=${WP_CORE_DIR-${TMPDIR}/wordpress/}

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]" >&2
    exit 1
fi

download() {
    local url="$1"
    local output="$2"
    local max_attempts=2
    local attempt=1
    local wait_time=3
    
    while [ $attempt -le $max_attempts ]; do
        if [ $attempt -gt 1 ]; then
            echo "Retry attempt $attempt of $max_attempts (waiting ${wait_time}s)..." >&2
            sleep $wait_time
        fi
        
        if command -v curl >/dev/null; then
            if curl -sSL --connect-timeout 10 --max-time 120 "$url" -o "$output" 2>/dev/null; then
                return 0
            fi
        elif command -v wget >/dev/null; then
            if wget -q --timeout=10 --dns-timeout=10 --connect-timeout=10 --read-timeout=120 --tries=1 "$url" -O "$output" 2>/dev/null; then
                return 0
            fi
        else
            echo "Could not find curl or wget" >&2
            exit 1
        fi
        
        attempt=$((attempt + 1))
    done
    
    echo "Failed to download $url after $max_attempts attempts" >&2
    echo "This may indicate network connectivity issues or that the URL is blocked" >&2
    return 1
}

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then
        return
    fi

    mkdir -p "$WP_CORE_DIR"

    local ARCHIVE_NAME='latest'
    local GITHUB_TAG='6.7.1'

    if [ "$WP_VERSION" != 'latest' ]; then
        ARCHIVE_NAME="wordpress-${WP_VERSION}"
        GITHUB_TAG="$WP_VERSION"
    fi

    # Try wordpress.org first
    if download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "$TMPDIR/wordpress.tar.gz"; then
        echo "Downloaded WordPress from wordpress.org" >&2
        tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
        return 0
    fi
    
    # Fallback to GitHub mirror
    echo "wordpress.org not accessible, trying GitHub mirror..." >&2
    if download "https://github.com/WordPress/WordPress/archive/refs/tags/${GITHUB_TAG}.tar.gz" "$TMPDIR/wordpress.tar.gz"; then
        echo "Downloaded WordPress from GitHub" >&2
        tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
        return 0
    fi
    
    echo "Failed to download WordPress from both wordpress.org and GitHub" >&2
    return 1
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR/includes" ]; then
        return
    fi

    if [ -d "$VENDOR_TESTS_DIR/includes" ]; then
        USING_VENDOR_TESTS=1

        if [ "$WP_TESTS_DIR" != "$VENDOR_TESTS_DIR" ]; then
            echo "Linking WordPress test suite from composer package..."
            mkdir -p "$(dirname "$WP_TESTS_DIR")"
            rm -rf "$WP_TESTS_DIR"
            ln -s "$VENDOR_TESTS_DIR" "$WP_TESTS_DIR"
        fi

        return
    fi

    mkdir -p "$WP_TESTS_DIR"

    local ARCHIVE_NAME='trunk'

    if [ "$WP_VERSION" != 'latest' ]; then
        ARCHIVE_NAME="tags/${WP_VERSION}"
    fi

    local SVN_BASE="https://develop.svn.wordpress.org/${ARCHIVE_NAME}/tests/phpunit"

    if ! command -v svn >/dev/null; then
        echo "Subversion (svn) is required to download the WordPress test suite." >&2
        echo "Alternatively, run 'composer install' to use the bundled wp-phpunit/wp-phpunit package." >&2
        exit 1
    fi

    if ! svn export --force "$SVN_BASE" "$WP_TESTS_DIR" >/dev/null; then
        echo "Failed to download the WordPress test suite from $SVN_BASE." >&2
        echo "If network access to develop.svn.wordpress.org is unavailable, run 'composer install' to install wp-phpunit/wp-phpunit and re-run this script." >&2
        exit 1
    fi
}

configure_wp_tests() {
    local CONFIG_FILE="$WP_TESTS_DIR/wp-tests-config.php"

    if [ "$USING_VENDOR_TESTS" -eq 1 ]; then
        # The composer-provided test suite manages its own configuration.
        # Plugin bootstrap code sets WP_PHPUNIT__TESTS_CONFIG to point at the
        # local configuration file in tests/wp-tests-config.php, so we do not
        # need to create a second copy here.
        return
    fi

    if [ -f "$CONFIG_FILE" ]; then
        return
    fi

    cat > "$CONFIG_FILE" <<PHP
<?php
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', true );
define( 'ABSPATH', '${WP_CORE_DIR}' );
PHP
}

create_db() {
    if [ "$SKIP_DB_CREATE" = "true" ]; then
        return
    fi

    if command -v mysql >/dev/null; then
        mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" || true
    else
        echo "mysqladmin command not found; skipping database creation" >&2
    fi
}

install_wp
install_test_suite
configure_wp_tests
create_db
