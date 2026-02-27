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
	// Apple Messages for Business Tools
	// =========================================================================

	/**
	 * Send Apple Message tool: get_slug() returns correct slug.
	 */
	public function test_send_apple_message_tool_get_slug() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Send_Apple_Message();
		$this->assertSame( 'send_apple_message', $tool->get_slug() );
	}

	/**
	 * Send Apple Message tool: is_available() returns true.
	 */
	public function test_send_apple_message_tool_is_available() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message.php'
		);

		$this->assertTrue( WP_MCP_AI_Pro_Tool_Send_Apple_Message::is_available() );
	}

	/**
	 * Send Apple Message tool: execute returns WP_Error for missing api_key.
	 */
	public function test_send_apple_message_tool_returns_error_without_api_key() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message.php'
		);

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Apple_Message();
		$result = $tool->execute(
			array(
				'msp_api_url' => 'https://api.example.com/v1/apple/messages',
				// api_key intentionally omitted.
				'business_id' => 'biz-123',
				'message'     => 'Hello!',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_apple_api_key', $result->get_error_code() );

		wp_delete_user( $user_id );
	}

	/**
	 * Send Apple Message tool: execute returns WP_Error for non-HTTPS msp_api_url.
	 */
	public function test_send_apple_message_tool_rejects_http_url() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message.php'
		);

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Apple_Message();
		$result = $tool->execute(
			array(
				'msp_api_url' => 'http://api.example.com/v1/apple/messages',
				'api_key'     => 'test-api-key',
				'business_id' => 'biz-123',
				'message'     => 'Hello!',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_apple_msp_url', $result->get_error_code() );

		wp_delete_user( $user_id );
	}

	/**
	 * Send Apple Message tool: capability flags include 'pro' and 'write'.
	 */
	public function test_send_apple_message_tool_capability_flags() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message.php'
		);

		$tool  = new WP_MCP_AI_Pro_Tool_Send_Apple_Message();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'external-api', $flags );
	}

	/**
	 * Get Apple Messages tool: get_slug() returns correct slug.
	 */
	public function test_get_apple_messages_tool_get_slug() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Get_Apple_Messages',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-apple-messages.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Get_Apple_Messages();
		$this->assertSame( 'get_apple_messages', $tool->get_slug() );
	}

	/**
	 * Get Apple Messages tool: capability flags include 'read-only'.
	 */
	public function test_get_apple_messages_tool_capability_flags_include_read_only() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Get_Apple_Messages',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-apple-messages.php'
		);

		$tool  = new WP_MCP_AI_Pro_Tool_Get_Apple_Messages();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'pro', $flags );
	}

	/**
	 * Send Apple Message Interactive tool: get_slug() returns correct slug.
	 */
	public function test_send_apple_message_interactive_tool_get_slug() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message_Interactive',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message-interactive.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Send_Apple_Message_Interactive();
		$this->assertSame( 'send_apple_message_interactive', $tool->get_slug() );
	}

	/**
	 * Send Apple Message Interactive tool: execute returns WP_Error for unsupported type.
	 */
	public function test_send_apple_message_interactive_rejects_invalid_type() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message_Interactive',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message-interactive.php'
		);

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Apple_Message_Interactive();
		$result = $tool->execute(
			array(
				'msp_api_url'      => 'https://api.example.com/v1/apple/messages',
				'api_key'          => 'test-key',
				'business_id'      => 'biz-123',
				'interactive_type' => 'invalid_type',
				'body_text'        => 'Choose an option',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_apple_interactive_type', $result->get_error_code() );

		wp_delete_user( $user_id );
	}

	/**
	 * Send Apple Message Interactive tool: supported types constant covers expected values.
	 */
	public function test_send_apple_message_interactive_supported_types() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message_Interactive',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message-interactive.php'
		);

		$expected_types = array( 'list_picker', 'time_picker', 'rich_link', 'authenticate' );

		foreach ( $expected_types as $type ) {
			$this->assertContains( $type, WP_MCP_AI_Pro_Tool_Send_Apple_Message_Interactive::SUPPORTED_TYPES );
		}
	}

	/**
	 * Send Apple Group Message tool: get_slug() returns correct slug.
	 */
	public function test_send_apple_message_group_tool_get_slug() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message-group.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group();
		$this->assertSame( 'send_apple_message_group', $tool->get_slug() );
	}

	/**
	 * Send Apple Group Message tool: rejects participant lists exceeding MAX_PARTICIPANTS.
	 */
	public function test_send_apple_message_group_tool_rejects_too_many_participants() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message-group.php'
		);

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Build a participant list that exceeds MAX_PARTICIPANTS (32).
		$participants = array_map(
			static function ( $i ) {
				return 'participant_' . $i;
			},
			range( 1, 33 )
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group();
		$result = $tool->execute(
			array(
				'msp_api_url'  => 'https://api.example.com/v1/apple/messages',
				'api_key'      => 'test-key',
				'business_id'  => 'biz-123',
				'message'      => 'Hello group!',
				'participants' => $participants,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_too_many_participants', $result->get_error_code() );

		wp_delete_user( $user_id );
	}

	/**
	 * Send Apple Group Message tool: MAX_PARTICIPANTS constant equals 32.
	 */
	public function test_send_apple_message_group_max_participants_constant() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group',
			'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-apple-message-group.php'
		);

		$this->assertSame( 32, WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group::MAX_PARTICIPANTS );
	}
}

