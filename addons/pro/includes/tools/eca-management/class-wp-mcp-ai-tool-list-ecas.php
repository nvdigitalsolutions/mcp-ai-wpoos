<?php
/**
 * Tool for listing Extra-Curricular Activities (ECAs).
 *
 * Allows AI assistants to list and filter ECAs with various criteria.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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
		return __( 'Lists Extra-Curricular Activities with comprehensive filtering by type, day, year group, teacher, venue, status, and availability. Supports sorting and returns enrollment counts with capacity utilization.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_type'         => array(
					'type'        => 'string',
					'description' => __( 'Filter by ECA type', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' ),
				),
				'day'              => array(
					'type'        => 'string',
					'description' => __( 'Filter by day of the week', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
				),
				'year_group'       => array(
					'type'        => 'string',
					'description' => __( 'Filter by year group eligibility', 'mcp-ai-wpoos-pro' ),
				),
				'teacher'          => array(
					'type'        => 'string',
					'description' => __( 'Filter by teacher name', 'mcp-ai-wpoos-pro' ),
				),
				'venue'            => array(
					'type'        => 'string',
					'description' => __( 'Filter by venue/location', 'mcp-ai-wpoos-pro' ),
				),
				'status'           => array(
					'type'        => 'string',
					'description' => __( 'Filter by ECA status', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'inactive', 'full', 'cancelled' ),
				),
				'is_paid'          => array(
					'type'        => 'boolean',
					'description' => __( 'Filter by paid/free activities', 'mcp-ai-wpoos-pro' ),
				),
				'has_availability' => array(
					'type'        => 'boolean',
					'description' => __( 'Filter to show only ECAs with available spots', 'mcp-ai-wpoos-pro' ),
				),
				'search'           => array(
					'type'        => 'string',
					'description' => __( 'Search by ECA name or description', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'sort_by'          => array(
					'type'        => 'string',
					'description' => __( 'Sort results by field', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'name', 'day', 'created_date', 'type' ),
					'default'     => 'name',
				),
				'sort_order'       => array(
					'type'        => 'string',
					'description' => __( 'Sort direction', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ASC', 'DESC' ),
					'default'     => 'ASC',
				),
				'page'             => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'per_page'         => array(
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
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'school_admin', 'student' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$per_page = min( $per_page, 100 );

		// Determine sort order.
		$sort_by    = isset( $arguments['sort_by'] ) ? sanitize_key( $arguments['sort_by'] ) : 'name';
		$sort_order = isset( $arguments['sort_order'] ) && 'DESC' === strtoupper( $arguments['sort_order'] ) ? 'DESC' : 'ASC';

		$orderby = 'title';
		if ( 'created_date' === $sort_by ) {
			$orderby = 'date';
		} elseif ( 'day' === $sort_by ) {
			$orderby = 'meta_value';
		} elseif ( 'type' === $sort_by ) {
			$orderby = 'meta_value';
		}

		// Build query arguments.
		$query_args = array(
			'post_type'      => 'mcp_ai_eca',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => $sort_order,
		);

		// Add meta key for meta-based sorting.
		if ( 'day' === $sort_by ) {
			$query_args['meta_key'] = '_eca_day'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		} elseif ( 'type' === $sort_by ) {
			$query_args['meta_key'] = '_eca_type'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

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

		// Filter by year group via meta_query (LIKE for serialized array).
		if ( isset( $arguments['year_group'] ) && '' !== $arguments['year_group'] ) {
			$meta_query[] = array(
				'key'     => '_eca_year_groups',
				'value'   => sanitize_text_field( $arguments['year_group'] ),
				'compare' => 'LIKE',
			);
		}

		// Filter by teacher via meta_query (LIKE for serialized array).
		if ( isset( $arguments['teacher'] ) && '' !== $arguments['teacher'] ) {
			$meta_query[] = array(
				'key'     => '_eca_teachers',
				'value'   => sanitize_text_field( $arguments['teacher'] ),
				'compare' => 'LIKE',
			);
		}

		// Filter by venue.
		if ( isset( $arguments['venue'] ) && '' !== $arguments['venue'] ) {
			$meta_query[] = array(
				'key'     => '_eca_venue',
				'value'   => sanitize_text_field( $arguments['venue'] ),
				'compare' => 'LIKE',
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

		// Filter by availability (not full).
		if ( ! empty( $arguments['has_availability'] ) ) {
			$meta_query[] = array(
				'key'     => '_eca_status',
				'value'   => 'full',
				'compare' => '!=',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		$ecas = array();
		foreach ( $query->posts as $post ) {
			$ecas[] = $this->get_eca_data( $post->ID );
		}

		return array(
			'success'     => true,
			'ecas'        => $ecas,
			'total'       => $query->found_posts,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $query->max_num_pages,
			'has_more'    => $page < $query->max_num_pages,
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

		$max_students       = absint( get_post_meta( $post_id, '_eca_max_students', true ) );
		$current_enrollment = absint( get_post_meta( $post_id, '_eca_current_enrollment', true ) );
		$is_full            = $max_students > 0 && $current_enrollment >= $max_students;
		$available_spots    = $max_students > 0 ? max( 0, $max_students - $current_enrollment ) : null;
		$utilization        = $max_students > 0 ? round( ( $current_enrollment / $max_students ) * 100, 1 ) : null;

		$is_paid = get_post_meta( $post_id, '_eca_is_paid', true ) === 'yes';
		$cost    = $is_paid ? floatval( get_post_meta( $post_id, '_eca_cost', true ) ) : 0;

		return array(
			'eca_id'               => $post_id,
			'name'                 => $post->post_title,
			'eca_code'             => get_post_meta( $post_id, '_eca_code', true ),
			'description'          => $post->post_content,
			'type'                 => get_post_meta( $post_id, '_eca_type', true ),
			'day'                  => get_post_meta( $post_id, '_eca_day', true ),
			'start_time'           => get_post_meta( $post_id, '_eca_start_time', true ),
			'end_time'             => get_post_meta( $post_id, '_eca_end_time', true ),
			'venue'                => get_post_meta( $post_id, '_eca_venue', true ),
			'year_groups'          => get_post_meta( $post_id, '_eca_year_groups', true ),
			'teachers'             => get_post_meta( $post_id, '_eca_teachers', true ),
			'max_students'         => $max_students,
			'current_enrollment'   => $current_enrollment,
			'available_spots'      => $available_spots,
			'is_full'              => $is_full,
			'capacity_utilization' => $utilization,
			'is_paid'              => $is_paid,
			'cost'                 => $cost,
			'cost_period'          => get_post_meta( $post_id, '_eca_cost_period', true ),
			'requires_audition'    => get_post_meta( $post_id, '_eca_requires_audition', true ) === 'yes',
			'booking_type'         => get_post_meta( $post_id, '_eca_booking_type', true ),
			'status'               => get_post_meta( $post_id, '_eca_status', true ),
			'url'                  => get_permalink( $post_id ),
		);
	}
}
