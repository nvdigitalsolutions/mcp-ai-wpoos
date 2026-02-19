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
			'name'                => 'Test WhatsApp Connection With App ID',
			'url'                 => 'https://graph.facebook.com/v18.0',
			'connection_type'     => 'whatsapp',
			'auth_type'           => 'none',
			'enabled'             => true,
			'api_key'             => 'test_access_token',
			'api_secret'          => 'test_app_secret',
			'app_id'              => '123456789012345',
			'phone_number_id'     => '987654321012345',
			'verify_token'        => 'test_verify_token',
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
}
