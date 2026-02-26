<?php
/**
 * Chat Channels Integration Toolkit Initialization
 *
 * Loads the Chat Channels Toolkit system for unified multi-platform
 * messaging across Telegram, WhatsApp, Slack, Discord, Microsoft Teams,
 * Facebook Messenger, and other major chat platforms.
 *
 * This toolkit provides comprehensive chat channel integration following
 * industry best practices for multi-platform messaging.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Chat Channels toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_chat_channels_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Always load the Google Chat webhook handler when the pro addon is active
// so that the bot responds to messages even if the toolkit toggle is off.
$google_chat_webhook_init = WP_MCP_AI_PRO_PATH . 'includes/google-chat-webhook-init.php';
if ( file_exists( $google_chat_webhook_init ) ) {
	require_once $google_chat_webhook_init;
}

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load Chat Channels admin pages.
	if ( is_admin() ) {
		$admin_page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-chat-channels-settings-page.php';
		if ( file_exists( $admin_page_file ) ) {
			require_once $admin_page_file;
		}
	}

	// Register tools will be loaded automatically via the tools directory structure.
	// Tools are located in: addons/pro/includes/src/Tools/ChatChannels/.
	add_action( 'wp_mcp_ai_load_pro_tools', 'wp_mcp_ai_load_chat_channels_tools' );
}

/**
 * Enqueue chat channels toolkit admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_chat_channels_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_chat_channels_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-chat-channels-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-chat-channels-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-chat-channels-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_chat_channels_toolkit_admin_styles' );

/**
 * Load and register Chat Channels Toolkit tools.
 *
 * Registers chat channel tools including WebChat message handling.
 *
 * @since 1.0.0
 */
function wp_mcp_ai_load_chat_channels_tools() {
	if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
		return;
	}

	$registry = WP_MCP_AI_Tool_Registry::get_instance();

	// WebChat message tools (require JetEngine for CCT).
	$webchat_tools = array(
		'WP_MCP_AI_Tool_Save_WebChat_Message' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-save-webchat-message.php',
		'WP_MCP_AI_Tool_Get_WebChat_Messages' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-webchat-messages.php',
	);

	foreach ( $webchat_tools as $class => $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;

			if ( class_exists( $class ) ) {
				$should_register = true;

				// Check if tool declares an availability check.
				if ( method_exists( $class, 'is_available' ) ) {
					$should_register = (bool) call_user_func( array( $class, 'is_available' ) );
				}

				if ( $should_register ) {
					$registry->register_tool( new $class() );
				}
			}
		}
	}
}
