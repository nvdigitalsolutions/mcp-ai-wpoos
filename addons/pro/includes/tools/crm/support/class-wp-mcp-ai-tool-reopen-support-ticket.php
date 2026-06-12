<?php
/**
 * Reopen Support Ticket Tool
 *
 * Reopens a resolved or closed support ticket, increments reopen
 * counter, and clears the resolution timestamp.
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
class WP_MCP_AI_Tool_Reopen_Support_Ticket implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'The Reopen Support Ticket tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'reopen_support_ticket';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Reopen Support Ticket', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Reopen a resolved or closed support ticket. Moves back to In Progress, increments reopen counter.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ticket_id' => array(
					'type'        => 'integer',
					'description' => __( 'Support ticket post ID.', 'mcp-ai-wpoos-pro' ),
				),
				'reason'    => array(
					'type'        => 'string',
					'description' => __( 'Reason for reopening (adds as internal note).', 'mcp-ai-wpoos-pro' ),
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
		if ( ! $ticket || 'mcp_ai_ticket' !== $ticket->post_type ) {
			return new WP_Error( 'ticket_not_found', __( 'Support ticket not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$current_status = get_post_meta( $ticket_id, '_ticket_status', true );
		if ( ! in_array( $current_status, array( 'resolved', 'closed' ), true ) ) {
			return new WP_Error( 'not_resolved', __( 'Ticket is not in a resolved or closed state.', 'mcp-ai-wpoos-pro' ) );
		}

		// Transition back to In Progress.
		$old_status = $current_status;
		update_post_meta( $ticket_id, '_ticket_status', 'in_progress' );
		update_post_meta( $ticket_id, '_ticket_sla_resolved_at', '' );

		// Increment reopen counter.
		$reopen_count = (int) get_post_meta( $ticket_id, '_ticket_reopened_count', true );
		update_post_meta( $ticket_id, '_ticket_reopened_count', $reopen_count + 1 );

		// Recalculate SLA.
		if ( class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ) {
			WP_MCP_AI_Support_Ticket_CPT::recalc_ticket_sla( $ticket_id );
		}

		// Add reason as internal note.
		$reason = sanitize_textarea_field( $arguments['reason'] ?? '' );
		if ( $reason ) {
			$activity_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_crm_activity',
					'post_title'   => sprintf(
						/* translators: %d: ticket ID */
						__( 'Ticket #%d reopened', 'mcp-ai-wpoos-pro' ),
						$ticket_id
					),
					'post_content' => $reason,
					'post_status'  => 'publish',
				)
			);
			if ( ! is_wp_error( $activity_id ) ) {
				update_post_meta( $activity_id, 'activity_type', 'note' );
				update_post_meta( $activity_id, 'related_type', 'ticket' );
				update_post_meta( $activity_id, 'related_id', $ticket_id );
			}
		}

		// Fire hooks.
		do_action( 'wp_mcp_ai_crm_ticket_status_changed', $ticket_id, $old_status, 'in_progress' );
		do_action( 'wp_mcp_ai_crm_ticket_reopened', $ticket_id );

		return $this->format_success_response(
			__( 'Support ticket reopened.', 'mcp-ai-wpoos-pro' ),
			array(
				'ticket_id'       => $ticket_id,
				'reopen_count'    => $reopen_count + 1,
				'previous_status' => $old_status,
				'edit_url'        => get_edit_post_link( $ticket_id, 'raw' ),
			)
		);
	}
}
