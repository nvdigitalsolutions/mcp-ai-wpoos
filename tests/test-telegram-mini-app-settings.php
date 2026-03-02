<?php
/**
 * Test Telegram Mini App Settings tab and group/channel webhook support.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Telegram Mini App Settings endpoints and webhook enhancements.
 */
class Test_Telegram_Mini_App_Settings extends WP_UnitTestCase {

	/**
	 * Controller instance.
	 *
	 * @var WP_MCP_AI_Telegram_Mini_App_Controller|null
	 */
	private $controller;

	/**
	 * Webhook controller instance.
	 *
	 * @var WP_MCP_AI_Telegram_Webhook_Controller|null
	 */
	private $webhook_controller;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-mini-app-controller.php';
		if ( file_exists( $controller_file ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Telegram_Mini_App_Controller' ) ) {
				require_once $controller_file;
			}
			$this->controller = new WP_MCP_AI_Telegram_Mini_App_Controller();
		}

		$webhook_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php';
		if ( file_exists( $webhook_file ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Telegram_Webhook_Controller' ) ) {
				require_once $webhook_file;
			}
			$this->webhook_controller = new WP_MCP_AI_Telegram_Webhook_Controller();
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		parent::tearDown();
	}

	// =========================================================================
	// Mini App Settings – GET endpoint
	// =========================================================================

	/**
	 * Test that the settings endpoint returns expected structure.
	 */
	public function test_get_settings_returns_expected_keys() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/settings' );
		$response = $this->controller->handle_get_settings( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'wp_linked', $data );
		$this->assertArrayHasKey( 'wp_username', $data );
		$this->assertArrayHasKey( 'wp_display_name', $data );
		$this->assertArrayHasKey( 'preferences', $data );
		$this->assertArrayHasKey( 'group_settings', $data );
	}

	/**
	 * Test that preferences include default values.
	 */
	public function test_get_settings_returns_default_preferences() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/settings' );
		$response = $this->controller->handle_get_settings( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data['preferences'] );
		$this->assertEquals( 'auto', $data['preferences']['language'] );
		$this->assertTrue( $data['preferences']['notifications'] );
		$this->assertFalse( $data['preferences']['compact_mode'] );
	}

	/**
	 * Test wp_linked returns true when Telegram meta is set.
	 */
	public function test_get_settings_wp_linked_true() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, '_wp_mcp_ai_telegram_id', '12345678' );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/settings' );
		$response = $this->controller->handle_get_settings( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['wp_linked'] );

		delete_user_meta( $user_id, '_wp_mcp_ai_telegram_id' );
	}

	/**
	 * Test that group_settings includes expected keys.
	 */
	public function test_get_settings_includes_group_settings() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/settings' );
		$response = $this->controller->handle_get_settings( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'enable_groups', $data['group_settings'] );
		$this->assertArrayHasKey( 'enable_channels', $data['group_settings'] );
		$this->assertArrayHasKey( 'require_mention', $data['group_settings'] );
	}

	// =========================================================================
	// Mini App Settings – POST / save_preferences
	// =========================================================================

	/**
	 * Test saving preferences via the settings endpoint.
	 */
	public function test_save_preferences() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/telegram-mini-app/settings' );
		$request->set_body_params(
			array(
				'action'      => 'save_preferences',
				'preferences' => array(
					'language'      => 'es',
					'notifications' => false,
					'compact_mode'  => true,
				),
			)
		);

		$response = $this->controller->handle_save_settings( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );

		// Verify the preferences were saved.
		$saved = get_user_meta( $user_id, '_wp_mcp_ai_tma_preferences', true );
		$this->assertEquals( 'es', $saved['language'] );
		$this->assertFalse( $saved['notifications'] );
		$this->assertTrue( $saved['compact_mode'] );

		delete_user_meta( $user_id, '_wp_mcp_ai_tma_preferences' );
	}

	/**
	 * Test that invalid action returns error.
	 */
	public function test_invalid_action_returns_error() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/telegram-mini-app/settings' );
		$request->set_body_params( array( 'action' => 'not_a_valid_action' ) );

		$response = $this->controller->handle_save_settings( $request );

		$this->assertInstanceOf( 'WP_Error', $response );
	}

	// =========================================================================
	// Mini App Settings – POST / unlink_account
	// =========================================================================

	/**
	 * Test unlinking Telegram from WordPress account.
	 */
	public function test_unlink_account() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, '_wp_mcp_ai_telegram_id', '99887766' );
		update_user_meta( $user_id, '_wp_mcp_ai_telegram_username', 'testuser' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/telegram-mini-app/settings' );
		$request->set_body_params( array( 'action' => 'unlink_account' ) );

		$response = $this->controller->handle_save_settings( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );

		// Verify meta was removed.
		$this->assertEmpty( get_user_meta( $user_id, '_wp_mcp_ai_telegram_id', true ) );
		$this->assertEmpty( get_user_meta( $user_id, '_wp_mcp_ai_telegram_username', true ) );
	}

	// =========================================================================
	// Mini App Settings – POST / link_account
	// =========================================================================

	/**
	 * Test linking fails with empty credentials.
	 */
	public function test_link_account_requires_credentials() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/telegram-mini-app/settings' );
		$request->set_body_params(
			array(
				'action'   => 'link_account',
				'username' => '',
				'password' => '',
			)
		);

		$response = $this->controller->handle_save_settings( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['success'] );
	}

	// =========================================================================
	// Webhook – Group/channel support
	// =========================================================================

	/**
	 * Test that the webhook controller class exists with group/channel methods.
	 */
	public function test_webhook_controller_has_group_channel_methods() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$reflection = new ReflectionClass( $this->webhook_controller );

		$this->assertTrue( $reflection->hasMethod( 'process_channel_post' ), 'Should have process_channel_post method' );
		$this->assertTrue( $reflection->hasMethod( 'process_membership_update' ), 'Should have process_membership_update method' );
		$this->assertTrue( $reflection->hasMethod( 'message_mentions_bot' ), 'Should have message_mentions_bot method' );
		$this->assertTrue( $reflection->hasMethod( 'is_reply_to_bot' ), 'Should have is_reply_to_bot method' );
		$this->assertTrue( $reflection->hasMethod( 'strip_bot_mention' ), 'Should have strip_bot_mention method' );
		$this->assertTrue( $reflection->hasMethod( 'resolve_assistant_ids' ), 'Should have resolve_assistant_ids method' );
	}

	/**
	 * Test message_mentions_bot detects @bot_username.
	 */
	public function test_message_mentions_bot() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$method = new ReflectionMethod( $this->webhook_controller, 'message_mentions_bot' );
		$method->setAccessible( true );

		$connection = array( 'bot_username' => '@test_bot' );

		$this->assertTrue( $method->invoke( $this->webhook_controller, 'Hello @test_bot how are you?', $connection ) );
		$this->assertTrue( $method->invoke( $this->webhook_controller, '@test_bot help me', $connection ) );
		$this->assertFalse( $method->invoke( $this->webhook_controller, 'Hello world', $connection ) );
		$this->assertFalse( $method->invoke( $this->webhook_controller, 'Hello @other_bot', $connection ) );
	}

	/**
	 * Test strip_bot_mention removes the @bot_username from text.
	 */
	public function test_strip_bot_mention() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$method = new ReflectionMethod( $this->webhook_controller, 'strip_bot_mention' );
		$method->setAccessible( true );

		$connection = array( 'bot_username' => '@my_bot' );

		$this->assertEquals( 'what is the weather?', $method->invoke( $this->webhook_controller, '@my_bot what is the weather?', $connection ) );
		$this->assertEquals( 'hello world', $method->invoke( $this->webhook_controller, 'hello world', $connection ) );
	}

	/**
	 * Test is_reply_to_bot detects replies to bot messages.
	 */
	public function test_is_reply_to_bot() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$method = new ReflectionMethod( $this->webhook_controller, 'is_reply_to_bot' );
		$method->setAccessible( true );

		$connection = array( 'bot_username' => '@my_assistant_bot' );

		// Message is a reply to a bot with matching username.
		$message_reply = array(
			'text' => 'Thanks!',
			'reply_to_message' => array(
				'from' => array(
					'is_bot'   => true,
					'username' => 'my_assistant_bot',
				),
			),
		);
		$this->assertTrue( $method->invoke( $this->webhook_controller, $message_reply, $connection ) );

		// Message is a reply to a different bot.
		$message_other_bot = array(
			'text' => 'Thanks!',
			'reply_to_message' => array(
				'from' => array(
					'is_bot'   => true,
					'username' => 'some_other_bot',
				),
			),
		);
		$this->assertFalse( $method->invoke( $this->webhook_controller, $message_other_bot, $connection ) );

		// Message is not a reply.
		$message_no_reply = array( 'text' => 'Hello' );
		$this->assertFalse( $method->invoke( $this->webhook_controller, $message_no_reply, $connection ) );
	}

	/**
	 * Test resolve_assistant_ids falls back through the resolution chain.
	 */
	public function test_resolve_assistant_ids_fallback() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$method = new ReflectionMethod( $this->webhook_controller, 'resolve_assistant_ids' );
		$method->setAccessible( true );

		// Connection with explicit IDs.
		$connection = array( 'assigned_assistant_ids' => array( 42, 99 ) );
		$result     = $method->invoke( $this->webhook_controller, $connection, array() );
		$this->assertEquals( array( 42, 99 ), $result );

		// Connection without IDs; automation rules default.
		$connection = array();
		$rules      = array( 'default_assistant_id' => 55 );
		$result     = $method->invoke( $this->webhook_controller, $connection, $rules );
		$this->assertEquals( array( 55 ), $result );
	}

	/**
	 * Test that the webhook handler processes channel_post updates.
	 */
	public function test_handle_webhook_routes_channel_post() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		// The handler should return 200 OK (no error) even for channel posts
		// when no connection is configured.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/telegram' );
		$request->set_body( wp_json_encode(
			array(
				'update_id'    => 999001,
				'channel_post' => array(
					'message_id' => 1,
					'chat'       => array(
						'id'    => -1001234567890,
						'type'  => 'channel',
						'title' => 'Test Channel',
					),
					'date' => time(),
					'text' => 'Hello from channel',
				),
			)
		) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->webhook_controller->handle_webhook( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['ok'] );
	}

	/**
	 * Test that the webhook handler processes my_chat_member updates.
	 */
	public function test_handle_webhook_routes_membership_update() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/telegram' );
		$request->set_body( wp_json_encode(
			array(
				'update_id'      => 999002,
				'my_chat_member' => array(
					'chat' => array(
						'id'    => -1001234567890,
						'type'  => 'supergroup',
						'title' => 'Test Group',
					),
					'from' => array( 'id' => 123, 'first_name' => 'Admin' ),
					'old_chat_member' => array( 'status' => 'left' ),
					'new_chat_member' => array( 'status' => 'member' ),
				),
			)
		) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->webhook_controller->handle_webhook( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['ok'] );
	}
}
