<?php
/**
 * Project Management Admin Menu Registry
 *
 * Registers the top-level "NV Projects" admin menu section and provides
 * the parent slug for all PM submenu pages.
 *
 * This mirrors the "NV CRM" pattern but is dedicated entirely to
 * Project Management toolkit pages (Command Center, Projects, Tasks,
 * Events, Blueprints, Settings).
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Project Management Admin Menu Registration Class
 */
class WP_MCP_AI_PM_Admin_Menu {

	/**
	 * Parent menu slug for the NV Projects top-level admin menu.
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'nvoos-pm-dashboard';

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
	 * Initialize the PM admin menu.
	 *
	 * Registers the top-level "NV Projects" menu at priority 25 so that
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
	 * Register the top-level "NV Projects" admin menu.
	 *
	 * This serves as the landing page for the PM section and
	 * renders the Command Center by default.
	 */
	public static function register_parent_menu() {
		self::$command_center_hook = add_menu_page(
			__( 'NV Projects', 'mcp-ai-wpoos-pro' ),
			__( 'NV Projects', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PARENT_SLUG,
			array( 'WP_MCP_AI_PM_Command_Center_Page', 'render_page' ),
			'dashicons-portfolio',
			31
		);
	}

	/**
	 * Register additional submenu pages under NV Projects.
	 *
	 * @since 2.6.0
	 */
	public static function register_submenus() {
		// Projects CPT link.
		if ( post_type_exists( 'mcp_ai_project' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Projects', 'mcp-ai-wpoos-pro' ),
				__( 'Projects', 'mcp-ai-wpoos-pro' ),
				'edit_posts',
				'edit.php?post_type=mcp_ai_project',
				'',
				28
			);
		}

		// Tasks CPT link.
		if ( post_type_exists( 'mcp_ai_task' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Tasks', 'mcp-ai-wpoos-pro' ),
				__( 'Tasks', 'mcp-ai-wpoos-pro' ),
				'edit_posts',
				'edit.php?post_type=mcp_ai_task',
				'',
				29
			);
		}

		// Events CPT link.
		if ( post_type_exists( 'mcp_ai_event' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Events', 'mcp-ai-wpoos-pro' ),
				__( 'Events', 'mcp-ai-wpoos-pro' ),
				'edit_posts',
				'edit.php?post_type=mcp_ai_event',
				'',
				30
			);
		}
	}

	/**
	 * Get the parent menu slug for PM submenu pages.
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
