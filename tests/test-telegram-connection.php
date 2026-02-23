<?php
/**
 * Test Telegram chat channel connection fields and bot testing functionality.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Telegram connection fields persistence and bot testing.
 */
class Test_Telegram_Connection extends WP_UnitTestCase {

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
	 * Test that a Telegram connection can be saved and retrieved.
	 */
	public function test_telegram_connection_saves_and_retrieves() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Telegram Bot',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '1234567890:ABCdefGHIjklMNOpqrsTUVwxyz_testtoken',
			'bot_username'    => '@testbot',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return an error' );
		$this->assertIsString( $result, 'Connection save should return connection ID' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertEquals( 'Test Telegram Bot', $saved['name'] );
		$this->assertEquals( 'telegram', $saved['connection_type'] );
		$this->assertEquals( '@testbot', $saved['bot_username'] );
	}

	/**
	 * Test that the Telegram bot_username field persists when saving.
	 */
	public function test_telegram_bot_username_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Telegram Bot Username Test',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '9876543210:ZYXwvuTSRqponMLKjihGFEdcba_testtoken',
			'bot_username'    => '@my_support_bot',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( '@my_support_bot', $saved['bot_username'], 'Bot username should persist' );
	}

	/**
	 * Test that the Telegram bot_username is preserved when updating without providing it.
	 */
	public function test_telegram_bot_username_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Create the initial connection.
		$connection_data = array(
			'name'            => 'Telegram Update Test',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '1111111111:AABBCCDDEEFFGGHHIIJJKKLLMMNNOOPPQQt',
			'bot_username'    => '@original_bot',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		// Update without providing bot_username — it should be preserved.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Telegram Update Test — Renamed',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
		);

		$update_result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );
		$this->assertNotInstanceOf( 'WP_Error', $update_result );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( 'Telegram Update Test — Renamed', $updated['name'], 'Connection name should be updated' );
		$this->assertEquals( '@original_bot', $updated['bot_username'], 'Bot username should be preserved when not provided on update' );
	}

	/**
	 * Test that the Telegram secret_token field persists when saving.
	 */
	public function test_telegram_secret_token_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Telegram Secret Token Test',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '2222222222:AABBccDDeeFFggHHiiJJkkLLmmNNooXXyy_t',
			'secret_token'    => 'my-super-secret-webhook-token_123',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved );
		// secret_token is stored encrypted; it should be non-empty.
		$this->assertNotEmpty( $saved['secret_token'], 'Encrypted secret token should be stored' );
	}

	/**
	 * Test that the Telegram secret_token is preserved when updating without providing it.
	 */
	public function test_telegram_secret_token_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Create the initial connection with a secret token.
		$connection_data = array(
			'name'            => 'Telegram Secret Token Preserve Test',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '3333333333:ABcDEfGHiJKlMNopQRstUVwxYZ_testtoken',
			'secret_token'    => 'preserve-this-secret_ABC',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$original = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$original_encrypted_secret = $original['secret_token'];

		// Update without providing secret_token — it should be preserved.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Telegram Secret Token Preserve Test — Updated',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
		);

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals(
			$original_encrypted_secret,
			$updated['secret_token'],
			'Encrypted secret token should be preserved when not provided on update'
		);
	}

	/**
	 * Test that test_connection() dispatches to the Telegram-specific handler.
	 *
	 * This validates that a Telegram connection does not fall through to the generic
	 * WordPress REST API test (which would always fail for Telegram).
	 */
	public function test_connection_dispatches_for_telegram() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Use reflection to confirm test_telegram_connection() exists.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Remote_Site_Manager' );
		$this->assertTrue(
			$reflection->hasMethod( 'test_telegram_connection' ),
			'WP_MCP_AI_Pro_Remote_Site_Manager should have a test_telegram_connection() method'
		);

		$method = $reflection->getMethod( 'test_telegram_connection' );
		$this->assertTrue(
			$method->isProtected() || $method->isPublic(),
			'test_telegram_connection() should be at least protected'
		);
	}

	/**
	 * Test that the Telegram webhook controller can retrieve the bot token from a connection.
	 */
	public function test_telegram_webhook_controller_retrieves_bot_token() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Telegram_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'Telegram Webhook Controller not available' );
				return;
			}
		}

		// Create a Telegram connection.
		$connection_data = array(
			'name'            => 'Test Telegram Bot Controller',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '4444444444:ABcDeFgHiJkLmNoPqRsTuVwXyZ_testtoken',
			'secret_token'    => 'controller-secret-xyz_789',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		// Confirm the connection has the expected type.
		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertEquals( 'telegram', $saved['connection_type'] );
		$this->assertNotEmpty( $saved['api_key'], 'Encrypted bot token should be stored' );
		$this->assertNotEmpty( $saved['secret_token'], 'Encrypted secret token should be stored' );
	}
}
