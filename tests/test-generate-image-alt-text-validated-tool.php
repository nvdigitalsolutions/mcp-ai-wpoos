<?php
/**
 * Tests for Generate Image Alt Text Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Generate_Image_Alt_Text_Validated
 *
 * Tests for the validated generate_image_alt_text tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Generate_Image_Alt_Text_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Image_Alt_Text_Validated
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
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-generate-image-alt-text-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-generate-image-alt-text-validated.php';

		// Create test user with upload_files capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'author',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Generate_Image_Alt_Text_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'generate_image_alt_text_validated', $this->tool->get_slug() );
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
		$this->assertArrayHasKey( 'image_url', $schema['properties'] );
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
	}

	/**
	 * Test validation fails with invalid image URL.
	 */
	public function test_validation_fails_with_invalid_image_url() {
		$arguments = array(
			'image_url' => 'not-a-valid-url',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid attachment ID type.
	 */
	public function test_validation_fails_with_invalid_attachment_id_type() {
		$arguments = array(
			'attachment_id' => 'not-an-integer',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with negative attachment ID.
	 */
	public function test_validation_fails_with_negative_attachment_id() {
		$arguments = array(
			'attachment_id' => -5,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with context too long.
	 */
	public function test_validation_fails_with_context_too_long() {
		$arguments = array(
			'attachment_id' => 123,
			'context'       => str_repeat( 'a', 501 ), // Over 500 char limit.
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation passes with valid image URL.
	 */
	public function test_validation_passes_with_valid_image_url() {
		$arguments = array(
			'image_url' => 'https://example.com/image.jpg',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		// Validation should pass; result will be an error from the original tool
		// (because API is not configured), but not a validation error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
		}
	}

	/**
	 * Test validation passes with valid attachment ID.
	 */
	public function test_validation_passes_with_valid_attachment_id() {
		$arguments = array(
			'attachment_id' => 123,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		// Validation should pass; result will be an error from the original tool
		// (because attachment doesn't exist), but not a validation error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
		}
	}

	/**
	 * Test validation passes with valid context.
	 */
	public function test_validation_passes_with_valid_context() {
		$arguments = array(
			'image_url' => 'https://example.com/image.jpg',
			'context'   => 'This is a product photo for an e-commerce site',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		// Validation should pass; result will be an error from the original tool
		// (because API is not configured), but not a validation error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
		}
	}

	/**
	 * Test capability flags are delegated to original tool.
	 */
	public function test_capability_flags_delegation() {
		if ( method_exists( $this->tool, 'get_capability_flags' ) ) {
			$flags = $this->tool->get_capability_flags();
			$this->assertIsArray( $flags );
		}
	}

	/**
	 * Test model requirements are delegated to original tool.
	 */
	public function test_model_requirements_delegation() {
		if ( method_exists( $this->tool, 'get_model_requirements' ) ) {
			$requirements = $this->tool->get_model_requirements();
			$this->assertIsArray( $requirements );
		}
	}
}
