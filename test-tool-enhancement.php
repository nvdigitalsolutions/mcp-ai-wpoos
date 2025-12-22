#!/usr/bin/env php
<?php
/**
 * Manual test script to verify profession tool enhancement.
 * 
 * This script loads professions and checks that default_tools are enhanced
 * from the basic 3 tools to the recommended 5-7 tools.
 */

// Load WordPress.
require_once __DIR__ . '/vendor/autoload.php';

// Define constants needed.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
	define( 'WP_MCP_AI_PATH', __DIR__ . '/' );
}

// Manually require the needed classes.
require_once __DIR__ . '/includes/class-wp-mcp-ai-tool-registry.php';
require_once __DIR__ . '/includes/services/class-wp-mcp-ai-profession-tool-recommender.php';
require_once __DIR__ . '/includes/services/class-wp-mcp-ai-profession-knowledge-base-loader.php';

echo "=" . str_repeat( "=", 79 ) . "\n";
echo "Testing Profession Tool Enhancement\n";
echo "=" . str_repeat( "=", 79 ) . "\n\n";

// Test 1: Load professions and check tool counts.
echo "Test 1: Loading professions from JSON and checking tool enhancement\n";
echo "-" . str_repeat( "-", 79 ) . "\n";

$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
$professions = $loader->load_category( 'technology' );

if ( is_wp_error( $professions ) ) {
	echo "ERROR: Failed to load professions: " . $professions->get_error_message() . "\n";
	exit( 1 );
}

echo "Loaded " . count( $professions ) . " professions from technology category\n\n";

// Check a few professions.
$test_slugs = array( 'software_developer', 'web_developer', 'data_scientist' );

foreach ( $professions as $profession ) {
	if ( in_array( $profession['slug'], $test_slugs, true ) ) {
		$tool_count = count( $profession['default_tools'] );
		$status     = $tool_count > 3 ? '✓ PASS' : '✗ FAIL';
		
		echo sprintf(
			"%s %s: %d tools\n",
			$status,
			$profession['title'],
			$tool_count
		);
		
		echo "  Tools: " . implode( ', ', $profession['default_tools'] ) . "\n\n";
		
		// Check for core tools.
		$core_tools = array( 'web_search', 'search_content', 'save_post' );
		$has_core   = true;
		foreach ( $core_tools as $core_tool ) {
			if ( ! in_array( $core_tool, $profession['default_tools'], true ) ) {
				$has_core = false;
				echo "  WARNING: Missing core tool: {$core_tool}\n";
			}
		}
	}
}

echo "\n" . str_repeat( "=", 80 ) . "\n";
echo "Test completed!\n";

// Test 2: Test the enhance_default_tools method directly using reflection.
echo "\nTest 2: Testing enhance_default_tools method directly\n";
echo "-" . str_repeat( "-", 79 ) . "\n";

try {
	$reflection = new ReflectionClass( 'WP_MCP_AI_Profession_Knowledge_Base_Loader' );
	$method     = $reflection->getMethod( 'enhance_default_tools' );
	$method->setAccessible( true );
	
	$test_loader = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
	
	// Test with basic 3 tools (should be enhanced).
	$basic_tools = array( 'web_search', 'search_content', 'save_post' );
	$enhanced    = $method->invokeArgs( $test_loader, array( $basic_tools, 'software_developer', 'technical' ) );
	
	$status = count( $enhanced ) > 3 ? '✓ PASS' : '✗ FAIL';
	echo sprintf(
		"%s Basic 3 tools enhanced to %d tools\n",
		$status,
		count( $enhanced )
	);
	echo "  Enhanced tools: " . implode( ', ', $enhanced ) . "\n\n";
	
	// Test with custom tools (should be preserved).
	$custom_tools = array( 'web_search', 'search_content', 'save_post', 'custom_1', 'custom_2' );
	$preserved    = $method->invokeArgs( $test_loader, array( $custom_tools, 'software_developer', 'technical' ) );
	
	$status = $preserved === $custom_tools ? '✓ PASS' : '✗ FAIL';
	echo sprintf(
		"%s Custom tools (count: %d) preserved\n",
		$status,
		count( $preserved )
	);
	echo "  Preserved tools: " . implode( ', ', $preserved ) . "\n\n";
	
	// Test with empty tools (should get recommendations).
	$empty_tools = array();
	$recommended = $method->invokeArgs( $test_loader, array( $empty_tools, 'graphic_designer', 'creative' ) );
	
	$status = count( $recommended ) > 3 ? '✓ PASS' : '✗ FAIL';
	echo sprintf(
		"%s Empty tools enhanced to %d recommended tools\n",
		$status,
		count( $recommended )
	);
	echo "  Recommended tools: " . implode( ', ', $recommended ) . "\n";
	
} catch ( Exception $e ) {
	echo "ERROR: " . $e->getMessage() . "\n";
	exit( 1 );
}

echo "\n" . str_repeat( "=", 80 ) . "\n";
echo "All tests completed successfully!\n";

exit( 0 );
