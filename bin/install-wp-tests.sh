#!/usr/bin/env bash
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
#
# Downloads and configures the WordPress testing framework.

set -e

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
WP_TESTS_DIR=${WP_TESTS_DIR-${TMPDIR}/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-${TMPDIR}/wordpress/}

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
    echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]" >&2
    exit 1
fi

download() {
    if command -v curl >/dev/null; then
        curl -sSL "$1" -o "$2"
    elif command -v wget >/dev/null; then
        wget -q "$1" -O "$2"
    else
        echo "Could not find curl or wget" >&2
        exit 1
    fi
}

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then
        return
    fi

    mkdir -p "$WP_CORE_DIR"

    local ARCHIVE_NAME='latest'

    if [ "$WP_VERSION" != 'latest' ]; then
        ARCHIVE_NAME="wordpress-${WP_VERSION}"
    fi

    download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "$TMPDIR/wordpress.tar.gz"
    tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR/includes" ]; then
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
        exit 1
    fi

    svn export --force "$SVN_BASE" "$WP_TESTS_DIR" >/dev/null
}

configure_wp_tests() {
    local CONFIG_FILE="$WP_TESTS_DIR/wp-tests-config.php"

    if [ -f "$CONFIG_FILE" ]; then
        return
    fi

    download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "$CONFIG_FILE"

    local SED_OPTS=(-i)
    if [ "$(uname -s)" = 'Darwin' ]; then
        SED_OPTS=(-i '')
    fi

    sed "${SED_OPTS[@]}" "s/youremptytestdbnamehere/$DB_NAME/" "$CONFIG_FILE"
    sed "${SED_OPTS[@]}" "s/yourusernamehere/$DB_USER/" "$CONFIG_FILE"
    sed "${SED_OPTS[@]}" "s/yourpasswordhere/$DB_PASS/" "$CONFIG_FILE"
    sed "${SED_OPTS[@]}" "s:localhost:$DB_HOST:" "$CONFIG_FILE"
    sed "${SED_OPTS[@]}" "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR':" "$CONFIG_FILE"

    cat <<EXTRA >> "$CONFIG_FILE"

define( 'WP_DEBUG', true );
EXTRA
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
