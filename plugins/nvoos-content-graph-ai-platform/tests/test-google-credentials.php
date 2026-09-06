<?php
/**
 * Google Calendar credentials port tests (Wave E4, sub-cluster 3).
 *
 * Characterization suite for the ported `GoogleCalendarCredentials`: the
 * connection → settings → filter resolution order, the settings-level
 * surface (per-mode: the `wp_mcp_ai_settings` option standalone, the
 * base admin-settings component monolith), the legacy filter surface,
 * the lazy token-provider `make_client()` branches, the JWT
 * service-account minting with its access-token cache, the IANA
 * timezone fallback, the scope assertion, the calendar-ID resolution,
 * and the standalone `wp_mcp_ai_calendar_pro_required` degradation for
 * Remote Sites connections. HTTP is intercepted via `pre_http_request`.
 * Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Google\GoogleCalendarCredentials;
use NvoosContentGraphAiPlatform\Google\GoogleCalendarScopes;
use NvoosContentGraphAiPlatform\Google\GoogleCalendarClient;
use NvoosContentGraphAiPlatform\Google\GoogleOAuthService;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Seam exposing protected members for contract testing.
 */
class GoogleCalendarCredentialsSeam extends GoogleCalendarCredentials {

	/**
	 * Expose resolve_from_settings().
	 *
	 * @return array|\WP_Error
	 */
	public static function seam_resolve_from_settings() {
		return self::resolve_from_settings();
	}

	/**
	 * Expose resolve_from_filters().
	 *
	 * @param array $context   Tool context.
	 * @param array $arguments Tool arguments.
	 * @return array|\WP_Error
	 */
	public static function seam_resolve_from_filters( array $context = array(), array $arguments = array() ) {
		return self::resolve_from_filters( $context, $arguments );
	}

	/**
	 * Expose mint_service_account_token().
	 *
	 * @param array $service_account Service-account credentials.
	 * @param array $credentials     Resolved credential bundle.
	 * @return string|\WP_Error
	 */
	public static function seam_mint_service_account_token( array $service_account, array $credentials ) {
		return self::mint_service_account_token( $service_account, $credentials );
	}
}

/**
 * Google Calendar credential resolution characterization.
 */
class Test_Google_Credentials extends \WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );

		// Monolith: the base admin-settings component caches its merged
		// settings statically; reset so cross-test writes are visible.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
	}

	/**
	 * Fully configured settings resolve as the settings source.
	 */
	public function test_settings_surface_resolves_full_connection() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'google_calendar_client_id'           => 'cid',
				'google_calendar_client_secret'       => 'secret',
				'google_calendar_refresh_token'       => 'refresh',
				'google_calendar_user_email'          => 'admin@example.com',
				'google_calendar_default_calendar_id' => 'team@group.calendar.google.com',
				'google_calendar_scope_profile'       => GoogleCalendarScopes::PROFILE_FULL,
				'google_calendar_timezone'            => 'Europe/London',
			)
		);

		$credentials = GoogleCalendarCredentials::resolve();

		$this->assertIsArray( $credentials );
		$this->assertSame( GoogleCalendarCredentials::SOURCE_SETTINGS, $credentials['source'] );
		$this->assertSame( 'cid', $credentials['client_id'] );
		$this->assertSame( 'secret', $credentials['client_secret'] );
		$this->assertSame( 'refresh', $credentials['refresh_token'] );
		$this->assertSame( 'admin@example.com', $credentials['user_email'] );
		$this->assertSame( 'team@group.calendar.google.com', $credentials['calendar_id'] );
		$this->assertSame( GoogleCalendarScopes::PROFILE_FULL, $credentials['scope_profile'] );
		$this->assertSame( 'Europe/London', $credentials['timezone'] );
	}

	/**
	 * A settings connection without credentials fails with the actionable
	 * error code.
	 */
	public function test_settings_surface_missing_credentials_fails() {
		$result = GoogleCalendarCredentials::resolve();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_missing_credentials', $result->get_error_code() );
	}

	/**
	 * A settings connection falls back to `primary` for the calendar ID and
	 * the standard profile for the scope profile.
	 */
	public function test_settings_surface_fills_defaults() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'google_calendar_client_id'     => 'cid',
				'google_calendar_client_secret' => 'secret',
				'google_calendar_refresh_token' => 'refresh',
			)
		);

		$credentials = GoogleCalendarCredentials::resolve();

		$this->assertIsArray( $credentials );
		$this->assertSame( 'primary', $credentials['calendar_id'] );
		$this->assertSame( GoogleCalendarScopes::DEFAULT_PROFILE, $credentials['scope_profile'] );
	}

	/**
	 * The legacy filter surface resolves a pre-minted access token.
	 */
	public function test_filter_surface_resolves_access_token() {
		add_filter(
			'wp_mcp_ai_google_calendar_access_token',
			function ( $token ) {
				unset( $token );

				return 'pre-minted-token';
			}
		);

		$credentials = GoogleCalendarCredentialsSeam::seam_resolve_from_filters();

		$this->assertIsArray( $credentials );
		$this->assertSame( GoogleCalendarCredentials::SOURCE_FILTER, $credentials['source'] );
		$this->assertSame( 'pre-minted-token', $credentials['access_token'] );
		$this->assertSame( 'primary', $credentials['calendar_id'] );
		$this->assertSame( '', $credentials['granted_scopes'] );
	}

	/**
	 * The legacy filter surface resolves service-account credentials and the
	 * filtered calendar ID.
	 */
	public function test_filter_surface_resolves_service_account() {
		add_filter(
			'wp_mcp_ai_google_calendar_service_account_credentials',
			function () {
				return array(
					'client_email' => 'sa@example.com',
					'private_key'  => 'key',
				);
			}
		);
		add_filter(
			'wp_mcp_ai_google_calendar_default_calendar_id',
			function () {
				return 'filtered@group.calendar.google.com';
			}
		);

		$credentials = GoogleCalendarCredentialsSeam::seam_resolve_from_filters();

		$this->assertIsArray( $credentials );
		$this->assertSame( 'sa@example.com', $credentials['service_account']['client_email'] );
		$this->assertSame( 'filtered@group.calendar.google.com', $credentials['calendar_id'] );
	}

	/**
	 * With nothing configured, the settings-level error wins because it is the
	 * actionable one for admins.
	 */
	public function test_resolution_falls_back_to_the_settings_error() {
		$result = GoogleCalendarCredentials::resolve();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_missing_credentials', $result->get_error_code() );
	}

	/**
	 * Remote Sites resolution is unavailable in this addon's test matrices:
	 * standalone never ships the Pro manager, and the monolith matrix may or
	 * may not load the Pro addon's connection manager. Both surfaces must
	 * degrade with the documented error codes rather than a PHP fatal.
	 */
	public function test_connection_surface_degrades_without_a_pro_connection() {
		$result = GoogleCalendarCredentials::resolve( 'conn_1' );

		$this->assertWPError( $result );

		$expected = array(
			// Standalone (and monolith without the Pro addon loaded).
			'wp_mcp_ai_calendar_pro_required',
			// Monolith with the Pro manager loaded but no connection stored.
			'wp_mcp_ai_calendar_connection_not_found',
		);

		$this->assertContains( $result->get_error_code(), $expected );
	}

	// Client construction.

	/**
	 * A pre-minted access token becomes a literal token provider, so no OAuth
	 * machinery is touched.
	 */
	public function test_make_client_with_access_token() {
		$client = GoogleCalendarCredentials::make_client(
			array(
				'access_token' => 'literal-token',
				'user_email'   => 'admin@example.com',
			)
		);

		$this->assertInstanceOf( self::expected_client_class(), $client );

		$this->mock_response( 200, array( 'kind' => 'calendar#calendarList' ) );

		$result = $client->list_calendars();

		$this->assertIsArray( $result );
	}

	/**
	 * Incomplete refresh credentials fail make_client() before any provider is
	 * built.
	 */
	public function test_make_client_requires_complete_credentials() {
		$result = GoogleCalendarCredentials::make_client( array( 'client_id' => 'cid' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_missing_credentials', $result->get_error_code() );
	}

	/**
	 * Refresh-based clients attribute quota to the impersonated user.
	 */
	public function test_make_client_mints_token_via_refresh_flow() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				unset( $preempt, $args );

				if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'access_token' => 'minted-1',
								'expires_in'   => 3600,
							)
						),
						'headers'  => array(),
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client = GoogleCalendarCredentials::make_client(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'refresh_token' => 'refresh',
				'cache_key'     => 'test-' . wp_generate_uuid4(),
				'user_email'    => 'admin@example.com',
			)
		);

		$this->assertInstanceOf( self::expected_client_class(), $client );

		$result = $client->list_calendars();

		$this->assertIsArray( $result );
	}

	// Service accounts.

	/**
	 * Incomplete service-account credentials fail with the invalid-credentials
	 * error before any HTTP request.
	 */
	public function test_service_account_incomplete_credentials_fail() {
		$result = GoogleCalendarCredentialsSeam::seam_mint_service_account_token(
			array( 'client_email' => 'sa@example.com' ),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_invalid_credentials', $result->get_error_code() );
	}

	/**
	 * A cached service-account token short-circuits signing and HTTP entirely.
	 */
	public function test_service_account_cached_token_short_circuits() {
		$cache_key = 'sa:' . md5( 'sa@example.com||https://www.googleapis.com/auth/calendar.events' );
		set_transient( 'wp_mcp_ai_google_access_token_' . md5( $cache_key ), 'sa-cached', HOUR_IN_SECONDS );

		$result = GoogleCalendarCredentialsSeam::seam_mint_service_account_token(
			array(
				'client_email' => 'sa@example.com',
				'private_key'  => 'unused',
				'scopes'       => array( 'https://www.googleapis.com/auth/calendar.events' ),
			),
			array()
		);

		$this->assertSame( 'sa-cached', $result );
	}

	/**
	 * A service-account mint signs an RS256 JWT assertion and exchanges it for
	 * an access token, caching the result with the safety margin.
	 */
	public function test_service_account_mint_signs_and_exchanges() {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL is unavailable in this environment.' );
		}

		$key = openssl_pkey_new( array( 'private_key_bits' => 1024 ) );

		if ( false === $key ) {
			$this->markTestSkipped( 'Unable to generate a test RSA key.' );
		}

		openssl_pkey_export( $key, $private_key );

		$assertion_parts = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$assertion_parts ) {
				unset( $preempt, $url );

				$assertion_parts = explode( '.', (string) $args['body']['assertion'] );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'access_token' => 'sa-minted',
							'expires_in'   => 3600,
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = GoogleCalendarCredentialsSeam::seam_mint_service_account_token(
			array(
				'client_email' => 'sa@example.com',
				'private_key'  => $private_key,
				'scopes'       => array( 'https://www.googleapis.com/auth/calendar.events' ),
			),
			array()
		);

		$this->assertSame( 'sa-minted', $result );

		$this->assertIsArray( $assertion_parts );
		$this->assertCount( 3, $assertion_parts );

		$header = json_decode( base64_decode( strtr( $assertion_parts[0], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test-only JWT decoding.
		$this->assertSame( 'RS256', $header['alg'] );

		$claims = json_decode( base64_decode( strtr( $assertion_parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test-only JWT decoding.
		$this->assertSame( 'sa@example.com', $claims['iss'] );
		$this->assertSame( 'https://www.googleapis.com/auth/calendar.events', $claims['scope'] );
		$this->assertSame( GoogleOAuthService::TOKEN_ENDPOINT, $claims['aud'] );
		$this->assertArrayNotHasKey( 'sub', $claims, 'No delegation without a delegated email.' );

		$cache_key = 'sa:' . md5( 'sa@example.com||https://www.googleapis.com/auth/calendar.events' );
		$this->assertSame( 'sa-minted', get_transient( 'wp_mcp_ai_google_access_token_' . md5( $cache_key ) ) );
	}

	/**
	 * A delegated service account includes the `sub` claim for domain-wide
	 * delegation.
	 */
	public function test_service_account_delegation_adds_sub_claim() {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL is unavailable in this environment.' );
		}

		$key = openssl_pkey_new( array( 'private_key_bits' => 1024 ) );

		if ( false === $key ) {
			$this->markTestSkipped( 'Unable to generate a test RSA key.' );
		}

		openssl_pkey_export( $key, $private_key );

		$assertion_parts = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$assertion_parts ) {
				unset( $preempt, $url );

				$assertion_parts = explode( '.', (string) $args['body']['assertion'] );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'access_token' => 'sa-minted',
							'expires_in'   => 3600,
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = GoogleCalendarCredentialsSeam::seam_mint_service_account_token(
			array(
				'client_email'    => 'sa@example.com',
				'private_key'     => $private_key,
				'delegated_email' => 'user@example.com',
				'scopes'          => array( 'https://www.googleapis.com/auth/calendar.events' ),
			),
			array()
		);

		$this->assertSame( 'sa-minted', $result );

		$claims = json_decode( base64_decode( strtr( $assertion_parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test-only JWT decoding.

		$this->assertSame( 'user@example.com', $claims['sub'] );
	}

	// Helpers.

	/**
	 * The IANA timezone fallback rejects UTC offsets, which the Calendar API
	 * refuses.
	 */
	public function test_default_timezone_falls_back_to_utc() {
		update_option( 'timezone_string', '+05:30' );

		$this->assertSame( 'UTC', GoogleCalendarCredentials::default_timezone() );
	}

	/**
	 * Scope assertions delegate to the scope registry and return its error.
	 */
	public function test_require_scope_asserts_the_grant() {
		$granted = array( 'granted_scopes' => GoogleCalendarScopes::SCOPE_EVENTS_READONLY );

		$this->assertTrue(
			GoogleCalendarCredentials::require_scope( $granted, GoogleCalendarScopes::SCOPE_EVENTS_READONLY )
		);

		$error = GoogleCalendarCredentials::require_scope( $granted, GoogleCalendarScopes::SCOPE_EVENTS );

		$this->assertWPError( $error );
		$this->assertSame( 'wp_mcp_ai_calendar_missing_scope', $error->get_error_code() );
	}

	/**
	 * Per-request calendar overrides win over the configured default.
	 */
	public function test_resolve_calendar_id_prefers_the_override() {
		$credentials = array( 'calendar_id' => 'configured@group.calendar.google.com' );

		$this->assertSame( 'override@group.calendar.google.com', GoogleCalendarCredentials::resolve_calendar_id( $credentials, 'override@group.calendar.google.com' ) );
		$this->assertSame( 'configured@group.calendar.google.com', GoogleCalendarCredentials::resolve_calendar_id( $credentials ) );
		$this->assertSame( 'primary', GoogleCalendarCredentials::resolve_calendar_id( array() ) );
	}

	// Helpers.

	/**
	 * The client class `make_client()` produces per install mode: the base
	 * monolith class in monolith installs, the platform class standalone.
	 *
	 * @return string Class name.
	 */
	protected static function expected_client_class() {
		return defined( 'WP_MCP_AI_PATH' )
			? 'WP_MCP_AI_Google_Calendar_Client'
			: GoogleCalendarClient::class;
	}

	/**
	 * Short-circuit all HTTP requests with a fixed status and body.
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
}
