<?php
/**
 * WordPress unit tests configuration for testing.
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_STRICT );

// Get WordPress path
$_wp_core_dir = getenv( 'WP_CORE_DIR' );
if ( ! $_wp_core_dir ) {
$_wp_core_dir = '/tmp/wordpress/';
}

// Get test directory
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Setup database defaults
$_tests_db_name = getenv( 'WP_DB_NAME' ) ?: 'wordpress_test';
$_tests_db_user = getenv( 'WP_DB_USER' ) ?: 'root';
$_tests_db_pass = getenv( 'WP_DB_PASSWORD' ) ?: 'root';
$_tests_db_host = getenv( 'WP_DB_HOST' ) ?: 'localhost';

// Define database constants
if ( ! defined( 'DB_NAME' ) ) {
define( 'DB_NAME', $_tests_db_name );
}
if ( ! defined( 'DB_USER' ) ) {
define( 'DB_USER', $_tests_db_user );
}
if ( ! defined( 'DB_PASSWORD' ) ) {
define( 'DB_PASSWORD', $_tests_db_pass );
}
if ( ! defined( 'DB_HOST' ) ) {
define( 'DB_HOST', $_tests_db_host );
}

if ( ! defined( 'DB_CHARSET' ) ) {
define( 'DB_CHARSET', 'utf8' );
}
if ( ! defined( 'DB_COLLATE' ) ) {
define( 'DB_COLLATE', '' );
}

if ( ! defined( 'WP_TESTS_MULTISITE' ) ) {
define( 'WP_TESTS_MULTISITE', false );
}

// Authentication keys and salts
if ( ! defined( 'AUTH_KEY' ) ) {
define( 'AUTH_KEY',         'put your unique phrase here' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );
}
if ( ! defined( 'LOGGED_IN_KEY' ) ) {
define( 'LOGGED_IN_KEY',    'put your unique phrase here' );
}
if ( ! defined( 'NONCE_KEY' ) ) {
define( 'NONCE_KEY',        'put your unique phrase here' );
}
if ( ! defined( 'AUTH_SALT' ) ) {
define( 'AUTH_SALT',        'put your unique phrase here' );
}
if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
}
if ( ! defined( 'LOGGED_IN_SALT' ) ) {
define( 'LOGGED_IN_SALT',   'put your unique phrase here' );
}
if ( ! defined( 'NONCE_SALT' ) ) {
define( 'NONCE_SALT',       'put your unique phrase here' );
}

if ( ! defined( 'WP_TESTS_TABLE_PREFIX' ) ) {
define( 'WP_TESTS_TABLE_PREFIX', 'wptests_' );
}

$table_prefix = WP_TESTS_TABLE_PREFIX;

if ( ! defined( 'WP_DEBUG' ) ) {
define( 'WP_DEBUG', false );
}

if ( ! defined( 'WP_DEBUG_LOG' ) ) {
@define( 'WP_DEBUG_LOG', false );
}

if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
define( 'WP_DEBUG_DISPLAY', false );
}

if ( ! defined( 'ABSPATH' ) ) {
define( 'ABSPATH', rtrim( $_wp_core_dir, '/' ) . '/' );
}

// Define remaining constants
if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
define( 'WP_TESTS_DOMAIN', 'example.org' );
}
if ( ! defined( 'WP_TESTS_EMAIL' ) ) {
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
}
if ( ! defined( 'WP_TESTS_TITLE' ) ) {
define( 'WP_TESTS_TITLE', 'Test Blog' );
}
if ( ! defined( 'WP_PHP_BINARY' ) ) {
define( 'WP_PHP_BINARY', 'php' );
}

// Define pro addon constants for testing
if ( ! defined( 'WP_MCP_AI_PRO_FILE' ) ) {
define( 'WP_MCP_AI_PRO_FILE', dirname( dirname( __FILE__ ) ) . '/addons/pro/mcp-ai-wpoos-pro.php' );
}
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
define( 'WP_MCP_AI_PRO_PATH', dirname( dirname( __FILE__ ) ) . '/addons/pro/' );
}
