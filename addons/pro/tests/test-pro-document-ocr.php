<?php
/**
 * Tests for Pro Document OCR Tool
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Pro Document OCR Tool Test Case
 */
class Test_Pro_Document_OCR extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Pro_Document_OCR
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the tool class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php';
		$this->tool = new WP_MCP_AI_Tool_Pro_Document_OCR();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'pro_document_ocr', $this->tool->get_slug() );
		$this->assertEquals( 'Pro Document OCR', $this->tool->get_name() );
		$this->assertStringContainsString( 'Advanced AI-powered OCR', $this->tool->get_description() );
	}

	/**
	 * Test parameters schema structure.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'type', $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Check required source parameter.
		$this->assertContains( 'source', $schema['required'] );
		$this->assertArrayHasKey( 'source', $schema['properties'] );

		// Check source properties.
		$source = $schema['properties']['source'];
		$this->assertArrayHasKey( 'properties', $source );
		$this->assertArrayHasKey( 'attachment_ids', $source['properties'] );
		$this->assertArrayHasKey( 'attachment_id', $source['properties'] );
		$this->assertArrayHasKey( 'urls', $source['properties'] );
		$this->assertArrayHasKey( 'url', $source['properties'] );

		// Check options parameter.
		$this->assertArrayHasKey( 'options', $schema['properties'] );
		$options = $schema['properties']['options'];
		$this->assertArrayHasKey( 'provider', $options['properties'] );
		$this->assertArrayHasKey( 'output_format', $options['properties'] );
		$this->assertArrayHasKey( 'preserve_layout', $options['properties'] );

		// Verify output format enum.
		$this->assertArrayHasKey( 'enum', $options['properties']['output_format'] );
		$this->assertContains( 'text', $options['properties']['output_format']['enum'] );
		$this->assertContains( 'json', $options['properties']['output_format']['enum'] );
		$this->assertContains( 'markdown', $options['properties']['output_format']['enum'] );
		$this->assertContains( 'html', $options['properties']['output_format']['enum'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'requires-vision-model', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'consumes-tokens', $flags );
	}

	/**
	 * Test execute requires authentication.
	 */
	public function test_execute_requires_authentication() {
		$result = $this->tool->execute(
			array(
				'source' => array( 'attachment_id' => 123 ),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( 'permission_denied', $result['error'] );
		$this->assertArrayHasKey( 'report', $result );
		$this->assertStringContainsString( 'permission', $result['report'] );
	}

	/**
	 * Test execute requires source parameter.
	 */
	public function test_execute_requires_source() {
		// Create test user with upload capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( 'missing_source', $result['error'] );
		$this->assertArrayHasKey( 'report', $result );
		$this->assertStringContainsString( 'Source document', $result['report'] );
	}

	/**
	 * Test execute with non-existent attachment.
	 */
	public function test_execute_with_invalid_attachment() {
		// Create test user with upload capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$result = $this->tool->execute(
			array(
				'source' => array( 'attachment_id' => 99999 ),
			),
			array( 'user_id' => $user_id )
		);

		// Should return response with documents_count (even if 0 successful).
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		// Could be true or false depending on whether no documents or all failed.
		$this->assertArrayHasKey( 'report', $result );
	}

	/**
	 * Test default options are applied.
	 */
	public function test_default_options_applied() {
		$schema = $this->tool->get_parameters_schema();
		$options = $schema['properties']['options']['properties'];

		// Check defaults.
		$this->assertEquals( 'auto', $options['provider']['default'] );
		$this->assertEquals( 'text', $options['output_format']['default'] );
		$this->assertEquals( false, $options['preserve_layout']['default'] );
		$this->assertEquals( true, $options['include_metadata']['default'] );
		$this->assertEquals( true, $options['preprocess']['default'] );
	}

	/**
	 * Test max limits are enforced.
	 */
	public function test_max_limits() {
		$schema = $this->tool->get_parameters_schema();
		$source = $schema['properties']['source']['properties'];
		$options = $schema['properties']['options']['properties'];

		// Check batch limits.
		$this->assertArrayHasKey( 'maxItems', $source['attachment_ids'] );
		$this->assertEquals( 20, $source['attachment_ids']['maxItems'] );
		$this->assertEquals( 20, $source['urls']['maxItems'] );

		// Check page limit.
		$this->assertEquals( 50, $options['max_pages_per_pdf']['maximum'] );
		$this->assertEquals( 10, $options['max_pages_per_pdf']['default'] );
	}

	/**
	 * Test provider enum values.
	 */
	public function test_provider_enum() {
		$schema = $this->tool->get_parameters_schema();
		$provider = $schema['properties']['options']['properties']['provider'];

		$this->assertArrayHasKey( 'enum', $provider );
		$expected_providers = array( 'auto', 'openai', 'gemini', 'anthropic', 'ollama', 'tesseract' );
		foreach ( $expected_providers as $expected ) {
			$this->assertContains( $expected, $provider['enum'] );
		}
	}

	/**
	 * Test export options structure.
	 */
	public function test_export_options() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'export_options', $schema['properties'] );
		$export = $schema['properties']['export_options']['properties'];

		$this->assertArrayHasKey( 'save_as_attachment', $export );
		$this->assertArrayHasKey( 'attachment_title', $export );
		$this->assertEquals( 'boolean', $export['save_as_attachment']['type'] );
		$this->assertEquals( false, $export['save_as_attachment']['default'] );
	}

	/**
	 * Test tool slug uniqueness.
	 */
	public function test_slug_uniqueness() {
		$slug = $this->tool->get_slug();

		// Slug should not conflict with existing base OCR tool.
		$this->assertNotEquals( 'extract_image_text', $slug );
		$this->assertNotEquals( 'ocr_pdf_text', $slug );

		// Should be pro-prefixed.
		$this->assertStringStartsWith( 'pro_', $slug );
	}

	/**
	 * Test description mentions standards.
	 */
	public function test_description_mentions_standards() {
		$description = $this->tool->get_description();

		// Should mention industry standards.
		$this->assertStringContainsString( 'ISO', $description );
		$this->assertStringContainsString( 'NIST', $description );
	}

	/**
	 * Test description mentions key features.
	 */
	public function test_description_mentions_features() {
		$description = $this->tool->get_description();

		// Key features.
		$this->assertStringContainsString( 'multi-page', $description );
		$this->assertStringContainsString( 'batch', $description );
		$this->assertStringContainsString( 'layout preservation', $description );
		$this->assertStringContainsString( 'structured output', $description );
	}

	/**
	 * Test output format options.
	 */
	public function test_output_format_options() {
		$schema = $this->tool->get_parameters_schema();
		$format = $schema['properties']['options']['properties']['output_format'];

		$expected_formats = array( 'text', 'json', 'markdown', 'html' );
		$this->assertEquals( $expected_formats, $format['enum'] );
	}

	/**
	 * Test tool implements correct interfaces.
	 */
	public function test_implements_interfaces() {
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $this->tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $this->tool );
	}

	/**
	 * Test tool uses correct traits.
	 */
	public function test_uses_traits() {
		$uses_traits = class_uses( $this->tool );

		$this->assertContains( 'WP_MCP_AI_Tool_Chat_Response', $uses_traits );
		$this->assertContains( 'WP_MCP_AI_Tool_Document_Response', $uses_traits );
		$this->assertContains( 'WP_MCP_AI_Attachment_File_Resolver', $uses_traits );
	}

	/**
	 * Test multi-URL support in schema.
	 */
	public function test_multi_url_support() {
		$schema = $this->tool->get_parameters_schema();
		$source = $schema['properties']['source']['properties'];

		// Verify urls array is supported.
		$this->assertArrayHasKey( 'urls', $source );
		$this->assertEquals( 'array', $source['urls']['type'] );
		$this->assertArrayHasKey( 'items', $source['urls'] );
		$this->assertEquals( 'string', $source['urls']['items']['type'] );
		$this->assertEquals( 20, $source['urls']['maxItems'] );
	}

	/**
	 * Test multi-attachment-ID support in schema.
	 */
	public function test_multi_attachment_id_support() {
		$schema = $this->tool->get_parameters_schema();
		$source = $schema['properties']['source']['properties'];

		// Verify attachment_ids array is supported.
		$this->assertArrayHasKey( 'attachment_ids', $source );
		$this->assertEquals( 'array', $source['attachment_ids']['type'] );
		$this->assertArrayHasKey( 'items', $source['attachment_ids'] );
		$this->assertEquals( 'integer', $source['attachment_ids']['items']['type'] );
		$this->assertEquals( 20, $source['attachment_ids']['maxItems'] );
	}

	/**
	 * Test URL array parameter is properly documented.
	 */
	public function test_url_array_documentation() {
		$schema = $this->tool->get_parameters_schema();
		$source = $schema['properties']['source'];

		// Check that source description mentions arrays.
		$this->assertStringContainsString( 'array', $source['description'] );
		$this->assertStringContainsString( 'ONE of', $source['description'] );
	}
}
