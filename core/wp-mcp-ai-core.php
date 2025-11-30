<?php
/**
 * Plugin Name: WP MCP AI Core
 * Plugin URI: https://github.com/nvdigitalsolutions/wp-mcp-ai
 * Description: Core MCP (Model Context Protocol) server framework for WordPress. Provides a stable API for AI tool integration.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wp-mcp-ai-core
 * Domain Path: /languages
 * Network: true
 *
 * @package WP_MCP_AI_Core
 *
 * Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check PHP version compatibility before loading any classes.
 */
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	/**
	 * Display admin notice for PHP version incompatibility.
	 */
	function wp_mcp_ai_core_php_version_notice() {
		$message = sprintf(
			'<strong>WP MCP AI Core</strong> requires PHP version %2$s or higher. You are running PHP version %1$s. Please contact your hosting provider to upgrade PHP.',
			PHP_VERSION,
			'7.4.0'
		);
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses_post( $message )
		);
	}
	add_action( 'admin_notices', 'wp_mcp_ai_core_php_version_notice' );
	return;
}

// Core plugin constants.
if ( ! defined( 'WP_MCP_AI_CORE_VERSION' ) ) {
	define( 'WP_MCP_AI_CORE_VERSION', '1.0.0' );
}
if ( ! defined( 'WP_MCP_AI_CORE_FILE' ) ) {
	define( 'WP_MCP_AI_CORE_FILE', __FILE__ );
}
if ( ! defined( 'WP_MCP_AI_CORE_PATH' ) ) {
	define( 'WP_MCP_AI_CORE_PATH', plugin_dir_path( WP_MCP_AI_CORE_FILE ) );
}
if ( ! defined( 'WP_MCP_AI_CORE_URL' ) ) {
	define( 'WP_MCP_AI_CORE_URL', plugin_dir_url( WP_MCP_AI_CORE_FILE ) );
}

/**
 * ============================================================================
 * PUBLIC API FUNCTIONS
 *
 * These functions provide a stable interface for add-ons and third-party
 * integrations to interact with the MCP Core framework.
 * ============================================================================
 */

if ( ! function_exists( 'wp_mcp_ai_core_loaded' ) ) {
	/**
	 * Check if WP MCP AI Core is loaded.
	 *
	 * This function serves as a marker for add-ons to verify that
	 * the core plugin is active before registering their features.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always returns true when Core is loaded.
	 */
	function wp_mcp_ai_core_loaded() {
		return true;
	}
}

if ( ! function_exists( 'wp_mcp_ai_register_tool' ) ) {
	/**
	 * Register a tool with the MCP server.
	 *
	 * This is the primary API for registering custom tools from add-ons
	 * or third-party plugins.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_MCP_AI_Core_Tool_Interface|string $tool Tool instance or class name.
	 * @return bool True if registration succeeded, false otherwise.
	 */
	function wp_mcp_ai_register_tool( $tool ) {
		$server = WP_MCP_AI_Core_Server::get_instance();
		return $server->register_tool( $tool );
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_tool' ) ) {
	/**
	 * Retrieve a registered tool by slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Tool slug.
	 * @return WP_MCP_AI_Core_Tool_Interface|null Tool instance or null if not found.
	 */
	function wp_mcp_ai_get_tool( $slug ) {
		$server = WP_MCP_AI_Core_Server::get_instance();
		return $server->get_tool( $slug );
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_registered_tools' ) ) {
	/**
	 * Retrieve all registered tools.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_MCP_AI_Core_Tool_Interface[] Array of registered tool instances.
	 */
	function wp_mcp_ai_get_registered_tools() {
		$server = WP_MCP_AI_Core_Server::get_instance();
		return $server->get_tools();
	}
}

if ( ! function_exists( 'wp_mcp_ai_execute_tool' ) ) {
	/**
	 * Execute a registered tool.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug      Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return mixed|WP_Error Tool result or error.
	 */
	function wp_mcp_ai_execute_tool( $slug, $arguments = array(), $context = array() ) {
		$server = WP_MCP_AI_Core_Server::get_instance();
		return $server->execute_tool( $slug, $arguments, $context );
	}
}

/**
 * ============================================================================
 * CORE INTERFACES
 * ============================================================================
 */

// Load interfaces.
require_once WP_MCP_AI_CORE_PATH . 'includes/src/Interfaces/interface-wp-mcp-ai-core-tool.php';

/**
 * ============================================================================
 * MCP SERVER
 * ============================================================================
 */

// Load MCP Server.
require_once WP_MCP_AI_CORE_PATH . 'includes/src/Server/class-wp-mcp-ai-core-server.php';

/**
 * ============================================================================
 * CORE TOOLS
 * ============================================================================
 */

// Load baseline tools.
require_once WP_MCP_AI_CORE_PATH . 'includes/src/Tools/class-wp-mcp-ai-core-tool-posts.php';
require_once WP_MCP_AI_CORE_PATH . 'includes/src/Tools/class-wp-mcp-ai-core-tool-media.php';
require_once WP_MCP_AI_CORE_PATH . 'includes/src/Tools/class-wp-mcp-ai-core-tool-users.php';
require_once WP_MCP_AI_CORE_PATH . 'includes/src/Tools/class-wp-mcp-ai-core-tool-taxonomies.php';

/**
 * ============================================================================
 * BOOTSTRAP
 * ============================================================================
 */

if ( ! function_exists( 'wp_mcp_ai_core_init' ) ) {
	/**
	 * Initialize WP MCP AI Core.
	 *
	 * Bootstraps the MCP server, registers baseline tools, and fires the
	 * action hook for add-ons to register their tools.
	 *
	 * @since 1.0.0
	 */
	function wp_mcp_ai_core_init() {
		$server = WP_MCP_AI_Core_Server::get_instance();
		$server->init();

		// Register baseline tools.
		$server->register_tool( new WP_MCP_AI_Core_Tool_Posts() );
		$server->register_tool( new WP_MCP_AI_Core_Tool_Media() );
		$server->register_tool( new WP_MCP_AI_Core_Tool_Users() );
		$server->register_tool( new WP_MCP_AI_Core_Tool_Taxonomies() );

		/**
		 * Allow add-ons and third-party plugins to register tools.
		 *
		 * This action is the primary extension point for the MCP Core plugin.
		 * Add-ons should hook into this action to register their tools.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_MCP_AI_Core_Server $server The MCP server instance.
		 */
		do_action( 'wp_mcp_ai_register_tools', $server );

		/**
		 * Fires after WP MCP AI Core has completed initialization.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wp_mcp_ai_core_init' );
	}
}

// Initialize on plugins_loaded to allow add-ons to hook in.
add_action( 'plugins_loaded', 'wp_mcp_ai_core_init', 10 );

/**
 * ============================================================================
 * ACTIVATION / DEACTIVATION
 * ============================================================================
 */

/**
 * Plugin activation handler.
 *
 * @param bool $network_wide Whether activated network-wide.
 */
function wp_mcp_ai_core_activate( $network_wide = false ) {
	// Flush rewrite rules for REST endpoints.
	flush_rewrite_rules();
}
register_activation_hook( WP_MCP_AI_CORE_FILE, 'wp_mcp_ai_core_activate' );

/**
 * Plugin deactivation handler.
 *
 * @param bool $network_wide Whether deactivated network-wide.
 */
function wp_mcp_ai_core_deactivate( $network_wide = false ) {
	flush_rewrite_rules();
}
register_deactivation_hook( WP_MCP_AI_CORE_FILE, 'wp_mcp_ai_core_deactivate' );
