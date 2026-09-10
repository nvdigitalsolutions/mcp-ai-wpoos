<?php
/**
 * Characterization tests for the Wave F5 chat-channels tool batch — the
 * fifty loader tools plus the tree-only import-blueprint tool, the
 * google-service-account service, and the D8-compat cron-manager copy
 * ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/chat-channels/` are asserted in full, including the
 *   standalone tool filter and ecosystem registration.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Chat Channels tools tests.
 */
class Test_Chat_Channels_Tools extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Pro_Tool_Send_Telegram_Message'     => 'tools/chat-channels/class-wp-mcp-ai-pro-tool-send-telegram-message.php',
			'WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast' => 'tools/chat-channels/class-wp-mcp-ai-pro-tool-unified-channel-broadcast.php',
			'WP_MCP_AI_Pro_Google_Service_Account'         => 'tools/chat-channels/class-wp-mcp-ai-pro-google-service-account.php',
			'WP_MCP_AI_Tool_Import_Chat_Channels_Blueprint' => 'tools/chat-channels/examples/class-wp-mcp-ai-tool-import-chat-channels-blueprint.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}

		// The cron-manager D8 copy serves standalone; the monorepo root
		// classmap may serve the base copy in the test matrices.
		$cron_path = str_replace( '\\', '/', (string) ( new ReflectionClass( 'WP_MCP_AI_Cron_Manager' ) )->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'includes/class-wp-mcp-ai-cron-manager.php', $cron_path );
		} else {
			$this->assertStringContainsString( 'class-wp-mcp-ai-cron-manager.php', $cron_path );
		}
	}

	/**
	 * The fifty-one tool surfaces must be byte-identical — uniform
	 * `edit_posts` capability and the monolith slug map plus the tree-only
	 * import tool.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction' => 'add_discord_message_reaction',
			'WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member' => 'add_google_chat_space_member',
			'WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction' => 'add_telegram_message_reaction',
			'WP_MCP_AI_Pro_Tool_Create_Discord_Channel'    => 'create_discord_channel',
			'WP_MCP_AI_Pro_Tool_Create_Google_Chat_Space'  => 'create_google_chat_space',
			'WP_MCP_AI_Pro_Tool_Create_Messenger_Broadcast' => 'create_messenger_broadcast',
			'WP_MCP_AI_Pro_Tool_Create_Slack_Channel'      => 'create_slack_channel',
			'WP_MCP_AI_Pro_Tool_Get_Apple_Messages'        => 'get_apple_messages',
			'WP_MCP_AI_Pro_Tool_Get_Discord_Channels'      => 'get_discord_channels',
			'WP_MCP_AI_Pro_Tool_Get_Discord_Messages'      => 'get_discord_messages',
			'WP_MCP_AI_Pro_Tool_Get_Discord_Voice_Channel_Members' => 'get_discord_voice_channel_members',
			'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Messages'  => 'get_google_chat_messages',
			'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces'    => 'get_google_chat_spaces',
			'WP_MCP_AI_Pro_Tool_Get_Icloud_Drive_File'     => 'get_icloud_drive_file',
			'WP_MCP_AI_Pro_Tool_Get_Messenger_Conversations' => 'get_messenger_conversations',
			'WP_MCP_AI_Pro_Tool_Get_OneDrive_File'         => 'get_onedrive_file',
			'WP_MCP_AI_Pro_Tool_Get_Outlook_Messages'      => 'get_outlook_messages',
			'WP_MCP_AI_Pro_Tool_Get_Slack_Channels'        => 'get_slack_channels',
			'WP_MCP_AI_Pro_Tool_Get_Slack_Messages'        => 'get_slack_messages',
			'WP_MCP_AI_Pro_Tool_Get_Teams_Channels'        => 'get_teams_channels',
			'WP_MCP_AI_Pro_Tool_Get_Teams_Messages'        => 'get_teams_messages',
			'WP_MCP_AI_Pro_Tool_Get_Telegram_Updates'      => 'get_telegram_updates',
			'WP_MCP_AI_Pro_Tool_Get_Twitter_DMs'           => 'get_twitter_dms',
			'WP_MCP_AI_Pro_Tool_Get_WhatsApp_Messages'     => 'get_whatsapp_messages',
			'WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members' => 'list_google_chat_space_members',
			'WP_MCP_AI_Pro_Tool_List_Icloud_Drive_Files'   => 'list_icloud_drive_files',
			'WP_MCP_AI_Pro_Tool_List_OneDrive_Files'       => 'list_onedrive_files',
			'WP_MCP_AI_Pro_Tool_Manage_Telegram_Commands'  => 'manage_telegram_commands',
			'WP_MCP_AI_Pro_Tool_Manage_Telegram_Webhook'   => 'manage_telegram_webhook',
			'WP_MCP_AI_Pro_Tool_Manage_Twitter_Webhook'    => 'manage_twitter_webhook',
			'WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member' => 'remove_google_chat_space_member',
			'WP_MCP_AI_Pro_Tool_Schedule_Notify_SMS'       => 'schedule_notify_sms',
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message'        => 'send_apple_message',
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group'  => 'send_apple_message_group',
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message_Interactive' => 'send_apple_message_interactive',
			'WP_MCP_AI_Pro_Tool_Send_Discord_Message'      => 'send_discord_message',
			'WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message'  => 'send_google_chat_message',
			'WP_MCP_AI_Pro_Tool_Send_Messenger_Message'    => 'send_messenger_message',
			'WP_MCP_AI_Pro_Tool_Send_Outlook_Mail'         => 'send_outlook_mail',
			'WP_MCP_AI_Pro_Tool_Send_Slack_Message'        => 'send_slack_message',
			'WP_MCP_AI_Pro_Tool_Send_Teams_Message'        => 'send_teams_message',
			'WP_MCP_AI_Pro_Tool_Send_Telegram_Message'     => 'send_telegram_message',
			'WP_MCP_AI_Pro_Tool_Send_Twitter_DM'           => 'send_twitter_dm',
			'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Interactive' => 'send_whatsapp_interactive',
			'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Media'       => 'send_whatsapp_media',
			'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Message'     => 'send_whatsapp_message',
			'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Template'    => 'send_whatsapp_template',
			'WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast' => 'unified_channel_broadcast',
			'WP_MCP_AI_Pro_Tool_Upload_Icloud_Drive_File'  => 'upload_icloud_drive_file',
			'WP_MCP_AI_Pro_Tool_Upload_OneDrive_File'      => 'upload_onedrive_file',
			'WP_MCP_AI_Tool_Import_Chat_Channels_Blueprint' => 'import_chat_channels_blueprint',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}

		$import = new WP_MCP_AI_Tool_Import_Chat_Channels_Blueprint();
		$this->assertTrue( $import->requires_base_pro() );
	}

	/**
	 * The first argument gates must be byte-identical: the capability check
	 * passes for an author, then the missing-field WP_Error fires.
	 */
	public function test_gate_contracts(): void {
		// The send/reaction tools' execute gates default to `manage_options`
		// (byte-identical filterable capability), so an administrator passes
		// the capability gate and the missing-field WP_Error fires.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$reaction = new WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction();
		$result   = $reaction->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_discord_token', $result->get_error_code() );

		$slack  = new WP_MCP_AI_Pro_Tool_Send_Slack_Message();
		$result = $slack->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_slack_token', $result->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the full
	 * fifty-one-entry chat-channels map and the ecosystem registration
	 * helper must load.
	 */
	public function test_standalone_filter_and_registration(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base chat-channels init registers the tools at boot.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/chat-channels/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_chat_channels_tools', 10 );
		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 51, $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_Chat_Channels_Blueprint', $tools );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_chat_channels_ecosystem_tools' ) );

		$this->assertFileExists(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/chat-channels/examples/multi-channel-communications-manager.json'
		);
	}
}
