<?php
/**
 * Plugin Name: NV oOS Media Studio
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: NV oOS Media Studio — React-based SPA surface for the NV oOS plugin. Generated from the Toolkit SPA Blueprint.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-media-studio
 * Domain Path: /languages
 *
 * @package NV_oOS_Media_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — must match package.json. */
define( 'NVOOS_MEDIA_STUDIO_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_MEDIA_STUDIO_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_MEDIA_STUDIO_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_MEDIA_STUDIO_URL', plugin_dir_url( __FILE__ ) );

require_once NVOOS_MEDIA_STUDIO_PATH . 'includes/class-nvoos-media-studio-plugin.php';
require_once NVOOS_MEDIA_STUDIO_PATH . 'includes/rest/class-nvoos-media-studio-rest.php';
require_once NVOOS_MEDIA_STUDIO_PATH . 'includes/shortcode/class-nvoos-media-studio-shortcode.php';
require_once NVOOS_MEDIA_STUDIO_PATH . 'includes/block/class-nvoos-media-studio-block.php';

NV_oOS_Media_Studio_Plugin::init();
