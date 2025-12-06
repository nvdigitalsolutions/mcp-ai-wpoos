<?php
/**
 * Tests for image manipulation tools capability flags.
 *
 * @package WP_MCP_AI
 */

/**
 * Test capability flags for image manipulation tools.
 */
class WP_MCP_AI_Image_Tools_Capability_Flags_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_removebg_api_key' );
		parent::tearDown();
	}

	/**
	 * Test that resize_image tool returns correct capability flags.
	 */
	public function test_resize_image_capability_flags() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-resize-image.php';
		
		$tool  = new WP_MCP_AI_Tool_Resize_Image();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'idempotent', $flags );
		$this->assertContains( 'performance-impact', $flags );
	}

	/**
	 * Test that crop_image tool returns correct capability flags.
	 */
	public function test_crop_image_capability_flags() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-crop-image.php';
		
		$tool  = new WP_MCP_AI_Tool_Crop_Image();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'idempotent', $flags );
		$this->assertContains( 'performance-impact', $flags );
	}

	/**
	 * Test that rotate_image tool returns correct capability flags.
	 */
	public function test_rotate_image_capability_flags() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-rotate-image.php';
		
		$tool  = new WP_MCP_AI_Tool_Rotate_Image();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'idempotent', $flags );
		$this->assertContains( 'performance-impact', $flags );
	}

	/**
	 * Test that convert_image_format tool returns correct capability flags.
	 */
	public function test_convert_image_format_capability_flags() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-convert-image-format.php';
		
		$tool  = new WP_MCP_AI_Tool_Convert_Image_Format();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'idempotent', $flags );
		$this->assertContains( 'performance-impact', $flags );
	}

	/**
	 * Test that remove_background tool returns correct capability flags without API key.
	 */
	public function test_remove_background_capability_flags_without_api_key() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-remove-background.php';
		
		// Ensure no API key is set.
		delete_option( 'wp_mcp_ai_removebg_api_key' );

		$tool  = new WP_MCP_AI_Tool_Remove_Background();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'idempotent', $flags );
		$this->assertContains( 'performance-impact', $flags );

		// Should NOT have external API flags when no API key is configured.
		$this->assertNotContains( 'external-api', $flags );
		$this->assertNotContains( 'requires-credentials', $flags );
		$this->assertNotContains( 'network-dependent', $flags );
		$this->assertNotContains( 'consumes-tokens', $flags );
		$this->assertNotContains( 'rate-limited', $flags );
	}

	/**
	 * Test that remove_background tool returns correct capability flags with API key.
	 */
	public function test_remove_background_capability_flags_with_api_key() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-remove-background.php';
		
		// Set an API key.
		update_option( 'wp_mcp_ai_removebg_api_key', 'test-api-key' );

		$tool  = new WP_MCP_AI_Tool_Remove_Background();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'idempotent', $flags );
		$this->assertContains( 'performance-impact', $flags );

		// Should have external API flags when API key is configured.
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'network-dependent', $flags );
		$this->assertContains( 'consumes-tokens', $flags );
		$this->assertContains( 'rate-limited', $flags );

		// Should NOT have local-only flag when using external API.
		$this->assertNotContains( 'local-only', $flags );
	}

	/**
	 * Test that all image tools implement the capability flags interface.
	 */
	public function test_all_image_tools_implement_capability_flags_interface() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-resize-image.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-crop-image.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-rotate-image.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-convert-image-format.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-remove-background.php';

		$tools = array(
			new WP_MCP_AI_Tool_Resize_Image(),
			new WP_MCP_AI_Tool_Crop_Image(),
			new WP_MCP_AI_Tool_Rotate_Image(),
			new WP_MCP_AI_Tool_Convert_Image_Format(),
			new WP_MCP_AI_Tool_Remove_Background(),
		);

		foreach ( $tools as $tool ) {
			$this->assertInstanceOf(
				'WP_MCP_AI_Tool_Capability_Flags_Interface',
				$tool,
				get_class( $tool ) . ' should implement WP_MCP_AI_Tool_Capability_Flags_Interface'
			);
		}
	}
}
