<?php
/**
 * Tool for listing insurance policies.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List insurance policies.
 */
class WP_MCP_AI_Tool_List_Policies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_policies';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Policies', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists insurance policies with optional filtering by member, policy type, and status.', 'mcp-ai-wpoos-pro' );
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
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'expired', 'pending', 'cancelled', '' ),
				),
				'per_page'    => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'        => array(
					'type'        => 'integer',
					'description' => __( 'Page number (default: 1)', 'mcp-ai-wpoos-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list policies.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize inputs.
		$member_id   = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$policy_type = isset( $arguments['policy_type'] ) ? sanitize_key( $arguments['policy_type'] ) : '';
		$status      = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		$per_page    = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page        = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		// Validate per_page.
		if ( $per_page < 1 || $per_page > 100 ) {
			$per_page = 20;
		}

		// Build query.
		$query_args = array(
			'post_type'      => 'mcp_ai_policy',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Build meta query for filters.
		$meta_query = array( 'relation' => 'AND' );

		if ( $member_id ) {
			$meta_query[] = array(
				'key'   => '_policy_member_id',
				'value' => $member_id,
			);
		}

		if ( $status ) {
			$meta_query[] = array(
				'key'   => '_policy_status',
				'value' => $status,
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Add taxonomy filter if provided.
		if ( $policy_type ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_policy_type',
					'field'    => 'slug',
					'terms'    => $policy_type,
				),
			);
		}

		$query = new WP_Query( $query_args );

		$policies = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$policy_id = get_the_ID();

				// Get policy type.
				$types = wp_get_object_terms( $policy_id, 'mcp_ai_policy_type', array( 'fields' => 'slugs' ) );
				$type  = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : '';

				// Get member info.
				$mid   = get_post_meta( $policy_id, '_policy_member_id', true );
				$mname = '';
				if ( $mid ) {
					$mem   = get_post( $mid );
					$mname = $mem ? $mem->post_title : '';
				}

				$policies[] = array(
					'id'              => $policy_id,
					'policy_number'   => get_post_meta( $policy_id, '_policy_number', true ),
					'name'            => get_the_title(),
					'type'            => $type,
					'member_id'       => $mid,
					'member_name'     => $mname,
					'provider'        => get_post_meta( $policy_id, '_policy_provider', true ),
					'status'          => get_post_meta( $policy_id, '_policy_status', true ),
					'effective_date'  => get_post_meta( $policy_id, '_policy_effective_date', true ),
					'expiration_date' => get_post_meta( $policy_id, '_policy_expiration_date', true ),
					'premium'         => get_post_meta( $policy_id, '_policy_premium', true ),
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
