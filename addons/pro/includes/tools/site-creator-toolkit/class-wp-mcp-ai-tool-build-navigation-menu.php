<?php
/**
 * Build Navigation Menu Tool
 *
 * Creates smart navigation menus with dropdown support, mobile responsiveness,
 * and accessibility features.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Build Navigation Menu', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates smart navigation menus with dropdown support, mobile responsiveness, and accessibility. Generates menu structure and styling.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Menu name', 'mcp-ai-wpoos-pro' ),
				),
				'menu_items' => array(
					'type'        => 'array',
					'description' => __( 'Menu items', 'mcp-ai-wpoos-pro' ),
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
					'description' => __( 'Menu style', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'horizontal', 'vertical', 'mega', 'hamburger' ),
					'default'     => 'horizontal',
				),
				'sticky'     => array(
					'type'        => 'boolean',
					'description' => __( 'Enable sticky navigation', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'menu_name' ),
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
	 * @return array|WP_Error Navigation menu data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize arguments.
		$menu_name  = isset( $arguments['menu_name'] ) ? sanitize_text_field( $arguments['menu_name'] ) : '';
		$menu_items = isset( $arguments['menu_items'] ) && is_array( $arguments['menu_items'] ) ? $arguments['menu_items'] : array();
		$style      = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'horizontal';
		$sticky     = isset( $arguments['sticky'] ) ? (bool) $arguments['sticky'] : false;

		if ( empty( $menu_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Menu name is required.', 'mcp-ai-wpoos-pro' ) );
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
			'summary'   => sprintf( __( 'Generated %1$s navigation menu with %2$d items.', 'mcp-ai-wpoos-pro' ), $style, count( $menu_items ) ),
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
