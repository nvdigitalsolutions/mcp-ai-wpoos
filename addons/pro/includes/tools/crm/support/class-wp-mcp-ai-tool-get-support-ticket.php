<?php
/**
 * Get Support Ticket Tool
 *
 * Retrieves full support ticket details with SLA status, timeline,
 * related records, and resolution data.
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
class WP_MCP_AI_Tool_Get_Support_Ticket implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'The Get Support Ticket tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_support_ticket';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Support Ticket', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve full support ticket details including SLA status, timeline, related records, and resolution data.', 'mcp-ai-wpoos-pro' );
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
		return array( 'pro', 'database-read', 'requires-capability' );
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

		// Gather meta.
		$meta_keys = array(
			'_ticket_status',
			'_ticket_priority',
			'_ticket_source',
			'_ticket_category',
			'_ticket_contact_id',
			'_ticket_assignee_id',
			'_ticket_tags',
			'_ticket_parent_id',
			'_ticket_sla_first_response_by',
			'_ticket_sla_resolution_by',
			'_ticket_sla_first_response_at',
			'_ticket_sla_resolved_at',
			'_ticket_sla_status',
			'_ticket_sla_total_paused_secs',
			'_ticket_resolution_type',
			'_ticket_resolution_note',
			'_ticket_closed_by',
			'_ticket_closed_at',
			'_ticket_reopened_count',
		);

		$meta = array();
		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $ticket_id, $key, true );
			if ( '' !== $value && false !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		// Build contact info.
		$contact_id   = (int) ( $meta['_ticket_contact_id'] ?? 0 );
		$contact_info = null;
		if ( $contact_id ) {
			$contact = get_post( $contact_id );
			if ( $contact ) {
				$contact_info = array(
					'id'    => $contact->ID,
					'title' => $contact->post_title,
					'type'  => $contact->post_type,
					'email' => get_post_meta( $contact->ID, 'email', true ),
				);
			}
		}

		// Build assignee info.
		$assignee_id   = (int) ( $meta['_ticket_assignee_id'] ?? 0 );
		$assignee_info = null;
		if ( $assignee_id ) {
			$user = get_userdata( $assignee_id );
			if ( $user ) {
				$assignee_info = array(
					'id'           => $user->ID,
					'display_name' => $user->display_name,
					'email'        => $user->user_email,
				);
			}
		}

		// Calculate time in stage.
		$created_ts    = strtotime( $ticket->post_date );
		$time_in_stage = human_time_diff( $created_ts, time() );

		return $this->format_success_response(
			__( 'Support ticket retrieved.', 'mcp-ai-wpoos-pro' ),
			array(
				'ticket_id'      => $ticket_id,
				'subject'        => $ticket->post_title,
				'body'           => $ticket->post_content,
				'status'         => $meta['_ticket_status'] ?? 'new',
				'priority'       => $meta['_ticket_priority'] ?? 'p2_high',
				'category'       => $meta['_ticket_category'] ?? '',
				'source'         => $meta['_ticket_source'] ?? '',
				'tags'           => $meta['_ticket_tags'] ?? array(),
				'contact'        => $contact_info,
				'assignee'       => $assignee_info,
				'sla'            => array(
					'status'            => $meta['_ticket_sla_status'] ?? 'on_track',
					'first_response_by' => $meta['_ticket_sla_first_response_by'] ?? null,
					'first_response_at' => $meta['_ticket_sla_first_response_at'] ?? null,
					'resolution_by'     => $meta['_ticket_sla_resolution_by'] ?? null,
					'resolved_at'       => $meta['_ticket_sla_resolved_at'] ?? null,
					'total_paused_secs' => (int) ( $meta['_ticket_sla_total_paused_secs'] ?? 0 ),
				),
				'resolution'     => array(
					'type' => $meta['_ticket_resolution_type'] ?? null,
					'note' => $meta['_ticket_resolution_note'] ?? '',
				),
				'closed_by'      => $meta['_ticket_closed_by'] ?? null,
				'closed_at'      => $meta['_ticket_closed_at'] ?? null,
				'reopened_count' => (int) ( $meta['_ticket_reopened_count'] ?? 0 ),
				'time_in_stage'  => $time_in_stage,
				'created_at'     => $ticket->post_date,
				'edit_url'       => get_edit_post_link( $ticket->ID, 'raw' ),
			)
		);
	}
}
