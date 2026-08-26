<?php
/**
 * PHPUnit bootstrap for NV oOS Algorave.
 *
 * Loads the Composer autoloader (when present) and the WordPress test
 * suite, then manually activates the plugin.
 *
 * @package NV_oOS_Algorave
 */

// Composer autoloader (optional — the plugin also works without it).
$autoload = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

// WordPress test suite location: WP_TESTS_DIR env, monorepo vendor copy,
// or the default wp-cli scaffold location.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$monorepo_vendor = dirname( __DIR__, 3 ) . '/vendor/wp-phpunit/wp-phpunit';
	if ( file_exists( $monorepo_vendor . '/includes/functions.php' ) ) {
		$_tests_dir = $monorepo_vendor;
	} else {
		$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
	}
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR stream; WP_Filesystem not loaded yet.
	fwrite(
		STDERR,
		sprintf(
			"WordPress test suite not found at %s. Set the WP_TESTS_DIR environment variable or install the test suite (see https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/).\n",
			$_tests_dir
		)
	);
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

// Wire the monorepo's wp-tests-config.php into wp-phpunit when present.
$tests_config = dirname( __DIR__, 3 ) . '/tests/wp-tests-config.php';
if ( file_exists( $tests_config ) ) {
	if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
		putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $tests_config );
	}
	if ( ! defined( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
		define( 'WP_PHPUNIT__TESTS_CONFIG', $tests_config );
	}
}

/**
 * Manually load the plugin.
 *
 * @return void
 */
function _manually_load_nvoos_algorave_plugin() {
	require_once dirname( __DIR__ ) . '/nvoos-algorave.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_nvoos_algorave_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
