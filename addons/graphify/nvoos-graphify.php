<?php
/**
 * Plugin Name: NV oOS Graphify
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: WordPress Knowledge Graph addon for NV oOS. Extracts entities and relationships from your content, builds a navigable knowledge graph, and exposes it to AI assistants via oOS tools and a REST API. Requires NV oOS base plugin.
 * Version:     0.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-graphify
 * Domain Path: /languages
 *
 * @package NV_oOS_Graphify
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_GRAPHIFY_VERSION', '0.5.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_GRAPHIFY_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_GRAPHIFY_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_GRAPHIFY_URL', plugin_dir_url( __FILE__ ) );

/** DB schema version — bump when tables change. */
define( 'NVOOS_GRAPHIFY_DB_VERSION', '1' );

// Load core classes.
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-db.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-detector.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-structural-extractor.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-semantic-extractor.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-builder.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-analyzer.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-report.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-exporter.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify.php';

// Load admin classes.
if ( is_admin() ) {
	require_once NVOOS_GRAPHIFY_PATH . 'includes/admin/class-nvoos-graphify-settings.php';
}

// Load REST controller.
require_once NVOOS_GRAPHIFY_PATH . 'includes/rest/class-nvoos-graphify-rest.php';

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 0.5.0
 *
 * @return bool True when the base plugin is available.
 */
function nvoos_graphify_is_base_active() {
	return defined( 'WP_MCP_AI_VERSION' );
}

/**
 * Check whether Graphify is fully ready.
 *
 * @since 0.5.0
 *
 * @return bool True when the addon is operational.
 */
function nvoos_graphify_is_ready() {
	return nvoos_graphify_is_base_active() && NV_oOS_Graphify::is_enabled();
}

// Register activation / deactivation / uninstall hooks.
register_activation_hook( __FILE__, array( 'NV_oOS_Graphify', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NV_oOS_Graphify', 'deactivate' ) );

// Boot the plugin.
NV_oOS_Graphify::init();
