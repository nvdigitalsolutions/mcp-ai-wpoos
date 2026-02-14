<?php
/**
 * Tests for OCR PDF Text Extraction Tool
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Test OCR PDF Text Extraction Tool class
 */
class Test_WP_MCP_AI_Tool_OCR_PDF_Text extends WP_UnitTestCase {

	/**
	 * OCR PDF Text tool instance.
	 *
	 * @var WP_MCP_AI_Tool_OCR_PDF_Text
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Load tool.
		require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-ocr-pdf-text.php';
		
		$this->tool = new WP_MCP_AI_Tool_OCR_PDF_Text();
	}

	/**
	 * Test that tool class exists.
	 */
	public function test_tool_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_OCR_PDF_Text' ) );
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		$this->assertEquals( 'ocr_pdf_text', $this->tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_get_name() {
		$name = $this->tool->get_name();
		$this->assertIsString( $name );
		$this->assertNotEmpty( $name );
	}

	/**
	 * Test tool description.
	 */
	public function test_get_description() {
		$description = $this->tool->get_description();
		$this->assertIsString( $description );
		$this->assertNotEmpty( $description );
		$this->assertStringContainsString( 'OCR', $description );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_get_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();
		
		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		
		// Check for key parameters.
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'url', $schema['properties'] );
		$this->assertArrayHasKey( 'max_pages', $schema['properties'] );
		$this->assertArrayHasKey( 'provider', $schema['properties'] );
		$this->assertArrayHasKey( 'preprocess', $schema['properties'] );
		$this->assertArrayHasKey( 'language', $schema['properties'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		$flags = $this->tool->get_capability_flags();
		
		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'requires-vision-model', $flags );
	}

	/**
	 * Test execute without parameters.
	 */
	public function test_execute_without_parameters() {
		// Create a user with read capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $this->tool->execute( array(), array() );
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'report', $result );
		$this->assertStringContainsString( 'attachment_id or url', $result['report'] );
	}

	/**
	 * Test execute without permission.
	 */
	public function test_execute_without_permission() {
		// No user logged in.
		wp_set_current_user( 0 );

		$result = $this->tool->execute(
			array( 'attachment_id' => 1 ),
			array()
		);
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'report', $result );
		$this->assertStringContainsString( 'permission', $result['report'] );
	}

	/**
	 * Test execute with invalid attachment ID.
	 */
	public function test_execute_with_invalid_attachment_id() {
		// Create a user with read capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $this->tool->execute(
			array( 'attachment_id' => 99999 ),
			array()
		);
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'report', $result );
		$this->assertStringContainsString( 'not found', $result['report'] );
	}

	/**
	 * Test provider parameter validation.
	 */
	public function test_provider_parameter_values() {
		$schema = $this->tool->get_parameters_schema();
		$provider_schema = $schema['properties']['provider'];
		
		$this->assertArrayHasKey( 'enum', $provider_schema );
		$this->assertContains( 'auto', $provider_schema['enum'] );
		$this->assertContains( 'openai', $provider_schema['enum'] );
		$this->assertContains( 'gemini', $provider_schema['enum'] );
		$this->assertContains( 'ollama', $provider_schema['enum'] );
		$this->assertContains( 'tesseract', $provider_schema['enum'] );
	}
}
