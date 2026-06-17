<?php
/**
 * Resolve Support Ticket Tool
 *
 * Marks a ticket as resolved with resolution type, closing note,
 * and fires resolution hooks.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * {@inheritdoc}
 */
class WP_MCP_AI_Tool_Resolve_Support_Ticket implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Resolve Support Ticket tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'resolve_support_ticket';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Resolve Support Ticket', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Mark a support ticket as resolved with resolution type and closing note. Fires resolution hooks.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ticket_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Support ticket post ID.', 'mcp-ai-wpoos-pro' ),
				),
				'resolution_type' => array(
					'type'        => 'string',
					'description' => __( 'Resolution type: solved, not_reproducible, wont_fix, duplicate, third_party.', 'mcp-ai-wpoos-pro' ),
				),
				'resolution_note' => array(
					'type'        => 'string',
					'description' => __( 'Closing resolution note.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'ticket_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$ticket_id = absint( $arguments['ticket_id'] ?? 0 );

		if ( ! $ticket_id ) {
			return new WP_Error( 'invalid_ticket', __( 'A valid ticket ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$ticket = get_post( $ticket_id );
		if ( ! $ticket || 'mcp_ai_support_ticket' !== $ticket->post_type ) {
			return new WP_Error( 'ticket_not_found', __( 'Support ticket not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$current_status = get_post_meta( $ticket_id, '_ticket_status', true );
		if ( 'closed' === $current_status ) {
			return new WP_Error( 'already_closed', __( 'Ticket is already closed.', 'mcp-ai-wpoos-pro' ) );
		}

		$resolution_type = sanitize_key( $arguments['resolution_type'] ?? 'solved' );
		$valid_types     = array( 'solved', 'not_reproducible', 'wont_fix', 'duplicate', 'third_party' );
		if ( ! in_array( $resolution_type, $valid_types, true ) ) {
			$resolution_type = 'solved';
		}

		$resolution_note = sanitize_textarea_field( $arguments['resolution_note'] ?? '' );

		// Transition to resolved.
		$old_status = $current_status ? $current_status : 'new';
		update_post_meta( $ticket_id, '_ticket_status', 'resolved' );
		update_post_meta( $ticket_id, '_ticket_resolution_type', $resolution_type );
		update_post_meta( $ticket_id, '_ticket_resolution_note', $resolution_note );
		update_post_meta( $ticket_id, '_ticket_sla_resolved_at', current_time( 'mysql' ) );
		update_post_meta( $ticket_id, '_ticket_sla_status', 'on_track' );

		// Fire hooks.
		do_action( 'wp_mcp_ai_crm_ticket_status_changed', $ticket_id, $old_status, 'resolved' );
		do_action( 'wp_mcp_ai_crm_ticket_resolved', $ticket_id, $resolution_type );

		return $this->format_success_response(
			__( 'Support ticket resolved.', 'mcp-ai-wpoos-pro' ),
			array(
				'ticket_id'       => $ticket_id,
				'resolution_type' => $resolution_type,
				'edit_url'        => get_edit_post_link( $ticket_id, 'raw' ),
			)
		);
	}
}
