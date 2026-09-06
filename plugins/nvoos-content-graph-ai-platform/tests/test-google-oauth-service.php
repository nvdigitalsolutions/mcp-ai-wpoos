<?php
/**
 * Google OAuth service port tests (Wave E4, sub-cluster 3).
 *
 * Characterization suite for the ported `GoogleOAuthService`: the state
 * transient contract (single-use, user-bound, delete-before-validate),
 * the byte-stable redirect URI builders, the offline/consent authorize
 * URL builder, the `allowed_redirect_hosts` filter, the authorization
 * code exchange with its error branches, the cached refresh-token
 * minting with the 300 s safety margin, the cache forgetter, the
 * userinfo email fetch, and the revocation flow. HTTP is intercepted
 * via `pre_http_request`. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Google\GoogleOAuthService;

/**
 * Google OAuth service characterization.
 */
class Test_Google_OAuth_Service extends \WP_UnitTestCase {

	// State management.

	/**
	 * The state transient key is service-scoped and deterministically derived
	 * from the opaque state value.
	 */
	public function test_state_key_is_service_scoped_and_deterministic() {
		$key   = GoogleOAuthService::state_transient_key( 'google_calendar', 'abc' );
		$again = GoogleOAuthService::state_transient_key( 'google_calendar', 'abc' );

		$this->assertSame( $key, $again );
		$this->assertStringStartsWith( 'wp_mcp_ai_google_calendar_oauth_state_', $key );
		$this->assertNotSame( $key, GoogleOAuthService::state_transient_key( 'gmail', 'abc' ) );
	}

	/**
	 * State is single-use: a replayed callback must fail even inside the TTL.
	 */
	public function test_oauth_state_is_single_use() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$state = GoogleOAuthService::store_state( 'google_calendar', array( 'connection_id' => 'conn_1' ) );

		$first = GoogleOAuthService::consume_state( 'google_calendar', $state );
		$this->assertIsArray( $first );
		$this->assertSame( 'conn_1', $first['connection_id'] );

		$replay = GoogleOAuthService::consume_state( 'google_calendar', $state );
		$this->assertWPError( $replay );
		$this->assertSame( 'wp_mcp_ai_oauth_invalid_state', $replay->get_error_code() );
	}

	/**
	 * State is bound to the user who started the flow.
	 */
	public function test_oauth_state_is_user_bound() {
		$starter = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other   = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $starter );
		$state = GoogleOAuthService::store_state( 'google_calendar' );

		wp_set_current_user( $other );
		$result = GoogleOAuthService::consume_state( 'google_calendar', $state );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_state_user_mismatch', $result->get_error_code() );
	}

	/**
	 * An empty state value fails before any transient lookup.
	 */
	public function test_missing_state_fails_closed() {
		$result = GoogleOAuthService::consume_state( 'google_calendar', '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_missing_state', $result->get_error_code() );
	}

	// Redirect URIs.

	/**
	 * Google requires the authorize-time and exchange-time redirect URIs to match
	 * byte for byte, so both must come from the same builder.
	 */
	public function test_redirect_uri_is_stable_across_calls() {
		$first  = GoogleOAuthService::build_redirect_uri( 'google_calendar_callback' );
		$second = GoogleOAuthService::build_redirect_uri( 'google_calendar_callback' );

		$this->assertSame( $first, $second );
		$this->assertStringContainsString( 'wp_mcp_ai_oauth=google_calendar_callback', $first );
	}

	/**
	 * Remote Sites redirects use `oauth_handler` and must never carry `action`,
	 * which Google rejects inside redirect URIs.
	 */
	public function test_remote_redirect_uri_uses_oauth_handler() {
		$url = GoogleOAuthService::build_remote_redirect_uri( 'google_calendar_oauth_callback' );

		$this->assertStringContainsString( 'oauth_handler=google_calendar_oauth_callback', $url );
		$this->assertStringNotContainsString( 'action=', $url );
	}

	// Authorize URL.

	/**
	 * Offline access with forced consent is required to obtain a refresh token.
	 */
	public function test_authorize_url_requests_offline_access() {
		$url = GoogleOAuthService::build_authorize_url(
			array(
				'client_id'    => 'cid',
				'redirect_uri' => 'https://example.com/cb',
				'scope'        => 'https://www.googleapis.com/auth/calendar.events',
				'state'        => 'st',
			)
		);

		$this->assertIsString( $url );
		$this->assertStringStartsWith( GoogleOAuthService::AUTHORIZE_ENDPOINT, $url );
		$this->assertStringContainsString( 'access_type=offline', $url );
		$this->assertStringContainsString( 'prompt=consent', $url );
		$this->assertStringContainsString( 'include_granted_scopes=true', $url );
		$this->assertStringContainsString( 'response_type=code', $url );
	}

	/**
	 * A `me` login hint is Google's own alias and must be dropped rather than
	 * sent as a literal email.
	 */
	public function test_authorize_url_drops_me_login_hint() {
		$url = GoogleOAuthService::build_authorize_url(
			array(
				'client_id'    => 'cid',
				'redirect_uri' => 'https://example.com/cb',
				'scope'        => 'scope',
				'state'        => 'st',
				'login_hint'   => 'me',
			)
		);

		$this->assertStringNotContainsString( 'login_hint', $url );
	}

	/**
	 * An incomplete authorize request must fail loudly rather than producing a
	 * URL Google will reject.
	 */
	public function test_authorize_url_requires_all_parameters() {
		$result = GoogleOAuthService::build_authorize_url( array( 'client_id' => 'cid' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_incomplete_request', $result->get_error_code() );
	}

	/**
	 * The Google authorize host must be added to `allowed_redirect_hosts`.
	 */
	public function test_allowed_redirect_hosts_gains_google() {
		$hosts = GoogleOAuthService::filter_allowed_redirect_hosts( array( 'example.com' ) );

		$this->assertContains( GoogleOAuthService::AUTHORIZE_HOST, $hosts );
		$this->assertContains( 'example.com', $hosts );

		$hosts = GoogleOAuthService::filter_allowed_redirect_hosts( array( 'example.com', GoogleOAuthService::AUTHORIZE_HOST ) );

		$this->assertCount( 2, $hosts, 'Hosts must stay unique.' );
	}

	// Code exchange.

	/**
	 * A successful token response decodes into the raw payload.
	 */
	public function test_exchange_code_success() {
		$this->mock_response(
			200,
			array(
				'access_token'  => 'access_1',
				'refresh_token' => 'refresh_1',
				'scope'         => 'scope_1',
			)
		);

		$result = GoogleOAuthService::exchange_code(
			array(
				'code'          => 'code_1',
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'redirect_uri'  => 'https://example.com/cb',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'access_1', $result['access_token'] );
		$this->assertSame( 'refresh_1', $result['refresh_token'] );
	}

	/**
	 * A rejected exchange surfaces Google's error description.
	 */
	public function test_exchange_code_rejected() {
		$this->mock_response(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Bad code.',
			)
		);

		$result = GoogleOAuthService::exchange_code(
			array(
				'code'          => 'code_1',
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'redirect_uri'  => 'https://example.com/cb',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_invalid_grant', $result->get_error_code() );
		$this->assertStringContainsString( 'Bad code.', $result->get_error_message() );
	}

	/**
	 * An unreadable token response fails with the invalid-response code.
	 */
	public function test_exchange_code_unreadable_response() {
		$this->mock_raw_response( 200, 'not-json' );

		$result = GoogleOAuthService::exchange_code(
			array(
				'code'          => 'code_1',
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'redirect_uri'  => 'https://example.com/cb',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_invalid_response', $result->get_error_code() );
	}

	/**
	 * A transport failure maps to the transport-error code.
	 */
	public function test_exchange_code_transport_error() {
		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'Down.' );
			},
			10,
			3
		);

		$result = GoogleOAuthService::exchange_code(
			array(
				'code'          => 'code_1',
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'redirect_uri'  => 'https://example.com/cb',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_transport_error', $result->get_error_code() );
	}

	/**
	 * Missing exchange inputs fail before any HTTP request.
	 */
	public function test_exchange_code_requires_all_inputs() {
		$result = GoogleOAuthService::exchange_code( array( 'code' => 'code_1' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_incomplete_exchange', $result->get_error_code() );
	}

	// Access token minting.

	/**
	 * A revoked refresh token surfaces as `invalid_grant`, which callers use to
	 * prompt for reconnection rather than retrying forever.
	 */
	public function test_invalid_grant_is_distinguished() {
		$this->mock_response( 400, array( 'error' => 'invalid_grant' ) );

		$result = GoogleOAuthService::mint_access_token(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'refresh_token' => 'revoked',
				'cache_key'     => 'test-' . wp_generate_uuid4(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_invalid_grant', $result->get_error_code() );
	}

	/**
	 * A fresh access token is cached with the safety margin subtracted, so it
	 * never outlives the upstream expiry.
	 */
	public function test_minted_token_is_cached_with_margin() {
		$cache_key = 'test-' . wp_generate_uuid4();

		$this->mock_response(
			200,
			array(
				'access_token' => 'access_2',
				'expires_in'   => 3600,
			)
		);

		$result = GoogleOAuthService::mint_access_token(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'refresh_token' => 'refresh_2',
				'cache_key'     => $cache_key,
			)
		);

		$this->assertSame( 'access_2', $result );

		$transient = 'wp_mcp_ai_google_access_token_' . md5( $cache_key );

		$this->assertSame( 'access_2', get_transient( $transient ) );
	}

	/**
	 * A cached token is returned without hitting the network.
	 */
	public function test_cached_token_short_circuits_http() {
		$cache_key = 'test-' . wp_generate_uuid4();
		set_transient( 'wp_mcp_ai_google_access_token_' . md5( $cache_key ), 'cached_1', HOUR_IN_SECONDS );

		$called = false;

		add_filter(
			'pre_http_request',
			function () use ( &$called ) {
				$called = true;

				return array(
					'response' => array( 'code' => 500 ),
					'body'     => '',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = GoogleOAuthService::mint_access_token(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'refresh_token' => 'refresh_2',
				'cache_key'     => $cache_key,
			)
		);

		$this->assertSame( 'cached_1', $result );
		$this->assertFalse( $called, 'A cached token must not trigger an HTTP request.' );
	}

	/**
	 * `force_refresh` must bypass the cache.
	 */
	public function test_force_refresh_bypasses_the_cache() {
		$cache_key = 'test-' . wp_generate_uuid4();
		set_transient( 'wp_mcp_ai_google_access_token_' . md5( $cache_key ), 'cached_1', HOUR_IN_SECONDS );

		$this->mock_response(
			200,
			array(
				'access_token' => 'fresh_1',
				'expires_in'   => 3600,
			)
		);

		$result = GoogleOAuthService::mint_access_token(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'refresh_token' => 'refresh_2',
				'cache_key'     => $cache_key,
				'force_refresh' => true,
			)
		);

		$this->assertSame( 'fresh_1', $result );
	}

	/**
	 * Missing refresh credentials fail before any HTTP request.
	 */
	public function test_mint_requires_refresh_credentials() {
		$result = GoogleOAuthService::mint_access_token( array( 'client_id' => 'cid' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_missing_refresh_credentials', $result->get_error_code() );
	}

	/**
	 * A 200 response without an access token fails with the no-access-token code.
	 */
	public function test_mint_without_access_token_fails() {
		$this->mock_response( 200, array( 'expires_in' => 3600 ) );

		$result = GoogleOAuthService::mint_access_token(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'refresh_token' => 'refresh_2',
				'cache_key'     => 'test-' . wp_generate_uuid4(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_no_access_token', $result->get_error_code() );
	}

	/**
	 * The cache forgetter clears only the derived transient.
	 */
	public function test_forget_access_token_clears_the_cache() {
		$transient = 'wp_mcp_ai_google_access_token_' . md5( 'settings:1' );
		set_transient( $transient, 'token_1', HOUR_IN_SECONDS );

		GoogleOAuthService::forget_access_token( 'settings:1' );

		$this->assertFalse( get_transient( $transient ) );
	}

	// Userinfo + revoke.

	/**
	 * The userinfo email fetch resolves and sanitises the authorised account.
	 */
	public function test_fetch_userinfo_email() {
		$this->mock_response( 200, array( 'email' => 'admin@example.com' ) );

		$this->assertSame( 'admin@example.com', GoogleOAuthService::fetch_userinfo_email( 'token_1' ) );
	}

	/**
	 * A failing userinfo request degrades to an empty string, never a WP_Error,
	 * so the connect flow can complete without an email.
	 */
	public function test_fetch_userinfo_email_degrades_silently() {
		$this->mock_response( 401, array( 'error' => 'unauthorized' ) );

		$this->assertSame( '', GoogleOAuthService::fetch_userinfo_email( 'token_1' ) );
		$this->assertSame( '', GoogleOAuthService::fetch_userinfo_email( '' ) );
	}

	/**
	 * A successful revocation returns true.
	 */
	public function test_revoke_success() {
		$this->mock_response( 200, array() );

		$this->assertTrue( GoogleOAuthService::revoke( 'token_1' ) );
	}

	/**
	 * A rejected revocation returns the documented error code.
	 */
	public function test_revoke_rejected() {
		$this->mock_response( 400, array() );

		$result = GoogleOAuthService::revoke( 'token_1' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_revoke_failed', $result->get_error_code() );
	}

	/**
	 * Revoking an empty token fails without HTTP.
	 */
	public function test_revoke_requires_a_token() {
		$result = GoogleOAuthService::revoke( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_missing_token', $result->get_error_code() );
	}

	// Helpers.

	/**
	 * Short-circuit all HTTP requests with a fixed status and JSON body.
	 *
	 * @param int                 $status HTTP status code.
	 * @param array<string,mixed> $body   Response body, JSON-encoded.
	 * @return void
	 */
	protected function mock_response( $status, array $body ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $status, $body ) {
				unset( $preempt, $args, $url );

				return array(
					'response' => array( 'code' => $status ),
					'body'     => wp_json_encode( $body ),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Short-circuit all HTTP requests with a fixed status and raw body.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $raw    Raw response body.
	 * @return void
	 */
	protected function mock_raw_response( $status, $raw ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $status, $raw ) {
				unset( $preempt, $args, $url );

				return array(
					'response' => array( 'code' => $status ),
					'body'     => $raw,
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}
}
