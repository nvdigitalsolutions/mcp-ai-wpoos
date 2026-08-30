<?php
/**
 * Tests for Chat Channels CCTs, REST controller, and WhatsApp webhook persistence.
 *
 * Validates the new channel_messages and channel_contacts CCT helpers, the
 * Chat Channels REST controller route registrations, the admin menu class, and
 * the WhatsApp webhook's human-takeover keyword logic.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * @param string $file_name  File name inside includes/tools/chat-channels/.
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

		$path = WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/' . $file_name;
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
	 * The contacts CCT registration payload must include a connection_id field.
	 */
	public function test_channel_contacts_cct_schema_has_connection_id_field() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );

		$reflection = new ReflectionClass( 'WP_MCP_AI_Channel_Contacts_CCT' );
		$method     = $reflection->getMethod( 'get_registration_request' );
		$method->setAccessible( true );

		$payload = $method->invoke( null );
		$fields  = $payload['meta_fields'];
		$names   = array_column( $fields, 'name' );

		$this->assertContains( 'connection_id', $names, 'channel_contacts CCT schema must include a connection_id field' );
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
	 * Find_or_create() returns false without table even when connection_id is provided.
	 */
	public function test_channel_contacts_find_or_create_with_connection_id_returns_false_without_table() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );
		$result = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
			'slack',
			'U12345678',
			array( 'connection_id' => 'conn_a' )
		);
		$this->assertFalse( $result );
	}

	/**
	 * Is_human_takeover_active() returns false when table does not exist.
	 */
	public function test_channel_contacts_human_takeover_false_without_table() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );
		$result = WP_MCP_AI_Channel_Contacts_CCT::is_human_takeover_active( 'whatsapp', '15551234567' );
		$this->assertFalse( $result );
	}

	/**
	 * Is_human_takeover_active() accepts a connection_id argument and returns false without table.
	 */
	public function test_channel_contacts_human_takeover_with_connection_id_false_without_table() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );
		$result = WP_MCP_AI_Channel_Contacts_CCT::is_human_takeover_active( 'telegram', '987654321', 'conn_tg' );
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

		// Expect the incorrect usage notice since we call register_routes()
		// directly outside of the rest_api_init action.
		$this->setExpectedIncorrectUsage( 'register_rest_route' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		// Manually call register_routes so we can inspect the server.
		$controller->register_routes();

		$server = rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/mcp-ai-pro/v1/chat-channels/conversations', $routes, 'Conversations route must be registered' );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/chat-channels/reply', $routes, 'Reply route must be registered' );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/chat-channels/contacts', $routes, 'Contacts route must be registered' );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/chat-channels/conversations/(?P<contact_id>[0-9]+)/messages', $routes, 'Conversation messages route must be registered' );
	}

	/**
	 * The messages route must accept the optional source parameter.
	 */
	public function test_messages_route_has_source_arg() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$this->setExpectedIncorrectUsage( 'register_rest_route' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$controller->register_routes();

		$server = rest_get_server();
		$routes = $server->get_routes();
		$key    = '/mcp-ai-pro/v1/chat-channels/conversations/(?P<contact_id>[0-9]+)/messages';

		$this->assertArrayHasKey( $key, $routes, 'Messages route must be registered' );

		$endpoint = $routes[ $key ][0];
		$this->assertArrayHasKey( 'source', $endpoint['args'], 'Messages endpoint must accept "source" parameter' );
	}

	/**
	 * Resolve_contact_from_cpt populates channel and channel_contact_id from CPT meta.
	 */
	public function test_resolve_contact_from_cpt_finds_cpt_contact() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CPT', 'includes/class-wp-mcp-ai-channel-contacts-cpt.php' );

		// Register the CPT so that get_post() works.
		WP_MCP_AI_Channel_Contacts_CPT::register_post_type();

		$post_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE,
				'post_title'  => 'Test Contact',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_channel', 'whatsapp' );
		update_post_meta( $post_id, '_channel_contact_id', '12345' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_contact_from_cpt' );
		$method->setAccessible( true );

		$channel            = '';
		$channel_contact_id = '';
		$method->invokeArgs( $controller, array( $post_id, &$channel, &$channel_contact_id ) );

		$this->assertSame( 'whatsapp', $channel );
		$this->assertSame( '12345', $channel_contact_id );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Resolve_contact_from_cpt does not populate when post type mismatches.
	 */
	public function test_resolve_contact_from_cpt_ignores_wrong_post_type() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CPT', 'includes/class-wp-mcp-ai-channel-contacts-cpt.php' );

		// Create a regular post (not a contact CPT).
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Regular Post',
				'post_status' => 'publish',
			)
		);

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_contact_from_cpt' );
		$method->setAccessible( true );

		$channel            = '';
		$channel_contact_id = '';
		$method->invokeArgs( $controller, array( $post_id, &$channel, &$channel_contact_id ) );

		$this->assertSame( '', $channel, 'Channel should remain empty for wrong post type' );
		$this->assertSame( '', $channel_contact_id, 'Channel contact ID should remain empty for wrong post type' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Resolve_contact_from_cpt does not populate when channel meta is empty.
	 */
	public function test_resolve_contact_from_cpt_rejects_empty_channel() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CPT', 'includes/class-wp-mcp-ai-channel-contacts-cpt.php' );

		WP_MCP_AI_Channel_Contacts_CPT::register_post_type();

		$post_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE,
				'post_title'  => 'Incomplete Contact',
				'post_status' => 'publish',
			)
		);
		// channel is empty, channel_contact_id is set.
		update_post_meta( $post_id, '_channel', '' );
		update_post_meta( $post_id, '_channel_contact_id', '12345' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_contact_from_cpt' );
		$method->setAccessible( true );

		$channel            = '';
		$channel_contact_id = '';
		$method->invokeArgs( $controller, array( $post_id, &$channel, &$channel_contact_id ) );

		$this->assertSame( '', $channel, 'Channel should remain empty when meta is blank' );
		$this->assertSame( '', $channel_contact_id, 'Channel contact ID should remain empty when channel is blank' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Format_message includes decoded raw_payload when include_metadata is enabled.
	 */
	public function test_chat_channels_format_message_include_metadata() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'format_message' );
		$method->setAccessible( true );

		$row = array(
			'_ID'                => 55,
			'channel'            => 'telegram',
			'channel_contact_id' => '123',
			'raw_payload'        => wp_json_encode(
				array(
					'agentic_tool_messages' => array(
						array(
							'role'    => 'assistant',
							'content' => 'tool output',
						),
					),
				)
			),
		);

		$formatted = $method->invoke( $controller, $row, true );

		$this->assertArrayHasKey( 'raw_payload', $formatted );
		$this->assertSame( 'tool output', $formatted['raw_payload']['agentic_tool_messages'][0]['content'] );
	}

	/**
	 * Format_contact() must include connection_id in its return value.
	 */
	public function test_chat_channels_format_contact_includes_connection_id() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'format_contact' );
		$method->setAccessible( true );

		$row = array(
			'_ID'                => 1,
			'channel'            => 'slack',
			'channel_contact_id' => 'U12345678',
			'connection_id'      => 'conn_workspace_a',
			'display_name'       => 'Test User',
			'phone_number'       => '',
			'email'              => '',
			'tags'               => '[]',
			'crm_status'         => 'new',
			'notes'              => '',
			'assigned_agent'     => '',
			'human_takeover'     => 0,
			'last_message_at'    => 1700000000,
		);

		$formatted = $method->invoke( $controller, $row );

		$this->assertArrayHasKey( 'connection_id', $formatted, 'format_contact must include connection_id' );
		$this->assertSame( 'conn_workspace_a', $formatted['connection_id'] );
	}

	/**
	 * Format_contact() returns an empty string for connection_id when not set in the row.
	 */
	public function test_chat_channels_format_contact_connection_id_defaults_empty() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'format_contact' );
		$method->setAccessible( true );

		$row = array(
			'_ID'                => 2,
			'channel'            => 'whatsapp',
			'channel_contact_id' => '+1234567890',
			'display_name'       => 'Anon',
			'tags'               => '[]',
			'crm_status'         => 'new',
			'human_takeover'     => 0,
			'last_message_at'    => 0,
		);

		$formatted = $method->invoke( $controller, $row );

		$this->assertArrayHasKey( 'connection_id', $formatted, 'connection_id key must always be present' );
		$this->assertSame( '', $formatted['connection_id'], 'connection_id must default to empty string' );
	}

	/**
	 * Format_contact() includes a bot_username key that defaults to empty for non-Telegram contacts.
	 */
	public function test_chat_channels_format_contact_includes_bot_username() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'format_contact' );
		$method->setAccessible( true );

		// Non-Telegram channel: bot_username should always be empty.
		$row = array(
			'_ID'                => 5,
			'channel'            => 'slack',
			'channel_contact_id' => 'U12345678',
			'connection_id'      => 'conn_workspace_a',
			'display_name'       => 'Slack User',
			'tags'               => '[]',
			'crm_status'         => 'new',
			'human_takeover'     => 0,
			'last_message_at'    => 1700000000,
		);

		$formatted = $method->invoke( $controller, $row );

		$this->assertArrayHasKey( 'bot_username', $formatted, 'bot_username key must always be present' );
		$this->assertSame( '', $formatted['bot_username'], 'bot_username must be empty for non-Telegram contacts' );
	}

	/**
	 * Format_contact() returns empty bot_username for Telegram contact without connection_id.
	 */
	public function test_chat_channels_format_contact_telegram_no_connection_id() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'format_contact' );
		$method->setAccessible( true );

		// Telegram contact with no connection_id: bot_username should be empty.
		$row = array(
			'_ID'                => 6,
			'channel'            => 'telegram',
			'channel_contact_id' => '9988776655',
			'display_name'       => 'Test User',
			'tags'               => '[]',
			'crm_status'         => 'new',
			'human_takeover'     => 0,
			'last_message_at'    => 1700000000,
		);

		$formatted = $method->invoke( $controller, $row );

		$this->assertArrayHasKey( 'bot_username', $formatted, 'bot_username key must always be present' );
		$this->assertSame( '', $formatted['bot_username'], 'bot_username must be empty when no connection_id' );
	}

	/**
	 * Resolve_bot_username() method must exist and be callable via reflection.
	 */
	public function test_chat_channels_resolve_bot_username_method_exists() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );

		$this->assertTrue(
			$reflection->hasMethod( 'resolve_bot_username' ),
			'resolve_bot_username method must exist on the REST controller'
		);

		$method = $reflection->getMethod( 'resolve_bot_username' );
		$method->setAccessible( true );

		// With a non-existent connection_id, should return empty string.
		$result = $method->invoke( $controller, 'conn_nonexistent_12345' );
		$this->assertSame( '', $result, 'resolve_bot_username must return empty for unknown connection' );
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
	 * @param string $file_name  File name inside includes/tools/chat-channels/.
	 */
	private function assert_chat_channels_tool_loadable( $class_name, $file_name ) {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$path = WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/' . $file_name;
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
		// The WebChat P2P tool lives in the embedded addon's webchat tools
		// directory rather than the pro chat-channels directory.
		$path = WP_MCP_AI_PATH . 'addons/embedded/includes/webchat/tools/class-wp-mcp-ai-pro-tool-send-webchat-message.php';
		$this->assertFileExists( $path, 'send-webchat-message tool must exist in the embedded webchat tools directory' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Send_WebChat_Message' ) ) {
			require_once $path;
		}

		$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_Tool_Send_WebChat_Message' ), 'WP_MCP_AI_Pro_Tool_Send_WebChat_Message must be loadable' );

		$tool = new WP_MCP_AI_Pro_Tool_Send_WebChat_Message();
		$this->assertNotEmpty( $tool->get_slug(), 'WP_MCP_AI_Pro_Tool_Send_WebChat_Message::get_slug() must return a non-empty string' );
		$this->assertNotEmpty( $tool->get_name(), 'WP_MCP_AI_Pro_Tool_Send_WebChat_Message::get_name() must return a non-empty string' );
		$this->assertNotEmpty( $tool->get_description(), 'WP_MCP_AI_Pro_Tool_Send_WebChat_Message::get_description() must return a non-empty string' );
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

	/** Messenger get-conversations slug must equal 'get_messenger_conversations'. */
	public function test_chat_channels_messenger_conversations_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Messenger_Conversations',
			'class-wp-mcp-ai-pro-tool-get-messenger-conversations.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Get_Messenger_Conversations();
		$this->assertSame( 'get_messenger_conversations', $tool->get_slug() );
	}

	/** Messenger get-conversations returns WP_Error when access_token is missing. */
	public function test_chat_channels_messenger_conversations_execute_missing_token() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Messenger_Conversations',
			'class-wp-mcp-ai-pro-tool-get-messenger-conversations.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Messenger_Conversations();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_messenger_token', $result->get_error_code() );

		wp_set_current_user( 0 );
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

		$path = WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-unified-channel-broadcast.php';
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

	// =========================================================================
	// Apple Messages for Business Tools.
	// =========================================================================

	/**
	 * Send Apple Message tool: get_slug() returns correct slug.
	 */
	public function test_send_apple_message_tool_get_slug() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message',
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message.php'
		);

		$this->assertTrue( WP_MCP_AI_Pro_Tool_Send_Apple_Message::is_available() );
	}

	/**
	 * Send Apple Message tool: execute returns WP_Error for missing api_key.
	 */
	public function test_send_apple_message_tool_returns_error_without_api_key() {
		$this->load_pro_class(
			'WP_MCP_AI_Pro_Tool_Send_Apple_Message',
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-apple-messages.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-apple-messages.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message-interactive.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message-interactive.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message-interactive.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message-group.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message-group.php'
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
			'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-apple-message-group.php'
		);

		$this->assertSame( 32, WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group::MAX_PARTICIPANTS );
	}

	// =========================================================================
	// Office 365 – Outlook Tools
	// =========================================================================

	/** Outlook send-mail tool must be loadable. */
	public function test_chat_channels_tool_send_outlook_mail_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Send_Outlook_Mail',
			'class-wp-mcp-ai-pro-tool-send-outlook-mail.php'
		);
	}

	/** Outlook send-mail slug must equal 'send_outlook_mail'. */
	public function test_send_outlook_mail_tool_get_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Send_Outlook_Mail',
			'class-wp-mcp-ai-pro-tool-send-outlook-mail.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Send_Outlook_Mail();
		$this->assertSame( 'send_outlook_mail', $tool->get_slug() );
	}

	/** Outlook send-mail is_available() returns true. */
	public function test_send_outlook_mail_tool_is_available() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Send_Outlook_Mail',
			'class-wp-mcp-ai-pro-tool-send-outlook-mail.php'
		);

		$this->assertTrue( WP_MCP_AI_Pro_Tool_Send_Outlook_Mail::is_available() );
	}

	/** Outlook send-mail returns WP_Error for missing token. */
	public function test_send_outlook_mail_tool_returns_error_without_token() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Send_Outlook_Mail',
			'class-wp-mcp-ai-pro-tool-send-outlook-mail.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Outlook_Mail();
		$result = $tool->execute(
			array(
				'to_email' => 'test@example.com',
				'subject'  => 'Test Subject',
				'body'     => 'Test body content',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_outlook_token', $result->get_error_code() );

		wp_delete_user( $admin_id );
	}

	/** Outlook send-mail returns WP_Error for invalid email. */
	public function test_send_outlook_mail_tool_returns_error_for_invalid_email() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Send_Outlook_Mail',
			'class-wp-mcp-ai-pro-tool-send-outlook-mail.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Outlook_Mail();
		$result = $tool->execute(
			array(
				'token'    => 'test-token',
				'to_email' => 'not-an-email',
				'subject'  => 'Test Subject',
				'body'     => 'Test body content',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_to_email', $result->get_error_code() );

		wp_delete_user( $admin_id );
	}

	/** Outlook send-mail capability flags include 'pro' and 'write'. */
	public function test_send_outlook_mail_tool_capability_flags() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Send_Outlook_Mail',
			'class-wp-mcp-ai-pro-tool-send-outlook-mail.php'
		);

		$tool  = new WP_MCP_AI_Pro_Tool_Send_Outlook_Mail();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'write', $flags );
	}

	/** Outlook get-messages tool must be loadable. */
	public function test_chat_channels_tool_get_outlook_messages_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Outlook_Messages',
			'class-wp-mcp-ai-pro-tool-get-outlook-messages.php'
		);
	}

	/** Outlook get-messages slug must equal 'get_outlook_messages'. */
	public function test_get_outlook_messages_tool_get_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Outlook_Messages',
			'class-wp-mcp-ai-pro-tool-get-outlook-messages.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Get_Outlook_Messages();
		$this->assertSame( 'get_outlook_messages', $tool->get_slug() );
	}

	/** Outlook get-messages capability flags include 'read-only'. */
	public function test_get_outlook_messages_tool_capability_flags_include_read_only() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Outlook_Messages',
			'class-wp-mcp-ai-pro-tool-get-outlook-messages.php'
		);

		$tool  = new WP_MCP_AI_Pro_Tool_Get_Outlook_Messages();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'pro', $flags );
	}

	/** Outlook get-messages returns WP_Error for missing token. */
	public function test_get_outlook_messages_tool_returns_error_without_token() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Outlook_Messages',
			'class-wp-mcp-ai-pro-tool-get-outlook-messages.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Outlook_Messages();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_outlook_token', $result->get_error_code() );

		wp_delete_user( $admin_id );
	}

	// =========================================================================
	// Office 365 – OneDrive Tools
	// =========================================================================

	/** OneDrive list-files tool must be loadable. */
	public function test_chat_channels_tool_list_onedrive_files_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_List_Onedrive_Files',
			'class-wp-mcp-ai-pro-tool-list-onedrive-files.php'
		);
	}

	/** OneDrive list-files slug must equal 'list_onedrive_files'. */
	public function test_list_onedrive_files_tool_get_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_List_Onedrive_Files',
			'class-wp-mcp-ai-pro-tool-list-onedrive-files.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_List_Onedrive_Files();
		$this->assertSame( 'list_onedrive_files', $tool->get_slug() );
	}

	/** OneDrive list-files returns WP_Error for missing token. */
	public function test_list_onedrive_files_tool_returns_error_without_token() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_List_Onedrive_Files',
			'class-wp-mcp-ai-pro-tool-list-onedrive-files.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_List_Onedrive_Files();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_onedrive_token', $result->get_error_code() );

		wp_delete_user( $admin_id );
	}

	/** OneDrive get-file tool must be loadable. */
	public function test_chat_channels_tool_get_onedrive_file_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Onedrive_File',
			'class-wp-mcp-ai-pro-tool-get-onedrive-file.php'
		);
	}

	/** OneDrive get-file slug must equal 'get_onedrive_file'. */
	public function test_get_onedrive_file_tool_get_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Onedrive_File',
			'class-wp-mcp-ai-pro-tool-get-onedrive-file.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Get_Onedrive_File();
		$this->assertSame( 'get_onedrive_file', $tool->get_slug() );
	}

	/** OneDrive get-file returns WP_Error when neither item_id nor file_path is provided. */
	public function test_get_onedrive_file_tool_returns_error_without_identifier() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Onedrive_File',
			'class-wp-mcp-ai-pro-tool-get-onedrive-file.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Onedrive_File();
		$result = $tool->execute(
			array(
				'access_token' => 'test-token',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );

		wp_delete_user( $admin_id );
	}

	/** OneDrive upload-file tool must be loadable. */
	public function test_chat_channels_tool_upload_onedrive_file_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Upload_Onedrive_File',
			'class-wp-mcp-ai-pro-tool-upload-onedrive-file.php'
		);
	}

	/** OneDrive upload-file slug must equal 'upload_onedrive_file'. */
	public function test_upload_onedrive_file_tool_get_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Upload_Onedrive_File',
			'class-wp-mcp-ai-pro-tool-upload-onedrive-file.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Upload_Onedrive_File();
		$this->assertSame( 'upload_onedrive_file', $tool->get_slug() );
	}

	/** OneDrive upload-file capability flags include 'write'. */
	public function test_upload_onedrive_file_tool_capability_flags() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Upload_Onedrive_File',
			'class-wp-mcp-ai-pro-tool-upload-onedrive-file.php'
		);

		$tool  = new WP_MCP_AI_Pro_Tool_Upload_Onedrive_File();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'write', $flags );
		$this->assertContains( 'pro', $flags );
	}

	// =========================================================================
	// iCloud Drive Tools
	// =========================================================================

	/** ICloud list-files tool must be loadable. */
	public function test_chat_channels_tool_list_icloud_drive_files_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_List_Icloud_Drive_Files',
			'class-wp-mcp-ai-pro-tool-list-icloud-drive-files.php'
		);
	}

	/** ICloud list-files slug must equal 'list_icloud_drive_files'. */
	public function test_list_icloud_drive_files_tool_get_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_List_Icloud_Drive_Files',
			'class-wp-mcp-ai-pro-tool-list-icloud-drive-files.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_List_Icloud_Drive_Files();
		$this->assertSame( 'list_icloud_drive_files', $tool->get_slug() );
	}

	/** ICloud list-files returns WP_Error for missing gateway URL. */
	public function test_list_icloud_drive_files_tool_returns_error_without_gateway_url() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_List_Icloud_Drive_Files',
			'class-wp-mcp-ai-pro-tool-list-icloud-drive-files.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_List_Icloud_Drive_Files();
		$result = $tool->execute(
			array(
				'session_token' => 'test-token',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );

		wp_delete_user( $admin_id );
	}

	/** ICloud list-files rejects non-HTTPS gateway URL. */
	public function test_list_icloud_drive_files_tool_rejects_http_url() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_List_Icloud_Drive_Files',
			'class-wp-mcp-ai-pro-tool-list-icloud-drive-files.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_List_Icloud_Drive_Files();
		$result = $tool->execute(
			array(
				'gateway_url'   => 'http://insecure.example.com/drive',
				'session_token' => 'test-token',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );

		wp_delete_user( $admin_id );
	}

	/** ICloud get-file tool must be loadable. */
	public function test_chat_channels_tool_get_icloud_drive_file_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Get_Icloud_Drive_File',
			'class-wp-mcp-ai-pro-tool-get-icloud-drive-file.php'
		);
	}

	/** ICloud get-file slug must equal 'get_icloud_drive_file'. */
	public function test_get_icloud_drive_file_tool_get_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Icloud_Drive_File',
			'class-wp-mcp-ai-pro-tool-get-icloud-drive-file.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Get_Icloud_Drive_File();
		$this->assertSame( 'get_icloud_drive_file', $tool->get_slug() );
	}

	/** ICloud get-file capability flags include 'read-only'. */
	public function test_get_icloud_drive_file_tool_capability_flags_include_read_only() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Get_Icloud_Drive_File',
			'class-wp-mcp-ai-pro-tool-get-icloud-drive-file.php'
		);

		$tool  = new WP_MCP_AI_Pro_Tool_Get_Icloud_Drive_File();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'pro', $flags );
	}

	/** ICloud upload-file tool must be loadable. */
	public function test_chat_channels_tool_upload_icloud_drive_file_loadable() {
		$this->assert_chat_channels_tool_loadable(
			'WP_MCP_AI_Pro_Tool_Upload_Icloud_Drive_File',
			'class-wp-mcp-ai-pro-tool-upload-icloud-drive-file.php'
		);
	}

	/** ICloud upload-file slug must equal 'upload_icloud_drive_file'. */
	public function test_upload_icloud_drive_file_tool_get_slug() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Upload_Icloud_Drive_File',
			'class-wp-mcp-ai-pro-tool-upload-icloud-drive-file.php'
		);

		$tool = new WP_MCP_AI_Pro_Tool_Upload_Icloud_Drive_File();
		$this->assertSame( 'upload_icloud_drive_file', $tool->get_slug() );
	}

	/** ICloud upload-file capability flags include 'write'. */
	public function test_upload_icloud_drive_file_tool_capability_flags() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Upload_Icloud_Drive_File',
			'class-wp-mcp-ai-pro-tool-upload-icloud-drive-file.php'
		);

		$tool  = new WP_MCP_AI_Pro_Tool_Upload_Icloud_Drive_File();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'write', $flags );
		$this->assertContains( 'pro', $flags );
	}

	/** ICloud upload-file returns WP_Error for missing gateway URL. */
	public function test_upload_icloud_drive_file_tool_returns_error_without_gateway_url() {
		$this->load_channel_tool(
			'WP_MCP_AI_Pro_Tool_Upload_Icloud_Drive_File',
			'class-wp-mcp-ai-pro-tool-upload-icloud-drive-file.php'
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Upload_Icloud_Drive_File();
		$result = $tool->execute(
			array(
				'session_token' => 'test-token',
				'file_name'     => 'test.txt',
				'file_content'  => 'Hello World',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );

		wp_delete_user( $admin_id );
	}

	// =========================================================================
	// Message deduplication
	// =========================================================================

	/**
	 * Deduplicate_messages() must remove duplicates based on message_id,
	 * preferring CCT entries over CPT entries.
	 */
	public function test_deduplicate_messages_prefers_cct() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'deduplicate_messages' );
		$method->setAccessible( true );

		$messages = array(
			array(
				'message_id' => 'msg_1',
				'content'    => 'CPT version',
				'_store'     => 'cpt',
				'timestamp'  => 100,
			),
			array(
				'message_id' => 'msg_1',
				'content'    => 'CCT version',
				'_store'     => 'cct',
				'timestamp'  => 100,
			),
			array(
				'message_id' => 'msg_2',
				'content'    => 'Unique CPT',
				'_store'     => 'cpt',
				'timestamp'  => 200,
			),
			array(
				'message_id' => 'msg_3',
				'content'    => 'Unique CCT',
				'_store'     => 'cct',
				'timestamp'  => 300,
			),
		);

		$result = $method->invoke( $controller, $messages );

		$this->assertCount( 3, $result, 'Duplicate msg_1 should be reduced to one entry' );
		// CCT version should win.
		$this->assertSame( 'CCT version', $result[0]['content'] );
		$this->assertSame( 'Unique CPT', $result[1]['content'] );
		$this->assertSame( 'Unique CCT', $result[2]['content'] );
	}

	/**
	 * Deduplicate_messages() keeps messages without a message_id.
	 */
	public function test_deduplicate_messages_keeps_empty_ids() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'deduplicate_messages' );
		$method->setAccessible( true );

		$messages = array(
			array(
				'message_id' => '',
				'content'    => 'No ID one',
				'_store'     => 'cpt',
				'timestamp'  => 100,
			),
			array(
				'message_id' => '',
				'content'    => 'No ID two',
				'_store'     => 'cct',
				'timestamp'  => 200,
			),
		);

		$result = $method->invoke( $controller, $messages );

		$this->assertCount( 2, $result, 'Messages without IDs should both be kept' );
	}

	/**
	 * Format_message correctly maps the message_timestamp field to timestamp.
	 */
	public function test_format_message_maps_timestamp_field() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'format_message' );
		$method->setAccessible( true );

		$row = array(
			'_ID'                => 10,
			'channel'            => 'telegram',
			'channel_contact_id' => '999',
			'message_timestamp'  => 1700000000,
		);

		$formatted = $method->invoke( $controller, $row, false );

		// The format_message method itself does not add _store; it is added by the caller.
		$this->assertArrayHasKey( 'timestamp', $formatted );
		$this->assertSame( 1700000000, $formatted['timestamp'] );
	}

	// =========================================================================
	// Resolve contact helpers – connection_id support
	// =========================================================================

	/**
	 * Resolve_contact_from_cpt populates connection_id when available.
	 */
	public function test_resolve_contact_from_cpt_returns_connection_id() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CPT', 'includes/class-wp-mcp-ai-channel-contacts-cpt.php' );

		WP_MCP_AI_Channel_Contacts_CPT::register_post_type();

		$post_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE,
				'post_title'  => 'Telegram Contact',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_channel', 'telegram' );
		update_post_meta( $post_id, '_channel_contact_id', '12345' );
		update_post_meta( $post_id, '_connection_id', 'tg_bot_abc' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_contact_from_cpt' );
		$method->setAccessible( true );

		$channel            = '';
		$channel_contact_id = '';
		$connection_id      = '';
		$method->invokeArgs( $controller, array( $post_id, &$channel, &$channel_contact_id, &$connection_id ) );

		$this->assertSame( 'telegram', $channel );
		$this->assertSame( '12345', $channel_contact_id );
		$this->assertSame( 'tg_bot_abc', $connection_id );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Resolve_contact_from_cpt returns empty connection_id when meta is absent.
	 */
	public function test_resolve_contact_from_cpt_returns_empty_connection_id_when_absent() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CPT', 'includes/class-wp-mcp-ai-channel-contacts-cpt.php' );

		WP_MCP_AI_Channel_Contacts_CPT::register_post_type();

		$post_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE,
				'post_title'  => 'Legacy Contact',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_channel', 'whatsapp' );
		update_post_meta( $post_id, '_channel_contact_id', '5551234567' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_contact_from_cpt' );
		$method->setAccessible( true );

		$channel            = '';
		$channel_contact_id = '';
		$connection_id      = '';
		$method->invokeArgs( $controller, array( $post_id, &$channel, &$channel_contact_id, &$connection_id ) );

		$this->assertSame( 'whatsapp', $channel );
		$this->assertSame( '5551234567', $channel_contact_id );
		$this->assertSame( '', $connection_id );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Resolve_contact_from_cpt works without the optional connection_id argument (backward compat).
	 */
	public function test_resolve_contact_from_cpt_backward_compat_without_connection_id_arg() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CPT', 'includes/class-wp-mcp-ai-channel-contacts-cpt.php' );

		WP_MCP_AI_Channel_Contacts_CPT::register_post_type();

		$post_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE,
				'post_title'  => 'Compat Contact',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_channel', 'whatsapp' );
		update_post_meta( $post_id, '_channel_contact_id', '99999' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_contact_from_cpt' );
		$method->setAccessible( true );

		$channel            = '';
		$channel_contact_id = '';
		// Call without the 4th connection_id argument.
		$method->invokeArgs( $controller, array( $post_id, &$channel, &$channel_contact_id ) );

		$this->assertSame( 'whatsapp', $channel );
		$this->assertSame( '99999', $channel_contact_id );

		wp_delete_post( $post_id, true );
	}

	// =========================================================================
	// CPT message scoping – Telegram vs non-Telegram
	// =========================================================================

	/**
	 * Fetch_messages_from_cpt for Telegram with connection_id uses inclusive filter.
	 *
	 * Messages matching the connection_id OR with empty/missing connection_id
	 * should all be returned.
	 */
	public function test_fetch_messages_from_cpt_telegram_inclusive_connection_id() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );
		$this->load_pro_class( 'WP_MCP_AI_Channel_Messages_CPT', 'includes/class-wp-mcp-ai-channel-messages-cpt.php' );

		WP_MCP_AI_Channel_Messages_CPT::register_post_type();

		$ts = time();

		// Message with matching connection_id.
		$p1 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Messages_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $p1, '_channel', 'telegram' );
		update_post_meta( $p1, '_channel_contact_id', '100' );
		update_post_meta( $p1, '_connection_id', 'bot_a' );
		update_post_meta( $p1, '_direction', 'inbound' );
		update_post_meta( $p1, '_message_timestamp', $ts );
		update_post_meta( $p1, '_message_id', 'tg_1' );

		// Message with empty connection_id (legacy).
		$p2 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Messages_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $p2, '_channel', 'telegram' );
		update_post_meta( $p2, '_channel_contact_id', '100' );
		update_post_meta( $p2, '_connection_id', '' );
		update_post_meta( $p2, '_direction', 'outbound' );
		update_post_meta( $p2, '_message_timestamp', $ts + 1 );
		update_post_meta( $p2, '_message_id', 'tg_2' );

		// Message with no connection_id meta at all (legacy).
		$p3 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Messages_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $p3, '_channel', 'telegram' );
		update_post_meta( $p3, '_channel_contact_id', '100' );
		// No _connection_id meta set.
		update_post_meta( $p3, '_direction', 'inbound' );
		update_post_meta( $p3, '_message_timestamp', $ts + 2 );
		update_post_meta( $p3, '_message_id', 'tg_3' );

		// Message with a DIFFERENT connection_id (should be excluded).
		$p4 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Messages_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $p4, '_channel', 'telegram' );
		update_post_meta( $p4, '_channel_contact_id', '100' );
		update_post_meta( $p4, '_connection_id', 'bot_b' );
		update_post_meta( $p4, '_direction', 'inbound' );
		update_post_meta( $p4, '_message_timestamp', $ts + 3 );
		update_post_meta( $p4, '_message_id', 'tg_4' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'fetch_messages_from_cpt' );
		$method->setAccessible( true );

		$items = $method->invoke( $controller, 'telegram', '100', false, 'bot_a' );

		$message_ids = array_map(
			function ( $i ) {
				return $i['message_id'];
			},
			$items
		);

		$this->assertContains( 'tg_1', $message_ids, 'Message with matching connection_id should be included' );
		$this->assertContains( 'tg_2', $message_ids, 'Legacy message with empty connection_id should be included' );
		$this->assertContains( 'tg_3', $message_ids, 'Legacy message with no connection_id meta should be included' );
		$this->assertNotContains( 'tg_4', $message_ids, 'Message from a different bot should be excluded' );

		wp_delete_post( $p1, true );
		wp_delete_post( $p2, true );
		wp_delete_post( $p3, true );
		wp_delete_post( $p4, true );
	}

	/**
	 * Fetch_messages_from_cpt for non-Telegram returns all messages regardless of connection_id.
	 */
	public function test_fetch_messages_from_cpt_whatsapp_no_connection_id_scoping() {
		$this->load_pro_class( 'WP_MCP_AI_Chat_Channels_REST_Controller', 'includes/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );
		$this->load_pro_class( 'WP_MCP_AI_Channel_Messages_CPT', 'includes/class-wp-mcp-ai-channel-messages-cpt.php' );

		WP_MCP_AI_Channel_Messages_CPT::register_post_type();

		$ts = time();

		// Message with connection_id on WhatsApp.
		$p1 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Messages_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $p1, '_channel', 'whatsapp' );
		update_post_meta( $p1, '_channel_contact_id', '5551234567' );
		update_post_meta( $p1, '_connection_id', 'wa_conn_1' );
		update_post_meta( $p1, '_direction', 'inbound' );
		update_post_meta( $p1, '_message_timestamp', $ts );
		update_post_meta( $p1, '_message_id', 'wa_1' );

		// Message with different connection_id on WhatsApp.
		$p2 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Channel_Messages_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $p2, '_channel', 'whatsapp' );
		update_post_meta( $p2, '_channel_contact_id', '5551234567' );
		update_post_meta( $p2, '_connection_id', 'wa_conn_2' );
		update_post_meta( $p2, '_direction', 'outbound' );
		update_post_meta( $p2, '_message_timestamp', $ts + 1 );
		update_post_meta( $p2, '_message_id', 'wa_2' );

		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'fetch_messages_from_cpt' );
		$method->setAccessible( true );

		// Empty connection_id = no scoping (the caller doesn't pass it for non-telegram).
		$items = $method->invoke( $controller, 'whatsapp', '5551234567', false, '' );

		$message_ids = array_map(
			function ( $i ) {
				return $i['message_id'];
			},
			$items
		);

		$this->assertContains( 'wa_1', $message_ids, 'All WhatsApp messages should be returned' );
		$this->assertContains( 'wa_2', $message_ids, 'All WhatsApp messages should be returned regardless of connection_id' );

		wp_delete_post( $p1, true );
		wp_delete_post( $p2, true );
	}

	// =========================================================================
	// CCT migration – connection_id column
	// =========================================================================

	/**
	 * The contacts CCT migration method exists and is callable.
	 */
	public function test_channel_contacts_cct_has_connection_id_migration() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Contacts_CCT', 'includes/class-wp-mcp-ai-channel-contacts-cct.php' );

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Channel_Contacts_CCT', 'maybe_migrate_connection_id' ),
			'Contacts CCT must have maybe_migrate_connection_id method'
		);
	}

	/**
	 * The messages CCT migration method exists and is callable.
	 */
	public function test_channel_messages_cct_has_connection_id_migration() {
		$this->load_pro_class( 'WP_MCP_AI_Channel_Messages_CCT', 'includes/class-wp-mcp-ai-channel-messages-cct.php' );

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Channel_Messages_CCT', 'maybe_migrate_connection_id' ),
			'Messages CCT must have maybe_migrate_connection_id method'
		);
	}
}
