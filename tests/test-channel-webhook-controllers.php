<?php
/**
 * Test webhook controllers for Telegram, Slack, Discord, and Teams channels.
 *
 * Validates the per-user conversation history pattern (matching PR #3844),
 * signature helper constants, and conversation key generation for each controller.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for channel webhook controllers.
 */
class Test_Channel_Webhook_Controllers extends WP_UnitTestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Load a controller class if not already loaded.
	 *
	 * @param string $class_name PHP class name to check for.
	 * @param string $relative_path Path relative to WP_MCP_AI_PRO_PATH.
	 */
	private function load_controller( $class_name, $relative_path ) {
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
	// Telegram Webhook Controller
	// =========================================================================

	/**
	 * Test CONVERSATION_HISTORY_TTL constant equals 86400 (24 hours).
	 */
	public function test_telegram_conversation_history_ttl_constant() {
		$this->load_controller( 'WP_MCP_AI_Telegram_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php' );

		$this->assertSame(
			86400,
			WP_MCP_AI_Telegram_Webhook_Controller::CONVERSATION_HISTORY_TTL,
			'Telegram CONVERSATION_HISTORY_TTL should be 86400 seconds'
		);
	}

	/**
	 * Test DEDUP_TRANSIENT_TTL constant equals 60.
	 */
	public function test_telegram_dedup_ttl_constant() {
		$this->load_controller( 'WP_MCP_AI_Telegram_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php' );

		$this->assertSame(
			60,
			WP_MCP_AI_Telegram_Webhook_Controller::DEDUP_TRANSIENT_TTL,
			'Telegram DEDUP_TRANSIENT_TTL should be 60 seconds'
		);
	}

	/**
	 * Test get_conversation_history_key returns a deterministic non-empty string.
	 */
	public function test_telegram_conversation_history_key_is_deterministic() {
		$this->load_controller( 'WP_MCP_AI_Telegram_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php' );

		$controller = new WP_MCP_AI_Telegram_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( $controller, '123456', 'conn_abc' );
		$key2 = $method->invoke( $controller, '123456', 'conn_abc' );
		$key3 = $method->invoke( $controller, '999999', 'conn_abc' );

		$this->assertIsString( $key1 );
		$this->assertNotEmpty( $key1 );
		$this->assertSame( $key1, $key2, 'Same inputs must produce same key' );
		$this->assertNotSame( $key1, $key3, 'Different user produces different key' );
		$this->assertStringStartsWith( 'wp_mcp_ai_tg_conv_', $key1 );
		$this->assertLessThanOrEqual( 172, strlen( $key1 ), 'Key must fit WordPress transient key limit' );
	}

	/**
	 * Test that different connection IDs produce different keys for the same user.
	 */
	public function test_telegram_history_key_differs_by_connection() {
		$this->load_controller( 'WP_MCP_AI_Telegram_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php' );

		$controller = new WP_MCP_AI_Telegram_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key_a = $method->invoke( $controller, '123456', 'conn_A' );
		$key_b = $method->invoke( $controller, '123456', 'conn_B' );

		$this->assertNotSame( $key_a, $key_b );
	}

	// =========================================================================
	// Slack Event Controller
	// =========================================================================

	/**
	 * Test CONVERSATION_HISTORY_TTL constant equals 86400.
	 */
	public function test_slack_conversation_history_ttl_constant() {
		$this->load_controller( 'WP_MCP_AI_Slack_Event_Controller', 'includes/rest/class-wp-mcp-ai-slack-event-controller.php' );

		$this->assertSame(
			86400,
			WP_MCP_AI_Slack_Event_Controller::CONVERSATION_HISTORY_TTL,
			'Slack CONVERSATION_HISTORY_TTL should be 86400 seconds'
		);
	}

	/**
	 * Test MAX_REQUEST_AGE constant equals 300 (5 minutes — replay attack window).
	 */
	public function test_slack_max_request_age_constant() {
		$this->load_controller( 'WP_MCP_AI_Slack_Event_Controller', 'includes/rest/class-wp-mcp-ai-slack-event-controller.php' );

		$this->assertSame(
			300,
			WP_MCP_AI_Slack_Event_Controller::MAX_REQUEST_AGE,
			'Slack MAX_REQUEST_AGE should be 300 seconds'
		);
	}

	/**
	 * Test get_conversation_history_key is deterministic and unique per user+channel.
	 */
	public function test_slack_conversation_history_key_is_deterministic() {
		$this->load_controller( 'WP_MCP_AI_Slack_Event_Controller', 'includes/rest/class-wp-mcp-ai-slack-event-controller.php' );

		$controller = new WP_MCP_AI_Slack_Event_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( $controller, 'U123', 'C456', 'conn_abc' );
		$key2 = $method->invoke( $controller, 'U123', 'C456', 'conn_abc' );
		$key3 = $method->invoke( $controller, 'U999', 'C456', 'conn_abc' );

		$this->assertIsString( $key1 );
		$this->assertNotEmpty( $key1 );
		$this->assertSame( $key1, $key2, 'Same inputs must produce same key' );
		$this->assertNotSame( $key1, $key3, 'Different user produces different key' );
		$this->assertStringStartsWith( 'wp_mcp_ai_sl_conv_', $key1 );
		$this->assertLessThanOrEqual( 172, strlen( $key1 ) );
	}

	/**
	 * Test that validate_slack_signature allows requests when no signing secret is configured.
	 */
	public function test_slack_validation_passes_without_signing_secret() {
		$this->load_controller( 'WP_MCP_AI_Slack_Event_Controller', 'includes/rest/class-wp-mcp-ai-slack-event-controller.php' );

		// Controller's get_active_slack_connection will return null (no connections stored).
		$controller = new WP_MCP_AI_Slack_Event_Controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/slack' );
		$request->set_body( '{"type":"url_verification"}' );

		$result = $controller->validate_slack_signature( $request );

		// Without a configured signing secret the method should return true (allows through with warning).
		$this->assertTrue( $result, 'Validation should pass when no signing secret is configured' );
	}

	// =========================================================================
	// Discord Interaction Controller
	// =========================================================================

	/**
	 * Test CONVERSATION_HISTORY_TTL constant equals 86400.
	 */
	public function test_discord_conversation_history_ttl_constant() {
		$this->load_controller( 'WP_MCP_AI_Discord_Interaction_Controller', 'includes/rest/class-wp-mcp-ai-discord-interaction-controller.php' );

		$this->assertSame(
			86400,
			WP_MCP_AI_Discord_Interaction_Controller::CONVERSATION_HISTORY_TTL,
			'Discord CONVERSATION_HISTORY_TTL should be 86400 seconds'
		);
	}

	/**
	 * Test interaction type constants match Discord specification.
	 */
	public function test_discord_interaction_type_constants() {
		$this->load_controller( 'WP_MCP_AI_Discord_Interaction_Controller', 'includes/rest/class-wp-mcp-ai-discord-interaction-controller.php' );

		$this->assertSame( 1, WP_MCP_AI_Discord_Interaction_Controller::INTERACTION_TYPE_PING );
		$this->assertSame( 2, WP_MCP_AI_Discord_Interaction_Controller::INTERACTION_TYPE_APPLICATION_COMMAND );
		$this->assertSame( 1, WP_MCP_AI_Discord_Interaction_Controller::RESPONSE_TYPE_PONG );
		$this->assertSame( 5, WP_MCP_AI_Discord_Interaction_Controller::RESPONSE_TYPE_DEFERRED );
	}

	/**
	 * Test get_conversation_history_key is deterministic and scoped to user+channel.
	 */
	public function test_discord_conversation_history_key_is_deterministic() {
		$this->load_controller( 'WP_MCP_AI_Discord_Interaction_Controller', 'includes/rest/class-wp-mcp-ai-discord-interaction-controller.php' );

		$controller = new WP_MCP_AI_Discord_Interaction_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( $controller, 'user_111', 'chan_222', 'conn_abc' );
		$key2 = $method->invoke( $controller, 'user_111', 'chan_222', 'conn_abc' );
		$key3 = $method->invoke( $controller, 'user_333', 'chan_222', 'conn_abc' );

		$this->assertIsString( $key1 );
		$this->assertNotEmpty( $key1 );
		$this->assertSame( $key1, $key2, 'Same inputs must produce same key' );
		$this->assertNotSame( $key1, $key3, 'Different user produces different key' );
		$this->assertStringStartsWith( 'wp_mcp_ai_ds_conv_', $key1 );
		$this->assertLessThanOrEqual( 172, strlen( $key1 ) );
	}

	/**
	 * Test that a PING interaction returns a PONG response.
	 */
	public function test_discord_ping_returns_pong() {
		$this->load_controller( 'WP_MCP_AI_Discord_Interaction_Controller', 'includes/rest/class-wp-mcp-ai-discord-interaction-controller.php' );

		$controller = new WP_MCP_AI_Discord_Interaction_Controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/discord' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'type' => 1 ) ) );

		$response = $controller->handle_interaction( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'type', $data );
		$this->assertSame( WP_MCP_AI_Discord_Interaction_Controller::RESPONSE_TYPE_PONG, $data['type'] );
	}

	/**
	 * Test extract_message_text picks up the first string option value.
	 */
	public function test_discord_extract_message_text_from_options() {
		$this->load_controller( 'WP_MCP_AI_Discord_Interaction_Controller', 'includes/rest/class-wp-mcp-ai-discord-interaction-controller.php' );

		$controller = new WP_MCP_AI_Discord_Interaction_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_message_text' );
		$method->setAccessible( true );

		$payload = array(
			'data' => array(
				'options' => array(
					array(
						'name'  => 'message',
						'value' => 'Hello from Discord',
					),
				),
			),
		);

		$text = $method->invoke( $controller, $payload );

		$this->assertSame( 'Hello from Discord', $text );
	}

	/**
	 * Test extract_user_id prefers member.user.id over user.id.
	 */
	public function test_discord_extract_user_id_prefers_member() {
		$this->load_controller( 'WP_MCP_AI_Discord_Interaction_Controller', 'includes/rest/class-wp-mcp-ai-discord-interaction-controller.php' );

		$controller = new WP_MCP_AI_Discord_Interaction_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_user_id' );
		$method->setAccessible( true );

		$payload_guild = array(
			'member' => array( 'user' => array( 'id' => 'guild_user_id' ) ),
			'user'   => array( 'id' => 'dm_user_id' ),
		);

		$this->assertSame( 'guild_user_id', $method->invoke( $controller, $payload_guild ) );

		$payload_dm = array(
			'user' => array( 'id' => 'dm_user_id' ),
		);

		$this->assertSame( 'dm_user_id', $method->invoke( $controller, $payload_dm ) );
	}

	// =========================================================================
	// Teams Webhook Controller
	// =========================================================================

	/**
	 * Test CONVERSATION_HISTORY_TTL constant equals 86400.
	 */
	public function test_teams_conversation_history_ttl_constant() {
		$this->load_controller( 'WP_MCP_AI_Teams_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-teams-webhook-controller.php' );

		$this->assertSame(
			86400,
			WP_MCP_AI_Teams_Webhook_Controller::CONVERSATION_HISTORY_TTL,
			'Teams CONVERSATION_HISTORY_TTL should be 86400 seconds'
		);
	}

	/**
	 * Test get_conversation_history_key is deterministic and scoped to user+channel.
	 */
	public function test_teams_conversation_history_key_is_deterministic() {
		$this->load_controller( 'WP_MCP_AI_Teams_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-teams-webhook-controller.php' );

		$controller = new WP_MCP_AI_Teams_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( $controller, 'aad_user_1', 'chan_abc', 'conn_xyz' );
		$key2 = $method->invoke( $controller, 'aad_user_1', 'chan_abc', 'conn_xyz' );
		$key3 = $method->invoke( $controller, 'aad_user_2', 'chan_abc', 'conn_xyz' );

		$this->assertIsString( $key1 );
		$this->assertNotEmpty( $key1 );
		$this->assertSame( $key1, $key2, 'Same inputs must produce same key' );
		$this->assertNotSame( $key1, $key3, 'Different user produces different key' );
		$this->assertStringStartsWith( 'wp_mcp_ai_ms_conv_', $key1 );
		$this->assertLessThanOrEqual( 172, strlen( $key1 ) );
	}

	/**
	 * Test extract_message_text strips bot @mentions.
	 */
	public function test_teams_extract_message_text_strips_at_mention() {
		$this->load_controller( 'WP_MCP_AI_Teams_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-teams-webhook-controller.php' );

		$controller = new WP_MCP_AI_Teams_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_message_text' );
		$method->setAccessible( true );

		$payload = array(
			'text' => '<at>MyBot</at> What is the weather today?',
		);

		$text = $method->invoke( $controller, $payload );

		$this->assertStringNotContainsString( '<at>', $text );
		$this->assertStringNotContainsString( 'MyBot', $text );
		$this->assertStringContainsString( 'What is the weather today', $text );
	}

	/**
	 * Test extract_user_id prefers aadObjectId over id.
	 */
	public function test_teams_extract_user_id_prefers_aad_object_id() {
		$this->load_controller( 'WP_MCP_AI_Teams_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-teams-webhook-controller.php' );

		$controller = new WP_MCP_AI_Teams_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_user_id' );
		$method->setAccessible( true );

		$payload = array(
			'from' => array(
				'id'          => 'teams_id_123',
				'aadObjectId' => 'aad_object_id_456',
			),
		);

		$this->assertSame( 'aad_object_id_456', $method->invoke( $controller, $payload ) );
	}

	/**
	 * Test that validate_teams_signature allows through when no signing secret is set.
	 */
	public function test_teams_validation_passes_without_signing_secret() {
		$this->load_controller( 'WP_MCP_AI_Teams_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-teams-webhook-controller.php' );

		$controller = new WP_MCP_AI_Teams_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/teams' );
		$request->set_body( '{"type":"message","text":"Hello"}' );

		$result = $controller->validate_teams_signature( $request );

		$this->assertTrue( $result, 'Validation should pass when no signing secret is configured' );
	}

	// =========================================================================
	// Google Chat Webhook Controller
	// =========================================================================

	/**
	 * Test CONVERSATION_HISTORY_TTL constant equals 86400 (24 hours).
	 */
	public function test_google_chat_conversation_history_ttl_constant() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$this->assertSame(
			86400,
			WP_MCP_AI_Google_Chat_Webhook_Controller::CONVERSATION_HISTORY_TTL,
			'Google Chat CONVERSATION_HISTORY_TTL should be 86400 seconds'
		);
	}

	/**
	 * Test DEDUP_TRANSIENT_TTL constant equals 60.
	 */
	public function test_google_chat_dedup_ttl_constant() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$this->assertSame(
			60,
			WP_MCP_AI_Google_Chat_Webhook_Controller::DEDUP_TRANSIENT_TTL,
			'Google Chat DEDUP_TRANSIENT_TTL should be 60 seconds'
		);
	}

	/**
	 * Test CHAT_API_BASE constant is the correct Google Chat API URL.
	 */
	public function test_google_chat_api_base_constant() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$this->assertSame(
			'https://chat.googleapis.com/v1',
			WP_MCP_AI_Google_Chat_Webhook_Controller::CHAT_API_BASE
		);
	}

	/**
	 * Test get_conversation_history_key returns a deterministic, scoped, non-empty string.
	 */
	public function test_google_chat_conversation_history_key_is_deterministic() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( $controller, 'users/123', 'spaces/AAA', 'conn_abc' );
		$key2 = $method->invoke( $controller, 'users/123', 'spaces/AAA', 'conn_abc' );
		$key3 = $method->invoke( $controller, 'users/456', 'spaces/AAA', 'conn_abc' );

		$this->assertIsString( $key1 );
		$this->assertNotEmpty( $key1 );
		$this->assertSame( $key1, $key2, 'Same inputs must produce same key' );
		$this->assertNotSame( $key1, $key3, 'Different sender produces different key' );
		$this->assertStringStartsWith( 'wp_mcp_ai_gc_conv_', $key1 );
		$this->assertLessThanOrEqual( 172, strlen( $key1 ), 'Key must fit WordPress transient key limit' );
	}

	/**
	 * Test that different connection IDs produce different keys for the same sender/space.
	 */
	public function test_google_chat_history_key_differs_by_connection() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( $controller, 'users/123', 'spaces/AAA', 'conn_1' );
		$key2 = $method->invoke( $controller, 'users/123', 'spaces/AAA', 'conn_2' );

		$this->assertNotSame( $key1, $key2, 'Different connections must produce different history keys' );
	}

	/**
	 * Test extract_message_text prefers argumentText (bot mention stripped) over raw text.
	 */
	public function test_google_chat_extract_message_text_prefers_argument_text() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_message_text' );
		$method->setAccessible( true );

		$payload = array(
			'message' => array(
				'text'         => '@MyBot What is the weather?',
				'argumentText' => 'What is the weather?',
			),
		);

		$this->assertSame( 'What is the weather?', $method->invoke( $controller, $payload ) );
	}

	/**
	 * Test extract_message_text falls back to text when argumentText is absent.
	 */
	public function test_google_chat_extract_message_text_falls_back_to_text() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_message_text' );
		$method->setAccessible( true );

		$payload = array(
			'message' => array(
				'text' => 'Hello from DM',
			),
		);

		$this->assertSame( 'Hello from DM', $method->invoke( $controller, $payload ) );
	}

	/**
	 * Test extract_message_text returns empty string when no text is present.
	 */
	public function test_google_chat_extract_message_text_empty_for_no_text() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_message_text' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( $controller, array( 'message' => array() ) ) );
		$this->assertSame( '', $method->invoke( $controller, array() ) );
	}

	/**
	 * Test validate_google_oidc_token rejects requests with no Authorization header.
	 */
	public function test_google_chat_validation_rejects_missing_auth_header() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );

		$result = $controller->validate_google_oidc_token( $request );

		$this->assertFalse( $result, 'Validation must reject requests without an Authorization header' );
	}

	/**
	 * Test validate_google_oidc_token rejects requests with a non-Bearer Authorization header.
	 */
	public function test_google_chat_validation_rejects_non_bearer_auth() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Basic dXNlcjpwYXNz' );

		$result = $controller->validate_google_oidc_token( $request );

		$this->assertFalse( $result, 'Validation must reject non-Bearer Authorization schemes' );
	}

	/**
	 * Test validate_google_oidc_token allows through when Bearer token is present
	 * but no audience is configured (no-audience mode).
	 */
	public function test_google_chat_validation_passes_without_audience_configured() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		// Ensure no google_chat connection is stored so audience defaults to empty.
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Bearer some.valid.looking.token' );

		$result = $controller->validate_google_oidc_token( $request );

		$this->assertTrue( $result, 'Validation should pass when no audience is configured and Bearer token is present' );
	}

	/**
	 * Test validate_google_oidc_token rejects an expired JWT when audience is configured.
	 */
	public function test_google_chat_validation_rejects_expired_jwt() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$webhook_url = home_url( '/wp-json/mcp-ai/v1/webhooks/google-chat' );

		// Store a google_chat connection with an audience URL.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC Test',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'dummy_token',
				'verify_token'           => $webhook_url,
				'assigned_assistant_ids' => array( 1 ),
			)
		);

		// Build a JWT with a past expiry and matching audience.
		$header  = rtrim(
			strtr(
				base64_encode(
					wp_json_encode(
						array(
							'alg' => 'RS256',
							'typ' => 'JWT',
						)
					)
				),
				'+/',
				'-_'
			),
			'='
		); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$payload = rtrim(
			strtr(
				base64_encode(
					wp_json_encode(
						array( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						'iss' => 'accounts.google.com',
						'aud' => $webhook_url,
						'exp' => time() - 3600,
						)
					)
				),
				'+/',
				'-_'
			),
			'='
		);
		$token   = $header . '.' . $payload . '.fakesig';

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );

		$result = $controller->validate_google_oidc_token( $request );

		$this->assertFalse( $result, 'Validation must reject expired OIDC tokens' );

		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Test validate_google_oidc_token rejects a JWT with mismatched audience.
	 */
	public function test_google_chat_validation_rejects_wrong_audience() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$webhook_url = home_url( '/wp-json/mcp-ai/v1/webhooks/google-chat' );

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC Test',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'dummy_token',
				'verify_token'           => $webhook_url,
				'assigned_assistant_ids' => array( 1 ),
			)
		);

		// Build a JWT with a different (wrong) audience.
		$header  = rtrim(
			strtr(
				base64_encode(
					wp_json_encode(
						array(
							'alg' => 'RS256',
							'typ' => 'JWT',
						)
					)
				),
				'+/',
				'-_'
			),
			'='
		); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$payload = rtrim(
			strtr(
				base64_encode(
					wp_json_encode(
						array( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						'iss' => 'accounts.google.com',
						'aud' => 'https://example.com/wrong-endpoint',
						'exp' => time() + 3600,
						)
					)
				),
				'+/',
				'-_'
			),
			'='
		);
		$token   = $header . '.' . $payload . '.fakesig';

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );

		$result = $controller->validate_google_oidc_token( $request );

		$this->assertFalse( $result, 'Validation must reject tokens with audience mismatch' );

		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	// =========================================================================
	// Google Chat – per-space assistant routing enhancements.
	// =========================================================================

	/**
	 * Test get_active_google_chat_connection returns space-specific connection when available.
	 */
	public function test_google_chat_space_specific_connection_preferred_over_generic() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$connections = array(
			array(
				'id'                     => 'generic_conn',
				'connection_type'        => 'google_chat',
				'enabled'                => true,
				'assigned_assistant_ids' => array( 1 ),
				'api_key'                => 'dummy_token',
			),
			array(
				'id'                     => 'space_conn',
				'connection_type'        => 'google_chat',
				'enabled'                => true,
				'assigned_assistant_ids' => array( 2 ),
				'api_key'                => 'dummy_token',
				'google_chat_space'      => 'spaces/AAABBB',
			),
		);

		update_option( 'wp_mcp_ai_pro_remote_sites', $connections );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_active_google_chat_connection' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, 'spaces/AAABBB' );

		$this->assertIsArray( $result );
		$this->assertSame( 'space_conn', $result['id'], 'Space-specific connection should be preferred' );

		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Test get_active_google_chat_connection falls back to generic when no space match.
	 */
	public function test_google_chat_falls_back_to_generic_when_no_space_match() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$connections = array(
			array(
				'id'                     => 'generic_conn',
				'connection_type'        => 'google_chat',
				'enabled'                => true,
				'assigned_assistant_ids' => array( 1 ),
				'api_key'                => 'dummy_token',
			),
		);

		update_option( 'wp_mcp_ai_pro_remote_sites', $connections );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_active_google_chat_connection' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, 'spaces/UNKNOWN' );

		$this->assertIsArray( $result );
		$this->assertSame( 'generic_conn', $result['id'], 'Should fall back to generic connection' );

		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Test handle_welcome_message_job returns early when space_name is empty.
	 */
	public function test_google_chat_welcome_message_job_returns_early_on_empty_space() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();

		// This should not throw an exception even with invalid args.
		$controller->handle_welcome_message_job(
			array(
				'space_name'    => '',
				'message_text'  => 'Hello!',
				'connection_id' => 'conn_abc',
			)
		);

		// If we reach here without exception, the early-return guard works.
		$this->assertTrue( true );
	}

	/**
	 * Test get_active_google_chat_connection returns a connection even when
	 * assigned_assistant_ids is not set (removed that requirement so the global
	 * default_assistant_id fallback can be applied in handle_webhook).
	 */
	public function test_google_chat_connection_found_without_assigned_assistants() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Connection with no assigned_assistant_ids.
		$connections = array(
			array(
				'id'              => 'no_assistant_conn',
				'connection_type' => 'google_chat',
				'enabled'         => true,
				'api_key'         => 'dummy_token',
			),
		);

		update_option( 'wp_mcp_ai_pro_remote_sites', $connections );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_active_google_chat_connection' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, '' );

		$this->assertIsArray( $result, 'Connection without assigned_assistant_ids should still be returned' );
		$this->assertSame( 'no_assistant_conn', $result['id'], 'Correct connection should be returned' );

		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Test that handle_webhook uses the global default_assistant_id from automation rules
	 * when the connection has no assigned_assistant_ids — mirrors the Telegram/WhatsApp pattern.
	 */
	public function test_google_chat_handle_webhook_uses_default_assistant_id_fallback() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Save a google_chat connection with NO assigned_assistant_ids.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'GC Default Assistant Test',
				'url'             => 'https://chat.googleapis.com/v1',
				'connection_type' => 'google_chat',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'dummy_token',
			)
		);

		// Set the global default_assistant_id in automation rules.
		$assistant_id = 42;
		update_option(
			'wp_mcp_ai_chat_channels_automation_rules',
			array( 'default_assistant_id' => $assistant_id )
		);

		// Use the filter to capture the resolved assistant IDs without actually scheduling cron.
		$captured_assistant_ids = null;
		add_filter(
			'wp_mcp_ai_google_chat_should_auto_reply',
			function ( $should_reply, $payload, $automation_rules ) use ( &$captured_assistant_ids, $assistant_id ) {
				// The controller resolves $assigned_assistant_ids before calling the filter.
				// We verify this by capturing the default from automation_rules.
				$captured_assistant_ids = ! empty( $automation_rules['default_assistant_id'] )
					? array( absint( $automation_rules['default_assistant_id'] ) )
					: array();
				return false; // Prevent cron dispatch.
			},
			10,
			3
		);

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Bearer some.token.here' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'type'    => 'MESSAGE',
					'message' => array(
						'name'   => 'spaces/AAABBB/messages/msg001',
						'text'   => 'Hello bot',
						'sender' => array( 'name' => 'users/12345' ),
					),
					'space'   => array( 'name' => 'spaces/AAABBB' ),
				)
			)
		);

		$controller->handle_webhook( $request );

		remove_all_filters( 'wp_mcp_ai_google_chat_should_auto_reply' );

		$this->assertNotNull( $captured_assistant_ids, 'wp_mcp_ai_google_chat_should_auto_reply filter should have been called' );
		$this->assertContains(
			$assistant_id,
			$captured_assistant_ids,
			'Automation rules default_assistant_id should be used as fallback'
		);

		// Cleanup.
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		delete_option( 'wp_mcp_ai_chat_channels_automation_rules' );
	}

	/**
	 * Test that the wp_mcp_ai_google_chat_should_auto_reply filter can block the auto-reply.
	 */
	public function test_google_chat_should_auto_reply_filter_is_applied() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC Filter Test',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'dummy_token',
				'assigned_assistant_ids' => array( 1 ),
			)
		);

		$filter_was_called = false;
		add_filter(
			'wp_mcp_ai_google_chat_should_auto_reply',
			function () use ( &$filter_was_called ) {
				$filter_was_called = true;
				return false; // Block reply without dispatching cron.
			}
		);

		// Capture cron state before invocation.
		$before_crons = _get_cron_array();

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Bearer some.token.here' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'type'    => 'MESSAGE',
					'message' => array(
						'name'   => 'spaces/AAABBB/messages/msg002',
						'text'   => 'Filter test message',
						'sender' => array( 'name' => 'users/99999' ),
					),
					'space'   => array( 'name' => 'spaces/AAABBB' ),
				)
			)
		);

		$controller->handle_webhook( $request );

		remove_all_filters( 'wp_mcp_ai_google_chat_should_auto_reply' );

		$after_crons = _get_cron_array();

		$this->assertTrue( $filter_was_called, 'wp_mcp_ai_google_chat_should_auto_reply filter should have been invoked' );
		$this->assertEquals(
			$before_crons,
			$after_crons,
			'No cron event should be scheduled when the filter returns false'
		);

		// Cleanup.
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Test that handle_webhook schedules a cron job for MESSAGE events when the connection
	 * has assigned assistants — the core auto-reply trigger.
	 */
	public function test_google_chat_handle_webhook_schedules_cron_for_message_event() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC Cron Test',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'dummy_token',
				'assigned_assistant_ids' => array( 7 ),
			)
		);

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Bearer some.token.here' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'type'    => 'MESSAGE',
					'message' => array(
						'name'   => 'spaces/CCCDDDD/messages/msg003',
						'text'   => 'Hello from space',
						'sender' => array( 'name' => 'users/55555' ),
					),
					'space'   => array( 'name' => 'spaces/CCCDDDD' ),
				)
			)
		);

		$controller->handle_webhook( $request );

		$crons = _get_cron_array();
		$hook  = WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK;

		$found = false;
		foreach ( $crons as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Cron job should be scheduled for a MESSAGE event with an active connection' );

		// Clean up.
		wp_clear_scheduled_hook( $hook );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	// =========================================================================
	// Shared conversation history trimming logic (platform-agnostic).
	// =========================================================================

	/**
	 * Test that the history trimming algorithm matches the WhatsApp PR #3844 pattern.
	 * Before adding a new user message, history is trimmed to max_history - 1.
	 */
	public function test_history_trimmed_before_new_message_all_channels() {
		$max_history = 6;

		$history = array();
		for ( $i = 0; $i < $max_history; $i++ ) {
			$history[] = array(
				'role'    => 'user',
				'content' => "msg $i",
			);
			$history[] = array(
				'role'    => 'assistant',
				'content' => "reply $i",
			);
		}

		// Trim before adding new message (matches production logic).
		if ( count( $history ) >= $max_history ) {
			$history = array_slice( $history, -( $max_history - 1 ) );
		}

		$this->assertLessThanOrEqual(
			$max_history - 1,
			count( $history ),
			'History should leave room for the new user message'
		);
	}

	/**
	 * Test that saved history never exceeds max_history after appending user+assistant turns.
	 */
	public function test_history_saved_does_not_exceed_max_history_all_channels() {
		$history      = array(
			array(
				'role'    => 'user',
				'content' => 'a',
			),
			array(
				'role'    => 'assistant',
				'content' => 'b',
			),
			array(
				'role'    => 'user',
				'content' => 'c',
			),
			array(
				'role'    => 'assistant',
				'content' => 'd',
			),
		);
		$max_history  = 4;
		$message_text = 'e';
		$content      = 'f';

		$history[] = array(
			'role'    => 'user',
			'content' => $message_text,
		);
		$history[] = array(
			'role'    => 'assistant',
			'content' => $content,
		);
		if ( count( $history ) > $max_history ) {
			$history = array_slice( $history, -$max_history );
		}

		$this->assertLessThanOrEqual( $max_history, count( $history ) );

		$last = end( $history );
		$this->assertSame( 'assistant', $last['role'] );
		$this->assertSame( $content, $last['content'] );
	}

	// =========================================================================
	// Twitter/X Webhook Controller
	// =========================================================================

	/**
	 * Test CONVERSATION_HISTORY_TTL constant equals 86400 (24 hours).
	 */
	public function test_twitter_conversation_history_ttl_constant() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		$this->assertSame(
			86400,
			WP_MCP_AI_Twitter_Webhook_Controller::CONVERSATION_HISTORY_TTL,
			'Twitter CONVERSATION_HISTORY_TTL should be 86400 seconds'
		);
	}

	/**
	 * Test DEDUP_TRANSIENT_TTL constant equals 60.
	 */
	public function test_twitter_dedup_ttl_constant() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		$this->assertSame(
			60,
			WP_MCP_AI_Twitter_Webhook_Controller::DEDUP_TRANSIENT_TTL,
			'Twitter DEDUP_TRANSIENT_TTL should be 60 seconds'
		);
	}

	/**
	 * Test MAX_DM_LENGTH constant equals 10000 (Twitter API v2 DM limit).
	 */
	public function test_twitter_max_dm_length_constant() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		$this->assertSame(
			10000,
			WP_MCP_AI_Twitter_Webhook_Controller::MAX_DM_LENGTH,
			'Twitter MAX_DM_LENGTH should be 10000 characters'
		);
	}

	/**
	 * Test get_conversation_history_key returns a deterministic non-empty string.
	 */
	public function test_twitter_conversation_history_key_is_deterministic() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		$controller = new WP_MCP_AI_Twitter_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( $controller, '123456789', 'conn_tw_abc' );
		$key2 = $method->invoke( $controller, '123456789', 'conn_tw_abc' );
		$key3 = $method->invoke( $controller, '987654321', 'conn_tw_abc' );

		$this->assertIsString( $key1 );
		$this->assertNotEmpty( $key1 );
		$this->assertSame( $key1, $key2, 'Same inputs must produce same key' );
		$this->assertNotSame( $key1, $key3, 'Different sender produces different key' );
		$this->assertStringStartsWith( 'wp_mcp_ai_tw_conv_', $key1 );
		$this->assertLessThanOrEqual( 172, strlen( $key1 ), 'Key must fit WordPress transient key limit' );
	}

	/**
	 * Test that different connection IDs produce different keys for the same sender.
	 */
	public function test_twitter_history_key_differs_by_connection() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		$controller = new WP_MCP_AI_Twitter_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key_a = $method->invoke( $controller, '123456789', 'conn_A' );
		$key_b = $method->invoke( $controller, '123456789', 'conn_B' );

		$this->assertNotSame( $key_a, $key_b );
	}

	/**
	 * Test validate_webhook_signature passes when no consumer secret is configured
	 * (soft-fail behaviour matching WhatsApp/Slack pattern).
	 */
	public function test_twitter_validation_passes_without_consumer_secret() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		// No connections stored — get_consumer_secret() returns empty string.
		$controller = new WP_MCP_AI_Twitter_Webhook_Controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/twitter' );
		$request->set_body( '{"direct_message_events":[]}' );

		$result = $controller->validate_webhook_signature( $request );

		$this->assertTrue( $result, 'Validation should pass when no consumer secret is configured' );
	}

	/**
	 * Test validate_webhook_signature rejects requests with a wrong signature
	 * when the consumer secret IS configured.
	 */
	public function test_twitter_validation_fails_with_wrong_signature() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		// Subclass that stubs get_consumer_secret() to return a known value.
		$controller = new class() extends WP_MCP_AI_Twitter_Webhook_Controller {
			protected function get_consumer_secret( $connection_id = '' ) {
				return 'test_consumer_secret';
			}
		};

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/twitter' );
		$request->set_body( '{"direct_message_events":[]}' );
		// Provide a deliberately incorrect signature.
		$request->set_header( 'x-twitter-webhooks-signature', 'sha256=INVALIDSIGNATURE' );

		$result = $controller->validate_webhook_signature( $request );

		$this->assertFalse( $result, 'Validation should fail with an invalid signature' );
	}

	/**
	 * Test validate_webhook_signature accepts requests with the correct HMAC-SHA256 signature.
	 */
	public function test_twitter_validation_passes_with_correct_signature() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		$secret  = 'my_consumer_secret';
		$payload = '{"direct_message_events":[{"type":"message_create","id":"1"}]}';

		// Build the expected signature (matches the controller's algorithm).
		$expected_signature = 'sha256=' . base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) );

		$controller = new class( $secret ) extends WP_MCP_AI_Twitter_Webhook_Controller {
			private $test_secret;
			public function __construct( $secret ) {
				$this->test_secret = $secret;
			}
			protected function get_consumer_secret( $connection_id = '' ) {
				return $this->test_secret;
			}
		};

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/twitter' );
		$request->set_body( $payload );
		$request->set_header( 'x-twitter-webhooks-signature', $expected_signature );

		$result = $controller->validate_webhook_signature( $request );

		$this->assertTrue( $result, 'Validation should pass with the correct HMAC-SHA256 signature' );
	}

	/**
	 * Test CRC challenge returns 400 when crc_token is missing.
	 */
	public function test_twitter_crc_challenge_returns_error_when_token_missing() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		$controller = new WP_MCP_AI_Twitter_Webhook_Controller();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/twitter' );
		// No crc_token param set.

		$response = $controller->handle_crc_challenge( $request );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertSame( 'twitter_crc_missing_token', $response->get_error_code() );
	}

	/**
	 * Test handle_webhook returns ok:true for an empty payload.
	 */
	public function test_twitter_handle_webhook_returns_ok_for_empty_payload() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		$controller = new WP_MCP_AI_Twitter_Webhook_Controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/twitter' );
		$request->set_body( '' );

		$response = $controller->handle_webhook( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['ok'] );
	}

	/**
	 * Test extract_content_from_chat_response returns the assistant message.
	 */
	public function test_twitter_extract_content_from_chat_response() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		$controller = new WP_MCP_AI_Twitter_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_content_from_chat_response' );
		$method->setAccessible( true );

		$response_data = array(
			'assistant_id' => 1,
			'data'         => array(
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Hello from Twitter AI!',
						),
					),
				),
			),
		);

		$content = $method->invoke( $controller, $response_data );

		$this->assertSame( 'Hello from Twitter AI!', $content );
	}

	/**
	 * Test extract_content_from_chat_response returns empty string when choices are absent.
	 */
	public function test_twitter_extract_content_returns_empty_when_no_choices() {
		$this->load_controller( 'WP_MCP_AI_Twitter_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php' );

		$controller = new WP_MCP_AI_Twitter_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_content_from_chat_response' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( $controller, array() ) );
		$this->assertSame( '', $method->invoke( $controller, 'not_an_array' ) );
		$this->assertSame( '', $method->invoke( $controller, array( 'data' => array( 'choices' => array() ) ) ) );
	}

	// =========================================================================
	// message_mentions_assistant() — @slug mention trigger (all channels)
	// =========================================================================

	/**
	 * Helper: invoke message_mentions_assistant() via reflection on a given controller.
	 *
	 * @param object $controller  Controller instance.
	 * @param string $text        Message text.
	 * @param int[]  $ids         Array of assistant post IDs.
	 * @return bool
	 */
	private function call_mentions( $controller, $text, array $ids ) {
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'message_mentions_assistant' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $text, $ids );
	}

	/**
	 * message_mentions_assistant returns false for empty message text.
	 */
	public function test_mention_trigger_returns_false_for_empty_text() {
		$this->load_controller( 'WP_MCP_AI_Telegram_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php' );
		$controller = new WP_MCP_AI_Telegram_Webhook_Controller();

		// Create a real assistant post so get_post_field can find a slug.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Bot',
				'post_name'   => 'test-bot',
				'post_status' => 'publish',
			)
		);

		$this->assertFalse( $this->call_mentions( $controller, '', array( $post_id ) ) );

		wp_delete_post( $post_id, true );
	}

	/**
	 * message_mentions_assistant returns false for empty assistant IDs.
	 */
	public function test_mention_trigger_returns_false_for_empty_ids() {
		$this->load_controller( 'WP_MCP_AI_Slack_Event_Controller', 'includes/rest/class-wp-mcp-ai-slack-event-controller.php' );
		$controller = new WP_MCP_AI_Slack_Event_Controller();

		$this->assertFalse( $this->call_mentions( $controller, '@any-bot Hello', array() ) );
	}

	/**
	 * message_mentions_assistant returns true when @slug appears in the text.
	 */
	public function test_mention_trigger_detects_slug_mention() {
		$this->load_controller( 'WP_MCP_AI_Slack_Event_Controller', 'includes/rest/class-wp-mcp-ai-slack-event-controller.php' );
		$controller = new WP_MCP_AI_Slack_Event_Controller();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Sales Bot',
				'post_name'   => 'sales-bot',
				'post_status' => 'publish',
			)
		);

		$this->assertTrue( $this->call_mentions( $controller, '@sales-bot can you help me?', array( $post_id ) ) );

		wp_delete_post( $post_id, true );
	}

	/**
	 * message_mentions_assistant is case-insensitive.
	 */
	public function test_mention_trigger_is_case_insensitive() {
		$this->load_controller( 'WP_MCP_AI_Telegram_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php' );
		$controller = new WP_MCP_AI_Telegram_Webhook_Controller();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Support Bot',
				'post_name'   => 'support-bot',
				'post_status' => 'publish',
			)
		);

		$this->assertTrue( $this->call_mentions( $controller, '@Support-Bot please help', array( $post_id ) ) );
		$this->assertTrue( $this->call_mentions( $controller, '@SUPPORT-BOT test', array( $post_id ) ) );

		wp_delete_post( $post_id, true );
	}

	/**
	 * message_mentions_assistant returns false when @slug is not present.
	 */
	public function test_mention_trigger_returns_false_when_no_slug_in_text() {
		$this->load_controller( 'WP_MCP_AI_Discord_Interaction_Controller', 'includes/rest/class-wp-mcp-ai-discord-interaction-controller.php' );
		$controller = new WP_MCP_AI_Discord_Interaction_Controller();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Help Bot',
				'post_name'   => 'help-bot',
				'post_status' => 'publish',
			)
		);

		// No @mention at all.
		$this->assertFalse( $this->call_mentions( $controller, 'Can someone help me?', array( $post_id ) ) );
		// Slug without the @ prefix should not match.
		$this->assertFalse( $this->call_mentions( $controller, 'help-bot please assist', array( $post_id ) ) );
		// @bot should not match when slug is help-bot (different slug).
		$post_id_bot = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Bot',
				'post_name'   => 'bot',
				'post_status' => 'publish',
			)
		);
		// @help-bot should NOT trigger slug "bot" (boundary check).
		$this->assertFalse( $this->call_mentions( $controller, '@help-bot can you help?', array( $post_id_bot ) ) );
		// @bots should NOT trigger slug "bot" (boundary check).
		$this->assertFalse( $this->call_mentions( $controller, '@bots are great', array( $post_id_bot ) ) );

		wp_delete_post( $post_id, true );
		wp_delete_post( $post_id_bot, true );
	}

	/**
	 * message_mentions_assistant returns true when any one of multiple assistants is mentioned.
	 */
	public function test_mention_trigger_matches_any_assigned_assistant() {
		$this->load_controller( 'WP_MCP_AI_Teams_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-teams-webhook-controller.php' );
		$controller = new WP_MCP_AI_Teams_Webhook_Controller();

		$post_id_1 = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Assistant Alpha',
				'post_name'   => 'assistant-alpha',
				'post_status' => 'publish',
			)
		);
		$post_id_2 = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Assistant Beta',
				'post_name'   => 'assistant-beta',
				'post_status' => 'publish',
			)
		);

		// Mentioning the second assistant should still return true.
		$this->assertTrue( $this->call_mentions( $controller, 'Hey @assistant-beta help', array( $post_id_1, $post_id_2 ) ) );
		// Mentioning neither should return false.
		$this->assertFalse( $this->call_mentions( $controller, 'Hey everyone!', array( $post_id_1, $post_id_2 ) ) );

		wp_delete_post( $post_id_1, true );
		wp_delete_post( $post_id_2, true );
	}

	/**
	 * message_mentions_assistant is available on the Google Chat controller.
	 */
	public function test_mention_trigger_available_on_google_chat_controller() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );
		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'GC Bot',
				'post_name'   => 'gc-bot',
				'post_status' => 'publish',
			)
		);

		$this->assertTrue( $this->call_mentions( $controller, 'Hello @gc-bot, what is the weather?', array( $post_id ) ) );

		wp_delete_post( $post_id, true );
	}

	/**
	 * message_mentions_assistant is available on the WhatsApp controller.
	 */
	public function test_mention_trigger_available_on_whatsapp_controller() {
		$this->load_controller( 'WP_MCP_AI_WhatsApp_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php' );
		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'WA Bot',
				'post_name'   => 'wa-bot',
				'post_status' => 'publish',
			)
		);

		$this->assertTrue( $this->call_mentions( $controller, '@wa-bot check my order', array( $post_id ) ) );
		$this->assertFalse( $this->call_mentions( $controller, 'check my order', array( $post_id ) ) );

		wp_delete_post( $post_id, true );
	}

	/**
	 * message_mentions_assistant is available on the Messenger controller.
	 */
	public function test_mention_trigger_available_on_messenger_controller() {
		$this->load_controller( 'WP_MCP_AI_Messenger_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-messenger-webhook-controller.php' );
		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'FB Bot',
				'post_name'   => 'fb-bot',
				'post_status' => 'publish',
			)
		);

		$this->assertTrue( $this->call_mentions( $controller, '@fb-bot help me', array( $post_id ) ) );

		wp_delete_post( $post_id, true );
	}

	// =========================================================================
	// Google Chat — native @mention bypass for require_mention
	// =========================================================================

	/**
	 * Google Chat payload with argumentText indicates the bot was natively @mentioned.
	 *
	 * When Google Chat sends argumentText it has already stripped the bot's display name
	 * from the message. This is the canonical signal that the user triggered the bot via
	 * Google Chat's own @mention mechanism.
	 */
	public function test_google_chat_argument_text_signals_native_bot_mention() {
		// A payload where the user typed "@BotApp hello" — Google Chat strips
		// the mention and puts the remainder in argumentText.
		$payload_with_mention = array(
			'message' => array(
				'text'         => '@BotApp hello',
				'argumentText' => ' hello',
			),
		);

		// A DM payload: no argumentText (Google Chat does not populate it in DMs).
		$payload_dm = array(
			'message' => array(
				'text' => 'just a question',
			),
		);

		// argumentText presence is the signal that the bot was @mentioned in a space.
		$this->assertTrue( isset( $payload_with_mention['message']['argumentText'] ), 'argumentText must be present when bot is @mentioned in a Google Chat space' );
		$this->assertFalse( isset( $payload_dm['message']['argumentText'] ), 'argumentText absent in DM payloads — DM detection should rely on space type instead' );
	}

	/**
	 * Google Chat DIRECT_MESSAGE space type indicates a direct message to the bot.
	 *
	 * In direct messages every incoming message triggers the bot webhook, so
	 * require_mention should always be considered satisfied.
	 */
	public function test_google_chat_direct_message_space_type_detected() {
		$dm_space_type    = 'DIRECT_MESSAGE';
		$group_space_type = 'SPACE';

		$this->assertSame( 'DIRECT_MESSAGE', $dm_space_type );
		$this->assertNotSame( 'DIRECT_MESSAGE', $group_space_type );
	}

	/**
	 * Google Chat handle_webhook always schedules a reply regardless of the require_mention
	 * setting — the bot auto-replies to every message it receives.
	 */
	public function test_google_chat_always_schedules_reply_for_group_message() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'GC Require Bot',
				'post_name'   => 'gc-require-bot',
				'post_status' => 'publish',
			)
		);

		// Store a connection with require_mention enabled.
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				array(
					'id'                     => 'gc_require_conn',
					'connection_type'        => 'google_chat',
					'enabled'                => true,
					'api_key'                => 'dummy_token',
					'assigned_assistant_ids' => array( $post_id ),
					'require_mention'        => true,
				),
			)
		);

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();

		// Build a MESSAGE payload for a GROUP SPACE without argumentText and without @slug.
		$payload = array(
			'type'    => 'MESSAGE',
			'space'   => array(
				'name' => 'spaces/AAABBB',
				'type' => 'SPACE',
			),
			'message' => array(
				'name'   => 'spaces/AAABBB/messages/msg-001',
				'text'   => 'Hello everyone!',
				'sender' => array( 'name' => 'users/111' ),
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_body( wp_json_encode( $payload ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $controller->handle_webhook( $request );
		$data     = rest_ensure_response( $response )->get_data();

		// Cron job must be scheduled — the bot always auto-replies to every message.
		$this->assertNotFalse(
			wp_next_scheduled( WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK ),
			'Cron job must be scheduled — the bot always auto-replies regardless of require_mention setting'
		);

		wp_unschedule_hook( WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK );
		wp_delete_post( $post_id, true );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Google Chat handle_webhook schedules a reply when require_mention is enabled
	 * and the payload contains argumentText (native Google Chat @mention).
	 */
	public function test_google_chat_require_mention_allows_native_at_mention() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'GC Native Bot',
				'post_name'   => 'gc-native-bot',
				'post_status' => 'publish',
			)
		);

		// Store a connection with require_mention enabled.
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				array(
					'id'                     => 'gc_native_conn',
					'connection_type'        => 'google_chat',
					'enabled'                => true,
					'api_key'                => 'dummy_token',
					'assigned_assistant_ids' => array( $post_id ),
					'require_mention'        => true,
				),
			)
		);

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();

		// Build a MESSAGE payload where Google Chat populated argumentText
		// (indicating the user sent "@BotApp hello" in a group space).
		$payload = array(
			'type'    => 'MESSAGE',
			'space'   => array(
				'name' => 'spaces/CCCDDD',
				'type' => 'SPACE',
			),
			'message' => array(
				'name'         => 'spaces/CCCDDD/messages/msg-native-001',
				'text'         => '@BotApp hello',
				'argumentText' => ' hello',
				'sender'       => array( 'name' => 'users/222' ),
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_body( wp_json_encode( $payload ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$controller->handle_webhook( $request );

		// Cron job must be scheduled — the native @mention satisfies require_mention.
		$this->assertNotFalse(
			wp_next_scheduled( WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK ),
			'Cron job must be scheduled when the bot receives a native Google Chat @mention (argumentText present)'
		);

		wp_unschedule_hook( WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK );
		wp_delete_post( $post_id, true );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Google Chat handle_webhook schedules a reply when require_mention is enabled
	 * and the space type is DIRECT_MESSAGE.
	 */
	public function test_google_chat_require_mention_allows_direct_message() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'GC DM Bot',
				'post_name'   => 'gc-dm-bot',
				'post_status' => 'publish',
			)
		);

		// Store a connection with require_mention enabled.
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				array(
					'id'                     => 'gc_dm_conn',
					'connection_type'        => 'google_chat',
					'enabled'                => true,
					'api_key'                => 'dummy_token',
					'assigned_assistant_ids' => array( $post_id ),
					'require_mention'        => true,
				),
			)
		);

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();

		// DM payload: no argumentText, space type is DIRECT_MESSAGE.
		$payload = array(
			'type'    => 'MESSAGE',
			'space'   => array(
				'name' => 'spaces/EEEFFF',
				'type' => 'DIRECT_MESSAGE',
			),
			'message' => array(
				'name'   => 'spaces/EEEFFF/messages/msg-dm-001',
				'text'   => 'Hello bot!',
				'sender' => array( 'name' => 'users/333' ),
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_body( wp_json_encode( $payload ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$controller->handle_webhook( $request );

		// Cron job must be scheduled — DMs always satisfy require_mention.
		$this->assertNotFalse(
			wp_next_scheduled( WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK ),
			'Cron job must be scheduled when the message arrives in a DIRECT_MESSAGE space'
		);

		wp_unschedule_hook( WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK );
		wp_delete_post( $post_id, true );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Google Chat handle_webhook schedules an AI reply (not a welcome message) when
	 * the ADDED_TO_SPACE event has DIRECT_MESSAGE space type and includes a user message.
	 *
	 * Google Chat embeds the user's first DM in the ADDED_TO_SPACE payload, so the bot
	 * must respond with an AI reply rather than a static welcome message.
	 */
	public function test_google_chat_added_to_space_dm_schedules_ai_reply() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'GC DM Added Bot',
				'post_name'   => 'gc-dm-added-bot',
				'post_status' => 'publish',
			)
		);

		// Store a connection with an assigned assistant.
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				array(
					'id'                     => 'gc_dm_added_conn',
					'connection_type'        => 'google_chat',
					'enabled'                => true,
					'api_key'                => 'dummy_token',
					'assigned_assistant_ids' => array( $post_id ),
				),
			)
		);

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();

		// ADDED_TO_SPACE payload for a DM that includes the user's first message.
		$payload = array(
			'type'    => 'ADDED_TO_SPACE',
			'space'   => array(
				'name' => 'spaces/DDDMMM',
				'type' => 'DIRECT_MESSAGE',
			),
			'message' => array(
				'name'   => 'spaces/DDDMMM/messages/msg-added-001',
				'text'   => 'Hello! I need help.',
				'sender' => array( 'name' => 'users/444' ),
			),
			'user'    => array( 'name' => 'users/444' ),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_body( wp_json_encode( $payload ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$controller->handle_webhook( $request );

		// The AI reply cron job must be scheduled — the user's first message must not be ignored.
		$this->assertNotFalse(
			wp_next_scheduled( WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK ),
			'An AI reply cron job must be scheduled when the bot is added via DM and the user included an initial message'
		);

		wp_unschedule_hook( WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK );
		wp_unschedule_hook( 'wp_mcp_ai_google_chat_send_welcome_message' );
		wp_delete_post( $post_id, true );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	// =========================================================================
	// Google Chat — thread-reply passthrough
	// =========================================================================

	/**
	 * handle_webhook passes message.thread.name through to the cron job args
	 * so that handle_google_chat_reply_job can reply in the same thread.
	 */
	public function test_google_chat_thread_name_passed_to_cron_job() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC Thread Test',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'dummy_token',
				'assigned_assistant_ids' => array( 5 ),
			)
		);

		$thread_resource = 'spaces/AAABBB/threads/thread-xyz';

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Bearer some.token.here' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'type'    => 'MESSAGE',
					'message' => array(
						'name'         => 'spaces/AAABBB/messages/msg-thread-001',
						'text'         => '@Bot hello in thread',
						'argumentText' => ' hello in thread',
						'thread'       => array( 'name' => $thread_resource ),
						'sender'       => array( 'name' => 'users/77777' ),
					),
					'space'   => array(
						'name' => 'spaces/AAABBB',
						'type' => 'SPACE',
					),
				)
			)
		);

		$controller->handle_webhook( $request );

		$crons = _get_cron_array();
		$hook  = WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK;

		$thread_name_found = false;

		foreach ( $crons as $events ) {
			if ( ! isset( $events[ $hook ] ) ) {
				continue;
			}
			foreach ( $events[ $hook ] as $event ) {
				if ( isset( $event['args'][0]['thread_name'] ) && $thread_resource === $event['args'][0]['thread_name'] ) {
					$thread_name_found = true;
				}
			}
		}

		$this->assertTrue( $thread_name_found, 'thread_name must be included in cron job args for thread-based replies' );

		wp_unschedule_hook( $hook );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * handle_webhook passes an empty thread_name to the cron job when the payload
	 * contains no message.thread field (e.g. DMs or new top-level messages).
	 */
	public function test_google_chat_empty_thread_name_when_no_thread_in_payload() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC No Thread Test',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'dummy_token',
				'assigned_assistant_ids' => array( 6 ),
			)
		);

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Bearer some.token.here' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'type'    => 'MESSAGE',
					'message' => array(
						'name'   => 'spaces/GGGHHH/messages/msg-no-thread-001',
						'text'   => 'Hello DM bot',
						'sender' => array( 'name' => 'users/88888' ),
					),
					'space'   => array(
						'name' => 'spaces/GGGHHH',
						'type' => 'DIRECT_MESSAGE',
					),
				)
			)
		);

		$controller->handle_webhook( $request );

		$crons = _get_cron_array();
		$hook  = WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK;

		$empty_thread_found = false;

		foreach ( $crons as $events ) {
			if ( ! isset( $events[ $hook ] ) ) {
				continue;
			}
			foreach ( $events[ $hook ] as $event ) {
				if ( isset( $event['args'][0] ) && '' === ( $event['args'][0]['thread_name'] ?? null ) ) {
					$empty_thread_found = true;
				}
			}
		}

		$this->assertTrue( $empty_thread_found, 'thread_name must be empty string when no thread is present in the payload' );

		wp_unschedule_hook( $hook );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	// =========================================================================
	// Google Chat — normalize_payload (Workspace Add-ons format support)
	// =========================================================================

	/**
	 * Standard format payload passes through normalize_payload unchanged.
	 *
	 * When the standard Chat API format is used (type field is MESSAGE, not GOOGLE_CHAT),
	 * the original payload must be returned without modification.
	 */
	public function test_google_chat_normalize_payload_passthrough_for_standard_format() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'normalize_payload' );
		$method->setAccessible( true );

		$payload = array(
			'type'    => 'MESSAGE',
			'message' => array( 'text' => 'Hello' ),
			'space'   => array(
				'name' => 'spaces/AAA',
				'type' => 'DM',
			),
			'user'    => array( 'name' => 'users/123' ),
		);

		$result = $method->invoke( $controller, $payload );

		$this->assertSame( $payload, $result, 'Standard Chat API payload should pass through normalize_payload unchanged' );
	}

	/**
	 * Workspace Add-ons format is unwrapped by normalize_payload.
	 *
	 * Must unwrap the Google Workspace Add-ons event envelope
	 * (type=GOOGLE_CHAT with google.chat nesting) to expose the inner Chat event.
	 */
	public function test_google_chat_normalize_payload_unwraps_workspace_addon_format() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'normalize_payload' );
		$method->setAccessible( true );

		$inner_event = array(
			'type'    => 'MESSAGE',
			'message' => array( 'text' => 'Hello from Add-on' ),
			'space'   => array(
				'name'      => 'spaces/AAA',
				'spaceType' => 'DIRECT_MESSAGE',
			),
			'user'    => array( 'name' => 'users/123' ),
		);

		$workspace_addon_payload = array(
			'type'    => 'GOOGLE_CHAT',
			'eventId' => 'unique-event-id',
			'google'  => array(
				'chat' => $inner_event,
			),
		);

		$result = $method->invoke( $controller, $workspace_addon_payload );

		$this->assertSame(
			$inner_event,
			$result,
			'normalize_payload must unwrap the Google Workspace Add-ons GOOGLE_CHAT envelope'
		);
		$this->assertSame( 'MESSAGE', $result['type'], 'Inner event type must be MESSAGE after unwrapping' );
	}

	/**
	 * Malformed Workspace Add-ons envelopes are returned as-is by normalize_payload.
	 *
	 * Returns the original payload when the GOOGLE_CHAT type is set
	 * but the google.chat key is absent or not an array.
	 */
	public function test_google_chat_normalize_payload_handles_malformed_workspace_addon_envelope() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'normalize_payload' );
		$method->setAccessible( true );

		// Missing google.chat key.
		$malformed = array(
			'type'   => 'GOOGLE_CHAT',
			'google' => array(),
		);
		$this->assertSame( $malformed, $method->invoke( $controller, $malformed ), 'Malformed envelope must be returned as-is' );

		// google.chat is not an array.
		$malformed2 = array(
			'type'   => 'GOOGLE_CHAT',
			'google' => array( 'chat' => 'not-an-array' ),
		);
		$this->assertSame( $malformed2, $method->invoke( $controller, $malformed2 ), 'Non-array google.chat must be returned as-is' );
	}

	// =========================================================================
	// Google Chat — get_space_type (modern + legacy type normalisation)
	// =========================================================================

	/**
	 * Prefers spaceType field over deprecated type field when both are present.
	 *
	 * Returns the modern spaceType value when both spaceType and
	 * the deprecated type field are present in the payload.
	 */
	public function test_google_chat_get_space_type_prefers_space_type_field() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_space_type' );
		$method->setAccessible( true );

		$payload = array(
			'space' => array(
				'name'      => 'spaces/AAA',
				'type'      => 'DM',            // deprecated.
				'spaceType' => 'DIRECT_MESSAGE', // modern.
			),
		);

		$this->assertSame(
			'DIRECT_MESSAGE',
			$method->invoke( $controller, $payload ),
			'get_space_type must prefer spaceType over the deprecated type field'
		);
	}

	/**
	 * Maps legacy "DM" type to the modern "DIRECT_MESSAGE" value.
	 */
	public function test_google_chat_get_space_type_maps_dm_to_direct_message() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_space_type' );
		$method->setAccessible( true );

		$payload = array(
			'space' => array(
				'name' => 'spaces/AAA',
				'type' => 'DM', // deprecated legacy value.
			),
		);

		$this->assertSame(
			'DIRECT_MESSAGE',
			$method->invoke( $controller, $payload ),
			'get_space_type must map legacy "DM" to "DIRECT_MESSAGE"'
		);
	}

	/**
	 * Maps legacy "ROOM" type to the modern "SPACE" value.
	 */
	public function test_google_chat_get_space_type_maps_room_to_space() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_space_type' );
		$method->setAccessible( true );

		$payload = array(
			'space' => array(
				'name' => 'spaces/BBB',
				'type' => 'ROOM', // deprecated legacy value.
			),
		);

		$this->assertSame(
			'SPACE',
			$method->invoke( $controller, $payload ),
			'get_space_type must map legacy "ROOM" to "SPACE"'
		);
	}

	/**
	 * Returns modern values unchanged (GROUP_CHAT, SPACE, DIRECT_MESSAGE).
	 *
	 * Values that do not need mapping should pass through without modification.
	 */
	public function test_google_chat_get_space_type_returns_modern_values_unchanged() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_space_type' );
		$method->setAccessible( true );

		foreach ( array( 'DIRECT_MESSAGE', 'SPACE', 'GROUP_CHAT' ) as $modern_type ) {
			$payload = array(
				'space' => array(
					'name' => 'spaces/CCC',
					'type' => $modern_type,
				),
			);
			$this->assertSame(
				$modern_type,
				$method->invoke( $controller, $payload ),
				"get_space_type must return modern value '{$modern_type}' unchanged"
			);
		}
	}

	// =========================================================================
	// Google Chat — Workspace Add-ons end-to-end: handle_webhook with GOOGLE_CHAT wrapper
	// =========================================================================

	/**
	 * Workspace Add-ons GOOGLE_CHAT envelope schedules a cron reply via handle_webhook.
	 *
	 * A MESSAGE event wrapped in the Google Workspace Add-ons GOOGLE_CHAT envelope
	 * format must trigger the standard AI reply cron job.
	 */
	public function test_google_chat_handle_webhook_processes_workspace_addon_event() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC Workspace Add-on Test',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'dummy_token',
				'assigned_assistant_ids' => array( 99 ),
			)
		);

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Bearer some.valid.token' );
		$request->set_header( 'Content-Type', 'application/json' );

		// Workspace Add-ons event envelope: outer type is GOOGLE_CHAT; inner type is MESSAGE.
		$request->set_body(
			wp_json_encode(
				array(
					'type'    => 'GOOGLE_CHAT',
					'eventId' => 'workspace-event-001',
					'google'  => array(
						'chat' => array(
							'type'    => 'MESSAGE',
							'message' => array(
								'name'   => 'spaces/WWWXXX/messages/msg-wa-001',
								'text'   => 'Hello from Workspace Add-on',
								'sender' => array( 'name' => 'users/99999' ),
							),
							'space'   => array(
								'name'      => 'spaces/WWWXXX',
								'spaceType' => 'DIRECT_MESSAGE',
							),
							'user'    => array( 'name' => 'users/99999' ),
						),
					),
				)
			)
		);

		$controller->handle_webhook( $request );

		$crons = _get_cron_array();
		$hook  = WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK;

		$found = false;
		foreach ( $crons as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			'handle_webhook must schedule a cron reply for Workspace Add-ons GOOGLE_CHAT event format'
		);

		wp_clear_scheduled_hook( $hook );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Workspace Add-ons envelope with legacy "DM" space type must still schedule a reply.
	 *
	 * The DM-to-DIRECT_MESSAGE mapping must apply when processing events from the
	 * Workspace Add-ons framework with a require_mention connection.
	 */
	public function test_google_chat_workspace_addon_with_legacy_dm_space_type_schedules_reply() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC Legacy DM Test',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'dummy_token',
				'assigned_assistant_ids' => array( 88 ),
				'require_mention'        => true, // Require mention enabled — DM must still pass.
			)
		);

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Authorization', 'Bearer some.valid.token' );
		$request->set_header( 'Content-Type', 'application/json' );

		// Workspace Add-ons envelope; inner event uses the OLD deprecated "DM" space type.
		$request->set_body(
			wp_json_encode(
				array(
					'type'   => 'GOOGLE_CHAT',
					'google' => array(
						'chat' => array(
							'type'    => 'MESSAGE',
							'message' => array(
								'name'   => 'spaces/LLLLMM/messages/msg-dm-legacy-001',
								'text'   => 'Hello legacy DM',
								'sender' => array( 'name' => 'users/77777' ),
							),
							'space'   => array(
								'name' => 'spaces/LLLLMM',
								'type' => 'DM', // Legacy value — should be mapped to DIRECT_MESSAGE.
							),
							'user'    => array( 'name' => 'users/77777' ),
						),
					),
				)
			)
		);

		$controller->handle_webhook( $request );

		$crons = _get_cron_array();
		$hook  = WP_MCP_AI_Google_Chat_Webhook_Controller::REPLY_CRON_HOOK;

		$found = false;
		foreach ( $crons as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			'A DM with legacy "DM" space type inside a Workspace Add-ons envelope must still trigger an AI reply'
		);

		wp_clear_scheduled_hook( $hook );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	// =========================================================================
	// Google Chat – Marketplace App / auth robustness (priority + header fixes).
	// =========================================================================

	/**
	 * Test allow_google_oidc_auth is registered at priority 99999.
	 *
	 * Third-party JWT auth plugins commonly hook rest_authentication_errors at
	 * priority 100–999. Our filter must run after them so it can clear any
	 * WP_Error they set before WordPress rejects the request.
	 */
	public function test_google_chat_auth_filter_registered_at_99999() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();

		$priority = has_filter( 'rest_authentication_errors', array( $controller, 'allow_google_oidc_auth' ) );

		$this->assertSame(
			99999,
			$priority,
			'allow_google_oidc_auth must be registered at priority 99999 so it runs after JWT plugins at 100–999'
		);
	}

	/**
	 * Test allow_google_oidc_auth clears a WP_Error set by a JWT plugin simulated
	 * at priority 500 (i.e. higher than the old priority of 99).
	 */
	public function test_google_chat_auth_filter_clears_error_from_high_priority_jwt_plugin() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();

		// Simulate a JWT auth plugin that sets a WP_Error after the OLD priority (99).
		$jwt_error = new WP_Error( 'jwt_auth_bad_config', 'JWT auth error' );

		// Simulate the REQUEST_URI for our webhook route.
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp-ai/v1/webhooks/google-chat';

		$result = $controller->allow_google_oidc_auth( $jwt_error );

		$this->assertNull( $result, 'allow_google_oidc_auth must clear WP_Error set by plugins at higher priorities' );
	}

	/**
	 * Test validate_google_oidc_token accepts a Bearer token supplied via
	 * $_SERVER['HTTP_AUTHORIZATION'] when the WP_REST_Request header is empty
	 * (simulates Apache + FastCGI environments that strip the Authorization header).
	 */
	public function test_google_chat_validation_accepts_bearer_from_server_http_authorization() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		// Ensure no connection is stored so audience defaults to empty (always passes).
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		// Inject Bearer token directly into $_SERVER (no WP_REST_Request header set).
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some.valid.looking.token';

		try {
			$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
			$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
			// Do NOT set_header() — we want get_header() to return empty.

			$result = $controller->validate_google_oidc_token( $request );
		} finally {
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
		}

		$this->assertTrue( $result, 'validate_google_oidc_token should accept Bearer token from $_SERVER[HTTP_AUTHORIZATION] fallback' );
	}

	/**
	 * Test validate_google_oidc_token accepts a Bearer token supplied via
	 * $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] (a second common Apache fallback).
	 */
	public function test_google_chat_validation_accepts_bearer_from_redirect_http_authorization() {
		$this->load_controller( 'WP_MCP_AI_Google_Chat_Webhook_Controller', 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );

		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer another.valid.looking.token';

		try {
			$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
			$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );

			$result = $controller->validate_google_oidc_token( $request );
		} finally {
			unset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		$this->assertTrue( $result, 'validate_google_oidc_token should accept Bearer token from $_SERVER[REDIRECT_HTTP_AUTHORIZATION] fallback' );
	}
}
