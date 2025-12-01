<?php
/**
 * Test Veo video generation media lookup functionality.
 *
 * Tests that when a job transient expires, the system can recover
 * completion status by looking up media attachments with matching job_id.
 *
 * @package WP_MCP_AI
 */

/**
 * Test media lookup for Veo job recovery.
 */
class Test_Veo_Media_Lookup extends WP_UnitTestCase {
	/**
	 * Service instance.
	 *
	 * @var WP_MCP_AI_Gemini_Video_Generation_Service
	 */
	protected $service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';

		// Initialize service hooks.
		WP_MCP_AI_Gemini_Video_Generation_Service::init();

		$this->service = new WP_MCP_AI_Gemini_Video_Generation_Service();
	}

	/**
	 * Test that get_async_status returns error when job not found and no media exists.
	 */
	public function test_get_async_status_returns_error_when_not_found() {
		$job_id = 'veo_nonexistent_' . uniqid( '', true );

		$status = $this->service->get_async_status( $job_id );

		$this->assertWPError( $status );
		$this->assertEquals( 'wp_mcp_ai_job_not_found', $status->get_error_code() );
	}

	/**
	 * Test that get_async_status recovers from media when transient missing.
	 */
	public function test_get_async_status_recovers_from_media() {
		$job_id = 'veo_test_' . uniqid( '', true );

		// Create an attachment with the job_id in metadata.
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'video/mp4',
				'post_title'     => 'Veo Generated Video: Test prompt',
				'post_content'   => 'A test video prompt',
				'post_status'    => 'inherit',
			)
		);

		// Add veo metadata including job_id.
		update_post_meta( $attachment_id, '_veo_job_id', $job_id );
		update_post_meta( $attachment_id, '_veo_prompt', 'A test video prompt' );
		update_post_meta( $attachment_id, '_veo_duration', 5 );
		update_post_meta( $attachment_id, '_veo_aspect_ratio', '16:9' );
		update_post_meta( $attachment_id, '_veo_resolution', '720p' );
		update_post_meta( $attachment_id, '_veo_model', 'veo-3.1-generate-preview' );
		update_post_meta( $attachment_id, '_veo_provider', 'gemini' );

		// Ensure NO transient exists (simulates expired transient).
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );

		// Track if job_completed hook is fired.
		$hook_fired = false;
		$hook_data  = array();
		add_action(
			'wp_mcp_ai_job_completed',
			function ( $fired_job_id, $result, $metadata ) use ( &$hook_fired, &$hook_data, $job_id ) {
				if ( $fired_job_id === $job_id ) {
					$hook_fired = true;
					$hook_data  = array(
						'job_id'   => $fired_job_id,
						'result'   => $result,
						'metadata' => $metadata,
					);
				}
			},
			10,
			3
		);

		// Get status - should recover from media.
		$status = $this->service->get_async_status( $job_id );

		// Should NOT be an error.
		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'status', $status );
		$this->assertEquals( 'completed', $status['status'] );
		$this->assertEquals( $job_id, $status['job_id'] );
		$this->assertTrue( $status['recovered'] );

		// Check result data.
		$this->assertArrayHasKey( 'result', $status );
		$result = $status['result'];
		$this->assertEquals( $attachment_id, $result['attachment_id'] );
		$this->assertEquals( 'A test video prompt', $result['prompt'] );
		$this->assertEquals( 5, $result['duration'] );
		$this->assertEquals( '16:9', $result['aspect_ratio'] );
		$this->assertEquals( '720p', $result['resolution'] );
		$this->assertEquals( 'veo-3.1-generate-preview', $result['model'] );
		$this->assertEquals( 'gemini', $result['provider'] );

		// Verify job_completed hook was fired with recovered flag.
		$this->assertTrue( $hook_fired, 'wp_mcp_ai_job_completed hook should be fired' );
		$this->assertEquals( $job_id, $hook_data['job_id'] );
		$this->assertTrue( $hook_data['metadata']['recovered'] );
	}

	/**
	 * Test that transient status takes priority over media lookup.
	 */
	public function test_transient_status_takes_priority() {
		$job_id = 'veo_priority_' . uniqid( '', true );

		// Create an attachment with the job_id.
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'video/mp4',
				'post_title'     => 'Veo Generated Video: Priority test',
				'post_status'    => 'inherit',
			)
		);
		update_post_meta( $attachment_id, '_veo_job_id', $job_id );

		// Also create a transient showing job is still polling.
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array( 'prompt' => 'Priority test' ),
			'status'         => 'polling',
			'queued_at'      => time(),
			'poll_attempt'   => 5,
			'max_attempts'   => 60,
		);
		set_transient(
			WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id,
			$metadata,
			DAY_IN_SECONDS
		);

		// Get status - should use transient, not media.
		$status = $this->service->get_async_status( $job_id );

		$this->assertIsArray( $status );
		$this->assertEquals( 'polling', $status['status'] );
		$this->assertEquals( 5, $status['poll_attempt'] );
		$this->assertArrayNotHasKey( 'recovered', $status );
	}

	/**
	 * Test find_attachment_by_job_id returns null for empty job_id.
	 */
	public function test_find_attachment_rejects_empty_job_id() {
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'find_attachment_by_job_id' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->service, '' );
		$this->assertNull( $result );

		$result = $method->invoke( $this->service, null );
		$this->assertNull( $result );
	}

	/**
	 * Test that job_id is stored when save_video_to_media is called.
	 */
	public function test_save_video_to_media_stores_job_id() {
		// Skip if we can't create test files.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			$this->markTestSkipped( 'wp_upload_bits not available' );
		}

		$job_id = 'veo_save_' . uniqid( '', true );

		// Create a mock video result.
		$result = array(
			'video_data'   => 'fake video content for testing',
			'prompt'       => 'Test save video prompt',
			'duration'     => 6,
			'aspect_ratio' => '2:3',
			'resolution'   => '720p',
			'model'        => 'veo-2.0-generate-001',
			'provider'     => 'gemini',
		);

		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'save_video_to_media' );
		$method->setAccessible( true );

		$save_result = $method->invoke( $this->service, $result, 1, $job_id );

		// Should return array with attachment_id.
		$this->assertIsArray( $save_result );
		$this->assertArrayHasKey( 'attachment_id', $save_result );

		$attachment_id = $save_result['attachment_id'];

		// Verify job_id was stored in metadata.
		$stored_job_id = get_post_meta( $attachment_id, '_veo_job_id', true );
		$this->assertEquals( $job_id, $stored_job_id );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test that save_video_to_media works without job_id (backward compatibility).
	 */
	public function test_save_video_to_media_works_without_job_id() {
		// Skip if we can't create test files.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			$this->markTestSkipped( 'wp_upload_bits not available' );
		}

		$result = array(
			'video_data'   => 'fake video content',
			'prompt'       => 'Test no job id',
			'duration'     => 5,
			'aspect_ratio' => '16:9',
			'resolution'   => '720p',
			'model'        => 'veo-3.1-generate-preview',
			'provider'     => 'gemini',
		);

		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'save_video_to_media' );
		$method->setAccessible( true );

		// Call without job_id (backward compatibility).
		$save_result = $method->invoke( $this->service, $result, 1 );

		$this->assertIsArray( $save_result );
		$this->assertArrayHasKey( 'attachment_id', $save_result );

		$attachment_id = $save_result['attachment_id'];

		// Verify no job_id was stored.
		$stored_job_id = get_post_meta( $attachment_id, '_veo_job_id', true );
		$this->assertEmpty( $stored_job_id );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test media lookup sanitizes job_id to prevent injection.
	 */
	public function test_media_lookup_sanitizes_job_id() {
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'find_attachment_by_job_id' );
		$method->setAccessible( true );

		// Test with potentially malicious input.
		$malicious_id = "veo_test'; DROP TABLE wp_posts; --";
		$result       = $method->invoke( $this->service, $malicious_id );

		// Should return null (not found) without causing errors.
		$this->assertNull( $result );
	}
}
