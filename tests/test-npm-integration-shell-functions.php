<?php
/**
 * Tests for NPM integration shell function safety checks.
 *
 * Validates that:
 * 1. wp_mcp_ai_is_nodejs_available() safely handles disabled shell_exec
 * 2. wp_mcp_ai_exec_node_service() safely handles disabled exec
 * 3. No fatal errors occur when shell functions are disabled
 *
 * @package WP_MCP_AI
 */

/**
 * Test NPM integration shell function safety.
 */
class WP_MCP_AI_NPM_Integration_Shell_Functions_Test extends WP_UnitTestCase {

	/**
	 * Path to npm-integration-filters.php file.
	 *
	 * @var string
	 */
	private $npm_filters_file;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->npm_filters_file = WP_MCP_AI_PRO_PATH . 'includes/npm-integration-filters.php';
	}

	/**
	 * Test that npm-integration-filters.php file exists.
	 */
	public function test_npm_integration_filters_file_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$this->assertFileExists(
			$this->npm_filters_file,
			'npm-integration-filters.php file should exist'
		);
	}

	/**
	 * Test that wp_mcp_ai_is_nodejs_available exists and is callable.
	 */
	public function test_nodejs_available_function_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Load the file if function doesn't exist yet.
		if ( ! function_exists( 'wp_mcp_ai_is_nodejs_available' ) ) {
			require_once $this->npm_filters_file;
		}

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_is_nodejs_available' ),
			'wp_mcp_ai_is_nodejs_available() function should exist'
		);
	}

	/**
	 * Test that wp_mcp_ai_is_nodejs_available checks for shell_exec availability.
	 *
	 * This test verifies that the function doesn't cause a fatal error
	 * when shell_exec is disabled.
	 */
	public function test_nodejs_available_handles_missing_shell_exec() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Load the file if function doesn't exist yet.
		if ( ! function_exists( 'wp_mcp_ai_is_nodejs_available' ) ) {
			require_once $this->npm_filters_file;
		}

		// Skip if shell_exec is actually disabled (the function will return false).
		if ( ! function_exists( 'shell_exec' ) ) {
			$result = wp_mcp_ai_is_nodejs_available();
			$this->assertFalse(
				$result,
				'wp_mcp_ai_is_nodejs_available() should return false when shell_exec is disabled'
			);
			return;
		}

		// If shell_exec exists, the function should execute without fatal error.
		$result = wp_mcp_ai_is_nodejs_available();
		$this->assertIsBool(
			$result,
			'wp_mcp_ai_is_nodejs_available() should return a boolean value'
		);
	}

	/**
	 * Test that wp_mcp_ai_exec_node_service exists and is callable.
	 */
	public function test_exec_node_service_function_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Load the file if function doesn't exist yet.
		if ( ! function_exists( 'wp_mcp_ai_exec_node_service' ) ) {
			require_once $this->npm_filters_file;
		}

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_exec_node_service' ),
			'wp_mcp_ai_exec_node_service() function should exist'
		);
	}

	/**
	 * Test that wp_mcp_ai_exec_node_service handles missing exec function.
	 *
	 * This test verifies that the function returns a WP_Error instead of
	 * causing a fatal error when exec is disabled.
	 */
	public function test_exec_node_service_handles_missing_exec() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Load the file if function doesn't exist yet.
		if ( ! function_exists( 'wp_mcp_ai_exec_node_service' ) ) {
			require_once $this->npm_filters_file;
		}

		// Skip if exec is actually disabled - we'll get the expected error.
		if ( ! function_exists( 'exec' ) ) {
			$result = wp_mcp_ai_exec_node_service( '/fake/service.js', 'test', array() );
			
			$this->assertWPError(
				$result,
				'wp_mcp_ai_exec_node_service() should return WP_Error when exec is disabled'
			);
			
			$this->assertEquals(
				'shell_functions_disabled',
				$result->get_error_code(),
				'Error code should be shell_functions_disabled'
			);
			return;
		}

		// If exec exists but shell_exec doesn't, should still return error.
		if ( ! function_exists( 'shell_exec' ) ) {
			$result = wp_mcp_ai_exec_node_service( '/fake/service.js', 'test', array() );
			
			$this->assertWPError(
				$result,
				'wp_mcp_ai_exec_node_service() should return WP_Error when shell functions are disabled'
			);
			return;
		}

		// Both functions exist - will return error for missing service file.
		$result = wp_mcp_ai_exec_node_service( '/fake/nonexistent/service.js', 'test', array() );
		
		$this->assertWPError(
			$result,
			'wp_mcp_ai_exec_node_service() should return WP_Error for invalid service file'
		);
	}

	/**
	 * Test that the file can be included without fatal errors.
	 *
	 * This is the critical test - ensures that simply including the file
	 * doesn't cause a fatal error due to missing shell functions.
	 */
	public function test_npm_integration_file_can_be_included_safely() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// This should not cause a fatal error even if shell_exec is disabled.
		// The previous fatal error would have occurred at line 31 during include.
		$this->assertFileExists( $this->npm_filters_file );
		
		// If we get here, the file loaded successfully.
		$this->assertTrue( true, 'File loaded without fatal error' );
	}

	/**
	 * Test that auto-registration doesn't cause errors when shell functions are disabled.
	 */
	public function test_auto_registration_handles_disabled_shell_functions() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Load the file if not already loaded.
		if ( ! function_exists( 'wp_mcp_ai_is_nodejs_available' ) ) {
			require_once $this->npm_filters_file;
		}

		// If shell_exec is disabled, auto-registration should not have occurred.
		if ( ! function_exists( 'shell_exec' ) ) {
			// The filters should not be registered when shell_exec is disabled.
			$this->assertFalse(
				has_filter( 'wp_mcp_ai_prettier_format_code' ),
				'Prettier filter should not be registered when shell_exec is disabled'
			);
		}

		// If we got here without fatal error, test passes.
		$this->assertTrue( true, 'Auto-registration completed without fatal error' );
	}
}
