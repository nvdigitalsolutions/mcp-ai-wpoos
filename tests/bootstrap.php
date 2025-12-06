<?php
/**
 * PHPUnit bootstrap file.
 *
 * Sets up the WordPress test environment and loads the plugin.
 *
 * @package WP_MCP_AI
 */

$_tests_dir  = getenv( 'WP_TESTS_DIR' );
$plugin_root = dirname( __DIR__ );

if ( ! $_tests_dir ) {
	$vendor_tests_dir = $plugin_root . '/vendor/wp-phpunit/wp-phpunit';
	if ( file_exists( $vendor_tests_dir . '/includes/functions.php' ) ) {
		$_tests_dir = $vendor_tests_dir;
	} else {
		$_tests_dir = sys_get_temp_dir() . '/wordpress-tests-lib';
	}
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress tests directory at {$_tests_dir}.\n" );
	fwrite( STDERR, "Run 'composer run test:install' to download the WordPress testing framework or install the 'wp-phpunit/wp-phpunit' composer package.\n" );
	exit( 1 );
}

$wordpress_path = getenv( 'WP_CORE_DIR' );
if ( ! $wordpress_path ) {
	$codex_path     = $plugin_root . '/.codex-wordpress/wordpress';
	$startup_script = $plugin_root . '/bin/codex-startup.sh';

	if ( ! file_exists( $codex_path . '/wp-load.php' ) && is_file( $startup_script ) ) {
		fwrite( STDERR, "WordPress core not found. Running codex-startup provisioning script...\n" );

		$startup_output = array();
		$startup_result = 0;

		// Check if exec() is available before attempting to run the script.
		if ( function_exists( 'exec' ) ) {
			exec( escapeshellcmd( $startup_script ) . ' 2>&1', $startup_output, $startup_result );

			if ( ! empty( $startup_output ) ) {
				fwrite( STDERR, implode( "\n", $startup_output ) . "\n" );
			}

			if ( 0 !== $startup_result ) {
				fwrite( STDERR, "codex-startup.sh exited with a non-zero status ({$startup_result}).\n" );
			}
		} else {
			fwrite( STDERR, "Warning: exec() function is disabled. Cannot run automatic WordPress setup.\n" );
			fwrite( STDERR, "Please set WP_CORE_DIR environment variable or manually run: {$startup_script}\n" );
		}
	}

	if ( file_exists( $codex_path . '/wp-load.php' ) ) {
		$wordpress_path = $codex_path;
	}
}

if ( ! $wordpress_path ) {
	fwrite( STDERR, "Could not locate a WordPress installation.\n" );
	fwrite( STDERR, "Run 'bin/codex-startup.sh' or define the WP_CORE_DIR environment variable before executing the tests.\n" );
	exit( 1 );
}

$tests_db_dir = $plugin_root . '/.codex-wordpress/tests-database';
if ( ! is_dir( $tests_db_dir ) && ! mkdir( $tests_db_dir, 0775, true ) && ! is_dir( $tests_db_dir ) ) {
	fwrite( STDERR, "Unable to create the SQLite directory at {$tests_db_dir}.\n" );
	exit( 1 );
}

$polyfills_root = $plugin_root . '/vendor/yoast/phpunit-polyfills';
if ( file_exists( $polyfills_root . '/phpunitpolyfills-autoload.php' ) && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_root );
}

$tests_config = $plugin_root . '/tests/wp-tests-config.php';

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $tests_config );
}

if ( ! defined( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	define( 'WP_PHPUNIT__TESTS_CONFIG', $tests_config );
}

define( 'WP_TESTS_CONFIG_FILE_PATH', $tests_config );

// Enable full version for tests to load all integration classes.
// Individual tests can use the wp_mcp_ai_base_version filter to test base version behavior.
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
	define( 'WP_MCP_AI_BASE_VERSION', false );
}

require_once $_tests_dir . '/includes/functions.php';
require_once __DIR__ . '/helpers/trait-wp-mcp-ai-docx-test-helper.php';
require_once __DIR__ . '/helpers/trait-wp-mcp-ai-rest-test-helper.php';
require_once __DIR__ . '/helpers/class-wp-mcp-ai-test-helper.php';

/**
 * Manually load the plugin being tested.
 */
function wp_mcp_ai_manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-mcp-ai.php';
}

tests_add_filter( 'muplugins_loaded', 'wp_mcp_ai_manually_load_plugin' );

/**
 * Set up test environment with admin user and authentication.
 */
function wp_mcp_ai_setup_test_environment() {
	// Create admin user for tests.
	$admin_id = wp_create_user( 'test_admin', 'password', 'admin@example.com' );
	$admin    = new WP_User( $admin_id );
	$admin->set_role( 'administrator' );
	
	// Set as current user.
	wp_set_current_user( $admin_id );
	
	// Set up REST authentication.
	$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
	
	// Set auth cookie.
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in' );
	
	// Enable all capabilities for admin user in tests.
	add_filter(
		'user_has_cap',
		function ( $allcaps ) {
			$allcaps['manage_options']    = true;
			$allcaps['edit_posts']         = true;
			$allcaps['upload_files']       = true;
			$allcaps['edit_others_posts']  = true;
			$allcaps['delete_posts']       = true;
			return $allcaps;
		}
	);
}

tests_add_filter( 'wp_loaded', 'wp_mcp_ai_setup_test_environment' );

require $_tests_dir . '/includes/bootstrap.php';
