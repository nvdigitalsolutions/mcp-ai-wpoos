#!/usr/bin/env php
<?php
/**
 * Manual test script for preset persistence.
 *
 * This script simulates applying a preset and checking if settings persist.
 *
 * Usage: php bin/test-preset-persistence.php
 */

// Define WordPress constants to prevent errors.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// Mock WordPress functions needed for basic testing.
if ( ! function_exists( 'get_option' ) ) {
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

	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( __( $text, $domain ) );
	}
}

// Load necessary classes.
require_once ABSPATH . 'includes/admin/class-wp-mcp-ai-admin-settings-base.php';
require_once ABSPATH . 'includes/admin/class-wp-mcp-ai-settings-registry.php';
require_once ABSPATH . 'includes/services/class-wp-mcp-ai-orchestration-preset-service.php';

echo "Testing Configuration Preset Persistence\n";
echo "=========================================\n\n";

// Test 1: Check defaults include orchestration settings.
echo "Test 1: Checking if orchestration settings are in defaults...\n";
$defaults = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

$required_settings = array(
	'orchestration_preset',
	'memory_warning_threshold',
	'prediction_safety_buffer',
	'high_tier_max_tokens',
);

$missing = array();
foreach ( $required_settings as $setting ) {
	if ( ! isset( $defaults[ $setting ] ) ) {
		$missing[] = $setting;
	}
}

if ( empty( $missing ) ) {
	echo "✓ All required orchestration settings are in defaults\n";
} else {
	echo '✗ Missing settings: ' . implode( ', ', $missing ) . "\n";
	exit( 1 );
}

// Test 2: Verify default values.
echo "\nTest 2: Verifying default values...\n";
$expected_defaults = array(
	'orchestration_preset'     => 'custom',
	'memory_warning_threshold' => 70,
	'prediction_safety_buffer' => 15,
	'high_tier_max_tokens'     => 32000,
);

$incorrect = array();
foreach ( $expected_defaults as $key => $expected ) {
	$actual = $defaults[ $key ];
	if ( $actual !== $expected ) {
		$incorrect[] = "$key (expected: $expected, got: $actual)";
	}
}

if ( empty( $incorrect ) ) {
	echo "✓ All default values are correct\n";
} else {
	echo "✗ Incorrect defaults:\n";
	foreach ( $incorrect as $error ) {
		echo "  - $error\n";
	}
	exit( 1 );
}

// Test 3: Simulate applying a preset.
echo "\nTest 3: Simulating preset application...\n";
delete_option( 'wp_mcp_ai_settings' );

$balanced = WP_MCP_AI_Orchestration_Preset_Service::get_presets()['balanced'];
echo "Applying 'balanced' preset with these values:\n";
foreach ( $balanced['settings'] as $key => $value ) {
	echo "  - $key: $value\n";
}

// Manually apply settings (simulating what the service does).
foreach ( $balanced['settings'] as $key => $value ) {
	WP_MCP_AI_Settings_Registry::update_setting( $key, $value );
}

// Verify settings were saved.
$saved = get_option( 'wp_mcp_ai_settings', array() );
echo "\nSaved settings in database:\n";
echo '  - memory_warning_threshold: ' . ( $saved['memory_warning_threshold'] ?? 'NOT SET' ) . "\n";
echo '  - prediction_safety_buffer: ' . ( $saved['prediction_safety_buffer'] ?? 'NOT SET' ) . "\n";

if ( isset( $saved['memory_warning_threshold'] ) && $saved['memory_warning_threshold'] === 70 ) {
	echo "✓ Settings were saved correctly\n";
} else {
	echo "✗ Settings were not saved correctly\n";
	exit( 1 );
}

// Test 4: Simulate partial form save (from different tab).
echo "\nTest 4: Simulating partial form save from different tab...\n";
$settings_base = new WP_MCP_AI_Admin_Settings_Base();

// Simulate form submission with only general settings.
$partial_form = array(
	'default_model'  => 'gpt-4o',
	'enable_logging' => '1',
	// Note: orchestration settings are NOT included.
);

$sanitized = $settings_base->sanitize_settings( $partial_form );

echo "Sanitized settings:\n";
echo '  - default_model: ' . ( $sanitized['default_model'] ?? 'NOT SET' ) . "\n";
echo '  - memory_warning_threshold: ' . ( $sanitized['memory_warning_threshold'] ?? 'NOT SET' ) . "\n";
echo '  - prediction_safety_buffer: ' . ( $sanitized['prediction_safety_buffer'] ?? 'NOT SET' ) . "\n";

// Check if orchestration settings were preserved.
if ( isset( $sanitized['memory_warning_threshold'] ) && $sanitized['memory_warning_threshold'] === 70 ) {
	echo "✓ Orchestration settings were preserved during partial save\n";
} else {
	echo "✗ Orchestration settings were lost during partial save\n";
	echo "  Expected memory_warning_threshold: 70\n";
	echo '  Got: ' . ( $sanitized['memory_warning_threshold'] ?? 'NOT SET' ) . "\n";
	exit( 1 );
}

echo "\n=========================================\n";
echo "All tests passed! ✓\n";
echo "Configuration presets should now persist correctly.\n";
