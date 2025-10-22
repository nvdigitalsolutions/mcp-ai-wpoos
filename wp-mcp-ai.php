<?php
/**
 * Plugin Name: WP MCP AI
 * Plugin URI: https://github.com/nvdigitalsolutions/wp-mcp-ai
 * Description: Core AI Assistant framework for WordPress and JetEngine, using OpenAI GPT models.
 * Version: 0.9.0
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPLv2 or later
 * Text Domain: wp-mcp-ai
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WP_MCP_AI_VERSION', '0.9.0' );
define( 'WP_MCP_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_MCP_AI_URL', plugin_dir_url( __FILE__ ) );

require_once WP_MCP_AI_PATH . 'includes/class-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-assistant-cpt.php';
require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-rest-endpoints.php';
require_once WP_MCP_AI_PATH . 'includes/class-tool-registry.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
require_once WP_MCP_AI_PATH . 'includes/tools-init.php';

/**
 * Load the plugin textdomain for localisation support.
 */
function wp_mcp_ai_load_textdomain() {
    load_plugin_textdomain( 'wp-mcp-ai', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'wp_mcp_ai_load_textdomain' );

/**
 * Bootstrap the plugin once all dependencies are loaded.
 */
function wp_mcp_ai_bootstrap() {
    $registry = WP_MCP_AI_Tool_Registry::get_instance();
    $registry->init();

    $client = new WP_MCP_AI_OpenAI_Client();

    $GLOBALS['wp_mcp_ai_admin_settings'] = new WP_MCP_AI_Admin_Settings();
    $GLOBALS['wp_mcp_ai_assistant_cpt']  = new WP_MCP_AI_Assistant_CPT( $registry );
    $GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $client );
    $GLOBALS['wp_mcp_ai_shortcode'] = new WP_MCP_AI_Shortcode();
}

add_action( 'plugins_loaded', 'wp_mcp_ai_bootstrap', 20 );

/**
 * Plugin activation handler.
 */
function wp_mcp_ai_activate() {
    $registry = WP_MCP_AI_Tool_Registry::get_instance();
    $registry->init();

    WP_MCP_AI_Assistant_CPT::register_post_type();
    flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'wp_mcp_ai_activate' );

/**
 * Plugin deactivation handler.
 */
function wp_mcp_ai_deactivate() {
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'wp_mcp_ai_deactivate' );
