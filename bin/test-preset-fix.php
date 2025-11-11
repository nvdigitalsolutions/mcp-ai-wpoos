#!/usr/bin/env php
<?php
/**
 * Simple test for preset persistence fix.
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

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( __( $text, $domain ) );
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

function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$parsed_args = get_object_vars( $args );
	} elseif ( is_array( $args ) ) {
		$parsed_args =& $args;
	} else {
		wp_parse_str( $args, $parsed_args );
	}

	if ( is_array( $defaults ) && $defaults ) {
		return array_merge( $defaults, $parsed_args );
	}
	return $parsed_args;
}

function wp_parse_str( $input_string, &$result ) {
	parse_str( $input_string, $result );
}

function apply_filters( $hook_name, $value ) {
	return $value;  // Just pass through for testing.
}

// Define constants.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// Load class.
require_once ABSPATH . 'includes/admin/class-wp-mcp-ai-admin-settings-base.php';

echo "Simple Preset Persistence Test\n";
echo "===============================\n\n";

// Test 1: Check defaults.
echo "Test 1: Orchestration settings in defaults...\n";
$defaults = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

$tests = array(
	array( 'key' => 'orchestration_preset', 'expected' => 'custom' ),
	array( 'key' => 'memory_warning_threshold', 'expected' => 70 ),
	array( 'key' => 'prediction_safety_buffer', 'expected' => 15 ),
	array( 'key' => 'high_tier_max_tokens', 'expected' => 32000 ),
);

$passed = 0;
foreach ( $tests as $test ) {
	$key = $test['key'];
	$expected = $test['expected'];
	$actual = $defaults[ $key ] ?? 'MISSING';
	
	if ( $actual === $expected ) {
		echo "  ✓ $key = $expected\n";
		$passed++;
	} else {
		echo "  ✗ $key: expected $expected, got $actual\n";
	}
}

if ( $passed === count( $tests ) ) {
	echo "✓ Test 1 passed\n\n";
} else {
	echo "✗ Test 1 failed\n";
	exit( 1 );
}

// Test 2: Sanitize preserves existing values.
echo "Test 2: Sanitize preserves orchestration settings...\n";

// Set up initial state with orchestration settings.
update_option( 'wp_mcp_ai_settings', array(
	'memory_warning_threshold' => 80,
	'prediction_safety_buffer' => 20,
	'default_model' => 'gpt-4o',
	'enable_logging' => true,
) );

// Simulate form submission with only general settings.
$settings_base = new WP_MCP_AI_Admin_Settings_Base();
$partial_form = array(
	'default_model' => 'gpt-4o-mini',  // Changed.
	'enable_logging' => '1',  // Unchanged.
	// Orchestration settings NOT included in form.
);

$sanitized = $settings_base->sanitize_settings( $partial_form );

// Check if orchestration settings were preserved.
$tests2 = array(
	array( 'key' => 'memory_warning_threshold', 'expected' => 80, 'desc' => 'should be preserved' ),
	array( 'key' => 'prediction_safety_buffer', 'expected' => 20, 'desc' => 'should be preserved' ),
	array( 'key' => 'default_model', 'expected' => 'gpt-4o-mini', 'desc' => 'should be updated' ),
);

$passed2 = 0;
foreach ( $tests2 as $test ) {
	$key = $test['key'];
	$expected = $test['expected'];
	$desc = $test['desc'];
	$actual = $sanitized[ $key ] ?? 'MISSING';
	
	if ( $actual === $expected ) {
		echo "  ✓ $key = $expected ($desc)\n";
		$passed2++;
	} else {
		echo "  ✗ $key: expected $expected, got $actual ($desc)\n";
	}
}

if ( $passed2 === count( $tests2 ) ) {
	echo "✓ Test 2 passed\n\n";
} else {
	echo "✗ Test 2 failed\n";
	exit( 1 );
}

// Test 3: Checkboxes still reset correctly.
echo "Test 3: Checkboxes reset when unchecked...\n";

update_option( 'wp_mcp_ai_settings', array(
	'enable_budget_management' => true,
	'enable_logging' => true,
) );

$partial_form2 = array(
	'default_model' => 'gpt-4o',
	// Checkboxes not included = unchecked.
);

$sanitized2 = $settings_base->sanitize_settings( $partial_form2 );

if ( $sanitized2['enable_budget_management'] === false && $sanitized2['enable_logging'] === false ) {
	echo "  ✓ Checkboxes correctly reset to false\n";
	echo "✓ Test 3 passed\n\n";
} else {
	echo "  ✗ Checkboxes not reset correctly\n";
	echo "    enable_budget_management: " . var_export( $sanitized2['enable_budget_management'], true ) . "\n";
	echo "    enable_logging: " . var_export( $sanitized2['enable_logging'], true ) . "\n";
	exit( 1 );
}

echo "===============================\n";
echo "All tests passed! ✓\n";
echo "The fix correctly:\n";
echo "1. Adds orchestration settings to defaults\n";
echo "2. Preserves non-boolean settings when not in form\n";
echo "3. Resets checkboxes to false when unchecked\n";
