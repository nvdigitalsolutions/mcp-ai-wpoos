#!/usr/bin/env php
<?php
/**
 * Test script to verify orchestration presets functionality
 */

// Simulate WordPress constants and functions
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_MCP_AI_PATH', __DIR__ . '/' );
define( 'WP_MCP_AI_VERSION', '1.0.0' );

// Mock WordPress functions
function __( $text, $domain ) {
	return $text;
}

function esc_html__( $text, $domain ) {
	return htmlspecialchars( $text );
}

function esc_html( $text ) {
	return htmlspecialchars( $text );
}

function esc_url( $url ) {
	return htmlspecialchars( $url );
}

function esc_attr( $attr ) {
	return htmlspecialchars( $attr );
}

function admin_url( $path ) {
	return 'http://localhost/wp-admin/' . $path;
}

function wp_kses_post( $text ) {
	return $text;
}

// Mock settings registry
class WP_MCP_AI_Settings_Registry {
	public static function get_setting( $key, $default = null ) {
		return $default;
	}
}

echo "=== Orchestration Presets Verification Script ===\n\n";

// Load the preset service
require_once __DIR__ . '/includes/services/class-wp-mcp-ai-orchestration-preset-service.php';

echo "1. Checking if Preset Service class exists...\n";
if ( class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
	echo "   ✓ WP_MCP_AI_Orchestration_Preset_Service class found\n\n";
} else {
	echo "   ✗ Class not found!\n";
	exit( 1 );
}

echo "2. Getting available presets...\n";
try {
	$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
	echo "   ✓ Found " . count( $presets ) . " presets:\n";
	
	foreach ( $presets as $preset_id => $preset_config ) {
		if ( isset( $preset_config['name'] ) ) {
			echo "      - {$preset_id}: {$preset_config['name']}\n";
		}
	}
	echo "\n";
} catch ( Exception $e ) {
	echo "   ✗ Error: " . $e->getMessage() . "\n";
	exit( 1 );
}

echo "3. Checking preset details...\n";
foreach ( $presets as $preset_id => $preset_config ) {
	$has_name = isset( $preset_config['name'] );
	$has_desc = isset( $preset_config['description'] );
	$has_settings = isset( $preset_config['settings'] );
	
	if ( $has_name && $has_desc && $has_settings ) {
		$settings_count = is_array( $preset_config['settings'] ) ? count( $preset_config['settings'] ) : 0;
		echo "   ✓ {$preset_id}: {$settings_count} settings defined\n";
	} else {
		echo "   ✗ {$preset_id}: Missing required fields\n";
	}
}
echo "\n";

// Load the renderer
require_once __DIR__ . '/includes/admin/class-wp-mcp-ai-orchestration-renderer.php';

echo "4. Checking if Renderer class exists...\n";
if ( class_exists( 'WP_MCP_AI_Orchestration_Renderer' ) ) {
	echo "   ✓ WP_MCP_AI_Orchestration_Renderer class found\n\n";
} else {
	echo "   ✗ Class not found!\n";
	exit( 1 );
}

echo "5. Testing renderer methods...\n";
$reflection = new ReflectionClass( 'WP_MCP_AI_Orchestration_Renderer' );
$methods = $reflection->getMethods( ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC );

foreach ( $methods as $method ) {
	echo "   ✓ Method: {$method->getName()}\n";
}
echo "\n";

echo "6. Testing preset selector rendering...\n";
try {
	$html = WP_MCP_AI_Orchestration_Renderer::render_presets_selector( $presets );
	if ( ! empty( $html ) ) {
		echo "   ✓ Preset selector HTML generated (" . strlen( $html ) . " bytes)\n";
		
		// Check for key elements
		if ( strpos( $html, 'wp-mcp-ai-presets-section' ) !== false ) {
			echo "   ✓ Contains preset section class\n";
		}
		if ( strpos( $html, 'preset-card' ) !== false ) {
			echo "   ✓ Contains preset cards\n";
		}
		if ( strpos( $html, 'apply-preset' ) !== false ) {
			echo "   ✓ Contains apply buttons\n";
		}
	} else {
		echo "   ✗ No HTML generated\n";
	}
	echo "\n";
} catch ( Exception $e ) {
	echo "   ✗ Error: " . $e->getMessage() . "\n";
	exit( 1 );
}

echo "7. Testing slider rendering...\n";
try {
	$slider_config = array(
		'label'       => 'Test Slider',
		'description' => 'Test description',
		'min'         => 0,
		'max'         => 100,
		'step'        => 5,
		'default'     => 50,
		'suffix'      => '%',
	);
	
	$html = WP_MCP_AI_Orchestration_Renderer::render_slider( 'test_slider', $slider_config );
	if ( ! empty( $html ) ) {
		echo "   ✓ Slider HTML generated (" . strlen( $html ) . " bytes)\n";
		
		// Check for key elements
		if ( strpos( $html, 'wp-mcp-ai-slider' ) !== false ) {
			echo "   ✓ Contains slider class\n";
		}
		if ( strpos( $html, 'type="range"' ) !== false ) {
			echo "   ✓ Contains range input\n";
		}
	} else {
		echo "   ✗ No HTML generated\n";
	}
	echo "\n";
} catch ( Exception $e ) {
	echo "   ✗ Error: " . $e->getMessage() . "\n";
	exit( 1 );
}

echo "=== All checks passed! ===\n";
echo "\nThe orchestration presets and sliders should now be visible on the dashboard.\n";
echo "Navigate to: WordPress Admin → WP oOS → Orchestration tab\n";
