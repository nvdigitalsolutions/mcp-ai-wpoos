<?php
/**
 * Local configuration for the WordPress PHPUnit test suite.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'wordpress_test' );
}

if ( ! defined( 'DB_USER' ) ) {
	define( 'DB_USER', 'root' );
}

if ( ! defined( 'DB_PASSWORD' ) ) {
	define( 'DB_PASSWORD', '' );
}

if ( ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', 'localhost' );
}

if ( ! defined( 'DB_CHARSET' ) ) {
	define( 'DB_CHARSET', 'utf8' );
}

if ( ! defined( 'DB_COLLATE' ) ) {
	define( 'DB_COLLATE', '' );
}

// SQLite configuration for local development (optional).
// if ( ! defined( 'DB_TYPE' ) ) {
// define( 'DB_TYPE', 'sqlite' );
// }.
//
// if ( ! defined( 'DB_DIR' ) ) {
// define( 'DB_DIR', dirname( __DIR__ ) . '/.codex-wordpress/tests-database' );
// }.
//
// if ( ! defined( 'DB_FILE' ) ) {
// define( 'DB_FILE', 'wptests.sqlite' );
// }.

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
	define( 'WP_TESTS_TITLE', 'WP oOS Test Suite' );
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
