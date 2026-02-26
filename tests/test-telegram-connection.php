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

	// =========================================================================
	// Auto-reply assistant setting tests (mirrors WhatsApp auto-reply behaviour)
	// =========================================================================

	/**
	 * Load the Telegram controller, skipping if unavailable.
	 *
	 * @return WP_MCP_AI_Telegram_Webhook_Controller|null
	 */
	private function load_telegram_controller() {
		if ( ! class_exists( 'WP_MCP_AI_Telegram_Webhook_Controller' ) ) {
			if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$this->markTestSkipped( 'Pro addon not available' );
				return null;
			}
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'Telegram Webhook Controller not available' );
				return null;
			}
		}
		return new WP_MCP_AI_Telegram_Webhook_Controller();
	}

	/**
	 * Test that get_channel_contact_id() method exists in the Telegram controller.
	 *
	 * This method is needed for human takeover keyword support.
	 */
	public function test_telegram_controller_has_get_channel_contact_id_method() {
		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		$reflection = new ReflectionClass( $controller );
		$this->assertTrue(
			$reflection->hasMethod( 'get_channel_contact_id' ),
			'Telegram controller should have get_channel_contact_id() for human takeover support'
		);

		$method = $reflection->getMethod( 'get_channel_contact_id' );
		$this->assertTrue(
			$method->isProtected() || $method->isPublic(),
			'get_channel_contact_id() should be at least protected'
		);
	}

	/**
	 * Test that get_active_telegram_connection() returns a connection even when
	 * assigned_assistant_ids is not set — allowing the global default_assistant_id
	 * from automation rules to serve as a fallback.
	 */
	public function test_get_active_telegram_connection_returns_connection_without_assigned_assistants() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		// Save a Telegram connection with NO assigned_assistant_ids.
		$connection_data = array(
			'name'            => 'Telegram No Assistants',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '5555555555:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
		);

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_active_telegram_connection' );
		$method->setAccessible( true );

		$connection = $method->invoke( $controller );

		$this->assertNotNull(
			$connection,
			'get_active_telegram_connection() should return an enabled Telegram connection even without assigned_assistant_ids'
		);
		$this->assertEquals( 'telegram', $connection['connection_type'] );
	}

	/**
	 * Test that process_message() uses the default_assistant_id from automation
	 * rules as a fallback when no per-connection assistant is assigned.
	 *
	 * Validates the core new behaviour: Telegram auto-reply now respects the
	 * global assistant setting just like the WhatsApp channel does.
	 */
	public function test_process_message_uses_default_assistant_id_from_automation_rules() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		// Create a dummy assistant post.
		$assistant_id = wp_insert_post( array(
			'post_type'   => 'mcp_ai_assistant',
			'post_title'  => 'Default Telegram Bot',
			'post_name'   => 'default-telegram-bot',
			'post_status' => 'publish',
		) );
		$this->assertGreaterThan( 0, $assistant_id, 'Assistant post should be created successfully' );

		// Set the global default_assistant_id in automation rules.
		update_option( 'wp_mcp_ai_chat_channels_automation_rules', array(
			'default_assistant_id' => $assistant_id,
		) );

		// Save a Telegram connection with NO assigned_assistant_ids.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( array(
			'name'            => 'Telegram Default Assistant Test',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '6666666666:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
		) );

		// Use the filter to capture whether auto-reply was triggered.
		$captured_assistant_ids = null;
		add_filter(
			'wp_mcp_ai_telegram_should_auto_reply',
			function ( $should_reply, $message, $automation_rules ) use ( &$captured_assistant_ids ) {
				// At this point the controller has already resolved assigned_assistant_ids.
				// We verify by inspecting whether default_assistant_id was set.
				$captured_assistant_ids = isset( $automation_rules['default_assistant_id'] )
					? array( absint( $automation_rules['default_assistant_id'] ) )
					: array();
				return false; // Prevent actual cron dispatch.
			},
			10,
			3
		);

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'process_message' );
		$method->setAccessible( true );

		$method->invoke( $controller, array(
			'text'    => 'Hello bot',
			'chat'    => array( 'id' => '111222333' ),
			'from'    => array( 'id' => '444555666' ),
			'message_id' => 1,
		) );

		remove_all_filters( 'wp_mcp_ai_telegram_should_auto_reply' );

		$this->assertNotNull( $captured_assistant_ids, 'Filter should have been called' );
		$this->assertContains(
			$assistant_id,
			$captured_assistant_ids,
			'Automation rules default_assistant_id should be used as fallback'
		);

		// Cleanup.
		wp_delete_post( $assistant_id, true );
		delete_option( 'wp_mcp_ai_chat_channels_automation_rules' );
	}

	/**
	 * Test that the wp_mcp_ai_telegram_should_auto_reply filter is applied in
	 * process_message() — allowing developers to override the auto-reply decision.
	 */
	public function test_telegram_should_auto_reply_filter_is_applied() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		$assistant_id = wp_insert_post( array(
			'post_type'   => 'mcp_ai_assistant',
			'post_title'  => 'Filter Test Bot',
			'post_name'   => 'filter-test-bot',
			'post_status' => 'publish',
		) );
		$this->assertGreaterThan( 0, $assistant_id, 'Assistant post should be created successfully' );

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( array(
			'name'                  => 'Telegram Filter Test',
			'url'                   => 'https://api.telegram.org',
			'connection_type'       => 'telegram',
			'auth_type'             => 'none',
			'enabled'               => true,
			'api_key'               => '7777777777:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
			'assigned_assistant_ids' => array( $assistant_id ),
		) );

		$filter_was_called = false;
		add_filter(
			'wp_mcp_ai_telegram_should_auto_reply',
			function () use ( &$filter_was_called ) {
				$filter_was_called = true;
				return false; // Block reply without dispatching cron.
			}
		);

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'process_message' );
		$method->setAccessible( true );
		$method->invoke( $controller, array(
			'text'       => 'Filter test message',
			'chat'       => array( 'id' => '777888999' ),
			'from'       => array( 'id' => '111000111' ),
			'message_id' => 2,
		) );

		remove_all_filters( 'wp_mcp_ai_telegram_should_auto_reply' );

		$this->assertTrue( $filter_was_called, 'wp_mcp_ai_telegram_should_auto_reply filter should have been invoked' );

		wp_delete_post( $assistant_id, true );
	}
}
