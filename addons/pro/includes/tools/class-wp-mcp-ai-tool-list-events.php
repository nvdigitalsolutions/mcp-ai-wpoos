<?php
/**
 * Tool for listing events.
 *
 * Allows AI assistants to list and filter events for calendar views.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists events with filtering options optimized for calendar views.
 */
class WP_MCP_AI_Tool_List_Events implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_events';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Events', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists calendar events with optional filtering by date range, project, type, or attendees. Essential for calendar views and scheduling.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Filter by project ID (optional)', 'wp-mcp-ai' ),
				),
				'type'         => array(
					'type'        => 'string',
					'description' => __( 'Filter by event type (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'meeting', 'deadline', 'milestone', 'reminder', 'other' ),
				),
				'attendee'     => array(
					'type'        => 'integer',
					'description' => __( 'Filter by attendee user ID (optional)', 'wp-mcp-ai' ),
				),
				'start_after'  => array(
					'type'        => 'string',
					'description' => __( 'Filter events starting after this date (YYYY-MM-DD) - for calendar range views (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'start_before' => array(
					'type'        => 'string',
					'description' => __( 'Filter events starting before this date (YYYY-MM-DD) - for calendar range views (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of events to return (default: 100, max: 500)', 'wp-mcp-ai' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_project_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list events.', 'wp-mcp-ai' ) );
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_event',
			'post_status'    => 'publish',
			'posts_per_page' => isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 500 ) : 100,
			'orderby'        => 'meta_value',
			'meta_key'       => '_event_start_date',
			'order'          => 'ASC',
		);

		$meta_query = array();

		// Filter by project.
		if ( ! empty( $arguments['project_id'] ) ) {
			$meta_query[] = array(
				'key'     => '_event_project_id',
				'value'   => absint( $arguments['project_id'] ),
				'compare' => '=',
			);
		}

		// Filter by event type.
		if ( ! empty( $arguments['type'] ) ) {
			$meta_query[] = array(
				'key'     => '_event_type',
				'value'   => sanitize_key( $arguments['type'] ),
				'compare' => '=',
			);
		}

		// Filter by attendee.
		if ( ! empty( $arguments['attendee'] ) ) {
			$meta_query[] = array(
				'key'     => '_event_attendees',
				'value'   => sprintf( ':"%d";', absint( $arguments['attendee'] ) ),
				'compare' => 'LIKE',
			);
		}

		// Filter by date range (critical for calendar views).
		if ( ! empty( $arguments['start_after'] ) ) {
			$meta_query[] = array(
				'key'     => '_event_start_date',
				'value'   => sanitize_text_field( $arguments['start_after'] ),
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		if ( ! empty( $arguments['start_before'] ) ) {
			$meta_query[] = array(
				'key'     => '_event_start_date',
				'value'   => sanitize_text_field( $arguments['start_before'] ),
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query  = new WP_Query( $query_args );
		$events = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$event_id = get_the_ID();

				$events[] = array(
					'id'          => $event_id,
					'title'       => get_the_title(),
					'description' => get_the_content(),
					'project_id'  => absint( get_post_meta( $event_id, '_event_project_id', true ) ) ?: null,
					'start_date'  => get_post_meta( $event_id, '_event_start_date', true ),
					'start_time'  => get_post_meta( $event_id, '_event_start_time', true ) ?: '',
					'end_date'    => get_post_meta( $event_id, '_event_end_date', true ),
					'end_time'    => get_post_meta( $event_id, '_event_end_time', true ) ?: '',
					'all_day'     => (bool) get_post_meta( $event_id, '_event_all_day', true ),
					'location'    => get_post_meta( $event_id, '_event_location', true ) ?: '',
					'type'        => get_post_meta( $event_id, '_event_type', true ) ?: 'meeting',
					'attendees'   => get_post_meta( $event_id, '_event_attendees', true ) ?: array(),
					'created_at'  => get_the_date( 'c' ),
					'updated_at'  => get_the_modified_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success' => true,
			'count'   => count( $events ),
			'total'   => $query->found_posts,
			'events'  => $events,
		);
	}
}
