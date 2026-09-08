<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-chat-channels-mcp-server.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Channels MCP server.
 *
 * Exposes cross-platform messaging tools for Slack, Discord, Teams, WhatsApp,
 * Telegram, Google Chat, Messenger, Twitter DMs, Outlook, and iCloud/OneDrive.
 * Tools-only server — workflow plumbing without a CPT-shaped ingestion surface.
 */
class WP_MCP_AI_Chat_Channels_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'chat-channels';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Chat Channels', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Cross-platform messaging across Slack, Discord, Microsoft Teams, WhatsApp, Telegram, Google Chat, Messenger, Twitter DMs, Outlook, and Apple Messages. Tools-only server.',
			'nvoos-content-graph-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array();
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the Chat Channels MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_chat_channels_candidate_tools',
			array(
				// Slack.
				'get_slack_channels',
				'get_slack_messages',
				'send_slack_message',
				'create_slack_channel',
				// Discord.
				'get_discord_channels',
				'get_discord_messages',
				'send_discord_message',
				'create_discord_channel',
				'add_discord_message_reaction',
				'get_discord_voice_channel_members',
				// Microsoft Teams.
				'get_teams_channels',
				'get_teams_messages',
				'send_teams_message',
				// WhatsApp.
				'get_whatsapp_messages',
				'send_whatsapp_template',
				'send_whatsapp_media',
				'send_whatsapp_interactive',
				// Telegram.
				'get_telegram_updates',
				'add_telegram_message_reaction',
				'manage_telegram_commands',
				'manage_telegram_webhook',
				// Google Chat.
				'get_google_chat_spaces',
				'get_google_chat_messages',
				'send_google_chat_message',
				'create_google_chat_space',
				'add_google_chat_space_member',
				'remove_google_chat_space_member',
				'list_google_chat_space_members',
				// Messenger.
				'get_messenger_conversations',
				'send_messenger_message',
				'create_messenger_broadcast',
				// Twitter DMs.
				'get_twitter_dms',
				'send_twitter_dm',
				'manage_twitter_webhook',
				// Outlook.
				'get_outlook_messages',
				'send_outlook_mail',
				// Apple Messages / iCloud.
				'get_apple_messages',
				'send_apple_message',
				'send_apple_message_group',
				'send_apple_message_interactive',
				// OneDrive.
				'get_onedrive_file',
				'list_onedrive_files',
				'upload_onedrive_file',
				// iCloud Drive.
				'get_icloud_drive_file',
				'list_icloud_drive_files',
				'upload_icloud_drive_file',
				// Unified.
				'unified_channel_broadcast',
			)
		);
	}
}
