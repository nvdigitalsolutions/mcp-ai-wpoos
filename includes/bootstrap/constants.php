<?php
/**
 * Plugin Constants
 *
 * Defines all plugin-wide constants. Must be included after WP_MCP_AI_FILE has
 * been set in the main plugin file, because PATH and URL are derived from it.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
 * Base version mode flag — legacy/backward-compatibility only.
 *
 * This constant previously restricted the tool registry to a subset of tools.
 * That restriction has been removed: all tools in includes/tools/ are always
 * attempted on every installation; tools for optional third-party plugins
 * (WooCommerce, JetEngine, etc.) self-report as unavailable via is_available()
 * when those plugins are not installed.
 *
 * The constant is preserved so that:
 *  1. Third-party code relying on the wp_mcp_ai_base_version filter continues to work.
 *  2. The wp_mcp_ai_is_base_version() helper can still be used by callers that
 *     need to detect the private/custom build entry point (mcp-ai-wpoos-base.php).
 *     That entry point is excluded from the WordPress.org distribution ZIP via
 *     .distignore so it never fires for WordPress.org users.
 *
 * The Pro addon (addons/pro/) is a genuine extension — it adds brand-new tools
 * that do not exist in the base plugin. It does NOT unlock or gate any tool that
 * is already present in includes/tools/.
 *
 * @var bool False = all base tools always load (default). True = private/custom
 *           build mode set by mcp-ai-wpoos-base.php (excluded from WP.org ZIP).
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
