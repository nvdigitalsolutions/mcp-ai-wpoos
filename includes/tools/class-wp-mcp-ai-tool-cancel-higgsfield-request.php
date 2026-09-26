<?php
/**
 * Tool for canceling a queued Higgsfield generation request.
 *
 * Higgsfield requests that have not started processing can be canceled via
 * POST /requests/{id}/cancel (202 = canceled; 400 = already started). This
 * tool exposes that lifecycle operation for queue management — mirroring the
 * cancel-first-class pattern used by fal.ai and Replicate.
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
 * Cancels a queued Higgsfield generation request.
 */
class WP_MCP_AI_Tool_Cancel_Higgsfield_Request implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'cancel_higgsfield_request';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Cancel Higgsfield Request', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Cancels a queued Higgsfield generation request that has not started processing. Returns an error if the request has already started (400) or does not belong to this account (404). Canceling is irreversible.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Stopping a queued Higgsfield request before it starts processing and incurs cost.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Requests already in progress — the provider rejects cancellation once processing starts. Checking a request\'s state; use check_higgsfield_request.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'check_higgsfield_request', 'generate_higgsfield_video', 'generate_higgsfield_image' ),
			'notes'           => __( 'Canceled and failed requests are not billed; provider-side credit returns automatically.', 'mcp-ai-wpoos' ),
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
		$result = $client->cancel_request( $request_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => __( 'The request was canceled successfully.', 'mcp-ai-wpoos' ),
			'data'    => array(
				'request_id' => $request_id,
				'status'     => 'canceled',
			),
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
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Mutates provider-side state.
			'destructive',          // Cancellation is irreversible.
			'requires-credentials', // Requires Higgsfield API key pair.
			'external-api',         // Makes external API requests.
			'network-dependent',    // Requires internet connection.
		);
	}
}
