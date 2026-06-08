<?php
/**
 * Create Support Ticket Tool
 *
 * Creates a new support ticket with priority, contact, category,
 * source, and optional assignee. Calculates SLA targets from priority.
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
class WP_MCP_AI_Tool_Create_Support_Ticket implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'The Create Support Ticket tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_support_ticket';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Support Ticket', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new support ticket with priority, contact, category, and source. Calculates SLA targets from priority.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'subject'     => array(
					'type'        => 'string',
					'description' => __( 'Ticket subject/title.', 'mcp-ai-wpoos-pro' ),
				),
				'body'        => array(
					'type'        => 'string',
					'description' => __( 'Ticket body/description.', 'mcp-ai-wpoos-pro' ),
				),
				'contact_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Lead or contact ID of the requester.', 'mcp-ai-wpoos-pro' ),
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => __( 'Priority: p1_critical, p2_high, p3_medium, or p4_low. Default: p2_high.', 'mcp-ai-wpoos-pro' ),
				),
				'category'    => array(
					'type'        => 'string',
					'description' => __( 'Category: bug, question, feature_request, account, billing, other. Default: question.', 'mcp-ai-wpoos-pro' ),
				),
				'source'      => array(
					'type'        => 'string',
					'description' => __( 'Source channel: email, phone, chat, web_form, api, other. Default: email.', 'mcp-ai-wpoos-pro' ),
				),
				'assignee_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the assignee. Default: 0 (unassigned).', 'mcp-ai-wpoos-pro' ),
				),
				'tags'        => array(
					'type'        => 'array',
					'description' => __( 'Array of tag strings.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'subject' ),
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
		$subject    = sanitize_text_field( $arguments['subject'] ?? __( 'New Support Ticket', 'mcp-ai-wpoos-pro' ) );
		$body       = sanitize_textarea_field( $arguments['body'] ?? '' );
		$contact_id = absint( $arguments['contact_id'] ?? 0 );
		$priority   = sanitize_key( $arguments['priority'] ?? 'p2_high' );
		$category   = sanitize_key( $arguments['category'] ?? 'question' );
		$source     = sanitize_key( $arguments['source'] ?? 'email' );
		$assignee   = absint( $arguments['assignee_id'] ?? 0 );
		$tags       = isset( $arguments['tags'] ) && is_array( $arguments['tags'] )
			? array_map( 'sanitize_text_field', $arguments['tags'] )
			: array();

		$valid_priorities = array( 'p1_critical', 'p2_high', 'p3_medium', 'p4_low' );
		if ( ! in_array( $priority, $valid_priorities, true ) ) {
			$priority = 'p2_high';
		}

		$valid_categories = array( 'bug', 'question', 'feature_request', 'account', 'billing', 'other' );
		if ( ! in_array( $category, $valid_categories, true ) ) {
			$category = 'question';
		}

		$valid_sources = array( 'email', 'phone', 'chat', 'web_form', 'api', 'other' );
		if ( ! in_array( $source, $valid_sources, true ) ) {
			$source = 'email';
		}

		// Create the ticket post.
		$ticket_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_support_ticket',
				'post_title'   => $subject,
				'post_content' => $body,
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $ticket_id ) ) {
			return $ticket_id;
		}

		// Set meta fields.
		update_post_meta( $ticket_id, '_ticket_status', 'new' );
		update_post_meta( $ticket_id, '_ticket_priority', $priority );
		update_post_meta( $ticket_id, '_ticket_source', $source );
		update_post_meta( $ticket_id, '_ticket_category', $category );
		update_post_meta( $ticket_id, '_ticket_contact_id', $contact_id );
		update_post_meta( $ticket_id, '_ticket_assignee_id', $assignee );
		update_post_meta( $ticket_id, '_ticket_tags', $tags );
		update_post_meta( $ticket_id, '_ticket_parent_id', 0 );
		update_post_meta( $ticket_id, '_ticket_sla_status', 'on_track' );
		update_post_meta( $ticket_id, '_ticket_reopened_count', 0 );

		// Calculate SLA targets.
		if ( class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ) {
			$sla = WP_MCP_AI_Support_Ticket_CPT::calculate_sla_targets( $priority, current_time( 'mysql' ) );
			update_post_meta( $ticket_id, '_ticket_sla_first_response_by', $sla['first_response_by'] );
			update_post_meta( $ticket_id, '_ticket_sla_resolution_by', $sla['resolution_by'] );
		}

		// Fire creation hook.
		do_action(
			'wp_mcp_ai_crm_ticket_created',
			$ticket_id,
			array(
				'subject'    => $subject,
				'priority'   => $priority,
				'contact_id' => $contact_id,
			)
		);

		return $this->format_success_response(
			__( 'Support ticket created successfully.', 'mcp-ai-wpoos-pro' ),
			array(
				'ticket_id' => $ticket_id,
				'subject'   => $subject,
				'priority'  => $priority,
				'edit_url'  => get_edit_post_link( $ticket_id, 'raw' ),
			)
		);
	}
}
