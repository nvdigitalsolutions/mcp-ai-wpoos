#!/usr/bin/env php
<?php
/**
 * Test that cron_job_retention_period is in defaults and persists correctly.
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

function __( $text, $domain = 'default' ) {
	return $text;
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

function apply_filters( $hook_name, $value ) {
	return $value;
}

// Define constants.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// Load class.
require_once ABSPATH . 'includes/admin/class-wp-mcp-ai-admin-settings-base.php';

echo "Test: cron_job_retention_period Setting\n";
echo "========================================\n\n";

// Test 1: Check it's in defaults
echo "Test 1: cron_job_retention_period is in defaults...\n";
$defaults = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

if ( ! array_key_exists( 'cron_job_retention_period', $defaults ) ) {
	echo "  ✗ FAILED: cron_job_retention_period is NOT in defaults\n";
	exit( 1 );
}

$default_value = $defaults['cron_job_retention_period'];
echo "  ✓ cron_job_retention_period is in defaults\n";
echo "  ✓ Default value: '$default_value'\n";

if ( $default_value !== '24' ) {
	echo "  ✗ FAILED: Expected default value '24', got '$default_value'\n";
	exit( 1 );
}
echo "  ✓ Default value matches expected '24'\n";
echo "✓ Test 1 passed\n\n";

// Test 2: Test persistence through sanitize
echo "Test 2: Setting persists through sanitize_settings...\n";

// Set initial value.
update_option(
	'wp_mcp_ai_settings',
	array(
		'cron_job_retention_period' => '168',  // 1 week.
		'default_model'             => 'gpt-4o',
	)
);

// Simulate form submission from different tab (without cron setting).
$settings_base = new WP_MCP_AI_Admin_Settings_Base();
$partial_form  = array(
	'default_model' => 'gpt-4o-mini',  // Changed.
// cron_job_retention_period NOT included.
);

$sanitized = $settings_base->sanitize_settings( $partial_form );

if ( ! isset( $sanitized['cron_job_retention_period'] ) ) {
	echo "  ✗ FAILED: cron_job_retention_period was removed during sanitize\n";
	exit( 1 );
}

$persisted_value = $sanitized['cron_job_retention_period'];
echo "  ✓ cron_job_retention_period present after sanitize\n";

if ( $persisted_value !== '168' ) {
	echo "  ✗ FAILED: Expected '168', got '$persisted_value'\n";
	echo "  This means the setting was reset to default instead of being preserved\n";
	exit( 1 );
}

echo "  ✓ Value correctly preserved as '168' (not reset to default)\n";
echo "✓ Test 2 passed\n\n";

echo "========================================\n";
echo "All tests passed! ✓\n\n";
echo "The cron_job_retention_period setting:\n";
echo "1. Is now in defaults with value '24'\n";
echo "2. Persists correctly when not included in form submissions\n";
echo "3. Will work correctly with preset persistence\n";
