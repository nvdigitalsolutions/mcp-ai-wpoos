<?php
/**
 * Tool for listing ECAs (Extra-Curricular Activities).
 *
 * Allows AI assistants to list and filter ECAs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists ECAs with filtering options.
 */
class WP_MCP_AI_Tool_List_ECAs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_ecas';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List ECAs', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists Extra-Curricular Activities (ECAs) with optional filtering by type, day, year group, or other criteria. Useful for managing school activities and creating timetables.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_type'     => array(
					'type'        => 'string',
					'description' => __( 'Filter by ECA type (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'club', 'society', 'sport_squad', 'sport_academy', 'other' ),
				),
				'day'          => array(
					'type'        => 'string',
					'description' => __( 'Filter by day of the week (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
				),
				'year_group'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by year group (e.g., "Year 7") (optional)', 'wp-mcp-ai' ),
				),
				'is_paid'      => array(
					'type'        => 'boolean',
					'description' => __( 'Filter by paid/free activities (optional)', 'wp-mcp-ai' ),
				),
				'booking_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by booking type (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'preference', 'first_come_first_served', 'audition', 'pre_selected' ),
				),
				'search'       => array(
					'type'        => 'string',
					'description' => __( 'Search by ECA name or description (optional)', 'wp-mcp-ai' ),
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of ECAs to return (default: 50, max: 200)', 'wp-mcp-ai' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
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
		// ECA management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list ECAs.', 'wp-mcp-ai' ) );
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_eca',
			'post_status'    => 'publish',
			'posts_per_page' => isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 200 ) : 50,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Search filter.
		if ( ! empty( $arguments['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		// Build meta query for filtering.
		$meta_query = array();

		// Filter by ECA type.
		if ( ! empty( $arguments['eca_type'] ) ) {
			$meta_query[] = array(
				'key'     => '_eca_type',
				'value'   => sanitize_key( $arguments['eca_type'] ),
				'compare' => '=',
			);
		}

		// Filter by day.
		if ( ! empty( $arguments['day'] ) ) {
			$meta_query[] = array(
				'key'     => '_eca_day',
				'value'   => sanitize_text_field( $arguments['day'] ),
				'compare' => '=',
			);
		}

		// Filter by year group.
		if ( ! empty( $arguments['year_group'] ) ) {
			$meta_query[] = array(
				'key'     => '_eca_year_groups',
				'value'   => sprintf( '"%s"', sanitize_text_field( $arguments['year_group'] ) ),
				'compare' => 'LIKE',
			);
		}

		// Filter by paid status.
		if ( isset( $arguments['is_paid'] ) ) {
			$meta_query[] = array(
				'key'     => '_eca_is_paid',
				'value'   => (bool) $arguments['is_paid'] ? '1' : '0',
				'compare' => '=',
			);
		}

		// Filter by booking type.
		if ( ! empty( $arguments['booking_type'] ) ) {
			$meta_query[] = array(
				'key'     => '_eca_booking_type',
				'value'   => sanitize_key( $arguments['booking_type'] ),
				'compare' => '=',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new WP_Query( $query_args );
		$ecas  = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$eca_id = get_the_ID();

				$ecas[] = array(
					'id'           => $eca_id,
					'name'         => get_the_title(),
					'description'  => get_the_content(),
					'eca_code'     => get_post_meta( $eca_id, '_eca_code', true ) ?: '',
					'type'         => get_post_meta( $eca_id, '_eca_type', true ) ?: 'club',
					'day'          => get_post_meta( $eca_id, '_eca_day', true ) ?: '',
					'time_start'   => get_post_meta( $eca_id, '_eca_time_start', true ) ?: '',
					'time_end'     => get_post_meta( $eca_id, '_eca_time_end', true ) ?: '',
					'venue'        => get_post_meta( $eca_id, '_eca_venue', true ) ?: '',
					'year_groups'  => get_post_meta( $eca_id, '_eca_year_groups', true ) ?: array(),
					'teachers'     => get_post_meta( $eca_id, '_eca_teachers', true ) ?: array(),
					'max_capacity' => absint( get_post_meta( $eca_id, '_eca_max_capacity', true ) ),
					'is_paid'      => (bool) get_post_meta( $eca_id, '_eca_is_paid', true ),
					'cost'         => get_post_meta( $eca_id, '_eca_cost', true ) ?: '',
					'booking_type' => get_post_meta( $eca_id, '_eca_booking_type', true ) ?: 'preference',
					'isams_id'     => get_post_meta( $eca_id, '_eca_isams_id', true ) ?: '',
					'socs_id'      => get_post_meta( $eca_id, '_eca_socs_id', true ) ?: '',
					'created_at'   => get_the_date( 'c' ),
					'updated_at'   => get_the_modified_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success' => true,
			'count'   => count( $ecas ),
			'total'   => $query->found_posts,
			'ecas'    => $ecas,
		);
	}
}
