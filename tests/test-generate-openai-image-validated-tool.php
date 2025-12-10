<?php
/**
 * Tests for Generate OpenAI Image Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Generate_OpenAI_Image_Validated
 *
 * Tests for the validated generate_openai_image tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Generate_OpenAI_Image_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Generate_OpenAI_Image_Validated
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
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-generate-openai-image-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-generate-openai-image-validated.php';

		// Create test user with read capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Generate_OpenAI_Image_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'generate_openai_image_validated', $this->tool->get_slug() );
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
		$this->assertArrayHasKey( 'model', $schema['properties'] );
		$this->assertArrayHasKey( 'size', $schema['properties'] );
		$this->assertArrayHasKey( 'quality', $schema['properties'] );
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
	 * Test validation fails with invalid size.
	 */
	public function test_validation_fails_with_invalid_size() {
		$arguments = array(
			'prompt' => 'A beautiful landscape',
			'size'   => '2048x2048',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid quality.
	 */
	public function test_validation_fails_with_invalid_quality() {
		$arguments = array(
			'prompt'  => 'A beautiful landscape',
			'quality' => 'ultra',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid response_format.
	 */
	public function test_validation_fails_with_invalid_response_format() {
		$arguments = array(
			'prompt'          => 'A beautiful landscape',
			'response_format' => 'json',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid format.
	 */
	public function test_validation_fails_with_invalid_format() {
		$arguments = array(
			'prompt' => 'A beautiful landscape',
			'format' => 'jpeg',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid timeout (too low).
	 */
	public function test_validation_fails_with_invalid_timeout_low() {
		$arguments = array(
			'prompt'  => 'A beautiful landscape',
			'timeout' => 2,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid timeout (too high).
	 */
	public function test_validation_fails_with_invalid_timeout_high() {
		$arguments = array(
			'prompt'  => 'A beautiful landscape',
			'timeout' => 400,
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
