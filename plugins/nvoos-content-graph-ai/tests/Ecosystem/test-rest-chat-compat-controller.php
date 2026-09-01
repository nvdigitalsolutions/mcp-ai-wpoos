<?php
/**
 * Chat compat REST controller port tests (Wave D5d).
 *
 * Characterization suite for `ChatCompatController`. Assertions mirror
 * the base plugin's `mcp-ai/v1/chat` surface: constants, route
 * registration (standalone-only), the messages-array validation rules,
 * the options-envelope → CG-AI params translation (provider/model/
 * temperature clamp/stream), message normalization, the GET SSE
 * deferral, and permission gates.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Rest\ChatCompatController;
use NvoosContentGraphAi\Rest\ChatController;

/**
 * @group rest
 */
class Test_Chat_Compat_Controller extends \WP_UnitTestCase {

	/**
	 * Controller instance under test.
	 *
	 * @var ChatCompatController
	 */
	private $controller;

	public function setUp(): void {
		parent::setUp();
		$this->controller = new ChatCompatController( new ChatController() );
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Build a base-shaped chat request.
	 *
	 * @param array $params Request params.
	 * @return WP_REST_Request
	 */
	private function chat_request( array $params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * A single valid user message.
	 *
	 * @return array
	 */
	private function user_message(): array {
		return array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);
	}

	// ─── Constants + route registration ─────────────────────────────

	public function test_constants_match_base(): void {
		$this->assertSame( 'mcp-ai/v1', ChatCompatController::REST_NAMESPACE );
	}

	public function test_routes_register_standalone_only(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// The base plugin owns these routes in monolith installs.
			$this->assertTrue( true );
			return;
		}

		// The ecosystem bootstrap requires the plugin after plugins_loaded
		// has fired, so Plugin::register() never runs here — register via
		// a rest_api_init firing to stay on the action (WP 6.9 flags
		// off-action registration as incorrect usage).
		$server     = \rest_get_server();
		$controller = $this->controller;
		\add_action(
			'rest_api_init',
			static function () use ( $controller ): void {
				$controller->registerRoutes();
			}
		);
		\do_action( 'rest_api_init', $server );

		$routes = $server->get_routes( 'mcp-ai/v1' );
		$this->assertArrayHasKey( '/mcp-ai/v1/chat', $routes );
	}

	// ─── Messages validation (base validator rules) ─────────────────

	public function test_validate_messages_array_rejects_non_array(): void {
		$result = $this->controller->validate_messages_array( 'nope', new \WP_REST_Request(), 'messages' );
		$this->assertWPError( $result );
	}

	public function test_validate_messages_array_rejects_empty(): void {
		$result = $this->controller->validate_messages_array( array(), new \WP_REST_Request(), 'messages' );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'cannot be empty', $result->get_error_message() );
	}

	public function test_validate_messages_array_rejects_non_array_message(): void {
		$result = $this->controller->validate_messages_array( array( 'hi' ), new \WP_REST_Request(), 'messages' );
		$this->assertWPError( $result );
	}

	public function test_validate_messages_array_requires_role(): void {
		$result = $this->controller->validate_messages_array(
			array( array( 'content' => 'x' ) ),
			new \WP_REST_Request(),
			'messages'
		);
		$this->assertWPError( $result );
	}

	public function test_validate_messages_array_requires_content(): void {
		$result = $this->controller->validate_messages_array(
			array( array( 'role' => 'user' ) ),
			new \WP_REST_Request(),
			'messages'
		);
		$this->assertWPError( $result );
	}

	public function test_validate_messages_array_allows_assistant_without_content(): void {
		$result = $this->controller->validate_messages_array(
			array( array( 'role' => 'assistant' ) ),
			new \WP_REST_Request(),
			'messages'
		);
		$this->assertTrue( $result );
	}

	public function test_validate_messages_array_accepts_valid_messages(): void {
		$result = $this->controller->validate_messages_array(
			$this->user_message(),
			new \WP_REST_Request(),
			'messages'
		);
		$this->assertTrue( $result );
	}

	// ─── Options-envelope translation ───────────────────────────────

	public function test_translate_request_maps_options(): void {
		$translated = $this->controller->translate_request(
			$this->chat_request(
				array(
					'messages' => $this->user_message(),
					'options'  => array(
						'provider'    => 'gemini',
						'model'       => 'gemini-1.5-pro',
						'temperature' => 1.5,
						'stream'      => true,
					),
				)
			)
		);

		$this->assertSame( 'gemini', $translated->get_param( 'provider' ) );
		$this->assertSame( 'gemini-1.5-pro', $translated->get_param( 'model' ) );
		$this->assertSame( 1.5, $translated->get_param( 'temperature' ) );
		$this->assertTrue( $translated->get_param( 'stream' ) );
	}

	public function test_translate_request_clamps_temperature(): void {
		$translated = $this->controller->translate_request(
			$this->chat_request(
				array(
					'messages' => $this->user_message(),
					'options'  => array( 'temperature' => 9 ),
				)
			)
		);
		$this->assertSame( 2.0, $translated->get_param( 'temperature' ) );

		$translated = $this->controller->translate_request(
			$this->chat_request(
				array(
					'messages' => $this->user_message(),
					'options'  => array( 'temperature' => -5 ),
				)
			)
		);
		$this->assertSame( 0.0, $translated->get_param( 'temperature' ) );
	}

	public function test_translate_request_defaults(): void {
		$translated = $this->controller->translate_request(
			$this->chat_request( array( 'messages' => $this->user_message() ) )
		);

		$this->assertSame( '', $translated->get_param( 'provider' ) );
		$this->assertSame( '', $translated->get_param( 'model' ) );
		$this->assertNull( $translated->get_param( 'temperature' ) );
		$this->assertFalse( $translated->get_param( 'stream' ) );
		$this->assertTrue( $translated->get_param( 'system_prompt' ) );
		$this->assertFalse( $translated->get_param( 'include_context' ) );
		$this->assertFalse( $translated->get_param( 'cache_system_prompt' ) );
	}

	public function test_translate_request_sanitizes_messages(): void {
		$translated = $this->controller->translate_request(
			$this->chat_request(
				array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => '<b>Hello</b><script>alert(1)</script>',
						),
					),
				)
			)
		);

		$messages = $translated->get_param( 'messages' );
		$this->assertCount( 1, $messages );
		$this->assertSame( 'user', $messages[0]['role'] );
		$this->assertStringNotContainsString( '<script>', $messages[0]['content'] );
	}

	public function test_translate_request_json_encodes_content_parts(): void {
		$translated = $this->controller->translate_request(
			$this->chat_request(
				array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => array(
								array( 'type' => 'input_text', 'text' => 'hi' ),
							),
						),
					),
				)
			)
		);

		$messages = $translated->get_param( 'messages' );
		$this->assertIsString( $messages[0]['content'] );
		$this->assertStringContainsString( 'input_text', $messages[0]['content'] );
	}

	public function test_translate_request_ignores_unported_params(): void {
		$translated = $this->controller->translate_request(
			$this->chat_request(
				array(
					'assistant_id'        => 'profession_123',
					'professional_prompt' => 'You are a doctor.',
					'messages'            => $this->user_message(),
					'options'             => array( 'response_format' => array( 'type' => 'json_object' ) ),
				)
			)
		);

		// Accepted for wire compatibility, not consulted yet (documented).
		$this->assertNull( $translated->get_param( 'assistant_id' ) );
		$this->assertNull( $translated->get_param( 'professional_prompt' ) );
		$this->assertNull( $translated->get_param( 'response_format' ) );
	}

	// ─── Handlers ───────────────────────────────────────────────────

	public function test_handle_chat_request_missing_messages_guard(): void {
		$response = $this->controller->handle_chat_request( $this->chat_request() );

		$this->assertWPError( $response );
		$this->assertSame( 'rest_invalid_param', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_handle_chat_request_delegates_and_surfaces_provider_error(): void {
		$response = $this->controller->handle_chat_request(
			$this->chat_request( array( 'messages' => $this->user_message() ) )
		);

		// No API keys are configured in the test environment, so the
		// translation must reach the orchestrator and come back with the
		// provider's missing-key payload. Faithful delegation: the
		// pre-existing /ai/chat surface wraps normalized provider errors
		// in the success envelope (success + data.code) — the compat
		// route reproduces that byte-for-byte (tracked CG-AI quirk).
		$this->assertNotWPError( $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'missing_api_key', $data['data']['code'] );
		$this->assertSame( 400, $data['data']['data']['status'] );
	}

	public function test_handle_chat_get_request_deferred(): void {
		$request  = new \WP_REST_Request( 'GET', '/mcp-ai/v1/chat' );
		$response = $this->controller->handle_chat_get_request( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_sse_chat_deferred', $response->get_error_code() );
		$this->assertSame( 501, $response->get_error_data()['status'] );
	}

	// ─── Permissions ────────────────────────────────────────────────

	public function test_permission_gates(): void {
		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );

		$this->assertWPError( $this->controller->permissions_check( $request ) );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		\wp_set_current_user( $author );
		$this->assertTrue( $this->controller->permissions_check( $request ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->assertWPError( $this->controller->permissions_check( $request ) );
	}
}
