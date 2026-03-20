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
	define( 'WP_MCP_AI_VERSION', '1.1.4' );
}

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
	define( 'WP_MCP_AI_PATH', plugin_dir_path( WP_MCP_AI_FILE ) );
}

if ( ! defined( 'WP_MCP_AI_URL' ) ) {
	define( 'WP_MCP_AI_URL', plugin_dir_url( WP_MCP_AI_FILE ) );
}

/**
 * Base version mode.
 *
 * Defaults to true (165 core tools).
 * Set `define( 'WP_MCP_AI_BASE_VERSION', false )` in wp-config.php for full mode.
 */
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
	define( 'WP_MCP_AI_BASE_VERSION', true );
}

/**
 * Pro Dashboard enabled.
 *
 * Defaults to true. Set to false to disable Pro Dashboard features.
 */
if ( ! defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) ) {
	define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );
}
