<?php
/**
 * Escalate Support Ticket Tool
 *
 * Bumps a ticket's priority and optionally notifies the assignee
 * or manager about the escalation.
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
class WP_MCP_AI_Tool_Escalate_Support_Ticket implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'The Escalate Support Ticket tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'escalate_support_ticket';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Escalate Support Ticket', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Escalate a support ticket by bumping priority and optionally notifying the assignee/manager.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ticket_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Support ticket post ID.', 'mcp-ai-wpoos-pro' ),
				),
				'new_priority' => array(
					'type'        => 'string',
					'description' => __( 'Target priority level (must be higher than current).', 'mcp-ai-wpoos-pro' ),
				),
				'reason'       => array(
					'type'        => 'string',
					'description' => __( 'Reason for escalation (adds as internal note).', 'mcp-ai-wpoos-pro' ),
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

		$current_priority = get_post_meta( $ticket_id, '_ticket_priority', true ) ? get_post_meta( $ticket_id, '_ticket_priority', true ) : 'p2_high';
		$new_priority     = sanitize_key( $arguments['new_priority'] ?? 'p1_critical' );

		$priority_order = array(
			'p4_low'      => 0,
			'p3_medium'   => 1,
			'p2_high'     => 2,
			'p1_critical' => 3,
		);
		$current_level  = $priority_order[ $current_priority ] ?? 1;
		$new_level      = $priority_order[ $new_priority ] ?? 3;

		if ( $new_level <= $current_level && 'p1_critical' !== $current_priority ) {
			return new WP_Error(
				'priority_not_higher',
				sprintf(
					/* translators: 1: current priority, 2: requested priority */
					__( 'New priority (%1$s) must be higher than current priority (%2$s).', 'mcp-ai-wpoos-pro' ),
					$new_priority,
					$current_priority
				)
			);
		}

		update_post_meta( $ticket_id, '_ticket_priority', $new_priority );

		// Recalculate SLA targets with new priority.
		if ( class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ) {
			$sla = WP_MCP_AI_Support_Ticket_CPT::calculate_sla_targets( $new_priority, $ticket->post_date );
			update_post_meta( $ticket_id, '_ticket_sla_first_response_by', $sla['first_response_by'] );
			update_post_meta( $ticket_id, '_ticket_sla_resolution_by', $sla['resolution_by'] );
			WP_MCP_AI_Support_Ticket_CPT::recalc_ticket_sla( $ticket_id );
		}

		// Add escalation note.
		$reason      = sanitize_textarea_field( $arguments['reason'] ?? __( 'Ticket escalated.', 'mcp-ai-wpoos-pro' ) );
		$activity_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_crm_activity',
				'post_title'   => sprintf(
					/* translators: 1: ticket ID, 2: old priority, 3: new priority */
					__( 'Ticket #%1$d escalated from %2$s to %3$s', 'mcp-ai-wpoos-pro' ),
					$ticket_id,
					$current_priority,
					$new_priority
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

		return $this->format_success_response(
			__( 'Support ticket escalated.', 'mcp-ai-wpoos-pro' ),
			array(
				'ticket_id'         => $ticket_id,
				'previous_priority' => $current_priority,
				'new_priority'      => $new_priority,
				'edit_url'          => get_edit_post_link( $ticket_id, 'raw' ),
			)
		);
	}
}
