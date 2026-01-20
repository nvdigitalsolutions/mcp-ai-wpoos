<?php
/**
 * Tool for searching and researching prescriptions.
 *
 * Allows AI assistants to search prescriptions with advanced filtering.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search and research prescriptions.
 */
class WP_MCP_AI_Tool_Search_Prescriptions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_prescriptions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Prescriptions', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search and research prescriptions with advanced filtering by member, medication name, prescriber, status, and date ranges. Useful for medication reconciliation, refill tracking, and drug interaction checking.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Filter by member ID (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'medication'  => array(
					'type'        => 'string',
					'description' => __( 'Search by medication name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'prescriber'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by prescriber name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'active_only' => array(
					'type'        => 'boolean',
					'description' => __( 'Only show currently active prescriptions (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by prescriptions starting on or after this date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by prescriptions ending on or before this date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'search'      => array(
					'type'        => 'string',
					'description' => __( 'Search prescriptions by any text (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'per_page'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of prescriptions to return per page (optional, default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'        => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
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
		return ! empty( $settings['enable_health_wellness_management'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search prescriptions.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate and sanitize inputs.
		$member_id   = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$medication  = isset( $arguments['medication'] ) ? sanitize_text_field( $arguments['medication'] ) : '';
		$prescriber  = isset( $arguments['prescriber'] ) ? sanitize_text_field( $arguments['prescriber'] ) : '';
		$active_only = isset( $arguments['active_only'] ) ? (bool) $arguments['active_only'] : true;
		$start_date  = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date    = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$search      = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$per_page    = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page        = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		// Validate per_page.
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_prescription',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Add search if provided.
		if ( $search ) {
			$query_args['s'] = $search;
		}

		// Build meta query.
		$meta_query = array( 'relation' => 'AND' );

		// Filter by member.
		if ( $member_id ) {
			$meta_query[] = array(
				'key'   => '_prescription_member_id',
				'value' => $member_id,
			);
		}

		// Filter by prescriber.
		if ( $prescriber ) {
			$meta_query[] = array(
				'key'     => '_prescription_prescriber',
				'value'   => $prescriber,
				'compare' => 'LIKE',
			);
		}

		// Filter by start date.
		if ( $start_date ) {
			$meta_query[] = array(
				'key'     => '_prescription_start_date',
				'value'   => $start_date,
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		// Filter by end date.
		if ( $end_date ) {
			$meta_query[] = array(
				'key'     => '_prescription_end_date',
				'value'   => $end_date,
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}

		// Filter active only.
		if ( $active_only ) {
			$meta_query[] = array(
				'relation' => 'AND',
				array(
					'key'     => '_prescription_start_date',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '<=',
					'type'    => 'DATE',
				),
				array(
					'key'     => '_prescription_end_date',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		// Build response.
		$prescriptions = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$prescription_id = get_the_ID();

				// Get member info.
				$member_id   = get_post_meta( $prescription_id, '_prescription_member_id', true );
				$member_name = '';
				if ( $member_id ) {
					$member      = get_post( $member_id );
					$member_name = $member ? $member->post_title : '';
				}

				// Check if currently active.
				$start     = get_post_meta( $prescription_id, '_prescription_start_date', true );
				$end       = get_post_meta( $prescription_id, '_prescription_end_date', true );
				$today     = current_time( 'Y-m-d' );
				$is_active = ( ! $start || $start <= $today ) && ( ! $end || $end >= $today );

				$prescriptions[] = array(
					'id'           => $prescription_id,
					'medication'   => get_the_title(),
					'member_id'    => $member_id,
					'member_name'  => $member_name,
					'dosage'       => get_post_meta( $prescription_id, '_prescription_dosage', true ),
					'frequency'    => get_post_meta( $prescription_id, '_prescription_frequency', true ),
					'prescriber'   => get_post_meta( $prescription_id, '_prescription_prescriber', true ),
					'start_date'   => $start,
					'end_date'     => $end,
					'is_active'    => $is_active,
					'instructions' => wp_trim_words( get_the_content(), 20 ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'       => true,
			'prescriptions' => $prescriptions,
			'pagination'    => array(
				'total'        => $query->found_posts,
				'total_pages'  => $query->max_num_pages,
				'current_page' => $page,
				'per_page'     => $per_page,
			),
		);
	}
}
