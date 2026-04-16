<?php
/**
 * Plugin Name: NV oOS Graphify — WordPress Knowledge Graph
 * Plugin URI:  https://developer.suspended.suspended/nvoos-graphify
 * Description: AI-powered knowledge graph builder for WordPress. Extracts entities and relationships from your content, builds an interactive knowledge graph, and enables AI assistants to navigate site architecture.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI:  https://developer.suspended.suspended
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-graphify
 * Domain Path: /languages
 *
 * @package NV_oOS_Graphify
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://developer.suspended.suspended)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_GRAPHIFY_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_GRAPHIFY_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_GRAPHIFY_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_GRAPHIFY_URL', plugin_dir_url( __FILE__ ) );

// Load core classes.
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-database.php';

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 0.1.0
 *
 * @return bool True when the base plugin is available.
 */
function nvoos_graphify_is_base_active() {
	return defined( 'WP_MCP_AI_VERSION' );
}

/**
 * Check whether the graphify addon is fully ready.
 *
 * @since 0.1.0
 *
 * @return bool True when the addon is operational.
 */
function nvoos_graphify_is_ready() {
	return nvoos_graphify_is_base_active() && NV_oOS_Graphify::is_enabled();
}

// Boot the plugin.
NV_oOS_Graphify::init();

// Create tables on activation.
register_activation_hook(
	NVOOS_GRAPHIFY_FILE,
	function () {
		NV_oOS_Graphify_Database::create_tables();
		set_transient( 'nvoos_graphify_activated', true, 30 );
	}
);
