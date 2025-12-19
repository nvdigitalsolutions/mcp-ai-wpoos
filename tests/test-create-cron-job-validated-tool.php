<?php
/**
 * Tests for Create Cron Job Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Create_Cron_Job_Validated
 *
 * Tests for the validated create_cron_job tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Create_Cron_Job_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Create_Cron_Job_Validated
	 */
	private $tool;

	/**
	 * Test user ID with admin capabilities.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Test user ID without admin capabilities.
	 *
	 * @var int
	 */
	private $editor_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load dependencies.
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validator-service.php';
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validated-tool.php';
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-create-cron-job-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-cron-manager.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-create-cron-job-validated.php';

		// Create test user with admin capability.
		$this->admin_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Create test user without admin capability.
		$this->editor_user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $this->admin_user_id );

		$this->tool = new WP_MCP_AI_Tool_Create_Cron_Job_Validated();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clear all scheduled cron events.
		$crons = _get_cron_array();
		if ( ! empty( $crons ) ) {
			foreach ( $crons as $timestamp => $cron ) {
				foreach ( $cron as $hook => $events ) {
					foreach ( $events as $key => $event ) {
						wp_unschedule_event( $timestamp, $hook, $event['args'] );
					}
				}
			}
		}

		parent::tearDown();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'create_cron_job_validated', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertStringContainsString( 'Validated', $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Symfony Validator', $this->tool->get_description() );
	}

	/**
	 * Test creating a single cron job with valid data.
	 */
	public function test_create_single_cron_job_with_valid_data() {
		$future_timestamp = time() + 3600;
		$arguments        = array(
			'hook'      => 'test_cron_hook',
			'timestamp' => $future_timestamp,
			'schedule'  => 'single',
			'args'      => array(),
		);

		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'hook', $result );
		$this->assertArrayHasKey( 'schedule', $result );
		$this->assertArrayHasKey( 'timestamp', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertEquals( 'test_cron_hook', $result['hook'] );
		$this->assertEquals( 'single', $result['schedule'] );
		$this->assertEquals( $future_timestamp, $result['timestamp'] );

		// Verify event was scheduled.
		$next_scheduled = wp_next_scheduled( 'test_cron_hook', array() );
		$this->assertEquals( $future_timestamp, $next_scheduled );
	}

	/**
	 * Test creating a recurring cron job.
	 */
	public function test_create_recurring_cron_job() {
		$future_timestamp = time() + 3600;
		$arguments        = array(
			'hook'      => 'test_recurring_hook',
			'timestamp' => $future_timestamp,
			'schedule'  => 'hourly',
			'args'      => array(),
		);

		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertEquals( 'hourly', $result['schedule'] );

		// Verify event was scheduled.
		$next_scheduled = wp_next_scheduled( 'test_recurring_hook', array() );
		$this->assertNotFalse( $next_scheduled );
	}

	/**
	 * Test cron job with custom arguments.
	 */
	public function test_create_cron_job_with_arguments() {
		$future_timestamp = time() + 3600;
		$custom_args      = array(
			'post_id' => 123,
			'action'  => 'publish',
		);
		$arguments        = array(
			'hook'      => 'test_hook_with_args',
			'timestamp' => $future_timestamp,
			'schedule'  => 'single',
			'args'      => $custom_args,
		);

		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'args', $result );

		// Verify event was scheduled with correct arguments.
		$next_scheduled = wp_next_scheduled( 'test_hook_with_args', $custom_args );
		$this->assertNotFalse( $next_scheduled );
	}

	/**
	 * Test validation fails when hook is missing.
	 */
	public function test_validation_fails_without_hook() {
		$arguments = array(
			'timestamp' => time() + 3600,
			'schedule'  => 'single',
		);

		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for missing hook' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid hook format.
	 */
	public function test_validation_fails_with_invalid_hook_format() {
		$arguments = array(
			'hook'      => 'Invalid-Hook-Name',
			'timestamp' => time() + 3600,
			'schedule'  => 'single',
		);

		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for invalid hook format' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test default timestamp is set when not provided.
	 */
	public function test_default_timestamp_when_not_provided() {
		$arguments = array(
			'hook'     => 'test_default_timestamp',
			'schedule' => 'single',
		);

		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'timestamp', $result );
		// Default should be ~20 seconds in the future.
		$this->assertGreaterThan( time(), $result['timestamp'] );
		$this->assertLessThan( time() + 30, $result['timestamp'] );
	}

	/**
	 * Test error when timestamp is in the past.
	 */
	public function test_error_when_timestamp_in_past() {
		$past_timestamp = time() - 3600;
		$arguments      = array(
			'hook'      => 'test_past_timestamp',
			'timestamp' => $past_timestamp,
			'schedule'  => 'single',
		);

		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_past_timestamp', $result->get_error_code() );
	}

	/**
	 * Test error when invalid schedule is provided.
	 */
	public function test_error_with_invalid_schedule() {
		$arguments = array(
			'hook'      => 'test_invalid_schedule',
			'timestamp' => time() + 3600,
			'schedule'  => 'invalid_schedule',
		);

		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_schedule', $result->get_error_code() );
	}

	/**
	 * Test error when duplicate event already exists.
	 */
	public function test_error_when_duplicate_event_exists() {
		$future_timestamp = time() + 3600;
		$arguments        = array(
			'hook'      => 'test_duplicate_hook',
			'timestamp' => $future_timestamp,
			'schedule'  => 'single',
			'args'      => array(),
		);

		$context = array( 'user_id' => $this->admin_user_id );

		// Schedule the first event.
		$result1 = $this->tool->execute( $arguments, $context );
		$this->assertIsArray( $result1 );

		// Try to schedule duplicate event.
		$result2 = $this->tool->execute( $arguments, $context );
		$this->assertInstanceOf( 'WP_Error', $result2 );
		$this->assertEquals( 'wp_mcp_ai_event_exists', $result2->get_error_code() );
	}

	/**
	 * Test permission check - non-admin users should be denied.
	 */
	public function test_permission_denied_for_non_admin_users() {
		$arguments = array(
			'hook'      => 'test_permission_hook',
			'timestamp' => time() + 3600,
			'schedule'  => 'single',
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'async', $flags );
		$this->assertContains( 'background-only', $flags );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'hook', $schema['properties'] );
		$this->assertArrayHasKey( 'timestamp', $schema['properties'] );
		$this->assertArrayHasKey( 'schedule', $schema['properties'] );
		$this->assertArrayHasKey( 'args', $schema['properties'] );
		$this->assertContains( 'hook', $schema['required'] );
	}
}
