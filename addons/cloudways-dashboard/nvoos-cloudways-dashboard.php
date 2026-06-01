<?php
/**
 * Plugin Name: NV oOS Cloudways Dashboard
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: SaaS operator dashboard for managing Cloudways servers, WordPress sites, and NV oOS toolkits — powered by a Velzon-themed React SPA.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-cloudways-dashboard
 * Domain Path: /languages
 * Requires Plugins: mcp-ai-wpoos
 *
 * @package NV_oOS_CloudwaysDashboard
 *
 * Copyright (c) 2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — must match package.json. */
define( 'NVOOS_CLOUDWAYS_DASHBOARD_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_CLOUDWAYS_DASHBOARD_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_CLOUDWAYS_DASHBOARD_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_CLOUDWAYS_DASHBOARD_URL', plugin_dir_url( __FILE__ ) );

require_once NVOOS_CLOUDWAYS_DASHBOARD_PATH . 'includes/class-nvoos-cloudways-dashboard-plugin.php';
require_once NVOOS_CLOUDWAYS_DASHBOARD_PATH . 'includes/class-nvoos-cloudways-dashboard-provisioning-job.php';
require_once NVOOS_CLOUDWAYS_DASHBOARD_PATH . 'includes/class-nvoos-cloudways-dashboard-toolkit-manager.php';
require_once NVOOS_CLOUDWAYS_DASHBOARD_PATH . 'includes/rest/class-nvoos-cloudways-dashboard-rest.php';
require_once NVOOS_CLOUDWAYS_DASHBOARD_PATH . 'includes/shortcode/class-nvoos-cloudways-dashboard-shortcode.php';
require_once NVOOS_CLOUDWAYS_DASHBOARD_PATH . 'includes/block/class-nvoos-cloudways-dashboard-block.php';

NV_oOS_CloudwaysDashboard_Plugin::init();
