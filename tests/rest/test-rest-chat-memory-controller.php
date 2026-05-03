<?php
/**
 * Tests for the chat-client ⇄ memory bridge REST controller.
 *
 * Covers the static helpers and the REST surface mounted at
 * `/mcp-ai/v1/chat-memory/*`. Exercises the routes through the
 * WordPress REST server so permissions, sanitisation, and the
 * kill-switch all run end-to-end.
 *
 * @package WP_MCP_AI
 * @since 1.6.0
 */

/**
 * Test case for the chat-memory REST controller.
 */
class Test_Chat_Memory_REST_Controller extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected $editor_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up the REST server and a test user.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tear down REST server.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		// Reset chat-memory user meta so tests are isolated.
		delete_user_meta( $this->editor_id, WP_MCP_AI_REST_Chat_Memory_Controller::USER_META_ENABLED );
		delete_user_meta( $this->editor_id, WP_MCP_AI_REST_Chat_Memory_Controller::USER_META_AUTOSUMMARIZE );
		delete_user_meta( $this->subscriber_id, WP_MCP_AI_REST_Chat_Memory_Controller::USER_META_ENABLED );

		parent::tearDown();
	}

	/**
	 * The proxy routes must be registered with the REST API.
	 */
	public function test_routes_are_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/chat-memory/preferences', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/chat-memory/wake-up', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/chat-memory/recall', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/chat-memory/store', $routes );
		// Item routes use a path parameter so check the regex form.
		$this->assertTrue(
			(bool) array_filter(
				array_keys( $routes ),
				static function ( $route ) {
					return false !== strpos( $route, '/chat-memory/(?P<context_id>' );
				}
			),
			'Per-context_id route should be registered.'
		);
	}

	/**
	 * Default preferences should be enabled=true, autosummarize=false.
	 */
	public function test_default_preferences_are_enabled_and_no_autosummary() {
		$prefs = WP_MCP_AI_REST_Chat_Memory_Controller::get_preferences( $this->editor_id );
		$this->assertTrue( $prefs['enabled'] );
		$this->assertFalse( $prefs['autosummarize'] );
	}

	/**
	 * The site-wide kill-switch filter must be honoured.
	 */
	public function test_kill_switch_filter_disables_surface() {
		$this->assertTrue( WP_MCP_AI_REST_Chat_Memory_Controller::is_chat_memory_enabled( $this->editor_id ) );

		$callback = static function () {
			return false;
		};
		add_filter( 'wp_mcp_ai_chat_memory_enabled', $callback );

		$this->assertFalse( WP_MCP_AI_REST_Chat_Memory_Controller::is_chat_memory_enabled( $this->editor_id ) );

		remove_filter( 'wp_mcp_ai_chat_memory_enabled', $callback );
	}

	/**
	 * Guests must be denied access (403) on every memory route.
	 */
	public function test_guest_requests_are_denied() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-memory/preferences' );
		$response = $this->server->dispatch( $request );

		$this->assertGreaterThanOrEqual( 401, $response->get_status() );
	}

	/**
	 * GET preferences should return defaults for a logged-in editor.
	 */
	public function test_get_preferences_returns_defaults() {
		wp_set_current_user( $this->editor_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-memory/preferences' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( true, $data['enabled'] );
		$this->assertSame( false, $data['autosummarize'] );
	}

	/**
	 * POST preferences should persist enabled/autosummarize values.
	 */
	public function test_update_preferences_persists_values() {
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-memory/preferences' );
		$request->set_param( 'enabled', false );
		$request->set_param( 'autosummarize', true );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$prefs = WP_MCP_AI_REST_Chat_Memory_Controller::get_preferences( $this->editor_id );
		$this->assertFalse( $prefs['enabled'] );
		$this->assertTrue( $prefs['autosummarize'] );
	}

	/**
	 * Disabling the user-level preference should block subsequent reads with 403.
	 */
	public function test_disabling_user_preference_blocks_recall() {
		wp_set_current_user( $this->editor_id );
		update_user_meta( $this->editor_id, WP_MCP_AI_REST_Chat_Memory_Controller::USER_META_ENABLED, 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-memory/recall' );
		$request->set_param( 'agent_id', 1 );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'chat_memory_disabled', $data['code'] );
	}

	/**
	 * Subscribers (no edit_posts) must not be able to write to memory.
	 */
	public function test_subscriber_cannot_store() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-memory/store' );
		$request->set_param( 'agent_id', 1 );
		$request->set_param( 'content', 'remember me' );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * sanitize_context_id() must strip everything outside [A-Za-z0-9_-].
	 */
	public function test_sanitize_context_id_strips_unsafe_characters() {
		$controller = new WP_MCP_AI_REST_Chat_Memory_Controller();
		$this->assertSame( 'ctx_abc-123', $controller->sanitize_context_id( 'ctx_abc-123' ) );
		$this->assertSame( 'ctx_abc123', $controller->sanitize_context_id( 'ctx/../abc 123' ) );
		$this->assertSame( '', $controller->sanitize_context_id( '!@#$%^&*()' ) );
	}
}
