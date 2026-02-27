<?php
/**
 * Tests for Chat Channels CCTs, REST controller, and WhatsApp webhook persistence.
 *
 * Validates the new channel_messages and channel_contacts CCT helpers, the
 * Chat Channels REST controller route registrations, the admin menu class, and
 * the WhatsApp webhook's human-takeover keyword logic.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Chat Channels feature.
 */
class Test_Chat_Channels extends WP_UnitTestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Load a pro class from its file path if not already loaded.
	 *
	 * @param string $class_name    PHP class name.
	 * @param string $relative_path Path relative to WP_MCP_AI_PRO_PATH.
	 */
	private function load_pro_class( $class_name, $relative_path ) {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		if ( ! class_exists( $class_name ) ) {
			$full_path = WP_MCP_AI_PRO_PATH . $relative_path;
			if ( file_exists( $full_path ) ) {
				require_once $full_path;
			} else {
				$this->markTestSkipped( $class_name . ' file not found at ' . $full_path );
			}
		}
	}

	/**
	 * Load a ChatChannels tool class by file name, including the tool interface.
	 *
	 * Centralises the interface + class loading pattern shared by slug, schema,
	 * and execute validation tests.
	 *
	 * @param string $class_name PHP class name.
	 * @param string $file_name  File name inside includes/src/Tools/ChatChannels/.
	 */
	private function load_channel_tool( $class_name, $file_name ) {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		if ( class_exists( $class_name ) ) {
			return;
		}

		$interface_path = WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
		if ( file_exists( $interface_path ) ) {
			require_once $interface_path;
		}

		$path = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/' . $file_name;
		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( $class_name . ' file not found at ' . $path );
		}

		require_once $path;

		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( $class_name . ' could not be loaded' );
		}
	}

	// =========================================================================
	// Channel Messages CCT
	// =========================================================================

	/**
	 * The CCT slug constant must equal 'channel_messages'.
	 */
	public function test_channel_messages_cct_slug_constant() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Messages_CCT', 'includes/class-wp-mcp-ai-channel-messages-cct.php' );
		$this->assertSame( 'channel_messages', WP_MCP_AI_Channel_Messages_CCT::SLUG );
	}

	/**
	 * The field ID base must be 41000.
	 */
	public function test_channel_messages_cct_field_id_base() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Messages_CCT', 'includes/class-wp-mcp-ai-channel-messages-cct.php' );
		$this->assertSame( 41000, WP_MCP_AI_Channel_Messages_CCT::FIELD_ID_BASE );
	}

	/**
	 * Get_slug() helper must return the SLUG constant value.
	 */
	public function test_channel_messages_get_slug() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Messages_CCT', 'includes/class-wp-mcp-ai-channel-messages-cct.php' );
		$this->assertSame( WP_MCP_AI_Channel_Messages_CCT::SLUG, WP_MCP_AI_Channel_Messages_CCT::get_slug() );
	}

	/**
	 * Get_table_name() must include the WP table prefix and the CCT slug.
	 */
	public function test_channel_messages_cct_table_name_format() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Messages_CCT', 'includes/class-wp-mcp-ai-channel-messages-cct.php' );
		global $wpdb;
		$table = WP_MCP_AI_Channel_Messages_CCT::get_table_name();
		$this->assertStringContainsString( $wpdb->prefix, $table );
		$this->assertStringContainsString( 'channel_messages', $table );
	}

	/**
	 * Table_exists() must return false in a clean test environment (no JetEngine).
	 */
	public function test_channel_messages_table_exists_false_without_jetengine() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Messages_CCT', 'includes/class-wp-mcp-ai-channel-messages-cct.php' );
		$this->assertFalse( WP_MCP_AI_Channel_Messages_CCT::table_exists() );
	}

	/**
	 * Insert() must return false when the CCT table does not exist.
	 */
	public function test_channel_messages_insert_returns_false_without_table() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Messages_CCT', 'includes/class-wp-mcp-ai-channel-messages-cct.php' );
		$result = WP_MCP_AI_Channel_Messages_CCT::insert(
			array(
				'channel'            => 'whatsapp',
				'channel_contact_id' => '15551234567',
				'content'            => 'Hello',
				'direction'          => 'inbound',
			)
		);
		$this->assertFalse( $result );
	}

	// =========================================================================
	// Channel Contacts CCT
	// =========================================================================

	/**
	 * The CCT slug constant must equal 'channel_contacts'.
	 */
	public function test_channel_contacts_cct_slug_constant() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );
		$this->assertSame( 'channel_contacts', WP_MCP_AI_Channel_Contacts_CCT::SLUG );
	}

	/**
	 * The field ID base must be 42000, safely separated from channel_messages (41000).
	 */
	public function test_channel_contacts_cct_field_id_base() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );
		$this->assertSame( 42000, WP_MCP_AI_Channel_Contacts_CCT::FIELD_ID_BASE );
	}

	/**
	 * Status constants must match expected strings.
	 */
	public function test_channel_contacts_status_constants() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );
		$this->assertSame( 'new', WP_MCP_AI_Channel_Contacts_CCT::STATUS_NEW );
		$this->assertSame( 'active', WP_MCP_AI_Channel_Contacts_CCT::STATUS_ACTIVE );
		$this->assertSame( 'resolved', WP_MCP_AI_Channel_Contacts_CCT::STATUS_RESOLVED );
		$this->assertSame( 'blocked', WP_MCP_AI_Channel_Contacts_CCT::STATUS_BLOCKED );
	}

	/**
	 * Get_table_name() must include the WP prefix and 'channel_contacts'.
	 */
	public function test_channel_contacts_table_name_format() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );
		global $wpdb;
		$table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		$this->assertStringContainsString( $wpdb->prefix, $table );
		$this->assertStringContainsString( 'channel_contacts', $table );
	}

	/**
	 * Find_or_create() returns false when the table does not exist.
	 */
	public function test_channel_contacts_find_or_create_returns_false_without_table() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );
		$result = WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'whatsapp', '15551234567' );
		$this->assertFalse( $result );
	}

	/**
	 * Find_or_create() returns false when channel or contact ID is empty.
	 */
	public function test_channel_contacts_find_or_create_empty_args() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );
		$this->assertFalse( WP_MCP_AI_Channel_Contacts_CCT::find_or_create( '', '12345' ) );
		$this->assertFalse( WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'whatsapp', '' ) );
	}

	/**
	 * Is_human_takeover_active() returns false when table does not exist.
	 */
	public function test_channel_contacts_human_takeover_false_without_table() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );
		$result = WP_MCP_AI_Channel_Contacts_CCT::is_human_takeover_active( 'whatsapp', '15551234567' );
		$this->assertFalse( $result );
	}

	// =========================================================================
	// Chat Channels REST Controller
	// =========================================================================

	/**
	 * The REST controller must register its routes on rest_api_init.
	 */
	public function test_chat_channels_rest_controller_registers_routes() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		// Manually call register_routes so we can inspect the server.
		$controller->register_routes();

		$server = rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/mcp-ai-pro/v1/chat-channels/conversations', $routes, 'Conversations route must be registered' );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/chat-channels/reply', $routes, 'Reply route must be registered' );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/chat-channels/contacts', $routes, 'Contacts route must be registered' );
	}

	/**
	 * The admin_permissions_check must reject unauthenticated requests.
	 */
	public function test_chat_channels_rest_controller_permissions_unauthenticated() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		wp_set_current_user( 0 ); // Not logged in.

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/chat-channels/conversations' );
		$result     = $controller->admin_permissions_check( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * The admin_permissions_check must allow administrators.
	 */
	public function test_chat_channels_rest_controller_permissions_admin() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/chat-channels/conversations' );
		$result     = $controller->admin_permissions_check( $request );

		$this->assertTrue( $result );

		wp_set_current_user( 0 );
	}

	/**
	 * Get_conversations returns an empty result set when the CCT table does not exist.
	 */
	public function test_chat_channels_get_conversations_empty_without_table() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/chat-channels/conversations' );
		$response   = $controller->get_conversations( $request );
		$data       = $response->get_data();

		$this->assertSame( array(), $data['items'] );
		$this->assertSame( 0, $data['total'] );
	}

	// =========================================================================
	// Chat Channels Menu
	// =========================================================================

	/**
	 * The menu slug constant must equal the expected string.
	 */
	public function test_chat_channels_menu_slug_constant() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_Menu', 'includes/admin/class-wp-mcp-ai-chat-channels-menu.php' );
		$this->assertSame( 'wp-mcp-ai-chat-channels', WP_MCP_AI_Chat_Channels_Menu::MENU_SLUG );
	}

	/**
	 * The capability constant must be manage_options.
	 */
	public function test_chat_channels_menu_capability_constant() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_Menu', 'includes/admin/class-wp-mcp-ai-chat-channels-menu.php' );
		$this->assertSame( 'manage_options', WP_MCP_AI_Chat_Channels_Menu::CAPABILITY );
	}

	// =========================================================================
	// WhatsApp Webhook – human takeover gate (unit-level)
	// =========================================================================

	/**
	 * Is_human_takeover_active() must return false when the CCT table does not exist.
	 *
	 * This mirrors the gate inside maybe_auto_reply(): if the table isn't there, no
	 * takeover is flagged and AI auto-reply proceeds normally.
	 */
	public function test_whatsapp_webhook_human_takeover_gate_without_table() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );

		$is_active = WP_MCP_AI_Channel_Contacts_CCT::is_human_takeover_active( 'whatsapp', '15551234567' );

		$this->assertFalse( $is_active, 'Human takeover must default to false when table is absent' );
	}

	/**
	 * WhatsApp webhook controller must expose the get_channel_contact_id helper.
	 */
	public function test_whatsapp_webhook_controller_has_get_channel_contact_id_method() {
		$this->load_pro_class( 'WP_MCP_AI_WhatsApp_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php' );

		$reflection = new ReflectionClass( 'WP_MCP_AI_WhatsApp_Webhook_Controller' );
		$this->assertTrue(
			$reflection->hasMethod( 'get_channel_contact_id' ),
			'get_channel_contact_id helper method must exist on the webhook controller'
		);
	}

	/**
	 * Get_channel_contact_id must return null when the CCT table does not exist.
	 */
	public function test_whatsapp_webhook_get_channel_contact_id_returns_null_without_table() {
		$this->load_pro_class( 'WP_MCP_AI_WhatsApp_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php' );
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_channel_contact_id' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, 'whatsapp', '15551234567' );

		$this->assertNull( $result, 'Must return null when channel_contacts table does not exist' );
	}

	// =========================================================================
	// Tool file existence – all platform tool classes must be loadable
	// =========================================================================

	/**
	 * Helper: assert a ChatChannels tool file exists and the class is loadable.
	 *
	 * @param string $class_name PHP class name.
	 * @param string $file_name  File name inside includes/src/Tools/ChatChannels/.
	 */
	private function assert_chat_channels_tool_loadable( $class_name, $file_name ) {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$path = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/' . $file_name;
		$this->assertFileExists( $path, $file_name . ' must exist in ChatChannels directory' );

		if ( ! class_exists( $class_name ) ) {
			if ( file_exists( WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
			}
			require_once $path;
		}

		$this->assertTrue( class_exists( $class_name ), $class_name . ' must be loadable' );

		$tool = new $class_name();
		$this->assertNotEmpty( $tool->get_slug(), $class_name . '::get_slug() must return a non-empty string' );
		$this->assertNotEmpty( $tool->get_name(), $class_name . '::get_name() must return a non-empty string' );
		$this->assertNotEmpty( $tool->get_description(), $class_name . '::get_description() must return a non-empty string' );
	}

	// ----- WebChat P2P -------------------------------------------------------

	/** WebChat P2P send message tool must be loadable. */
	public function test_chat_channels_tool_send_webchat_message_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Send_WebChat_Message',
			'class-wp-mcp-ai-pro-tool-send-webchat-message.php'
		);
	}

	// ----- Telegram ----------------------------------------------------------

	/** Telegram get-updates tool must be loadable. */
	public function test_chat_channels_tool_get_telegram_updates_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Telegram_Updates',
			'class-wp-mcp-ai-pro-tool-get-telegram-updates.php'
		);
	}

	/** Telegram manage-webhook tool must be loadable. */
	public function test_chat_channels_tool_manage_telegram_webhook_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Manage_Telegram_Webhook',
			'class-wp-mcp-ai-pro-tool-manage-telegram-webhook.php'
		);
	}

	// ----- WhatsApp ----------------------------------------------------------

	/** WhatsApp get-messages tool must be loadable. */
	public function test_chat_channels_tool_get_whatsapp_messages_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_WhatsApp_Messages',
			'class-wp-mcp-ai-pro-tool-get-whatsapp-messages.php'
		);
	}

	/** WhatsApp send-interactive tool must be loadable. */
	public function test_chat_channels_tool_send_whatsapp_interactive_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Interactive',
			'class-wp-mcp-ai-pro-tool-send-whatsapp-interactive.php'
		);
	}

	/** WhatsApp send-media tool must be loadable. */
	public function test_chat_channels_tool_send_whatsapp_media_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Media',
			'class-wp-mcp-ai-pro-tool-send-whatsapp-media.php'
		);
	}

	/** WhatsApp send-template tool must be loadable. */
	public function test_chat_channels_tool_send_whatsapp_template_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Template',
			'class-wp-mcp-ai-pro-tool-send-whatsapp-template.php'
		);
	}

	// ----- Slack -------------------------------------------------------------

	/** Slack get-channels tool must be loadable. */
	public function test_chat_channels_tool_get_slack_channels_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Slack_Channels',
			'class-wp-mcp-ai-pro-tool-get-slack-channels.php'
		);
	}

	/** Slack get-messages tool must be loadable. */
	public function test_chat_channels_tool_get_slack_messages_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Slack_Messages',
			'class-wp-mcp-ai-pro-tool-get-slack-messages.php'
		);
	}

	/** Slack send-message tool must be loadable. */
	public function test_chat_channels_tool_send_slack_message_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Send_Slack_Message',
			'class-wp-mcp-ai-pro-tool-send-slack-message.php'
		);
	}

	/** Slack create-channel tool must be loadable. */
	public function test_chat_channels_tool_create_slack_channel_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Create_Slack_Channel',
			'class-wp-mcp-ai-pro-tool-create-slack-channel.php'
		);
	}

	// ----- Discord -----------------------------------------------------------

	/** Discord get-channels tool must be loadable. */
	public function test_chat_channels_tool_get_discord_channels_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Discord_Channels',
			'class-wp-mcp-ai-pro-tool-get-discord-channels.php'
		);
	}

	/** Discord get-messages tool must be loadable. */
	public function test_chat_channels_tool_get_discord_messages_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Discord_Messages',
			'class-wp-mcp-ai-pro-tool-get-discord-messages.php'
		);
	}

	/** Discord send-message tool must be loadable. */
	public function test_chat_channels_tool_send_discord_message_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Send_Discord_Message',
			'class-wp-mcp-ai-pro-tool-send-discord-message.php'
		);
	}

	/** Discord create-channel tool must be loadable. */
	public function test_chat_channels_tool_create_discord_channel_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Create_Discord_Channel',
			'class-wp-mcp-ai-pro-tool-create-discord-channel.php'
		);
	}

	// ----- Microsoft Teams ---------------------------------------------------

	/** Teams get-channels tool must be loadable. */
	public function test_chat_channels_tool_get_teams_channels_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Teams_Channels',
			'class-wp-mcp-ai-pro-tool-get-teams-channels.php'
		);
	}

	/** Teams get-messages tool must be loadable. */
	public function test_chat_channels_tool_get_teams_messages_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Teams_Messages',
			'class-wp-mcp-ai-pro-tool-get-teams-messages.php'
		);
	}

	/** Teams send-message tool must be loadable. */
	public function test_chat_channels_tool_send_teams_message_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Send_Teams_Message',
			'class-wp-mcp-ai-pro-tool-send-teams-message.php'
		);
	}

	// ----- Facebook Messenger ------------------------------------------------

	/** Messenger get-conversations tool must be loadable. */
	public function test_chat_channels_tool_get_messenger_conversations_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Messenger_Conversations',
			'class-wp-mcp-ai-pro-tool-get-messenger-conversations.php'
		);
	}

	/** Messenger send-message tool must be loadable. */
	public function test_chat_channels_tool_send_messenger_message_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Send_Messenger_Message',
			'class-wp-mcp-ai-pro-tool-send-messenger-message.php'
		);
	}

	/** Messenger create-broadcast tool must be loadable. */
	public function test_chat_channels_tool_create_messenger_broadcast_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Create_Messenger_Broadcast',
			'class-wp-mcp-ai-pro-tool-create-messenger-broadcast.php'
		);
	}

	// ----- Unified broadcast -------------------------------------------------

	/** Unified channel broadcast tool must be loadable. */
	public function test_chat_channels_tool_unified_channel_broadcast_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast',
			'class-wp-mcp-ai-pro-tool-unified-channel-broadcast.php'
		);
	}

	/** Unified broadcast slug must equal 'unified_channel_broadcast'. */
	public function test_chat_channels_unified_broadcast_slug() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$path = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-unified-channel-broadcast.php';
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast' ) && file_exists( $path ) ) {
			if ( file_exists( WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
			}
			require_once $path;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast could not be loaded' );
		}

		$tool = new WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast();
		$this->assertSame( 'unified_channel_broadcast', $tool->get_slug() );
	}

	// ----- OpenClaw Feb 2026 parity: Reactions & Voice -----------------------

	/** Discord add-message-reaction tool must be loadable. */
	public function test_chat_channels_tool_add_discord_message_reaction_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction',
			'class-wp-mcp-ai-pro-tool-add-discord-message-reaction.php'
		);
	}

	/** Discord add-reaction slug must equal 'add_discord_message_reaction'. */
	public function test_chat_channels_discord_reaction_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction',
			'class-wp-mcp-ai-pro-tool-add-discord-message-reaction.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction();
		$this->assertSame( 'add_discord_message_reaction', $tool->get_slug() );
	}

	/** Discord add-reaction schema must require token, channel_id, message_id, emoji. */
	public function test_chat_channels_discord_reaction_schema_required_fields() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction',
			'class-wp-mcp-ai-pro-tool-add-discord-message-reaction.php'
		);

		$tool     = new WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction();
		$schema   = $tool->get_parameters_schema();
		$required = $schema['required'];

		$this->assertContains( 'token', $required );
		$this->assertContains( 'channel_id', $required );
		$this->assertContains( 'message_id', $required );
		$this->assertContains( 'emoji', $required );
	}

	/** Discord add-reaction returns WP_Error when token is missing. */
	public function test_chat_channels_discord_reaction_execute_missing_token() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction',
			'class-wp-mcp-ai-pro-tool-add-discord-message-reaction.php'
		);

		// Run as admin so the capability gate passes.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction();
		$result = $tool->execute(
			array(
				'channel_id' => '123456789',
				'message_id' => '987654321',
				'emoji'      => '👍',
				// token intentionally omitted.
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_discord_token', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	/** Telegram add-message-reaction tool must be loadable. */
	public function test_chat_channels_tool_add_telegram_message_reaction_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction',
			'class-wp-mcp-ai-pro-tool-add-telegram-message-reaction.php'
		);
	}

	/** Telegram add-reaction slug must equal 'add_telegram_message_reaction'. */
	public function test_chat_channels_telegram_reaction_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction',
			'class-wp-mcp-ai-pro-tool-add-telegram-message-reaction.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction();
		$this->assertSame( 'add_telegram_message_reaction', $tool->get_slug() );
	}

	/** Telegram add-reaction schema must require token, chat_id, message_id, emoji. */
	public function test_chat_channels_telegram_reaction_schema_required_fields() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction',
			'class-wp-mcp-ai-pro-tool-add-telegram-message-reaction.php'
		);

		$tool     = new WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction();
		$schema   = $tool->get_parameters_schema();
		$required = $schema['required'];

		$this->assertContains( 'token', $required );
		$this->assertContains( 'chat_id', $required );
		$this->assertContains( 'message_id', $required );
		$this->assertContains( 'emoji', $required );
	}

	/** Telegram add-reaction returns WP_Error when token is missing. */
	public function test_chat_channels_telegram_reaction_execute_missing_token() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction',
			'class-wp-mcp-ai-pro-tool-add-telegram-message-reaction.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction();
		$result = $tool->execute(
			array(
				'chat_id'    => '123456789',
				'message_id' => 42,
				'emoji'      => '👍',
				// token intentionally omitted.
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_telegram_token', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	/** Discord get-voice-channel-members tool must be loadable. */
	public function test_chat_channels_tool_get_discord_voice_channel_members_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Discord_Voice_Channel_Members',
			'class-wp-mcp-ai-pro-tool-get-discord-voice-channel-members.php'
		);
	}

	/** Discord voice-channel-members slug must equal 'get_discord_voice_channel_members'. */
	public function test_chat_channels_discord_voice_members_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Discord_Voice_Channel_Members',
			'class-wp-mcp-ai-pro-tool-get-discord-voice-channel-members.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Get_Discord_Voice_Channel_Members();
		$this->assertSame( 'get_discord_voice_channel_members', $tool->get_slug() );
	}

	/** Discord voice-channel-members returns WP_Error when token is missing. */
	public function test_chat_channels_discord_voice_members_execute_missing_token() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Discord_Voice_Channel_Members',
			'class-wp-mcp-ai-pro-tool-get-discord-voice-channel-members.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Discord_Voice_Channel_Members();
		$result = $tool->execute(
			array(
				'channel_id' => '123456789',
				// token intentionally omitted.
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_discord_token', $result->get_error_code() );

		wp_set_current_user( 0 );
	}
}
