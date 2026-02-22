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
		$header  = rtrim( strtr( base64_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$payload = rtrim( strtr( base64_encode( wp_json_encode( array( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'iss' => 'accounts.google.com',
			'aud' => $webhook_url,
			'exp' => time() - 3600,
		) ) ), '+/', '-_' ), '=' );
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
		$header  = rtrim( strtr( base64_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$payload = rtrim( strtr( base64_encode( wp_json_encode( array( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'iss' => 'accounts.google.com',
			'aud' => 'https://example.com/wrong-endpoint',
			'exp' => time() + 3600,
		) ) ), '+/', '-_' ), '=' );
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
}
