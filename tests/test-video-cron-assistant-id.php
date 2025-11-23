<?php
/**
 * Test for video generation service assistant_id in cron job recording.
 *
 * Verifies that the recent cron fix (PR #1588) properly maintains assistant_id
 * context throughout the video polling lifecycle.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

/**
 * Test assistant_id handling in video generation cron jobs.
 */
class WP_MCP_AI_Video_Cron_Assistant_ID_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Clear cron and manager state.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
	}

	public function tearDown(): void {
		// Clean up.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Test that assistant_id is preserved in initial video job recording.
	 */
	public function test_assistant_id_in_initial_video_job() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Simulate queueing a video job with assistant_id.
		$operation = array(
			'operation_name' => 'test_operation',
			'model_used'     => 'veo-2.0-generate-001',
		);

		$args = array(
			'user_id'      => 1,
			'assistant_id' => 42,
			'prompt'       => 'Test video',
		);

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$result = $method->invoke( $service, $operation, $args );

		// Verify job was created.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['async'] );
		$this->assertArrayHasKey( 'job_id', $result );

		// Verify assistant_id was recorded in cron manager.
		$job = WP_MCP_AI_Cron_Manager::get_job( $result['job_id'] );
		$this->assertNotNull( $job );
		$this->assertEquals( 42, $job['assistant_id'], 'Assistant ID should be preserved in initial job recording' );
	}

	/**
	 * Test that assistant_id is preserved when scheduling next poll.
	 *
	 * This is the specific bug that was fixed - the schedule_next_poll method
	 * was missing the assistant_id parameter.
	 */
	public function test_assistant_id_in_next_poll_job() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create initial metadata with assistant_id.
		$job_id   = 'veo_test_' . uniqid();
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'test_operation',
			'model'          => 'veo-2.0-generate-001',
			'args'           => array(
				'user_id'      => 1,
				'assistant_id' => 99,
				'prompt'       => 'Test video continuation',
			),
			'status'         => 'polling',
			'queued_at'      => time(),
			'poll_attempt'   => 1,
			'max_attempts'   => 60,
		);

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'schedule_next_poll' );
		$method->setAccessible( true );

		$method->invoke( $service, $job_id, $metadata );

		// Verify assistant_id was recorded in the next poll job.
		$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );
		$this->assertNotNull( $job, 'Job should be recorded in cron manager' );
		$this->assertEquals( 99, $job['assistant_id'], 'Assistant ID should be preserved in next poll recording' );
	}

	/**
	 * Test that assistant_id defaults to 0 when not provided.
	 */
	public function test_assistant_id_defaults_to_zero() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create metadata without assistant_id.
		$job_id   = 'veo_test_' . uniqid();
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'test_operation',
			'model'          => 'veo-2.0-generate-001',
			'args'           => array(
				'user_id' => 1,
				'prompt'  => 'Test video no assistant',
			),
			'status'         => 'polling',
			'queued_at'      => time(),
			'poll_attempt'   => 1,
			'max_attempts'   => 60,
		);

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'schedule_next_poll' );
		$method->setAccessible( true );

		$method->invoke( $service, $job_id, $metadata );

		// Verify assistant_id defaults to 0.
		$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );
		$this->assertNotNull( $job );
		$this->assertEquals( 0, $job['assistant_id'], 'Assistant ID should default to 0 when not provided' );
	}
}
