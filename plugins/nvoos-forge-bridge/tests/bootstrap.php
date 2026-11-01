<?php
// phpcs:ignoreFile
/**
 * PHPUnit bootstrap for NV oOS Forge Bridge.
 *
 * Loads the Composer autoloader, the WordPress test suite (wp-phpunit from
 * this plugin's own vendor, or WP_TESTS_DIR), then boots the plugin the real
 * way (main file → plugins_loaded hook) so tests exercise the actual boot
 * path, not a hand-rolled one.
 *
 * @package NvoosForgeBridge
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR stream.
	fwrite( STDERR, "Composer autoloader not found. Run `composer install` first.\n" );
	exit( 1 );
}
require_once $autoload;

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$vendored_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
	if ( file_exists( $vendored_tests_dir . '/includes/functions.php' ) ) {
		$_tests_dir = $vendored_tests_dir;
	} else {
		$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
	}
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR stream.
	fwrite(
		STDERR,
		sprintf(
			"WordPress test suite not found at %s. Set WP_TESTS_DIR or install wp-phpunit.\n",
			$_tests_dir
		)
	);
	exit( 1 );
}

require_once $_tests_dir . '/includes/bootstrap.php';

// Boot the plugin through its real entry point.
require_once dirname( __DIR__ ) . '/nvoos-forge-bridge.php';
