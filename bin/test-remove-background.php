#!/usr/bin/env php
<?php
/**
 * Manual test script for remove background tool.
 *
 * Usage: php bin/test-remove-background.php
 *
 * This script tests the remove background tool integration without requiring
 * WordPress test framework to be installed.
 *
 * @package WP_MCP_AI
 */

// Simulate WordPress environment for basic testing.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_MCP_AI_PATH', dirname( __DIR__ ) . '/' );

// Basic test framework.
class SimpleTest {
	private $passed = 0;
	private $failed = 0;
	private $tests  = array();

	public function assert_true( $condition, $message ) {
		if ( $condition ) {
			$this->passed++;
			$this->tests[] = array( 'status' => 'PASS', 'message' => $message );
			echo "✓ {$message}\n";
		} else {
			$this->failed++;
			$this->tests[] = array( 'status' => 'FAIL', 'message' => $message );
			echo "✗ {$message}\n";
		}
	}

	public function assert_false( $condition, $message ) {
		$this->assert_true( ! $condition, $message );
	}

	public function assert_equals( $expected, $actual, $message ) {
		if ( $expected === $actual ) {
			$this->passed++;
			$this->tests[] = array( 'status' => 'PASS', 'message' => $message );
			echo "✓ {$message}\n";
		} else {
			$this->failed++;
			$this->tests[] = array( 'status' => 'FAIL', 'message' => "{$message} (expected: {$expected}, got: {$actual})" );
			echo "✗ {$message} (expected: {$expected}, got: {$actual})\n";
		}
	}

	public function summary() {
		echo "\n" . str_repeat( '=', 60 ) . "\n";
		echo "Test Summary\n";
		echo str_repeat( '=', 60 ) . "\n";
		echo "Total: " . ( $this->passed + $this->failed ) . "\n";
		echo "Passed: {$this->passed}\n";
		echo "Failed: {$this->failed}\n";
		echo str_repeat( '=', 60 ) . "\n";

		return $this->failed === 0 ? 0 : 1;
	}
}

echo "Remove Background Tool - Integration Test\n";
echo str_repeat( '=', 60 ) . "\n\n";

$test = new SimpleTest();

// Test 1: Check if tool file exists.
$tool_file = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-remove-background.php';
$test->assert_true( file_exists( $tool_file ), 'Tool class file exists' );

// Test 2: Check if tool class is defined in file.
if ( file_exists( $tool_file ) ) {
	$tool_content = file_get_contents( $tool_file );
	$test->assert_true(
		strpos( $tool_content, 'class WP_MCP_AI_Tool_Remove_Background' ) !== false,
		'Tool class is defined in file'
	);
}

// Test 3: Check if settings file was updated.
$settings_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings-base.php';
$settings_content = file_get_contents( $settings_file );
$test->assert_true(
	strpos( $settings_content, 'removebg_api_key' ) !== false,
	'Settings file contains removebg_api_key'
);

// Test 4: Check if integrations section was updated.
$integrations_file = WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-integrations.php';
$integrations_content = file_get_contents( $integrations_file );
$test->assert_true(
	strpos( $integrations_content, 'removebg_api_key' ) !== false,
	'Integrations section contains removebg_api_key field'
);
$test->assert_true(
	strpos( $integrations_content, 'https://www.remove.bg/api' ) !== false,
	'Integrations section has link to remove.bg API'
);

// Test 5: Check if tool registry was updated.
$registry_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
$registry_content = file_get_contents( $registry_file );
$test->assert_true(
	strpos( $registry_content, 'WP_MCP_AI_Tool_Remove_Background' ) !== false,
	'Tool registry contains remove background tool'
);
$test->assert_true(
	strpos( $registry_content, "'remove_background'" ) !== false,
	'Tool registry has remove_background slug in group map'
);

// Test 6: Check if test file exists.
$test_file = WP_MCP_AI_PATH . 'tests/test-remove-background-tool.php';
$test->assert_true( file_exists( $test_file ), 'Test file exists' );

// Test 7: Check if test file has proper structure.
if ( file_exists( $test_file ) ) {
	$test_content = file_get_contents( $test_file );
	$test->assert_true(
		strpos( $test_content, 'class Test_Remove_Background_Tool' ) !== false,
		'Test file has test class'
	);
	$test->assert_true(
		strpos( $test_content, 'test_tool_registered' ) !== false,
		'Test file has registration test'
	);
	$test->assert_true(
		strpos( $test_content, 'test_removebg_api_key_setting_exists' ) !== false,
		'Test file has settings test'
	);
}

// Test 8: Check Python availability.
$python_available = false;
$python_version   = '';
$python_cmd       = '';

foreach ( array( 'python3', 'python' ) as $cmd ) {
	$output      = array();
	$return_code = 0;
	exec( "which {$cmd} 2>&1", $output, $return_code );

	if ( 0 === $return_code && ! empty( $output[0] ) ) {
		$python_cmd = $cmd;
		$output     = array();
		exec( "{$cmd} --version 2>&1", $output, $return_code );
		if ( ! empty( $output[0] ) ) {
			$python_version = $output[0];
			$python_available = true;
			break;
		}
	}
}

$test->assert_true( $python_available, "Python is available ({$python_version})" );

// Test 9: Check if rembg is installed.
if ( $python_available ) {
	$output      = array();
	$return_code = 0;
	exec( "{$python_cmd} -c 'import rembg; print(\"installed\")' 2>&1", $output, $return_code );

	$rembg_installed = ( 0 === $return_code && ! empty( $output[0] ) && 'installed' === $output[0] );

	if ( $rembg_installed ) {
		echo "✓ rembg library is installed (free mode available)\n";
	} else {
		echo "ℹ rembg library not installed (only paid mode available)\n";
		echo "  To install: pip3 install rembg pillow\n";
	}
}

// Test 10: Verify helper function is defined.
$helper_file = WP_MCP_AI_PATH . 'includes/tools/remove-background.php';
if ( file_exists( $helper_file ) ) {
	$helper_content = file_get_contents( $helper_file );
	$test->assert_true(
		strpos( $helper_content, 'function wp_mcp_ai_remove_image_background' ) !== false,
		'Helper function is defined (backwards compatibility)'
	);
}

// Print summary.
echo "\n";
$exit_code = $test->summary();

// Additional information.
echo "\n" . str_repeat( '=', 60 ) . "\n";
echo "Additional Information\n";
echo str_repeat( '=', 60 ) . "\n";
echo "Tool supports two modes:\n";
echo "  1. FREE: Python rembg library (requires: pip3 install rembg)\n";
echo "  2. PAID: remove.bg API (requires API key in settings)\n";
echo "\nMethod parameter options:\n";
echo "  - auto: Try free first, fallback to paid (default)\n";
echo "  - free: Use only free method\n";
echo "  - paid: Use only paid method\n";
echo "\nTo configure API key:\n";
echo "  WP Admin → Settings → WP oOS → Tools → External Tools\n";
echo "  Add your remove.bg API key from https://www.remove.bg/api\n";
echo str_repeat( '=', 60 ) . "\n";

exit( $exit_code );
