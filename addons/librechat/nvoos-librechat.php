<?php
/**
 * Plugin Name: NV oOS LibreChat
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: LibreChat-powered AI chat surface for NV oOS. Drop-in shortcode + Gutenberg block that surfaces the LibreChat React UI backed by the existing NV oOS REST chat endpoints (mcp-ai/v1/chat-client, /chat-transcripts, /chat-memory). Adds sandboxed code interpreter, web search with reranking, and speech-to-text/text-to-speech. No Node server is introduced.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-librechat
 * Domain Path: /languages
 *
 * @package NV_oOS_LibreChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — must match package.json. */
define( 'NVOOS_LIBRECHAT_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_LIBRECHAT_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_LIBRECHAT_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_LIBRECHAT_URL', plugin_dir_url( __FILE__ ) );

require_once NVOOS_LIBRECHAT_PATH . 'includes/class-nvoos-librechat-plugin.php';
require_once NVOOS_LIBRECHAT_PATH . 'includes/rest/class-nvoos-librechat-rest.php';
require_once NVOOS_LIBRECHAT_PATH . 'includes/shortcode/class-nvoos-librechat-shortcode.php';
require_once NVOOS_LIBRECHAT_PATH . 'includes/block/class-nvoos-librechat-block.php';
require_once NVOOS_LIBRECHAT_PATH . 'includes/admin/class-nvoos-librechat-admin-page.php';
require_once NVOOS_LIBRECHAT_PATH . 'includes/services/class-nvoos-librechat-code-interpreter.php';
require_once NVOOS_LIBRECHAT_PATH . 'includes/services/class-nvoos-librechat-speech.php';

NV_oOS_LibreChat_Code_Interpreter::init();
NV_oOS_LibreChat_Plugin::init();
