<?php
/**
 * Shared Google OAuth 2.0 service (Wave E4, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Google_OAuth_Service`:
 * byte-identical endpoints/constants, the single-use user-bound state
 * transient contract (`wp_mcp_ai_{service}_oauth_state_{md5}`,
 * delete-before-validate replay protection), the byte-stable redirect
 * URI builders, the offline/consent authorize-URL builder, the
 * `allowed_redirect_hosts` filter, the authorization-code exchange,
 * the refresh-token access-token minting with the
 * `wp_mcp_ai_google_access_token_{md5}` cache + 300 s safety margin,
 * the revocation flow, and the token-response parser with its error
 * codes (`wp_mcp_ai_oauth_missing_state|invalid_state|
 * state_user_mismatch|incomplete_request|incomplete_exchange|
 * transport_error|invalid_response|rejected|invalid_grant|
 * no_access_token|missing_refresh_credentials|missing_token|
 * revoke_failed`).
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Google
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Google;

/**
 * Google OAuth 2.0 helper shared by all Google connections.
 *
 * @since 2.1.0
 */
class GoogleOAuthService {

	/**
	 * Google OAuth 2.0 authorization endpoint.
	 *
	 * @var string
	 */
	const AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * Google OAuth 2.0 token endpoint.
	 *
	 * @var string
	 */
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/**
	 * Google OAuth 2.0 revocation endpoint.
	 *
	 * @var string
	 */
	const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

	/**
	 * Google userinfo endpoint used to resolve the authorised account email.
	 *
	 * @var string
	 */
	const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';

	/**
	 * Hostname added to `allowed_redirect_hosts` for the authorize redirect.
	 *
	 * @var string
	 */
	const AUTHORIZE_HOST = 'accounts.google.com';

	/**
	 * Lifetime of an OAuth state transient, in seconds.
	 *
	 * @var int
	 */
	const STATE_TTL = 600;

	/**
	 * Safety margin subtracted from `expires_in` when caching access tokens.
	 *
	 * @var int
	 */
	const TOKEN_CACHE_MARGIN = 300;

	/**
	 * Default HTTP timeout for token and userinfo requests, in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Build the OAuth state transient key for a service.
	 *
	 * @since 2.1.0
	 *
	 * @param string $service Service slug, e.g. `google_calendar`.
	 * @param string $state   Opaque state value.
	 * @return string Transient key.
	 */
	public static function state_transient_key( $service, $state ) {
		return 'wp_mcp_ai_' . sanitize_key( $service ) . '_oauth_state_' . md5( (string) $state );
	}

	/**
	 * Generate and store a single-use OAuth state value.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $service Service slug, e.g. `google_calendar`.
	 * @param array<string,mixed> $payload Extra data to associate with the state
	 *                                     (for example `connection_id`). The
	 *                                     current user ID and timestamp are added
	 *                                     automatically.
	 * @return string The generated state value.
	 */
	public static function store_state( $service, array $payload = array() ) {
		$state = wp_generate_uuid4();

		$data = array_merge(
			$payload,
			array(
				'user_id' => get_current_user_id(),
				'time'    => time(),
			)
		);

		set_transient( self::state_transient_key( $service, $state ), $data, self::STATE_TTL );

		return $state;
	}

	/**
	 * Consume and validate an OAuth state value.
	 *
	 * The transient is deleted before validation so a replayed callback cannot
	 * succeed, even inside the TTL window.
	 *
	 * @since 2.1.0
	 *
	 * @param string $service Service slug, e.g. `google_calendar`.
	 * @param string $state   State value received from Google.
	 * @return array<string,mixed>|\WP_Error State payload on success, WP_Error otherwise.
	 */
	public static function consume_state( $service, $state ) {
		$state = is_string( $state ) ? trim( $state ) : '';

		if ( '' === $state ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_missing_state',
				__( 'OAuth state verification failed. Please try again.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$key  = self::state_transient_key( $service, $state );
		$data = get_transient( $key );

		// Single-use: always delete, regardless of validity.
		delete_transient( $key );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_invalid_state',
				__( 'OAuth state verification failed or expired. Please try connecting again.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$expected_user = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;

		if ( ! $expected_user || get_current_user_id() !== $expected_user ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_state_user_mismatch',
				__( 'OAuth state verification failed because the request was started by a different user.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $data;
	}

	/**
	 * Build a redirect URI for a base-plugin OAuth callback.
	 *
	 * Google requires the redirect URI supplied at authorize time and at token
	 * exchange time to be byte-identical, so both call sites must use this
	 * helper.
	 *
	 * @since 2.1.0
	 *
	 * @param string $handler Callback handler slug, e.g. `google_calendar_callback`.
	 * @return string Absolute redirect URI.
	 */
	public static function build_redirect_uri( $handler ) {
		return add_query_arg(
			array( 'wp_mcp_ai_oauth' => $handler ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a redirect URI for a Pro Remote Sites OAuth callback.
	 *
	 * Remote Sites uses an `oauth_handler` query var rather than `action`,
	 * because Google rejects redirect URIs containing an `action` parameter.
	 *
	 * @since 2.1.0
	 *
	 * @param string $handler Callback handler slug, e.g. `google_calendar_oauth_callback`.
	 * @param string $page    Admin page slug. Defaults to the Remote Sites page.
	 * @return string Absolute redirect URI.
	 */
	public static function build_remote_redirect_uri( $handler, $page = 'wp-mcp-ai-remote-sites' ) {
		return add_query_arg(
			array(
				'page'          => $page,
				'oauth_handler' => $handler,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a Google OAuth 2.0 authorization URL.
	 *
	 * Always requests offline access with `prompt=consent` so a refresh token is
	 * returned, and sets `include_granted_scopes=true` for incremental
	 * authorisation.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     Authorization request arguments.
	 *
	 *     @type string $client_id    Required. OAuth client ID.
	 *     @type string $redirect_uri Required. Redirect URI from one of the builders above.
	 *     @type string $scope        Required. Space-delimited scope string.
	 *     @type string $state        Required. State value from `store_state()`.
	 *     @type string $login_hint   Optional. Account email to pre-select.
	 * }
	 * @return string|\WP_Error Authorization URL, or WP_Error when required args are missing.
	 */
	public static function build_authorize_url( array $args ) {
		$client_id    = isset( $args['client_id'] ) ? trim( (string) $args['client_id'] ) : '';
		$redirect_uri = isset( $args['redirect_uri'] ) ? (string) $args['redirect_uri'] : '';
		$scope        = isset( $args['scope'] ) ? trim( (string) $args['scope'] ) : '';
		$state        = isset( $args['state'] ) ? (string) $args['state'] : '';

		if ( '' === $client_id || '' === $redirect_uri || '' === $scope || '' === $state ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_incomplete_request',
				__( 'Unable to build the Google authorization URL because required parameters are missing.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$params = array(
			'client_id'              => $client_id,
			'redirect_uri'           => $redirect_uri,
			'response_type'          => 'code',
			'scope'                  => $scope,
			'access_type'            => 'offline',
			'include_granted_scopes' => 'true',
			'prompt'                 => 'consent',
			'state'                  => $state,
		);

		$login_hint = isset( $args['login_hint'] ) ? trim( (string) $args['login_hint'] ) : '';

		if ( '' !== $login_hint && 'me' !== strtolower( $login_hint ) ) {
			$params['login_hint'] = $login_hint;
		}

		return add_query_arg( $params, self::AUTHORIZE_ENDPOINT );
	}

	/**
	 * Allow the Google authorize host in `wp_safe_redirect()`.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string> $hosts Allowed hosts.
	 * @return array<string> Allowed hosts including the Google OAuth host.
	 */
	public static function filter_allowed_redirect_hosts( $hosts ) {
		$hosts   = is_array( $hosts ) ? $hosts : array();
		$hosts[] = self::AUTHORIZE_HOST;

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     Token exchange arguments.
	 *
	 *     @type string $code          Required. Authorization code from the callback.
	 *     @type string $client_id     Required. OAuth client ID.
	 *     @type string $client_secret Required. OAuth client secret (plaintext).
	 *     @type string $redirect_uri  Required. Must byte-match the authorize request.
	 *     @type int    $timeout       Optional. HTTP timeout in seconds.
	 * }
	 * @return array<string,mixed>|\WP_Error Token payload on success.
	 */
	public static function exchange_code( array $args ) {
		$code          = isset( $args['code'] ) ? trim( (string) $args['code'] ) : '';
		$client_id     = isset( $args['client_id'] ) ? trim( (string) $args['client_id'] ) : '';
		$client_secret = isset( $args['client_secret'] ) ? (string) $args['client_secret'] : '';
		$redirect_uri  = isset( $args['redirect_uri'] ) ? (string) $args['redirect_uri'] : '';
		$timeout       = isset( $args['timeout'] ) ? absint( $args['timeout'] ) : self::DEFAULT_TIMEOUT;

		if ( '' === $code || '' === $client_id || '' === $client_secret || '' === $redirect_uri ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_incomplete_exchange',
				__( 'Unable to exchange the authorization code because required credentials are missing.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		return self::parse_token_response( $response );
	}

	/**
	 * Exchange a refresh token for a fresh access token, with caching.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     Refresh arguments.
	 *
	 *     @type string $client_id     Required. OAuth client ID.
	 *     @type string $client_secret Required. OAuth client secret (plaintext).
	 *     @type string $refresh_token Required. Refresh token (plaintext).
	 *     @type string $cache_key     Optional. Stable identity for the token cache.
	 *                                 When omitted a hash of the credentials is used.
	 *     @type bool   $force_refresh Optional. Bypass the cache. Default false.
	 *     @type int    $timeout       Optional. HTTP timeout in seconds.
	 * }
	 * @return string|\WP_Error Access token on success, WP_Error otherwise.
	 */
	public static function mint_access_token( array $args ) {
		$client_id     = isset( $args['client_id'] ) ? trim( (string) $args['client_id'] ) : '';
		$client_secret = isset( $args['client_secret'] ) ? (string) $args['client_secret'] : '';
		$refresh_token = isset( $args['refresh_token'] ) ? (string) $args['refresh_token'] : '';
		$timeout       = isset( $args['timeout'] ) ? absint( $args['timeout'] ) : self::DEFAULT_TIMEOUT;
		$force         = ! empty( $args['force_refresh'] );

		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_missing_refresh_credentials',
				__( 'Google credentials are incomplete. A client ID, client secret, and refresh token are all required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$cache_key = isset( $args['cache_key'] ) && '' !== $args['cache_key']
			? (string) $args['cache_key']
			: md5( $client_id . '|' . $refresh_token );

		$transient = 'wp_mcp_ai_google_access_token_' . md5( $cache_key );

		if ( ! $force ) {
			$cached = get_transient( $transient );

			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		$parsed = self::parse_token_response( $response );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$access_token = isset( $parsed['access_token'] ) ? (string) $parsed['access_token'] : '';

		if ( '' === $access_token ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_no_access_token',
				__( 'Google did not return an access token.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$expires_in = isset( $parsed['expires_in'] ) ? absint( $parsed['expires_in'] ) : 3600;
		$ttl        = $expires_in - self::TOKEN_CACHE_MARGIN;

		if ( $ttl > 0 ) {
			set_transient( $transient, $access_token, $ttl );
		}

		return $access_token;
	}

	/**
	 * Clear a cached access token.
	 *
	 * Call this when a refresh token is rotated or revoked so the next request
	 * does not present a token minted from stale credentials.
	 *
	 * @since 2.1.0
	 *
	 * @param string $cache_key Identity used when the token was cached.
	 * @return void
	 */
	public static function forget_access_token( $cache_key ) {
		if ( ! is_string( $cache_key ) || '' === $cache_key ) {
			return;
		}

		delete_transient( 'wp_mcp_ai_google_access_token_' . md5( $cache_key ) );
	}

	/**
	 * Resolve the email address of the authorised Google account.
	 *
	 * @since 2.1.0
	 *
	 * @param string $access_token Access token.
	 * @param int    $timeout      Optional. HTTP timeout in seconds.
	 * @return string Email address, or an empty string when unavailable.
	 */
	public static function fetch_userinfo_email( $access_token, $timeout = self::DEFAULT_TIMEOUT ) {
		$access_token = is_string( $access_token ) ? trim( $access_token ) : '';

		if ( '' === $access_token ) {
			return '';
		}

		$response = wp_remote_get(
			self::USERINFO_ENDPOINT,
			array(
				'timeout' => $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['email'] ) ) {
			return '';
		}

		return sanitize_email( $body['email'] );
	}

	/**
	 * Revoke an access or refresh token at Google.
	 *
	 * Revoking an access token also revokes its corresponding refresh token.
	 * Callers should treat failure as non-fatal — local credentials must still
	 * be cleared even when the upstream revocation cannot be confirmed.
	 *
	 * @since 2.1.0
	 *
	 * @param string $token   Access or refresh token.
	 * @param int    $timeout Optional. HTTP timeout in seconds.
	 * @return true|\WP_Error True on success, WP_Error otherwise.
	 */
	public static function revoke( $token, $timeout = self::DEFAULT_TIMEOUT ) {
		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_missing_token',
				__( 'No token was supplied for revocation.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$response = wp_remote_post(
			self::REVOKE_ENDPOINT,
			array(
				'timeout' => $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array( 'token' => $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_revoke_failed',
				__( 'Google rejected the token revocation request.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => $status )
			);
		}

		return true;
	}

	/**
	 * Parse and validate a Google token endpoint response.
	 *
	 * @since 2.1.0
	 *
	 * @param array|\WP_Error $response Raw `wp_remote_*` response.
	 * @return array<string,mixed>|\WP_Error Decoded body on success, WP_Error otherwise.
	 */
	protected static function parse_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_transport_error',
				__( 'Unable to reach the Google token endpoint. Please try again.', 'nvoos-content-graph-ai-platform' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'wp_mcp_ai_oauth_invalid_response',
				__( 'Google returned an unreadable response from the token endpoint.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => $status )
			);
		}

		if ( 200 !== $status ) {
			$message = __( 'Google rejected the authorization request.', 'nvoos-content-graph-ai-platform' );

			if ( ! empty( $data['error_description'] ) ) {
				$message = sprintf( '%s %s', $message, (string) $data['error_description'] );
			} elseif ( ! empty( $data['error'] ) ) {
				$message = sprintf( '%s (%s)', $message, (string) $data['error'] );
			}

			// `invalid_grant` on a refresh means the token was revoked or expired.
			$code = isset( $data['error'] ) && 'invalid_grant' === $data['error']
				? 'wp_mcp_ai_oauth_invalid_grant'
				: 'wp_mcp_ai_oauth_rejected';

			return new \WP_Error(
				$code,
				$message,
				array(
					'status'   => $status,
					'response' => $data,
				)
			);
		}

		return $data;
	}
}
