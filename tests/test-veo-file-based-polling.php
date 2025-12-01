<?php
/**
 * Test file-based polling for Veo video generation.
 *
 * Validates that video completion can be detected by checking for file creation
 * in the uploads directory, in addition to polling the Gemini API.
 *
 * @package WP_MCP_AI
 */

/**
 * Test file-based polling for video generation completion.
 */
class Test_Veo_File_Based_Polling extends WP_UnitTestCase {
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

		// Initialize service.
		WP_MCP_AI_Gemini_Video_Generation_Service::init();
		$this->service = new WP_MCP_AI_Gemini_Video_Generation_Service();
	}

	/**
	 * Test that expected_filename is stored in job metadata.
	 */
	public function test_expected_filename_stored_in_metadata() {
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$operation = array(
			'operation_name' => 'operations/test-op-123',
			'metadata'       => array(),
		);

		$args = array(
			'prompt'  => 'Test video prompt',
			'user_id' => 1,
		);

		$result = $method->invoke( $this->service, $operation, $args );

		// Verify job ID was returned.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['async'] );
		$this->assertArrayHasKey( 'job_id', $result );

		$job_id = $result['job_id'];

		// Verify transient was created with expected_filename.
		$transient_key = WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id;
		$metadata      = get_transient( $transient_key );

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'expected_filename', $metadata );

		// Verify filename format: veo-video-{job_id}.mp4
		$expected_filename = 'veo-video-' . $job_id . '.mp4';
		$this->assertEquals( $expected_filename, $metadata['expected_filename'] );
	}

	/**
	 * Test that save_video_to_media uses job_id in filename when provided.
	 */
	public function test_save_video_uses_job_id_in_filename() {
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'save_video_to_media' );
		$method->setAccessible( true );

		$job_id = 'veo_test_123';
		$result = array(
			'video_data'   => 'fake video data',
			'prompt'       => 'Test prompt',
			'duration'     => 5,
			'aspect_ratio' => '3:2',
			'resolution'   => '720p',
			'model'        => 'veo-3.1-generate-preview',
			'provider'     => 'gemini',
		);

		$save_result = $method->invoke( $this->service, $result, 1, $job_id );

		// Verify save was successful.
		$this->assertIsArray( $save_result );
		$this->assertArrayHasKey( 'attachment_id', $save_result );

		// Get the attachment and verify filename.
		$attachment_id = $save_result['attachment_id'];
		$file_path     = get_attached_file( $attachment_id );
		$filename      = basename( $file_path );

		// Verify filename uses job_id format.
		$expected_filename = 'veo-video-' . $job_id . '.mp4';
		$this->assertEquals( $expected_filename, $filename );

		// Verify job_id is stored in metadata.
		$stored_job_id = get_post_meta( $attachment_id, '_veo_job_id', true );
		$this->assertEquals( $job_id, $stored_job_id );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test that check_for_created_video_file detects existing video.
	 */
	public function test_check_for_created_video_file_detects_existing_video() {
		// First, create a video attachment with a specific filename.
		$job_id            = 'veo_test_456';
		$expected_filename = 'veo-video-' . $job_id . '.mp4';

		// Create a temporary video file.
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/' . $expected_filename;
		file_put_contents( $file_path, 'fake video data' );

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'video/mp4',
			'post_title'     => 'Test Video',
			'post_content'   => 'Test prompt',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		// Add metadata.
		update_post_meta( $attachment_id, '_veo_prompt', 'Test prompt' );
		update_post_meta( $attachment_id, '_veo_duration', 5 );
		update_post_meta( $attachment_id, '_veo_aspect_ratio', '3:2' );
		update_post_meta( $attachment_id, '_veo_resolution', '720p' );
		update_post_meta( $attachment_id, '_veo_model', 'veo-3.1-generate-preview' );
		update_post_meta( $attachment_id, '_veo_provider', 'gemini' );

		// Now test the check_for_created_video_file method.
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'check_for_created_video_file' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->service, $expected_filename, $job_id );

		// Verify file was detected.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( $attachment_id, $result['attachment_id'] );
		$this->assertEquals( $job_id, $result['job_id'] );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'video_url', $result );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}
	}

	/**
	 * Test that check_for_created_video_file returns false when file doesn't exist.
	 */
	public function test_check_for_created_video_file_returns_false_when_not_found() {
		$job_id            = 'veo_nonexistent_789';
		$expected_filename = 'veo-video-' . $job_id . '.mp4';

		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'check_for_created_video_file' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->service, $expected_filename, $job_id );

		// Verify no file was detected.
		$this->assertFalse( $result );
	}

	/**
	 * Test that poll_video_async checks for file creation first.
	 *
	 * This test verifies the integration between poll_video_async and
	 * check_for_created_video_file by simulating a scenario where the
	 * video file is created externally while polling is in progress.
	 */
	public function test_poll_video_async_uses_file_based_detection() {
		// Create a mock transient with job metadata.
		$job_id            = 'veo_test_poll_123';
		$expected_filename = 'veo-video-' . $job_id . '.mp4';

		$metadata = array(
			'job_id'            => $job_id,
			'operation_name'    => 'operations/test-op',
			'model'             => 'veo-3.1-generate-preview',
			'args'              => array(
				'prompt'  => 'Test prompt',
				'user_id' => 1,
			),
			'status'            => 'pending',
			'queued_at'         => time(),
			'poll_attempt'      => 0,
			'max_attempts'      => 60,
			'expected_filename' => $expected_filename,
		);

		set_transient(
			WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id,
			$metadata,
			DAY_IN_SECONDS
		);

		// Create the video file before polling starts.
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/' . $expected_filename;
		file_put_contents( $file_path, 'fake video data' );

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'video/mp4',
			'post_title'     => 'Test Video',
			'post_content'   => 'Test prompt',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		// Add metadata.
		update_post_meta( $attachment_id, '_veo_prompt', 'Test prompt' );
		update_post_meta( $attachment_id, '_veo_duration', 5 );
		update_post_meta( $attachment_id, '_veo_aspect_ratio', '3:2' );
		update_post_meta( $attachment_id, '_veo_resolution', '720p' );
		update_post_meta( $attachment_id, '_veo_model', 'veo-3.1-generate-preview' );
		update_post_meta( $attachment_id, '_veo_provider', 'gemini' );

		// Track hook fires.
		$hook_fired   = false;
		$hook_job_id  = null;
		$hook_result  = null;
		$hook_tracker = function ( $fired_job_id, $result ) use ( &$hook_fired, &$hook_job_id, &$hook_result ) {
			$hook_fired  = true;
			$hook_job_id = $fired_job_id;
			$hook_result = $result;
		};
		add_action( 'wp_mcp_ai_job_completed', $hook_tracker, 10, 2 );

		// Now call poll_video_async.
		$this->service->poll_video_async( $job_id );

		// Verify completion hook was fired.
		$this->assertTrue( $hook_fired, 'Completion hook should have been fired' );
		$this->assertEquals( $job_id, $hook_job_id );
		$this->assertIsArray( $hook_result );
		$this->assertTrue( $hook_result['success'] );
		$this->assertEquals( $attachment_id, $hook_result['attachment_id'] );

		// Verify transient was updated to completed status.
		$updated_metadata = get_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		$this->assertEquals( 'completed', $updated_metadata['status'] );
		$this->assertArrayHasKey( 'result', $updated_metadata );

		// Clean up.
		remove_action( 'wp_mcp_ai_job_completed', $hook_tracker );
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up any test transients using WordPress API.
		// Get all test transient keys we created.
		$test_job_ids = array(
			'veo_test_123',
			'veo_test_456',
			'veo_nonexistent_789',
			'veo_test_poll_123',
		);

		foreach ( $test_job_ids as $job_id ) {
			$transient_key = WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id;
			delete_transient( $transient_key );
		}

		// Also clean up any transients that may have been created dynamically during tests.
		// Use a more targeted approach than direct DB query.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional cleanup in test teardown, targeted to test-specific transients only.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . 'veo_test_' ) . '%'
			)
		);
	}
}
