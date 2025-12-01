<?php
/**
 * Test that veo job ID is included in delegation message
 *
 * Verifies that when a veo job is created via async executor delegation,
 * the veo job ID is properly included in the message field so the chat UI
 * can display it to the user.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for veo job ID visibility in delegation messages
 */
class Test_Veo_Job_ID_In_Delegation_Message extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';

		// Initialize job notifier.
		WP_MCP_AI_Job_Notifier::init();
	}

	/**
	 * Test that delegated job metadata includes veo job ID in message.
	 */
	public function test_delegated_job_metadata_includes_veo_job_id_in_message() {
		// Create executor instance.
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Create a mock tool that simulates veo video generation returning a nested async response.
		$veo_job_id = 'veo_test_12345';
		$mock_tool  = new class( $veo_job_id ) implements WP_MCP_AI_Tool_Interface {
			private $veo_job_id;

			public function __construct( $veo_job_id ) {
				$this->veo_job_id = $veo_job_id;
			}

			public function get_slug() {
				return 'generate_veo_video';
			}

			public function get_name() {
				return 'Generate Video with Veo';
			}

			public function get_description() {
				return 'Generates videos using Veo';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(
						'prompt' => array(
							'type'        => 'string',
							'description' => 'Video prompt',
						),
					),
					'required'   => array( 'prompt' ),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				// Simulate veo service's queue_async_polling return value.
				// which includes the veo job ID in the message.
				return array(
					'async'   => true,
					'job_id'  => $this->veo_job_id,
					'status'  => 'pending',
					'message' => sprintf(
						'Video generation started (Job ID: %s). Your video is being created in the background and will appear here when ready.',
						$this->veo_job_id
					),
				);
			}
		};

		// Register the mock tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $mock_tool );

		// Queue the tool for async execution.
		$parent_job_id = $executor->queue_tool( 'generate_veo_video', array( 'prompt' => 'Test video' ), array( 'user_id' => 1 ) );

		$this->assertNotInstanceOf( 'WP_Error', $parent_job_id, 'Job should be queued successfully' );
		$this->assertStringStartsWith( 'async_', $parent_job_id, 'Parent job ID should start with async_' );

		// Execute the async tool (simulates cron execution).
		$executor->execute_async_tool( $parent_job_id );

		// Get the job status from the Job Notifier cache.
		// This is what the REST API would return when polling the job.
		$cached_status = WP_MCP_AI_Job_Notifier::get_job_status( $parent_job_id );

		// Verify the cached status exists and has the expected structure.
		$this->assertIsArray( $cached_status, 'Cached job status should exist' );
		$this->assertEquals( 'started', $cached_status['status'], 'Cached status should be "started"' );
		$this->assertArrayHasKey( 'metadata', $cached_status, 'Cached status should have metadata' );

		// Verify the metadata includes the veo job ID.
		$metadata = $cached_status['metadata'];
		$this->assertArrayHasKey( 'delegated_to', $metadata, 'Metadata should have delegated_to field' );
		$this->assertEquals( $veo_job_id, $metadata['delegated_to'], 'Should be delegated to veo job' );

		// CRITICAL: Verify the message includes the veo job ID.
		// This is what the chat UI will display to the user.
		$this->assertArrayHasKey( 'message', $metadata, 'Metadata should have message field' );
		$this->assertStringContainsString( $veo_job_id, $metadata['message'], 'Message should include veo job ID' );
		$this->assertStringContainsString( 'Job ID:', $metadata['message'], 'Message should explicitly label the job ID' );
		$this->assertStringContainsString( 'Video generation started', $metadata['message'], 'Message should indicate video generation started' );

		// Cleanup.
		$reflection = new ReflectionClass( $executor );
		$method     = $reflection->getMethod( 'delete_metadata' );
		$method->setAccessible( true );
		$method->invoke( $executor, $parent_job_id );
	}

	/**
	 * Test that the Cron Status Service returns the veo job ID message.
	 */
	public function test_cron_status_service_returns_veo_job_id_message() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Create a mock tool that simulates veo video generation.
		$veo_job_id = 'veo_test_67890';
		$mock_tool  = new class( $veo_job_id ) implements WP_MCP_AI_Tool_Interface {
			private $veo_job_id;

			public function __construct( $veo_job_id ) {
				$this->veo_job_id = $veo_job_id;
			}

			public function get_slug() {
				return 'generate_veo_video';
			}

			public function get_name() {
				return 'Generate Video with Veo';
			}

			public function get_description() {
				return 'Generates videos using Veo';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				return array(
					'async'   => true,
					'job_id'  => $this->veo_job_id,
					'status'  => 'pending',
					'message' => sprintf(
						'Video generation started (Job ID: %s). Your video is being created in the background and will appear here when ready.',
						$this->veo_job_id
					),
				);
			}
		};

		// Register the mock tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $mock_tool );

		// Queue and execute the tool.
		$parent_job_id = $executor->queue_tool( 'generate_veo_video', array(), array( 'user_id' => 1 ) );
		$executor->execute_async_tool( $parent_job_id );

		// Get job details via Cron Status Service (this is what the REST API uses).
		$service     = new WP_MCP_AI_Cron_Status_Service();
		$job_details = $service->get_job_details( $parent_job_id, 1 );

		// Verify job details are returned.
		$this->assertNotInstanceOf( 'WP_Error', $job_details, 'Should return job details' );
		$this->assertIsArray( $job_details, 'Job details should be array' );

		// Verify the job details include the delegated_to and message fields.
		// The REST API returns these to the chat UI via SSE or polling.
		$this->assertArrayHasKey( 'delegated_to', $job_details, 'Job details should have delegated_to' );
		$this->assertEquals( $veo_job_id, $job_details['delegated_to'], 'Should include veo job ID' );

		$this->assertArrayHasKey( 'message', $job_details, 'Job details should have message' );
		$this->assertStringContainsString( $veo_job_id, $job_details['message'], 'Message should include veo job ID' );

		// Cleanup.
		$reflection = new ReflectionClass( $executor );
		$method     = $reflection->getMethod( 'delete_metadata' );
		$method->setAccessible( true );
		$method->invoke( $executor, $parent_job_id );
	}
}
