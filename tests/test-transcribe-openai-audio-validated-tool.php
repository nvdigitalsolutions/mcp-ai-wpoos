<?php
/**
 * Tests for Transcribe OpenAI Audio Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Transcribe_OpenAI_Audio_Validated
 *
 * Tests for the validated transcribe_openai_audio tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Transcribe_OpenAI_Audio_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Transcribe_OpenAI_Audio_Validated
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
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-transcribe-openai-audio-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio-validated.php';

		// Create test user with read capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'transcribe_openai_audio_validated', $this->tool->get_slug() );
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
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'model', $schema['properties'] );
		$this->assertArrayHasKey( 'response_format', $schema['properties'] );
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
			'attachment_id' => -1,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid model type.
	 */
	public function test_validation_fails_with_invalid_model_type() {
		$arguments = array(
			'attachment_id' => 123,
			'model'         => 12345, // Should be string.
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid response format.
	 */
	public function test_validation_fails_with_invalid_response_format() {
		$arguments = array(
			'attachment_id'   => 123,
			'response_format' => 'invalid_format',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'json', $result->get_error_message() );
	}

	/**
	 * Test validation fails with invalid temperature range.
	 */
	public function test_validation_fails_with_temperature_out_of_range() {
		$arguments = array(
			'attachment_id' => 123,
			'temperature'   => 1.5, // Must be 0-1.
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
			'attachment_id' => 123,
			'timeout'       => 'not-a-number',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid language code format.
	 */
	public function test_validation_fails_with_invalid_language_code() {
		$arguments = array(
			'attachment_id' => 123,
			'language'      => 'invalid-lang-code',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation passes with valid language code.
	 */
	public function test_validation_passes_with_valid_language_code() {
		$arguments = array(
			'attachment_id' => 123,
			'language'      => 'en',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		// Validation should pass; result will be an error from the original tool
		// because attachment doesn't exist, but not a validation error.
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
}
