<?php
/**
 * Tool for listing Extra-Curricular Activities (ECAs).
 *
 * Allows AI assistants to list and filter ECAs with various criteria.
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
		return __( 'List ECAs', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists Extra-Curricular Activities with filtering options by type, day, year group, status, and availability. Returns ECA details including enrollment counts and capacity.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_type'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by ECA type', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' ),
				),
				'day'           => array(
					'type'        => 'string',
					'description' => __( 'Filter by day of the week', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
				),
				'year_group'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by year group eligibility', 'mcp-ai-wpoos-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by ECA status', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'inactive', 'full', 'cancelled' ),
				),
				'is_paid'       => array(
					'type'        => 'boolean',
					'description' => __( 'Filter by paid/free activities', 'mcp-ai-wpoos-pro' ),
				),
				'has_availability' => array(
					'type'        => 'boolean',
					'description' => __( 'Filter to show only ECAs with available spots', 'mcp-ai-wpoos-pro' ),
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Search by ECA name or description', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'page'          => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'per_page'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of ECAs per page', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
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
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to list ECAs.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get pagination parameters.
		$page = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$per_page = min( $per_page, 100 );

		// Build query arguments.
		$query_args = array(
			'post_type'      => 'mcp_ai_eca',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Add search if provided.
		if ( isset( $arguments['search'] ) && '' !== $arguments['search'] ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		// Build meta query for filters.
		$meta_query = array( 'relation' => 'AND' );

		// Filter by type.
		if ( isset( $arguments['eca_type'] ) && '' !== $arguments['eca_type'] ) {
			$meta_query[] = array(
				'key'   => '_eca_type',
				'value' => sanitize_key( $arguments['eca_type'] ),
			);
		}

		// Filter by day.
		if ( isset( $arguments['day'] ) && '' !== $arguments['day'] ) {
			$meta_query[] = array(
				'key'   => '_eca_day',
				'value' => sanitize_text_field( $arguments['day'] ),
			);
		}

		// Filter by status.
		if ( isset( $arguments['status'] ) && '' !== $arguments['status'] ) {
			$meta_query[] = array(
				'key'   => '_eca_status',
				'value' => sanitize_key( $arguments['status'] ),
			);
		}

		// Filter by paid/free.
		if ( isset( $arguments['is_paid'] ) ) {
			$meta_query[] = array(
				'key'   => '_eca_is_paid',
				'value' => $arguments['is_paid'] ? 'yes' : 'no',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		$ecas = array();
		foreach ( $query->posts as $post ) {
			$eca_data = $this->get_eca_data( $post->ID );

			// Filter by year group if specified.
			if ( isset( $arguments['year_group'] ) && '' !== $arguments['year_group'] ) {
				$year_groups = get_post_meta( $post->ID, '_eca_year_groups', true );
				if ( ! is_array( $year_groups ) || ! in_array( $arguments['year_group'], $year_groups, true ) ) {
					continue;
				}
			}

			// Filter by availability if specified.
			if ( isset( $arguments['has_availability'] ) && $arguments['has_availability'] ) {
				if ( $eca_data['is_full'] ) {
					continue;
				}
			}

			$ecas[] = $eca_data;
		}

		return array(
			'success'       => true,
			'ecas'          => $ecas,
			'total'         => $query->found_posts,
			'page'          => $page,
			'per_page'      => $per_page,
			'total_pages'   => $query->max_num_pages,
			'has_more'      => $page < $query->max_num_pages,
		);
	}

	/**
	 * Get ECA data with enrollment information.
	 *
	 * @param int $post_id ECA post ID.
	 * @return array ECA data.
	 */
	private function get_eca_data( $post_id ) {
		$post = get_post( $post_id );

		$max_students = absint( get_post_meta( $post_id, '_eca_max_students', true ) );
		$current_enrollment = absint( get_post_meta( $post_id, '_eca_current_enrollment', true ) );
		$is_full = $max_students > 0 && $current_enrollment >= $max_students;
		$available_spots = $max_students > 0 ? max( 0, $max_students - $current_enrollment ) : null;

		$is_paid = get_post_meta( $post_id, '_eca_is_paid', true ) === 'yes';
		$cost = $is_paid ? floatval( get_post_meta( $post_id, '_eca_cost', true ) ) : 0;

		return array(
			'eca_id'              => $post_id,
			'name'                => $post->post_title,
			'eca_code'            => get_post_meta( $post_id, '_eca_code', true ),
			'description'         => $post->post_content,
			'type'                => get_post_meta( $post_id, '_eca_type', true ),
			'day'                 => get_post_meta( $post_id, '_eca_day', true ),
			'start_time'          => get_post_meta( $post_id, '_eca_start_time', true ),
			'end_time'            => get_post_meta( $post_id, '_eca_end_time', true ),
			'venue'               => get_post_meta( $post_id, '_eca_venue', true ),
			'year_groups'         => get_post_meta( $post_id, '_eca_year_groups', true ),
			'teachers'            => get_post_meta( $post_id, '_eca_teachers', true ),
			'max_students'        => $max_students,
			'current_enrollment'  => $current_enrollment,
			'available_spots'     => $available_spots,
			'is_full'             => $is_full,
			'is_paid'             => $is_paid,
			'cost'                => $cost,
			'cost_period'         => get_post_meta( $post_id, '_eca_cost_period', true ),
			'requires_audition'   => get_post_meta( $post_id, '_eca_requires_audition', true ) === 'yes',
			'booking_type'        => get_post_meta( $post_id, '_eca_booking_type', true ),
			'status'              => get_post_meta( $post_id, '_eca_status', true ),
			'url'                 => get_permalink( $post_id ),
		);
	}
}
