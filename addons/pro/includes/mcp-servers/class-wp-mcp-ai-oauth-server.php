<?php
/**
 * OAuth 2.0 Authorization Server for MCP
 *
 * Implements the MCP Authorization Specification 2025-06-18. Provides:
 *  - OAuth 2.1-compliant authorization code flow with PKCE (RFC 7636).
 *  - Resource Indicators (RFC 8707).
 *  - Dynamic Client Registration (RFC 7591).
 *  - OAuth 2.0 Authorization Server Metadata (RFC 8414).
 *  - OAuth 2.0 Protected Resource Metadata (RFC 9728).
 *  - Hierarchical scope system with least-privilege defaults.
 *
 * This enables MCP clients (Codex, Claude Desktop, Zed) to authenticate
 * users via their WordPress credentials without manually copying bearer
 * tokens. The user clicks "Connect" in the client, logs into WordPress
 * in their browser, and the client automatically receives tokens.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages OAuth 2.0 tokens for MCP authentication.
 */
class WP_MCP_AI_OAuth_Server {

	/**
	 * Storage option key for issued tokens.
	 *
	 * @var string
	 */
	const TOKENS_OPTION = 'wp_mcp_ai_oauth_tokens';

	/**
	 * Storage option key for authorization codes.
	 *
	 * @var string
	 */
	const CODES_OPTION = 'wp_mcp_ai_oauth_codes';

	/**
	 * Storage option key for registered clients.
	 *
	 * @var string
	 */
	const CLIENTS_OPTION = 'wp_mcp_ai_oauth_clients';

	/**
	 * Token type identifier.
	 *
	 * @var string
	 */
	const TOKEN_PREFIX = 'mcp_at_';

	/**
	 * Access token lifetime (1 hour).
	 *
	 * @var int
	 */
	const ACCESS_TOKEN_TTL = 3600;

	/**
	 * Refresh token lifetime (30 days).
	 *
	 * @var int
	 */
	const REFRESH_TOKEN_TTL = 2592000;

	/**
	 * Authorization code lifetime (10 minutes).
	 *
	 * @var int
	 */
	const AUTH_CODE_TTL = 600;

	/**
	 * Well-known scope for MCP access.
	 *
	 * @var string
	 */
	const SCOPE_MCP = 'mcp';

	/**
	 * Read-only scope.
	 *
	 * @var string
	 */
	const SCOPE_MCP_READ = 'mcp:read';

	/**
	 * Read-write scope.
	 *
	 * @var string
	 */
	const SCOPE_MCP_WRITE = 'mcp:write';

	// -----------------------------------------------------------------------
	// Scope System
	// -----------------------------------------------------------------------

	/**
	 * Define all supported scopes and their relationships.
	 *
	 * Hierarchical: broader scopes imply narrower ones.
	 * e.g. `mcp` implies `mcp:read` and `mcp:write`.
	 *
	 * @return array<string, array{description: string, implies: string[]}>
	 */
	public static function get_supported_scopes() {
		return array(
			self::SCOPE_MCP       => array(
				'description' => __( 'Full MCP access (read + write).', 'mcp-ai-wpoos-pro' ),
				'implies'     => array( self::SCOPE_MCP_READ, self::SCOPE_MCP_WRITE ),
			),
			self::SCOPE_MCP_READ  => array(
				'description' => __( 'Read MCP tools, resources, and prompts.', 'mcp-ai-wpoos-pro' ),
				'implies'     => array(),
			),
			self::SCOPE_MCP_WRITE => array(
				'description' => __( 'Execute MCP tools and modify resources.', 'mcp-ai-wpoos-pro' ),
				'implies'     => array(),
			),
		);
	}

	/**
	 * Normalize a space-separated scope string, expanding hierarchical scopes.
	 *
	 * Given "mcp", returns "mcp mcp:read mcp:write".
	 *
	 * @param string|null $scope Space-separated scope string.
	 * @return string Normalized scope string.
	 */
	public static function normalize_scopes( $scope ) {
		if ( empty( $scope ) ) {
			return '';
		}

		$requested = array_filter( array_map( 'trim', explode( ' ', (string) $scope ) ) );
		$all       = self::get_supported_scopes();
		$expanded  = array();

		foreach ( $requested as $s ) {
			$expanded[] = $s;
			if ( isset( $all[ $s ]['implies'] ) ) {
				foreach ( $all[ $s ]['implies'] as $implied ) {
					$expanded[] = $implied;
				}
			}
		}

		return implode( ' ', array_unique( $expanded ) );
	}

	/**
	 * Check if a token's scope satisfies a required scope.
	 *
	 * @param string $granted  Space-separated granted scopes.
	 * @param string $required Required scope.
	 * @return bool
	 */
	public static function scope_satisfies( $granted, $required ) {
		if ( empty( $required ) ) {
			return true;
		}
		if ( empty( $granted ) ) {
			return false;
		}

		$granted_set = array_filter( array_map( 'trim', explode( ' ', $granted ) ) );
		$all_scopes  = self::get_supported_scopes();

		// Expand granted scopes hierarchically.
		$effective = $granted_set;
		foreach ( $granted_set as $g ) {
			if ( isset( $all_scopes[ $g ]['implies'] ) ) {
				foreach ( $all_scopes[ $g ]['implies'] as $implied ) {
					$effective[] = $implied;
				}
			}
		}
		$effective = array_unique( $effective );

		return in_array( $required, $effective, true );
	}

	// -----------------------------------------------------------------------
	// Token Management
	// -----------------------------------------------------------------------

	/**
	 * Issue an access token and refresh token.
	 *
	 * @param int         $user_id  WordPress user ID.
	 * @param string      $audience Resource indicator (MCP server URL).
	 * @param string|null $scope    Requested OAuth scope string.
	 * @return array{access_token:string, token_type:string, expires_in:int, refresh_token:string, scope:string}
	 */
	public function issue_tokens( $user_id, $audience, $scope = null ) {
		$user_id = absint( $user_id );
		$now     = time();
		$tokens  = $this->load_tokens();
		$scope   = self::normalize_scopes( $scope );

		// Default to full MCP scope if none requested.
		if ( empty( $scope ) ) {
			$scope = self::SCOPE_MCP;
		}

		$access_token  = self::TOKEN_PREFIX . bin2hex( random_bytes( 32 ) );
		$refresh_token = self::TOKEN_PREFIX . 'rt_' . bin2hex( random_bytes( 32 ) );

		$tokens = $this->purge_expired( $tokens, $now );

		$tokens[ $access_token ] = array(
			'user_id'         => $user_id,
			'audience'        => $audience,
			'scope'           => $scope,
			'issued_at'       => $now,
			'expires_at'      => $now + self::ACCESS_TOKEN_TTL,
			'refresh_token'   => $refresh_token,
			'refresh_expires' => $now + self::REFRESH_TOKEN_TTL,
		);

		$tokens[ $refresh_token ] = array(
			'user_id'          => $user_id,
			'audience'         => $audience,
			'scope'            => $scope,
			'issued_at'        => $now,
			'expires_at'       => $now + self::REFRESH_TOKEN_TTL,
			'is_refresh'       => true,
			'access_token_key' => $access_token,
		);

		$this->save_tokens( $tokens );

		return array(
			'access_token'  => $access_token,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TOKEN_TTL,
			'refresh_token' => $refresh_token,
			'scope'         => $scope,
		);
	}

	/**
	 * Refresh an access token using a valid refresh token.
	 *
	 * @param string $refresh_token Raw refresh token string.
	 * @return array|WP_Error Token response on success, WP_Error on failure.
	 */
	public function refresh_access_token( $refresh_token ) {
		$tokens = $this->load_tokens();
		$now    = time();

		if ( ! isset( $tokens[ $refresh_token ] ) ) {
			return new WP_Error(
				'invalid_grant',
				__( 'Invalid refresh token.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$entry = $tokens[ $refresh_token ];

		if ( empty( $entry['is_refresh'] ) ) {
			return new WP_Error(
				'invalid_grant',
				__( 'Token is not a refresh token.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( $entry['expires_at'] <= $now ) {
			unset( $tokens[ $refresh_token ] );
			$this->save_tokens( $tokens );
			return new WP_Error(
				'invalid_grant',
				__( 'Refresh token has expired.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Revoke old access token.
		$old_at = isset( $entry['access_token_key'] ) ? $entry['access_token_key'] : '';
		if ( '' !== $old_at && isset( $tokens[ $old_at ] ) ) {
			unset( $tokens[ $old_at ] );
		}

		// Issue new tokens preserving scope and audience.
		$new = $this->issue_tokens(
			$entry['user_id'],
			$entry['audience'],
			isset( $entry['scope'] ) ? $entry['scope'] : null
		);

		// Revoke old refresh token (rotation).
		unset( $tokens[ $refresh_token ] );
		$this->save_tokens( $tokens );

		return $new;
	}

	/**
	 * Validate an access token, checking it was issued for the expected audience.
	 *
	 * Per MCP spec: "MCP servers MUST validate that access tokens were issued
	 * specifically for them as the intended audience" (RFC 8707 §2).
	 *
	 * @param string $token    Raw token string.
	 * @param string $audience Expected audience (MCP server URL). Empty to skip audience check.
	 * @return array{user_id:int, audience:string, scope:string}|null Token data or null if invalid.
	 */
	public function validate_token( $token, $audience = '' ) {
		if ( '' === $token || 0 !== strpos( $token, self::TOKEN_PREFIX ) ) {
			return null;
		}

		$tokens = $this->load_tokens();
		$now    = time();

		if ( ! isset( $tokens[ $token ] ) ) {
			return null;
		}

		$entry = $tokens[ $token ];

		// Refuse refresh tokens.
		if ( ! empty( $entry['is_refresh'] ) ) {
			return null;
		}

		// Expiry check.
		if ( $entry['expires_at'] <= $now ) {
			unset( $tokens[ $token ] );
			$this->save_tokens( $tokens );
			return null;
		}

		// Audience validation (RFC 8707).
		$token_audience = isset( $entry['audience'] ) ? (string) $entry['audience'] : '';
		if ( '' !== $audience ) {
			// Normalize: lowercase scheme + host, strip trailing slash.
			$normalized_token_aud = self::normalize_uri( $token_audience );
			$normalized_expected  = self::normalize_uri( $audience );

			if ( $normalized_token_aud !== $normalized_expected ) {
				return null;
			}
		}

		return array(
			'user_id'  => (int) $entry['user_id'],
			'audience' => $token_audience,
			'scope'    => isset( $entry['scope'] ) ? (string) $entry['scope'] : self::SCOPE_MCP,
		);
	}

	/**
	 * Check if a token has sufficient scope for an operation.
	 *
	 * Use this after validate_token() to enforce scope-based access control.
	 * Returns null if scope is sufficient, or the required scope string if
	 * a 403 insufficient_scope response should be sent.
	 *
	 * @param string $granted    Space-separated granted scopes from the token.
	 * @param string $required   Required scope for the operation (e.g. 'mcp:read', 'mcp:write').
	 * @return string|null Required scope string if insufficient, null if sufficient.
	 */
	public static function check_scope( $granted, $required ) {
		if ( self::scope_satisfies( $granted, $required ) ) {
			return null;
		}
		return $required;
	}

	/**
	 * Revoke a token.
	 *
	 * @param string $token Raw token string.
	 * @return bool True if revoked.
	 */
	public function revoke_token( $token ) {
		$tokens = $this->load_tokens();
		if ( ! isset( $tokens[ $token ] ) ) {
			return false;
		}
		unset( $tokens[ $token ] );
		$this->save_tokens( $tokens );
		return true;
	}

	// -----------------------------------------------------------------------
	// Authorization Codes
	// -----------------------------------------------------------------------

	/**
	 * Generate a short-lived authorization code.
	 *
	 * @param int         $user_id        WordPress user ID.
	 * @param string      $client_id      OAuth client ID.
	 * @param string      $redirect_uri   Client's redirect URI.
	 * @param string      $code_challenge PKCE S256 code challenge.
	 * @param string|null $audience       Resource indicator (MCP server URL).
	 * @param string|null $scope          Requested scope string.
	 * @return string Authorization code.
	 */
	public function generate_auth_code( $user_id, $client_id, $redirect_uri, $code_challenge, $audience = null, $scope = null ) {
		$codes = $this->load_codes();
		$now   = time();

		$codes = $this->purge_expired( $codes, $now, self::AUTH_CODE_TTL );

		$code           = bin2hex( random_bytes( 32 ) );
		$codes[ $code ] = array(
			'user_id'        => absint( $user_id ),
			'client_id'      => sanitize_key( $client_id ),
			'redirect_uri'   => esc_url_raw( $redirect_uri ),
			'code_challenge' => $code_challenge,
			'audience'       => $audience,
			'scope'          => $scope,
			'created_at'     => $now,
			'expires_at'     => $now + self::AUTH_CODE_TTL,
		);

		$this->save_codes( $codes );

		return $code;
	}

	/**
	 * Validate and consume an authorization code.
	 *
	 * @param string $code          Authorization code.
	 * @param string $code_verifier PKCE code verifier.
	 * @return array|WP_Error Token entry on success, WP_Error on failure.
	 */
	public function exchange_code( $code, $code_verifier ) {
		$codes = $this->load_codes();
		$now   = time();

		if ( ! isset( $codes[ $code ] ) ) {
			return new WP_Error(
				'invalid_grant',
				__( 'Invalid or expired authorization code.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$entry = $codes[ $code ];

		if ( $entry['expires_at'] <= $now ) {
			unset( $codes[ $code ] );
			$this->save_codes( $codes );
			return new WP_Error(
				'invalid_grant',
				__( 'Authorization code has expired.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Validate PKCE.
		$expected_challenge = $entry['code_challenge'];
		$computed_challenge = self::compute_s256_challenge( $code_verifier );

		if ( ! hash_equals( $expected_challenge, $computed_challenge ) ) {
			unset( $codes[ $code ] );
			$this->save_codes( $codes );
			return new WP_Error(
				'invalid_grant',
				__( 'Invalid code_verifier.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Consume immediately to prevent replay.
		unset( $codes[ $code ] );
		$this->save_codes( $codes );

		return $entry;
	}

	// -----------------------------------------------------------------------
	// Client Registration (RFC 7591)
	// -----------------------------------------------------------------------

	/**
	 * Register a new OAuth client.
	 *
	 * @param array $metadata Client metadata.
	 * @return array{client_id:string, client_name:string, redirect_uris:string[], grant_types:string[], token_endpoint_auth_method:string}
	 */
	public function register_client( $metadata ) {
		$clients = $this->load_clients();

		$client_id = 'mcp_client_' . bin2hex( random_bytes( 16 ) );

		$clients[ $client_id ] = array(
			'client_name'   => isset( $metadata['client_name'] ) ? sanitize_text_field( $metadata['client_name'] ) : 'MCP Client',
			'redirect_uris' => isset( $metadata['redirect_uris'] ) && is_array( $metadata['redirect_uris'] )
				? array_map( 'esc_url_raw', $metadata['redirect_uris'] )
				: array(),
			'created_at'    => time(),
		);

		$this->save_clients( $clients );

		return array(
			'client_id'                  => $client_id,
			'client_name'                => $clients[ $client_id ]['client_name'],
			'redirect_uris'              => $clients[ $client_id ]['redirect_uris'],
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * Check if a client ID is registered.
	 *
	 * @param string $client_id Client identifier.
	 * @return bool
	 */
	public function is_client_registered( $client_id ) {
		$clients = $this->load_clients();
		return isset( $clients[ $client_id ] );
	}

	/**
	 * Get client redirect URIs.
	 *
	 * @param string $client_id Client identifier.
	 * @return string[]
	 */
	public function get_client_redirect_uris( $client_id ) {
		$clients = $this->load_clients();
		if ( ! isset( $clients[ $client_id ] ) ) {
			return array();
		}
		return isset( $clients[ $client_id ]['redirect_uris'] )
			? $clients[ $client_id ]['redirect_uris']
			: array();
	}

	// -----------------------------------------------------------------------
	// OAuth Metadata
	// -----------------------------------------------------------------------

	/**
	 * Build OAuth 2.0 Authorization Server Metadata (RFC 8414).
	 *
	 * @return array<string,mixed>
	 */
	public function get_metadata() {
		$scopes = self::get_supported_scopes();
		return array(
			'issuer'                                => home_url(),
			'authorization_endpoint'                => rest_url( 'mcp-ai/v1/oauth/authorize' ),
			'token_endpoint'                        => rest_url( 'mcp-ai/v1/oauth/token' ),
			'revocation_endpoint'                   => rest_url( 'mcp-ai/v1/oauth/revoke' ),
			'registration_endpoint'                 => rest_url( 'mcp-ai/v1/oauth/register' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'token_endpoint_auth_signing_alg_values_supported' => array(),
			'scopes_supported'                      => array_keys( $scopes ),
			'authorization_response_iss_parameter_supported' => true,
			'service_documentation'                 => home_url( '/wp-json/mcp-ai/v1/mcp' ),
		);
	}

	/**
	 * Build MCP Protected Resource Metadata (RFC 9728).
	 *
	 * @param string|null $resource_url Optional override for the resource URL.
	 * @param string|null $resource_name Optional resource display name.
	 * @return array<string,mixed>
	 */
	public function get_protected_resource_metadata( $resource_url = null, $resource_name = null ) {
			$url  = null !== $resource_url ? $resource_url : rest_url( 'mcp-ai/v1/mcp' );
			$name = null !== $resource_name ? $resource_name : 'NV oOS MCP Server';
		$scopes   = self::get_supported_scopes();

		return array(
			'resource'                 => $url,
			'authorization_servers'    => array( home_url() ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => $name,
			'resource_documentation'   => $url,
			'scopes_supported'         => array_keys( $scopes ),
		);
	}

	/**
	 * Build per-toolkit MCP OAuth metadata.
	 *
	 * @param string $server_slug Toolkit server slug.
	 * @return array<string,mixed>
	 */
	public function get_toolkit_metadata( $server_slug ) {
		$server_slug = sanitize_key( $server_slug );
		return $this->get_protected_resource_metadata(
			rest_url( 'mcp-ai-pro/v1/mcp/' . $server_slug ),
			'NV oOS Toolkit MCP Server: ' . $server_slug
		);
	}

	// -----------------------------------------------------------------------
	// WWW-Authenticate Helper
	// -----------------------------------------------------------------------

	/**
	 * Build the WWW-Authenticate header value for a 401 response.
	 *
	 * Per MCP spec: servers MUST include WWW-Authenticate with
	 * resource_metadata URL and optionally scope.
	 *
	 * @param string      $resource_url MCP server URL.
	 * @param string|null $scope        Optional required scope.
	 * @return string
	 */
	public static function build_www_authenticate( $resource_url, $scope = null ) {
		$meta_url = rest_url( 'mcp-ai/v1/.well-known/oauth-protected-resource' );
		$parts    = array(
			'Bearer',
			sprintf( 'resource_metadata="%s"', $meta_url ),
		);

		if ( null !== $scope && '' !== $scope ) {
			$parts[] = sprintf( 'scope="%s"', $scope );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Build the WWW-Authenticate header for a 403 insufficient_scope response.
	 *
	 * @param string $required_scope The scope required for the operation.
	 * @param string $resource_url   MCP server URL (unused, kept for API consistency).
	 * @return string
	 */
	public static function build_insufficient_scope_www_authenticate( $required_scope, $resource_url ) {
			$meta_url = '' !== $resource_url ? $resource_url : rest_url( 'mcp-ai/v1/.well-known/oauth-protected-resource' );
		return sprintf(
			'Bearer error="insufficient_scope", scope="%s", resource_metadata="%s"',
			$required_scope,
			$meta_url
		);
	}

	// -----------------------------------------------------------------------
	// Singleton
	// -----------------------------------------------------------------------

	/**
	 * Singleton instance holder.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -----------------------------------------------------------------------
	// Utilities
	// -----------------------------------------------------------------------

	/**
	 * Compute PKCE S256 challenge from verifier.
	 *
	 * @param string $verifier Raw code verifier (43-128 chars).
	 * @return string Base64URL-encoded SHA-256 hash.
	 */
	public static function compute_s256_challenge( $verifier ) {
		return self::base64url_encode( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Base64URL-encode raw bytes.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function base64url_encode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by RFC 4648 §5 for PKCE S256 challenge computation.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Normalize a URI for audience comparison.
	 *
	 * Lowercase scheme + host, strip trailing slash.
	 *
	 * @param string $uri URI to normalize.
	 * @return string
	 */
	private static function normalize_uri( $uri ) {
		$parts = wp_parse_url( $uri );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return strtolower( rtrim( $uri, '/' ) );
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$host   = strtolower( $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';

		return $scheme . '://' . $host . $port . $path;
	}

	// -----------------------------------------------------------------------
	// Storage Helpers
	// -----------------------------------------------------------------------

	/**
	 * Load tokens from the WordPress options table.
	 *
	 * @return array<string, array>
	 */
	private function load_tokens() {
		$tokens = get_option( self::TOKENS_OPTION, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Persist tokens to the WordPress options table.
	 *
	 * @param array $tokens Token store.
	 * @return void
	 */
	private function save_tokens( $tokens ) {
		update_option( self::TOKENS_OPTION, $tokens, false );
	}

	/**
	 * Load authorization codes from the WordPress options table.
	 *
	 * @return array<string, array>
	 */
	private function load_codes() {
		$codes = get_option( self::CODES_OPTION, array() );
		return is_array( $codes ) ? $codes : array();
	}

	/**
	 * Persist authorization codes to the WordPress options table.
	 *
	 * @param array $codes Code store.
	 * @return void
	 */
	private function save_codes( $codes ) {
		update_option( self::CODES_OPTION, $codes, false );
	}

	/**
	 * Load registered OAuth clients from the WordPress options table.
	 *
	 * @return array<string, array>
	 */
	private function load_clients() {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $clients : array();
	}

	/**
	 * Persist registered OAuth clients to the WordPress options table.
	 *
	 * @param array $clients Client store.
	 * @return void
	 */
	private function save_clients( $clients ) {
		update_option( self::CLIENTS_OPTION, $clients, false );
	}

	/**
	 * Remove expired entries from a token/code store.
	 *
	 * @param array $store Entries keyed by token/code.
	 * @param int   $now   Current Unix timestamp.
	 * @param int   $ttl   Optional TTL override for non-token entries.
	 * @return array Filtered store.
	 */
	private function purge_expired( $store, $now, $ttl = 0 ) {
		foreach ( $store as $key => $entry ) {
			$expires = isset( $entry['expires_at'] ) ? (int) $entry['expires_at'] : 0;
			if ( 0 === $expires && $ttl > 0 && isset( $entry['created_at'] ) ) {
				$expires = (int) $entry['created_at'] + $ttl;
			}
			if ( $expires > 0 && $expires <= $now ) {
				unset( $store[ $key ] );
			}
		}
		return $store;
	}
}
