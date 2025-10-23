<?php
/**
 * Plugin Name: WP MCP AI
 * Plugin URI: https://github.com/nvdigitalsolutions/wp-mcp-ai
 * Description: Core AI Assistant framework for WordPress and JetEngine, using OpenAI GPT models.
 * Version: 1.0.0
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPLv2 or later
 * Text Domain: wp-mcp-ai
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WP_MCP_AI_VERSION', '1.0.0' );
define( 'WP_MCP_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_MCP_AI_URL', plugin_dir_url( __FILE__ ) );

require_once WP_MCP_AI_PATH . 'includes/class-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-credentials.php';
require_once WP_MCP_AI_PATH . 'includes/class-assistant-cpt.php';
require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-endpoint-report.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-tool-handlers.php';
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

/**
 * Plugin uninstall handler.
 */
function wp_mcp_ai_uninstall() {
    $settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    $settings = wp_parse_args( $settings, WP_MCP_AI_Admin_Settings::get_default_settings() );

    if ( empty( $settings['delete_on_uninstall'] ) ) {
        return;
    }

    /**
     * Fires before WP MCP AI performs its uninstall cleanup routines.
     */
    do_action( 'wp_mcp_ai_before_uninstall_cleanup' );

    $assistant_ids = get_posts(
        array(
            'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        )
    );

    if ( ! empty( $assistant_ids ) ) {
        foreach ( $assistant_ids as $assistant_id ) {
            wp_delete_post( $assistant_id, true );
        }
    }

    $settings_deleted = delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
    delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );

    /**
     * Fires after WP MCP AI completes its uninstall cleanup routines.
     *
     * @param array $summary Summary of cleanup actions performed.
     */
    do_action(
        'wp_mcp_ai_after_uninstall_cleanup',
        array(
            'assistants_deleted' => is_array( $assistant_ids ) ? count( $assistant_ids ) : 0,
            'settings_deleted'   => (bool) $settings_deleted,
        )
    );
}

register_uninstall_hook( __FILE__, 'wp_mcp_ai_uninstall' );
