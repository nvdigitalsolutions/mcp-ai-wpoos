<?php
/**
 * Content Assistant Initialization.
 *
 * Loads and initializes the AI Content Assistant metabox feature.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize the Content Assistant feature.
 *
 * Checks if the feature is enabled in settings and loads the necessary files.
 *
 * @since 1.1.0
 */
function wp_mcp_ai_init_content_assistant() {
	// Check if feature is enabled in settings.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	$enabled  = isset( $settings['enable_content_assistant_metabox'] ) ? $settings['enable_content_assistant_metabox'] : true;

	// Allow filtering of the enabled state.
	/**
	 * Filters whether the Content Assistant metabox feature is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param bool $enabled Whether the feature is enabled.
	 */
	$enabled = apply_filters( 'wp_mcp_ai_content_assistant_enabled', $enabled );

	if ( ! $enabled ) {
		return;
	}

	// Load the metabox class.
	require_once WP_MCP_AI_PATH . 'includes/metaboxes/class-wp-mcp-ai-content-assistant-metabox.php';

	// Initialize the metabox.
	new WP_MCP_AI_Content_Assistant_Metabox();
}

// Initialize on admin_init to ensure WordPress is fully loaded.
add_action( 'admin_init', 'wp_mcp_ai_init_content_assistant' );
