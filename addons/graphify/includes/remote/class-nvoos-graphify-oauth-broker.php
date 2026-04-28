<?php
/**
 * NV oOS Graphify — OAuth2 Connection Broker
 *
 * Reusable OAuth2 helper for SaaS remote-source drivers (Slack, GitHub,
 * HubSpot, Google Workspace, Microsoft 365, Atlassian, etc.). The broker
 * provides the four primitives every OAuth2-backed driver needs:
 *
 *   1. build_authorize_url() — assemble the redirect URL the user is sent to
 *   2. exchange_code()       — swap an auth code for access + refresh tokens
 *   3. refresh_token()       — exchange a refresh token for a fresh access token
 *   4. get_access_token()    — return a valid access token, refreshing if expired
 *
 * Tokens are persisted inside each remote-source's `config_json` blob via the
 * existing NV_oOS_Graphify_DB::save_remote_source() path, which already
 * encrypts sensitive fields (refresh_token, access_token) via
 * NV_oOS_Graphify_Crypto::is_sensitive_key().
 *
 * The broker performs zero schema changes and reuses the existing remote-source
 * row for storage. It does not register REST endpoints itself — drivers that
 * need a callback URL register their own under the existing graphify REST
 * namespace and call exchange_code() from the callback handler.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth2 authorisation-code-flow broker.
 *
 * @since 0.7.0
 */
class NV_oOS_Graphify_OAuth_Broker {

	/**
	 * Default expiry buffer in seconds — refresh when fewer than this many
	 * seconds remain before access-token expiry.
	 *
	 * @var int
	 */
	const REFRESH_BUFFER_SECONDS = 60;

	/**
	 * Build an OAuth2 authorise URL.
	 *
	 * @since 0.7.0
	 *
	 * @param array $args {
	 *     Authorise-URL parameters.
	 *
	 *     @type string $authorize_url Provider authorise URL (required).
	 *     @type string $client_id     Client ID (required).
	 *     @type string $redirect_uri  Redirect URI registered with provider (required).
	 *     @type string $scope         Space-separated scope string.
	 *     @type string $state         Opaque CSRF token (required).
	 *     @type array  $extra         Additional query parameters.
	 * }
	 * @return string|WP_Error Fully-qualified URL or WP_Error.
	 */
	public static function build_authorize_url( array $args ) {
		$authorize_url = isset( $args['authorize_url'] ) ? esc_url_raw( $args['authorize_url'] ) : '';
		$client_id     = isset( $args['client_id'] ) ? sanitize_text_field( $args['client_id'] ) : '';
		$redirect_uri  = isset( $args['redirect_uri'] ) ? esc_url_raw( $args['redirect_uri'] ) : '';
		$scope         = isset( $args['scope'] ) ? sanitize_text_field( $args['scope'] ) : '';
		$state         = isset( $args['state'] ) ? sanitize_text_field( $args['state'] ) : '';
		$extra         = isset( $args['extra'] ) && is_array( $args['extra'] ) ? $args['extra'] : array();

		if ( empty( $authorize_url ) || empty( $client_id ) || empty( $redirect_uri ) || empty( $state ) ) {
			return new WP_Error( 'oauth_missing_args', __( 'authorize_url, client_id, redirect_uri, and state are required.', 'nvoos-graphify' ) );
		}

		$query = array_merge(
			array(
				'response_type' => 'code',
				'client_id'     => $client_id,
				'redirect_uri'  => $redirect_uri,
				'state'         => $state,
			),
			$extra
		);
		if ( ! empty( $scope ) ) {
			$query['scope'] = $scope;
		}

		return add_query_arg( $query, $authorize_url );
	}

	/**
	 * Exchange an authorisation code for an access + refresh token.
	 *
	 * @since 0.7.0
	 *
	 * @param array $args {
	 *     Authorisation-code exchange parameters.
	 *
	 *     @type string $token_url     Provider token endpoint (required).
	 *     @type string $client_id     Client ID (required).
	 *     @type string $client_secret Client secret (required).
	 *     @type string $redirect_uri  Same redirect_uri used on authorise (required).
	 *     @type string $code          Authorisation code (required).
	 *     @type array  $extra         Additional POST body parameters.
	 * }
	 * @return array|WP_Error Token array or WP_Error.
	 */
	public static function exchange_code( array $args ) {
		$token_url     = isset( $args['token_url'] ) ? esc_url_raw( $args['token_url'] ) : '';
		$client_id     = isset( $args['client_id'] ) ? sanitize_text_field( $args['client_id'] ) : '';
		$client_secret = isset( $args['client_secret'] ) ? (string) $args['client_secret'] : '';
		$redirect_uri  = isset( $args['redirect_uri'] ) ? esc_url_raw( $args['redirect_uri'] ) : '';
		$code          = isset( $args['code'] ) ? sanitize_text_field( $args['code'] ) : '';
		$extra         = isset( $args['extra'] ) && is_array( $args['extra'] ) ? $args['extra'] : array();

		if ( empty( $token_url ) || empty( $client_id ) || empty( $client_secret ) || empty( $redirect_uri ) || empty( $code ) ) {
			return new WP_Error( 'oauth_missing_args', __( 'token_url, client_id, client_secret, redirect_uri, and code are required.', 'nvoos-graphify' ) );
		}

		$body = array_merge(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $redirect_uri,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			),
			$extra
		);

		return self::request_token( $token_url, $body );
	}

	/**
	 * Exchange a refresh token for a fresh access token.
	 *
	 * @since 0.7.0
	 *
	 * @param array $args {
	 *     Refresh-token exchange parameters.
	 *
	 *     @type string $token_url     Provider token endpoint (required).
	 *     @type string $client_id     Client ID (required).
	 *     @type string $client_secret Client secret (required).
	 *     @type string $refresh_token Refresh token (required).
	 *     @type array  $extra         Additional POST body parameters.
	 * }
	 * @return array|WP_Error Token array or WP_Error.
	 */
	public static function refresh_token( array $args ) {
		$token_url     = isset( $args['token_url'] ) ? esc_url_raw( $args['token_url'] ) : '';
		$client_id     = isset( $args['client_id'] ) ? sanitize_text_field( $args['client_id'] ) : '';
		$client_secret = isset( $args['client_secret'] ) ? (string) $args['client_secret'] : '';
		$refresh_token = isset( $args['refresh_token'] ) ? (string) $args['refresh_token'] : '';
		$extra         = isset( $args['extra'] ) && is_array( $args['extra'] ) ? $args['extra'] : array();

		if ( empty( $token_url ) || empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
			return new WP_Error( 'oauth_missing_args', __( 'token_url, client_id, client_secret, and refresh_token are required.', 'nvoos-graphify' ) );
		}

		$body = array_merge(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			),
			$extra
		);

		return self::request_token( $token_url, $body );
	}

	/**
	 * Return a valid access token for a source, transparently refreshing it
	 * if the stored token is expired or about to expire.
	 *
	 * Expects the source's config to contain: token_url, client_id,
	 * client_secret, refresh_token, access_token, expires_at (RFC3339).
	 *
	 * @since 0.7.0
	 *
	 * @param array $config Source config array (decrypted).
	 * @return string|WP_Error Access token or WP_Error.
	 */
	public static function get_access_token( array $config ) {
		$access_token = isset( $config['access_token'] ) ? (string) $config['access_token'] : '';
		$expires_at   = isset( $config['expires_at'] ) ? (string) $config['expires_at'] : '';

		// Fast-path: token still valid.
		if ( ! empty( $access_token ) && ! self::is_expired( $expires_at ) ) {
			return $access_token;
		}

		// Otherwise refresh.
		$refreshed = self::refresh_token(
			array(
				'token_url'     => isset( $config['token_url'] ) ? $config['token_url'] : '',
				'client_id'     => isset( $config['client_id'] ) ? $config['client_id'] : '',
				'client_secret' => isset( $config['client_secret'] ) ? $config['client_secret'] : '',
				'refresh_token' => isset( $config['refresh_token'] ) ? $config['refresh_token'] : '',
			)
		);

		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		return isset( $refreshed['access_token'] ) ? (string) $refreshed['access_token'] : new WP_Error( 'oauth_no_token', __( 'Provider response did not include an access_token.', 'nvoos-graphify' ) );
	}

	/**
	 * Persist a token bundle into a remote source's config (encrypting
	 * sensitive fields automatically via the existing save_remote_source path).
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug   Source slug.
	 * @param array  $tokens Token array as returned by exchange_code() / refresh_token().
	 * @return true|WP_Error
	 */
	public static function persist_tokens( $slug, array $tokens ) {
		$slug   = sanitize_key( $slug );
		$source = NV_oOS_Graphify_DB::get_remote_source( $slug );
		if ( ! $source ) {
			return new WP_Error( 'oauth_unknown_source', __( 'Unknown source slug.', 'nvoos-graphify' ) );
		}

		$config = array();
		if ( ! empty( $source->config_json ) ) {
			$decoded = json_decode( $source->config_json, true );
			if ( is_array( $decoded ) ) {
				// Decrypt existing sensitive values so save_remote_source can re-encrypt them.
				foreach ( $decoded as $k => $v ) {
					if ( is_string( $v ) && NV_oOS_Graphify_Crypto::is_sensitive_key( $k ) ) {
						$decoded[ $k ] = NV_oOS_Graphify_Crypto::decrypt( $v );
					}
				}
				$config = $decoded;
			}
		}

		if ( isset( $tokens['access_token'] ) ) {
			$config['access_token'] = (string) $tokens['access_token'];
		}
		if ( isset( $tokens['refresh_token'] ) ) {
			$config['refresh_token'] = (string) $tokens['refresh_token'];
		}
		if ( isset( $tokens['expires_in'] ) ) {
			$config['expires_at'] = gmdate( 'c', time() + absint( $tokens['expires_in'] ) );
		}

		return NV_oOS_Graphify_DB::save_remote_source(
			array(
				'slug'    => $slug,
				'driver'  => $source->driver,
				'label'   => $source->label,
				'enabled' => (int) $source->enabled,
				'config'  => $config,
			)
		);
	}

	/**
	 * Determine whether an RFC3339 expiry timestamp is in the past
	 * (or within the refresh buffer window).
	 *
	 * @since 0.7.0
	 *
	 * @param string $expires_at RFC3339 timestamp; empty string = expired.
	 * @return bool
	 */
	public static function is_expired( $expires_at ) {
		if ( empty( $expires_at ) ) {
			return true;
		}
		$ts = strtotime( $expires_at );
		if ( false === $ts ) {
			return true;
		}
		return ( $ts - self::REFRESH_BUFFER_SECONDS ) <= time();
	}

	/**
	 * Perform the actual POST to the provider's token endpoint and parse JSON.
	 *
	 * @since 0.7.0
	 *
	 * @param string $token_url Provider token endpoint.
	 * @param array  $body      POST body (form-urlencoded).
	 * @return array|WP_Error Decoded JSON token array, or WP_Error.
	 */
	private static function request_token( $token_url, array $body ) {
		$response = wp_remote_post(
			$token_url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			$msg = is_array( $data ) && isset( $data['error_description'] ) ? (string) $data['error_description'] : '';
			if ( '' === $msg && is_array( $data ) && isset( $data['error'] ) ) {
				$msg = (string) $data['error'];
			}
			if ( '' === $msg ) {
				/* translators: %d HTTP status code */
				$msg = sprintf( __( 'OAuth provider returned HTTP %d.', 'nvoos-graphify' ), $status );
			}
			return new WP_Error( 'oauth_http_error', $msg, array( 'status' => $status ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'oauth_invalid_response', __( 'OAuth provider response was not valid JSON.', 'nvoos-graphify' ) );
		}

		return $data;
	}
}
