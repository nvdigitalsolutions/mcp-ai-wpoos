<?php
/**
 * Manual test script for preset settings preview
 *
 * Run: php tests/manual-test-preset-preview.php
 */

// Bootstrap WordPress.
require_once dirname( __DIR__ ) . '/../../wp-load.php';

// Load required classes.
require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-orchestration-preset-service.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-orchestration-renderer.php';

echo "Testing Preset Settings Preview\n";
echo "================================\n\n";

// Get presets.
$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();

echo "Found " . count( $presets ) . " presets\n\n";

// Test each preset.
foreach ( $presets as $preset_id => $preset_config ) {
	echo "Preset: {$preset_config['name']} ($preset_id)\n";
	echo str_repeat( '-', 50 ) . "\n";
	
	if ( ! empty( $preset_config['settings'] ) ) {
		$settings = $preset_config['settings'];
		
		if ( isset( $settings['high_tier_max_tokens'] ) ) {
			echo "✓ Context Window: " . number_format( $settings['high_tier_max_tokens'] ) . " tokens\n";
		} else {
			echo "✗ Context Window: Not set\n";
		}
		
		if ( isset( $settings['per_call_token_limit'] ) ) {
			echo "✓ Per-Call Limit: " . number_format( $settings['per_call_token_limit'] ) . " tokens\n";
		} else {
			echo "✗ Per-Call Limit: Not set\n";
		}
		
		if ( isset( $settings['memory_critical_threshold'] ) ) {
			echo "✓ Memory Threshold: {$settings['memory_critical_threshold']}%\n";
		} else {
			echo "✗ Memory Threshold: Not set\n";
		}
	} else {
		echo "✓ No settings (custom preset)\n";
	}
	
	echo "\n";
}

echo "Testing HTML Rendering\n";
echo "======================\n\n";

// Render the preset selector.
$html = WP_MCP_AI_Orchestration_Renderer::render_presets_selector( $presets );

// Check for expected elements.
$checks = array(
	'preset-settings-preview'  => 'Settings preview container',
	'preset-setting-item'      => 'Setting items',
	'preset-setting-label'     => 'Setting labels',
	'preset-setting-value'     => 'Setting values',
	'dashicons-chart-bar'      => 'Chart icon',
	'dashicons-admin-tools'    => 'Tools icon',
	'dashicons-warning'        => 'Warning icon',
	'32,000'                   => 'Balanced preset tokens',
	'16,000'                   => 'Conservative preset tokens',
	'64,000'                   => 'Performance preset tokens',
);

foreach ( $checks as $needle => $description ) {
	if ( strpos( $html, $needle ) !== false ) {
		echo "✓ $description found\n";
	} else {
		echo "✗ $description NOT found\n";
	}
}

echo "\nTest complete!\n";
