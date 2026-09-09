<?php
/**
 * WP_MCP_AI_Tool_Generate_Blog_Layout (ecosystem port - Wave F2, site-creator tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/site-creator-toolkit/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Site_Creator_Toolkit
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
 * Generate Blog Layout Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_Blog_Layout implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if tool is available.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_blog_layout';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Blog Layout', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates blog listing and detail page layouts with categories, pagination, sidebar widgets, and featured post sections. Supports grid, list, and masonry layouts.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'layout_style'    => array(
					'type'        => 'string',
					'description' => __( 'Blog listing layout', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'grid', 'list', 'masonry', 'featured' ),
					'default'     => 'grid',
				),
				'include_sidebar' => array(
					'type'        => 'boolean',
					'description' => __( 'Include sidebar', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'posts_per_page'  => array(
					'type'        => 'integer',
					'description' => __( 'Posts per page', 'nvoos-content-graph-pro' ),
					'default'     => 10,
					'minimum'     => 5,
					'maximum'     => 20,
				),
				'show_featured'   => array(
					'type'        => 'boolean',
					'description' => __( 'Show featured posts section', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
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
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Blog layout data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'nvoos-content-graph-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize arguments.
		$layout_style    = isset( $arguments['layout_style'] ) ? sanitize_text_field( $arguments['layout_style'] ) : 'grid';
		$include_sidebar = isset( $arguments['include_sidebar'] ) ? (bool) $arguments['include_sidebar'] : true;
		$posts_per_page  = isset( $arguments['posts_per_page'] ) ? min( 20, max( 5, absint( $arguments['posts_per_page'] ) ) ) : 10;
		$show_featured   = isset( $arguments['show_featured'] ) ? (bool) $arguments['show_featured'] : true;

		// Generate blog layout.
		$blog_layout = array(
			'listing_page' => array(
				'layout'         => $layout_style,
				'posts_per_page' => $posts_per_page,
				'sections'       => array(),
			),
			'single_post'  => array(
				'layout'   => $include_sidebar ? 'with-sidebar' : 'full-width',
				'sections' => array(),
			),
		);

		// Build listing page sections.
		if ( $show_featured ) {
			$blog_layout['listing_page']['sections'][] = $this->generate_featured_section();
		}

		$blog_layout['listing_page']['sections'][] = $this->generate_category_filter();
		$blog_layout['listing_page']['sections'][] = $this->generate_posts_grid( $layout_style );
		$blog_layout['listing_page']['sections'][] = $this->generate_pagination();

		if ( $include_sidebar ) {
			$blog_layout['listing_page']['sidebar'] = $this->generate_sidebar();
		}

		// Build single post sections.
		$blog_layout['single_post']['sections'][] = $this->generate_post_header();
		$blog_layout['single_post']['sections'][] = $this->generate_post_content();
		$blog_layout['single_post']['sections'][] = $this->generate_post_meta();
		$blog_layout['single_post']['sections'][] = $this->generate_related_posts();

		if ( $include_sidebar ) {
			$blog_layout['single_post']['sidebar'] = $this->generate_sidebar();
		}

		return array(
			'success'     => true,
			'blog_layout' => $blog_layout,
			/* translators: 1: layout style, 2: number of sections */
			'summary'     => sprintf( __( 'Generated %1$s blog layout with %2$d sections.', 'nvoos-content-graph-pro' ), $layout_style, count( $blog_layout['listing_page']['sections'] ) ),
			'timestamp'   => current_time( 'mysql' ),
		);
	}

	/**
	 * Generate featured section.
	 *
	 * @since 1.2.0
	 *
	 * @return array Featured section.
	 */
	private function generate_featured_section() {
		return array(
			'type'    => 'featured-posts',
			'content' => array(
				'title' => 'Featured Posts',
				'count' => 3,
			),
		);
	}

	/**
	 * Generate category filter.
	 *
	 * @since 1.2.0
	 *
	 * @return array Category filter.
	 */
	private function generate_category_filter() {
		return array(
			'type'    => 'category-filter',
			'content' => array(
				'show_all' => true,
				'style'    => 'tabs',
			),
		);
	}

	/**
	 * Generate posts grid.
	 *
	 * @since 1.2.0
	 *
	 * @param string $layout Layout style.
	 * @return array Posts grid.
	 */
	private function generate_posts_grid( $layout ) {
		return array(
			'type'    => 'posts-grid',
			'content' => array(
				'layout'  => $layout,
				'columns' => 'grid' === $layout ? 3 : 1,
			),
		);
	}

	/**
	 * Generate pagination.
	 *
	 * @since 1.2.0
	 *
	 * @return array Pagination.
	 */
	private function generate_pagination() {
		return array(
			'type'    => 'pagination',
			'content' => array(
				'style' => 'numbers',
			),
		);
	}

	/**
	 * Generate sidebar.
	 *
	 * @since 1.2.0
	 *
	 * @return array Sidebar.
	 */
	private function generate_sidebar() {
		return array(
			'widgets' => array(
				array( 'type' => 'search' ),
				array( 'type' => 'categories' ),
				array( 'type' => 'recent-posts' ),
				array( 'type' => 'tags' ),
			),
		);
	}

	/**
	 * Generate post header.
	 *
	 * @since 1.2.0
	 *
	 * @return array Post header.
	 */
	private function generate_post_header() {
		return array(
			'type'    => 'post-header',
			'content' => array(
				'show_featured_image' => true,
				'show_meta'           => true,
			),
		);
	}

	/**
	 * Generate post content.
	 *
	 * @since 1.2.0
	 *
	 * @return array Post content.
	 */
	private function generate_post_content() {
		return array(
			'type'    => 'post-content',
			'content' => array(
				'show_table_of_contents' => true,
			),
		);
	}

	/**
	 * Generate post meta.
	 *
	 * @since 1.2.0
	 *
	 * @return array Post meta.
	 */
	private function generate_post_meta() {
		return array(
			'type'    => 'post-meta',
			'content' => array(
				'show_author'     => true,
				'show_date'       => true,
				'show_categories' => true,
				'show_tags'       => true,
				'show_share'      => true,
			),
		);
	}

	/**
	 * Generate related posts.
	 *
	 * @since 1.2.0
	 *
	 * @return array Related posts.
	 */
	private function generate_related_posts() {
		return array(
			'type'    => 'related-posts',
			'content' => array(
				'count' => 3,
				'title' => 'Related Posts',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
