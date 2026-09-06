<?php
/**
 * PHPUnit bootstrap for NV oOS Content Graph — AI.
 *
 * @package NvoosContentGraphAi
 */

// Detect the WordPress test suite location.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// Default path when using wp-phpunit/wp-phpunit Composer package.
	$_tests_dir = __DIR__ . '/../vendor/wp-phpunit/wp-phpunit/includes';
}

if ( ! file_exists( $_tests_dir . '/bootstrap.php' ) ) {
	// Fallback: try parent plugin's vendor directory.
	$_tests_dir = __DIR__ . '/../../nvoos-content-graph/vendor/wp-phpunit/wp-phpunit/includes';
}

if ( ! file_exists( $_tests_dir . '/bootstrap.php' ) ) {
	fwrite( STDERR, "WP test suite not found. Set WP_TESTS_DIR environment variable.\n" );
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/functions.php';

/**
 * Load the parent plugin and the AI addon once WordPress is available.
 *
 * Equivalent to activating both plugins in a real install: requiring the
 * main files registers their autoloaders and hooks before plugins_loaded.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// Parent plugin first — the addon guards on its public functions.
		$parent = dirname( __DIR__ ) . '/../nvoos-content-graph/nvoos-content-graph.php';
		if ( file_exists( $parent ) ) {
			require_once $parent;
		}

		require_once dirname( __DIR__ ) . '/nvoos-content-graph-ai.php';
	}
);

// Load the WordPress test bootstrap (runs wp-settings in-process and
// fires the muplugins_loaded hook registered above).
require_once $_tests_dir . '/bootstrap.php';
