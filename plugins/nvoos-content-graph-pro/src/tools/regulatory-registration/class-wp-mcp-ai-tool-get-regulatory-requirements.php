<?php
/**
 * WP_MCP_AI_Tool_Get_Regulatory_Requirements (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


/**
 * Gets regulatory requirements.
 */
class WP_MCP_AI_Tool_Get_Regulatory_Requirements implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_regulatory_requirements';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Regulatory Requirements', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves regulatory requirements for a specific country/authority. Filters by requirement type, product category, and mandatory status.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country'          => array(
					'type'        => 'string',
					'description' => __( 'Country code to filter by (required)', 'nvoos-content-graph-pro' ),
				),
				'authority'        => array(
					'type'        => 'string',
					'description' => __( 'Authority name to filter by (optional)', 'nvoos-content-graph-pro' ),
				),
				'requirement_type' => array(
					'type'        => 'string',
					'description' => __( 'Type to filter by: document, test, certification, ingredient_restriction, other (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'document', 'test', 'certification', 'ingredient_restriction', 'other' ),
				),
				'product_category' => array(
					'type'        => 'string',
					'description' => __( 'Product category to filter by (optional)', 'nvoos-content-graph-pro' ),
				),
				'mandatory_only'   => array(
					'type'        => 'boolean',
					'description' => __( 'Return only mandatory requirements (optional, default: false)', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'page'             => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination (optional, default: 1)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'per_page'         => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (optional, default: 50, max: 100)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 50,
				),
			),
			'required'             => array( 'country' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
			'idempotent',           // Can be called multiple times safely with same result.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['country'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Country is required.', 'nvoos-content-graph-pro' ),
			);
		}

		$page     = ! empty( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$per_page = ! empty( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 50;

		// Build meta query.
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'   => 'country',
				'value' => sanitize_text_field( $arguments['country'] ),
			),
		);

		if ( ! empty( $arguments['authority'] ) ) {
			$meta_query[] = array(
				'key'   => 'authority',
				'value' => sanitize_text_field( $arguments['authority'] ),
			);
		}

		if ( ! empty( $arguments['requirement_type'] ) ) {
			$meta_query[] = array(
				'key'   => 'requirement_type',
				'value' => sanitize_text_field( $arguments['requirement_type'] ),
			);
		}

		if ( ! empty( $arguments['product_category'] ) ) {
			$meta_query[] = array(
				'key'   => 'product_category',
				'value' => sanitize_text_field( $arguments['product_category'] ),
			);
		}

		if ( ! empty( $arguments['mandatory_only'] ) ) {
			$meta_query[] = array(
				'key'   => 'is_mandatory',
				'value' => '1',
			);
		}

		// Query requirements.
		$query_args = array(
			'post_type'      => 'mcp_ai_requirement',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_query'     => $meta_query,
			'orderby'        => 'meta_value',
			'meta_key'       => 'requirement_type',
			'order'          => 'ASC',
		);

		$query = new WP_Query( $query_args );

		$requirements = array();
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$requirements[] = array(
					'requirement_id'   => $post->ID,
					'title'            => $post->post_title,
					'description'      => $post->post_content,
					'country'          => get_post_meta( $post->ID, 'country', true ),
					'authority'        => get_post_meta( $post->ID, 'authority', true ),
					'requirement_type' => get_post_meta( $post->ID, 'requirement_type', true ),
					'is_mandatory'     => (bool) get_post_meta( $post->ID, 'is_mandatory', true ),
					'product_category' => get_post_meta( $post->ID, 'product_category', true ),
					'effective_date'   => get_post_meta( $post->ID, 'effective_date', true ),
					'reference_url'    => get_post_meta( $post->ID, 'reference_url', true ),
				);
			}
		}

		return array(
			'success'      => true,
			'country'      => $arguments['country'],
			'requirements' => $requirements,
			'total'        => $query->found_posts,
			'page'         => $page,
			'per_page'     => $per_page,
			'total_pages'  => $query->max_num_pages,
		);
	}
}
