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
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
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
	 * Build a REST request for the current user, adding the WordPress REST
	 * nonce header when a user is logged in.
	 *
	 * The controller's authenticator requires a valid `wp_rest` nonce for
	 * cookie-style auth; without the header every dispatched request falls
	 * through to the 401 unauthenticated branch regardless of
	 * `wp_set_current_user()`. This mirrors the trait helper
	 * `WP_MCP_AI_REST_Test_Helper::create_authenticated_request()`.
	 *
	 * @param string $method HTTP method (GET, POST, ...).
	 * @param string $route  REST route path.
	 * @param array  $params Optional request parameters.
	 * @return WP_REST_Request
	 */
	private function request_for_current_user( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( get_current_user_id() > 0 ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		return $request;
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
		$this->assertArrayHasKey( '/mcp-ai/v1/chat-memory/audit', $routes );
		$this->assertTrue(
			(bool) array_filter(
				array_keys( $routes ),
				static function ( $route ) {
					return false !== strpos( $route, '/chat-memory/sessions/(?P<session_id>' );
				}
			),
			'Per-session replay route should be registered.'
		);
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

		$request  = $this->request_for_current_user( 'GET', '/mcp-ai/v1/chat-memory/preferences' );
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

		$request  = $this->request_for_current_user(
			'POST',
			'/mcp-ai/v1/chat-memory/preferences',
			array(
				'enabled'       => false,
				'autosummarize' => true,
			)
		);
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

		$request  = $this->request_for_current_user( 'GET', '/mcp-ai/v1/chat-memory/recall', array( 'agent_id' => 1 ) );
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

		$request  = $this->request_for_current_user(
			'POST',
			'/mcp-ai/v1/chat-memory/store',
			array(
				'agent_id' => 1,
				'content'  => 'remember me',
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The sanitize_context_id() helper must strip everything outside [A-Za-z0-9_-].
	 */
	public function test_sanitize_context_id_strips_unsafe_characters() {
		$controller = new WP_MCP_AI_REST_Chat_Memory_Controller();
		$this->assertSame( 'ctx_abc-123', $controller->sanitize_context_id( 'ctx_abc-123' ) );
		$this->assertSame( 'ctxabc123', $controller->sanitize_context_id( 'ctx/../abc 123' ) );
		$this->assertSame( '', $controller->sanitize_context_id( '!@#$%^&*()' ) );
	}

	/**
	 * The sanitize_session_id() helper must strip everything outside [A-Za-z0-9_-].
	 */
	public function test_sanitize_session_id_strips_unsafe_characters() {
		$controller = new WP_MCP_AI_REST_Chat_Memory_Controller();
		$this->assertSame( 'sess_abc-123', $controller->sanitize_session_id( 'sess_abc-123' ) );
		$this->assertSame( 'sessabc123', $controller->sanitize_session_id( 'sess/../abc 123' ) );
		$this->assertSame( '', $controller->sanitize_session_id( '!@#$%^&*()' ) );
	}

	/**
	 * The audit endpoint requires authentication.
	 */
	public function test_audit_requires_login() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-memory/audit' );
		$request->set_param( 'agent_id', 1 );
		$response = $this->server->dispatch( $request );

		// Unauthenticated requests are rejected by the authenticator with 401
		// before the route-level permission callback ever runs.
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Disabling the user-level preference must block the audit endpoint too.
	 */
	public function test_audit_respects_user_preference_disable() {
		wp_set_current_user( $this->editor_id );
		update_user_meta( $this->editor_id, WP_MCP_AI_REST_Chat_Memory_Controller::USER_META_ENABLED, 0 );

		$request  = $this->request_for_current_user( 'GET', '/mcp-ai/v1/chat-memory/audit', array( 'agent_id' => 1 ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'chat_memory_disabled', $data['code'] );
	}

	/**
	 * The audit endpoint must accept enum values for action_type and reject others.
	 * (Validation is performed by core; the route declares the enum.)
	 */
	public function test_audit_action_type_enum_is_declared() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/chat-memory/audit', $routes );

		$audit_route_handlers = $routes['/mcp-ai/v1/chat-memory/audit'];
		$this->assertNotEmpty( $audit_route_handlers );
		$args = $audit_route_handlers[0]['args'];
		$this->assertArrayHasKey( 'action_type', $args );
		$this->assertSame(
			array( 'create', 'update', 'delete', 'access' ),
			$args['action_type']['enum']
		);
		$this->assertArrayHasKey( 'limit', $args );
	}

	/**
	 * A logged-in user with a valid agent_id should hit the underlying tool and
	 * receive a 2xx envelope. The audit log may legitimately be empty for a fresh
	 * site, so we only assert the success-shape envelope here.
	 */
	public function test_audit_returns_success_envelope_for_editor() {
		wp_set_current_user( $this->editor_id );

		$request  = $this->request_for_current_user(
			'GET',
			'/mcp-ai/v1/chat-memory/audit',
			array(
				'agent_id' => 'user_' . $this->editor_id,
				'limit'    => 10,
			)
		);
		$response = $this->server->dispatch( $request );

		$status = $response->get_status();
		// Either a 200 envelope (tool present + responded) or a 503 (tool not registered
		// in this minimal test build). Both prove the route + permission gate are wired.
		$this->assertContains( $status, array( 200, 503 ) );
	}

	/**
	 * Session replay endpoint should return buffered frames for a valid session.
	 */
	public function test_session_replay_returns_buffered_frames() {
		if ( ! class_exists( 'WP_MCP_AI_Chat_Session_Frame_Buffer' ) ) {
			$this->markTestSkipped( 'Chat session frame buffer is unavailable in this test build.' );
		}

		wp_set_current_user( $this->editor_id );
		$session_id = 'sess_replay_' . $this->editor_id;
		WP_MCP_AI_Chat_Session_Frame_Buffer::flush( $session_id );
		WP_MCP_AI_Chat_Session_Frame_Buffer::push(
			$session_id,
			'chat:resumed',
			array(
				'session_id' => $session_id,
				'message'    => 'Replay message',
				'ts'         => time(),
			)
		);

		$request  = $this->request_for_current_user(
			'GET',
			'/mcp-ai/v1/chat-memory/sessions/' . $session_id,
			array( 'limit' => 10 )
		);
		$response = $this->server->dispatch( $request );

		WP_MCP_AI_Chat_Session_Frame_Buffer::flush( $session_id );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $session_id, $data['session_id'] );
		$this->assertArrayHasKey( 'events', $data );
		$this->assertNotEmpty( $data['events'] );
		$this->assertSame( 'chat:resumed', $data['events'][0]['event'] );
	}

	/**
	 * Drawer-empty regression: GET /chat-memory/recall with no `wing` must
	 * route to `retrieve_agent_memory` (which lists every memory for the
	 * agent) rather than `recall_memory` (which 400s on missing wing). When
	 * the JetEngine CCT table is seeded with a row that survived an
	 * object-cache flush, the response must surface that row.
	 */
	public function test_recall_without_wing_lists_cct_rows() {
		global $wpdb;
		$table = $wpdb->prefix . 'jet_cct_ai_agent_memories';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture: table name is $wpdb->prefix + literal.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				`_ID` int(11) NOT NULL AUTO_INCREMENT,
				`cct_status` varchar(20) DEFAULT 'publish',
				`context_id` varchar(190) DEFAULT '',
				`agent_id` varchar(190) DEFAULT '',
				`memory_tier` varchar(40) DEFAULT '',
				`context_type` varchar(40) DEFAULT '',
				`wing` varchar(190) DEFAULT '',
				`room` varchar(190) DEFAULT '',
				`title` varchar(255) DEFAULT '',
				`content` longtext,
				`tags` longtext,
				`importance` varchar(20) DEFAULT '',
				`verbatim` tinyint(1) DEFAULT 0,
				`transaction_time` datetime DEFAULT NULL,
				`valid_from` datetime DEFAULT NULL,
				`valid_until` datetime DEFAULT NULL,
				`expires_at` datetime DEFAULT NULL,
				`source` varchar(190) DEFAULT '',
				`metadata` longtext,
				PRIMARY KEY (`_ID`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$agent_id = 'agent_drawer_' . $this->editor_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'cct_status'       => 'publish',
				'context_id'       => 'ctx_drawer_regression_001',
				'agent_id'         => $agent_id,
				'memory_tier'      => 'semantic',
				'context_type'     => 'fact',
				'wing'             => '',
				'room'             => '',
				'title'            => 'Drawer should see me',
				'content'          => 'Even after Redis flush.',
				'tags'             => wp_json_encode( array( 'regression' ) ),
				'importance'       => 'medium',
				'transaction_time' => '2026-04-01 00:00:00',
				'valid_from'       => '2026-04-01 00:00:00',
				'valid_until'      => '2099-01-01 00:00:00',
				'expires_at'       => '2099-01-01 00:00:00',
				'source'           => 'store_agent_context',
			)
		);

		// Make sure no stale transient index sneaks in.
		delete_transient( 'mcp_ai_ctx_index_' . md5( $agent_id ) );

		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-memory/recall' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'agent_id', $agent_id );
		$response = $this->server->dispatch( $request );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test cleanup
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		$this->assertSame( 200, $response->get_status(), 'No-wing recall must succeed (no 400 from recall_memory).' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'contexts', $data, 'Response envelope must come from retrieve_agent_memory.' );
		$ids = array_map(
			static function ( $c ) {
				return isset( $c['context_id'] ) ? $c['context_id'] : '';
			},
			(array) $data['contexts']
		);
		$this->assertContains( 'ctx_drawer_regression_001', $ids, 'Drawer must surface the durable CCT row.' );
	}
}
