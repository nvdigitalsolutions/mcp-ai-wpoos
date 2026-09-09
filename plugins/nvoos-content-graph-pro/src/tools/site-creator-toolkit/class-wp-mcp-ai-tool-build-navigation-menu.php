<?php
/**
 * WP_MCP_AI_Tool_Build_Navigation_Menu (ecosystem port - Wave F2, site-creator tool batch).
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
 * Build Navigation Menu Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Build_Navigation_Menu implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'build_navigation_menu';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Build Navigation Menu', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates smart navigation menus with dropdown support, mobile responsiveness, and accessibility. Generates menu structure and styling.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'menu_name'  => array(
					'type'        => 'string',
					'description' => __( 'Menu name', 'nvoos-content-graph-pro' ),
				),
				'menu_items' => array(
					'type'        => 'array',
					'description' => __( 'Menu items', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'label' => array( 'type' => 'string' ),
							'url'   => array( 'type' => 'string' ),
						),
					),
				),
				'style'      => array(
					'type'        => 'string',
					'description' => __( 'Menu style', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'horizontal', 'vertical', 'mega', 'hamburger' ),
					'default'     => 'horizontal',
				),
				'sticky'     => array(
					'type'        => 'boolean',
					'description' => __( 'Enable sticky navigation', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'menu_name' ),
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
	 * @return array|WP_Error Navigation menu data or error.
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
		$menu_name  = isset( $arguments['menu_name'] ) ? sanitize_text_field( $arguments['menu_name'] ) : '';
		$menu_items = isset( $arguments['menu_items'] ) && is_array( $arguments['menu_items'] ) ? $arguments['menu_items'] : array();
		$style      = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'horizontal';
		$sticky     = isset( $arguments['sticky'] ) ? (bool) $arguments['sticky'] : false;

		if ( empty( $menu_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Menu name is required.', 'nvoos-content-graph-pro' ) );
		}

		// Use default items if none provided.
		if ( empty( $menu_items ) ) {
			$menu_items = array(
				array(
					'label' => 'Home',
					'url'   => '/',
				),
				array(
					'label' => 'About',
					'url'   => '/about',
				),
				array(
					'label' => 'Services',
					'url'   => '/services',
				),
				array(
					'label' => 'Blog',
					'url'   => '/blog',
				),
				array(
					'label' => 'Contact',
					'url'   => '/contact',
				),
			);
		}

		// Generate navigation menu.
		$nav_menu = array(
			'name'     => $menu_name,
			'style'    => $style,
			'sticky'   => $sticky,
			'items'    => array_map(
				function ( $item ) {
					return array(
						'label' => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '',
						'url'   => isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '#',
					);
				},
				$menu_items
			),
			'features' => array(
				'responsive'    => true,
				'accessibility' => true,
				'search'        => 'horizontal' === $style,
			),
		);

		return array(
			'success'   => true,
			'nav_menu'  => $nav_menu,
			/* translators: 1: navigation menu style, 2: number of menu items */
			'summary'   => sprintf( __( 'Generated %1$s navigation menu with %2$d items.', 'nvoos-content-graph-pro' ), $style, count( $menu_items ) ),
			'timestamp' => current_time( 'mysql' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
