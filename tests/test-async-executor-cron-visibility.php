<?php
/**
 * Test async executor cron job visibility in cron manager.
 *
 * Verifies that cron jobs scheduled by the async executor are properly
 * recorded in the cron manager before they execute.
 *
 * @package WP_MCP_AI
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
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
	}

	/**
	 * Test that async executor schedules cron with delay to allow recording.
	 */
	public function test_async_executor_schedules_with_delay() {
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
		$this->assertGreaterThan( time(), $event->timestamp, 'Cron should be scheduled in the future (not immediate)' );
		$this->assertLessThanOrEqual( time() + 2, $event->timestamp, 'Cron should be scheduled within 2 seconds' );

		// Check that job was recorded in cron manager.
		$recorded_job = WP_MCP_AI_Cron_Manager::get_job( md5( wp_json_encode( array(
			'hook' => $hook,
			'args' => array( $job_id ),
		) ) ) );

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

		$result = $method->invoke( $service, $operation, $args );

		$this->assertIsArray( $result, 'Should return async result' );
		$this->assertTrue( $result['async'], 'Should be async' );
		$this->assertArrayHasKey( 'job_id', $result );

		$job_id = $result['job_id'];

		// Check that cron was scheduled.
		$hook  = WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK;
		$event = wp_get_scheduled_event( $hook, array( $job_id ) );

		$this->assertNotFalse( $event, 'Veo cron event should be scheduled' );
		$this->assertGreaterThan( time(), $event->timestamp, 'Veo cron should be scheduled in the future (not immediate)' );
		$this->assertLessThanOrEqual( time() + 2, $event->timestamp, 'Veo cron should be scheduled within 2 seconds' );

		// Check that job was recorded in cron manager.
		$recorded_job = WP_MCP_AI_Cron_Manager::get_job( md5( wp_json_encode( array(
			'hook' => $hook,
			'args' => array( $job_id ),
		) ) ) );

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
			private $slug;

			public function __construct( $slug ) {
				$this->slug = $slug;
			}

			public function get_slug() {
				return $this->slug;
			}

			public function get_name() {
				return 'Test Tool';
			}

			public function get_description() {
				return 'A test tool';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				return array( 'success' => true );
			}
		};
	}
}
