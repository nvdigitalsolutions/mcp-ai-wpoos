<?php
/**
 * PHPUnit bootstrap for NV oOS Graphify — AI.
 *
 * @package NvoosGraphifyAi
 */

// Detect the WordPress test suite location.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// Default path when using wp-phpunit/wp-phpunit Composer package.
	$_tests_dir = __DIR__ . '/../vendor/wp-phpunit/wp-phpunit/includes';
}

if ( ! file_exists( $_tests_dir . '/bootstrap.php' ) ) {
	// Fallback: try parent plugin's vendor directory.
	$_tests_dir = __DIR__ . '/../../nvoos-graphify/vendor/wp-phpunit/wp-phpunit/includes';
}

if ( ! file_exists( $_tests_dir . '/bootstrap.php' ) ) {
	fwrite( STDERR, "WP test suite not found. Set WP_TESTS_DIR environment variable.\n" );
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/bootstrap.php';
