<?php
/**
 * Media Admin Menu Registry
 *
 * Registers the top-level "NV Media" admin menu section and provides
 * the parent slug for all Media submenu pages.
 *
 * This mirrors the "NV CRM" and "NV Projects" patterns but is dedicated
 * entirely to Media toolkit pages (Command Center, Templates, Collections,
 * Blueprints, Settings).
 *
 * @package WP_MCP_AI_Pro
 * @since 3.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Admin Menu Registration Class
 */
class WP_MCP_AI_Media_Admin_Menu {

	/**
	 * Parent menu slug for the NV Media top-level admin menu.
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'nvoos-media-dashboard';

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
	 * Initialize the Media admin menu.
	 *
	 * Registers the top-level "NV Media" menu at priority 25 so that
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
	 * Register the top-level "NV Media" admin menu.
	 *
	 * This serves as the landing page for the Media section and
	 * renders the Command Center by default.
	 */
	public static function register_parent_menu() {
		self::$command_center_hook = add_menu_page(
			__( 'NV Media', 'mcp-ai-wpoos-pro' ),
			__( 'NV Media', 'mcp-ai-wpoos-pro' ),
			'upload_files',
			self::PARENT_SLUG,
			array( 'WP_MCP_AI_Media_Command_Center_Page', 'render_page' ),
			'dashicons-admin-media',
			32
		);
	}

	/**
	 * Register additional submenu pages under NV Media.
	 *
	 * @since 3.9.0
	 */
	public static function register_submenus() {
		// Media Templates CPT link.
		if ( post_type_exists( 'mcp_ai_media_tpl' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Templates', 'mcp-ai-wpoos-pro' ),
				__( 'Templates', 'mcp-ai-wpoos-pro' ),
				'upload_files',
				'edit.php?post_type=mcp_ai_media_tpl',
				'',
				28
			);
		}

		// Media Collections CPT link.
		if ( post_type_exists( 'mcp_ai_media_coll' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Collections', 'mcp-ai-wpoos-pro' ),
				__( 'Collections', 'mcp-ai-wpoos-pro' ),
				'upload_files',
				'edit.php?post_type=mcp_ai_media_coll',
				'',
				29
			);
		}

		// WordPress Media Library link.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Media Library', 'mcp-ai-wpoos-pro' ),
			__( 'Media Library', 'mcp-ai-wpoos-pro' ),
			'upload_files',
			'upload.php',
			'',
			30
		);
	}

	/**
	 * Get the parent menu slug for Media submenu pages.
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
