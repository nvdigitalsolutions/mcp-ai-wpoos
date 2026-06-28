<?php
/**
 * Client Communication Logger Tool
 *
 * Logs client communications including emails, phone calls, meetings, and letters.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs and tracks client communications.
 */
class WP_MCP_AI_Tool_LF_Client_Communication_Logger implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_client_communication_logger';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Client Communication Logger', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Logs client communications (emails, phone calls, meetings, letters, texts) with date, summary, participants, and optional matter association.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'client_id'          => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the client record.', 'mcp-ai-wpoos-pro' ),
				),
				'matter_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Optional associated matter ID.', 'mcp-ai-wpoos-pro' ),
				),
				'communication_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of communication.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'email', 'phone', 'meeting', 'letter', 'text' ),
				),
				'summary'            => array(
					'type'        => 'string',
					'description' => __( 'Summary of the communication.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'date'               => array(
					'type'        => 'string',
					'description' => __( 'Date of the communication (YYYY-MM-DD). Defaults to today.', 'mcp-ai-wpoos-pro' ),
				),
				'participants'       => array(
					'type'        => 'array',
					'description' => __( 'List of participants in the communication.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'client_id', 'communication_type', 'summary' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$client_id    = isset( $arguments['client_id'] ) ? absint( $arguments['client_id'] ) : 0;
		$matter_id    = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$comm_type    = isset( $arguments['communication_type'] ) ? sanitize_text_field( $arguments['communication_type'] ) : '';
		$summary      = isset( $arguments['summary'] ) ? sanitize_textarea_field( $arguments['summary'] ) : '';
		$date         = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' );
		$participants = array();
		if ( ! empty( $arguments['participants'] ) && is_array( $arguments['participants'] ) ) {
			$participants = array_map( 'sanitize_text_field', $arguments['participants'] );
		}

		if ( ! $client_id || empty( $comm_type ) || empty( $summary ) ) {
			return new WP_Error( 'missing_required', __( 'Client ID, communication type, and summary are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$client_post = get_post( $client_id );
		if ( ! $client_post || 'mcp_ai_lf_client' !== $client_post->post_type ) {
			return new WP_Error( 'not_found', __( 'Client record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_types = array( 'email', 'phone', 'meeting', 'letter', 'text' );
		if ( ! in_array( $comm_type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_param', __( 'Invalid communication type.', 'mcp-ai-wpoos-pro' ) );
		}

		$communications = get_post_meta( $client_id, '_lf_communications', true );
		if ( ! is_array( $communications ) ) {
			$communications = array();
		}

		$entry = array(
			'id'           => wp_generate_uuid4(),
			'type'         => $comm_type,
			'summary'      => $summary,
			'date'         => $date,
			'matter_id'    => $matter_id,
			'participants' => $participants,
			'logged_by'    => $uid,
			'logged_at'    => current_time( 'Y-m-d H:i:s' ),
		);

		$communications[] = $entry;
		update_post_meta( $client_id, '_lf_communications', $communications );

		return array(
			'success'    => true,
			'message'    => __( 'Communication logged successfully. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
			'data'       => array(
				'communication_id' => $entry['id'],
				'client_id'        => $client_id,
				'client_name'      => $client_post->post_title,
				'type'             => $comm_type,
				'date'             => $date,
				'total_logged'     => count( $communications ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
