<?php
/**
 * Tool for getting regulatory requirements in the Regulatory Registration system.
 *
 * Allows AI assistants to retrieve country-specific regulatory requirements.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Get Regulatory Requirements', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves regulatory requirements for a specific country/authority. Filters by requirement type, product category, and mandatory status.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Country code to filter by (required)', 'mcp-ai-wpoos-pro' ),
				),
				'authority'        => array(
					'type'        => 'string',
					'description' => __( 'Authority name to filter by (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'requirement_type' => array(
					'type'        => 'string',
					'description' => __( 'Type to filter by: document, test, certification, ingredient_restriction, other (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'document', 'test', 'certification', 'ingredient_restriction', 'other' ),
				),
				'product_category' => array(
					'type'        => 'string',
					'description' => __( 'Product category to filter by (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'mandatory_only'   => array(
					'type'        => 'boolean',
					'description' => __( 'Return only mandatory requirements (optional, default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'page'             => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'per_page'         => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (optional, default: 50, max: 100)', 'mcp-ai-wpoos-pro' ),
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
	public function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['country'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Country is required.', 'mcp-ai-wpoos-pro' ),
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
			'post_type'      => 'mcp_ai_reg_requirement',
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
