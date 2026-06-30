<?php
/**
 * PHPUnit bootstrap for NV oOS Graphify.
 *
 * Loads Composer autoloader and the WordPress test suite.
 *
 * @package NvoosGraphify
 */
declare(strict_types=1);

// Composer autoloader.
$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR stream; WP_Filesystem not loaded yet.
		fwrite( STDERR, "Composer autoloader not found. Run `composer install` first.\n" );
		exit( 1 );
}
require_once $autoload;

// WordPress test suite.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR stream; WP_Filesystem not loaded yet.
		fwrite(
			STDERR,
			sprintf(
				"WordPress test suite not found at %s. Set WP_TESTS_DIR environment variable or install the test suite (see https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/).\n",
				$_tests_dir
			)
		);
		exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin.
 */
function _manually_load_nvoos_graphify_plugin(): void {
	require_once dirname( __DIR__ ) . '/nvoos-graphify.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_nvoos_graphify_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
