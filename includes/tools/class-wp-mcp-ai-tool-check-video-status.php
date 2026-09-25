<?php
/**
 * Tool for checking video generation status.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Checks the status of async video generation jobs.
 */
class WP_MCP_AI_Tool_Check_Video_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'Check Video Generation Status', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks the status of an async video generation job. Use this to poll for completion after calling generate_veo_video, generate_sora_video, or generate_higgsfield_video in async mode.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Polling an async video generation job for completion after starting generate_veo_video, generate_sora_video, or generate_higgsfield_video in async mode.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Analyzing existing video content; use analyze_video. For batch job status use get_batch_status.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'generate_veo_video', 'generate_sora_video', 'generate_higgsfield_video', 'analyze_video' ),
			'notes'           => __( 'Pass the job_id returned by the generation tool. Completed jobs return an attachment_id when saved to the media library.', 'mcp-ai-wpoos' ),
		);
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
					'description' => __( 'The job ID returned from generate_veo_video when using async mode.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'job_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
				__( 'Job ID is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$job_id = sanitize_text_field( $arguments['job_id'] );

		// Async-executor job IDs (async_* — e.g. generate_higgsfield_video) are
		// resolved through the tool async executor rather than the Gemini service.
		if ( 0 === strpos( $job_id, 'async_' ) && class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

			$executor = new WP_MCP_AI_Tool_Async_Executor();
			$result   = $executor->get_result( $job_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$status = isset( $result['status'] ) ? sanitize_key( $result['status'] ) : 'unknown';

			if ( 'completed' === $status ) {
				$tool_result = isset( $result['result'] ) ? $result['result'] : array();

				if ( is_array( $tool_result ) && ! empty( $tool_result['attachment_id'] ) ) {
					return array(
						'success'       => true,
						'status'        => 'completed',
						'job_id'        => $job_id,
						'attachment_id' => $tool_result['attachment_id'],
						'url'           => isset( $tool_result['url'] ) ? $tool_result['url'] : wp_get_attachment_url( $tool_result['attachment_id'] ),
						'message'       => __( 'Video generation completed successfully.', 'mcp-ai-wpoos' ),
					);
				}

				return array(
					'success' => true,
					'status'  => 'completed',
					'job_id'  => $job_id,
					'result'  => isset( $result['result'] ) ? $result['result'] : null,
					'message' => __( 'Video generation completed successfully.', 'mcp-ai-wpoos' ),
				);
			}

			return array(
				'success' => true,
				'job_id'  => $job_id,
				'status'  => $status,
				'message' => $this->get_status_message( $status ),
			);
		}

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
					'message'       => __( 'Video generation completed successfully.', 'mcp-ai-wpoos' ),
				);
			}

			// Return general completion info.
			return array(
				'success' => true,
				'status'  => 'completed',
				'job_id'  => $job_id,
				'result'  => $result,
				'message' => __( 'Video generation completed successfully.', 'mcp-ai-wpoos' ),
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
				return __( 'Video generation request is queued and will start shortly.', 'mcp-ai-wpoos' );
			case 'polling':
				return __( 'Video is being generated. Please check again in a few seconds.', 'mcp-ai-wpoos' );
			case 'completed':
				return __( 'Video generation completed successfully.', 'mcp-ai-wpoos' );
			case 'failed':
				return __( 'Video generation failed.', 'mcp-ai-wpoos' );
			default:
				return __( 'Unknown status.', 'mcp-ai-wpoos' );
		}
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'content_publishing',

			'pattern_compatibility' => array( 'sequential' ),

			'profession_tags'       => array( 'video_producer', 'content_creator' ),

			'risk_level'            => 'info',

		);
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
