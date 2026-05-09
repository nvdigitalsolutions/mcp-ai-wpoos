<?php
/**
 * Plugin Name: NV oOS Document Editor
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: NV oOS Document Editor — React-based SPA surface for the NV oOS plugin. Generated from the Toolkit SPA Blueprint.
 * Version:     0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-document-editor
 * Domain Path: /languages
 *
 * @package NV_oOS_Document_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — must match package.json. */
define( 'NVOOS_DOCUMENT_EDITOR_VERSION', '0.2.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_DOCUMENT_EDITOR_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_DOCUMENT_EDITOR_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_DOCUMENT_EDITOR_URL', plugin_dir_url( __FILE__ ) );

require_once NVOOS_DOCUMENT_EDITOR_PATH . 'includes/class-nvoos-document-editor-plugin.php';
require_once NVOOS_DOCUMENT_EDITOR_PATH . 'includes/rest/class-nvoos-document-editor-rest.php';
require_once NVOOS_DOCUMENT_EDITOR_PATH . 'includes/shortcode/class-nvoos-document-editor-shortcode.php';
require_once NVOOS_DOCUMENT_EDITOR_PATH . 'includes/block/class-nvoos-document-editor-block.php';

NV_oOS_Document_Editor_Plugin::init();
