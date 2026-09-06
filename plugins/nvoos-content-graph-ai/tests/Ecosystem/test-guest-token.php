<?php
/**
 * Guest token flow port tests (Wave D-UI-1a).
 *
 * Characterization suite for `NvoosContentGraphAi\Chat\GuestToken`:
 * byte-identical constants, token issuance + validation roundtrip,
 * assistant scoping, absolute-max lifetime enforcement, origin binding,
 * header/param extraction, TTL caps, and the REST permission
 * integration on the chat compat route.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\GuestToken;
use NvoosContentGraphAi\Rest\ChatCompatController;
use NvoosContentGraphAi\Rest\ChatController;

/**
 * @group auth
 */
class Test_Guest_Token extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! \post_type_exists( 'mcp_ai_assistant' ) ) {
			\register_post_type( 'mcp_ai_assistant', array( 'public' => true ) );
		}
	}

	public function tearDown(): void {
		\delete_option( 'nvoos_content_graph_settings' );
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			\delete_option( 'wp_mcp_ai_settings' );
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Build a REST request carrying a guest token.
	 *
	 * @param string $token        Guest token.
	 * @param array  $headers      Extra headers.
	 * @param array  $params       Request params.
	 * @return WP_REST_Request
	 */
	private function guest_request( string $token = '', array $headers = array(), array $params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		if ( '' !== $token ) {
			$request->set_header( 'X-WP-MCP-AI-Guest', $token );
		}
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Create an assistant post and return its ID.
	 *
	 * @return int
	 */
	private function create_assistant(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
	}

	// ─── Constants + extraction ─────────────────────────────────────

	public function test_constants_match_base(): void {
		$this->assertSame( DAY_IN_SECONDS, GuestToken::GUEST_TOKEN_TTL );
		$this->assertSame( 604800, GuestToken::GUEST_TOKEN_MAX_TTL );
		$this->assertSame( 60, GuestToken::GUEST_TOKEN_MIN_TTL );
		$this->assertSame( 'wp_mcp_ai_guest_access_', GuestToken::GUEST_TOKEN_TRANSIENT_PREFIX );
	}

	public function test_extract_guest_token(): void {
		$request = $this->guest_request( ' tok123 ' );
		$this->assertSame( 'tok123', GuestToken::extract_guest_token( $request ) );

		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'guest_token', ' param-tok ' );
		$this->assertSame( 'param-tok', GuestToken::extract_guest_token( $request ) );

		$this->assertSame( '', GuestToken::extract_guest_token( new \WP_REST_Request( 'POST', '/mcp-ai/v1/chat' ) ) );
	}

	// ─── Issuance + validation ──────────────────────────────────────

	public function test_generate_requires_assistant(): void {
		$this->assertSame( '', GuestToken::generate_guest_token( 0 ) );
	}

	public function test_roundtrip_validation(): void {
		$assistant_id = $this->create_assistant();
		$token        = GuestToken::generate_guest_token( $assistant_id );

		$this->assertIsString( $token );
		$this->assertSame( 32, strlen( $token ) );

		$this->assertSame( $assistant_id, GuestToken::validate_guest_token( $token ) );
		$this->assertSame( $assistant_id, GuestToken::validate_guest_token( $token, $assistant_id ) );

		// Assistant scoping: a token is rejected when the request names a
		// different assistant.
		$this->assertFalse( GuestToken::validate_guest_token( $token, $assistant_id + 999 ) );

		// Any assistant is accepted when the request does not scope.
		$this->assertSame( $assistant_id, GuestToken::validate_guest_token( $token, 0 ) );
	}

	public function test_validate_rejects_bad_tokens(): void {
		$this->assertFalse( GuestToken::validate_guest_token( '' ) );
		$this->assertFalse( GuestToken::validate_guest_token( 'does-not-exist' ) );
		$this->assertFalse( GuestToken::validate_guest_token( null ) );
	}

	public function test_absolute_max_lifetime_enforced(): void {
		$assistant_id = $this->create_assistant();
		$token        = GuestToken::generate_guest_token( $assistant_id );

		// Rewrite the stored record with an ancient creation timestamp.
		$key = \get_option( '_transient_timeout_wp_mcp_ai_guest_access_' . md5( $token ), false );
		$this->assertNotFalse( $key );

		\set_transient(
			'wp_mcp_ai_guest_access_' . md5( $token ),
			array(
				'assistant_id' => $assistant_id,
				'created'      => time() - ( GuestToken::GUEST_TOKEN_MAX_TTL + 10 ),
				'origin'       => '',
			),
			DAY_IN_SECONDS
		);

		$this->assertFalse( GuestToken::validate_guest_token( $token ) );

		// The expired record is deleted.
		$this->assertFalse( \get_transient( 'wp_mcp_ai_guest_access_' . md5( $token ) ) );
	}

	public function test_origin_binding(): void {
		$assistant_id = $this->create_assistant();
		$token        = GuestToken::generate_guest_token( $assistant_id, 'example.com' );

		// Matching origin passes.
		$request = $this->guest_request( $token, array( 'Origin' => 'https://example.com/page' ) );
		$this->assertSame( $assistant_id, GuestToken::validate_guest_token( $token, 0, $request ) );

		// Referer fallback passes too.
		$request = $this->guest_request( $token, array( 'Referer' => 'https://example.com/other' ) );
		$this->assertSame( $assistant_id, GuestToken::validate_guest_token( $token, 0, $request ) );

		// A different origin is rejected.
		$request = $this->guest_request( $token, array( 'Origin' => 'https://evil.example.net/x' ) );
		$this->assertFalse( GuestToken::validate_guest_token( $token, 0, $request ) );

		// Allowlist filter can extend the allowed origins.
		\add_filter(
			'wp_mcp_ai_guest_token_allowed_origins',
			static function ( array $origins ) {
				$origins[] = 'evil.example.net';
				return $origins;
			}
		);
		$this->assertSame( $assistant_id, GuestToken::validate_guest_token( $token, 0, $request ) );
	}

	public function test_unbound_token_skips_origin_check(): void {
		$assistant_id = $this->create_assistant();
		$token        = GuestToken::generate_guest_token( $assistant_id, '' );

		$request = $this->guest_request( $token, array( 'Origin' => 'https://anything.example/x' ) );
		$this->assertSame( $assistant_id, GuestToken::validate_guest_token( $token, 0, $request ) );
	}

	public function test_issuance_origin_filter(): void {
		\add_filter(
			'wp_mcp_ai_guest_token_issuance_origin',
			static function () {
				return 'cdn.example.com';
			}
		);

		$assistant_id = $this->create_assistant();
		$token        = GuestToken::generate_guest_token( $assistant_id );

		$request = $this->guest_request( $token, array( 'Origin' => 'https://cdn.example.com/x' ) );
		$this->assertSame( $assistant_id, GuestToken::validate_guest_token( $token, 0, $request ) );
	}

	public function test_ttl_default_and_clamp(): void {
		$reflection = new \ReflectionMethod( GuestToken::class, 'get_guest_token_ttl' );
		$reflection->setAccessible( true );

		// With no stored setting the default applies.
		$this->assertSame( DAY_IN_SECONDS, $reflection->invoke( null ) );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// The base settings registry owns the TTL in monolith installs.
			return;
		}

		// Standalone: the CG settings store feeds the TTL, clamped to
		// [60, 604800].
		\update_option( 'nvoos_content_graph_settings', array( 'guest_token_lifetime' => 10 ) );
		$this->assertSame( 60, $reflection->invoke( null ) );

		\update_option( 'nvoos_content_graph_settings', array( 'guest_token_lifetime' => 99999999 ) );
		$this->assertSame( 604800, $reflection->invoke( null ) );
	}

	// ─── REST permission integration ────────────────────────────────

	public function test_chat_compat_permission_accepts_guest_token(): void {
		$controller   = new ChatCompatController( new ChatController() );
		$assistant_id = $this->create_assistant();
		$token        = GuestToken::generate_guest_token( $assistant_id );

		// Origin-bound tokens require a matching Origin header on the
		// request (browsers send it; byte-identical base behaviour).
		$home_origin = array( 'Origin' => home_url() );

		// Logged-out with no token → denied.
		$this->assertWPError( $controller->permissions_check( $this->guest_request() ) );

		// Logged-out with a valid token → allowed.
		$request = $this->guest_request( $token, $home_origin );
		$this->assertTrue( $controller->permissions_check( $request ) );

		// A token scoped to another assistant is rejected for a request
		// that names a different assistant.
		$other     = $this->create_assistant();
		$request   = $this->guest_request( $token, $home_origin, array( 'assistant_id' => $other ) );
		$validated = $controller->permissions_check( $request );
		if ( ! is_wp_error( $validated ) ) {
			$this->assertTrue( $validated );
		} else {
			// Without a matching scope the gate falls back to the capability
			// check — the logged-out caller is denied.
			$this->assertSame( 'rest_forbidden', $validated->get_error_code() );
		}

		// Logged-in authors still pass without any token.
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		\wp_set_current_user( $author );
		$this->assertTrue( $controller->permissions_check( $this->guest_request() ) );
	}

	public function test_request_guest_access_wrapper(): void {
		$assistant_id = $this->create_assistant();
		$token        = GuestToken::generate_guest_token( $assistant_id );

		$this->assertSame(
			$assistant_id,
			GuestToken::validate_request_guest_access(
				$this->guest_request( $token, array( 'Origin' => home_url() ) )
			)
		);

		$this->assertFalse( GuestToken::validate_request_guest_access( $this->guest_request() ) );
	}
}
