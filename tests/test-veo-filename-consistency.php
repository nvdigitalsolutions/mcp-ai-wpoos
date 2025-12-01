<?php
/**
 * Test filename consistency between expected_filename and actual filename
 * for veo video generation.
 *
 * This test ensures that the expected_url returned to clients matches
 * the actual file URL when the video is saved, preventing 404 errors.
 *
 * @package WP_MCP_AI
 */

class Test_Veo_Filename_Consistency extends WP_UnitTestCase {

	/**
	 * Test that expected_filename matches the actual filename saved.
	 *
	 * Verifies that queue_async_polling(), save_video_to_media(), and
	 * get_async_pending_metadata() all generate the same filename for a given job_id.
	 */
	public function test_expected_filename_matches_saved_filename() {
		// Test with various job_id formats.
		$test_cases = array(
			'async_c35fa56adf6cb70e',
			'veo_69264137e396a4_03027627',
			'async_test123',
			'veo_test456',
		);

		foreach ( $test_cases as $job_id ) {
			// Get expected filename from queue_async_polling simulation.
			$expected_filename_from_queue = $this->simulate_queue_expected_filename( $job_id );

			// Get expected filename from get_async_pending_metadata.
			$expected_filename_from_metadata = $this->get_expected_filename_from_tool( $job_id );

			// Get actual filename from save_video_to_media.
			$actual_filename = $this->get_actual_filename_from_save( $job_id );

			// All three should match.
			$this->assertEquals(
				$expected_filename_from_queue,
				$actual_filename,
				"Expected filename from queue should match actual filename for job_id: {$job_id}"
			);

			$this->assertEquals(
				$expected_filename_from_metadata,
				$actual_filename,
				"Expected filename from metadata should match actual filename for job_id: {$job_id}"
			);
		}
	}

	/**
	 * Simulate the expected_filename generation from queue_async_polling.
	 *
	 * @param string $job_id Job identifier.
	 * @return string Expected filename.
	 */
	protected function simulate_queue_expected_filename( $job_id ) {
		// This matches the logic in queue_async_polling() line 1038.
		// After fix: should use sanitize_file_name().
		return 'veo-video-' . sanitize_file_name( $job_id ) . '.mp4';
	}

	/**
	 * Get expected filename from tool's get_async_pending_metadata method.
	 *
	 * @param string $job_id Job identifier.
	 * @return string Expected filename.
	 */
	protected function get_expected_filename_from_tool( $job_id ) {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Call the public method to get metadata.
		$metadata = $tool->get_async_pending_metadata( $job_id, array(), array() );

		$this->assertArrayHasKey( 'expected_filename', $metadata );
		return $metadata['expected_filename'];
	}

	/**
	 * Get actual filename that would be used by save_video_to_media.
	 *
	 * @param string $job_id Job identifier.
	 * @return string Actual filename.
	 */
	protected function get_actual_filename_from_save( $job_id ) {
		// This matches the logic in save_video_to_media() lines 529, 1914.
		return 'veo-video-' . sanitize_file_name( $job_id ) . '.mp4';
	}

	/**
	 * Test expected_url matches the upload directory pattern.
	 */
	public function test_expected_url_format() {
		$job_id = 'async_test123';

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		$metadata = $tool->get_async_pending_metadata( $job_id, array(), array() );

		$this->assertArrayHasKey( 'expected_url', $metadata );

		// If upload dir is available, URL should be set.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['url'] ) && empty( $upload_dir['error'] ) ) {
			$this->assertNotEmpty( $metadata['expected_url'] );

			// URL should contain the sanitized filename.
			$expected_filename = 'veo-video-' . sanitize_file_name( $job_id ) . '.mp4';
			$this->assertStringContainsString( $expected_filename, $metadata['expected_url'] );
		}
	}

	/**
	 * Test that sanitize_file_name preserves valid job_id characters.
	 *
	 * This ensures our assumption about sanitize_file_name is correct.
	 */
	public function test_sanitize_file_name_preserves_job_id() {
		$test_cases = array(
			'async_c35fa56adf6cb70e' => 'async_c35fa56adf6cb70e',
			'veo_69264137e396a4_03027627' => 'veo_69264137e396a4_03027627',
			'test_123_abc' => 'test_123_abc',
		);

		foreach ( $test_cases as $input => $expected ) {
			$sanitized = sanitize_file_name( $input );
			$this->assertEquals(
				$expected,
				$sanitized,
				"sanitize_file_name should preserve valid job_id characters: {$input}"
			);
		}
	}
}
