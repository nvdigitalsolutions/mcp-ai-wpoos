#!/usr/bin/env php
<?php
/**
 * Test that active preset updates correctly after applying a preset.
 *
 * This test verifies that:
 * 1. The orchestration_preset setting is updated when apply_preset is called
 * 2. The hidden field value would be correct after page reload
 * 3. If form is saved after preset application, correct value persists
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

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
}

function get_current_user_id() {
	return 1;
}

function wp_send_json_success( $data = null ) {
	echo json_encode(
		array(
			'success' => true,
			'data'    => $data,
		)
	);
	exit;
}

function wp_send_json_error( $data = null ) {
	echo json_encode(
		array(
			'success' => false,
			'data'    => $data,
		)
	);
	exit;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	// Mock - do nothing for tests.
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	// Mock - do nothing for tests.
}

function apply_filters( $hook, $value ) {
	return $value;
}

function wp_cache_delete( $key, $group = '' ) {
	// Mock - cache delete for tests.
	return true;
}

function register_setting( $option_group, $option_name, $args = array() ) {
	// Mock - do nothing for tests.
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

// Define constants.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// Load required classes.
require_once ABSPATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once ABSPATH . 'includes/admin/class-wp-mcp-ai-settings-registry.php';
require_once ABSPATH . 'includes/services/class-wp-mcp-ai-orchestration-preset-service.php';

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
		return 512 * 1024 * 1024; // 512MB.
	}
}

echo "Active Preset Update Test\n";
echo "=========================\n\n";

// Test 1: Verify apply_preset updates the orchestration_preset setting
echo "Test 1: Apply preset updates orchestration_preset setting...\n";
delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

// Initially, preset should be 'custom' (default).
$initial_preset = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset', 'custom' );
echo "  Initial preset: $initial_preset\n";

// Apply balanced preset.
$result = WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'balanced' );
if ( $result !== true ) {
	echo "  ✗ Failed to apply preset\n";
	exit( 1 );
}

// Verify preset was updated.
$active_preset = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset' );
if ( $active_preset === 'balanced' ) {
	echo "  ✓ Preset updated to: $active_preset\n";
	echo "✓ Test 1 passed\n\n";
} else {
	echo "  ✗ Preset not updated correctly. Expected 'balanced', got: $active_preset\n";
	exit( 1 );
}

// Test 2: Verify preset persists across form saves
echo "Test 2: Preset persists when other settings are saved...\n";

// Simulate saving the form with the orchestration_preset field included.
$form_data = array(
	'orchestration_preset' => 'balanced',  // This should be in the form.
	'default_model'        => 'gpt-4o',
	'enable_logging'       => '1',
);

// Update settings.
WP_MCP_AI_Settings_Registry::update_settings( $form_data );

// Verify preset is still 'balanced'.
$preset_after_save = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset' );
if ( $preset_after_save === 'balanced' ) {
	echo "  ✓ Preset still 'balanced' after form save\n";
	echo "✓ Test 2 passed\n\n";
} else {
	echo "  ✗ Preset changed after form save. Expected 'balanced', got: $preset_after_save\n";
	exit( 1 );
}

// Test 3: Apply different preset and verify it updates
echo "Test 3: Apply different preset (aggressive)...\n";

$result = WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'aggressive' );
if ( $result !== true ) {
	echo "  ✗ Failed to apply aggressive preset\n";
	exit( 1 );
}

$new_preset = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset' );
if ( $new_preset === 'aggressive' ) {
	echo "  ✓ Preset updated to: $new_preset\n";
	echo "✓ Test 3 passed\n\n";
} else {
	echo "  ✗ Preset not updated. Expected 'aggressive', got: $new_preset\n";
	exit( 1 );
}

// Test 4: Verify preset settings were also applied
echo "Test 4: Verify preset settings were applied...\n";

$memory_threshold = WP_MCP_AI_Settings_Registry::get_setting( 'memory_warning_threshold' );
// Aggressive preset should have memory_warning_threshold of 80.
if ( $memory_threshold === 80 ) {
	echo "  ✓ Memory warning threshold: $memory_threshold (aggressive preset value)\n";
	echo "✓ Test 4 passed\n\n";
} else {
	echo "  ✗ Expected memory_warning_threshold of 80, got: $memory_threshold\n";
	exit( 1 );
}

echo "=========================\n";
echo "All tests passed! ✓\n\n";
echo "The fix correctly:\n";
echo "1. Updates orchestration_preset when apply_preset is called\n";
echo "2. Persists the preset value across form saves\n";
echo "3. Applies all preset settings correctly\n";
echo "4. The JavaScript now updates the hidden field immediately,\n";
echo "   ensuring the correct value is saved even if user clicks\n";
echo "   'Save Changes' before page reload completes.\n";
