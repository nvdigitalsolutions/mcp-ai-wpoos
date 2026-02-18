<?php
/**
 * Tool for listing and searching companies in the CRM system.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to list and search companies in the CRM.
 */
class WP_MCP_AI_Tool_Get_Companies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether Company CPT is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return post_type_exists( 'mcp_ai_company' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Get Companies tool is disabled because the CRM Toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_companies';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Companies', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List and search companies in the CRM. Filter by industry, size, target status, or location. Returns company details including contacts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Search term to match against company name or description.', 'mcp-ai-wpoos-pro' ),
				),
				'industry'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by industry sector.', 'mcp-ai-wpoos-pro' ),
				),
				'company_size'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by company size range.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( '1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5001+' ),
				),
				'target_status' => array(
					'type'        => 'string',
					'description' => __( 'Filter by target status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'prospect', 'target', 'in_discussion', 'client', 'not_interested' ),
				),
				'city'          => array(
					'type'        => 'string',
					'description' => __( 'Filter by city.', 'mcp-ai-wpoos-pro' ),
				),
				'state'         => array(
					'type'        => 'string',
					'description' => __( 'Filter by state or province.', 'mcp-ai-wpoos-pro' ),
				),
				'country'       => array(
					'type'        => 'string',
					'description' => __( 'Filter by country.', 'mcp-ai-wpoos-pro' ),
				),
				'per_page'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of companies per page. Default 20, max 100.', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'          => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default 1.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_company_cpt_missing', __( 'Company CPT is not registered. Enable CRM Toolkit in settings.', 'mcp-ai-wpoos-pro' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view companies.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build query arguments.
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'post_type'      => 'mcp_ai_company',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Add search if provided.
		if ( ! empty( $arguments['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		// Build meta query for filters.
		$meta_query = array( 'relation' => 'AND' );

		if ( ! empty( $arguments['industry'] ) ) {
			$meta_query[] = array(
				'key'     => '_company_industry',
				'value'   => sanitize_text_field( $arguments['industry'] ),
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $arguments['company_size'] ) ) {
			$meta_query[] = array(
				'key'   => '_company_size',
				'value' => sanitize_text_field( $arguments['company_size'] ),
			);
		}

		if ( ! empty( $arguments['target_status'] ) ) {
			$meta_query[] = array(
				'key'   => '_company_target_status',
				'value' => sanitize_text_field( $arguments['target_status'] ),
			);
		}

		if ( ! empty( $arguments['city'] ) ) {
			$meta_query[] = array(
				'key'     => '_company_city',
				'value'   => sanitize_text_field( $arguments['city'] ),
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $arguments['state'] ) ) {
			$meta_query[] = array(
				'key'     => '_company_state',
				'value'   => sanitize_text_field( $arguments['state'] ),
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $arguments['country'] ) ) {
			$meta_query[] = array(
				'key'     => '_company_country',
				'value'   => sanitize_text_field( $arguments['country'] ),
				'compare' => 'LIKE',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		// Build results.
		$companies = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				$companies[] = array(
					'id'            => $post_id,
					'company_name'  => get_the_title(),
					'description'   => get_the_content(),
					'industry'      => get_post_meta( $post_id, '_company_industry', true ),
					'company_size'  => get_post_meta( $post_id, '_company_size', true ),
					'website'       => get_post_meta( $post_id, '_company_website', true ),
					'city'          => get_post_meta( $post_id, '_company_city', true ),
					'state'         => get_post_meta( $post_id, '_company_state', true ),
					'country'       => get_post_meta( $post_id, '_company_country', true ),
					'phone'         => get_post_meta( $post_id, '_company_phone', true ),
					'revenue'       => get_post_meta( $post_id, '_company_revenue', true ),
					'target_status' => get_post_meta( $post_id, '_company_target_status', true ),
					'linkedin'      => get_post_meta( $post_id, '_company_linkedin', true ),
					'twitter'       => get_post_meta( $post_id, '_company_twitter', true ),
					'edit_url'      => get_edit_post_link( $post_id, 'raw' ),
					'created_date'  => get_the_date( 'Y-m-d H:i:s' ),
				);
			}
			wp_reset_postdata();
		}

		$total_companies = $query->found_posts;
		$total_pages     = $query->max_num_pages;

		return array(
			'success'         => true,
			'companies'       => $companies,
			'total_companies' => $total_companies,
			'total_pages'     => $total_pages,
			'current_page'    => $page,
			'per_page'        => $per_page,
			'filters_applied' => array_filter(
				array(
					'search'        => $arguments['search'] ?? null,
					'industry'      => $arguments['industry'] ?? null,
					'company_size'  => $arguments['company_size'] ?? null,
					'target_status' => $arguments['target_status'] ?? null,
					'city'          => $arguments['city'] ?? null,
					'state'         => $arguments['state'] ?? null,
					'country'       => $arguments['country'] ?? null,
				)
			),
			'message'         => sprintf(
				/* translators: %d: Number of companies found */
				__( 'Found %d companies matching your criteria.', 'mcp-ai-wpoos-pro' ),
				$total_companies
			),
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
			'profession_tags'       => array( 'sales_manager', 'business_development', 'marketing_manager' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires user capabilities.
			'read-only',            // Does not modify data.
			'local-only',           // No external API calls.
		);
	}
}
