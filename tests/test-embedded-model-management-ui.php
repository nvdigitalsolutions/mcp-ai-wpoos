<?php
/**
 * Test Embedded Model Management UI
 *
 * @package WP_MCP_AI
 */

/**
 * Test embedded model management UI rendering.
 */
class Test_Embedded_Model_Management_UI extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up admin user.
		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );
	}

	/**
	 * Test that the embedded model management UI renders.
	 */
	public function test_embedded_model_management_renders() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Check that the class exists.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Section_Providers' ) );

		// Create section instance.
		$section = new WP_MCP_AI_Section_Providers();

		// Check method exists.
		$this->assertTrue( method_exists( $section, 'render_embedded_model_management' ) );

		// Capture output.
		ob_start();
		$section->render_embedded_model_management( array() );
		$output = ob_get_clean();

		// Check that output contains expected elements.
		$this->assertStringContainsString( 'wp-mcp-ai-embedded-model-management', $output );
		$this->assertStringContainsString( 'data-nonce=', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-model-list', $output );
	}

	/**
	 * Test that available models are displayed.
	 */
	public function test_embedded_models_displayed() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Check that embedded client exists.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Embedded_Client' ) );

		// Create client instance.
		$client = new WP_MCP_AI_Embedded_Client();

		// Get available models.
		$available_models = $client->get_available_models();

		// Check that models exist.
		$this->assertNotEmpty( $available_models );
		$this->assertIsArray( $available_models );

		// Check model structure.
		foreach ( $available_models as $slug => $model ) {
			$this->assertArrayHasKey( 'name', $model );
			$this->assertArrayHasKey( 'description', $model );
			$this->assertArrayHasKey( 'size', $model );
			$this->assertArrayHasKey( 'license', $model );
		}
	}

	/**
	 * Test that the UI shows download buttons for non-downloaded models.
	 */
	public function test_download_button_shown_for_non_downloaded_models() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Create section instance.
		$section = new WP_MCP_AI_Section_Providers();

		// Capture output.
		ob_start();
		$section->render_embedded_model_management( array() );
		$output = ob_get_clean();

		// Check that output contains download buttons.
		$this->assertStringContainsString( 'wp-mcp-ai-download-model', $output );
		$this->assertStringContainsString( 'button-primary', $output );
	}

	/**
	 * Test that AJAX handler is initialized.
	 */
	public function test_ajax_handler_initialized() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Check that AJAX handler class exists.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Embedded_Model_Ajax' ) );

		// Check that AJAX actions are registered.
		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_download_embedded_model', array( 'WP_MCP_AI_Embedded_Model_Ajax', 'download_model' ) )
		);
		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_delete_embedded_model', array( 'WP_MCP_AI_Embedded_Model_Ajax', 'delete_model' ) )
		);
		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_list_embedded_models', array( 'WP_MCP_AI_Embedded_Model_Ajax', 'list_models' ) )
		);
	}

	/**
	 * Test that model management UI displays client-side model information.
	 */
	public function test_model_management_shows_client_side_info() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Create section instance.
		$section = new WP_MCP_AI_Section_Providers();

		// Capture output.
		ob_start();
		$section->render_embedded_model_management( array() );
		$output = ob_get_clean();

		// Check that output contains client-side model information.
		$this->assertStringContainsString( 'Client-Side Models', $output );
		$this->assertStringContainsString( 'WebGPU/WebAssembly', $output );
		$this->assertStringContainsString( 'Pro Feature', $output );
	}

	/**
	 * Test that usage instructions are shown for downloaded models.
	 */
	public function test_usage_instructions_for_downloaded_models() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Create section instance.
		$section = new WP_MCP_AI_Section_Providers();

		// Capture output.
		ob_start();
		$section->render_embedded_model_management( array() );
		$output = ob_get_clean();

		// Check that usage instructions class exists in the output.
		$this->assertStringContainsString( 'wp-mcp-ai-model-usage-info', $output );

		// Check that instructions text is present.
		$this->assertStringContainsString( 'How to use this model:', $output );
		$this->assertStringContainsString( 'The model identifier is:', $output );
		$this->assertStringContainsString( 'Default Embedded Model', $output );
	}
}
