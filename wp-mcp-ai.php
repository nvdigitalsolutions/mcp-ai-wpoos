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
require_once WP_MCP_AI_PATH . 'includes/class-assistant-cpt.php';
require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-rest-endpoints.php';
require_once WP_MCP_AI_PATH . 'includes/class-tool-registry.php';
require_once WP_MCP_AI_PATH . 'includes/tools-init.php';

add_action( 'plugins_loaded', function() {
    new WP_MCP_AI_Admin_Settings();
    new WP_MCP_AI_Assistant_CPT();
    new WP_MCP_AI_REST();
});
