<?php
/**
 * Tool for checking video generation status.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Checks the status of async video generation jobs.
 */
class WP_MCP_AI_Tool_Check_Video_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_video_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Video Generation Status', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks the status of an async video generation job. Use this to poll for completion after calling generate_veo_video in async mode.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'job_id' => array(
					'type'        => 'string',
					'description' => __( 'The job ID returned from generate_veo_video when using async mode.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'job_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate job_id.
		if ( empty( $arguments['job_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_job_id',
				__( 'Job ID is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$job_id = sanitize_text_field( $arguments['job_id'] );

		// Load the video generation service.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Get job status.
		$status = $service->get_async_status( $job_id );

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		// If completed, include result details.
		if ( 'completed' === $status['status'] && isset( $status['result'] ) ) {
			$result = $status['result'];

			// If save_to_media was requested in original args, the video should already be in media library.
			// Return attachment info if available.
			if ( isset( $result['attachment_id'] ) ) {
				return array(
					'success'       => true,
					'status'        => 'completed',
					'job_id'        => $job_id,
					'attachment_id' => $result['attachment_id'],
					'url'           => isset( $result['url'] ) ? $result['url'] : wp_get_attachment_url( $result['attachment_id'] ),
					'message'       => __( 'Video generation completed successfully.', 'wp-mcp-ai' ),
				);
			}

			// Return general completion info.
			return array(
				'success' => true,
				'status'  => 'completed',
				'job_id'  => $job_id,
				'result'  => $result,
				'message' => __( 'Video generation completed successfully.', 'wp-mcp-ai' ),
			);
		}

		// Return current status.
		return array(
			'success'      => true,
			'job_id'       => $job_id,
			'status'       => $status['status'],
			'poll_attempt' => $status['poll_attempt'],
			'max_attempts' => $status['max_attempts'],
			'message'      => $this->get_status_message( $status['status'] ),
		);
	}

	/**
	 * Get human-readable status message.
	 *
	 * @param string $status Job status.
	 * @return string Status message.
	 */
	protected function get_status_message( $status ) {
		switch ( $status ) {
			case 'pending':
				return __( 'Video generation request is queued and will start shortly.', 'wp-mcp-ai' );
			case 'polling':
				return __( 'Video is being generated. Please check again in a few seconds.', 'wp-mcp-ai' );
			case 'completed':
				return __( 'Video generation completed successfully.', 'wp-mcp-ai' );
			case 'failed':
				return __( 'Video generation failed.', 'wp-mcp-ai' );
			default:
				return __( 'Unknown status.', 'wp-mcp-ai' );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read',             // Reads job status.
			'requires-credentials', // Requires Gemini API key.
		);
	}
}
