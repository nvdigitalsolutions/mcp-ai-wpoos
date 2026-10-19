<?php
/**
 * Tests for the MCP OAuth authorization-endpoint hardening (RFC 9700).
 *
 * Covers the redirect-URI exact-match contract, registration-time URI
 * validation, unknown-client handling, scope/resource validation, the
 * browser consent step, and the token-exchange redirect_uri consistency
 * check introduced in 1.2.8.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class Test_OAuth_Authorize_Hardening extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * PKCE verifier used for the last authorize request built.
	 *
	 * @var string
	 */
	protected $verifier = '';

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		delete_option( WP_MCP_AI_OAuth_Server::CLIENTS_OPTION );
		delete_option( WP_MCP_AI_OAuth_Server::CODES_OPTION );
		delete_option( WP_MCP_AI_OAuth_Server::TOKENS_OPTION );

		$this->reset_open_registration();

		// Default: non-browser (API) requests.
		$_SERVER['HTTP_ACCEPT'] = 'application/json';
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_OAuth_Server::CLIENTS_OPTION );
		delete_option( WP_MCP_AI_OAuth_Server::CODES_OPTION );
		delete_option( WP_MCP_AI_OAuth_Server::TOKENS_OPTION );

		$this->reset_open_registration();

		unset( $_SERVER['HTTP_ACCEPT'] );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Whether the settings repository (and therefore the open-registration
	 * toggle) is available in this test matrix.
	 *
	 * @return bool
	 */
	private function repository_available() {
		return function_exists( 'wp_mcp_ai_get_settings_repository' );
	}

	/**
	 * Enable or disable open OAuth client registration for the request.
	 *
	 * @param bool $enabled Whether open registration should be allowed.
	 * @return void
	 */
	private function set_open_registration( $enabled ) {
		$value = $enabled ? 0 : 1;

		if ( $this->repository_available() ) {
			wp_mcp_ai_get_settings_repository()->update( 'oauth_disable_open_registration', $value );
		} else {
			update_option( 'wp_mcp_ai_oauth_disable_open_registration', $value );
		}
	}

	/**
	 * Reset the open-registration setting to its fail-safe default.
	 *
	 * @return void
	 */
	private function reset_open_registration() {
		if ( $this->repository_available() ) {
			wp_mcp_ai_get_settings_repository()->delete( 'oauth_disable_open_registration' );
		} else {
			delete_option( 'wp_mcp_ai_oauth_disable_open_registration' );
		}

		// Defensive: clear the key from the settings blob too, in case an
		// earlier suite persisted it there.
		$blob = get_option( 'wp_mcp_ai_settings', array() );
		if ( is_array( $blob ) && array_key_exists( 'oauth_disable_open_registration', $blob ) ) {
			unset( $blob['oauth_disable_open_registration'] );
			update_option( 'wp_mcp_ai_settings', $blob );
		}
	}

	/**
	 * Register a test client with the given redirect URIs.
	 *
	 * @param string[] $redirect_uris Registered redirect URIs.
	 * @return string|WP_Error Client ID, or error on failure.
	 */
	private function register_test_client( array $redirect_uris ) {
		$result = WP_MCP_AI_OAuth_Server::get_instance()->register_client(
			array(
				'client_name'   => 'Hardening Test Client',
				'redirect_uris' => $redirect_uris,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['client_id'];
	}

	/**
	 * Build a valid set of authorization parameters for the given client.
	 *
	 * Stores the generated PKCE verifier on the instance for later token
	 * exchange tests.
	 *
	 * @param string $client_id    Client ID.
	 * @param string $redirect_uri Redirect URI used in the request.
	 * @param array  $overrides    Parameter overrides.
	 * @return array<string,string>
	 */
	private function authorize_params( $client_id, $redirect_uri, array $overrides = array() ) {
		$this->verifier = 'test-verifier-' . wp_generate_password( 32, false, false );

		$params = array(
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect_uri,
			'code_challenge'        => WP_MCP_AI_OAuth_Server::compute_s256_challenge( $this->verifier ),
			'code_challenge_method' => 'S256',
			'scope'                 => 'mcp',
		);

		return array_merge( $params, $overrides );
	}

	/**
	 * Build an authorize REST request.
	 *
	 * @param string               $method HTTP method.
	 * @param array<string,string> $params Request parameters.
	 * @return WP_REST_Request
	 */
	private function make_authorize_request( $method, array $params ) {
		$request = new WP_REST_Request( $method, '/mcp-ai/v1/oauth/authorize' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * The consent nonce action for a given request shape.
	 *
	 * @param string $client_id      Client ID.
	 * @param string $redirect_uri   Redirect URI.
	 * @param string $code_challenge PKCE challenge.
	 * @return string
	 */
	private function consent_nonce( $client_id, $redirect_uri, $code_challenge ) {
		return wp_create_nonce(
			WP_MCP_AI_OAuth_REST::get_instance()->get_consent_nonce_action( $client_id, $redirect_uri, $code_challenge )
		);
	}

	/**
	 * Stored authorization codes option value.
	 *
	 * @return array<string,array>
	 */
	private function stored_codes() {
		$codes = get_option( WP_MCP_AI_OAuth_Server::CODES_OPTION, array() );
		return is_array( $codes ) ? $codes : array();
	}

	/**
	 * A registered client with an exact-matching redirect URI receives a
	 * 302 back to that URI carrying a code.
	 */
	public function test_authorize_issues_code_for_exact_registered_redirect_uri() {
		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$request   = $this->make_authorize_request(
			'GET',
			$this->authorize_params( $client_id, 'https://client.example/callback' )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 302, $response->get_status() );
		$this->assertStringStartsWith( 'https://client.example/callback?code=', $response->get_headers()['Location'] );
		$this->assertCount( 1, $this->stored_codes() );
	}

	/**
	 * A redirect URI not registered for the client is rejected without any
	 * redirect — and no authorization code is minted.
	 */
	public function test_authorize_rejects_unregistered_redirect_uri_without_redirect() {
		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$request   = $this->make_authorize_request(
			'GET',
			$this->authorize_params( $client_id, 'https://evil.example/steal' )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_request', $response->get_error_code() );
		$this->assertSame( array(), $this->stored_codes() );
	}

	/**
	 * A client with an empty registered redirect-URI list rejects every
	 * request — the empty list is never treated as a wildcard.
	 */
	public function test_authorize_rejects_client_with_empty_registered_uris() {
		$result    = WP_MCP_AI_OAuth_Server::get_instance()->register_client( array( 'client_name' => 'No URIs' ) );
		$client_id = $result['client_id'];
		$request   = $this->make_authorize_request(
			'GET',
			$this->authorize_params( $client_id, 'https://evil.example/steal' )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_request', $response->get_error_code() );
		$this->assertSame( array(), $this->stored_codes() );
	}

	/**
	 * Unknown clients are rejected by default (open registration disabled).
	 */
	public function test_unknown_client_rejected_by_default() {
		$request = $this->make_authorize_request(
			'GET',
			$this->authorize_params( 'mcp_client_unknown', 'https://evil.example/steal' )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_client', $response->get_error_code() );
		$this->assertSame( 401, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->stored_codes() );
	}

	/**
	 * With open registration enabled, unknown clients may proceed only with
	 * loopback redirect URIs (RFC 8252 native apps).
	 */
	public function test_unknown_loopback_client_allowed_when_open_registration_enabled() {
		if ( ! $this->repository_available() ) {
			$this->markTestSkipped( 'Open-registration toggle requires the settings repository.' );
		}

		$this->set_open_registration( true );

		$request = $this->make_authorize_request(
			'GET',
			$this->authorize_params( 'mcp_client_loopback', 'http://127.0.0.1:41234/callback' )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 302, $response->get_status() );
		$this->assertStringStartsWith( 'http://127.0.0.1:41234/callback?code=', $response->get_headers()['Location'] );
		$this->assertCount( 1, $this->stored_codes() );
	}

	/**
	 * With open registration enabled, unknown clients are still rejected for
	 * non-loopback redirect URIs.
	 */
	public function test_unknown_non_loopback_client_rejected_when_open_registration_enabled() {
		if ( ! $this->repository_available() ) {
			$this->markTestSkipped( 'Open-registration toggle requires the settings repository.' );
		}

		$this->set_open_registration( true );

		$request = $this->make_authorize_request(
			'GET',
			$this->authorize_params( 'mcp_client_evil', 'https://evil.example/steal' )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_client', $response->get_error_code() );
		$this->assertSame( array(), $this->stored_codes() );
	}

	/**
	 * Registration rejects plain-HTTP redirect URIs on non-loopback hosts.
	 */
	public function test_register_rejects_plain_http_non_loopback() {
		$result = $this->register_test_client( array( 'http://client.example/callback' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_redirect_uri', $result->get_error_code() );
	}

	/**
	 * Registration rejects wildcard hosts, fragments, and embedded credentials.
	 */
	public function test_register_rejects_wildcard_fragment_and_userinfo_uris() {
		$wildcard = $this->register_test_client( array( 'https://*.example.com/callback' ) );
		$this->assertWPError( $wildcard );
		$this->assertSame( 'invalid_redirect_uri', $wildcard->get_error_code() );

		$fragment = $this->register_test_client( array( 'https://client.example/callback#frag' ) );
		$this->assertWPError( $fragment );
		$this->assertSame( 'invalid_redirect_uri', $fragment->get_error_code() );

		$userinfo = $this->register_test_client( array( 'https://user@client.example/callback' ) );
		$this->assertWPError( $userinfo );
		$this->assertSame( 'invalid_redirect_uri', $userinfo->get_error_code() );
	}

	/**
	 * Registration accepts loopback plain-HTTP redirect URIs.
	 */
	public function test_register_accepts_loopback_http() {
		$result = $this->register_test_client( array( 'http://localhost:3000/callback' ) );

		$this->assertNotWPError( $result );
	}

	/**
	 * Requests for unknown scopes are rejected outright (RFC 9700 §2.2.1).
	 */
	public function test_unknown_scope_rejected() {
		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$request   = $this->make_authorize_request(
			'GET',
			$this->authorize_params( $client_id, 'https://client.example/callback', array( 'scope' => 'admin mcp' ) )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_scope', $response->get_error_code() );
		$this->assertSame( array(), $this->stored_codes() );
	}

	/**
	 * Resource indicators must be HTTPS (or loopback HTTP).
	 */
	public function test_resource_must_be_https_or_loopback() {
		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$request   = $this->make_authorize_request(
			'GET',
			$this->authorize_params( $client_id, 'https://client.example/callback', array( 'resource' => 'http://evil.example/mcp' ) )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_target', $response->get_error_code() );
		$this->assertSame( array(), $this->stored_codes() );
	}

	/**
	 * A missing redirect_uri is rejected before any code is issued.
	 */
	public function test_authorize_without_redirect_uri_rejected() {
		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$request   = $this->make_authorize_request(
			'GET',
			$this->authorize_params( $client_id, '' )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_request', $response->get_error_code() );
		$this->assertSame( array(), $this->stored_codes() );
	}

	/**
	 * Browser requests render a consent page and mint no code until the user
	 * explicitly allows.
	 */
	public function test_browser_request_renders_consent_page_and_mints_no_code() {
		$_SERVER['HTTP_ACCEPT'] = 'text/html';

		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$request   = $this->make_authorize_request(
			'GET',
			$this->authorize_params( $client_id, 'https://client.example/callback' )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$html = $response->get_data();
		$this->assertStringContainsString( 'name="consent"', $html );
		$this->assertStringContainsString( 'name="client_id"', $html );
		$this->assertStringContainsString( 'name="redirect_uri"', $html );
		$this->assertStringContainsString( 'Hardening Test Client', $html );

		// No code may be minted by the consent page alone.
		$this->assertSame( array(), $this->stored_codes() );
	}

	/**
	 * An Allow decision without the consent nonce is rejected.
	 */
	public function test_consent_allow_without_nonce_rejected() {
		$_SERVER['HTTP_ACCEPT'] = 'text/html';

		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$request   = $this->make_authorize_request(
			'POST',
			$this->authorize_params( $client_id, 'https://client.example/callback', array( 'consent' => 'allow' ) )
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_request', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->stored_codes() );
	}

	/**
	 * A Deny decision redirects back to the client with access_denied.
	 */
	public function test_consent_deny_redirects_with_access_denied() {
		$_SERVER['HTTP_ACCEPT'] = 'text/html';

		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$params    = $this->authorize_params( $client_id, 'https://client.example/callback' );
		$params    = array_merge(
			$params,
			array(
				'consent'  => 'deny',
				'_wpnonce' => $this->consent_nonce( $client_id, 'https://client.example/callback', $params['code_challenge'] ),
			)
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize(
			$this->make_authorize_request( 'POST', $params )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 302, $response->get_status() );
		$this->assertStringStartsWith( 'https://client.example/callback?error=access_denied', $response->get_headers()['Location'] );
		$this->assertSame( array(), $this->stored_codes() );
	}

	/**
	 * An Allow decision with a valid consent nonce issues the code for the
	 * current user and redirects to the client.
	 */
	public function test_consent_allow_issues_code() {
		$_SERVER['HTTP_ACCEPT'] = 'text/html';

		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$params    = $this->authorize_params( $client_id, 'https://client.example/callback' );
		$params    = array_merge(
			$params,
			array(
				'consent'  => 'allow',
				'_wpnonce' => $this->consent_nonce( $client_id, 'https://client.example/callback', $params['code_challenge'] ),
			)
		);

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize(
			$this->make_authorize_request( 'POST', $params )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 302, $response->get_status() );
		$this->assertStringStartsWith( 'https://client.example/callback?code=', $response->get_headers()['Location'] );

		$codes = $this->stored_codes();
		$this->assertCount( 1, $codes );
		$entry = reset( $codes );
		$this->assertSame( $this->admin_id, $entry['user_id'] );
	}

	/**
	 * The authorize route accepts both GET (consent) and POST (decision).
	 */
	public function test_authorize_route_registered_for_get_and_post() {
		$controller = WP_MCP_AI_OAuth_REST::get_instance();

		$GLOBALS['wp_rest_server'] = null;
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		rest_get_server();

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/oauth/authorize', $routes );

		$methods = array();
		foreach ( $routes['/mcp-ai/v1/oauth/authorize'] as $endpoint ) {
			foreach ( array_keys( $endpoint['methods'] ) as $method ) {
				$methods[] = $method;
			}
		}

		$this->assertContains( 'GET', $methods );
		$this->assertContains( 'POST', $methods );
	}

	/**
	 * Token exchange rejects a redirect_uri that does not match the one used
	 * at authorization time (RFC 6749 §4.1.3).
	 */
	public function test_token_exchange_rejects_mismatched_redirect_uri() {
		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$params    = $this->authorize_params( $client_id, 'https://client.example/callback' );

		$auth_response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize(
			$this->make_authorize_request( 'GET', $params )
		);
		$location      = $auth_response->get_headers()['Location'];
		$query         = array();
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$code = isset( $query['code'] ) ? $query['code'] : '';

		$token_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/oauth/token' );
		foreach (
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'code_verifier' => $this->verifier,
				'redirect_uri'  => 'https://other.example/callback',
			) as $key => $value
		) {
			$token_request->set_param( $key, $value );
		}

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_token( $token_request );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_grant', $response->get_error_code() );
	}

	/**
	 * Token exchange with the matching redirect_uri issues tokens for the
	 * authorizing user.
	 */
	public function test_token_exchange_matching_redirect_uri_succeeds() {
		$client_id = $this->register_test_client( array( 'https://client.example/callback' ) );
		$params    = $this->authorize_params( $client_id, 'https://client.example/callback' );

		$auth_response = WP_MCP_AI_OAuth_REST::get_instance()->handle_authorize(
			$this->make_authorize_request( 'GET', $params )
		);
		$location      = $auth_response->get_headers()['Location'];
		$query         = array();
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$code = isset( $query['code'] ) ? $query['code'] : '';

		$token_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/oauth/token' );
		foreach (
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'code_verifier' => $this->verifier,
				'redirect_uri'  => 'https://client.example/callback',
			) as $key => $value
		) {
			$token_request->set_param( $key, $value );
		}

		$response = WP_MCP_AI_OAuth_REST::get_instance()->handle_token( $token_request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'access_token', $data );

		$validated = WP_MCP_AI_OAuth_Server::get_instance()->validate_token(
			$data['access_token'],
			rest_url( 'mcp-ai/v1/mcp' )
		);
		$this->assertIsArray( $validated );
		$this->assertSame( $this->admin_id, $validated['user_id'] );
	}
}
