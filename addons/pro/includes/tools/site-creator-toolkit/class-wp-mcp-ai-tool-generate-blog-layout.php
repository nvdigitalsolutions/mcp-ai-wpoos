<?php
/**
 * Generate Blog Layout Tool
 *
 * Creates blog listing and detail page layouts with categories, pagination,
 * sidebar widgets, and featured post sections.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Generate Blog Layout', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates blog listing and detail page layouts with categories, pagination, sidebar widgets, and featured post sections. Supports grid, list, and masonry layouts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'layout_style'     => array(
					'type'        => 'string',
					'description' => __( 'Blog listing layout', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'grid', 'list', 'masonry', 'featured' ),
					'default'     => 'grid',
				),
				'include_sidebar'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include sidebar', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'posts_per_page'   => array(
					'type'        => 'integer',
					'description' => __( 'Posts per page', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 5,
					'maximum'     => 20,
				),
				'show_featured'    => array(
					'type'        => 'boolean',
					'description' => __( 'Show featured posts section', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
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
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
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
			'summary'     => sprintf( __( 'Generated %s blog layout with %d sections.', 'mcp-ai-wpoos-pro' ), $layout_style, count( $blog_layout['listing_page']['sections'] ) ),
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
