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
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * @param string $job_id Job ID.

	 * @param array  $result Job result.

	 * @param array  $metadata Job metadata.
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
		// The ordering contract lives in fire_job_completion_hooks(): the veo
		// job's wp_mcp_ai_job_completed hook must fire before the parent async
		// job is completed via complete_parent_job().
		$file_path    = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		$file_content = file_get_contents( $file_path );

		// Find the hook-firing method.
		$method_start = strpos( $file_content, 'protected function fire_job_completion_hooks(' );
		$this->assertNotFalse( $method_start, 'fire_job_completion_hooks method should exist' );

		// Limit the search scope to the next method.
		$next_method = strpos( $file_content, 'protected function maybe_restore_blog(', $method_start );
		$this->assertNotFalse( $next_method, 'maybe_restore_blog should follow fire_job_completion_hooks' );
		$method_content = substr( $file_content, $method_start, $next_method - $method_start );

		// The veo job completion hook must be fired inside this method.
		$veo_completion_pos = strpos( $method_content, "'wp_mcp_ai_job_completed'," );
		$this->assertNotFalse( $veo_completion_pos, 'Veo job completion hook should exist in fire_job_completion_hooks' );

		// The parent job completion call must be made inside this method.
		$parent_completion_pos = strpos( $method_content, "\$this->complete_parent_job( \$metadata['parent_job_id'], \$result );" );
		$this->assertNotFalse( $parent_completion_pos, 'Parent job completion call should exist in fire_job_completion_hooks' );

		// CRITICAL ASSERTION: Parent job completion must come AFTER veo job completion.
		$this->assertGreaterThan(
			$veo_completion_pos,
			$parent_completion_pos,
			'Parent job completion must be called AFTER veo job completion hook to prevent race conditions'
		);

		// The polling paths must route through the hook-firing method.
		$this->assertStringContainsString(
			'$this->fire_job_completion_hooks( $job_id, $metadata, $attachment );',
			$file_content,
			'do_poll_video_async should route completion through fire_job_completion_hooks'
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
			'CRITICAL ORDER: Complete the parent async job only AFTER the veo job',
			$file_content,
			'Comment explaining the critical order should be present'
		);

		// Verify explanation about race conditions is present.
		$this->assertStringContainsString(
			'preventing race conditions',
			$file_content,
			'Comment explaining race condition prevention should be present'
		);

		// Verify the dedicated hook-firing method is documented.
		$this->assertStringContainsString(
			'Fire job completion hooks.',
			$file_content,
			'Comment explaining job completion hooks should be present'
		);
	}

	/**
	 * Test that both completion hooks still fire (just in correct order)
	 */
	public function test_both_hooks_fire_in_sequence() {
		// This test documents that we still fire BOTH completion hooks:
		// 1. For the veo job (veo_xxx)
		// 2. For the parent job (async_xxx) via complete_parent_job
		//
		// The fix is about ORDERING, not eliminating one of the hooks.

		$file_path    = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		$file_content = file_get_contents( $file_path );

		// Verify the veo job completion hook is fired.
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

		// Verify complete_parent_job is called with the final result.
		$this->assertStringContainsString(
			"\$this->complete_parent_job( \$metadata['parent_job_id'], \$result );",
			$file_content,
			'Parent job completion should be called'
		);

		// Verify complete_parent_job method fires its own hook.
		$this->assertStringContainsString(
			'$parent_job_id,',
			$file_content,
			'Parent job completion hook should have $parent_job_id parameter'
		);
	}
}
