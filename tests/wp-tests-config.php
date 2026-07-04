<?php
/**
 * Local configuration for the WordPress PHPUnit test suite.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// Detect CI / MySQL environment via the WP_DB_HOST env var exported by phpunit.yml.
// When WP_DB_HOST is set (e.g. "127.0.0.1" in GitHub Actions), use real MySQL and
// pick up credentials from the WP_DB_* env vars.  Otherwise fall back to SQLite for
// local / Codex environments where the SQLite drop-in is available.
$_wp_db_host  = getenv( 'WP_DB_HOST' );
$_studio_mode = false;

// ── WordPress Studio: auto-detect DB credentials from wp-config ──
if ( ! $_wp_db_host ) {
	$wordpress_path = getenv( 'WP_CORE_DIR' );
	if ( ! $wordpress_path ) {
		$studio_slug = getenv( 'WP_STUDIO_SITE_SLUG' );
		if ( $studio_slug ) {
			if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
				$studio_base = getenv( 'LOCALAPPDATA' ) . '/WordPress Studio/sites';
			} else {
				$studio_base = getenv( 'HOME' ) . '/Library/Application Support/WordPress Studio/sites';
			}
			$wordpress_path = $studio_base . '/' . $studio_slug . '/app/public';
		}
	}

	// Try to read Studio's wp-config.php for MySQL credentials.
	$studio_config = ( $wordpress_path ? $wordpress_path . '/wp-config.php' : '' );
	if ( $studio_config && file_exists( $studio_config ) && ! getenv( 'WP_DB_HOST' ) ) {
		$config_contents = file_get_contents( $studio_config );
		if ( $config_contents && preg_match( "/define\s*\(\s*'DB_HOST'\s*,\s*'([^']+)'\s*\)/", $config_contents, $m ) ) {
			$_wp_db_host  = $m[1];
			$_studio_mode = true;
		}
	}
}

if ( ! defined( 'DB_NAME' ) ) {
	$studio_test_db = $_studio_mode ? 'wordpress_test' : null;
	$db_name        = getenv( 'WP_DB_NAME' ) ? getenv( 'WP_DB_NAME' ) : ( $studio_test_db ? $studio_test_db : 'wordpress_test' );
	define( 'DB_NAME', $db_name );
}

if ( ! defined( 'DB_USER' ) ) {
	$studio_db_user = $_studio_mode ? 'root' : null;
	$db_user        = getenv( 'WP_DB_USER' ) ? getenv( 'WP_DB_USER' ) : ( $studio_db_user ? $studio_db_user : 'WordPress' );
	define( 'DB_USER', $db_user );
}

if ( ! defined( 'DB_PASSWORD' ) ) {
	$studio_db_pass = $_studio_mode ? 'root' : null;
	$db_password    = getenv( 'WP_DB_PASSWORD' ) ? getenv( 'WP_DB_PASSWORD' ) : ( $studio_db_pass ? $studio_db_pass : 'WordPress' );
	define( 'DB_PASSWORD', $db_password );
}

if ( ! defined( 'DB_HOST' ) ) {
	// Use 127.0.0.1 (TCP) when a host env var is provided; avoids Unix-socket
	// "No such file or directory" errors in GitHub Actions MySQL service containers.
	define( 'DB_HOST', $_wp_db_host ? $_wp_db_host : 'localhost' );
}

if ( ! defined( 'DB_CHARSET' ) ) {
	define( 'DB_CHARSET', 'utf8' );
}

if ( ! defined( 'DB_COLLATE' ) ) {
	define( 'DB_COLLATE', '' );
}

// Use SQLite only in local / Codex / Studio environments (no WP_DB_HOST env var).
// In CI the MySQL service container is used instead.
// Studio sites use their own MySQL; tests create a separate test database
// using SQLite for isolation regardless of the production DB type.
if ( ! $_wp_db_host || $_studio_mode ) {
	if ( ! defined( 'DB_TYPE' ) ) {
		define( 'DB_TYPE', 'sqlite' );
	}

	if ( ! defined( 'DB_DIR' ) ) {
		// Use Studio-temp or Codex path depending on environment.
		$studio_slug = getenv( 'WP_STUDIO_SITE_SLUG' );
		if ( $studio_slug ) {
			define( 'DB_DIR', sys_get_temp_dir() . '/wp-mcp-ai-tests-database/' . $studio_slug );
		} else {
			define( 'DB_DIR', dirname( __DIR__ ) . '/.codex-wordpress/tests-database' );
		}
	}

	if ( ! defined( 'DB_FILE' ) ) {
		define( 'DB_FILE', 'wptests.sqlite' );
	}

	if ( $_studio_mode ) {
		fwrite( STDOUT, "  (Using SQLite for test isolation — production site uses MySQL)\n" );
	}
}

if ( ! isset( $table_prefix ) ) {
	$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required for WordPress test suite.
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
	define( 'WP_TESTS_DOMAIN', 'example.org' );
}

if ( ! defined( 'WP_TESTS_EMAIL' ) ) {
	define( 'WP_TESTS_EMAIL', 'admin@example.org' );
}

if ( ! defined( 'WP_TESTS_TITLE' ) ) {
	define( 'WP_TESTS_TITLE', 'NV oOS Test Suite' );
}

if ( ! defined( 'WP_PHP_BINARY' ) ) {
	define( 'WP_PHP_BINARY', 'php' );
}

if ( ! defined( 'ABSPATH' ) ) {
	$core_dir = getenv( 'WP_CORE_DIR' );
	if ( ! $core_dir ) {
		$core_dir = dirname( __DIR__ ) . '/.codex-wordpress/wordpress';
	}

	define( 'ABSPATH', rtrim( $core_dir, '/\\' ) . '/' );
}
