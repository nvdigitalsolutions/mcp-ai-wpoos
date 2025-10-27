<?php
/**
 * Helpers for loading the Deep Chat frontend library.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'wp_mcp_ai_register_deep_chat_assets' ) ) {
    /**
     * Register the Deep Chat frontend assets.
     *
     * @since 1.0.0
     *
     * @return string Script handle registered for the Deep Chat bundle.
     */
    function wp_mcp_ai_register_deep_chat_assets() {
        $handle = 'wp-mcp-ai-deep-chat';

        if ( wp_script_is( $handle, 'registered' ) ) {
            return $handle;
        }

        $local_path = WP_MCP_AI_PATH . 'assets/js/deep-chat/deepChat.bundle.js';
        $local_url  = WP_MCP_AI_URL . 'assets/js/deep-chat/deepChat.bundle.js';

        if ( file_exists( $local_path ) ) {
            $script_url = $local_url;
            $version    = filemtime( $local_path );
        } else {
            $script_url = 'https://cdn.jsdelivr.net/npm/deep-chat@2.3.0/dist/deepChat.bundle.js';
            $version    = '2.3.0';
        }

        $script_url = apply_filters( 'wp_mcp_ai_deep_chat_script_url', $script_url );
        $version    = apply_filters( 'wp_mcp_ai_deep_chat_script_version', $version );

        wp_register_script(
            $handle,
            esc_url_raw( $script_url ),
            array(),
            $version,
            true
        );

        $integration_handle = 'wp-mcp-ai-deep-chat-app';
        if ( ! wp_script_is( $integration_handle, 'registered' ) ) {
            $integration_path = WP_MCP_AI_PATH . 'assets/js/deep-chat/index.js';
            $integration_url  = WP_MCP_AI_URL . 'assets/js/deep-chat/index.js';

            if ( file_exists( $integration_path ) ) {
                wp_register_script(
                    $integration_handle,
                    $integration_url,
                    array( $handle ),
                    filemtime( $integration_path ),
                    true
                );
            }
        }

        // Load the bundle asynchronously to avoid blocking rendering.
        wp_script_add_data( $handle, 'strategy', 'defer' );

        $style_path = WP_MCP_AI_PATH . 'assets/css/deep-chat.css';
        $style_url  = WP_MCP_AI_URL . 'assets/css/deep-chat.css';

        if ( file_exists( $style_path ) && ! wp_style_is( 'wp-mcp-ai-deep-chat', 'registered' ) ) {
            wp_register_style( 'wp-mcp-ai-deep-chat', $style_url, array(), filemtime( $style_path ) );
        }

        return $handle;
    }
}

if ( ! function_exists( 'wp_mcp_ai_enqueue_deep_chat_assets' ) ) {
    /**
     * Ensure the Deep Chat assets are enqueued.
     *
     * @since 1.0.0
     *
     * @return string Script handle enqueued for the Deep Chat bundle.
     */
    function wp_mcp_ai_enqueue_deep_chat_assets() {
        $handle = wp_mcp_ai_register_deep_chat_assets();

        if ( $handle && ! wp_script_is( $handle, 'enqueued' ) ) {
            wp_enqueue_script( $handle );
        }

        if ( wp_script_is( 'wp-mcp-ai-deep-chat-app', 'registered' ) && ! wp_script_is( 'wp-mcp-ai-deep-chat-app', 'enqueued' ) ) {
            wp_enqueue_script( 'wp-mcp-ai-deep-chat-app' );
        }

        if ( wp_style_is( 'wp-mcp-ai-deep-chat', 'registered' ) && ! wp_style_is( 'wp-mcp-ai-deep-chat', 'enqueued' ) ) {
            wp_enqueue_style( 'wp-mcp-ai-deep-chat' );
        }

        return $handle;
    }
}
