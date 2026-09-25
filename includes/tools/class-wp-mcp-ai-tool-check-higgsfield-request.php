<?php
/**
 * Tool for checking the status of a Higgsfield generation request.
 *
 * Higgsfield generation is asynchronous: every submission returns a
 * request_id that can be polled via GET /requests/{id}/status until a
 * terminal state (completed | failed | nsfw | canceled). This tool exposes
 * that lifecycle operation so callers can follow up on timed-out sync
 * generations or externally submitted requests.
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
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-higgsfield-client.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Checks the status of a Higgsfield generation request.
 */
class WP_MCP_AI_Tool_Check_Higgsfield_Request implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_higgsfield_request';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Higgsfield Request', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks the status of a Higgsfield generation request by its request_id. States: queued, in_progress, completed, failed, nsfw, canceled. Completed requests include the output video/images/audio URLs. Use this to poll a request started by generate_higgsfield_video or generate_higgsfield_image — especially after a timeout, which returns the request_id for follow-up.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Polling a Higgsfield request_id after a generation timeout, or checking a request submitted out-of-band.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Tracking plugin-internal async jobs (async_* IDs); use check_video_status. Canceling queued work; use cancel_higgsfield_request.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'generate_higgsfield_video', 'generate_higgsfield_image', 'cancel_higgsfield_request' ),
			'notes'           => __( 'Output URLs are only present once the request completes. Failed requests include a provider error message.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'request_id' => array(
					'type'        => 'string',
					'description' => __( 'The Higgsfield request ID (UUID) returned when the generation was submitted.', 'mcp-ai-wpoos' ),
					'minLength'   => 1,
				),
			),
			'required'             => array( 'request_id' ),
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
		// Gate 1 — sanitize at entry.
		if ( empty( $arguments['request_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_request_id',
				__( 'A Higgsfield request ID is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$request_id = sanitize_text_field( $arguments['request_id'] );

		$client = new WP_MCP_AI_Higgsfield_Client();
		$status = $client->get_request_status( $request_id );

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$state       = isset( $status['status'] ) ? $status['status'] : 'unknown';
		$is_terminal = in_array( $state, WP_MCP_AI_Higgsfield_Client::TERMINAL_STATUSES, true );

		$data = array(
			'request_id' => $request_id,
			'status'     => $state,
			'terminal'   => $is_terminal,
		);

		if ( ! empty( $status['error'] ) && is_string( $status['error'] ) ) {
			$data['error'] = sanitize_text_field( $status['error'] );
		}

		// Include output URLs when present.
		$outputs = $client->extract_outputs( $status );
		if ( ! empty( $outputs['video_url'] ) ) {
			$data['video_url'] = $outputs['video_url'];
		}
		if ( ! empty( $outputs['image_urls'] ) ) {
			$data['image_urls'] = $outputs['image_urls'];
		}
		if ( ! empty( $outputs['audio_urls'] ) ) {
			$data['audio_urls'] = $outputs['audio_urls'];
		}

		$message = 'completed' === $state
			? __( 'The request completed successfully.', 'mcp-ai-wpoos' )
			: sprintf(
				/* translators: %s: request state */
				__( 'Request status: %s.', 'mcp-ai-wpoos' ),
				$state
			);

		return array(
			'success' => true,
			'message' => $message,
			'data'    => $data,
		);
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
			'read',                // Reads request state.
			'requires-credentials', // Requires Higgsfield API key pair.
			'external-api',        // Makes external API requests.
			'network-dependent',   // Requires internet connection.
		);
	}
}
