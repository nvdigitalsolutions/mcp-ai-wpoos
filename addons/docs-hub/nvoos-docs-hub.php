<?php
/**
 * Plugin Name: NV oOS Docs Hub
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: React-based documentation browser for NV oOS. Discovers, indexes, and renders Markdown docs from the base plugin and every addon in a GitBook-style SPA. Shortcode [nvoos_docs] embeds it on any page.
 * Version:     0.3.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-docs-hub
 * Domain Path: /languages
 *
 * @package NV_oOS_Docs_Hub
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_DOCS_HUB_VERSION', '0.3.2' );

/** Absolute path to this plugin file. */
define( 'NVOOS_DOCS_HUB_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_DOCS_HUB_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_DOCS_HUB_URL', plugin_dir_url( __FILE__ ) );

// Load core classes.
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-plugin.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-remote-repo.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-scanner.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-indexer.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cache.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-state.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-job.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-pipeline.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/rest/class-nvoos-docs-hub-rest.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/shortcode/class-nvoos-docs-hub-shortcode.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/block/class-nvoos-docs-hub-block.php';

// Load admin classes.
if ( is_admin() ) {
	require_once NVOOS_DOCS_HUB_PATH . 'includes/admin/class-nvoos-docs-hub-settings.php';
}

// Load WP-CLI command.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cli.php';
}

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 1.0.0
 *
 * @return bool True when the base plugin is available.
 */
function nvoos_docs_hub_is_base_active() {
	return defined( 'WP_MCP_AI_VERSION' );
}

/**
 * Check whether the docs hub addon is fully ready.
 *
 * @since 1.0.0
 *
 * @return bool True when the addon is operational.
 */
function nvoos_docs_hub_is_ready() {
	return NV_oOS_Docs_Hub_Plugin::is_enabled();
}

// Boot the plugin.
NV_oOS_Docs_Hub_Plugin::init();
