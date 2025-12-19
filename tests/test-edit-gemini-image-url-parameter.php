<?php
/**
 * Test Edit Gemini Image Tool URL and File ID Parameters
 *
 * Verifies that the edit_gemini_image tool properly exposes url and file_id
 * parameters in its schema, allowing LLMs to use attached images.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for URL and file_id parameter support
 */
class Test_Edit_Gemini_Image_URL_Parameter extends WP_UnitTestCase {

	/**
	 * Test that the tool schema includes url parameter
	 */
	public function test_tool_schema_includes_url_parameter() {
		require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';

		$tool   = new WP_MCP_AI_Tool_Edit_Gemini_Image();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'url', $schema['properties'], 'Schema should include url parameter' );

		// Verify url parameter has proper structure
		$url_param = $schema['properties']['url'];
		$this->assertArrayHasKey( 'type', $url_param, 'URL parameter should have type' );
		$this->assertEquals( 'string', $url_param['type'], 'URL parameter should be string type' );
		$this->assertArrayHasKey( 'description', $url_param, 'URL parameter should have description' );
		$this->assertNotEmpty( $url_param['description'], 'URL parameter description should not be empty' );
	}

	/**
	 * Test that the tool schema includes file_id parameter
	 */
	public function test_tool_schema_includes_file_id_parameter() {
		require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';

		$tool   = new WP_MCP_AI_Tool_Edit_Gemini_Image();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'file_id', $schema['properties'], 'Schema should include file_id parameter' );

		// Verify file_id parameter has proper structure
		$file_id_param = $schema['properties']['file_id'];
		$this->assertArrayHasKey( 'type', $file_id_param, 'file_id parameter should have type' );
		$this->assertEquals( 'string', $file_id_param['type'], 'file_id parameter should be string type' );
		$this->assertArrayHasKey( 'description', $file_id_param, 'file_id parameter should have description' );
		$this->assertNotEmpty( $file_id_param['description'], 'file_id parameter description should not be empty' );
	}

	/**
	 * Test that existing parameters are still present
	 */
	public function test_tool_schema_preserves_existing_parameters() {
		require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';

		$tool   = new WP_MCP_AI_Tool_Edit_Gemini_Image();
		$schema = $tool->get_parameters_schema();

		$expected_params = array(
			'prompt',
			'attachment_id',
			'image_url',
			'image_data',
			'source_mime_type',
			'model',
			'aspect_ratio',
			'mime_type',
			'file_name',
			'timeout',
		);

		foreach ( $expected_params as $param ) {
			$this->assertArrayHasKey( $param, $schema['properties'], "Schema should include $param parameter" );
		}
	}

	/**
	 * Test that the tool description mentions attachment support
	 */
	public function test_tool_description_mentions_attachments() {
		require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';

		$tool        = new WP_MCP_AI_Tool_Edit_Gemini_Image();
		$description = $tool->get_description();

		$this->assertNotEmpty( $description, 'Tool description should not be empty' );
		$this->assertStringContainsString( 'attachment', strtolower( $description ), 'Description should mention attachments' );
	}

	/**
	 * Test that the tool uses the Attachment_File_Resolver trait
	 */
	public function test_tool_uses_attachment_file_resolver_trait() {
		require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';

		$tool       = new WP_MCP_AI_Tool_Edit_Gemini_Image();
		$traits     = class_uses( $tool );
		$trait_name = 'WP_MCP_AI_Attachment_File_Resolver';

		$this->assertContains( $trait_name, $traits, "Tool should use $trait_name trait" );
	}

	/**
	 * Test that url parameter description is helpful for LLMs
	 */
	public function test_url_parameter_description_is_helpful() {
		require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';

		$tool   = new WP_MCP_AI_Tool_Edit_Gemini_Image();
		$schema = $tool->get_parameters_schema();

		$url_description = $schema['properties']['url']['description'];

		// Check that the description provides context about when to use this parameter
		$this->assertStringContainsString( 'URL', $url_description, 'Description should mention URL' );
		$this->assertStringContainsString( 'image', strtolower( $url_description ), 'Description should mention image' );
	}

	/**
	 * Test that shortcut tasks mention URL parameter usage
	 */
	public function test_shortcut_tasks_mention_url_parameter() {
		require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';

		$tool      = new WP_MCP_AI_Tool_Edit_Gemini_Image();
		$shortcuts = $tool->get_shortcut_tasks();

		$this->assertNotEmpty( $shortcuts, 'Tool should have shortcut tasks' );

		// Check if at least one shortcut mentions URL parameter
		$mentions_url = false;
		foreach ( $shortcuts as $shortcut ) {
			if ( isset( $shortcut['payload'] ) && stripos( $shortcut['payload'], 'url' ) !== false ) {
				$mentions_url = true;
				break;
			}
		}

		$this->assertTrue( $mentions_url, 'At least one shortcut task should mention the url parameter' );
	}
}
