<?php
/**
 * Coordinates registration of the chat shortcodes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Instantiate the shortcode handlers and coordinate shared assets.
 */
class WP_MCP_AI_Shortcodes {
    /**
     * Primary chat shortcode handler.
     *
     * @var WP_MCP_AI_Shortcode
     */
    protected $chat_shortcode;

    /**
     * Deep Chat shortcode handler.
     *
     * @var WP_MCP_AI_Deep_Chat_Shortcode
     */
    protected $deep_chat_shortcode;

    /**
     * Boot the shortcode handlers.
     */
    public function __construct() {
        $this->chat_shortcode      = new WP_MCP_AI_Shortcode();
        $this->deep_chat_shortcode = new WP_MCP_AI_Deep_Chat_Shortcode();

        // Maintain backwards compatibility with the historic globals.
        $GLOBALS['wp_mcp_ai_shortcode']          = $this->chat_shortcode;
        $GLOBALS['wp_mcp_ai_deep_chat_shortcode'] = $this->deep_chat_shortcode;

        add_action( 'init', array( $this, 'register_shared_assets' ), 9 );
    }

    /**
     * Ensure shared Deep Chat assets are registered once per request.
     */
    public function register_shared_assets() {
        if ( function_exists( 'wp_mcp_ai_register_deep_chat_assets' ) ) {
            wp_mcp_ai_register_deep_chat_assets();
        }
    }

    /**
     * Retrieve the classic chat shortcode handler.
     *
     * @return WP_MCP_AI_Shortcode
     */
    public function get_chat_shortcode() {
        return $this->chat_shortcode;
    }

    /**
     * Retrieve the Deep Chat shortcode handler.
     *
     * @return WP_MCP_AI_Deep_Chat_Shortcode
     */
    public function get_deep_chat_shortcode() {
        return $this->deep_chat_shortcode;
    }
}
