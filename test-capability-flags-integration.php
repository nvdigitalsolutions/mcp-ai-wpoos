#!/usr/bin/env php
<?php
/**
 * Integration test for capability flags with actual tools.
 *
 * This script tests the capability flags system with actual registered tools
 * to ensure everything works end-to-end.
 */

// Bootstrap WordPress test environment if available.
if ( ! getenv( 'WP_TESTS_DIR' ) ) {
	echo "Note: WP_TESTS_DIR not set. Running in standalone mode.\n";
	echo "For full integration testing, set up WordPress test environment.\n\n";
}

// Set up basic constants.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
	define( 'WP_MCP_AI_PATH', __DIR__ . '/' );
}

// Mock WordPress functions needed by the registry.
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain ) {
		return htmlspecialchars( $text );
	}
}

echo "=== Capability Flags Integration Test ===\n\n";

// Load required files.
require_once __DIR__ . '/includes/interfaces/interface-wp-mcp-ai-tool-interface.php';
require_once __DIR__ . '/includes/class-wp-mcp-ai-tool-registry.php';

echo "1. Testing tool registry initialization...\n";
$registry = WP_MCP_AI_Tool_Registry::get_instance();

if ( ! $registry ) {
	echo "   ✗ Failed to get registry instance\n";
	exit( 1 );
}
echo "   ✓ Registry instance created\n\n";

echo "2. Testing methods exist...\n";
$methods = array(
	'get_tool_capability_flags',
	'get_all_tool_capability_flags',
	'get_tools_by_capability_flag',
);

foreach ( $methods as $method ) {
	if ( ! method_exists( $registry, $method ) ) {
		echo "   ✗ Method missing: $method\n";
		exit( 1 );
	}
	echo "   ✓ Method exists: $method\n";
}
echo "\n";

echo "3. Testing with mock tool...\n";

// Create a mock tool that implements capability flags.
class Mock_Tool_With_Flags implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public function get_slug() {
		return 'mock_tool_test';
	}

	public function get_name() {
		return 'Mock Tool';
	}

	public function get_description() {
		return 'A mock tool for testing';
	}

	public function get_parameters_schema() {
		return array( 'type' => 'object' );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'success' => true );
	}

	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'test-flag' );
	}
}

// Register the mock tool.
$mock_tool  = new Mock_Tool_With_Flags();
$registered = $registry->register_tool( $mock_tool );

if ( ! $registered ) {
	echo "   ✗ Failed to register mock tool\n";
	exit( 1 );
}
echo "   ✓ Mock tool registered successfully\n";

// Test get_tool_capability_flags.
$flags = $registry->get_tool_capability_flags( 'mock_tool_test' );
if ( ! is_array( $flags ) ) {
	echo "   ✗ get_tool_capability_flags did not return array\n";
	exit( 1 );
}
if ( count( $flags ) !== 3 ) {
	echo '   ✗ Expected 3 flags, got ' . count( $flags ) . "\n";
	exit( 1 );
}
if ( ! in_array( 'read-only', $flags, true ) ) {
	echo "   ✗ Expected 'read-only' flag not found\n";
	exit( 1 );
}
echo "   ✓ get_tool_capability_flags returned correct flags\n";

// Test get_all_tool_capability_flags.
$all_flags = $registry->get_all_tool_capability_flags();
if ( ! is_array( $all_flags ) ) {
	echo "   ✗ get_all_tool_capability_flags did not return array\n";
	exit( 1 );
}
if ( ! isset( $all_flags['mock_tool_test'] ) ) {
	echo "   ✗ Mock tool not found in all flags map\n";
	exit( 1 );
}
echo "   ✓ get_all_tool_capability_flags includes mock tool\n";

// Test get_tools_by_capability_flag.
$readonly_tools = $registry->get_tools_by_capability_flag( 'read-only' );
if ( ! is_array( $readonly_tools ) ) {
	echo "   ✗ get_tools_by_capability_flag did not return array\n";
	exit( 1 );
}
$found_mock = false;
foreach ( $readonly_tools as $tool ) {
	if ( $tool->get_slug() === 'mock_tool_test' ) {
		$found_mock = true;
		break;
	}
}
if ( ! $found_mock ) {
	echo "   ✗ Mock tool not found in read-only tools\n";
	exit( 1 );
}
echo "   ✓ get_tools_by_capability_flag correctly filters tools\n\n";

echo "4. Testing edge cases...\n";

// Test with non-existent tool.
$empty_flags = $registry->get_tool_capability_flags( 'nonexistent_tool_xyz' );
if ( ! is_array( $empty_flags ) || ! empty( $empty_flags ) ) {
	echo "   ✗ Non-existent tool should return empty array\n";
	exit( 1 );
}
echo "   ✓ Non-existent tool returns empty array\n";

// Test with non-existent flag.
$no_tools = $registry->get_tools_by_capability_flag( 'nonexistent-flag-xyz' );
if ( ! is_array( $no_tools ) || ! empty( $no_tools ) ) {
	echo "   ✗ Non-existent flag should return empty array\n";
	exit( 1 );
}
echo "   ✓ Non-existent flag returns empty array\n";

// Create a tool without capability flags interface.
class Mock_Tool_Without_Flags implements WP_MCP_AI_Tool_Interface {
	public function get_slug() {
		return 'mock_tool_no_flags';
	}

	public function get_name() {
		return 'Mock Tool No Flags';
	}

	public function get_description() {
		return 'A mock tool without flags';
	}

	public function get_parameters_schema() {
		return array( 'type' => 'object' );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'success' => true );
	}
}

$mock_no_flags = new Mock_Tool_Without_Flags();
$registry->register_tool( $mock_no_flags );

$no_flags = $registry->get_tool_capability_flags( 'mock_tool_no_flags' );
if ( ! is_array( $no_flags ) || ! empty( $no_flags ) ) {
	echo "   ✗ Tool without interface should return empty array\n";
	exit( 1 );
}
echo "   ✓ Tool without interface returns empty array\n\n";

echo "=== All Integration Tests Passed! ===\n\n";
echo "Summary:\n";
echo "  ✓ Registry methods implemented correctly\n";
echo "  ✓ Capability flags retrieval works\n";
echo "  ✓ Tool filtering by flags works\n";
echo "  ✓ Edge cases handled properly\n";
echo "\nThe capability flags system is fully functional.\n";
