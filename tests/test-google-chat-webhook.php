<?php
/**
 * Google Chat Webhook Handler Tests
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return; // Skip if the pro addon is not active.
}

require_once WP_MCP_AI_PRO_PATH . 'includes/src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php';

/**
 * Tests for the Google Chat incoming webhook handler.
 *
 * @covers WP_MCP_AI_Google_Chat_Webhook_Handler
 */
class WP_MCP_AI_Google_Chat_Webhook_Handler_Test extends WP_UnitTestCase {

	/**
	 * Webhook handler instance.
	 *
	 * @var WP_MCP_AI_Google_Chat_Webhook_Handler
	 */
	protected $handler;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->handler = new WP_MCP_AI_Google_Chat_Webhook_Handler();
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	/**
	 * Test that webhook handler registers the REST route.
	 */
	public function test_register_routes() {
		$this->handler->register_routes();

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/webhooks/google-chat', $routes );
	}

	// ------------------------------------------------------------------
	// handle_webhook — empty / malformed body
	// ------------------------------------------------------------------

	/**
	 * Test that an empty body returns HTTP 200 with empty text.
	 */
	public function test_handle_webhook_empty_body_returns_200() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_body( '' );

		$response = $this->handler->handle_webhook( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '', $data['text'] );
	}

	/**
	 * Test that an unknown event type returns HTTP 200 with empty text.
	 */
	public function test_handle_webhook_unknown_event_type_returns_200() {
		$event = array( 'type' => 'UNKNOWN_EVENT' );

		$request  = $this->make_request( $event );
		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['text'] );
	}

	// ------------------------------------------------------------------
	// MESSAGE events
	// ------------------------------------------------------------------

	/**
	 * Test that a DM MESSAGE event returns HTTP 200.
	 */
	public function test_handle_webhook_dm_message_returns_200() {
		$event = $this->make_message_event( 'DM', 'Hello bot!' );

		$request  = $this->make_request( $event );
		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test that a ROOM (Space) MESSAGE event returns HTTP 200.
	 */
	public function test_handle_webhook_space_message_returns_200() {
		$event = $this->make_message_event( 'ROOM', '<users/12345> what can you do?' );

		$request  = $this->make_request( $event );
		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test that MESSAGE action hooks fire with cleaned text.
	 */
	public function test_handle_webhook_message_action_hook_fires() {
		$received_clean = null;
		$received_raw   = null;

		add_action(
			'wp_mcp_ai_google_chat_message',
			function ( $clean, $raw, $space_type, $message, $event ) use ( &$received_clean, &$received_raw ) {
				$received_clean = $clean;
				$received_raw   = $raw;
			},
			10,
			5
		);

		$raw_text = '<users/67890> tell me a joke';
		$event    = $this->make_message_event( 'ROOM', $raw_text );
		$request  = $this->make_request( $event );

		$this->handler->handle_webhook( $request );

		$this->assertSame( 'tell me a joke', $received_clean );
		$this->assertSame( $raw_text, $received_raw );
	}

	/**
	 * Test that ROOM message fires the space-specific action hook.
	 */
	public function test_handle_webhook_room_message_fires_space_hook() {
		$space_hook_fired = false;
		$dm_hook_fired    = false;

		add_action(
			'wp_mcp_ai_google_chat_message_in_space',
			function () use ( &$space_hook_fired ) {
				$space_hook_fired = true;
			}
		);

		add_action(
			'wp_mcp_ai_google_chat_message_in_dm',
			function () use ( &$dm_hook_fired ) {
				$dm_hook_fired = true;
			}
		);

		$event   = $this->make_message_event( 'ROOM', '<users/1> hi' );
		$request = $this->make_request( $event );

		$this->handler->handle_webhook( $request );

		$this->assertTrue( $space_hook_fired, 'Space-specific hook should fire for ROOM messages' );
		$this->assertFalse( $dm_hook_fired, 'DM hook should NOT fire for ROOM messages' );
	}

	/**
	 * Test that DM message fires the DM-specific action hook.
	 */
	public function test_handle_webhook_dm_message_fires_dm_hook() {
		$space_hook_fired = false;
		$dm_hook_fired    = false;

		add_action(
			'wp_mcp_ai_google_chat_message_in_space',
			function () use ( &$space_hook_fired ) {
				$space_hook_fired = true;
			}
		);

		add_action(
			'wp_mcp_ai_google_chat_message_in_dm',
			function () use ( &$dm_hook_fired ) {
				$dm_hook_fired = true;
			}
		);

		$event   = $this->make_message_event( 'DM', 'hello' );
		$request = $this->make_request( $event );

		$this->handler->handle_webhook( $request );

		$this->assertFalse( $space_hook_fired, 'Space hook should NOT fire for DM messages' );
		$this->assertTrue( $dm_hook_fired, 'DM-specific hook should fire for DM messages' );
	}

	/**
	 * Test that response text filter is applied for MESSAGE events.
	 */
	public function test_handle_webhook_message_response_filter_applied() {
		add_filter(
			'wp_mcp_ai_google_chat_message_response',
			function () {
				return 'I can help you with many things!';
			}
		);

		$event    = $this->make_message_event( 'DM', 'what can you do?' );
		$request  = $this->make_request( $event );
		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 'I can help you with many things!', $response->get_data()['text'] );
	}

	// ------------------------------------------------------------------
	// ADDED_TO_SPACE events
	// ------------------------------------------------------------------

	/**
	 * Test that ADDED_TO_SPACE event returns a greeting.
	 */
	public function test_handle_webhook_added_to_space_returns_greeting() {
		$event = array(
			'type'  => 'ADDED_TO_SPACE',
			'space' => array(
				'type'        => 'ROOM',
				'displayName' => 'Engineering Team',
			),
		);

		$request  = $this->make_request( $event );
		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 200, $response->get_status() );
		$text = $response->get_data()['text'];
		$this->assertNotEmpty( $text, 'ADDED_TO_SPACE should return a non-empty greeting' );
	}

	/**
	 * Test that ADDED_TO_SPACE action hook fires.
	 */
	public function test_handle_webhook_added_to_space_action_hook_fires() {
		$received_name = null;

		add_action(
			'wp_mcp_ai_google_chat_added_to_space',
			function ( $space_name, $space, $event ) use ( &$received_name ) {
				$received_name = $space_name;
			},
			10,
			3
		);

		$event = array(
			'type'  => 'ADDED_TO_SPACE',
			'space' => array(
				'type'        => 'ROOM',
				'displayName' => 'Test Space',
			),
		);

		$request = $this->make_request( $event );
		$this->handler->handle_webhook( $request );

		$this->assertSame( 'Test Space', $received_name );
	}

	// ------------------------------------------------------------------
	// REMOVED_FROM_SPACE events
	// ------------------------------------------------------------------

	/**
	 * Test that REMOVED_FROM_SPACE event returns empty text (no reply needed).
	 */
	public function test_handle_webhook_removed_from_space_returns_empty_text() {
		$event = array(
			'type'  => 'REMOVED_FROM_SPACE',
			'space' => array(
				'type'        => 'ROOM',
				'displayName' => 'Engineering Team',
			),
		);

		$request  = $this->make_request( $event );
		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['text'] );
	}

	/**
	 * Test that REMOVED_FROM_SPACE action hook fires.
	 */
	public function test_handle_webhook_removed_from_space_action_hook_fires() {
		$hook_fired = false;

		add_action(
			'wp_mcp_ai_google_chat_removed_from_space',
			function () use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		$event = array(
			'type'  => 'REMOVED_FROM_SPACE',
			'space' => array( 'type' => 'ROOM' ),
		);

		$request = $this->make_request( $event );
		$this->handler->handle_webhook( $request );

		$this->assertTrue( $hook_fired );
	}

	// ------------------------------------------------------------------
	// strip_mention_markup
	// ------------------------------------------------------------------

	/**
	 * Test that a single mention is stripped.
	 */
	public function test_strip_mention_markup_single_mention() {
		$result = $this->handler->strip_mention_markup( '<users/123456789> what can you do?' );
		$this->assertSame( 'what can you do?', $result );
	}

	/**
	 * Test that multiple mentions are stripped.
	 */
	public function test_strip_mention_markup_multiple_mentions() {
		$result = $this->handler->strip_mention_markup( '<users/111> hello <users/222> world' );
		$this->assertSame( 'hello world', $result );
	}

	/**
	 * Test that text without mentions is returned unchanged.
	 */
	public function test_strip_mention_markup_no_mention() {
		$result = $this->handler->strip_mention_markup( 'hello world' );
		$this->assertSame( 'hello world', $result );
	}

	/**
	 * Test that an all-user mention (<users/all>) is stripped.
	 */
	public function test_strip_mention_markup_all_users_mention() {
		$result = $this->handler->strip_mention_markup( '<users/all> team announcement' );
		$this->assertSame( 'team announcement', $result );
	}

	/**
	 * Test that a non-string input returns an empty string.
	 */
	public function test_strip_mention_markup_non_string_returns_empty() {
		$result = $this->handler->strip_mention_markup( null );
		$this->assertSame( '', $result );
	}

	/**
	 * Test that an empty string input returns an empty string.
	 */
	public function test_strip_mention_markup_empty_string() {
		$result = $this->handler->strip_mention_markup( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test that surrounding whitespace is trimmed.
	 */
	public function test_strip_mention_markup_trims_whitespace() {
		$result = $this->handler->strip_mention_markup( '<users/99>   hello   ' );
		$this->assertSame( 'hello', $result );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_google_chat_response_text filter
	// ------------------------------------------------------------------

	/**
	 * Test that the global response text filter overrides the handler result.
	 */
	public function test_global_response_text_filter_overrides() {
		add_filter(
			'wp_mcp_ai_google_chat_response_text',
			function () {
				return 'Overridden response';
			}
		);

		$event   = $this->make_message_event( 'DM', 'hello' );
		$request = $this->make_request( $event );

		$response = $this->handler->handle_webhook( $request );

		$this->assertSame( 'Overridden response', $response->get_data()['text'] );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Create a WP_REST_Request with a JSON body.
	 *
	 * @param array $event Event payload.
	 * @return WP_REST_Request
	 */
	private function make_request( array $event ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $event ) );
		return $request;
	}

	/**
	 * Build a minimal Google Chat MESSAGE event payload.
	 *
	 * @param string $space_type 'DM' or 'ROOM'.
	 * @param string $text       Message text.
	 * @return array
	 */
	private function make_message_event( $space_type, $text ) {
		return array(
			'type'    => 'MESSAGE',
			'message' => array(
				'text' => $text,
			),
			'space'   => array(
				'type' => $space_type,
			),
		);
	}
}
