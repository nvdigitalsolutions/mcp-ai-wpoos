<?php
/**
 * Tool for listing checkups/appointments.
 *
 * Allows AI assistants to list checkups/appointments for members.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists checkups/appointments with filtering and pagination.
 */
class WP_MCP_AI_Tool_List_Checkups implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_checkups';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Checkups', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists checkups/appointments with optional filtering by member, status, and date range. Supports pagination.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Filter by member ID (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'status'     => array(
					'type'        => 'string',
					'description' => __( 'Filter by status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'scheduled', 'completed', 'cancelled', 'no-show', '' ),
				),
				'start_date' => array(
					'type'        => 'string',
					'description' => __( 'Filter checkups on or after this date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'   => array(
					'type'        => 'string',
					'description' => __( 'Filter checkups on or before this date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'search'     => array(
					'type'        => 'string',
					'description' => __( 'Search by title or provider (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'per_page'   => array(
					'type'        => 'integer',
					'description' => __( 'Checkups per page (optional, default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'       => array(
					'type'        => 'integer',
					'description' => __( 'Page number (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list checkups.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate and sanitize inputs.
		$member_id  = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$status     = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		$start_date = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date   = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$search     = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$per_page   = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page       = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		// Validate per_page.
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_checkup',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value',
			'meta_key'       => '_checkup_date',
			'order'          => 'ASC',
		);

		// Add meta query for filters.
		$meta_query = array( 'relation' => 'AND' );

		if ( $member_id ) {
			$meta_query[] = array(
				'key'   => '_checkup_member_id',
				'value' => $member_id,
			);
		}

		if ( $status ) {
			$meta_query[] = array(
				'key'   => '_checkup_status',
				'value' => $status,
			);
		}

		// Date range filter.
		if ( $start_date && $end_date ) {
			$meta_query[] = array(
				'key'     => '_checkup_date',
				'value'   => array( $start_date, $end_date ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			);
		} elseif ( $start_date ) {
			$meta_query[] = array(
				'key'     => '_checkup_date',
				'value'   => $start_date,
				'compare' => '>=',
				'type'    => 'DATE',
			);
		} elseif ( $end_date ) {
			$meta_query[] = array(
				'key'     => '_checkup_date',
				'value'   => $end_date,
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Add search if provided.
		if ( $search ) {
			$query_args['s'] = $search;
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		// Build checkups array.
		$checkups = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$checkup_id = get_the_ID();

				// Get member info.
				$checkup_member_id = get_post_meta( $checkup_id, '_checkup_member_id', true );
				$member_name       = '';
				if ( $checkup_member_id ) {
					$member = get_post( $checkup_member_id );
					$member_name = $member ? $member->post_title : '';
				}

				$checkups[] = array(
					'id'          => $checkup_id,
					'title'       => get_the_title(),
					'member_id'   => $checkup_member_id,
					'member_name' => $member_name,
					'date'        => get_post_meta( $checkup_id, '_checkup_date', true ),
					'time'        => get_post_meta( $checkup_id, '_checkup_time', true ),
					'provider'    => get_post_meta( $checkup_id, '_checkup_provider', true ),
					'location'    => get_post_meta( $checkup_id, '_checkup_location', true ),
					'status'      => get_post_meta( $checkup_id, '_checkup_status', true ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'    => true,
			'checkups'   => $checkups,
			'pagination' => array(
				'total'        => $query->found_posts,
				'per_page'     => $per_page,
				'current_page' => $page,
				'total_pages'  => $query->max_num_pages,
			),
		);
	}
}
