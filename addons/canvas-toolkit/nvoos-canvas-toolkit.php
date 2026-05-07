<?php
/**
 * Plugin Name: NV oOS Canvas Toolkit
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: NV oOS Canvas Toolkit — React-based SPA surface for the NV oOS plugin. Generated from the Toolkit SPA Blueprint.
 * Version:     0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-canvas-toolkit
 * Domain Path: /languages
 *
 * @package NV_oOS_Canvas_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — must match package.json. */
define( 'NVOOS_CANVAS_TOOLKIT_VERSION', '0.2.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_CANVAS_TOOLKIT_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_CANVAS_TOOLKIT_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_CANVAS_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );

require_once NVOOS_CANVAS_TOOLKIT_PATH . 'includes/class-nvoos-canvas-toolkit-plugin.php';
require_once NVOOS_CANVAS_TOOLKIT_PATH . 'includes/rest/class-nvoos-canvas-toolkit-rest.php';
require_once NVOOS_CANVAS_TOOLKIT_PATH . 'includes/shortcode/class-nvoos-canvas-toolkit-shortcode.php';
require_once NVOOS_CANVAS_TOOLKIT_PATH . 'includes/block/class-nvoos-canvas-toolkit-block.php';

NV_oOS_Canvas_Toolkit_Plugin::init();
