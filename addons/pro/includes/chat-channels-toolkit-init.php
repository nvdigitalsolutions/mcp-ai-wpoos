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
 * Registers chat channel tools including WebChat and Google Chat tools.
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

	// Google Chat space tools.
	$google_chat_tools_dir = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/';
	$google_chat_tools     = array(
		'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces'          => $google_chat_tools_dir . 'class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php',
		'WP_MCP_AI_Pro_Tool_Create_Google_Chat_Space'        => $google_chat_tools_dir . 'class-wp-mcp-ai-pro-tool-create-google-chat-space.php',
		'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Messages'        => $google_chat_tools_dir . 'class-wp-mcp-ai-pro-tool-get-google-chat-messages.php',
		'WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message'        => $google_chat_tools_dir . 'class-wp-mcp-ai-pro-tool-send-google-chat-message.php',
		'WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members'  => $google_chat_tools_dir . 'class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php',
		'WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member'    => $google_chat_tools_dir . 'class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php',
		'WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member' => $google_chat_tools_dir . 'class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php',
	);

	foreach ( $google_chat_tools as $class => $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;

			if ( class_exists( $class ) ) {
				$should_register = true;

				if ( method_exists( $class, 'is_available' ) ) {
					$should_register = (bool) call_user_func( array( $class, 'is_available' ) );
				}

				if ( $should_register ) {
					$registry->register_tool( new $class() );
				}
			}
		}
	}

	// Twitter/X DM tools.
	$twitter_tools_dir = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/';
	$twitter_tools     = array(
		'WP_MCP_AI_Pro_Tool_Send_Twitter_DM'        => $twitter_tools_dir . 'class-wp-mcp-ai-pro-tool-send-twitter-dm.php',
		'WP_MCP_AI_Pro_Tool_Get_Twitter_DMs'        => $twitter_tools_dir . 'class-wp-mcp-ai-pro-tool-get-twitter-dms.php',
		'WP_MCP_AI_Pro_Tool_Manage_Twitter_Webhook' => $twitter_tools_dir . 'class-wp-mcp-ai-pro-tool-manage-twitter-webhook.php',
	);

	foreach ( $twitter_tools as $class => $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;

			if ( class_exists( $class ) ) {
				$should_register = true;

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
