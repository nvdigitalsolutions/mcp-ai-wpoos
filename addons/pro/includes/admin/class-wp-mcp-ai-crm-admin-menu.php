<?php
/**
 * CRM Admin Menu Registry
 *
 * Registers the top-level "NV CRM" admin menu section and provides
 * the parent slug for all CRM submenu pages.
 *
 * This mirrors the "NV oOS Pro Dashboard" pattern but is dedicated
 * entirely to CRM toolkit pages (Command Center, Research & Add,
 * Settings, etc.).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.24
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Admin Menu Registration Class
 */
class WP_MCP_AI_CRM_Admin_Menu {

	/**
	 * Parent menu slug for the NV CRM top-level admin menu.
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'nvoos-crm-dashboard';

	/**
	 * Page hook for the Command Center landing page.
	 *
	 * @var string|null
	 */
	private static $command_center_hook = null;

	/**
	 * Whether the menu has been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Initialize the CRM admin menu.
	 *
	 * Registers the top-level "NV CRM" menu at priority 25 so that
	 * submenu pages registered at priority 26+ can attach to it.
	 */
	public static function init() {
		if ( self::$registered ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_parent_menu' ), 25 );
		add_action( 'admin_menu', array( __CLASS__, 'register_submenus' ), 28 );
		self::$registered = true;
	}

	/**
	 * Register the top-level "NV CRM" admin menu.
	 *
	 * This serves as the landing page for the CRM section and
	 * redirects to the Command Center by default.
	 */
	public static function register_parent_menu() {
		self::$command_center_hook = add_menu_page(
			__( 'NV CRM', 'mcp-ai-wpoos-pro' ),
			__( 'NV CRM', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PARENT_SLUG,
			array( 'WP_MCP_AI_CRM_Command_Center_Page', 'render_page' ),
			'dashicons-groups',
			30
		);
	}

	/**
	 * Register additional submenu pages under NV CRM.
	 *
	 * @since 2.6.0
	 */
	public static function register_submenus() {
		// Customers submenu — links to the Customer CPT listing.
		if ( post_type_exists( 'mcp_ai_customer' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Customers', 'mcp-ai-wpoos-pro' ),
				__( 'Customers', 'mcp-ai-wpoos-pro' ),
				'edit_posts',
				'edit.php?post_type=mcp_ai_customer',
				'',
				28
			);
		}
	}

	/**
	 * Get the parent menu slug for CRM submenu pages.
	 *
	 * @return string
	 */
	public static function get_parent_slug() {
		return self::PARENT_SLUG;
	}

	/**
	 * Get the command center page hook.
	 *
	 * @return string|null
	 */
	public static function get_command_center_hook() {
		return self::$command_center_hook;
	}
}
