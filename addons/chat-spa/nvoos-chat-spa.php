<?php
/**
 * Plugin Name: NV oOS Chat SPA
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: NV oOS Chat — modern React chat surface for the NV oOS plugin. Drop-in shortcode + Gutenberg block that talks to the existing NV oOS REST chat endpoints (mcp-ai/v1/chat-client, /chat-transcripts, /chat-memory) via the Vercel AI SDK UI layer (@ai-sdk/react). No Node server is introduced.
 * Version:     0.6.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-chat-spa
 * Domain Path: /languages
 *
 * @package NV_oOS_Chat_Spa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — must match package.json. */
define( 'NVOOS_CHAT_SPA_VERSION', '0.6.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_CHAT_SPA_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_CHAT_SPA_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_CHAT_SPA_URL', plugin_dir_url( __FILE__ ) );

require_once NVOOS_CHAT_SPA_PATH . 'includes/class-nvoos-chat-spa-plugin.php';
require_once NVOOS_CHAT_SPA_PATH . 'includes/rest/class-nvoos-chat-spa-rest.php';
require_once NVOOS_CHAT_SPA_PATH . 'includes/shortcode/class-nvoos-chat-spa-shortcode.php';
require_once NVOOS_CHAT_SPA_PATH . 'includes/block/class-nvoos-chat-spa-block.php';
require_once NVOOS_CHAT_SPA_PATH . 'includes/admin/class-nvoos-chat-spa-admin-page.php';

NV_oOS_Chat_Spa_Plugin::init();
