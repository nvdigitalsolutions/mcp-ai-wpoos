<?php
/**
 * Update Support Ticket Tool
 *
 * Updates an existing support ticket: change status, assign, add
 * internal note, change priority, update body.
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
class WP_MCP_AI_Tool_Update_Support_Ticket implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'The Update Support Ticket tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_support_ticket';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Support Ticket', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Update a support ticket: change status, assignee, priority, category, add internal note, update body.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ticket_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Support ticket post ID.', 'mcp-ai-wpoos-pro' ),
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'New stage: new, triaged, in_progress, waiting_on_customer, waiting_on_third_party, resolved, closed.', 'mcp-ai-wpoos-pro' ),
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => __( 'New priority: p1_critical, p2_high, p3_medium, p4_low.', 'mcp-ai-wpoos-pro' ),
				),
				'category'    => array(
					'type'        => 'string',
					'description' => __( 'New category.', 'mcp-ai-wpoos-pro' ),
				),
				'assignee_id' => array(
					'type'        => 'integer',
					'description' => __( 'New assignee WordPress user ID.', 'mcp-ai-wpoos-pro' ),
				),
				'body'        => array(
					'type'        => 'string',
					'description' => __( 'Updated ticket body content.', 'mcp-ai-wpoos-pro' ),
				),
				'note'        => array(
					'type'        => 'string',
					'description' => __( 'Internal note to add as an activity.', 'mcp-ai-wpoos-pro' ),
				),
				'subject'     => array(
					'type'        => 'string',
					'description' => __( 'Updated ticket subject.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'        => array(
					'type'        => 'array',
					'description' => __( 'Updated array of tag strings.', 'mcp-ai-wpoos-pro' ),
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

		$updates = array();

		// Subject.
		if ( isset( $arguments['subject'] ) ) {
			$updates['post_title'] = sanitize_text_field( $arguments['subject'] );
		}

		// Body.
		if ( isset( $arguments['body'] ) ) {
			$updates['post_content'] = sanitize_textarea_field( $arguments['body'] );
		}

		if ( ! empty( $updates ) ) {
			$updates['ID'] = $ticket_id;
			wp_update_post( $updates );
		}

		$changes = array();

		// Status.
		if ( isset( $arguments['status'] ) ) {
			$new_status = sanitize_key( $arguments['status'] );
			$old_status = get_post_meta( $ticket_id, '_ticket_status', true );
			$valid      = array( 'new', 'triaged', 'in_progress', 'waiting_on_customer', 'waiting_on_third_party', 'resolved', 'closed' );
			if ( in_array( $new_status, $valid, true ) ) {
				update_post_meta( $ticket_id, '_ticket_status', $new_status );
				if ( $old_status !== $new_status ) {
					do_action( 'wp_mcp_ai_crm_ticket_status_changed', $ticket_id, $old_status, $new_status );
				}
				$changes[] = "status: {$old_status} → {$new_status}";
			}
		}

		// Priority.
		if ( isset( $arguments['priority'] ) ) {
			$priority = sanitize_key( $arguments['priority'] );
			$valid    = array( 'p1_critical', 'p2_high', 'p3_medium', 'p4_low' );
			if ( in_array( $priority, $valid, true ) ) {
				update_post_meta( $ticket_id, '_ticket_priority', $priority );
				$changes[] = "priority: {$priority}";
			}
		}

		// Category.
		if ( isset( $arguments['category'] ) ) {
			update_post_meta( $ticket_id, '_ticket_category', sanitize_key( $arguments['category'] ) );
			$changes[] = 'category updated';
		}

		// Assignee.
		if ( isset( $arguments['assignee_id'] ) ) {
			$old_assignee = (int) get_post_meta( $ticket_id, '_ticket_assignee_id', true );
			$new_assignee = absint( $arguments['assignee_id'] );
			update_post_meta( $ticket_id, '_ticket_assignee_id', $new_assignee );
			if ( $old_assignee !== $new_assignee && $new_assignee > 0 ) {
				do_action( 'wp_mcp_ai_crm_ticket_assigned', $ticket_id, $old_assignee, $new_assignee );
			}
			$changes[] = 'assignee updated';
		}

		// Tags.
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			update_post_meta( $ticket_id, '_ticket_tags', array_map( 'sanitize_text_field', $arguments['tags'] ) );
			$changes[] = 'tags updated';
		}

		// Internal note → activity.
		if ( isset( $arguments['note'] ) && ! empty( $arguments['note'] ) ) {
			$activity_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_crm_activity',
					'post_title'   => sprintf(
						/* translators: %d: ticket ID */
						__( 'Note on Ticket #%d', 'mcp-ai-wpoos-pro' ),
						$ticket_id
					),
					'post_content' => sanitize_textarea_field( $arguments['note'] ),
					'post_status'  => 'publish',
					'post_author'  => get_current_user_id(),
				)
			);
			if ( ! is_wp_error( $activity_id ) ) {
				update_post_meta( $activity_id, 'activity_type', 'note' );
				update_post_meta( $activity_id, 'related_type', 'ticket' );
				update_post_meta( $activity_id, 'related_id', $ticket_id );
			}
			$changes[] = 'internal note added';
		}

		return $this->format_success_response(
			__( 'Support ticket updated.', 'mcp-ai-wpoos-pro' ),
			array(
				'ticket_id' => $ticket_id,
				'changes'   => $changes,
				'edit_url'  => get_edit_post_link( $ticket_id, 'raw' ),
			)
		);
	}
}
