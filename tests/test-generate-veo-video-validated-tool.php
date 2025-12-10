<?php
/**
 * Tests for Generate Veo Video Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Generate_Veo_Video_Validated
 *
 * Tests for the validated generate_veo_video tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Generate_Veo_Video_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Veo_Video_Validated
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
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-generate-veo-video-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-generate-veo-video-validated.php';

		// Create test user with upload_files capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Generate_Veo_Video_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'generate_veo_video_validated', $this->tool->get_slug() );
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
		$this->assertArrayHasKey( 'duration', $schema['properties'] );
		$this->assertArrayHasKey( 'aspect_ratio', $schema['properties'] );
		$this->assertArrayHasKey( 'resolution', $schema['properties'] );
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
	 * Test validation fails with invalid duration (too low).
	 */
	public function test_validation_fails_with_invalid_duration_low() {
		$arguments = array(
			'prompt'   => 'A sunset over the ocean',
			'duration' => 2,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid duration (too high).
	 */
	public function test_validation_fails_with_invalid_duration_high() {
		$arguments = array(
			'prompt'   => 'A sunset over the ocean',
			'duration' => 10,
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
			'prompt'       => 'A sunset over the ocean',
			'aspect_ratio' => '21:9',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid resolution.
	 */
	public function test_validation_fails_with_invalid_resolution() {
		$arguments = array(
			'prompt'     => 'A sunset over the ocean',
			'resolution' => '4k',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid style.
	 */
	public function test_validation_fails_with_invalid_style() {
		$arguments = array(
			'prompt' => 'A sunset over the ocean',
			'style'  => 'invalid_style',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid model.
	 */
	public function test_validation_fails_with_invalid_model() {
		$arguments = array(
			'prompt' => 'A sunset over the ocean',
			'model'  => 'veo-5.0',
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
}
