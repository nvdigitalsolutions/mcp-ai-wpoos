<?php
/**
 * Test async executor cron job visibility in cron manager.
 *
 * Verifies that cron jobs scheduled by the async executor are properly
 * recorded in the cron manager before they execute.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test async executor cron job recording.
 */
class Test_Async_Executor_Cron_Visibility extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
	}

	/**
	 * Test that async executor schedules cron immediately so spawn_cron() can fire it.
	 */
	public function test_async_executor_schedules_immediately() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Mock tool that does nothing.
		$tool_slug = 'test_tool_' . uniqid();

		// Create a mock tool.
		$tool = $this->create_mock_tool( $tool_slug );

		// Register the tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$registry->register_tool( $tool );

		// Queue the tool.
		$context = array(
			'user_id' => 1,
		);

		$job_id = $executor->queue_tool( $tool_slug, array(), $context );

		$this->assertIsString( $job_id, 'Job ID should be returned' );
		$this->assertStringStartsWith( 'async_', $job_id, 'Job ID should start with async_' );

		// Check that cron was scheduled.
		$hook  = WP_MCP_AI_Tool_Async_Executor::CRON_HOOK;
		$event = wp_get_scheduled_event( $hook, array( $job_id ) );

		$this->assertNotFalse( $event, 'Cron event should be scheduled' );
		// Event must be already due so spawn_cron() can pick it up. We allow a small
		// tolerance window around time() to keep the test resilient to clock drift.
		$this->assertLessThanOrEqual( time(), $event->timestamp, 'Cron should be scheduled in the past or at current time so spawn_cron() will fire it' );
		$this->assertGreaterThanOrEqual( time() - 5, $event->timestamp, 'Cron should be scheduled close to the current time (within 5s tolerance)' );

		// Check that job was recorded in cron manager.
		$recorded_job = WP_MCP_AI_Cron_Manager::get_job(
			md5(
				wp_json_encode(
					array(
						'hook' => $hook,
						'args' => array( $job_id ),
					)
				)
			)
		);

		$this->assertIsArray( $recorded_job, 'Job should be recorded in cron manager' );
		$this->assertEquals( $hook, $recorded_job['hook'], 'Hook should match' );
		$this->assertEquals( array( $job_id ), $recorded_job['args'], 'Args should match' );
		$this->assertEquals( 'single', $recorded_job['schedule'], 'Should be single event' );
		$this->assertEquals( 1, $recorded_job['created_by'], 'Should be created by user 1' );
	}

	/**
	 * Test that veo service also schedules with delay.
	 */
	public function test_veo_service_schedules_with_delay() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		WP_MCP_AI_Gemini_Video_Generation_Service::init();

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Use reflection to access queue_async_polling method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$operation = array(
			'operation_name' => 'operations/test-operation-123',
			'model_used'     => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'metadata'       => array(),
		);

		$args = array(
			'prompt'  => 'Test video',
			'user_id' => 1,
		);

		// Capture a single time baseline before invoking so the timing
		// assertions don't drift with how long queue_async_polling() itself
		// takes (transient save, spawn_cron(), hooks, etc.).
		$before = time();

		$result = $method->invoke( $service, $operation, $args );

		$this->assertIsArray( $result, 'Should return async result' );
		$this->assertTrue( $result['async'], 'Should be async' );
		$this->assertArrayHasKey( 'job_id', $result );

		$job_id = $result['job_id'];

		// Check that cron was scheduled.
		$hook  = WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK;
		$event = wp_get_scheduled_event( $hook, array( $job_id ) );

		$this->assertNotFalse( $event, 'Veo cron event should be scheduled' );

		// The first poll is deliberately scheduled 1 second in the future
		// so the transient write completes before cron can execute.
		$this->assertGreaterThanOrEqual( $before + 1, $event->timestamp, 'Veo cron should be scheduled at least 1 second in the future' );
		$this->assertLessThanOrEqual( $before + 3, $event->timestamp, 'Veo cron should be scheduled within 3 seconds' );

		// Check that job was recorded in cron manager.
		$recorded_job = WP_MCP_AI_Cron_Manager::get_job(
			md5(
				wp_json_encode(
					array(
						'hook' => $hook,
						'args' => array( $job_id ),
					)
				)
			)
		);

		$this->assertIsArray( $recorded_job, 'Veo job should be recorded in cron manager' );
		$this->assertEquals( $hook, $recorded_job['hook'], 'Hook should match' );
		$this->assertEquals( array( $job_id ), $recorded_job['args'], 'Args should match' );
		$this->assertEquals( 'single', $recorded_job['schedule'], 'Should be single event' );
	}

	/**
	 * Create a mock tool for testing.
	 *
	 * @param string $slug Tool slug.
	 * @return object Mock tool instance.
	 */
	protected function create_mock_tool( $slug ) {
		return new class( $slug ) implements WP_MCP_AI_Tool_Interface {
			use WP_MCP_AI_Tool_Default_Capability;

			/**
			 * Tool slug.
			 *
			 * @var string
			 */
			private $slug;

			/**
			 * Constructor.
			 *
			 * @param string $slug Tool slug.
			 */
			public function __construct( $slug ) {
				$this->slug = $slug;
			}

			/**
			 * Get the tool slug.
			 *
			 * @return string Tool slug.
			 */
			public function get_slug() {
				return $this->slug;
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Test Tool';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'A test tool';
			}

			/**
			 * Get the parameters schema.
			 *
			 * @return array Parameters schema.
			 */
			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			/**
			 * Execute the tool.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context Execution context.
			 * @return array|WP_Error Tool result.
			 */
			public function execute( array $arguments = array(), array $context = array() ) {
				return array( 'success' => true );
			}
		};
	}
}
