<?php
/**
 * List Support Tickets Tool
 *
 * Queries support tickets with filters for status, priority, assignee,
 * contact, date range, and search.
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
class WP_MCP_AI_Tool_List_Support_Tickets implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'The List Support Tickets tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_support_tickets';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Support Tickets', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List support tickets with filters for status, priority, assignee, contact, date range, and search.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by stage: new, triaged, in_progress, waiting_on_customer, waiting_on_third_party, resolved, closed.', 'mcp-ai-wpoos-pro' ),
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by priority: p1_critical, p2_high, p3_medium, p4_low.', 'mcp-ai-wpoos-pro' ),
				),
				'assignee_id' => array(
					'type'        => 'integer',
					'description' => __( 'Filter by WordPress user ID of assignee.', 'mcp-ai-wpoos-pro' ),
				),
				'contact_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Filter by lead/contact ID of requester.', 'mcp-ai-wpoos-pro' ),
				),
				'sla_status'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by SLA status: on_track, at_risk, breached.', 'mcp-ai-wpoos-pro' ),
				),
				'date_from'   => array(
					'type'        => 'string',
					'description' => __( 'Start date (Y-m-d).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'     => array(
					'type'        => 'string',
					'description' => __( 'End date (Y-m-d).', 'mcp-ai-wpoos-pro' ),
				),
				'search'      => array(
					'type'        => 'string',
					'description' => __( 'Search term for ticket subject/content.', 'mcp-ai-wpoos-pro' ),
				),
				'per_page'    => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (max 50). Default: 20.', 'mcp-ai-wpoos-pro' ),
				),
				'page'        => array(
					'type'        => 'integer',
					'description' => __( 'Page number. Default: 1.', 'mcp-ai-wpoos-pro' ),
				),
				'orderby'     => array(
					'type'        => 'string',
					'description' => __( 'Sort by: date, priority, status. Default: date.', 'mcp-ai-wpoos-pro' ),
				),
				'order'       => array(
					'type'        => 'string',
					'description' => __( 'Sort order: ASC or DESC. Default: DESC.', 'mcp-ai-wpoos-pro' ),
				),
			),
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
		$per_page    = min( 50, max( 1, absint( $arguments['per_page'] ?? 20 ) ) );
		$page        = max( 1, absint( $arguments['page'] ?? 1 ) );
		$search      = sanitize_text_field( $arguments['search'] ?? '' );
		$status      = sanitize_key( $arguments['status'] ?? '' );
		$priority    = sanitize_key( $arguments['priority'] ?? '' );
		$assignee_id = absint( $arguments['assignee_id'] ?? 0 );
		$contact_id  = absint( $arguments['contact_id'] ?? 0 );
		$sla_status  = sanitize_key( $arguments['sla_status'] ?? '' );
		$date_from   = sanitize_text_field( $arguments['date_from'] ?? '' );
		$date_to     = sanitize_text_field( $arguments['date_to'] ?? '' );
		$orderby     = sanitize_key( $arguments['orderby'] ?? 'date' );
		$order       = strtoupper( sanitize_text_field( $arguments['order'] ?? 'DESC' ) );
		$order       = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		$args = array(
			'post_type'      => 'mcp_ai_support_ticket',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => $order,
		);

		if ( $search ) {
			$args['s'] = $search;
		}

		// Date range.
		if ( $date_from || $date_to ) {
			$date_query = array( 'inclusive' => true );
			if ( $date_from ) {
				$date_query['after'] = $date_from;
			}
			if ( $date_to ) {
				$date_query['before'] = $date_to;
			}
			$args['date_query'] = array( $date_query );
		}

		// Meta filters.
		$meta_query = array();
		if ( $status ) {
			$meta_query[] = array(
				'key'   => '_ticket_status',
				'value' => $status,
			);
		}
		if ( $priority ) {
			$meta_query[] = array(
				'key'   => '_ticket_priority',
				'value' => $priority,
			);
		}
		if ( $assignee_id ) {
			$meta_query[] = array(
				'key'   => '_ticket_assignee_id',
				'value' => (string) $assignee_id,
			);
		}
		if ( $contact_id ) {
			$meta_query[] = array(
				'key'   => '_ticket_contact_id',
				'value' => (string) $contact_id,
			);
		}
		if ( $sla_status ) {
			$meta_query[] = array(
				'key'   => '_ticket_sla_status',
				'value' => $sla_status,
			);
		}
		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query   = new WP_Query( $args );
		$tickets = array();

		foreach ( $query->posts as $post ) {
			$tickets[] = array(
				'id'         => $post->ID,
				'subject'    => $post->post_title,
				'status'     => get_post_meta( $post->ID, '_ticket_status', true ) ? get_post_meta( $post->ID, '_ticket_status', true ) : 'new',
				'priority'   => get_post_meta( $post->ID, '_ticket_priority', true ) ? get_post_meta( $post->ID, '_ticket_priority', true ) : 'p2_high',
				'sla_status' => get_post_meta( $post->ID, '_ticket_sla_status', true ) ? get_post_meta( $post->ID, '_ticket_sla_status', true ) : 'on_track',
				'created_at' => $post->post_date,
				'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return $this->format_success_response(
			__( 'Tickets retrieved.', 'mcp-ai-wpoos-pro' ),
			array(
				'tickets' => $tickets,
				'total'   => (int) $query->found_posts,
				'page'    => $page,
				'pages'   => (int) $query->max_num_pages,
			)
		);
	}
}
