<?php
/**
 * Tests for Edit Gemini Image Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Edit_Gemini_Image_Validated
 *
 * Tests for the validated edit_gemini_image tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Edit_Gemini_Image_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Edit_Gemini_Image_Validated
	 */
	private $tool;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Skip if PHP < 8.0 (Symfony Validator attributes require PHP 8.0+).
		if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
			$this->markTestSkipped( 'Symfony Validator requires PHP 8.0+' );
		}

		// Load dependencies.
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validator-service.php';
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validated-tool.php';
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-edit-gemini-image-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-edit-gemini-image-validated.php';

		// Create test user with read capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Edit_Gemini_Image_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'edit_gemini_image_validated', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Validated', $this->tool->get_name() );
	}

	/**
	 * Test parameter schema is inherited from original tool.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'prompt', $schema['properties'] );
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'image_url', $schema['properties'] );
		$this->assertArrayHasKey( 'image_data', $schema['properties'] );
	}

	/**
	 * Test validation fails with missing prompt.
	 */
	public function test_validation_fails_with_missing_prompt() {
		$arguments = array();

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with empty prompt.
	 */
	public function test_validation_fails_with_empty_prompt() {
		$arguments = array(
			'prompt' => '',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid attachment_id (negative).
	 */
	public function test_validation_fails_with_invalid_attachment_id() {
		$arguments = array(
			'prompt'        => 'Remove background',
			'attachment_id' => -1,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid image_url.
	 */
	public function test_validation_fails_with_invalid_image_url() {
		$arguments = array(
			'prompt'    => 'Remove background',
			'image_url' => 'not-a-valid-url',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid source_mime_type.
	 */
	public function test_validation_fails_with_invalid_source_mime_type() {
		$arguments = array(
			'prompt'           => 'Remove background',
			'image_data'       => 'base64data',
			'source_mime_type' => 'image/bmp',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid aspect_ratio.
	 */
	public function test_validation_fails_with_invalid_aspect_ratio() {
		$arguments = array(
			'prompt'       => 'Remove background',
			'image_url'    => 'https://example.com/image.jpg',
			'aspect_ratio' => '21:9',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid mime_type.
	 */
	public function test_validation_fails_with_invalid_mime_type() {
		$arguments = array(
			'prompt'    => 'Remove background',
			'image_url' => 'https://example.com/image.jpg',
			'mime_type' => 'image/bmp',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid timeout.
	 */
	public function test_validation_fails_with_invalid_timeout() {
		$arguments = array(
			'prompt'    => 'Remove background',
			'image_url' => 'https://example.com/image.jpg',
			'timeout'   => 400,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test capability flags are delegated.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();
		$this->assertIsArray( $flags );
	}

	/**
	 * Test model requirements are delegated.
	 */
	public function test_model_requirements() {
		$requirements = $this->tool->get_model_requirements();
		$this->assertIsArray( $requirements );
	}

	/**
	 * Test shortcut tasks are delegated.
	 */
	public function test_shortcut_tasks() {
		$shortcuts = $this->tool->get_shortcut_tasks();
		$this->assertIsArray( $shortcuts );
	}
}
