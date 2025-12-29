<?php
/**
 * Interface for tools that provide pre-execution metadata for async responses.
 *
 * When a tool is queued for async execution by the orchestrator, tools implementing
 * this interface can provide metadata that should be included in the immediate
 * pending response. This is useful for:
 *
 * - Video generation: Provide expected_url and expected_filename so the UI can
 *   show a placeholder video element before the actual video is ready.
 * - File generation: Provide expected file paths or download URLs.
 * - Long-running tasks: Provide estimated completion times or progress indicators.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for tools that provide pre-execution metadata for async responses.
 *
 * @since 1.0.0
 */
interface WP_MCP_AI_Tool_Async_Metadata_Interface {
	/**
	 * Get pre-execution metadata to include in async pending response.
	 *
	 * This method is called when the orchestrator queues a tool for async execution.
	 * The returned metadata is merged into the pending response, allowing the UI
	 * to display placeholders or preliminary information before the tool completes.
	 *
	 * Example return value for video generation:
	 * array(
	 *     'expected_url'      => 'https://example.com/wp-content/uploads/2025/11/veo-video-abc123.mp4',
	 *     'expected_filename' => 'veo-video-abc123.mp4',
	 *     'estimated_time'    => 300, // seconds.
	 * )
	 *
	 * @param string $job_id    The async job identifier.
	 * @param array  $arguments Tool arguments that will be passed to execute().
	 * @param array  $context   Execution context.
	 * @return array Associative array of metadata to include in pending response.
	 */
	public function get_async_pending_metadata( $job_id, array $arguments = array(), array $context = array() );
}
