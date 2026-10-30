<?php
/**
 * Tests for WP_MCP_AI_REST_Magic_Login_Controller.
 *
 * Covers the two public magic-link routes — anti-enumeration request
 * responses, token hashing at rest, email delivery, single-use confirm with
 * session start, uniform invalid-token errors, rate limiting, and the
 * disabled-state 404.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the magic-login REST controller.
 */
class Test_REST_Magic_Login_Controller extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Mail captured by the pre_wp_mail short-circuit.
	 *
	 * @var array|null
	 */
	protected $captured_mail = null;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		// Force-register the controller's routes so dispatch() resolves them
		// even if bootstrap didn't wire them in this test context.
		$controller = new WP_MCP_AI_REST_Magic_Login_Controller();
		$controller->register_routes();

		// Enable the feature for the test run.
		add_filter( 'wp_mcp_ai_magic_login_enabled', '__return_true' );

		// Short-circuit wp_mail and capture the arguments.
		$this->captured_mail = null;
		add_filter(
			'pre_wp_mail',
			function ( $return, $atts ) {
				$this->captured_mail = $atts;
				return true;
			},
			10,
			2
		);
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		global $wpdb, $wp_rest_server;

		remove_filter( 'wp_mcp_ai_magic_login_enabled', '__return_true' );

		// Clean every magic-login transient (tokens + rate-limit counters) so
		// tests stay isolated regardless of execution order.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_wp_mcp_ai_magic_login_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_wp_mcp_ai_magic_login_' ) . '%'
			)
		);

		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Dispatch a POST to a magic-login route.
	 *
	 * @param string $route Route path.
	 * @param array  $body  JSON body parameters.
	 * @return WP_REST_Response
	 */
	private function dispatch_post( $route, $body ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * Create a user and request a link for their email, returning the token
	 * extracted from the captured mail.
	 *
	 * @return array{token: string, user: WP_User}
	 */
	private function request_link_for_fresh_user() {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'magic-' . wp_generate_password( 8, false ) . '@example.com',
			)
		);
		$user    = get_user_by( 'id', $user_id );

		$response = $this->dispatch_post( '/magic-login/request', array( 'identifier' => $user->user_email ) );
		$this->assertSame( 200, $response->get_status() );

		$this->assertIsArray( $this->captured_mail );
		$this->assertSame( $user->user_email, $this->captured_mail['to'] );
		$this->assertSame(
			1,
			preg_match( '/#\/magic-login\?token=([A-Za-z0-9]{43})/', $this->captured_mail['message'], $matches )
		);

		return array(
			'token' => $matches[1],
			'user'  => $user,
		);
	}

	/**
	 * Requests for unknown identifiers answer with the same uniform success.
	 */
	public function test_request_unknown_identifier_returns_uniform_success() {
		$response = $this->dispatch_post( '/magic-login/request', array( 'identifier' => 'no-such-user@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'ok', $data );
		$this->assertTrue( $data['ok'] );
		$this->assertNotEmpty( $data['message'] );
		$this->assertNull( $this->captured_mail, 'No mail should be sent for unknown accounts.' );
	}

	/**
	 * A request for a known account emails a link, stores the token hashed,
	 * and never stores the raw token.
	 */
	public function test_request_known_user_emails_link_and_hashes_token_at_rest() {
		$result = $this->request_link_for_fresh_user();
		$token  = $result['token'];
		$user   = $result['user'];

		$hash = hash( 'sha256', $token );
		$data = get_transient( 'wp_mcp_ai_magic_login_token_' . $hash );
		$this->assertIsArray( $data );
		$this->assertSame( $user->ID, $data['user_id'] );

		// The raw token must never be a storage key.
		$this->assertFalse( get_transient( 'wp_mcp_ai_magic_login_token_' . $token ) );
	}

	/**
	 * Confirming a valid token starts the session, fires wp_login, and
	 * consumes the token.
	 */
	public function test_confirm_valid_token_starts_session_and_consumes_token() {
		$result = $this->request_link_for_fresh_user();
		$token  = $result['token'];
		$user   = $result['user'];

		$logins_before = did_action( 'wp_login' );

		$response = $this->dispatch_post( '/magic-login/confirm', array( 'token' => $token ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( $user->ID, $data['user']['id'] );
		$this->assertSame( $user->user_login, $data['user']['login'] );

		$this->assertGreaterThan( $logins_before, did_action( 'wp_login' ) );

		// Single-use: the token is gone.
		$this->assertFalse( get_transient( 'wp_mcp_ai_magic_login_token_' . hash( 'sha256', $token ) ) );
	}

	/**
	 * A token may only ever complete once.
	 */
	public function test_confirm_rejects_reused_token() {
		$result = $this->request_link_for_fresh_user();
		$token  = $result['token'];

		$first  = $this->dispatch_post( '/magic-login/confirm', array( 'token' => $token ) );
		$second = $this->dispatch_post( '/magic-login/confirm', array( 'token' => $token ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'invalid_token', $second->as_error()->get_error_code() );
	}

	/**
	 * Unknown tokens get the uniform invalid-token error.
	 */
	public function test_confirm_rejects_garbage_token() {
		$response = $this->dispatch_post( '/magic-login/confirm', array( 'token' => str_repeat( 'a', 43 ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_token', $response->as_error()->get_error_code() );
	}

	/**
	 * Requesting more than 3 links for one identifier in the window is
	 * rate-limited with a 429.
	 */
	public function test_request_is_rate_limited_per_identifier() {
		$identifier = 'rate-' . wp_generate_password( 8, false ) . '@example.com';

		for ( $i = 0; $i < 3; $i++ ) {
			$response = $this->dispatch_post( '/magic-login/request', array( 'identifier' => $identifier ) );
			$this->assertSame( 200, $response->get_status() );
		}

		$response = $this->dispatch_post( '/magic-login/request', array( 'identifier' => $identifier ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'too_many_requests', $response->as_error()->get_error_code() );
	}

	/**
	 * While the feature is disabled, both routes are invisible (404).
	 */
	public function test_routes_404_when_disabled() {
		remove_filter( 'wp_mcp_ai_magic_login_enabled', '__return_true' );
		add_filter( 'wp_mcp_ai_magic_login_enabled', '__return_false' );

		$request_response  = $this->dispatch_post( '/magic-login/request', array( 'identifier' => 'x@example.com' ) );
		$confirm_response  = $this->dispatch_post( '/magic-login/confirm', array( 'token' => str_repeat( 'a', 43 ) ) );

		$this->assertSame( 404, $request_response->get_status() );
		$this->assertSame( 404, $confirm_response->get_status() );
	}
}
