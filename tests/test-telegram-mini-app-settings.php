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
			'text'             => 'Thanks!',
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
			'text'             => 'Thanks!',
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
		$request->set_body(
			wp_json_encode(
				array(
					'update_id'    => 999001,
					'channel_post' => array(
						'message_id' => 1,
						'chat'       => array(
							'id'    => -1001234567890,
							'type'  => 'channel',
							'title' => 'Test Channel',
						),
						'date'       => time(),
						'text'       => 'Hello from channel',
					),
				)
			)
		);
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
		$request->set_body(
			wp_json_encode(
				array(
					'update_id'      => 999002,
					'my_chat_member' => array(
						'chat'            => array(
							'id'    => -1001234567890,
							'type'  => 'supergroup',
							'title' => 'Test Group',
						),
						'from'            => array(
							'id'         => 123,
							'first_name' => 'Admin',
						),
						'old_chat_member' => array( 'status' => 'left' ),
						'new_chat_member' => array( 'status' => 'member' ),
					),
				)
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->webhook_controller->handle_webhook( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['ok'] );
	}

	// =========================================================================
	// Slash command parsing & routing
	// =========================================================================

	/**
	 * Test parse_bot_command extracts command from entities.
	 */
	public function test_parse_bot_command_with_entities() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$method = new ReflectionMethod( $this->webhook_controller, 'parse_bot_command' );
		$method->setAccessible( true );

		$message = array(
			'text'     => '/help some arguments',
			'entities' => array(
				array(
					'type'   => 'bot_command',
					'offset' => 0,
					'length' => 5,
				),
			),
		);

		$result = $method->invoke( $this->webhook_controller, $message );

		$this->assertIsArray( $result );
		$this->assertEquals( 'help', $result['command'] );
		$this->assertEquals( 'some arguments', $result['args'] );
	}

	/**
	 * Test parse_bot_command handles @bot_username suffix.
	 */
	public function test_parse_bot_command_strips_bot_username() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$method = new ReflectionMethod( $this->webhook_controller, 'parse_bot_command' );
		$method->setAccessible( true );

		$message = array(
			'text'     => '/start@my_cool_bot deep_link_param',
			'entities' => array(
				array(
					'type'   => 'bot_command',
					'offset' => 0,
					'length' => 20,
				),
			),
		);

		$result = $method->invoke( $this->webhook_controller, $message );

		$this->assertIsArray( $result );
		$this->assertEquals( 'start', $result['command'] );
		$this->assertEquals( 'deep_link_param', $result['args'] );
	}

	/**
	 * Test parse_bot_command falls back to / prefix when no entities.
	 */
	public function test_parse_bot_command_fallback_prefix() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$method = new ReflectionMethod( $this->webhook_controller, 'parse_bot_command' );
		$method->setAccessible( true );

		$message = array( 'text' => '/cancel' );

		$result = $method->invoke( $this->webhook_controller, $message );

		$this->assertIsArray( $result );
		$this->assertEquals( 'cancel', $result['command'] );
		$this->assertEquals( '', $result['args'] );
	}

	/**
	 * Test parse_bot_command returns null for non-command messages.
	 */
	public function test_parse_bot_command_returns_null_for_text() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$method = new ReflectionMethod( $this->webhook_controller, 'parse_bot_command' );
		$method->setAccessible( true );

		$message = array( 'text' => 'Hello, how are you?' );

		$result = $method->invoke( $this->webhook_controller, $message );
		$this->assertNull( $result );
	}

	/**
	 * Test parse_bot_command ignores commands not at offset 0.
	 */
	public function test_parse_bot_command_ignores_mid_text_commands() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$method = new ReflectionMethod( $this->webhook_controller, 'parse_bot_command' );
		$method->setAccessible( true );

		$message = array(
			'text'     => 'Please try /help',
			'entities' => array(
				array(
					'type'   => 'bot_command',
					'offset' => 11,
					'length' => 5,
				),
			),
		);

		$result = $method->invoke( $this->webhook_controller, $message );
		$this->assertNull( $result );
	}

	/**
	 * Test get_default_commands returns expected structure.
	 */
	public function test_get_default_commands() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller not available.' );
			return;
		}

		$commands = WP_MCP_AI_Telegram_Webhook_Controller::get_default_commands();

		$this->assertIsArray( $commands );
		$this->assertNotEmpty( $commands );

		$command_names = array_column( $commands, 'command' );
		$this->assertContains( 'start', $command_names );
		$this->assertContains( 'help', $command_names );
		$this->assertContains( 'settings', $command_names );
		$this->assertContains( 'status', $command_names );
		$this->assertContains( 'cancel', $command_names );

		// Each command must have 'command' and 'description' keys.
		foreach ( $commands as $cmd ) {
			$this->assertArrayHasKey( 'command', $cmd );
			$this->assertArrayHasKey( 'description', $cmd );
			$this->assertNotEmpty( $cmd['description'] );
		}
	}

	/**
	 * Test that the manage_telegram_commands tool class exists.
	 */
	public function test_manage_telegram_commands_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-manage-telegram-commands.php';

		if ( ! file_exists( $tool_file ) ) {
			$this->markTestSkipped( 'Manage Telegram commands tool file not found.' );
			return;
		}

		require_once $tool_file;

		$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_Tool_Manage_Telegram_Commands' ) );

		$tool = new WP_MCP_AI_Pro_Tool_Manage_Telegram_Commands();
		$this->assertEquals( 'manage_telegram_commands', $tool->get_slug() );

		$schema = $tool->get_parameters_schema();
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'token', $schema['properties'] );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertArrayHasKey( 'scope', $schema['properties'] );
		$this->assertArrayHasKey( 'commands', $schema['properties'] );

		// Verify action enum includes all three operations.
		$this->assertEquals( array( 'set', 'delete', 'get' ), $schema['properties']['action']['enum'] );
	}

	// =========================================================================
	// Telegram Stars / Payments / Inline settings schema
	// =========================================================================

	/**
	 * Test that Telegram enhancement settings can be stored and retrieved.
	 */
	public function test_telegram_enhancement_settings_schema() {
		$settings = array(
			'stars_enabled'          => true,
			'stars_pricing'          => array(
				array(
					'credits' => 1000,
					'stars'   => 100,
				),
				array(
					'credits' => 10000,
					'stars'   => 500,
				),
				array(
					'credits' => 100000,
					'stars'   => 2500,
				),
			),
			'subscriptions_enabled'  => true,
			'subscription_plans'     => array(
				array(
					'slug'   => 'single_toolkit',
					'stars'  => 200,
					'period' => 'monthly',
				),
				array(
					'slug'   => 'pro_bundle',
					'stars'  => 800,
					'period' => 'monthly',
				),
			),
			'inline_mode_enabled'    => true,
			'inline_cache_time'      => 300,
			'deep_linking_enabled'   => true,
			'referral_enabled'       => false,
			'referral_bonus_credits' => 500,
			'payment_provider_token' => '',
			'fullscreen_toolkits'    => array(
				'document_generation',
				'image_production',
				'video_production',
				'architectural_design',
			),
		);

		update_option( 'wp_mcp_ai_telegram_settings', $settings );
		$saved = get_option( 'wp_mcp_ai_telegram_settings' );

		$this->assertIsArray( $saved );
		$this->assertTrue( $saved['stars_enabled'] );
		$this->assertCount( 3, $saved['stars_pricing'] );
		$this->assertEquals( 1000, $saved['stars_pricing'][0]['credits'] );
		$this->assertEquals( 100, $saved['stars_pricing'][0]['stars'] );
		$this->assertTrue( $saved['subscriptions_enabled'] );
		$this->assertCount( 2, $saved['subscription_plans'] );
		$this->assertEquals( 'single_toolkit', $saved['subscription_plans'][0]['slug'] );
		$this->assertTrue( $saved['inline_mode_enabled'] );
		$this->assertEquals( 300, $saved['inline_cache_time'] );
		$this->assertTrue( $saved['deep_linking_enabled'] );
		$this->assertFalse( $saved['referral_enabled'] );
		$this->assertEquals( 500, $saved['referral_bonus_credits'] );
		$this->assertEmpty( $saved['payment_provider_token'] );
		$this->assertCount( 4, $saved['fullscreen_toolkits'] );
		$this->assertContains( 'document_generation', $saved['fullscreen_toolkits'] );

		delete_option( 'wp_mcp_ai_telegram_settings' );
	}

	/**
	 * Test that Stars user meta fields can be stored per user.
	 */
	public function test_stars_user_meta_fields() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		update_user_meta( $user_id, '_wp_mcp_ai_tg_stars_balance', 1250 );
		update_user_meta( $user_id, '_wp_mcp_ai_tg_subscription_plan', 'pro_bundle' );
		update_user_meta( $user_id, '_wp_mcp_ai_tg_subscription_expires', 1743552000 );
		update_user_meta( $user_id, '_wp_mcp_ai_tg_referral_code', 'REF_abc123' );
		update_user_meta( $user_id, '_wp_mcp_ai_tg_pinned_toolkits', array( 'crm', 'ecommerce', 'analytics' ) );
		update_user_meta(
			$user_id,
			'_wp_mcp_ai_tg_quick_actions',
			array(
				'create_post',
				'view_orders',
				'generate_doc',
				'search_contacts',
				'check_analytics',
				'manage_products',
			)
		);

		$this->assertEquals( 1250, (int) get_user_meta( $user_id, '_wp_mcp_ai_tg_stars_balance', true ) );
		$this->assertEquals( 'pro_bundle', get_user_meta( $user_id, '_wp_mcp_ai_tg_subscription_plan', true ) );
		$this->assertEquals( 1743552000, (int) get_user_meta( $user_id, '_wp_mcp_ai_tg_subscription_expires', true ) );
		$this->assertEquals( 'REF_abc123', get_user_meta( $user_id, '_wp_mcp_ai_tg_referral_code', true ) );

		$pinned = get_user_meta( $user_id, '_wp_mcp_ai_tg_pinned_toolkits', true );
		$this->assertCount( 3, $pinned );
		$this->assertContains( 'crm', $pinned );

		$actions = get_user_meta( $user_id, '_wp_mcp_ai_tg_quick_actions', true );
		$this->assertCount( 6, $actions );
		$this->assertContains( 'create_post', $actions );

		// Cleanup.
		delete_user_meta( $user_id, '_wp_mcp_ai_tg_stars_balance' );
		delete_user_meta( $user_id, '_wp_mcp_ai_tg_subscription_plan' );
		delete_user_meta( $user_id, '_wp_mcp_ai_tg_subscription_expires' );
		delete_user_meta( $user_id, '_wp_mcp_ai_tg_referral_code' );
		delete_user_meta( $user_id, '_wp_mcp_ai_tg_pinned_toolkits' );
		delete_user_meta( $user_id, '_wp_mcp_ai_tg_quick_actions' );
	}

	/**
	 * Test that payment history can be stored and appended.
	 */
	public function test_payment_history_storage() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$history = array(
			array(
				'type'      => 'stars',
				'amount'    => 500,
				'credits'   => 10000,
				'timestamp' => 1709352000,
				'status'    => 'completed',
			),
		);

		update_user_meta( $user_id, '_wp_mcp_ai_tg_payment_history', $history );

		// Append a second payment.
		$saved   = get_user_meta( $user_id, '_wp_mcp_ai_tg_payment_history', true );
		$saved[] = array(
			'type'      => 'subscription',
			'amount'    => 800,
			'plan'      => 'pro_bundle',
			'timestamp' => 1709438400,
			'status'    => 'completed',
		);
		update_user_meta( $user_id, '_wp_mcp_ai_tg_payment_history', $saved );

		$final = get_user_meta( $user_id, '_wp_mcp_ai_tg_payment_history', true );
		$this->assertCount( 2, $final );
		$this->assertEquals( 'stars', $final[0]['type'] );
		$this->assertEquals( 'subscription', $final[1]['type'] );
		$this->assertEquals( 10000, $final[0]['credits'] );
		$this->assertEquals( 'pro_bundle', $final[1]['plan'] );

		delete_user_meta( $user_id, '_wp_mcp_ai_tg_payment_history' );
	}

	// =========================================================================
	// Content Update Endpoint Tests
	// =========================================================================

	/**
	 * Test handle_update_content creates a new post when id=0.
	 */
	public function test_handle_update_content_creates_post() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller unavailable.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/telegram-mini-app/content' );
		$request->set_param( 'id', 0 );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'title', 'Test Mini App Post' );
		$request->set_param( 'content', '<p>Hello from the Mini App</p>' );
		$request->set_param( 'status', 'draft' );

		$response = $this->controller->handle_update_content( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertGreaterThan( 0, $data['id'] );

		$post = get_post( $data['id'] );
		$this->assertEquals( 'Test Mini App Post', $post->post_title );
		$this->assertEquals( 'draft', $post->post_status );
		$this->assertStringContainsString( 'Hello from the Mini App', $post->post_content );

		wp_delete_post( $data['id'], true );
	}

	/**
	 * Test handle_update_content updates an existing post.
	 */
	public function test_handle_update_content_updates_post() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller unavailable.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Original Title',
				'post_content' => 'Original content',
				'post_status'  => 'draft',
				'post_author'  => $user,
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/telegram-mini-app/content' );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'title', 'Updated Title' );
		$request->set_param( 'content', '<p>Updated content</p>' );
		$request->set_param( 'status', 'publish' );

		$response = $this->controller->handle_update_content( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertEquals( $post_id, $data['id'] );

		$post = get_post( $post_id );
		$this->assertEquals( 'Updated Title', $post->post_title );
		$this->assertEquals( 'publish', $post->post_status );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test handle_update_content rejects invalid status.
	 */
	public function test_handle_update_content_sanitizes_status() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller unavailable.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/telegram-mini-app/content' );
		$request->set_param( 'id', 0 );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'title', 'Status Test' );
		$request->set_param( 'content', '' );
		$request->set_param( 'status', 'private' ); // Not in allowed list.

		$response = $this->controller->handle_update_content( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$post = get_post( $data['id'] );
		$this->assertEquals( 'draft', $post->post_status ); // Falls back to draft.

		wp_delete_post( $data['id'], true );
	}

	// =========================================================================
	// Content Visibility Tests
	// =========================================================================

	/**
	 * Test enabled_post_types preference can be saved and loaded.
	 */
	public function test_enabled_post_types_preference() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$prefs = array(
			'language'           => 'en',
			'notifications'      => true,
			'compact_mode'       => false,
			'enabled_post_types' => array( 'post', 'page', 'mcp_ai_contact' ),
		);
		update_user_meta( $user_id, '_wp_mcp_ai_tma_preferences', $prefs );

		$stored = get_user_meta( $user_id, '_wp_mcp_ai_tma_preferences', true );
		$this->assertIsArray( $stored['enabled_post_types'] );
		$this->assertCount( 3, $stored['enabled_post_types'] );
		$this->assertContains( 'post', $stored['enabled_post_types'] );
		$this->assertContains( 'mcp_ai_contact', $stored['enabled_post_types'] );

		delete_user_meta( $user_id, '_wp_mcp_ai_tma_preferences' );
	}

	/**
	 * Test null enabled_post_types means show all (default).
	 */
	public function test_enabled_post_types_null_shows_all() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$prefs = array(
			'language'      => 'auto',
			'notifications' => true,
			'compact_mode'  => false,
		);
		update_user_meta( $user_id, '_wp_mcp_ai_tma_preferences', $prefs );

		$stored = get_user_meta( $user_id, '_wp_mcp_ai_tma_preferences', true );
		$this->assertArrayNotHasKey( 'enabled_post_types', $stored );

		delete_user_meta( $user_id, '_wp_mcp_ai_tma_preferences' );
	}

	// =========================================================================
	// Shop Balance Endpoint Tests
	// =========================================================================

	/**
	 * Test handle_shop_balance returns balance and pricing data.
	 */
	public function test_handle_shop_balance() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller unavailable.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		update_user_meta( $user, '_wp_mcp_ai_tma_stars_balance', 250 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/shop/balance' );
		$response = $this->controller->handle_shop_balance( $request );
		$data     = $response->get_data();

		$this->assertEquals( 250, $data['balance'] );
		$this->assertIsArray( $data['pricing'] );
		$this->assertGreaterThanOrEqual( 1, count( $data['pricing'] ) );
		$this->assertIsArray( $data['recent_payments'] );

		delete_user_meta( $user, '_wp_mcp_ai_tma_stars_balance' );
	}

	/**
	 * Test shop balance returns zero for new users.
	 */
	public function test_handle_shop_balance_default_zero() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller unavailable.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-mini-app/shop/balance' );
		$response = $this->controller->handle_shop_balance( $request );
		$data     = $response->get_data();

		$this->assertEquals( 0, $data['balance'] );
	}

	// =========================================================================
	// Webhook New Commands Tests
	// =========================================================================

	/**
	 * Test get_default_commands includes new commands.
	 */
	public function test_default_commands_include_new_ones() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller unavailable.' );
		}

		$commands     = WP_MCP_AI_Telegram_Webhook_Controller::get_default_commands();
		$command_list = wp_list_pluck( $commands, 'command' );

		$this->assertContains( 'tools', $command_list );
		$this->assertContains( 'balance', $command_list );
		$this->assertContains( 'app', $command_list );
		// Original commands still present.
		$this->assertContains( 'start', $command_list );
		$this->assertContains( 'help', $command_list );
		$this->assertContains( 'settings', $command_list );
		$this->assertContains( 'status', $command_list );
		$this->assertContains( 'cancel', $command_list );
	}

	/**
	 * Test resolve_wp_user_from_telegram_id helper.
	 */
	public function test_resolve_wp_user_from_telegram_id() {
		if ( ! $this->webhook_controller ) {
			$this->markTestSkipped( 'Webhook controller unavailable.' );
		}

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user, '_wp_mcp_ai_telegram_id', '123456789' );

		// Use reflection to call the protected method.
		$method = new ReflectionMethod( $this->webhook_controller, 'resolve_wp_user_from_telegram_id' );
		$method->setAccessible( true );

		$resolved = $method->invoke( $this->webhook_controller, '123456789' );
		$this->assertEquals( $user, $resolved );

		$not_found = $method->invoke( $this->webhook_controller, '999999999' );
		$this->assertNull( $not_found );

		delete_user_meta( $user, '_wp_mcp_ai_telegram_id' );
	}

	/**
	 * Test Stars balance is stored and retrieved correctly.
	 */
	public function test_stars_balance_storage() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Initial balance should be 0.
		$balance = (int) get_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', true );
		$this->assertEquals( 0, $balance );

		// Credit the user.
		update_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', 500 );
		$balance = (int) get_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', true );
		$this->assertEquals( 500, $balance );

		// Add more credits.
		$current = (int) get_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', true );
		update_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', $current + 200 );
		$balance = (int) get_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', true );
		$this->assertEquals( 700, $balance );

		delete_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance' );
	}

	/**
	 * Test that the local Chart.js vendor file exists.
	 */
	public function test_local_chart_js_file_exists() {
		$chart_path = WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js';
		$this->assertFileExists( $chart_path, 'Local Chart.js vendor file should exist at assets/js/vendor/chart.min.js' );
	}

	/**
	 * Return the controller source for static analysis tests.
	 *
	 * @return string
	 */
	private function get_controller_source() {
		static $source = null;
		if ( null === $source ) {
			$source = file_get_contents(
				WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-mini-app-controller.php'
			);
		}
		return $source;
	}

	/**
	 * Test that handle_mini_app references local Chart.js URL instead of CDN.
	 *
	 * The Telegram WebView can block CDN requests or fail SRI checks, so
	 * Chart.js must be served from the plugin's own assets directory.
	 */
	public function test_mini_app_uses_local_chart_js() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$source = $this->get_controller_source();

		$this->assertStringNotContainsString(
			'cdnjs.cloudflare.com/ajax/libs/Chart.js',
			$source,
			'Mini App controller should not load Chart.js from the CDN'
		);

		$this->assertStringContainsString(
			'assets/js/vendor/chart.min.js',
			$source,
			'Mini App controller should reference the local Chart.js file'
		);
	}

	/**
	 * Test that handle_mini_app sets no-cache headers to prevent Telegram
	 * WebView from serving stale versions of the Mini App shell.
	 */
	public function test_mini_app_sets_cache_control_headers() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$source = $this->get_controller_source();

		$this->assertStringContainsString(
			'no-store',
			$source,
			'Mini App should set no-store Cache-Control directive to prevent stale caching'
		);

		$this->assertStringContainsString(
			'no-cache',
			$source,
			'Mini App should set no-cache directive'
		);

		$this->assertStringContainsString(
			'Pragma',
			$source,
			'Mini App should set Pragma header for legacy proxy compatibility'
		);
	}

	/**
	 * Test that Chart.js fallback message is shown when library is unavailable.
	 */
	public function test_mini_app_has_chart_js_fallback_message() {
		if ( ! $this->controller ) {
			$this->markTestSkipped( 'Mini App controller not available.' );
			return;
		}

		$source = $this->get_controller_source();

		$this->assertStringContainsString(
			'Chart library unavailable',
			$source,
			'Mini App should display a user-visible message when Chart.js fails to load'
		);
	}
}
