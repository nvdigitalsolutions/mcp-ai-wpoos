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
 * Registers chat channel tools for all supported platforms: WebChat, Google
 * Chat, Telegram, WhatsApp, Slack, Discord, Microsoft Teams, Facebook
 * Messenger, Twitter/X, and the unified broadcast tool.
 *
 * @since 1.0.0
 */
function wp_mcp_ai_load_chat_channels_tools() {
	if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
		return;
	}

	$registry  = WP_MCP_AI_Tool_Registry::get_instance();
	$tools_dir = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/';

	// All channel tools, grouped by platform for readability.
	$all_tools = array(

		// WebChat message tools (JetEngine CCT-backed).
		'WP_MCP_AI_Tool_Save_WebChat_Message' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-save-webchat-message.php',
		'WP_MCP_AI_Tool_Get_WebChat_Messages' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-webchat-messages.php',

		// WebChat P2P (WebRTC / signaling) tools.
		'WP_MCP_AI_Pro_Tool_Send_WebChat_Message' => $tools_dir . 'class-wp-mcp-ai-pro-tool-send-webchat-message.php',

		// Google Chat space tools.
		'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces'          => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php',
		'WP_MCP_AI_Pro_Tool_Create_Google_Chat_Space'        => $tools_dir . 'class-wp-mcp-ai-pro-tool-create-google-chat-space.php',
		'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Messages'        => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-google-chat-messages.php',
		'WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message'        => $tools_dir . 'class-wp-mcp-ai-pro-tool-send-google-chat-message.php',
		'WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members'  => $tools_dir . 'class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php',
		'WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member'    => $tools_dir . 'class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php',
		'WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member' => $tools_dir . 'class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php',

		// Telegram tools.
		'WP_MCP_AI_Pro_Tool_Get_Telegram_Updates'       => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-telegram-updates.php',
		'WP_MCP_AI_Pro_Tool_Manage_Telegram_Webhook'    => $tools_dir . 'class-wp-mcp-ai-pro-tool-manage-telegram-webhook.php',
		'WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction' => $tools_dir . 'class-wp-mcp-ai-pro-tool-add-telegram-message-reaction.php',

		// WhatsApp (Meta Cloud API) tools.
		'WP_MCP_AI_Pro_Tool_Get_WhatsApp_Messages'        => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-whatsapp-messages.php',
		'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Interactive'    => $tools_dir . 'class-wp-mcp-ai-pro-tool-send-whatsapp-interactive.php',
		'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Media'          => $tools_dir . 'class-wp-mcp-ai-pro-tool-send-whatsapp-media.php',
		'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Template'       => $tools_dir . 'class-wp-mcp-ai-pro-tool-send-whatsapp-template.php',

		// Slack tools.
		'WP_MCP_AI_Pro_Tool_Get_Slack_Channels'  => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-slack-channels.php',
		'WP_MCP_AI_Pro_Tool_Get_Slack_Messages'  => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-slack-messages.php',
		'WP_MCP_AI_Pro_Tool_Send_Slack_Message'  => $tools_dir . 'class-wp-mcp-ai-pro-tool-send-slack-message.php',
		'WP_MCP_AI_Pro_Tool_Create_Slack_Channel' => $tools_dir . 'class-wp-mcp-ai-pro-tool-create-slack-channel.php',

		// Discord tools.
		'WP_MCP_AI_Pro_Tool_Get_Discord_Channels'             => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-discord-channels.php',
		'WP_MCP_AI_Pro_Tool_Get_Discord_Messages'             => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-discord-messages.php',
		'WP_MCP_AI_Pro_Tool_Send_Discord_Message'             => $tools_dir . 'class-wp-mcp-ai-pro-tool-send-discord-message.php',
		'WP_MCP_AI_Pro_Tool_Create_Discord_Channel'           => $tools_dir . 'class-wp-mcp-ai-pro-tool-create-discord-channel.php',
		'WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction'     => $tools_dir . 'class-wp-mcp-ai-pro-tool-add-discord-message-reaction.php',
		'WP_MCP_AI_Pro_Tool_Get_Discord_Voice_Channel_Members' => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-discord-voice-channel-members.php',

		// Microsoft Teams tools.
		'WP_MCP_AI_Pro_Tool_Get_Teams_Channels' => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-teams-channels.php',
		'WP_MCP_AI_Pro_Tool_Get_Teams_Messages' => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-teams-messages.php',
		'WP_MCP_AI_Pro_Tool_Send_Teams_Message' => $tools_dir . 'class-wp-mcp-ai-pro-tool-send-teams-message.php',

		// Facebook Messenger tools.
		'WP_MCP_AI_Pro_Tool_Get_Messenger_Conversations' => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-messenger-conversations.php',
		'WP_MCP_AI_Pro_Tool_Send_Messenger_Message'      => $tools_dir . 'class-wp-mcp-ai-pro-tool-send-messenger-message.php',
		'WP_MCP_AI_Pro_Tool_Create_Messenger_Broadcast'  => $tools_dir . 'class-wp-mcp-ai-pro-tool-create-messenger-broadcast.php',

		// Twitter/X DM tools.
		'WP_MCP_AI_Pro_Tool_Send_Twitter_DM'        => $tools_dir . 'class-wp-mcp-ai-pro-tool-send-twitter-dm.php',
		'WP_MCP_AI_Pro_Tool_Get_Twitter_DMs'        => $tools_dir . 'class-wp-mcp-ai-pro-tool-get-twitter-dms.php',
		'WP_MCP_AI_Pro_Tool_Manage_Twitter_Webhook' => $tools_dir . 'class-wp-mcp-ai-pro-tool-manage-twitter-webhook.php',

		// Unified cross-channel broadcast tool.
		'WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast' => $tools_dir . 'class-wp-mcp-ai-pro-tool-unified-channel-broadcast.php',
	);

	foreach ( $all_tools as $class => $file ) {
		if ( ! file_exists( $file ) ) {
			continue;
		}

		require_once $file;

		if ( ! class_exists( $class ) ) {
			continue;
		}

		$should_register = true;

		// Honour optional static availability guard.
		if ( method_exists( $class, 'is_available' ) ) {
			$should_register = (bool) call_user_func( array( $class, 'is_available' ) );
		}

		if ( $should_register ) {
			$registry->register_tool( new $class() );
		}
	}
}

