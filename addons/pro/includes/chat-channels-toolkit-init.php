<?php
/**
 * Chat Channels Integration Toolkit Initialization
 *
 * Loads the Chat Channels Toolkit system for unified multi-platform
 * messaging across Telegram, WhatsApp, Slack, Discord, Microsoft Teams,
 * Facebook Messenger, Apple Messages for Business (iMessage), and other
 * major chat platforms.
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
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' );

// Only load if enabled and not in base version (Pro plugin active overrides base version restriction).
if ( $is_enabled && ! $is_base ) {

	// --- CCTs: Channel Messages and Channel Contacts ---
	$_cc_messages_cct = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-channel-messages-cct.php';
	$_cc_contacts_cct = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-channel-contacts-cct.php';

	if ( file_exists( $_cc_messages_cct ) ) {
		require_once $_cc_messages_cct;
		WP_MCP_AI_Channel_Messages_CCT::bootstrap();
	}
	if ( file_exists( $_cc_contacts_cct ) ) {
		require_once $_cc_contacts_cct;
		WP_MCP_AI_Channel_Contacts_CCT::bootstrap();
	}
	unset( $_cc_messages_cct, $_cc_contacts_cct );

	// --- REST API: Chat Channels inbox controller ---
	$_cc_rest = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php';
	if ( file_exists( $_cc_rest ) && ! class_exists( 'WP_MCP_AI_Chat_Channels_REST_Controller' ) ) {
		require_once $_cc_rest;
		new WP_MCP_AI_Chat_Channels_REST_Controller();
	}
	unset( $_cc_rest );

	// --- REST API: Apple Messages for Business webhook controller ---
	$_apple_rest = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-apple-messages-webhook-controller.php';
	if ( file_exists( $_apple_rest ) && ! class_exists( 'WP_MCP_AI_Apple_Messages_Webhook_Controller' ) ) {
		require_once $_apple_rest;
		new WP_MCP_AI_Apple_Messages_Webhook_Controller();
	}
	unset( $_apple_rest );

	// --- Admin: top-level Chat Channels menu (Dashboard, Inbox, Contacts, Automation) ---
	if ( is_admin() ) {
		$_cc_menu = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-chat-channels-menu.php';
		if ( file_exists( $_cc_menu ) && ! class_exists( 'WP_MCP_AI_Chat_Channels_Menu' ) ) {
			require_once $_cc_menu;
			new WP_MCP_AI_Chat_Channels_Menu();
		}
		unset( $_cc_menu );

		// Existing per-toolkit settings page (preserved for backwards compatibility).
		$_cc_settings_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-chat-channels-settings-page.php';
		if ( file_exists( $_cc_settings_page ) ) {
			require_once $_cc_settings_page;
		}
		unset( $_cc_settings_page );
	}

	// Register tools via the standard pro tools hook.
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
 * Registers chat channel tools including WebChat, Google Chat, Twitter/X,
 * and Apple Messages for Business (iMessage) tools.
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

	// Apple Messages for Business (iMessage) tools.
	$apple_tools_dir = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/';
	$apple_tools     = array(
		'WP_MCP_AI_Pro_Tool_Send_Apple_Message'             => $apple_tools_dir . 'class-wp-mcp-ai-pro-tool-send-apple-message.php',
		'WP_MCP_AI_Pro_Tool_Send_Apple_Message_Interactive' => $apple_tools_dir . 'class-wp-mcp-ai-pro-tool-send-apple-message-interactive.php',
		'WP_MCP_AI_Pro_Tool_Get_Apple_Messages'             => $apple_tools_dir . 'class-wp-mcp-ai-pro-tool-get-apple-messages.php',
		'WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group'       => $apple_tools_dir . 'class-wp-mcp-ai-pro-tool-send-apple-message-group.php',
	);

	foreach ( $apple_tools as $class => $file ) {
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

