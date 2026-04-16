<?php
/**
 * Plugin Name: NV oOS Graphify Addon
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: WordPress Knowledge Graph extension for NV oOS. Extracts entities and relationships from site content, builds an interactive knowledge graph, enables AI assistants to navigate site architecture via graph structure, and provides visualization + SEO/content-strategy insights. Requires NV oOS base plugin.
 * Version:     0.1.0
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
define( 'NVOOS_GRAPHIFY_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_GRAPHIFY_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_GRAPHIFY_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_GRAPHIFY_URL', plugin_dir_url( __FILE__ ) );

// Load core classes.
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-db.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-detector.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-extractor-structural.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-extractor-semantic.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-builder.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-cluster.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-analyzer.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-report.php';

// Load admin classes.
if ( is_admin() ) {
	require_once NVOOS_GRAPHIFY_PATH . 'includes/admin/class-nvoos-graphify-settings.php';
}

// Load REST controller.
require_once NVOOS_GRAPHIFY_PATH . 'includes/rest/class-nvoos-graphify-rest.php';

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

/**
 * Set the "just activated" transient and install database tables on activation.
 */
register_activation_hook(
	NVOOS_GRAPHIFY_FILE,
	function () {
		set_transient( 'nvoos_graphify_activated', true, 30 );
		NV_oOS_Graphify_DB::install();
	}
);

/**
 * Clean up on uninstall is handled via uninstall.php.
 *
 * @see addons/graphify/uninstall.php
 */
