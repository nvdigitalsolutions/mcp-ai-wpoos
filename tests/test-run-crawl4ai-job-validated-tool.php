<?php
/**
 * Tests for Run Crawl4AI Job Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Run_Crawl4AI_Job_Validated
 *
 * Tests for the validated run_crawl4ai_job tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Run_Crawl4AI_Job_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Run_Crawl4AI_Job_Validated
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
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-run-crawl4ai-job-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job-validated.php';

		// Create test user with manage_options capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'run_crawl4ai_job_validated', $this->tool->get_slug() );
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
		$this->assertArrayHasKey( 'urls', $schema['properties'] );
		$this->assertArrayHasKey( 'url', $schema['properties'] );
		$this->assertArrayHasKey( 'priority', $schema['properties'] );
	}

	/**
	 * Test validation fails with empty urls array.
	 */
	public function test_validation_fails_with_empty_urls_array() {
		$arguments = array(
			'urls' => array(),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid URL in urls array.
	 */
	public function test_validation_fails_with_invalid_url_in_array() {
		$arguments = array(
			'urls' => array( 'not-a-valid-url' ),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid url parameter.
	 */
	public function test_validation_fails_with_invalid_url() {
		$arguments = array(
			'url' => 'not-a-valid-url',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid priority (too low).
	 */
	public function test_validation_fails_with_invalid_priority_low() {
		$arguments = array(
			'urls'     => array( 'https://example.com' ),
			'priority' => -1,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid priority (too high).
	 */
	public function test_validation_fails_with_invalid_priority_high() {
		$arguments = array(
			'urls'     => array( 'https://example.com' ),
			'priority' => 101,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid poll_interval (too high).
	 */
	public function test_validation_fails_with_invalid_poll_interval() {
		$arguments = array(
			'urls'          => array( 'https://example.com' ),
			'poll_interval' => 31,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid timeout (too high).
	 */
	public function test_validation_fails_with_invalid_timeout() {
		$arguments = array(
			'urls'    => array( 'https://example.com' ),
			'timeout' => 601,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with non-boolean wait_for_completion.
	 */
	public function test_validation_fails_with_non_boolean_wait_for_completion() {
		$arguments = array(
			'urls'                => array( 'https://example.com' ),
			'wait_for_completion' => 'true',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with non-array options.
	 */
	public function test_validation_fails_with_non_array_options() {
		$arguments = array(
			'urls'    => array( 'https://example.com' ),
			'options' => 'invalid',
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
}
