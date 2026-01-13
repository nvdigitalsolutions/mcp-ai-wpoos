<?php
/**
 * Tool for searching and researching policies.
 *
 * Allows AI assistants to search insurance policies with advanced filtering.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search and research insurance policies.
 */
class WP_MCP_AI_Tool_Search_Policies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_policies';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Policies', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search and research insurance policies with advanced filtering by member, policy type, provider, status, and coverage dates. Useful for finding active coverage, comparing policies, and verifying benefits.', 'mcp-ai-wpoos-pro' );
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
				'policy_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by policy type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'health-insurance', 'dental-insurance', 'vision-insurance', 'pet-insurance', 'life-insurance', '' ),
				),
				'provider'    => array(
					'type'        => 'string',
					'description' => __( 'Search by insurance provider name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by policy status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'expired', 'pending', 'cancelled', '' ),
				),
				'active_only' => array(
					'type'        => 'boolean',
					'description' => __( 'Only show currently active policies (optional, default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'search'      => array(
					'type'        => 'string',
					'description' => __( 'Search policies by policy number or name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'per_page'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of policies to return per page (optional, default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search policies.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate and sanitize inputs.
		$member_id   = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$policy_type = isset( $arguments['policy_type'] ) ? sanitize_key( $arguments['policy_type'] ) : '';
		$provider    = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$status      = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		$active_only = isset( $arguments['active_only'] ) ? (bool) $arguments['active_only'] : false;
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
			'post_type'      => 'mcp_ai_policy',
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
				'key'   => '_policy_member_id',
				'value' => $member_id,
			);
		}

		// Filter by provider.
		if ( $provider ) {
			$meta_query[] = array(
				'key'     => '_policy_provider',
				'value'   => $provider,
				'compare' => 'LIKE',
			);
		}

		// Filter by status.
		if ( $status ) {
			$meta_query[] = array(
				'key'   => '_policy_status',
				'value' => $status,
			);
		}

		// Filter active only.
		if ( $active_only ) {
			$meta_query[] = array(
				'relation' => 'AND',
				array(
					'key'     => '_policy_effective_date',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '<=',
					'type'    => 'DATE',
				),
				array(
					'key'     => '_policy_expiration_date',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Add policy type filter if provided.
		if ( $policy_type ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_policy_type',
					'field'    => 'slug',
					'terms'    => $policy_type,
				),
			);
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		// Build response.
		$policies = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$policy_id = get_the_ID();

				// Get policy type.
				$types            = wp_get_object_terms( $policy_id, 'mcp_ai_policy_type', array( 'fields' => 'slugs' ) );
				$policy_type_slug = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : '';

				// Get member info.
				$member_id   = get_post_meta( $policy_id, '_policy_member_id', true );
				$member_name = '';
				if ( $member_id ) {
					$member      = get_post( $member_id );
					$member_name = $member ? $member->post_title : '';
				}

				$policies[] = array(
					'id'               => $policy_id,
					'policy_number'    => get_post_meta( $policy_id, '_policy_number', true ),
					'name'             => get_the_title(),
					'type'             => $policy_type_slug,
					'member_id'        => $member_id,
					'member_name'      => $member_name,
					'provider'         => get_post_meta( $policy_id, '_policy_provider', true ),
					'status'           => get_post_meta( $policy_id, '_policy_status', true ),
					'effective_date'   => get_post_meta( $policy_id, '_policy_effective_date', true ),
					'expiration_date'  => get_post_meta( $policy_id, '_policy_expiration_date', true ),
					'premium'          => get_post_meta( $policy_id, '_policy_premium', true ),
					'coverage_summary' => wp_trim_words( get_the_content(), 20 ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'    => true,
			'policies'   => $policies,
			'pagination' => array(
				'total'        => $query->found_posts,
				'total_pages'  => $query->max_num_pages,
				'current_page' => $page,
				'per_page'     => $per_page,
			),
		);
	}
}
