<?php
/**
 * PHPUnit bootstrap for NV oOS Algorave — AI.
 *
 * Loads the Composer autoloader (when present), the WordPress test suite,
 * and manually activates the NV oOS base plugin (when testing inside the
 * monorepo), the standalone NV oOS Algorave plugin, and this addon.
 *
 * @package NV_oOS_Algorave_AI
 */

// Composer autoloader (optional).
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
 * Manually load the plugins.
 *
 * @return void
 */
function _manually_load_nvoos_algorave_ai_plugins() {
	// NV oOS base plugin — provides the WP_MCP_AI tool interfaces. Present
	// when this addon is tested inside the NV oOS monorepo.
	$base_plugin = dirname( __DIR__, 3 ) . '/mcp-ai-wpoos.php';
	if ( file_exists( $base_plugin ) ) {
		require_once $base_plugin;
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR stream; WP_Filesystem not loaded yet.
		fwrite( STDERR, "Notice: NV oOS base plugin not found; interface-dependent tests will be skipped.\n" );
	}

	// Standalone parent plugin.
	require_once dirname( __DIR__ ) . '/../nvoos-algorave/nvoos-algorave.php';

	// This addon.
	require_once dirname( __DIR__ ) . '/nvoos-algorave-ai.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_nvoos_algorave_ai_plugins' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
