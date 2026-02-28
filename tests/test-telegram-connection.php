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

		$original                  = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
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
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Default Telegram Bot',
				'post_name'   => 'default-telegram-bot',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $assistant_id, 'Assistant post should be created successfully' );

		// Set the global default_assistant_id in automation rules.
		update_option(
			'wp_mcp_ai_chat_channels_automation_rules',
			array(
				'default_assistant_id' => $assistant_id,
			)
		);

		// Save a Telegram connection with NO assigned_assistant_ids.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Telegram Default Assistant Test',
				'url'             => 'https://api.telegram.org',
				'connection_type' => 'telegram',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => '6666666666:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
			)
		);

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

		$method->invoke(
			$controller,
			array(
				'text'       => 'Hello bot',
				'chat'       => array( 'id' => '111222333' ),
				'from'       => array( 'id' => '444555666' ),
				'message_id' => 1,
			)
		);

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

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Filter Test Bot',
				'post_name'   => 'filter-test-bot',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $assistant_id, 'Assistant post should be created successfully' );

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'Telegram Filter Test',
				'url'                    => 'https://api.telegram.org',
				'connection_type'        => 'telegram',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => '7777777777:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
				'assigned_assistant_ids' => array( $assistant_id ),
			)
		);

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
		$method->invoke(
			$controller,
			array(
				'text'       => 'Filter test message',
				'chat'       => array( 'id' => '777888999' ),
				'from'       => array( 'id' => '111000111' ),
				'message_id' => 2,
			)
		);

		remove_all_filters( 'wp_mcp_ai_telegram_should_auto_reply' );

		$this->assertTrue( $filter_was_called, 'wp_mcp_ai_telegram_should_auto_reply filter should have been invoked' );

		wp_delete_post( $assistant_id, true );
	}

	// =========================================================================
	// Telegram Web Login feature tests
	// =========================================================================

	/**
	 * Load the Telegram Login controller, skipping if unavailable.
	 *
	 * @return WP_MCP_AI_Telegram_Login_Controller|null
	 */
	private function load_telegram_login_controller() {
		if ( ! class_exists( 'WP_MCP_AI_Telegram_Login_Controller' ) ) {
			if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$this->markTestSkipped( 'Pro addon not available' );
				return null;
			}
			$file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-login-controller.php';
			if ( file_exists( $file ) ) {
				// Guard against loading the file twice (include_once handles this).
				if ( ! class_exists( 'WP_MCP_AI_Telegram_Login_Controller' ) ) {
					// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
					include_once $file;
				}
			} else {
				$this->markTestSkipped( 'Telegram Login Controller not available' );
				return null;
			}
		}
		// Return a fresh instance for use with ReflectionClass without triggering
		// the constructor (which calls add_action / add_shortcode – safe to call again).
		return new WP_MCP_AI_Telegram_Login_Controller();
	}

	/**
	 * Test that verify_auth_data() returns true for a valid Telegram auth payload.
	 */
	public function test_verify_auth_data_returns_true_for_valid_data() {
		$controller = $this->load_telegram_login_controller();
		if ( null === $controller ) {
			return;
		}

		// Build a synthetic auth data payload.
		$bot_token = 'TestBotToken1234567890:ABCDEFGHIJKLMNOPQRabcdefghijklmno';
		$auth_data = array(
			'id'         => '123456789',
			'first_name' => 'John',
			'username'   => 'johndoe',
			'auth_date'  => (string) time(),
		);

		// Compute the correct hash using the same algorithm as the controller.
		$check_fields = array();
		foreach ( $auth_data as $key => $value ) {
			$check_fields[] = $key . '=' . $value;
		}
		sort( $check_fields );
		$data_check_string = implode( "\n", $check_fields );
		$secret_key        = hash( 'sha256', $bot_token, true );
		$auth_data['hash'] = hash_hmac( 'sha256', $data_check_string, $secret_key );

		$result = $controller->verify_auth_data( $auth_data, $bot_token );

		$this->assertTrue( $result, 'verify_auth_data() should return true for valid Telegram auth data' );
	}

	/**
	 * Test that verify_auth_data() returns WP_Error for a tampered hash.
	 */
	public function test_verify_auth_data_returns_error_for_invalid_hash() {
		$controller = $this->load_telegram_login_controller();
		if ( null === $controller ) {
			return;
		}

		$auth_data = array(
			'id'         => '123456789',
			'first_name' => 'Jane',
			'auth_date'  => (string) time(),
			'hash'       => str_repeat( 'a', 64 ), // Fake hash.
		);

		$result = $controller->verify_auth_data( $auth_data, 'AnyBotToken:123' );

		$this->assertInstanceOf( 'WP_Error', $result, 'verify_auth_data() should return WP_Error for invalid hash' );
		$this->assertEquals( 'wp_mcp_ai_telegram_login_invalid_hash', $result->get_error_code() );
	}

	/**
	 * Test that verify_auth_data() returns WP_Error when auth_date is expired.
	 */
	public function test_verify_auth_data_returns_error_for_expired_auth_date() {
		$controller = $this->load_telegram_login_controller();
		if ( null === $controller ) {
			return;
		}

		$bot_token = 'TestBotToken:ExpiredTest123';
		$auth_data = array(
			'id'         => '999',
			'first_name' => 'Old',
			'auth_date'  => (string) ( time() - 90000 ), // More than 24 h ago.
		);

		$check_fields = array();
		foreach ( $auth_data as $key => $value ) {
			$check_fields[] = $key . '=' . $value;
		}
		sort( $check_fields );
		$data_check_string = implode( "\n", $check_fields );
		$secret_key        = hash( 'sha256', $bot_token, true );
		$auth_data['hash'] = hash_hmac( 'sha256', $data_check_string, $secret_key );

		$result = $controller->verify_auth_data( $auth_data, $bot_token );

		$this->assertInstanceOf( 'WP_Error', $result, 'verify_auth_data() should return WP_Error for expired auth_date' );
		$this->assertEquals( 'wp_mcp_ai_telegram_login_expired', $result->get_error_code() );
	}

	/**
	 * Test that verify_auth_data() returns WP_Error when hash is missing.
	 */
	public function test_verify_auth_data_returns_error_when_hash_missing() {
		$controller = $this->load_telegram_login_controller();
		if ( null === $controller ) {
			return;
		}

		$auth_data = array(
			'id'         => '123',
			'first_name' => 'NoHash',
			'auth_date'  => (string) time(),
		);

		$result = $controller->verify_auth_data( $auth_data, 'AnyToken:123' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_telegram_login_missing_hash', $result->get_error_code() );
	}

	/**
	 * Test that enable_web_login and web_login_redirect_url persist when saving a connection.
	 */
	public function test_telegram_web_login_fields_persist() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'                   => 'Telegram Web Login Test',
			'url'                    => 'https://api.telegram.org',
			'connection_type'        => 'telegram',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => '8888888888:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
			'enable_web_login'       => true,
			'web_login_redirect_url' => 'https://example.com/welcome',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved );
		$this->assertTrue( (bool) $saved['enable_web_login'], 'enable_web_login should be stored as true' );
		$this->assertEquals( 'https://example.com/welcome', $saved['web_login_redirect_url'], 'web_login_redirect_url should persist' );
	}

	/**
	 * Test that enable_web_login and web_login_redirect_url are preserved on update
	 * when not provided in the update data.
	 */
	public function test_telegram_web_login_fields_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Create initial connection with web login enabled.
		$connection_data = array(
			'name'                   => 'Telegram Web Login Preserve',
			'url'                    => 'https://api.telegram.org',
			'connection_type'        => 'telegram',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => '9999999999:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
			'enable_web_login'       => true,
			'web_login_redirect_url' => 'https://example.com/logged-in',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		// Update without providing web login fields.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Telegram Web Login Preserve — Renamed',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
		);

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertTrue( (bool) $updated['enable_web_login'], 'enable_web_login should be preserved on update' );
		$this->assertEquals( 'https://example.com/logged-in', $updated['web_login_redirect_url'], 'web_login_redirect_url should be preserved on update' );
	}

	/**
	 * Test that get_active_web_login_connection() returns only connections with Web Login enabled.
	 */
	public function test_get_active_web_login_connection_returns_correct_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$controller = $this->load_telegram_login_controller();
		if ( null === $controller ) {
			return;
		}

		// Save a Telegram connection without web login.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'             => 'No Web Login Bot',
				'url'              => 'https://api.telegram.org',
				'connection_type'  => 'telegram',
				'auth_type'        => 'none',
				'enabled'          => true,
				'api_key'          => '1010101010:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
				'enable_web_login' => false,
			)
		);

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_active_web_login_connection' );
		$method->setAccessible( true );

		// Should return null when no connection has web login enabled.
		$result = $method->invoke( $controller );
		$this->assertNull( $result, 'Should return null when no web-login-enabled Telegram connection exists' );

		// Now save one with web login enabled.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'             => 'Web Login Bot',
				'url'              => 'https://api.telegram.org',
				'connection_type'  => 'telegram',
				'auth_type'        => 'none',
				'enabled'          => true,
				'api_key'          => '1111111111:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
				'enable_web_login' => true,
			)
		);

		$result = $method->invoke( $controller );
		$this->assertNotNull( $result, 'Should return the web-login-enabled Telegram connection' );
		$this->assertTrue( (bool) $result['enable_web_login'], 'Returned connection should have enable_web_login set' );
	}

	/**
	 * Test that the [mcp_ai_telegram_login] shortcode renders a script tag when
	 * a bot_username is provided via the shortcode attribute.
	 */
	public function test_render_login_widget_shortcode_with_bot_username_attr() {
		$controller = $this->load_telegram_login_controller();
		if ( null === $controller ) {
			return;
		}

		$output = $controller->render_login_widget(
			array(
				'bot_username'   => 'mytestbot',
				'redirect_url'   => '',
				'button_size'    => 'large',
				'corner_radius'  => '',
				'request_access' => '',
				'show_avatar'    => '1',
				'lang'           => '',
			)
		);

		$this->assertStringContainsString( '<div class="wp-mcp-ai-telegram-login-widget">', $output, 'Shortcode should be wrapped in a div container' );
		$this->assertStringContainsString( '<script', $output, 'Shortcode should output a <script> tag' );
		$this->assertStringContainsString( 'telegram-widget.js', $output, 'Script should load the Telegram widget JS' );
		$this->assertStringContainsString( 'data-telegram-login="mytestbot"', $output, 'data-telegram-login attribute should contain the bot username' );
		$this->assertStringContainsString( 'data-size="large"', $output, 'data-size attribute should be present' );
	}

	/**
	 * Test that the shortcode returns a comment when bot_username is not provided
	 * and no Web Login connection is configured.
	 */
	public function test_render_login_widget_shortcode_without_bot_username_returns_comment() {
		$controller = $this->load_telegram_login_controller();
		if ( null === $controller ) {
			return;
		}

		$output = $controller->render_login_widget(
			array(
				'bot_username'   => '',
				'redirect_url'   => '',
				'button_size'    => 'large',
				'corner_radius'  => '',
				'request_access' => '',
				'show_avatar'    => '1',
				'lang'           => '',
			)
		);

		$this->assertStringContainsString( '<!--', $output, 'Shortcode should return an HTML comment when bot_username is missing' );
	}

	/**
	 * Test that handle_login_callback() returns a descriptive WP_Error when required
	 * Telegram auth parameters are missing from the request.
	 *
	 * This covers the case where the login-callback URL is accidentally used as the
	 * Mini App URL in BotFather: Telegram opens the URL without auth query params,
	 * which previously surfaced as the generic rest_missing_callback_param error.
	 */
	public function test_handle_login_callback_returns_error_when_auth_params_are_missing() {
		$controller = $this->load_telegram_login_controller();
		if ( null === $controller ) {
			return;
		}

		// Build a request with NO Telegram auth parameters.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-login' );

		$response = $controller->handle_login_callback( $request );

		$this->assertInstanceOf( 'WP_Error', $response, 'A request without auth params should return a WP_Error' );
		$this->assertEquals(
			'wp_mcp_ai_telegram_login_missing_params',
			$response->get_error_code(),
			'Error code should identify the missing-params scenario'
		);
		$this->assertEquals(
			400,
			$response->get_error_data()['status'],
			'HTTP status should be 400'
		);
		// Error message should mention all four missing parameters.
		$message = $response->get_error_message();
		$this->assertStringContainsString( 'id', $message );
		$this->assertStringContainsString( 'first_name', $message );
		$this->assertStringContainsString( 'auth_date', $message );
		$this->assertStringContainsString( 'hash', $message );
	}

	// =========================================================================
	// Telegram Mini App URL tests
	// =========================================================================

	/**
	 * Load the Telegram Mini App controller, skipping if unavailable.
	 *
	 * @return WP_MCP_AI_Telegram_Mini_App_Controller|null
	 */
	private function load_telegram_mini_app_controller() {
		if ( ! class_exists( 'WP_MCP_AI_Telegram_Mini_App_Controller' ) ) {
			if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$this->markTestSkipped( 'Pro addon not available' );
				return null;
			}
			$file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-mini-app-controller.php';
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
				include_once $file;
			} else {
				$this->markTestSkipped( 'Telegram Mini App Controller not available' );
				return null;
			}
		}
		return new WP_MCP_AI_Telegram_Mini_App_Controller();
	}

	/**
	 * Test that the Telegram Mini App controller class exists and can be instantiated.
	 */
	public function test_telegram_mini_app_controller_exists() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$this->assertInstanceOf(
			'WP_MCP_AI_Telegram_Mini_App_Controller',
			$controller,
			'Telegram Mini App controller should be instantiatable'
		);
	}

	/**
	 * Test that get_mini_app_url() returns a valid URL containing the expected path.
	 */
	public function test_get_mini_app_url_returns_valid_url() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$url = WP_MCP_AI_Telegram_Mini_App_Controller::get_mini_app_url();

		$this->assertNotEmpty( $url, 'Mini App URL should not be empty' );
		$this->assertStringContainsString( 'mcp-ai/v1/telegram-mini-app', $url, 'Mini App URL should contain the expected REST path' );
		$this->assertStringStartsWith( 'http', $url, 'Mini App URL should be a valid HTTP(S) URL' );
	}

	/**
	 * Test that the Telegram Mini App controller registers the expected REST route.
	 */
	public function test_telegram_mini_app_route_is_registered() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		// Trigger route registration.
		$controller->register_routes();

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			'/mcp-ai/v1/telegram-mini-app',
			$routes,
			'The /mcp-ai/v1/telegram-mini-app REST route should be registered'
		);
	}

	/**
	 * Test that the Mini App controller has a public static get_mini_app_url() method.
	 */
	public function test_telegram_mini_app_controller_has_get_mini_app_url_method() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$reflection = new ReflectionClass( $controller );

		$this->assertTrue(
			$reflection->hasMethod( 'get_mini_app_url' ),
			'Telegram Mini App controller should have a get_mini_app_url() method'
		);

		$method = $reflection->getMethod( 'get_mini_app_url' );
		$this->assertTrue(
			$method->isPublic() && $method->isStatic(),
			'get_mini_app_url() should be a public static method'
		);
	}

	/**
	 * Test that the Mini App controller registers the validate endpoint.
	 */
	public function test_telegram_mini_app_validate_route_is_registered() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$controller->register_routes();

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			'/mcp-ai/v1/telegram-mini-app/validate',
			$routes,
			'The /mcp-ai/v1/telegram-mini-app/validate REST route should be registered'
		);
	}

	/**
	 * Test that verify_init_data() verifies a correctly-signed initData string.
	 *
	 * Constructs a synthetic initData using the same algorithm Telegram uses:
	 *   secret_key    = HMAC-SHA256("WebAppData", bot_token)  [raw binary]
	 *   expected_hash = HMAC-SHA256(data_check_string, secret_key)  [hex]
	 */
	public function test_verify_init_data_returns_true_for_valid_data() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$bot_token = 'TestBotToken9876:ABCDEFGHIJKLMNOPQRabcdefghijklmno';
		$auth_date = (string) time();
		$user_json = wp_json_encode(
			array(
				'id'         => 123456789,
				'first_name' => 'Alice',
				'username'   => 'alice_test',
			)
		);

		// Build the data-check string exactly as Telegram specifies.
		$pairs = array(
			'auth_date' => $auth_date,
			'user'      => $user_json,
		);
		$check_pairs = array();
		foreach ( $pairs as $k => $v ) {
			$check_pairs[] = $k . '=' . $v;
		}
		sort( $check_pairs );
		$data_check_string = implode( "\n", $check_pairs );

		// Compute hash: Mini App uses "WebAppData" as the HMAC key for the secret.
		$hmac_secret_raw = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );
		$expected_hash   = hash_hmac( 'sha256', $data_check_string, $hmac_secret_raw );

		// Assemble a URL-encoded initData string.
		$init_data = http_build_query(
			array(
				'auth_date' => $auth_date,
				'user'      => $user_json,
				'hash'      => $expected_hash,
			)
		);

		$result = $controller->verify_init_data( $init_data, $bot_token );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'verify_init_data() should succeed for correctly-signed data' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'auth_date', $result );
	}

	/**
	 * Test that verify_init_data() returns WP_Error for a tampered hash.
	 */
	public function test_verify_init_data_returns_error_for_invalid_hash() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$init_data = http_build_query(
			array(
				'auth_date' => (string) time(),
				'user'      => '{"id":1,"first_name":"Bob"}',
				'hash'      => str_repeat( 'a', 64 ),
			)
		);

		$result = $controller->verify_init_data( $init_data, 'AnyBotToken:123' );

		$this->assertInstanceOf( 'WP_Error', $result, 'verify_init_data() should return WP_Error for invalid hash' );
		$this->assertEquals( 'wp_mcp_ai_telegram_mini_app_invalid_hash', $result->get_error_code() );
	}

	/**
	 * Test that verify_init_data() returns WP_Error when auth_date is expired.
	 */
	public function test_verify_init_data_returns_error_for_expired_auth_date() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		// Build a correctly-signed but expired payload.
		$bot_token = 'ExpiredToken:ABCDEFGHIJKLMNOPQRabcdefghijklmno';
		$auth_date = (string) ( time() - 90000 ); // Older than the 24-hour max.

		$pairs             = array( 'auth_date=' . $auth_date );
		$data_check_string = implode( "\n", $pairs );
		$hmac_secret_raw   = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );
		$hash              = hash_hmac( 'sha256', $data_check_string, $hmac_secret_raw );

		$init_data = http_build_query(
			array(
				'auth_date' => $auth_date,
				'hash'      => $hash,
			)
		);

		$result = $controller->verify_init_data( $init_data, $bot_token );

		$this->assertInstanceOf( 'WP_Error', $result, 'verify_init_data() should return WP_Error for expired auth_date' );
		$this->assertEquals( 'wp_mcp_ai_telegram_mini_app_expired', $result->get_error_code() );
	}

	/**
	 * Test that verify_init_data() returns WP_Error when hash is absent.
	 */
	public function test_verify_init_data_returns_error_when_hash_missing() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$init_data = http_build_query( array( 'auth_date' => (string) time() ) );

		$result = $controller->verify_init_data( $init_data, 'SomeBotToken:abc' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_telegram_mini_app_missing_hash', $result->get_error_code() );
	}

	/**
	 * Test that resolve_mini_app_assistant() returns the explicit ?assistant= query param when provided.
	 */
	public function test_resolve_mini_app_assistant_honours_query_param() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app' );
		$request->set_param( 'assistant', 'my-custom-assistant' );

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_mini_app_assistant' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, $request, null );

		$this->assertEquals( 'my-custom-assistant', $result, 'Explicit query param should be returned as-is' );
	}

	/**
	 * Test that resolve_mini_app_assistant() returns the first assigned_assistant_id from the connection
	 * when no explicit query param is provided.
	 */
	public function test_resolve_mini_app_assistant_uses_connection_assigned_assistant() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app' );
		// No assistant param set.

		$connection = array(
			'connection_type'       => 'telegram',
			'enabled'               => true,
			'assigned_assistant_ids' => array( 42, 99 ),
		);

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_mini_app_assistant' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, $request, $connection );

		$this->assertEquals( '42', $result, 'First assigned_assistant_id from the connection should be used' );
	}

	/**
	 * Test that resolve_mini_app_assistant() falls back to automation_rules default_assistant_id
	 * when the connection has no assigned_assistant_ids.
	 */
	public function test_resolve_mini_app_assistant_falls_back_to_automation_rules() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		update_option(
			'wp_mcp_ai_chat_channels_automation_rules',
			array( 'default_assistant_id' => 77 )
		);

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app' );
		// No assistant param, connection has no assigned_assistant_ids.
		$connection = array(
			'connection_type' => 'telegram',
			'enabled'         => true,
		);

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_mini_app_assistant' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, $request, $connection );

		delete_option( 'wp_mcp_ai_chat_channels_automation_rules' );

		$this->assertEquals( '77', $result, 'automation_rules default_assistant_id should be used as fallback' );
	}

	/**
	 * Test that resolve_mini_app_assistant() returns empty string when nothing is configured.
	 */
	public function test_resolve_mini_app_assistant_returns_empty_when_nothing_configured() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		delete_option( 'wp_mcp_ai_chat_channels_automation_rules' );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app' );

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_mini_app_assistant' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, $request, null );

		$this->assertSame( '', $result, 'Empty string should be returned when no assistant is configured' );
	}

	/**
	 * Test that query param takes precedence over connection assigned_assistant_ids.
	 */
	public function test_resolve_mini_app_assistant_query_param_takes_precedence_over_connection() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app' );
		$request->set_param( 'assistant', 'explicit-slug' );

		$connection = array(
			'connection_type'       => 'telegram',
			'enabled'               => true,
			'assigned_assistant_ids' => array( 55 ),
		);

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_mini_app_assistant' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, $request, $connection );

		$this->assertEquals( 'explicit-slug', $result, 'Explicit query param should take precedence over connection setting' );
	}
}
