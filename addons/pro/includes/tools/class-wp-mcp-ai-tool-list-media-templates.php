<?php
/**
 * Tool for listing media templates.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List available media templates with filtering options.
 */
class WP_MCP_AI_Tool_List_Media_Templates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_media_templates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Media Templates', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List available media templates with optional filtering by operation type, category, or search term. Returns template ID, title, operation, parameters, usage stats, and categories.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'operation'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by operation type: add_logo, resize_graphic, ai_enhance, ai_style, ai_background, ai_retouch', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add_logo', 'resize_graphic', 'expand_scene', 'ai_enhance', 'ai_style', 'ai_background', 'ai_retouch' ),
				),
				'category'       => array(
					'type'        => 'string',
					'description' => __( 'Filter by category slug (e.g., social-media, e-commerce, branding)', 'mcp-ai-wpoos-pro' ),
				),
				'search'         => array(
					'type'        => 'string',
					'description' => __( 'Search templates by title or description', 'mcp-ai-wpoos-pro' ),
				),
				'include_preset' => array(
					'type'        => 'boolean',
					'description' => __( 'Include preset templates. Default: true', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'per_page'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of templates per page. Default: 20', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'           => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Media Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Parse arguments.
		$operation      = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : '';
		$category       = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';
		$search         = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$include_preset = isset( $arguments['include_preset'] ) ? (bool) $arguments['include_preset'] : true;
		$per_page       = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page           = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_media_tpl',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Filter by operation.
		if ( ! empty( $operation ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_mcp_ai_template_operation',
					'value' => $operation,
				),
			);
		}

		// Filter by category.
		if ( ! empty( $category ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_tpl_category',
					'field'    => 'slug',
					'terms'    => $category,
				),
			);
		}

		// Search by title/description.
		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		// Exclude presets if requested.
		if ( ! $include_preset ) {
			if ( ! isset( $query_args['meta_query'] ) ) {
				$query_args['meta_query'] = array();
			}
			$query_args['meta_query'][] = array(
				'key'     => '_mcp_ai_template_is_preset',
				'compare' => 'NOT EXISTS',
			);
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		// Build templates array.
		$templates = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				// Get template meta.
				$operation_type = get_post_meta( $post_id, '_mcp_ai_template_operation', true );
				$parameters     = get_post_meta( $post_id, '_mcp_ai_template_parameters', true );
				$usage_count    = absint( get_post_meta( $post_id, '_mcp_ai_template_usage_count', true ) );
				$last_used      = get_post_meta( $post_id, '_mcp_ai_template_last_used', true );
				$is_preset      = (bool) get_post_meta( $post_id, '_mcp_ai_template_is_preset', true );
				$preset_id      = get_post_meta( $post_id, '_mcp_ai_template_preset_id', true );

				// Get categories.
				$terms      = wp_get_object_terms( $post_id, 'mcp_ai_tpl_category' );
				$categories = array();
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					foreach ( $terms as $term ) {
						$categories[] = array(
							'slug' => $term->slug,
							'name' => $term->name,
						);
					}
				}

				// Decode parameters.
				$params_decoded = array();
				if ( ! empty( $parameters ) ) {
					$params_decoded = json_decode( $parameters, true );
					if ( json_last_error() !== JSON_ERROR_NONE ) {
						$params_decoded = array();
					}
				}

				// Build template data.
				$templates[] = array(
					'id'          => $post_id,
					'title'       => get_the_title(),
					'description' => get_the_content(),
					'operation'   => $operation_type,
					'parameters'  => $params_decoded,
					'usage_count' => $usage_count,
					'last_used'   => $last_used ? $last_used : null,
					'categories'  => $categories,
					'is_preset'   => $is_preset,
					'preset_id'   => $preset_id ? $preset_id : null,
					'created'     => get_the_date( 'c' ),
					'modified'    => get_the_modified_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		// Build response.
		return array(
			'success'    => true,
			'templates'  => $templates,
			'pagination' => array(
				'total'       => $query->found_posts,
				'per_page'    => $per_page,
				'current'     => $page,
				'total_pages' => $query->max_num_pages,
			),
		);
	}
}
