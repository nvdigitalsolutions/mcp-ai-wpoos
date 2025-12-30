#!/usr/bin/env php
<?php
/**
 * Verify all settings used in the codebase have defaults.
 */

// Get all settings used via WP_MCP_AI_Settings_Registry::get_setting()
$settings_used = array(
	'cron_job_retention_period',
	'enable_logging',
	'enable_predictive_optimization',
	'error_rate_critical_threshold',
	'error_rate_warning_threshold',
	'memory_critical_threshold',
	'memory_warning_threshold',
	'openai_api_key',
	'orchestration_preset',
	'prediction_confidence_threshold',
	'provider_priority_list',
);

// Define mock functions.
function __( $text, $domain = 'default' ) {
	return $text; }
function sanitize_text_field( $str ) {
	return trim( strip_tags( $str ) ); }
function sanitize_email( $email ) {
	return filter_var( $email, FILTER_SANITIZE_EMAIL ); }
function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL ); }
function absint( $maybeint ) {
	return abs( (int) $maybeint ); }
function apply_filters( $hook_name, $value ) {
	return $value; }
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once ABSPATH . 'includes/admin/class-wp-mcp-ai-admin-settings-base.php';

$defaults = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

echo "Checking all settings used in codebase have defaults...\n\n";

$missing = array();
foreach ( $settings_used as $setting ) {
	if ( ! array_key_exists( $setting, $defaults ) ) {
		$missing[] = $setting;
		echo "✗ MISSING: $setting\n";
	} else {
		echo "✓ $setting\n";
	}
}

echo "\n";
if ( empty( $missing ) ) {
	echo "SUCCESS: All settings have defaults!\n";
	exit( 0 );
} else {
	echo 'FAILURE: ' . count( $missing ) . " setting(s) missing from defaults\n";
	exit( 1 );
}
