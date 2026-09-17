<?php
// phpcs:ignoreFile Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.PHP.CommentedOutCode -- Bootstrap file: mixes the PHPUnit 11 compat shim class with loader functions, mirroring the base plugin's tests/bootstrap.php.
/**
 * PHPUnit bootstrap for NV oOS Content Graph.
 *
 * Loads Composer autoloader and the WordPress test suite.
 *
 * @package NvoosContentGraph
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
//
// Prefer the wp-phpunit copy shipped in this plugin's own vendor directory:
// the monorepo root suite patches ITS vendor copy at bootstrap time (for
// PHPUnit 11) and writes the patch back to disk, and wp-phpunit can copy that
// patched file into the shared temp-dir test lib — which then breaks this
// plugin's PHPUnit 9.6 runs (`Call to undefined method ::name()`). Using the
// pristine vendored copy keeps a fresh clone of this plugin self-contained.
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

// ============================================================
// PHPUnit 11 Compatibility: parseTestMethodAnnotations() was
// removed in PHPUnit 10+. The pinned wp-phpunit 7.0.2
// abstract-testcase.php still calls it via this shim class.
// Define it here (mirrors the base plugin's tests/bootstrap.php)
// so the suite runs under both PHPUnit 9 and 11.
// ============================================================
if ( ! class_exists( 'WP_MCP_AI_PHPUnit11_Compat' ) ) {

	/**
	 * PHPUnit 11 compatibility shim.
	 *
	 * Provides a stub for the removed parseTestMethodAnnotations()
	 * method, returning empty arrays.
	 */
	class WP_MCP_AI_PHPUnit11_Compat {

		/**
		 * Stub for removed PHPUnit 9 parseTestMethodAnnotations().
		 *
		 * @param string $cn Class name.
		 * @param string $mn Optional method name.
		 * @return array<string,array>
		 */
		public static function parseTestMethodAnnotations( $cn, $mn = null ) {
			return array(
				'class'  => array(),
				'method' => array(),
			);
		}
	}
}

/**
 * Manually load the plugin.
 */
function _manually_load_nvoos_content_graph_plugin(): void {
	require_once dirname( __DIR__ ) . '/nvoos-content-graph.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_nvoos_content_graph_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
