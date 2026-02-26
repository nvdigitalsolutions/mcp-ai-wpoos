<?php
/**
 * Helper class for Google Service Account authentication.
 *
 * Builds a signed JWT assertion from a Service Account JSON key,
 * exchanges it for an OAuth 2.0 access token, and caches the result.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Service Account JWT signing and access token exchange for Google APIs.
 */
class WP_MCP_AI_Pro_Google_Service_Account {

	/**
	 * Google token endpoint.
	 */
	const TOKEN_URI = 'https://oauth2.googleapis.com/token';

	/**
	 * Transient key prefix for cached access tokens.
	 */
	const TOKEN_CACHE_PREFIX = 'wp_mcp_ai_gc_sa_token_';

	/**
	 * Parse a Service Account JSON key string into an array.
	 *
	 * @param string $json_key Raw JSON string from the downloaded service account key file.
	 * @return array|WP_Error Parsed credentials or error.
	 */
	public static function parse_key( $json_key ) {
		if ( ! is_string( $json_key ) || '' === trim( $json_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_gc_sa_empty_key',
				__( 'Service Account JSON key must not be empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		$credentials = json_decode( $json_key, true );

		if ( ! is_array( $credentials ) ) {
			return new WP_Error(
				'wp_mcp_ai_gc_sa_invalid_json',
				__( 'Service Account key is not valid JSON.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Detect non-service-account credential types and give a helpful error.
		$key_type = $credentials['type'] ?? '';
		if ( '' !== $key_type && 'service_account' !== $key_type ) {
			return new WP_Error(
				'wp_mcp_ai_gc_sa_wrong_key_type',
				sprintf(
					/* translators: %s: credential type found in the JSON key */
					__( 'The provided JSON is not a Service Account key (found type: "%s"). Please download a Service Account JSON key from Google Cloud Console, or use the OAuth 1-click connect button.', 'mcp-ai-wpoos-pro' ),
					$key_type
				)
			);
		}

		if ( empty( $credentials['client_email'] ) || empty( $credentials['private_key'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_gc_sa_incomplete_key',
				__( 'Service Account key is missing required fields (client_email, private_key).', 'mcp-ai-wpoos-pro' )
			);
		}

		return $credentials;
	}

	/**
	 * Retrieve an access token for the given Service Account credentials and scope.
	 *
	 * Tokens are cached in WordPress transients to avoid unnecessary token requests.
	 *
	 * @param array  $credentials Parsed service account credentials.
	 * @param string $scope       OAuth 2.0 scope string.
	 * @param int    $timeout     HTTP request timeout in seconds.
	 * @return string|WP_Error Access token string or error.
	 */
	public static function get_access_token( array $credentials, $scope, $timeout = 15 ) {
		$client_email = isset( $credentials['client_email'] ) ? sanitize_email( $credentials['client_email'] ) : '';
		$private_key  = isset( $credentials['private_key'] ) ? trim( (string) $credentials['private_key'] ) : '';
		$token_uri    = isset( $credentials['token_uri'] ) ? esc_url_raw( $credentials['token_uri'] ) : self::TOKEN_URI;

		if ( '' === $client_email || '' === $private_key ) {
			return new WP_Error(
				'wp_mcp_ai_gc_sa_incomplete_credentials',
				__( 'Incomplete Google Service Account credentials (client_email or private_key missing).', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( '' === $token_uri ) {
			$token_uri = self::TOKEN_URI;
		}

		// Check cache first.
		$cache_key = self::TOKEN_CACHE_PREFIX . md5( strtolower( $client_email ) . '|' . $scope );
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		// Build and sign the JWT assertion.
		$now    = time();
		$claims = array(
			'iss'   => $client_email,
			'scope' => $scope,
			'aud'   => $token_uri,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		$assertion = self::build_jwt_assertion( $claims, $private_key );
		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}

		// Exchange the JWT for an access token.
		$response = wp_remote_post(
			$token_uri,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'timeout' => absint( $timeout ) > 0 ? absint( $timeout ) : 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_gc_sa_token_request_failed',
				__( 'Unable to obtain a Google access token.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 || empty( $data['access_token'] ) ) {
			$message = __( 'Google rejected the Service Account token request.', 'mcp-ai-wpoos-pro' );
			if ( ! empty( $data['error_description'] ) ) {
				$message = sprintf( '%s %s', $message, $data['error_description'] );
			} elseif ( ! empty( $data['error'] ) ) {
				$message = sprintf( '%s %s', $message, $data['error'] );
			}

			return new WP_Error(
				'wp_mcp_ai_gc_sa_token_rejected',
				$message,
				array(
					'status'   => $status,
					'response' => $data,
				)
			);
		}

		$token_value = (string) $data['access_token'];
		$expires_in  = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		$cache_ttl   = max( 60, $expires_in - 60 );

		set_transient( $cache_key, $token_value, $cache_ttl );

		return $token_value;
	}

	/**
	 * Parse a Service Account JSON key and retrieve an access token in one step.
	 *
	 * @param string $json_key Raw JSON string from the service account key file.
	 * @param string $scope    OAuth 2.0 scope string.
	 * @param int    $timeout  HTTP request timeout in seconds.
	 * @return string|WP_Error Access token string or error.
	 */
	public static function get_access_token_from_key( $json_key, $scope, $timeout = 15 ) {
		$credentials = self::parse_key( $json_key );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		return self::get_access_token( $credentials, $scope, $timeout );
	}

	/**
	 * Retrieve an access token using an OAuth 2.0 refresh token.
	 *
	 * Useful when the connection was authorized via the 1-click OAuth flow
	 * rather than a Service Account key file.
	 *
	 * @param string $client_id     OAuth 2.0 client ID.
	 * @param string $client_secret OAuth 2.0 client secret.
	 * @param string $refresh_token OAuth 2.0 refresh token.
	 * @param int    $timeout       HTTP request timeout in seconds.
	 * @return string|WP_Error Access token string or error.
	 */
	public static function get_access_token_from_refresh_token( $client_id, $client_secret, $refresh_token, $timeout = 15 ) {
		$client_id     = trim( (string) $client_id );
		$client_secret = trim( (string) $client_secret );
		$refresh_token = trim( (string) $refresh_token );

		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			return new WP_Error(
				'wp_mcp_ai_gc_oauth_incomplete_credentials',
				__( 'Incomplete OAuth credentials: client_id, client_secret, and refresh_token are all required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$response = wp_remote_post(
			self::TOKEN_URI,
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'timeout' => absint( $timeout ) > 0 ? absint( $timeout ) : 15,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_gc_oauth_token_request_failed',
				__( 'Unable to obtain a Google access token via OAuth refresh token.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 || empty( $data['access_token'] ) ) {
			$message = __( 'Google rejected the OAuth token refresh request.', 'mcp-ai-wpoos-pro' );
			if ( ! empty( $data['error_description'] ) ) {
				$message = sprintf( '%s %s', $message, $data['error_description'] );
			} elseif ( ! empty( $data['error'] ) ) {
				$message = sprintf( '%s %s', $message, $data['error'] );
			}

			return new WP_Error(
				'wp_mcp_ai_gc_oauth_token_rejected',
				$message,
				array(
					'status'   => $status,
					'response' => $data,
				)
			);
		}

		return (string) $data['access_token'];
	}

	/**
	 * Build a signed JWT assertion using RS256.
	 *
	 * @param array  $claims      JWT payload claims.
	 * @param string $private_key PEM-encoded RSA private key.
	 * @return string|WP_Error Signed JWT string or error.
	 */
	protected static function build_jwt_assertion( array $claims, $private_key ) {
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$segments = array(
			self::base64url_encode( (string) wp_json_encode( $header ) ),
			self::base64url_encode( (string) wp_json_encode( $claims ) ),
		);

		$input     = implode( '.', $segments );
		$signature = '';

		$success = openssl_sign( $input, $signature, $private_key, 'sha256' );
		if ( ! $success ) {
			return new WP_Error(
				'wp_mcp_ai_gc_sa_signing_failed',
				__( 'Unable to sign the Google Service Account JWT assertion. Verify that the private_key in your Service Account JSON key is valid.', 'mcp-ai-wpoos-pro' )
			);
		}

		$segments[] = self::base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Base64 URL-safe encode a string.
	 *
	 * @param string $value Raw value to encode.
	 * @return string URL-safe base64-encoded string without padding.
	 */
	protected static function base64url_encode( $value ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$encoded = base64_encode( $value );
		return str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), $encoded );
	}
}
