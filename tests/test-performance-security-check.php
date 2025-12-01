<?php
/**
 * Test Performance Section Security Checks.
 *
 * Validates that security checks can run without errors.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Performance Section security checks.
 */
class Test_Performance_Security_Check extends WP_UnitTestCase {

	/**
	 * Test that security checks can be instantiated.
	 */
	public function test_security_check_methods_exist() {
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$performance_section = new WP_MCP_AI_Section_Performance();

		$this->assertTrue( method_exists( $performance_section, 'ajax_run_test' ) );
		$this->assertTrue( method_exists( $performance_section, 'run_performance_test_programmatically' ) );
		$this->assertTrue( method_exists( $performance_section, 'run_lightweight_check' ) );
	}

	/**
	 * Test security check methods using reflection.
	 */
	public function test_security_check_methods_callable() {
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$performance_section = new WP_MCP_AI_Section_Performance();
		$reflection          = new ReflectionClass( $performance_section );

		// Test that check methods exist.
		$this->assertTrue( $reflection->hasMethod( 'check_file_permissions' ) );
		$this->assertTrue( $reflection->hasMethod( 'check_https' ) );
		$this->assertTrue( $reflection->hasMethod( 'check_api_keys_configured' ) );
	}

	/**
	 * Test that security checks return proper structure.
	 */
	public function test_security_checks_return_structure() {
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$performance_section = new WP_MCP_AI_Section_Performance();
		$reflection          = new ReflectionClass( $performance_section );

		// Call check_file_permissions using reflection.
		$method = $reflection->getMethod( 'check_file_permissions' );
		$method->setAccessible( true );
		$result = $method->invoke( $performance_section );

		// Verify structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertContains( $result['status'], array( 'pass', 'fail', 'warning' ) );
	}

	/**
	 * Test that run_lightweight_check works for security type.
	 */
	public function test_run_lightweight_check_security() {
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$performance_section = new WP_MCP_AI_Section_Performance();
		$reflection          = new ReflectionClass( $performance_section );

		// Call run_lightweight_check using reflection.
		$method = $reflection->getMethod( 'run_lightweight_check' );
		$method->setAccessible( true );
		$result = $method->invoke( $performance_section, 'security' );

		// Verify result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'output', $result );

		// Result could be success or failure, but should have valid structure.
		$this->assertIsBool( $result['success'] );
		$this->assertIsString( $result['message'] );
		$this->assertIsString( $result['output'] );
	}

	/**
	 * Test that AJAX handlers are registered.
	 */
	public function test_ajax_handlers_registered() {
		global $wp_filter;

		require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		// Instantiate to register hooks.
		new WP_MCP_AI_Section_Performance();

		// Check if AJAX handlers are registered.
		$this->assertTrue( isset( $wp_filter['wp_ajax_wp_mcp_ai_run_performance_test'] ) );
		$this->assertTrue( isset( $wp_filter['wp_ajax_wp_mcp_ai_get_performance_metrics'] ) );
		$this->assertTrue( isset( $wp_filter['wp_ajax_wp_mcp_ai_export_test_results'] ) );
	}

	/**
	 * Test error handling in ajax_run_test.
	 */
	public function test_ajax_run_test_error_handling() {
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$performance_section = new WP_MCP_AI_Section_Performance();

		// The method should have a try-catch block.
		$reflection = new ReflectionClass( $performance_section );
		$method     = $reflection->getMethod( 'ajax_run_test' );

		// Get the method source code to verify try-catch exists.
		$filename   = $reflection->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();
		$length     = $end_line - $start_line;

		$source = file( $filename );
		$body   = implode( '', array_slice( $source, $start_line, $length ) );

		// Verify try-catch exists.
		$this->assertStringContainsString( 'try', $body );
		$this->assertStringContainsString( 'catch', $body );
		$this->assertStringContainsString( 'Exception', $body );
	}
}
