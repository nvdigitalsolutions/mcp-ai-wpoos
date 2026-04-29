<?php
/**
 * Plugin Name: NV Digital Open Operator System Complete (oOS)
 * Plugin URI: https://nvdigitalsolutions.com/wpoos
 * Description: AI Assistant framework with OpenAI, Gemini, and Ollama integration. Includes 230+ tools for content management, media generation, research, and site operations out of the box. Optional Pro addon (PHP 8.1+) adds advanced AI toolkits on top.
 * Version: 1.1.12
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: mcp-ai-wpoos
 * Domain Path: /languages
 * Network: true
 *
 * @package WP_MCP_AI
 *
 * phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Main plugin entry point; file is intentionally named after the plugin slug, not a class.
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevent double-loading of the plugin.
 * This check must come before any constants are defined to ensure we exit early
 * if the plugin has already been loaded through another entry point.
 */
if ( function_exists( 'wp_mcp_ai_core_loaded' ) ) {
	return;
}

/**
 * WP_MCP_AI_FILE must be defined here — it is tied to __FILE__ in this specific file.
 * All other plugin constants are centralised in includes/bootstrap/constants.php.
 */
if ( ! defined( 'WP_MCP_AI_FILE' ) ) {
	define( 'WP_MCP_AI_FILE', __FILE__ );
}

/**
 * Check PHP version compatibility before loading any classes.
 *
 * This plugin requires PHP 7.4 or later. On older PHP versions, class files
 * will fail to parse with syntax errors. We check the version early to provide
 * a clear error message instead of cryptic parse errors.
 */
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	/**
	 * Display admin notice for PHP version incompatibility.
	 */
	function wp_mcp_ai_php_version_notice() {
		$message = sprintf(
			'<strong>Open Operator System</strong> requires PHP version %2$s or higher. You are running PHP version %1$s. Please contact your hosting provider to upgrade PHP.',
			PHP_VERSION,
			'7.4.0'
		);
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses_post( $message )
		);
	}

	/**
	 * Prevent plugin activation on incompatible PHP versions.
	 */
	function wp_mcp_ai_deactivate_self() {
		deactivate_plugins( plugin_basename( WP_MCP_AI_FILE ) );
	}

	add_action( 'admin_notices', 'wp_mcp_ai_php_version_notice' );
	add_action( 'admin_init', 'wp_mcp_ai_deactivate_self' );

	return;
}

// Load bootstrap files in dependency order.
require_once __DIR__ . '/includes/bootstrap/constants.php';
require_once __DIR__ . '/includes/bootstrap/autoload.php';
require_once __DIR__ . '/includes/bootstrap/helpers.php';
require_once __DIR__ . '/includes/bootstrap/cron.php';
require_once __DIR__ . '/includes/bootstrap/hooks.php';
require_once __DIR__ . '/includes/bootstrap/loader.php';
require_once __DIR__ . '/includes/bootstrap/activation.php';
require_once __DIR__ . '/includes/class-wp-mcp-ai-plugin.php';

// Register lifecycle hooks (must reference WP_MCP_AI_FILE, defined above).
register_activation_hook( WP_MCP_AI_FILE, 'wp_mcp_ai_activate' );
register_deactivation_hook( WP_MCP_AI_FILE, 'wp_mcp_ai_deactivate' );
register_uninstall_hook( WP_MCP_AI_FILE, 'wp_mcp_ai_uninstall' );

// Boot the plugin on plugins_loaded.
if ( ! has_action( 'plugins_loaded', 'wp_mcp_ai_bootstrap' ) ) {
	add_action( 'plugins_loaded', 'wp_mcp_ai_bootstrap', 20 );
}
