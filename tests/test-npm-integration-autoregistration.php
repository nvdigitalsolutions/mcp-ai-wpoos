<?php
/**
 * Test for NPM Integration Filters Auto-Registration Fix
 *
 * Tests that npm-integration-filters.php defers auto-registration to the init hook
 * to prevent fatal errors during plugin activation when proc_open is disabled.
 *
 * @package WP_MCP_AI
 */

/**
 * Test NPM integration filters auto-registration.
 */
class Test_NPM_Integration_Autoregistration extends WP_UnitTestCase {

	/**
	 * Test that auto-registration is deferred to init hook.
	 *
	 * This test verifies that the auto-registration logic is not executed
	 * at file load time, but instead hooked to the WordPress init action.
	 */
	public function test_autoregistration_deferred_to_init_hook() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Check that the auto-registration function exists.
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_auto_register_npm_filters' ),
			'Auto-registration function should exist'
		);

		// Check that the function is hooked to init.
		$this->assertNotFalse(
			has_action( 'init', 'wp_mcp_ai_auto_register_npm_filters' ),
			'Auto-registration should be hooked to init action'
		);

		// Verify the priority is 20.
		$priority = has_action( 'init', 'wp_mcp_ai_auto_register_npm_filters' );
		$this->assertEquals(
			20,
			$priority,
			'Auto-registration should use priority 20'
		);
	}

	/**
	 * Test that npm-integration-filters.php can be loaded safely.
	 *
	 * This test simulates what happens during plugin activation by checking
	 * that the file can be parsed and loaded without triggering Process
	 * instantiation at the top level.
	 */
	public function test_file_can_be_loaded_safely() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$file = WP_MCP_AI_PRO_PATH . 'includes/npm-integration-filters.php';
		$this->assertFileExists( $file, 'npm-integration-filters.php should exist' );

		// Read file content to verify structure.
		$content = file_get_contents( $file );

		// Verify that the add_action call is present.
		$this->assertStringContainsString(
			"add_action( 'init', 'wp_mcp_ai_auto_register_npm_filters', 20 )",
			$content,
			'File should hook auto-registration to init action'
		);

		// Verify that the auto-registration function is defined.
		$this->assertStringContainsString(
			'function wp_mcp_ai_auto_register_npm_filters()',
			$content,
			'Auto-registration function should be defined'
		);
	}

	/**
	 * Test that auto-registration doesn't execute when proc_open is unavailable.
	 *
	 * This test verifies the graceful degradation behavior.
	 */
	public function test_autoregistration_handles_missing_proc_open() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// We can't actually disable proc_open in a running test,
		// but we can verify that the function checks for availability.
		$file    = WP_MCP_AI_PRO_PATH . 'includes/npm-integration-filters.php';
		$content = file_get_contents( $file );

		// Verify that wp_mcp_ai_is_nodejs_available() is used (which checks proc_open).
		$this->assertStringContainsString(
			'wp_mcp_ai_is_nodejs_available()',
			$content,
			'Auto-registration should check Node.js availability'
		);

		// Verify Process Service usage (which has proc_open checks).
		$this->assertStringContainsString(
			'WP_MCP_AI_Process_Service::get_instance()',
			$content,
			'Node.js availability check should use Process Service'
		);
	}

	/**
	 * Test that filters can be registered manually.
	 *
	 * Verifies that the registration functions are available for manual use
	 * if auto-registration is disabled.
	 */
	public function test_manual_registration_functions_exist() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Check that registration functions exist.
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_register_prettier_filters' ),
			'Prettier registration function should exist'
		);

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_register_mjml_filters' ),
			'MJML registration function should exist'
		);

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_register_ffmpeg_filters' ),
			'FFmpeg registration function should exist'
		);

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_register_all_npm_filters' ),
			'Register all filters function should exist'
		);
	}
}
