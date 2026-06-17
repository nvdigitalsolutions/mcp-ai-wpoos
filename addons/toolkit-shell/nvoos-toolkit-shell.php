<?php
/**
 * Plugin Name: NV oOS Toolkit Shell
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Manifest-driven React SPA shell for NV oOS Pro toolkits. One bundle, many surfaces — drives CRM, calendar-booking, financial-planner, regulatory-registration, law-firm, cre-debt, multilingual, ecommerce, social-media, and more via per-toolkit JSON manifests under addons/pro/config/spa-manifests/.
 * Version:     0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-toolkit-shell
 * Domain Path: /languages
 *
 * @package NV_oOS_Toolkit_Shell
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — must match package.json. */
define( 'NVOOS_TOOLKIT_SHELL_VERSION', '0.2.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_TOOLKIT_SHELL_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_TOOLKIT_SHELL_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_TOOLKIT_SHELL_URL', plugin_dir_url( __FILE__ ) );

require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/class-nvoos-toolkit-shell-plugin.php';
require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/class-nvoos-toolkit-shell-manifest-registry.php';
require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/rest/class-nvoos-toolkit-shell-rest.php';
require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/shortcode/class-nvoos-toolkit-shell-shortcode.php';
require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/block/class-nvoos-toolkit-shell-block.php';

NV_oOS_Toolkit_Shell_Plugin::init();
