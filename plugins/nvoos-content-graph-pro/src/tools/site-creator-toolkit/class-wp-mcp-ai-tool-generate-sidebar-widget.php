<?php
/**
 * WP_MCP_AI_Tool_Generate_Sidebar_Widget (ecosystem port - Wave F2, site-creator tool batch).
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
 * Generate Sidebar Widget Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_Sidebar_Widget implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'generate_sidebar_widget';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Sidebar Widget', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates sidebar widgets with dynamic content including recent posts, categories, tags, search, and custom areas.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'widget_type' => array(
					'type'        => 'string',
					'description' => __( 'Sidebar widget type', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'recent-posts', 'categories', 'tags', 'search', 'custom-html', 'newsletter' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Widget title', 'nvoos-content-graph-pro' ),
				),
				'count'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to display (for lists)', 'nvoos-content-graph-pro' ),
					'default'     => 5,
					'minimum'     => 1,
					'maximum'     => 10,
				),
			),
			'required'             => array( 'widget_type' ),
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
	 * @return array|WP_Error Sidebar widget data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'nvoos-content-graph-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize arguments.
		$widget_type = isset( $arguments['widget_type'] ) ? sanitize_text_field( $arguments['widget_type'] ) : 'recent-posts';
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : $this->get_default_title( $widget_type );
		$count       = isset( $arguments['count'] ) ? min( 10, max( 1, absint( $arguments['count'] ) ) ) : 5;

		// Generate sidebar widget.
		$sidebar_widget = array(
			'type'     => $widget_type,
			'title'    => $title,
			'settings' => $this->get_widget_settings( $widget_type, $count ),
		);

		return array(
			'success'        => true,
			'sidebar_widget' => $sidebar_widget,
			/* translators: %s: widget type */
			'summary'        => sprintf( __( 'Generated %s sidebar widget.', 'nvoos-content-graph-pro' ), $widget_type ),
			'timestamp'      => current_time( 'mysql' ),
		);
	}

	/**
	 * Get default title for widget type.
	 *
	 * @since 1.2.0
	 *
	 * @param string $widget_type Widget type.
	 * @return string Default title.
	 */
	private function get_default_title( $widget_type ) {
		$titles = array(
			'recent-posts' => 'Recent Posts',
			'categories'   => 'Categories',
			'tags'         => 'Tags',
			'search'       => 'Search',
			'custom-html'  => 'Custom Content',
			'newsletter'   => 'Newsletter',
		);

		return isset( $titles[ $widget_type ] ) ? $titles[ $widget_type ] : 'Widget';
	}

	/**
	 * Get widget settings.
	 *
	 * @since 1.2.0
	 *
	 * @param string $widget_type Widget type.
	 * @param int    $count       Item count.
	 * @return array Settings.
	 */
	private function get_widget_settings( $widget_type, $count ) {
		$settings = array();

		switch ( $widget_type ) {
			case 'recent-posts':
				$settings = array(
					'count'          => $count,
					'show_date'      => true,
					'show_thumbnail' => true,
				);
				break;

			case 'categories':
				$settings = array(
					'show_count'   => true,
					'hierarchical' => true,
					'dropdown'     => false,
				);
				break;

			case 'tags':
				$settings = array(
					'count'      => $count,
					'show_count' => true,
					'style'      => 'cloud',
				);
				break;

			case 'search':
				$settings = array(
					'placeholder' => 'Search...',
					'button_text' => 'Search',
				);
				break;

			case 'newsletter':
				$settings = array(
					'description' => 'Subscribe to our newsletter',
					'button_text' => 'Subscribe',
				);
				break;
		}

		return $settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
