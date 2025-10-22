<?php
/**
 * PHPUnit bootstrap file.
 *
 * Sets up the WordPress test environment and loads the plugin.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
    $_tests_dir = sys_get_temp_dir() . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    fwrite( STDERR, "Could not find the WordPress tests directory at {$_tests_dir}.\n" );
    fwrite( STDERR, "Run 'composer run test:install' to download the WordPress testing framework.\n" );
    exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function wp_mcp_ai_manually_load_plugin() {
    require dirname( __DIR__ ) . '/wp-mcp-ai.php';
}

tests_add_filter( 'muplugins_loaded', 'wp_mcp_ai_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
