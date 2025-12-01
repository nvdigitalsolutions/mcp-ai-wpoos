#!/usr/bin/env php
<?php
/**
 * Test preset persistence after page reload scenario.
 *
 * Simulates:
 * 1. User applies preset via AJAX
 * 2. Page reloads
 * 3. Page renders - should show correct active preset
 */

// Mock WordPress functions.
$mock_options = array();

function get_option( $option, $default = false ) {
	global $mock_options;
	return isset( $mock_options[ $option ] ) ? $mock_options[ $option ] : $default;
}

function update_option( $option, $value ) {
	global $mock_options;
	$mock_options[ $option ] = $value;
	return true;
}

function delete_option( $option ) {
	global $mock_options;
	unset( $mock_options[ $option ] );
	return true;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( __( $text, $domain ) );
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
}

function get_current_user_id() {
	return 1;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	// Mock - do nothing for tests.
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	// Mock - do nothing for tests.
}

function register_setting( $option_group, $option_name, $args = array() ) {
	// Mock - do nothing for tests.
}

function sanitize_text_field( $str ) {
	return trim( strip_tags( $str ) );
}

function sanitize_email( $email ) {
	return filter_var( $email, FILTER_SANITIZE_EMAIL );
}

function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL );
}

function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

function wp_cache_delete( $key, $group = '' ) {
	// Mock - do nothing for tests. In real WordPress, this clears object cache.
	return true;
}

// Mock WP_Error class.
class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message, $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// Mock logger.
class WP_MCP_AI_Logger {
	public static function log_error( $message, $context = array() ) {
		// Silent for tests.
	}

	public static function log_event( $message, $description, $context = array() ) {
		// Silent for tests.
	}
}

// Mock Resource Manager.
class WP_MCP_AI_Resource_Manager {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_memory_limit() {
		return 512 * 1024 * 1024; // 512MB - Medium tier
	}
}

// Define constants.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// Load required classes.
require_once ABSPATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once ABSPATH . 'includes/admin/class-wp-mcp-ai-settings-registry.php';
require_once ABSPATH . 'includes/services/class-wp-mcp-ai-orchestration-preset-service.php';

echo "Preset Page Reload Scenario Test\n";
echo "=================================\n\n";

// Simulate initial page load - no settings yet.
delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

echo "Step 1: Initial page load (no preset saved yet)...\n";
$initial_preset = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset', 'custom' );
echo "  Current preset: $initial_preset (should be 'custom' - the default)\n";

if ( $initial_preset !== 'custom' ) {
	echo "  ✗ Initial preset should be 'custom'\n";
	exit( 1 );
}
echo "  ✓ Correct initial state\n\n";

// Step 2: User clicks "Apply" on "Balanced" preset.
echo "Step 2: User clicks 'Apply' on 'Balanced' preset (AJAX call)...\n";
$result = WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'balanced' );

if ( $result !== true ) {
	echo "  ✗ Failed to apply preset\n";
	exit( 1 );
}

// Verify it was saved to database.
$saved_options = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
$saved_preset  = isset( $saved_options['orchestration_preset'] ) ? $saved_options['orchestration_preset'] : 'NOT SET';
echo "  Saved to database: orchestration_preset = $saved_preset\n";

if ( $saved_preset !== 'balanced' ) {
	echo "  ✗ Preset not saved correctly to database\n";
	exit( 1 );
}
echo "  ✓ Preset saved to database\n\n";

// Step 3: Simulate page reload - re-read from database.
echo "Step 3: Page reloads (simulating browser refresh)...\n";
echo "  Reading orchestration_preset from database...\n";

$current_preset = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset', 'custom' );
echo "  Current preset: $current_preset\n";

if ( $current_preset !== 'balanced' ) {
	echo "  ✗ After reload, preset should be 'balanced' but got '$current_preset'\n";
	echo "  THIS IS THE BUG! The preset reverted to default instead of showing saved value.\n";
	exit( 1 );
}
echo "  ✓ Preset correctly shows as 'balanced' after reload\n\n";

// Step 4: Verify the preset card would show as active.
echo "Step 4: Verify UI would render correctly...\n";
$presets             = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
$current_preset_name = isset( $presets[ $current_preset ]['name'] ) ? $presets[ $current_preset ]['name'] : 'Unknown';
echo "  Would display: 'Currently Active: $current_preset_name'\n";

if ( $current_preset_name !== 'Balanced' ) {
	echo "  ✗ Should display 'Balanced' but would display '$current_preset_name'\n";
	exit( 1 );
}
echo "  ✓ UI would correctly show 'Balanced' as active\n\n";

echo "=================================\n";
echo "All tests passed! ✓\n\n";
echo "The preset persistence is working correctly:\n";
echo "1. Preset is saved to database when applied via AJAX\n";
echo "2. Preset value persists after page reload\n";
echo "3. UI correctly displays the active preset\n";
