<?php
/**
 * Plugin Name: NV oOS Comic Reader
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: A modern comic book reader & creator addon for the NV oOS platform. Supports CBR, CBZ, CB7, and CBT formats with a React-based reading interface featuring single/double-page viewing, zoom, library management, and AI-powered comic creation tools.
 * Version:     0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-comic-reader
 * Domain Path: /languages
 *
 * @package NV_oOS_Comic_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — must match package.json. */
define( 'NVOOS_COMIC_READER_VERSION', '0.2.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_COMIC_READER_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_COMIC_READER_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_COMIC_READER_URL', plugin_dir_url( __FILE__ ) );

require_once NVOOS_COMIC_READER_PATH . 'includes/class-nvoos-comic-reader-plugin.php';
require_once NVOOS_COMIC_READER_PATH . 'includes/class-nvoos-comic-reader-mime.php';
require_once NVOOS_COMIC_READER_PATH . 'includes/rest/class-nvoos-comic-reader-rest.php';
require_once NVOOS_COMIC_READER_PATH . 'includes/shortcode/class-nvoos-comic-reader-shortcode.php';
require_once NVOOS_COMIC_READER_PATH . 'includes/block/class-nvoos-comic-reader-block.php';

/**
 * Boot the plugin on plugins_loaded.
 *
 * @return void
 */
function nvoos_comic_reader_boot() {
	NV_oOS_Comic_Reader_Plugin::init();
}
add_action( 'plugins_loaded', 'nvoos_comic_reader_boot', 20 );
