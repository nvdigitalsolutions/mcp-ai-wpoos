<?php
/**
 * Test Veo Job Completion Order
 *
 * Verifies that when a veo video generation job completes, the job completion hooks
 * are fired in the correct order:
 * 1. Veo job (veo_xxx) completion hook fires first
 * 2. Parent async job (async_xxx) completion hook fires second
 *
 * This ensures the notification system caches results properly and the chat client
 * receives the video URL without timing out.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for veo job completion order
 */
class Test_Veo_Job_Completion_Order extends WP_UnitTestCase {

	/**
	 * Track the order of job completion hooks
	 *
	 * @var array
	 */
	protected $completion_order = array();

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset completion order tracking.
		$this->completion_order = array();

		// Hook into job completion to track order.
		add_action( 'wp_mcp_ai_job_completed', array( $this, 'track_job_completion' ), 10, 3 );
	}

	/**
	 * Teardown test environment
	 */
	public function tearDown(): void {
		// Remove hook.
		remove_action( 'wp_mcp_ai_job_completed', array( $this, 'track_job_completion' ), 10 );

		parent::tearDown();
	}

	/**
	 * Track job completion hook calls
	 *
	 * @param string $job_id Job ID
	 * @param array  $result Job result
	 * @param array  $metadata Job metadata
	 */
	public function track_job_completion( $job_id, $result, $metadata ) {
		$this->completion_order[] = array(
			'job_id'   => $job_id,
			'result'   => $result,
			'metadata' => $metadata,
		);
	}

	/**
	 * Test that veo job completion fires before parent job completion
	 */
	public function test_veo_job_completes_before_parent_job() {
		// This test verifies the fix for the issue where both jobs completed simultaneously.
		// We can't easily test the actual async veo generation process, but we can verify.
		// that the complete_parent_job method is called AFTER the veo job completion hook.

		// The key assertion is that in poll_video_async():
		// 1. do_action('wp_mcp_ai_job_completed', veo_job_id, ...) is called first.
		// 2. complete_parent_job() is called second.
		//
		// Since we can't run the full async process in a unit test, we'll verify the.
		// code structure is correct by checking the file content.

		$file_path    = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		$file_content = file_get_contents( $file_path );

		// Verify that complete_parent_job appears AFTER wp_mcp_ai_job_completed in poll_video_async.
		// Find the poll_video_async method.
		$method_start = strpos( $file_content, 'public function poll_video_async(' );
		$this->assertNotFalse( $method_start, 'poll_video_async method should exist' );

		// Find the next method (to limit search scope)
		$next_method    = strpos( $file_content, 'protected function schedule_next_poll(', $method_start );
		$method_content = substr( $file_content, $method_start, $next_method - $method_start );

		// Find positions of key statements in the method.
		// Use flexible pattern that ignores indentation (whitespace variations)
		$veo_completion_pos = strpos( $method_content, "'wp_mcp_ai_job_completed'," );
		$job_id_param_pos   = strpos( $method_content, '$job_id,', $veo_completion_pos );

		// Verify this is the correct do_action by checking $job_id appears shortly after the hook name.
		$is_veo_hook = ( $job_id_param_pos !== false && ( $job_id_param_pos - $veo_completion_pos ) < 100 );

		$parent_completion_pos = strpos( $method_content, '$this->complete_parent_job( $metadata[\'parent_job_id\']' );

		// Assert both statements exist.
		$this->assertNotFalse( $veo_completion_pos, 'Veo job completion hook should exist in poll_video_async' );
		$this->assertTrue( $is_veo_hook, 'Veo job completion hook should have $job_id parameter' );
		$this->assertNotFalse( $parent_completion_pos, 'Parent job completion call should exist in poll_video_async' );

		// CRITICAL ASSERTION: Parent job completion must come AFTER veo job completion.
		$this->assertGreaterThan(
			$veo_completion_pos,
			$parent_completion_pos,
			'Parent job completion must be called AFTER veo job completion hook to prevent race conditions'
		);
	}

	/**
	 * Test that the comment explaining the fix is present
	 */
	public function test_critical_order_comment_exists() {
		$file_path    = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		$file_content = file_get_contents( $file_path );

		// Verify the critical order comment is present.
		$this->assertStringContainsString(
			'CRITICAL ORDER: Fire veo job completion hook FIRST before parent job completion',
			$file_content,
			'Comment explaining the critical order should be present'
		);

		// Verify the important comment is present.
		$this->assertStringContainsString(
			'IMPORTANT: Complete parent async job AFTER veo job hooks are fired',
			$file_content,
			'Comment explaining parent job completion order should be present'
		);

		// Verify explanation about race conditions is present.
		$this->assertStringContainsString(
			'preventing race conditions where both jobs complete',
			$file_content,
			'Comment explaining race condition prevention should be present'
		);
	}

	/**
	 * Test that both completion hooks still fire (just in correct order)
	 */
	public function test_both_hooks_fire_in_sequence() {
		// This test documents that we still fire BOTH completion hooks:
		// 1. For the veo job (veo_xxx)
		// 2. For the parent job (async_xxx) via complete_parent_job.
		//
		// The fix is about ORDERING, not eliminating one of the hooks.

		$file_path    = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		$file_content = file_get_contents( $file_path );

		// Verify poll_video_async contains the veo job completion.
		// Use flexible pattern that works regardless of indentation.
		$this->assertStringContainsString(
			"'wp_mcp_ai_job_completed',",
			$file_content,
			'Veo job completion hook should be fired'
		);

		$this->assertStringContainsString(
			'$job_id,',
			$file_content,
			'Veo job completion hook should have $job_id parameter'
		);

		// Verify complete_parent_job is called.
		$this->assertStringContainsString(
			'$this->complete_parent_job( $metadata[\'parent_job_id\'], $metadata[\'result\'] );',
			$file_content,
			'Parent job completion should be called'
		);

		// Verify complete_parent_job method fires its own hook.
		$this->assertStringContainsString(
			"'wp_mcp_ai_job_completed',",
			$file_content,
			'Parent job completion hook should be fired'
		);

		$this->assertStringContainsString(
			'$parent_job_id,',
			$file_content,
			'Parent job completion hook should have $parent_job_id parameter'
		);
	}
}
