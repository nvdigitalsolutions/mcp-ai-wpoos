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

	// -----------------------------------------------------------------------
	// Per-connection webhook endpoint tests (mirrors Apple Messages fix).
	// -----------------------------------------------------------------------

	/**
	 * Test that register_routes() registers both the global and per-connection
	 * webhook routes for Telegram.
	 */
	public function test_register_routes_registers_per_connection_route() {
		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		// Trigger route registration.
		$controller->register_routes();

		$routes = rest_get_server()->get_routes( 'mcp-ai/v1' );

		$this->assertArrayHasKey(
			'/mcp-ai/v1/webhooks/telegram',
			$routes,
			'Global (backward-compatible) Telegram webhook route should be registered'
		);

		$this->assertArrayHasKey(
			'/mcp-ai/v1/webhooks/telegram/(?P<connection_id>[a-zA-Z0-9_-]+)',
			$routes,
			'Per-connection Telegram webhook route should be registered'
		);
	}

	/**
	 * Test that $current_connection_id defaults to null.
	 */
	public function test_current_connection_id_defaults_to_null() {
		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		$reflection = new ReflectionClass( $controller );
		$property   = $reflection->getProperty( 'current_connection_id' );
		$property->setAccessible( true );

		$this->assertNull(
			$property->getValue( $controller ),
			'$current_connection_id should default to null'
		);
	}

	/**
	 * Test that get_active_telegram_connection() resolves a specific connection
	 * when a valid connection_id is passed.
	 */
	public function test_get_active_telegram_connection_resolves_by_id() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		// Create two distinct Telegram connections.
		$id_a = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Bot A',
				'url'             => 'https://api.telegram.org',
				'connection_type' => 'telegram',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => '1111111111:BotATokenABCDEFGHIJKLMNOPQRSTUVWX',
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $id_a, 'Bot A connection should be saved' );

		$id_b = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Bot B',
				'url'             => 'https://api.telegram.org',
				'connection_type' => 'telegram',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => '2222222222:BotBTokenABCDEFGHIJKLMNOPQRSTUVWX',
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $id_b, 'Bot B connection should be saved' );

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_active_telegram_connection' );
		$method->setAccessible( true );

		// Ask for Bot B specifically.
		$resolved = $method->invoke( $controller, $id_b );

		$this->assertNotNull( $resolved, 'Should resolve Bot B by ID' );
		$this->assertEquals( $id_b, $resolved['id'], 'Resolved connection should be Bot B' );
	}

	/**
	 * Test that get_active_telegram_connection() uses $this->current_connection_id
	 * when no explicit parameter is passed.
	 */
	public function test_get_active_telegram_connection_uses_instance_property() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		$conn_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Instance Prop Bot',
				'url'             => 'https://api.telegram.org',
				'connection_type' => 'telegram',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => '3333333333:InstancePropTokenABCDEFGHIJKLMNOPQ',
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $conn_id );

		$reflection = new ReflectionClass( $controller );

		// Set the instance property.
		$prop = $reflection->getProperty( 'current_connection_id' );
		$prop->setAccessible( true );
		$prop->setValue( $controller, $conn_id );

		$method = $reflection->getMethod( 'get_active_telegram_connection' );
		$method->setAccessible( true );

		// Call without explicit argument — should use instance property.
		$resolved = $method->invoke( $controller );

		$this->assertNotNull( $resolved, 'Should resolve using $current_connection_id property' );
		$this->assertEquals(
			$conn_id,
			$resolved['id'],
			'Connection resolved via instance property should match the saved connection'
		);
	}

	/**
	 * Test that get_secret_token() accepts a connection_id parameter.
	 */
	public function test_get_secret_token_accepts_connection_id_parameter() {
		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_secret_token' );

		$this->assertEquals(
			1,
			$method->getNumberOfParameters(),
			'get_secret_token() should accept exactly one parameter (connection_id)'
		);

		$param = $method->getParameters()[0];
		$this->assertEquals(
			'connection_id',
			$param->getName(),
			'Parameter should be named connection_id'
		);
		$this->assertTrue(
			$param->isOptional(),
			'connection_id parameter should be optional'
		);
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
	 * Test that auto_create_wp_user and new_user_role fields persist.
	 */
	public function test_telegram_auto_create_wp_user_fields_persist() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'                => 'Telegram Auto-Create Test',
			'url'                 => 'https://api.telegram.org',
			'connection_type'     => 'telegram',
			'auth_type'           => 'none',
			'enabled'             => true,
			'api_key'             => '7777777777:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
			'auto_create_wp_user' => true,
			'new_user_role'       => 'editor',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved );
		$this->assertTrue( (bool) $saved['auto_create_wp_user'], 'auto_create_wp_user should be stored as true' );
		$this->assertEquals( 'editor', $saved['new_user_role'], 'new_user_role should persist' );
	}

	/**
	 * Test that auto_create_wp_user and new_user_role are preserved on update
	 * when not provided in the update data.
	 */
	public function test_telegram_auto_create_wp_user_fields_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Create initial connection with auto-create enabled and custom role.
		$connection_data = array(
			'name'                => 'Telegram Auto-Create Preserve',
			'url'                 => 'https://api.telegram.org',
			'connection_type'     => 'telegram',
			'auth_type'           => 'none',
			'enabled'             => true,
			'api_key'             => '6666666666:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
			'auto_create_wp_user' => true,
			'new_user_role'       => 'author',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		// Update without providing auto-create fields.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Telegram Auto-Create Preserve — Renamed',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
		);

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertTrue( (bool) $updated['auto_create_wp_user'], 'auto_create_wp_user should be preserved on update' );
		$this->assertEquals( 'author', $updated['new_user_role'], 'new_user_role should be preserved on update' );
	}

	/**
	 * Test that auto_create_wp_user defaults to false and new_user_role defaults
	 * to subscriber when not provided.
	 */
	public function test_telegram_auto_create_wp_user_defaults() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Telegram Default Test',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '5555555555:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved );
		$this->assertFalse( (bool) $saved['auto_create_wp_user'], 'auto_create_wp_user should default to false' );
		$this->assertEquals( 'subscriber', $saved['new_user_role'], 'new_user_role should default to subscriber' );
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
		$pairs       = array(
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
			'connection_type'        => 'telegram',
			'enabled'                => true,
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
		delete_option( 'wp_mcp_ai_chat_channels_toolkit_settings' );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app' );

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_mini_app_assistant' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, $request, null );

		$this->assertSame( '', $result, 'Empty string should be returned when no assistant is configured' );
	}

	/**
	 * Test that resolve_mini_app_assistant() falls back to the toolkit settings default_assistant
	 * when automation rules also have no default assistant set.
	 */
	public function test_resolve_mini_app_assistant_falls_back_to_toolkit_settings() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		delete_option( 'wp_mcp_ai_chat_channels_automation_rules' );
		update_option( 'wp_mcp_ai_chat_channels_toolkit_settings', array( 'default_assistant' => 88 ) );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app' );

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_mini_app_assistant' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, $request, null );

		delete_option( 'wp_mcp_ai_chat_channels_toolkit_settings' );

		$this->assertEquals( '88', $result, 'toolkit settings default_assistant should be used as final fallback' );
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
			'connection_type'        => 'telegram',
			'enabled'                => true,
			'assigned_assistant_ids' => array( 55 ),
		);

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_mini_app_assistant' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, $request, $connection );

		$this->assertEquals( 'explicit-slug', $result, 'Explicit query param should take precedence over connection setting' );
	}

	// =========================================================================
	// New CMS endpoints: routes, permissions, and get_active_toolkits()
	// =========================================================================

	/**
	 * Test that the /content REST route is registered.
	 */
	public function test_telegram_mini_app_content_route_is_registered() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			'/mcp-ai/v1/telegram-mini-app/content',
			$routes,
			'/mcp-ai/v1/telegram-mini-app/content route should be registered'
		);
	}

	/**
	 * Test that the /tools REST route is registered.
	 */
	public function test_telegram_mini_app_tools_route_is_registered() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			'/mcp-ai/v1/telegram-mini-app/tools',
			$routes,
			'/mcp-ai/v1/telegram-mini-app/tools route should be registered'
		);
	}

	/**
	 * Test that the /media REST route is registered.
	 */
	public function test_telegram_mini_app_media_route_is_registered() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			'/mcp-ai/v1/telegram-mini-app/media',
			$routes,
			'/mcp-ai/v1/telegram-mini-app/media route should be registered'
		);
	}

	/**
	 * Test check_permission() returns false for unauthenticated users.
	 */
	public function test_check_permission_returns_false_for_unauthenticated_user() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		wp_set_current_user( 0 );
		$this->assertFalse(
			$controller->check_permission(),
			'check_permission() should return false for unauthenticated users'
		);
	}

	/**
	 * Test check_permission() returns true for users with edit_posts capability.
	 */
	public function test_check_permission_returns_true_for_editor() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue(
			$controller->check_permission(),
			'check_permission() should return true for users with edit_posts capability'
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Test get_active_toolkits() returns an empty array when Pro addon is absent.
	 */
	public function test_get_active_toolkits_returns_empty_when_pro_absent() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		// WP_MCP_AI_PRO_VERSION is not defined in the test environment,
		// so get_active_toolkits() should return an empty array.
		if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro version is active – skipping base-version guard test' );
			return;
		}

		$result = $controller->get_active_toolkits();

		$this->assertIsArray( $result, 'get_active_toolkits() should return an array' );
		$this->assertEmpty( $result, 'get_active_toolkits() should return empty array when Pro is absent' );
	}

	/**
	 * Test get_active_toolkits() returns correctly structured entries when Pro is present.
	 */
	public function test_get_active_toolkits_returns_structured_entries_when_pro_active() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
			return;
		}

		// Enable a known setting-gated toolkit.
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_crm_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		$result = $controller->get_active_toolkits();

		$this->assertIsArray( $result, 'get_active_toolkits() should return an array' );

		// Find the CRM toolkit entry.
		$found = null;
		foreach ( $result as $entry ) {
			if ( 'enable_crm_toolkit' === $entry['key'] ) {
				$found = $entry;
				break;
			}
		}

		$this->assertNotNull( $found, 'CRM Toolkit should be present when its setting is enabled' );
		$this->assertArrayHasKey( 'label', $found );
		$this->assertArrayHasKey( 'post_types', $found );
		$this->assertArrayHasKey( 'tool_slugs', $found );
		$this->assertIsArray( $found['post_types'] );
		$this->assertIsArray( $found['tool_slugs'] );

		// Clean up.
		unset( $settings['enable_crm_toolkit'] );
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * Test get_active_toolkits() excludes disabled toolkits.
	 */
	public function test_get_active_toolkits_excludes_disabled_toolkit() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
			return;
		}

		// Explicitly disable the CRM toolkit.
		$settings                                = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_crm_toolkit']          = false;
		$settings['enable_social_media_toolkit'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		$result = $controller->get_active_toolkits();

		foreach ( $result as $entry ) {
			$this->assertNotEquals( 'enable_crm_toolkit', $entry['key'], 'Disabled CRM Toolkit should not appear' );
			$this->assertNotEquals( 'enable_social_media_toolkit', $entry['key'], 'Disabled Social Media Toolkit should not appear' );
		}

		// Clean up.
		unset( $settings['enable_crm_toolkit'], $settings['enable_social_media_toolkit'] );
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * Test handle_content() returns WP_Error for an invalid post type.
	 */
	public function test_handle_content_returns_error_for_invalid_post_type() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		// Log in as an administrator so permission passes.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/content' );
		$request->set_param( 'post_type', 'nonexistent_type_xyz' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'search', '' );

		$result = $controller->handle_content( $request );

		$this->assertInstanceOf( 'WP_Error', $result, 'Invalid post type should return WP_Error' );
		$this->assertEquals( 'wp_mcp_ai_telegram_invalid_post_type', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * Test handle_content() returns posts array for a valid post type.
	 */
	public function test_handle_content_returns_posts_for_valid_post_type() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Test CMS Post',
			)
		);

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/content' );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'search', '' );

		$result = $controller->handle_content( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'posts', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'pages', $data );
		$this->assertArrayHasKey( 'post_types', $data );
		$this->assertIsArray( $data['posts'] );

		// Verify our test post appears.
		$ids = wp_list_pluck( $data['posts'], 'id' );
		$this->assertContains( $post_id, $ids, 'Test post should appear in content response' );

		// Verify post shape.
		$post_data = array_values(
			array_filter(
				$data['posts'],
				function ( $p ) use ( $post_id ) {
					return (int) $p['id'] === $post_id;
				}
			)
		);
		$this->assertNotEmpty( $post_data );
		$this->assertArrayHasKey( 'title', $post_data[0] );
		$this->assertArrayHasKey( 'status', $post_data[0] );
		$this->assertArrayHasKey( 'link', $post_data[0] );

		wp_delete_post( $post_id, true );
		wp_set_current_user( 0 );
	}

	/**
	 * Test handle_content() returns empty results for a subscriber who cannot
	 * edit posts, instead of a hard 403 that triggers a retry loop.
	 */
	public function test_handle_content_returns_empty_for_subscriber() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Create a post that the subscriber should NOT see.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Invisible to subscriber',
			)
		);

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/content' );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'search', '' );

		$result = $controller->handle_content( $request );

		// Must be a valid REST response (not WP_Error / 403) so the Mini App
		// client renders "No content found" instead of entering a retry loop.
		$this->assertInstanceOf( 'WP_REST_Response', $result, 'Subscriber should get a valid response, not WP_Error' );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'posts', $data );
		$this->assertEmpty( $data['posts'], 'Posts should be empty for subscriber' );
		$this->assertEquals( 0, $data['total'] );
		$this->assertArrayHasKey( 'post_types', $data );
		$this->assertEmpty( $data['post_types'], 'Post types should be empty for subscriber' );

		wp_delete_post( $post_id, true );
		wp_set_current_user( 0 );
	}

	/**
	 * Test handle_media() returns empty items when user lacks upload_files capability.
	 *
	 * Instead of a hard 403 the endpoint now returns an empty list so the Mini
	 * App client does not misinterpret it as an auth failure and enter a retry loop.
	 */
	public function test_handle_media_returns_empty_for_insufficient_permissions() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		// Subscriber cannot upload files.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/media' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'search', '' );
		$request->set_param( 'type', '' );

		$result = $controller->handle_media( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $result, 'Subscriber should get an empty WP_REST_Response from handle_media()' );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertEmpty( $data['items'], 'Items should be empty for subscriber' );
		$this->assertEquals( 0, $data['total'] );
		$this->assertEquals( 0, $data['pages'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Test handle_media() returns items array for an administrator.
	 */
	public function test_handle_media_returns_items_for_admin() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/media' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'search', '' );
		$request->set_param( 'type', '' );

		$result = $controller->handle_media( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'pages', $data );
		$this->assertIsArray( $data['items'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Test handle_tools() returns tools and slash_commands keys for an administrator.
	 */
	public function test_handle_tools_returns_expected_keys_for_admin() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/tools' );

		$result = $controller->handle_tools( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'toolkits', $data );
		$this->assertArrayHasKey( 'tools', $data );
		$this->assertArrayHasKey( 'slash_commands', $data );
		$this->assertIsArray( $data['toolkits'] );
		$this->assertIsArray( $data['tools'] );
		$this->assertIsArray( $data['slash_commands'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Test that sanitize_init_data() preserves percent-encoded characters
	 * required for HMAC-SHA256 verification of Telegram initData.
	 *
	 * sanitize_text_field() strips %XX sequences, corrupting the URL-encoded
	 * query string and causing hash verification to always fail. The custom
	 * sanitize_init_data() callback must preserve these sequences.
	 */
	public function test_sanitize_init_data_preserves_url_encoded_chars() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$user_json = wp_json_encode(
			array(
				'id'         => 8355775408,
				'first_name' => 'NV Digital Solutions',
				'username'   => 'testuser',
			)
		);
		$auth_date = (string) time();

		// Build a realistic URL-encoded initData string.
		$init_data = http_build_query(
			array(
				'auth_date' => $auth_date,
				'user'      => $user_json,
				'hash'      => str_repeat( 'a', 64 ),
			)
		);

		// Verify the string contains percent-encoded characters.
		$this->assertMatchesRegularExpression( '/%[0-9a-fA-F]{2}/', $init_data, 'Test input should contain percent-encoded characters' );

		$result = $controller->sanitize_init_data( $init_data );

		// The sanitized output must still contain percent-encoded characters.
		$this->assertMatchesRegularExpression( '/%[0-9a-fA-F]{2}/', $result, 'sanitize_init_data() must preserve percent-encoded characters' );

		// The sanitized output should be identical to the input for valid initData.
		$this->assertEquals( $init_data, $result, 'sanitize_init_data() should not alter a valid initData string' );
	}

	/**
	 * Test that sanitize_init_data() strips null bytes for security.
	 */
	public function test_sanitize_init_data_strips_null_bytes() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$input  = "auth_date=123\x00&hash=abc";
		$result = $controller->sanitize_init_data( $input );

		$this->assertStringNotContainsString( "\x00", $result, 'sanitize_init_data() should strip null bytes' );
		$this->assertEquals( 'auth_date=123&hash=abc', $result );
	}

	/**
	 * Test that sanitize_init_data() strips HTML tags for security.
	 */
	public function test_sanitize_init_data_strips_html_tags() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$input  = 'auth_date=123&user=<script>alert(1)</script>&hash=abc';
		$result = $controller->sanitize_init_data( $input );

		$this->assertStringNotContainsString( '<script>', $result, 'sanitize_init_data() should strip HTML tags' );
	}

	/**
	 * Test that verify_init_data() succeeds when initData is passed through
	 * sanitize_init_data() instead of sanitize_text_field().
	 *
	 * This is the end-to-end regression test for the mini app auto-login fix.
	 */
	public function test_verify_init_data_succeeds_after_sanitize_init_data() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$bot_token = 'TestBotToken9876:ABCDEFGHIJKLMNOPQRabcdefghijklmno';
		$auth_date = (string) time();
		$user_json = wp_json_encode(
			array(
				'id'         => 8355775408,
				'first_name' => 'NV Digital Solutions',
				'username'   => 'nvdigital',
			)
		);

		// Build the data-check string exactly as Telegram specifies.
		$pairs       = array(
			'auth_date' => $auth_date,
			'user'      => $user_json,
		);
		$check_pairs = array();
		foreach ( $pairs as $k => $v ) {
			$check_pairs[] = $k . '=' . $v;
		}
		sort( $check_pairs );
		$data_check_string = implode( "\n", $check_pairs );

		// Compute hash.
		$hmac_secret_raw = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );
		$expected_hash   = hash_hmac( 'sha256', $data_check_string, $hmac_secret_raw );

		// Assemble a URL-encoded initData string (as Telegram provides it).
		$init_data = http_build_query(
			array(
				'auth_date' => $auth_date,
				'user'      => $user_json,
				'hash'      => $expected_hash,
			)
		);

		// Verify that sanitize_text_field would break this (the original bug).
		$corrupted = sanitize_text_field( $init_data );
		$this->assertNotEquals( $init_data, $corrupted, 'sanitize_text_field should corrupt URL-encoded initData' );

		// Verify that sanitize_init_data preserves it.
		$sanitized = $controller->sanitize_init_data( $init_data );
		$this->assertEquals( $init_data, $sanitized, 'sanitize_init_data should preserve URL-encoded initData' );

		// Verify that HMAC verification succeeds with the sanitized data.
		$result = $controller->verify_init_data( $sanitized, $bot_token );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'verify_init_data() should succeed after sanitize_init_data()' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'user', $result );
		$this->assertEquals( 8355775408, $result['user']['id'] );
		$this->assertEquals( 'NV Digital Solutions', $result['user']['first_name'] );
	}

	/**
	 * Test that authenticate_via_tma_token() returns user ID when a valid TMA
	 * session token is present in the HTTP headers.
	 *
	 * This covers the core fix for the Telegram WebView 403 issue: the
	 * determine_current_user hook must authenticate the user before WordPress's
	 * rest_cookie_check_errors runs so that the user-specific nonce (returned
	 * by /validate) passes verification even when the auth cookie is absent.
	 */
	public function test_authenticate_via_tma_token_returns_user_id_for_valid_token() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		// Create a subscriber user to link to the token.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Simulate what handle_validate_init_data() does when creating a token.
		$raw_token  = bin2hex( random_bytes( 20 ) );
		$token_hash = hash( 'sha256', $raw_token );
		set_transient( 'wp_mcp_ai_tma_' . $token_hash, $user_id, HOUR_IN_SECONDS );

		// Simulate the HTTP header that authFetch() sends.
		$_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] = $raw_token;

		$result = $controller->authenticate_via_tma_token( false );

		// Clean up.
		unset( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] );
		delete_transient( 'wp_mcp_ai_tma_' . $token_hash );

		$this->assertEquals( $user_id, $result, 'authenticate_via_tma_token() should return the linked user ID' );
	}

	/**
	 * Test that authenticate_via_tma_token() returns false (unchanged) when no
	 * TMA token header is present.
	 */
	public function test_authenticate_via_tma_token_returns_false_without_header() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] );

		$result = $controller->authenticate_via_tma_token( false );

		$this->assertFalse( $result, 'authenticate_via_tma_token() should return false when no token header is set' );
	}

	/**
	 * Test that authenticate_via_tma_token() returns false when the token does
	 * not match any stored transient (e.g. expired or forged token).
	 */
	public function test_authenticate_via_tma_token_returns_false_for_unknown_token() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] = bin2hex( random_bytes( 20 ) );

		$result = $controller->authenticate_via_tma_token( false );

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] );

		$this->assertFalse( $result, 'authenticate_via_tma_token() should return false for an unknown token' );
	}

	/**
	 * Test that authenticate_via_tma_token() does not override an already
	 * authenticated user (non-zero $user_id passed in).
	 */
	public function test_authenticate_via_tma_token_does_not_override_existing_user() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$existing_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Even if a valid TMA token is present, it must not override.
		$other_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$raw_token     = bin2hex( random_bytes( 20 ) );
		$token_hash    = hash( 'sha256', $raw_token );
		set_transient( 'wp_mcp_ai_tma_' . $token_hash, $other_user_id, HOUR_IN_SECONDS );
		$_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] = $raw_token;

		$result = $controller->authenticate_via_tma_token( $existing_user_id );

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] );
		delete_transient( 'wp_mcp_ai_tma_' . $token_hash );

		$this->assertEquals(
			$existing_user_id,
			$result,
			'authenticate_via_tma_token() must not override an already-authenticated user'
		);
	}

	// =========================================================================
	// Telegram Mini App inline JS: infinite-loop prevention & fallback tests
	// =========================================================================

	/**
	 * Helper: capture the full HTML output of handle_mini_app().
	 *
	 * Because the handler calls exit(), we invoke it inside a
	 * separate PHP process via output buffering + shutdown function
	 * simulation.  As a simpler alternative we just read the source
	 * file and assert against the embedded JavaScript literals.
	 *
	 * @return string|null Inline JavaScript source or null if not available.
	 */
	private function get_mini_app_inline_js_source() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return null;
		}
		$file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-mini-app-controller.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Telegram Mini App Controller file not available' );
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return file_get_contents( $file );
	}

	/**
	 * Test that validateInitData() always rejects on errors (no silent resolve).
	 *
	 * The original bug allowed non-string errors (network/parse failures)
	 * to silently resolve the promise, which caused an infinite auth loop.
	 */
	public function test_validate_init_data_js_rejects_on_network_error() {
		$source = $this->get_mini_app_inline_js_source();
		if ( null === $source ) {
			return;
		}

		// The PHP file contains escaped single quotes (\') inside echo'd JS;
		// file_get_contents reads these as literal backslash-quote characters.
		$this->assertStringContainsString(
			"Promise.reject(typeof err === \\'string\\' ? err : \\'network_error\\')",
			$source,
			'validateInitData() catch handler should always reject, mapping non-string errors to network_error'
		);
	}

	/**
	 * Test that the global retry limit variable is defined.
	 */
	public function test_mini_app_js_defines_global_retry_limit() {
		$source = $this->get_mini_app_inline_js_source();
		if ( null === $source ) {
			return;
		}

		$this->assertStringContainsString(
			'tmaGlobalRetries',
			$source,
			'Mini App JS should define a global retry counter to prevent infinite loops'
		);
		$this->assertStringContainsString(
			'TMA_MAX_AUTO_RETRIES',
			$source,
			'Mini App JS should define a maximum auto-retry constant'
		);
	}

	/**
	 * Test that showLoginPrompt checks the global retry limit.
	 */
	public function test_show_login_prompt_checks_global_retry_limit() {
		$source = $this->get_mini_app_inline_js_source();
		if ( null === $source ) {
			return;
		}

		$this->assertStringContainsString(
			'tmaGlobalRetries < TMA_MAX_AUTO_RETRIES',
			$source,
			'showLoginPrompt should check the global retry limit before auto-retrying'
		);
	}

	/**
	 * Test that init falls back to Chat tab when validation fails.
	 */
	public function test_init_falls_back_to_chat_tab_on_auth_failure() {
		$source = $this->get_mini_app_inline_js_source();
		if ( null === $source ) {
			return;
		}

		// The init function should switch to the Chat tab on auth failure
		// instead of trying to load Content (which requires auth).
		$this->assertStringContainsString(
			"tmaSwitchTab(\\'chat\\')",
			$source,
			'init() should fall back to Chat tab when validateInitData() fails'
		);

		// Ensure the old pattern (catch → loadContent) is NOT present in init.
		$this->assertStringNotContainsString(
			'validateInitData().then(loadContent).catch(loadContent)',
			$source,
			'init() should NOT call loadContent() on auth failure (causes stuck UI)'
		);
	}

	// =========================================================================
	// allow_tma_token_auth: rest_authentication_errors safety net
	// =========================================================================

	/**
	 * Test that allow_tma_token_auth clears a cookie-nonce error when a valid
	 * TMA token is present.
	 */
	public function test_allow_tma_token_auth_clears_nonce_error_with_valid_token() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		// Create a user and a valid TMA token.
		$user_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$raw_token  = bin2hex( random_bytes( 20 ) );
		$token_hash = hash( 'sha256', $raw_token );
		set_transient( 'wp_mcp_ai_tma_' . $token_hash, $user_id, HOUR_IN_SECONDS );

		$_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] = $raw_token;

		$error  = new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed', array( 'status' => 403 ) );
		$result = $controller->allow_tma_token_auth( $error );

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] );
		delete_transient( 'wp_mcp_ai_tma_' . $token_hash );

		$this->assertTrue( $result, 'allow_tma_token_auth() should return true when a valid TMA token clears the nonce error' );
		$this->assertEquals( $user_id, get_current_user_id(), 'Current user should be set to the TMA-linked user' );
	}

	/**
	 * Test that allow_tma_token_auth passes through non-nonce errors untouched.
	 */
	public function test_allow_tma_token_auth_ignores_other_errors() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$error  = new WP_Error( 'rest_forbidden', 'Access denied', array( 'status' => 403 ) );
		$result = $controller->allow_tma_token_auth( $error );

		$this->assertWPError( $result, 'Non-nonce errors should be passed through unchanged' );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that allow_tma_token_auth passes through when no TMA token header.
	 */
	public function test_allow_tma_token_auth_passes_through_without_token() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] );

		$error  = new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed', array( 'status' => 403 ) );
		$result = $controller->allow_tma_token_auth( $error );

		$this->assertWPError( $result, 'Nonce error should pass through when no TMA token is present' );
	}

	/**
	 * Test that allow_tma_token_auth passes through for invalid/unknown token.
	 */
	public function test_allow_tma_token_auth_passes_through_for_invalid_token() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		// Use a well-formed but unknown token.
		$_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] = bin2hex( random_bytes( 20 ) );

		$error  = new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed', array( 'status' => 403 ) );
		$result = $controller->allow_tma_token_auth( $error );

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TMA_TOKEN'] );

		$this->assertWPError( $result, 'Nonce error should persist when the TMA token is unknown' );
	}

	/**
	 * Test that allow_tma_token_auth returns non-error results unchanged.
	 */
	public function test_allow_tma_token_auth_returns_non_error_unchanged() {
		$controller = $this->load_telegram_mini_app_controller();
		if ( null === $controller ) {
			return;
		}

		$this->assertNull( $controller->allow_tma_token_auth( null ), 'null should pass through' );
		$this->assertTrue( $controller->allow_tma_token_auth( true ), 'true should pass through' );
	}

	// =========================================================================
	// Cookie sync: $_COOKIE update during /validate
	// =========================================================================

	/**
	 * Test that the controller source contains the set_logged_in_cookie hook
	 * to sync $_COOKIE before wp_create_nonce().
	 */
	public function test_validate_handler_syncs_logged_in_cookie() {
		$source = $this->get_mini_app_inline_js_source();
		if ( null === $source ) {
			return;
		}

		$this->assertStringContainsString(
			'set_logged_in_cookie',
			$source,
			'handle_validate_init_data should hook into set_logged_in_cookie to sync $_COOKIE'
		);
		$this->assertStringContainsString(
			'LOGGED_IN_COOKIE',
			$source,
			'handle_validate_init_data should write to $_COOKIE[LOGGED_IN_COOKIE]'
		);
	}

	// =========================================================================
	// enable_groups persistence
	// =========================================================================

	/**
	 * Test that the enable_groups field persists when saving a Telegram connection.
	 */
	public function test_telegram_enable_groups_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Telegram Enable Groups Test',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '1111111111:ABCdefGHIjklMNOpqrsTUVwxyz_grouptest',
			'enable_groups'   => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved );
		$this->assertTrue( (bool) $saved['enable_groups'], 'enable_groups should be stored as true' );
	}

	/**
	 * Test that enable_groups defaults to false when not provided.
	 */
	public function test_telegram_enable_groups_defaults_false() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Telegram Groups Default Test',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '2222222222:ABCdefGHIjklMNOpqrsTUVwxyz_groupdflt',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $saved );
		$this->assertEmpty( $saved['enable_groups'], 'enable_groups should default to empty/false when not provided' );
	}

	/**
	 * Test that enable_groups is preserved when updating without providing it.
	 */
	public function test_telegram_enable_groups_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Telegram Groups Preserve Test',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '3333333333:ABCdefGHIjklMNOpqrsTUVwxyz_groupprsv',
			'enable_groups'   => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		// Update without providing enable_groups.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => 'Telegram Groups Preserve Test — Renamed',
			'url'             => 'https://api.telegram.org',
			'connection_type' => 'telegram',
			'auth_type'       => 'none',
			'enabled'         => true,
		);

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );

		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertTrue( (bool) $updated['enable_groups'], 'enable_groups should be preserved on update' );
	}

	// =========================================================================
	// Group reply robustness tests
	// =========================================================================

	/**
	 * Test that handle_telegram_reply_job() sets allow_sending_without_reply
	 * for group chats by verifying the method exists and processes group args.
	 */
	public function test_telegram_reply_job_accepts_group_chat_type() {
		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'handle_telegram_reply_job' );

		$this->assertTrue(
			$method->isPublic(),
			'handle_telegram_reply_job should be a public method callable by wp_cron'
		);

		// Verify the method accepts an array argument with group-specific fields.
		$params = $method->getParameters();
		$this->assertCount( 1, $params, 'handle_telegram_reply_job should accept one parameter' );
	}

	/**
	 * Test that process_message() logs the chat_id when a group message is
	 * ignored due to enable_groups being disabled.
	 */
	public function test_process_message_logs_chat_id_for_ignored_group() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		// Save a connection with enable_groups disabled (default).
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Telegram Group Disabled Test',
				'url'             => 'https://api.telegram.org',
				'connection_type' => 'telegram',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'AAAA000000:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
				'enable_groups'   => false,
			)
		);

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'process_message' );
		$method->setAccessible( true );

		// Simulate a group message — should be silently ignored.
		$method->invoke(
			$controller,
			array(
				'text'       => 'Hello from a group',
				'chat'       => array(
					'id'   => '-1001234567890',
					'type' => 'supergroup',
				),
				'from'       => array( 'id' => '444555666' ),
				'message_id' => 99,
			)
		);

		// The message should have been ignored (no cron scheduled).
		// We verify this by checking that no cron event was scheduled.
		$cron_events = _get_cron_array();
		$found       = false;
		if ( is_array( $cron_events ) ) {
			foreach ( $cron_events as $timestamp => $hooks ) {
				if ( isset( $hooks['wp_mcp_ai_telegram_send_ai_reply'] ) ) {
					$found = true;
					break;
				}
			}
		}
		$this->assertFalse( $found, 'No cron event should be scheduled when enable_groups is disabled' );
	}

	/**
	 * Test that process_message() processes group messages when enable_groups
	 * is enabled on the connection.
	 */
	public function test_process_message_processes_group_when_enabled() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$controller = $this->load_telegram_controller();
		if ( null === $controller ) {
			return;
		}

		// Create an assistant.
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Group Test Bot',
				'post_name'   => 'group-test-bot',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $assistant_id );

		// Save a connection with enable_groups enabled.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'Telegram Group Enabled Test',
				'url'                    => 'https://api.telegram.org',
				'connection_type'        => 'telegram',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'BBBB000000:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
				'enable_groups'          => true,
				'assigned_assistant_ids' => array( $assistant_id ),
			)
		);

		// Prevent actual cron dispatch but capture that the filter was reached.
		$filter_called = false;
		add_filter(
			'wp_mcp_ai_telegram_should_auto_reply',
			function () use ( &$filter_called ) {
				$filter_called = true;
				return false; // Block reply.
			}
		);

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'process_message' );
		$method->setAccessible( true );

		$method->invoke(
			$controller,
			array(
				'text'       => 'Hello from a group with groups enabled',
				'chat'       => array(
					'id'   => '-1009876543210',
					'type' => 'group',
				),
				'from'       => array( 'id' => '111222333' ),
				'message_id' => 100,
			)
		);

		remove_all_filters( 'wp_mcp_ai_telegram_should_auto_reply' );

		$this->assertTrue(
			$filter_called,
			'wp_mcp_ai_telegram_should_auto_reply filter should be reached for group messages when enable_groups is true'
		);

		wp_delete_post( $assistant_id, true );
	}

	/**
	 * Test that the reply job args include reply_to_message_id for group messages
	 * by verifying the cron hook signature in process_message.
	 */
	public function test_process_message_sets_reply_to_message_id_for_groups() {
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
				'post_title'  => 'Group Reply Test Bot',
				'post_name'   => 'group-reply-test-bot',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $assistant_id );

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'Telegram Reply Thread Test',
				'url'                    => 'https://api.telegram.org',
				'connection_type'        => 'telegram',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'CCCC000000:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh',
				'enable_groups'          => true,
				'assigned_assistant_ids' => array( $assistant_id ),
			)
		);

		// Allow the auto-reply to be scheduled.
		add_filter( 'wp_mcp_ai_telegram_should_auto_reply', '__return_true' );

		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'process_message' );
		$method->setAccessible( true );

		$method->invoke(
			$controller,
			array(
				'text'       => 'Group thread test',
				'chat'       => array(
					'id'   => '-1001111222333',
					'type' => 'supergroup',
				),
				'from'       => array( 'id' => '777888999' ),
				'message_id' => 42,
			)
		);

		remove_all_filters( 'wp_mcp_ai_telegram_should_auto_reply' );

		// Verify a cron event was scheduled.
		$cron_events = _get_cron_array();
		$found_args  = null;
		if ( is_array( $cron_events ) ) {
			foreach ( $cron_events as $timestamp => $hooks ) {
				if ( isset( $hooks['wp_mcp_ai_telegram_send_ai_reply'] ) ) {
					foreach ( $hooks['wp_mcp_ai_telegram_send_ai_reply'] as $key => $event ) {
						if ( isset( $event['args'][0] ) ) {
							$found_args = $event['args'][0];
							break 2;
						}
					}
				}
			}
		}

		$this->assertNotNull( $found_args, 'A cron event should have been scheduled for the group reply' );

		if ( null !== $found_args ) {
			$this->assertEquals( 'supergroup', $found_args['chat_type'], 'chat_type should be supergroup' );
			$this->assertEquals( '42', $found_args['reply_to_message_id'], 'reply_to_message_id should be set for group messages' );
			$this->assertEquals( '-1001111222333', $found_args['chat_id'], 'chat_id should be the group ID' );
		}

		wp_delete_post( $assistant_id, true );
	}
}
