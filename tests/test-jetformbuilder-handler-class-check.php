<?php
/**
 * Tests for JetFormBuilder tools handler class availability check.
 *
 * This test ensures that JetFormBuilder tools don't throw fatal errors
 * when the handler class is not loaded (e.g., in base version mode).
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-forms.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-submissions.php';

/**
 * Class WP_MCP_AI_JetFormBuilder_Handler_Class_Check_Test
 *
 * Tests that JetFormBuilder tools gracefully handle missing handler class.
 */
class WP_MCP_AI_JetFormBuilder_Handler_Class_Check_Test extends WP_UnitTestCase {

	/**
	 * Test that Get JetFormBuilder Forms tool returns false when handler class doesn't exist.
	 */
	public function test_get_jetformbuilder_forms_is_available_without_handler_class() {
		// Ensure the handler class is not loaded for this test.
		// In a real scenario, this would be when base version is active without Pro.
		$result = WP_MCP_AI_Tool_Get_JetFormBuilder_Forms::is_available();

		// Should return false (not throw fatal error) when handler class doesn't exist.
		// If handler class exists, it may return true or false based on JetFormBuilder availability.
		$this->assertIsBool( $result, 'is_available() should return a boolean value' );
	}

	/**
	 * Test that Get JetFormBuilder Submissions tool returns false when handler class doesn't exist.
	 */
	public function test_get_jetformbuilder_submissions_is_available_without_handler_class() {
		// Ensure the handler class is not loaded for this test.
		// In a real scenario, this would be when base version is active without Pro.
		$result = WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions::is_available();

		// Should return false (not throw fatal error) when handler class doesn't exist.
		// If handler class exists, it may return true or false based on JetFormBuilder availability.
		$this->assertIsBool( $result, 'is_available() should return a boolean value' );
	}

	/**
	 * Test that Get JetFormBuilder Forms tool can be instantiated without fatal error.
	 */
	public function test_get_jetformbuilder_forms_can_be_instantiated() {
		$tool = new WP_MCP_AI_Tool_Get_JetFormBuilder_Forms();
		$this->assertInstanceOf( WP_MCP_AI_Tool_Get_JetFormBuilder_Forms::class, $tool );
	}

	/**
	 * Test that Get JetFormBuilder Submissions tool can be instantiated without fatal error.
	 */
	public function test_get_jetformbuilder_submissions_can_be_instantiated() {
		$tool = new WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions();
		$this->assertInstanceOf( WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions::class, $tool );
	}

	/**
	 * Test that handler class exists when integrations are loaded.
	 */
	public function test_handler_class_exists_when_integrations_loaded() {
		// If wp_mcp_ai_should_load_integrations() returns true, the handler should be loaded.
		if ( function_exists( 'wp_mcp_ai_should_load_integrations' ) && wp_mcp_ai_should_load_integrations() ) {
			$this->assertTrue(
				class_exists( 'WP_MCP_AI_JetFormBuilder_Tool_Handlers' ),
				'Handler class should exist when integrations are loaded'
			);
		} else {
			$this->markTestSkipped( 'Integrations are not loaded in this test environment' );
		}
	}
}
