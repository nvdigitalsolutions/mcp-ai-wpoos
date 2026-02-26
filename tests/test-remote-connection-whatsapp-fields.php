<?php
/**
 * Test WhatsApp and other platform-specific fields persistence in Remote Site Manager.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Remote Site Manager platform-specific fields persistence.
 */
class Test_Remote_Connection_WhatsApp_Fields extends WP_UnitTestCase {

	/**
	 * Clean up connections before and after each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Clean up connections after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		parent::tearDown();
	}

	/**
	 * Test that WhatsApp app_id persists when saving connection.
	 */
	public function test_whatsapp_app_id_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp Connection With App ID',
			'url'             => 'https://graph.facebook.com/v18.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token',
			'api_secret'      => 'test_app_secret',
			'app_id'          => '123456789012345',
			'phone_number_id' => '987654321012345',
			'verify_token'    => 'test_verify_token',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return error' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved_connection, 'Saved connection should be retrievable' );
		$this->assertEquals( '123456789012345', $saved_connection['app_id'], 'App ID should persist' );
	}

	/**
	 * Test that WhatsApp app_id persists when saving connection.
	 */
	public function test_whatsapp_phone_number_id_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'                => 'Test WhatsApp Connection',
			'url'                 => 'https://graph.facebook.com/v18.0',
			'connection_type'     => 'whatsapp',
			'auth_type'           => 'none',
			'enabled'             => true,
			'api_key'             => 'test_access_token',
			'api_secret'          => 'test_app_secret',
			'phone_number_id'     => '123456789012345',
			'business_account_id' => '987654321098765',
			'verify_token'        => 'test_verify_token_12345',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return error' );
		$this->assertIsString( $result, 'Connection save should return connection ID' );

		// Retrieve the connection.
		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved_connection, 'Saved connection should be retrievable' );
		$this->assertEquals( 'Test WhatsApp Connection', $saved_connection['name'], 'Connection name should match' );
		$this->assertEquals( 'whatsapp', $saved_connection['connection_type'], 'Connection type should match' );
		$this->assertEquals( '123456789012345', $saved_connection['phone_number_id'], 'Phone number ID should persist' );
		$this->assertEquals( '987654321098765', $saved_connection['business_account_id'], 'Business account ID should persist' );
		$this->assertEquals( 'test_verify_token_12345', $saved_connection['verify_token'], 'Verify token should persist' );
	}

	/**
	 * Test that WhatsApp phone_number_id is preserved during update when not provided.
	 */
	public function test_whatsapp_phone_number_id_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Create initial connection with WhatsApp fields.
		$connection_data = array(
			'name'                => 'Test WhatsApp Connection',
			'url'                 => 'https://graph.facebook.com/v18.0',
			'connection_type'     => 'whatsapp',
			'auth_type'           => 'none',
			'enabled'             => true,
			'api_key'             => 'test_access_token',
			'api_secret'          => 'test_app_secret',
			'phone_number_id'     => '123456789012345',
			'business_account_id' => '987654321098765',
			'verify_token'        => 'test_verify_token_12345',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Initial save should succeed' );

		// Update connection WITHOUT providing WhatsApp fields (simulating form submission where these fields are empty).
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Updated WhatsApp Connection',
			'url'             => 'https://graph.facebook.com/v18.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			// Note: phone_number_id, business_account_id, verify_token are intentionally omitted.
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Update should succeed' );

		// Retrieve the updated connection.
		$updated_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$this->assertNotNull( $updated_connection, 'Updated connection should be retrievable' );
		$this->assertEquals( 'Updated WhatsApp Connection', $updated_connection['name'], 'Connection name should be updated' );
		// These fields should be preserved from the original connection.
		$this->assertEquals( '123456789012345', $updated_connection['phone_number_id'], 'Phone number ID should be preserved' );
		$this->assertEquals( '987654321098765', $updated_connection['business_account_id'], 'Business account ID should be preserved' );
		$this->assertEquals( 'test_verify_token_12345', $updated_connection['verify_token'], 'Verify token should be preserved' );
	}

	/**
	 * Test that WhatsApp display_phone_number persists when saving connection.
	 */
	public function test_whatsapp_display_phone_number_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'                 => 'Test WhatsApp Display Phone Connection',
			'url'                  => 'https://graph.facebook.com/v18.0',
			'connection_type'      => 'whatsapp',
			'auth_type'            => 'none',
			'enabled'              => true,
			'api_key'              => 'test_access_token',
			'api_secret'           => 'test_app_secret',
			'phone_number_id'      => '123456789012345',
			'display_phone_number' => '+1 555 000 1234',
			'business_account_id'  => '987654321098765',
			'verify_token'         => 'test_verify_token_12345',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return error' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved_connection, 'Saved connection should be retrievable' );
		$this->assertEquals( '+1 555 000 1234', $saved_connection['display_phone_number'], 'Display phone number should persist' );
	}

	/**
	 * Test that WhatsApp display_phone_number is preserved during update when not provided.
	 */
	public function test_whatsapp_display_phone_number_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Create initial connection with display_phone_number set.
		$connection_data = array(
			'name'                 => 'Test WhatsApp Display Phone Connection',
			'url'                  => 'https://graph.facebook.com/v18.0',
			'connection_type'      => 'whatsapp',
			'auth_type'            => 'none',
			'enabled'              => true,
			'api_key'              => 'test_access_token',
			'api_secret'           => 'test_app_secret',
			'phone_number_id'      => '123456789012345',
			'display_phone_number' => '+1 555 000 1234',
			'business_account_id'  => '987654321098765',
			'verify_token'         => 'test_verify_token_12345',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Initial save should succeed' );

		// Update connection WITHOUT providing display_phone_number (simulating form submission where the field is empty).
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Updated WhatsApp Display Phone Connection',
			'url'             => 'https://graph.facebook.com/v18.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			// Note: display_phone_number is intentionally omitted.
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Update should succeed' );

		// Retrieve the updated connection.
		$updated_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$this->assertNotNull( $updated_connection, 'Updated connection should be retrievable' );
		$this->assertEquals( 'Updated WhatsApp Display Phone Connection', $updated_connection['name'], 'Connection name should be updated' );
		// display_phone_number should be preserved from the original connection.
		$this->assertEquals( '+1 555 000 1234', $updated_connection['display_phone_number'], 'Display phone number should be preserved on update' );
	}

	/**
	 * Test that WhatsApp channel_url persists when saving a connection.
	 */
	public function test_whatsapp_channel_url_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'             => 'Test WhatsApp Channel URL Connection',
			'url'              => 'https://graph.facebook.com/v22.0',
			'connection_type'  => 'whatsapp',
			'auth_type'        => 'none',
			'enabled'          => true,
			'api_key'          => 'test_access_token',
			'phone_number_id'  => '123456789012345',
			'channel_url'      => 'https://chat.whatsapp.com/TestInviteCode12345',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return an error' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved_connection, 'Saved connection should be retrievable' );
		$this->assertEquals( 'https://chat.whatsapp.com/TestInviteCode12345', $saved_connection['channel_url'], 'Channel URL should persist' );
	}

	/**
	 * Test that WhatsApp channel_url is preserved on update when not provided.
	 */
	public function test_whatsapp_channel_url_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Create initial connection with channel_url set.
		$connection_data = array(
			'name'            => 'Test WhatsApp Channel URL Preserve',
			'url'             => 'https://graph.facebook.com/v22.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token',
			'phone_number_id' => '123456789012345',
			'channel_url'     => 'https://chat.whatsapp.com/OriginalInviteCode',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Initial save should succeed' );

		// Update WITHOUT providing channel_url.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Updated WhatsApp Channel URL Preserve',
			'url'             => 'https://graph.facebook.com/v22.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			// channel_url intentionally omitted.
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Update should succeed' );

		$updated_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$this->assertNotNull( $updated_connection, 'Updated connection should be retrievable' );
		$this->assertEquals( 'Updated WhatsApp Channel URL Preserve', $updated_connection['name'], 'Connection name should be updated' );
		$this->assertEquals( 'https://chat.whatsapp.com/OriginalInviteCode', $updated_connection['channel_url'], 'Channel URL should be preserved on update' );
	}

	/**
	 * Test that WhatsApp group_id persists when saving a connection.
	 */
	public function test_whatsapp_group_id_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp Group ID Connection',
			'url'             => 'https://graph.facebook.com/v22.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token',
			'phone_number_id' => '123456789012345',
			'group_id'        => '120363111222333444@g.us',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return an error' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved_connection, 'Saved connection should be retrievable' );
		$this->assertEquals( '120363111222333444@g.us', $saved_connection['group_id'], 'Group ID should persist' );
	}

	/**
	 * Test that WhatsApp group_id is preserved on update when not provided.
	 */
	public function test_whatsapp_group_id_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Create initial connection with group_id set.
		$connection_data = array(
			'name'            => 'Test WhatsApp Group ID Preserve',
			'url'             => 'https://graph.facebook.com/v22.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token',
			'phone_number_id' => '123456789012345',
			'group_id'        => '120363111222333444@g.us',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Initial save should succeed' );

		// Update WITHOUT providing group_id.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Updated WhatsApp Group ID Preserve',
			'url'             => 'https://graph.facebook.com/v22.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			// group_id intentionally omitted.
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Update should succeed' );

		$updated_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$this->assertNotNull( $updated_connection, 'Updated connection should be retrievable' );
		$this->assertEquals( 'Updated WhatsApp Group ID Preserve', $updated_connection['name'], 'Connection name should be updated' );
		$this->assertEquals( '120363111222333444@g.us', $updated_connection['group_id'], 'Group ID should be preserved on update' );
	}

	/**
	 * Test that Telegram bot_username persists.
	 */
	public function test_telegram_bot_username_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Telegram Connection',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_bot_token',
			'bot_username'    => '@test_bot',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( '@test_bot', $saved_connection['bot_username'], 'Bot username should persist' );
	}

	/**
	 * Test that Slack workspace_id persists.
	 */
	public function test_slack_workspace_id_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Slack Connection',
			'url'             => 'https://slack.com/api',
			'connection_type' => 'slack',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'xoxb-test-token',
			'api_secret'      => 'test_signing_secret',
			'workspace_id'    => 'T01234ABCDE',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( 'T01234ABCDE', $saved_connection['workspace_id'], 'Workspace ID should persist' );
	}

	/**
	 * Test that Discord application_id and guild_id persist.
	 */
	public function test_discord_fields_persist() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Discord Connection',
			'url'             => 'https://discord.com/api/v10',
			'connection_type' => 'discord',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_bot_token',
			'application_id'  => '123456789012345678',
			'guild_id'        => '987654321098765432',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( '123456789012345678', $saved_connection['application_id'], 'Application ID should persist' );
		$this->assertEquals( '987654321098765432', $saved_connection['guild_id'], 'Guild ID should persist' );
	}

	/**
	 * Test that Google Drive folder_id persists.
	 */
	public function test_google_drive_folder_id_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Google Drive Connection',
			'url'             => 'https://www.googleapis.com/drive/v3',
			'connection_type' => 'google_drive',
			'auth_type'       => 'none',
			'enabled'         => true,
			'client_id'       => 'test_client_id',
			'client_secret'   => 'test_client_secret',
			'folder_id'       => '1a2b3c4d5e6f7g8h9i0j',
			'user_email'      => 'test@example.com',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( '1a2b3c4d5e6f7g8h9i0j', $saved_connection['folder_id'], 'Folder ID should persist' );
	}

	/**
	 * Test that a missing app secret no longer blocks the connection test.
	 *
	 * Previously, test_whatsapp_connection returned a WP_Error when api_secret was
	 * absent. The app secret is only required for webhook signature validation, so the
	 * connection test should succeed and include a warning instead.
	 */
	public function test_whatsapp_connection_succeeds_without_app_secret() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Save a WhatsApp connection without an app secret.
		$connection_data = array(
			'name'            => 'Test WhatsApp No Secret',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_no_secret',
			// api_secret intentionally omitted.
			'phone_number_id' => '111222333444555',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		// Stub the WhatsApp API call so no real HTTP request is made.
		$filter_callback = function ( $preempt, $parsed_args, $url ) {
			if ( false !== strpos( $url, 'graph.facebook.com' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'display_phone_number' => '+1 555-000-0000',
							'verified_name'        => 'Test Business',
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $saved_connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// The test must NOT return a WP_Error just because api_secret is absent.
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection test should not fail when app secret is missing' );
		$this->assertTrue( isset( $result['success'] ) && $result['success'], 'Connection test should report success' );

		// A warning about the missing app secret should be included.
		$this->assertArrayHasKey( 'warning', $result, 'Result should contain a warning about the missing app secret' );
		$this->assertStringContainsString( 'secret', strtolower( $result['warning'] ), 'Warning should mention app secret' );
	}

	/**
	 * Test that the phone-number endpoint does NOT include quality_rating in its fields.
	 *
	 * quality_rating requires whatsapp_business_management permission which App Access
	 * Tokens lack.  It must be fetched as a separate optional request so that a 403
	 * response from that field does not fail the whole connection test.
	 */
	public function test_whatsapp_connection_phone_endpoint_excludes_quality_rating() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp Quality Rating',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_quality',
			'api_secret'      => 'test_app_secret_quality',
			'phone_number_id' => '999888777666555',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$captured_urls = array();

		// Stub all Graph API requests; return 200 for the phone-number lookup and
		// 403 for the quality_rating and business-profile endpoints.
		$filter_callback = function ( $preempt, $parsed_args, $url ) use ( &$captured_urls ) {
			if ( false !== strpos( $url, 'graph.facebook.com' ) ) {
				$captured_urls[] = $url;

				// Simulate 403 when quality_rating is the only requested field.
				if ( false !== strpos( $url, 'fields=quality_rating' ) ) {
					return array(
						'headers'  => array( 'content-type' => 'application/json' ),
						'body'     => wp_json_encode(
							array(
								'error' => array(
									'message' => '(#200) You do not have permission to access this field.',
									'type'    => 'OAuthException',
									'code'    => 200,
								),
							)
						),
						'response' => array(
							'code'    => 403,
							'message' => 'Forbidden',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				// Return success for all other requests.
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'display_phone_number' => '+1 555-000-1111',
							'verified_name'        => 'Quality Test Business',
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $saved_connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// The overall test should succeed even though the quality_rating request returned 403.
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection test must not fail due to a 403 on quality_rating' );
		$this->assertTrue( isset( $result['success'] ) && $result['success'], 'Connection test should succeed' );

		// Verify the primary phone-number URL does not contain quality_rating.
		// The primary request contains display_phone_number but NOT quality_rating or whatsapp_business_profile.
		$phone_url            = '';
		$phone_number_in_path = rawurlencode( $connection_data['phone_number_id'] );
		foreach ( $captured_urls as $url ) {
			$has_phone_id         = false !== strpos( $url, $phone_number_in_path );
			$has_display_phone    = false !== strpos( $url, 'display_phone_number' );
			$has_quality_rating   = false !== strpos( $url, 'quality_rating' );
			$has_business_profile = false !== strpos( $url, 'whatsapp_business_profile' );

			if ( $has_phone_id && $has_display_phone && ! $has_quality_rating && ! $has_business_profile ) {
				$phone_url = $url;
				break;
			}
		}

		$this->assertNotEmpty( $phone_url, 'Could not identify the primary phone-number endpoint in captured URLs' );
		$this->assertStringNotContainsString( 'quality_rating', $phone_url, 'Primary phone-number endpoint must not request quality_rating' );

		// quality_rating should remain 'unknown' when the optional request returns 403.
		$this->assertEquals( 'unknown', $result['quality_rating'], 'quality_rating should be unknown when the optional request fails' );
	}

	/**
	 * Test that a 403 with Facebook error code 200 on the primary phone-number request
	 * triggers a fallback to the base endpoint and still reports success.
	 *
	 * Some access tokens have the whatsapp_business_messaging scope for sending
	 * but lack the field-level permission to read display_phone_number or verified_name.
	 * The connection test must not fail in this case; it should succeed with a warning.
	 */
	public function test_whatsapp_connection_succeeds_when_primary_fields_request_returns_403() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp Limited Permissions',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_limited',
			'api_secret'      => 'test_app_secret',
			'phone_number_id' => '444555666777888',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$filter_callback = function ( $preempt, $parsed_args, $url ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			// The fields=display_phone_number,verified_name request returns 403 with FB error code 200.
			if ( false !== strpos( $url, 'fields=display_phone_number' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'message' => '(#200) You do not have permission to access this field.',
								'type'    => 'OAuthException',
								'code'    => 200,
							),
						)
					),
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			// The base endpoint (no fields) succeeds and returns just the ID.
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'id' => '444555666777888' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $saved_connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// The test must succeed despite the 403 on the fields request.
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection test must not fail when fields request returns 403 with FB error code 200' );
		$this->assertTrue( isset( $result['success'] ) && $result['success'], 'Connection test should report success' );

		// A warning about limited field access should be included.
		$this->assertArrayHasKey( 'warning', $result, 'Result should include a warning about limited field access' );
		$this->assertStringContainsString( 'permission', strtolower( $result['warning'] ), 'Warning should mention permission' );
	}

	/**
	 * Test that a 403 with FB code 200 on BOTH primary and fallback endpoints still passes.
	 *
	 * Some access tokens return 403 + FB code 200 on the base phone number node as well as on
	 * the specific fields request. The token is still valid (we receive a proper Facebook
	 * permission error, not an authentication failure), so the connection test must succeed.
	 */
	public function test_whatsapp_connection_succeeds_when_both_endpoints_return_403_with_field_permission_error() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp Ultra Limited Permissions',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_ultra_limited',
			'api_secret'      => 'test_app_secret',
			'phone_number_id' => '555666777888999',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$filter_callback = function ( $preempt, $parsed_args, $url ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			// Both the fields endpoint and the base endpoint return 403 with FB error code 200.
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'message' => '(#200) You do not have permission to access this field.',
							'type'    => 'OAuthException',
							'code'    => 200,
						),
					)
				),
				'response' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $saved_connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// The test must succeed even when both endpoints return 403 with FB code 200.
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection test must not fail when both endpoints return 403 with FB error code 200' );
		$this->assertTrue( isset( $result['success'] ) && $result['success'], 'Connection test should report success' );

		// A warning about limited field access should be included.
		$this->assertArrayHasKey( 'warning', $result, 'Result should include a warning about limited field access' );
		$this->assertStringContainsString( 'permission', strtolower( $result['warning'] ), 'Warning should mention permission' );
	}

	/**
	 * Test that a 403 with a non-200 Facebook error code (e.g. invalid token) still fails.
	 *
	 * Error code 190 means the token is invalid/expired and must not trigger the fallback.
	 */
	public function test_whatsapp_connection_fails_on_invalid_token_403() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp Invalid Token',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'invalid_token',
			'api_secret'      => 'test_app_secret',
			'phone_number_id' => '111222333444555',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$filter_callback = function ( $preempt, $parsed_args, $url ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}
			// Simulate an invalid/expired token error (FB error code 190).
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'message' => 'Invalid OAuth access token.',
							'type'    => 'OAuthException',
							'code'    => 190,
						),
					)
				),
				'response' => array(
					'code'    => 401,
					'message' => 'Unauthorized',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $saved_connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// An invalid token must still return an error.
		$this->assertInstanceOf( 'WP_Error', $result, 'Connection test should fail for an invalid token' );
	}

	/**
	 * Test that cache_ttl and test_endpoint persist.
	 */
	public function test_cache_ttl_and_test_endpoint_persist() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Generic API Connection',
			'url'             => 'https://api.example.com',
			'connection_type' => 'generic',
			'auth_type'       => 'none',
			'enabled'         => true,
			'cache_ttl'       => 600,
			'test_endpoint'   => '/api/health',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( 600, $saved_connection['cache_ttl'], 'Cache TTL should persist' );
		$this->assertEquals( '/api/health', $saved_connection['test_endpoint'], 'Test endpoint should persist' );
	}

	/**
	 * Test that graph_api_version persists for WhatsApp connections.
	 */
	public function test_whatsapp_graph_api_version_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'              => 'Test WhatsApp v21 Connection',
			'url'               => 'https://graph.facebook.com/v21.0',
			'connection_type'   => 'whatsapp',
			'auth_type'         => 'none',
			'enabled'           => true,
			'api_key'           => 'test_access_token',
			'phone_number_id'   => '123456789',
			'graph_api_version' => 'v21.0',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertEquals( 'v21.0', $saved['graph_api_version'], 'Graph API version should persist' );
	}

	/**
	 * Test that graph_api_version persists for Facebook Messenger connections.
	 */
	public function test_messenger_graph_api_version_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'              => 'Test Messenger v22 Connection',
			'url'               => 'https://graph.facebook.com/v22.0',
			'connection_type'   => 'facebook_messenger',
			'auth_type'         => 'none',
			'enabled'           => true,
			'api_key'           => 'test_page_access_token',
			'api_secret'        => 'test_app_secret',
			'app_id'            => '987654321',
			'page_id'           => '112233445566',
			'verify_token'      => 'test_verify_token',
			'graph_api_version' => 'v22.0',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertEquals( 'v22.0', $saved['graph_api_version'], 'Graph API version should persist for Messenger' );
		$this->assertEquals( '987654321', $saved['app_id'], 'App ID should persist for Messenger' );
		$this->assertEquals( '112233445566', $saved['page_id'], 'Page ID should persist for Messenger' );
	}

	/**
	 * Test that graph_api_version is preserved on update when not provided.
	 */
	public function test_graph_api_version_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'              => 'Test Messenger Update',
			'url'               => 'https://graph.facebook.com/v21.0',
			'connection_type'   => 'facebook_messenger',
			'auth_type'         => 'none',
			'enabled'           => true,
			'api_key'           => 'test_token',
			'graph_api_version' => 'v21.0',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Initial save should succeed' );

		// Update without providing graph_api_version.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Updated Messenger Connection',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'facebook_messenger',
			'auth_type'       => 'none',
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Update should succeed' );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( 'v21.0', $updated['graph_api_version'], 'Graph API version should be preserved on update' );
	}

	/**
	 * Test that assigned_assistant_ids persist for WhatsApp connections.
	 */
	public function test_whatsapp_assigned_assistant_ids_persist() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'                   => 'Test WhatsApp With Assistants',
			'url'                    => 'https://graph.facebook.com/v21.0',
			'connection_type'        => 'whatsapp',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => 'test_access_token',
			'phone_number_id'        => '123456789012345',
			'verify_token'           => 'test_verify_token',
			'assigned_assistant_ids' => array( 10, 20, 30 ),
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertIsArray( $saved['assigned_assistant_ids'], 'assigned_assistant_ids should be an array' );
		$this->assertEquals( array( 10, 20, 30 ), $saved['assigned_assistant_ids'], 'Assigned assistant IDs should persist' );
	}

	/**
	 * Test that assigned_assistant_ids are preserved on update when not provided.
	 */
	public function test_whatsapp_assigned_assistant_ids_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Create initial connection with assigned assistants.
		$connection_data = array(
			'name'                   => 'Test WhatsApp Preserve Assistants',
			'url'                    => 'https://graph.facebook.com/v21.0',
			'connection_type'        => 'whatsapp',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => 'test_access_token',
			'phone_number_id'        => '111222333444555',
			'verify_token'           => 'test_verify_token',
			'assigned_assistant_ids' => array( 5, 15 ),
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Initial save should succeed' );

		// Update without providing assigned_assistant_ids.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Updated WhatsApp Preserve Assistants',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Update should succeed' );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $updated, 'Updated connection should be retrievable' );
		$this->assertEquals( array( 5, 15 ), $updated['assigned_assistant_ids'], 'Assigned assistant IDs should be preserved on update' );
	}

	/**
	 * Test that system_user_id persists for WhatsApp connections.
	 */
	public function test_whatsapp_system_user_id_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp System User',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_system_user_access_token',
			'api_secret'      => 'test_app_secret',
			'app_id'          => '894182303344052',
			'system_user_id'  => '123456789012345',
			'phone_number_id' => '987654321098765',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should not return error' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertEquals( '123456789012345', $saved['system_user_id'], 'System User ID should persist' );
	}

	/**
	 * Test that system_user_id is preserved on update when not provided.
	 */
	public function test_whatsapp_system_user_id_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp System User Update',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token',
			'system_user_id'  => '999888777666555',
			'phone_number_id' => '111222333444555',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Initial save should succeed' );

		// Update without providing system_user_id.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Updated WhatsApp Connection',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'phone_number_id' => '111222333444555',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Update should succeed' );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( '999888777666555', $updated['system_user_id'], 'System User ID should be preserved on update' );
	}

	/**
	 * Test that system_user_id defaults to empty string when not set.
	 */
	public function test_whatsapp_system_user_id_defaults_to_empty() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp No System User',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token',
			'phone_number_id' => '555444333222111',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertArrayHasKey( 'system_user_id', $saved, 'system_user_id key should exist' );
		$this->assertEquals( '', $saved['system_user_id'], 'system_user_id should be empty string when not provided' );
	}

	/**
	 * Test that a 400 "Invalid appsecret_proof" response causes a retry without appsecret_proof.
	 *
	 * When the stored app secret is incorrect the Meta API returns HTTP 400 with an
	 * "Invalid appsecret_proof" error message.  The connection test must detect this,
	 * clear the proof, retry without it, and succeed if the access token itself is valid.
	 */
	public function test_whatsapp_connection_retries_without_appsecret_proof_on_400() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp Bad App Secret',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_valid_access_token',
			'api_secret'      => 'wrong_app_secret',
			'phone_number_id' => '123456789000001',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$filter_callback = function ( $preempt, $parsed_args, $url ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			// The first request (with appsecret_proof) returns HTTP 400.
			if ( false !== strpos( $url, 'appsecret_proof' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'message'    => 'Invalid appsecret_proof provided in the API argument',
								'type'       => 'OAuthException',
								'code'       => 1,
								'fbtrace_id' => 'abc123',
							),
						)
					),
					'response' => array(
						'code'    => 400,
						'message' => 'Bad Request',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			// The retry without appsecret_proof succeeds.
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'display_phone_number' => '+1 555-000-9999',
						'verified_name'        => 'Test Business Retry',
						'id'                   => '123456789000001',
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $saved_connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// The test must succeed after retrying without appsecret_proof.
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection test must not fail when retrying without appsecret_proof' );
		$this->assertTrue( isset( $result['success'] ) && $result['success'], 'Connection test should report success after retry' );
		$this->assertEquals( '+1 555-000-9999', $result['phone_number'], 'Phone number from retry response should be returned' );
	}

	/**
	 * Test that a 400 "Invalid appsecret_proof" with no app secret configured returns a helpful WP_Error.
	 *
	 * When the Meta app has "Require App Secret Proof" enabled and no app secret is stored,
	 * appsecret_proof is never sent.  Meta still returns HTTP 400 with the same error message.
	 * The connection test must detect this and return a clear WP_Error guiding the user to
	 * enter their App Secret instead of surfacing the raw Meta error.
	 */
	public function test_whatsapp_connection_returns_helpful_error_when_appsecret_required_but_not_configured() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Connection with NO app secret (api_secret is empty).
		$connection_data = array(
			'name'            => 'Test WhatsApp No App Secret',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_valid_access_token_no_secret',
			'api_secret'      => '',
			'phone_number_id' => '123456789000002',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		// Meta always returns 400 "Invalid appsecret_proof" (app requires it but none was sent).
		$filter_callback = function ( $preempt, $parsed_args, $url ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'message'    => 'Invalid appsecret_proof provided in the API argument',
							'type'       => 'OAuthException',
							'code'       => 1,
							'fbtrace_id' => 'def456',
						),
					)
				),
				'response' => array(
					'code'    => 400,
					'message' => 'Bad Request',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $saved_connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Must return a WP_Error with a helpful message pointing to the App Secret field.
		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error when appsecret_proof is required but not configured' );
		$this->assertStringContainsString( 'App Secret', $result->get_error_message(), 'Error message should mention App Secret' );
	}

	/**
	 * Test that test_connection() succeeds when the fields request returns HTTP 400 with
	 * Facebook error code 100 ("Tried accessing nonexisting field (display_phone_number)").
	 *
	 * Some System User tokens cannot access display_phone_number/verified_name as explicit
	 * ?fields= parameters and Meta returns 400 + #100. The handler must fall back to the
	 * base endpoint and report success with a warning, not surface the raw API error.
	 */
	public function test_whatsapp_connection_succeeds_when_primary_fields_request_returns_400_code_100() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp 400 Code 100',
			'url'             => 'https://graph.facebook.com/v22.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_400_code_100',
			'api_secret'      => '',
			'phone_number_id' => '777888999000111',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$filter_callback = function ( $preempt, $parsed_args, $url ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			// The fields=display_phone_number,verified_name request returns 400 with FB error code 100.
			if ( false !== strpos( $url, 'fields=display_phone_number' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'message'    => '(#100) Tried accessing nonexisting field (display_phone_number)',
								'type'       => 'OAuthException',
								'code'       => 100,
								'fbtrace_id' => 'abc100',
							),
						)
					),
					'response' => array(
						'code'    => 400,
						'message' => 'Bad Request',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			// The base endpoint (no fields) succeeds and returns just the ID.
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'id' => '777888999000111' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $saved_connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// The test must succeed despite the 400 + code 100 on the fields request.
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection test must not fail when fields request returns 400 with FB error code 100; got: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		$this->assertTrue( isset( $result['success'] ) && $result['success'], 'Connection test should report success' );

		// A warning about limited field access should be included.
		$this->assertArrayHasKey( 'warning', $result, 'Result should include a warning about limited field access' );
		$this->assertStringContainsString( 'permission', strtolower( $result['warning'] ), 'Warning should mention permission' );
	}

	/**
	 * Test that test_connection() succeeds when BOTH the fields request and the fallback
	 * base endpoint return HTTP 400 with Facebook error code 100.
	 *
	 * The connection must still report success with limited field access in this case.
	 */
	public function test_whatsapp_connection_succeeds_when_both_endpoints_return_400_code_100() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test WhatsApp Both 400 Code 100',
			'url'             => 'https://graph.facebook.com/v22.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_both_400_code_100',
			'api_secret'      => '',
			'phone_number_id' => '888999000111222',
			'verify_token'    => 'test_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		// Both endpoints return 400 with FB error code 100.
		$filter_callback = function ( $preempt, $parsed_args, $url ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'message'    => '(#100) Tried accessing nonexisting field (display_phone_number)',
							'type'       => 'OAuthException',
							'code'       => 100,
							'fbtrace_id' => 'abc100both',
						),
					)
				),
				'response' => array(
					'code'    => 400,
					'message' => 'Bad Request',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $saved_connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// The test must succeed even when both endpoints return 400 + code 100.
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection test must not fail when both endpoints return 400 with FB error code 100' );
		$this->assertTrue( isset( $result['success'] ) && $result['success'], 'Connection test should report success' );

		// A warning about limited field access should be included.
		$this->assertArrayHasKey( 'warning', $result, 'Result should include a warning about limited field access' );
	}
}
