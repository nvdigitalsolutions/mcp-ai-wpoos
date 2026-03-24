<?php
/**
 * Plugin Constants
 *
 * Defines all plugin-wide constants. Must be included after WP_MCP_AI_FILE has
 * been set in the main plugin file, because PATH and URL are derived from it.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_VERSION' ) ) {
	define( 'WP_MCP_AI_VERSION', '1.1.5' );
}

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
	define( 'WP_MCP_AI_PATH', plugin_dir_path( WP_MCP_AI_FILE ) );
}

if ( ! defined( 'WP_MCP_AI_URL' ) ) {
	define( 'WP_MCP_AI_URL', plugin_dir_url( WP_MCP_AI_FILE ) );
}

/**
 * Base version mode flag.
 *
 * The base plugin supports PHP 7.4+ and ships all tools in includes/tools/.
 * Every tool included in the base plugin is available to site owners without
 * restriction — tools for optional third-party plugins (WooCommerce, JetEngine,
 * etc.) are present but self-report as unavailable via is_available() when
 * those plugins are not installed.
 *
 * The Pro addon requires PHP 8.1+ and adds genuinely NEW tools that leverage
 * modern PHP features (enums, readonly properties, fibers, named arguments).
 * It does not "unlock" functionality that already exists in the base plugin.
 *
 * NOTE: This constant no longer affects which tools are registered. All tools
 * in includes/tools/ are always attempted, with is_available() determining
 * runtime availability. The constant is preserved for backward compatibility
 * with any filters or third-party code that reads it, and for the WordPress.org
 * build entry point (mcp-ai-wpoos-base.php) which sets it to true.
 */
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
	define( 'WP_MCP_AI_BASE_VERSION', false );
}

/**
 * Pro Dashboard enabled.
 *
 * Defaults to true. Set to false to disable Pro Dashboard features.
 */
if ( ! defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) ) {
	define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );
}
