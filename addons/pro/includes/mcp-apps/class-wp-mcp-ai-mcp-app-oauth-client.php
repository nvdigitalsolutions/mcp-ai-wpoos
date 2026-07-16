<?php
/**
 * MCP App OAuth Client.
 *
 * OAuth 2.0 client implementation for MCP Apps. Handles the client-side
 * of the OAuth 2.0 Authorization Code flow with PKCE (RFC 7636) when
 * connecting to remote MCP servers that require browser-based authentication.
 *
 * This enables the "MCP Web Login" flow: instead of manually copying bearer
 * tokens, the admin user is redirected to the remote MCP server's
 * authorization endpoint, logs in via their browser, and the tokens are
 * automatically exchanged and stored.
 *
 * Per the MCP Authorization Specification 2025-06-18:
 * - Metadata discovery via /.well-known/oauth-authorization-server (RFC 8414)
 * - Dynamic Client Registration (RFC 7591)
 * - Authorization Code flow with PKCE S256
 * - Resource Indicators (RFC 8707)
 * - Token refresh with rotation
 *
 * @package WP_MCP_AI_Pro
 * @since   1.9.0
 * @see     https://modelcontextprotocol.io/specification/2025-03-26
 * @see     https://modelcontextprotocol.io/extensions/apps/overview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth 2.0 client for MCP App connections.
 *
 * Discovers OAuth metadata from remote MCP servers, registers as a
 * dynamic client, and manages the authorization code flow with PKCE.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_MCP_App_OAuth_Client {

	/**
	 * Remote MCP server URL.
	 *
	 * @var string
	 */
	protected $server_url;

	/**
	 * OAuth authorization server metadata (RFC 8414).
	 *
	 * @var array|null
	 */
	protected $metadata = null;

	/**
	 * Registered client ID from the remote authorization server.
	 *
	 * @var string
	 */
	protected $client_id = '';

	/**
	 * Redirect URI for the OAuth callback.
	 *
	 * @var string
	 */
	protected $redirect_uri = '';

	/**
	 * PKCE code verifier (43-128 characters).
	 *
	 * @var string
	 */
	protected $code_verifier = '';

	/**
	 * PKCE S256 code challenge.
	 *
	 * @var string
	 */
	protected $code_challenge = '';

	/**
	 * OAuth state parameter for CSRF protection.
	 *
	 * @var string
	 */
	protected $state = '';

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * Whether to verify SSL.
	 *
	 * @var bool
	 */
	protected $verify_ssl;

	/**
	 * Stored token data.
	 *
	 * @var array
	 */
	protected $token_data = array();

	/**
	 * Constructor.
	 *
	 * @since 1.9.0
	 * @param string $server_url Remote MCP server endpoint URL.
	 * @param array  $options {
	 *     Optional. OAuth client options.
	 *
	 *     @type int  $timeout    HTTP request timeout in seconds. Default 30.
	 *     @type bool $verify_ssl Whether to verify SSL. Default true.
	 * }
	 */
	public function __construct( $server_url, array $options = array() ) {
		$this->server_url   = esc_url_raw( $server_url );
		$this->timeout      = isset( $options['timeout'] ) ? max( 1, min( 120, absint( $options['timeout'] ) ) ) : 30;
		$this->verify_ssl   = isset( $options['verify_ssl'] ) ? (bool) $options['verify_ssl'] : true;
		$this->redirect_uri = rest_url( 'mcp-ai/v1/mcp-apps/oauth/callback' );
	}

	/**
	 * Discover OAuth 2.0 Authorization Server metadata from the remote server.
	 *
	 * First tries the standard /.well-known/oauth-authorization-server endpoint.
	 * Falls back to checking the WWW-Authenticate header on a 401 response from
	 * the MCP endpoint.
	 *
	 * @since 1.9.0
	 * @return array|WP_Error OAuth metadata on success, WP_Error on failure.
	 */
	public function discover_metadata() {
		if ( null !== $this->metadata ) {
			return $this->metadata;
		}

		// Try the well-known discovery endpoint first.
		$well_known_url = $this->build_well_known_url();

		$response = wp_remote_get(
			$well_known_url,
			array(
				'timeout'   => $this->timeout,
				'sslverify' => $this->verify_ssl,
				'headers'   => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( is_array( $data ) && ! empty( $data['authorization_endpoint'] ) ) {
				$this->metadata = $data;
				return $this->metadata;
			}
		}

		// Fallback: try the REST endpoint directly.
		// WordPress sites may expose metadata at /wp-json/mcp-ai/v1/oauth/metadata
		// without the .well-known rewrite rule being active.
		$rest_metadata_url = $this->build_rest_metadata_url();
		if ( '' !== $rest_metadata_url && $rest_metadata_url !== $well_known_url ) {
			$rest_response = wp_remote_get(
				$rest_metadata_url,
				array(
					'timeout'   => $this->timeout,
					'sslverify' => $this->verify_ssl,
					'headers'   => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( ! is_wp_error( $rest_response ) && 200 === wp_remote_retrieve_response_code( $rest_response ) ) {
				$body = wp_remote_retrieve_body( $rest_response );
				$data = json_decode( $body, true );

				if ( is_array( $data ) && ! empty( $data['authorization_endpoint'] ) ) {
					$this->metadata = $data;
					return $this->metadata;
				}
			}
		}

		// Fallback: probe the MCP endpoint and check WWW-Authenticate header.
		$mcp_response = wp_remote_post(
			$this->server_url,
			array(
				'timeout'   => $this->timeout,
				'sslverify' => $this->verify_ssl,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 0,
						'method'  => 'tools/list',
						'params'  => new stdClass(),
					)
				),
			)
		);

		if ( ! is_wp_error( $mcp_response ) ) {
			$status_code = wp_remote_retrieve_response_code( $mcp_response );

			if ( 401 === $status_code || 403 === $status_code ) {
				$www_auth = wp_remote_retrieve_header( $mcp_response, 'www-authenticate' );

				// Fallback: if no WWW-Authenticate header, check JSON error body.
				// Some servers embed OAuth metadata in the error response JSON.
				if ( empty( $www_auth ) ) {
					$resp_body = wp_remote_retrieve_body( $mcp_response );
					$json_data = json_decode( $resp_body, true );
					if ( is_array( $json_data ) && ! empty( $json_data['data']['www_authenticate'] ) ) {
						$www_auth = $json_data['data']['www_authenticate'];
					}
				}

				if ( ! empty( $www_auth ) ) {
					$parsed = $this->parse_www_authenticate( $www_auth );

					if ( ! empty( $parsed['resource_metadata'] ) ) {
						// Fetch protected resource metadata (RFC 9728).
						$meta_response = wp_remote_get(
							$parsed['resource_metadata'],
							array(
								'timeout'   => $this->timeout,
								'sslverify' => $this->verify_ssl,
								'headers'   => array( 'Accept' => 'application/json' ),
							)
						);

						if ( ! is_wp_error( $meta_response ) && 200 === wp_remote_retrieve_response_code( $meta_response ) ) {
							$meta_body = wp_remote_retrieve_body( $meta_response );
							$meta_data = json_decode( $meta_body, true );

							if ( is_array( $meta_data ) && ! empty( $meta_data['authorization_servers'] ) ) {
								$auth_server = is_array( $meta_data['authorization_servers'] )
									? reset( $meta_data['authorization_servers'] )
									: $meta_data['authorization_servers'];

								// Fetch authorization server metadata.
								// Try well-known URL first, then REST API fallback.
								$metadata_fetched = false;
								$auth_meta_urls   = array(
									rtrim( $auth_server, '/' ) . '/.well-known/oauth-authorization-server',
									rtrim( $auth_server, '/' ) . '/wp-json/mcp-ai/v1/oauth/metadata',
								);

								foreach ( $auth_meta_urls as $auth_meta_url ) {
									$auth_response = wp_remote_get(
										$auth_meta_url,
										array(
											'timeout'   => $this->timeout,
											'sslverify' => $this->verify_ssl,
											'headers'   => array( 'Accept' => 'application/json' ),
										)
									);

									if ( ! is_wp_error( $auth_response ) && 200 === wp_remote_retrieve_response_code( $auth_response ) ) {
										$auth_body = wp_remote_retrieve_body( $auth_response );
										$auth_data = json_decode( $auth_body, true );

										if ( is_array( $auth_data ) && ! empty( $auth_data['authorization_endpoint'] ) ) {
											$this->metadata   = $auth_data;
											$metadata_fetched = true;
											break;
										}
									}
								}

								if ( $metadata_fetched ) {
									return $this->metadata;
								}
							}
						}
					}
				}
			}
		}

		return new WP_Error(
			'wp_mcp_ai_mcp_app_oauth_no_metadata',
			__( 'Could not discover OAuth metadata from the remote MCP server. The server may not support OAuth 2.0 authentication.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Check whether the remote server supports OAuth authentication.
	 *
	 * @since 1.9.0
	 * @return bool True if OAuth metadata was successfully discovered.
	 */
	public function supports_oauth() {
		$metadata = $this->discover_metadata();
		return ! is_wp_error( $metadata );
	}

	/**
	 * Register this WordPress site as a dynamic OAuth client with the remote server.
	 *
	 * Per RFC 7591, sends a registration request to the remote authorization
	 * server's registration endpoint with our redirect URI.
	 *
	 * @since 1.9.0
	 * @return array|WP_Error Client registration response on success.
	 */
	public function register_client() {
		$metadata = $this->discover_metadata();
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$registration_endpoint = isset( $metadata['registration_endpoint'] )
			? $metadata['registration_endpoint']
			: '';

		if ( empty( $registration_endpoint ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_no_registration',
				__( 'The remote MCP server does not support dynamic client registration.', 'mcp-ai-wpoos-pro' )
			);
		}

		$body = array(
			'client_name'                => get_bloginfo( 'name' ) . ' — NV oOS MCP App',
			'redirect_uris'              => array( $this->redirect_uri ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_method' => 'none',
			'application_type'           => 'web',
		);

		$response = wp_remote_post(
			$registration_endpoint,
			array(
				'timeout'   => $this->timeout,
				'sslverify' => $this->verify_ssl,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_registration_failed',
				sprintf(
					/* translators: %s: Error message. */
					__( 'Failed to register OAuth client: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$resp_body   = wp_remote_retrieve_body( $response );
		$data        = json_decode( $resp_body, true );

		if ( 201 !== $status_code && 200 !== $status_code ) {
			$error_desc = isset( $data['error_description'] ) ? $data['error_description'] : __( 'Unknown registration error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_registration_error',
				$error_desc,
				array( 'status' => $status_code )
			);
		}

		if ( ! is_array( $data ) || empty( $data['client_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_registration_invalid',
				__( 'Invalid registration response from remote server.', 'mcp-ai-wpoos-pro' )
			);
		}

		$this->client_id = sanitize_key( $data['client_id'] );

		return $data;
	}

	/**
	 * Generate PKCE code verifier and S256 challenge.
	 *
	 * Per RFC 7636, generates a cryptographically random verifier
	 * and its Base64URL-encoded SHA-256 hash.
	 *
	 * @since 1.9.0
	 * @return array{verifier: string, challenge: string}
	 */
	public function generate_pkce() {
		// Generate 32 random bytes → 43 character Base64URL verifier.
		$this->code_verifier  = $this->base64url_encode( random_bytes( 32 ) );
		$this->code_challenge = self::compute_s256_challenge( $this->code_verifier );

		return array(
			'verifier'  => $this->code_verifier,
			'challenge' => $this->code_challenge,
		);
	}

	/**
	 * Generate a CSRF state parameter.
	 *
	 * @since 1.9.0
	 * @return string Random state value.
	 */
	public function generate_state() {
		$this->state = bin2hex( random_bytes( 16 ) );
		return $this->state;
	}

	/**
	 * Build the authorization URL for browser-based login.
	 *
	 * Constructs the full authorization endpoint URL with all required
	 * parameters for the OAuth 2.0 authorization code flow with PKCE.
	 *
	 * @since 1.9.0
	 * @param string|null $scope Optional OAuth scope to request.
	 * @return string|WP_Error Authorization URL on success, WP_Error on failure.
	 */
	public function get_authorization_url( $scope = null ) {
		$metadata = $this->discover_metadata();
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$auth_endpoint = $metadata['authorization_endpoint'];

		// Generate PKCE if not already done.
		if ( empty( $this->code_challenge ) ) {
			$this->generate_pkce();
		}

		// Generate state if not already done.
		if ( empty( $this->state ) ) {
			$this->generate_state();
		}

		// Ensure we have a client ID (register if needed).
		if ( empty( $this->client_id ) ) {
			$reg_result = $this->register_client();
			if ( is_wp_error( $reg_result ) ) {
				return $reg_result;
			}
		}

		$params = array(
			'response_type'         => 'code',
			'client_id'             => $this->client_id,
			'redirect_uri'          => $this->redirect_uri,
			'code_challenge'        => $this->code_challenge,
			'code_challenge_method' => 'S256',
			'state'                 => $this->state,
		);

		if ( null !== $scope && '' !== $scope ) {
			$params['scope'] = $scope;
		}

		// Include resource indicator for the MCP server (RFC 8707).
		$params['resource'] = $this->server_url;

		return add_query_arg( $params, $auth_endpoint );
	}

	/**
	 * Exchange an authorization code for access and refresh tokens.
	 *
	 * @since 1.9.0
	 * @param string $code         Authorization code from the callback.
	 * @param string $state        State parameter from the callback (validated against stored state).
	 * @param string $code_verifier PKCE code verifier.
	 * @return array|WP_Error Token response on success, WP_Error on failure.
	 */
	public function exchange_code( $code, $state, $code_verifier ) {
		$metadata = $this->discover_metadata();
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$token_endpoint = $metadata['token_endpoint'];

		// Validate state.
		if ( ! hash_equals( $this->state, $state ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_state_mismatch',
				__( 'OAuth state parameter mismatch. This could indicate a CSRF attack.', 'mcp-ai-wpoos-pro' )
			);
		}

		$body = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $this->redirect_uri,
			'code_verifier' => $code_verifier,
			'resource'      => $this->server_url,
		);

		$response = wp_remote_post(
			$token_endpoint,
			array(
				'timeout'   => $this->timeout,
				'sslverify' => $this->verify_ssl,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_token_exchange_failed',
				sprintf(
					/* translators: %s: Error message. */
					__( 'Failed to exchange authorization code: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$resp_body   = wp_remote_retrieve_body( $response );
		$data        = json_decode( $resp_body, true );

		if ( 200 !== $status_code ) {
			$error_desc = isset( $data['error_description'] ) ? $data['error_description'] : __( 'Unknown token error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_token_error',
				$error_desc,
				array( 'status' => $status_code )
			);
		}

		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_token_invalid',
				__( 'Invalid token response from remote server.', 'mcp-ai-wpoos-pro' )
			);
		}

		$this->token_data = array(
			'access_token'  => $data['access_token'],
			'refresh_token' => isset( $data['refresh_token'] ) ? $data['refresh_token'] : '',
			'token_type'    => isset( $data['token_type'] ) ? $data['token_type'] : 'Bearer',
			'expires_in'    => isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600,
			'scope'         => isset( $data['scope'] ) ? $data['scope'] : '',
			'issued_at'     => time(),
		);

		return $this->token_data;
	}

	/**
	 * Refresh the access token using a refresh token.
	 *
	 * @since 1.9.0
	 * @param string $refresh_token The refresh token to use.
	 * @return array|WP_Error New token response on success, WP_Error on failure.
	 */
	public function refresh_token( $refresh_token ) {
		$metadata = $this->discover_metadata();
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$token_endpoint = $metadata['token_endpoint'];

		$body = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'resource'      => $this->server_url,
		);

		$response = wp_remote_post(
			$token_endpoint,
			array(
				'timeout'   => $this->timeout,
				'sslverify' => $this->verify_ssl,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_refresh_failed',
				sprintf(
					/* translators: %s: Error message. */
					__( 'Failed to refresh access token: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$resp_body   = wp_remote_retrieve_body( $response );
		$data        = json_decode( $resp_body, true );

		if ( 200 !== $status_code ) {
			$error_desc = isset( $data['error_description'] ) ? $data['error_description'] : __( 'Unknown refresh error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_refresh_error',
				$error_desc,
				array( 'status' => $status_code )
			);
		}

		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_refresh_invalid',
				__( 'Invalid refresh response from remote server.', 'mcp-ai-wpoos-pro' )
			);
		}

		$this->token_data = array(
			'access_token'  => $data['access_token'],
			'refresh_token' => isset( $data['refresh_token'] ) ? $data['refresh_token'] : $refresh_token,
			'token_type'    => isset( $data['token_type'] ) ? $data['token_type'] : 'Bearer',
			'expires_in'    => isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600,
			'scope'         => isset( $data['scope'] ) ? $data['scope'] : '',
			'issued_at'     => time(),
		);

		return $this->token_data;
	}

	/**
	 * Revoke the current access token with the remote server.
	 *
	 * @since 1.9.0
	 * @param string $token The token to revoke.
	 * @return bool True on success, false on failure.
	 */
	public function revoke_token( $token ) {
		$metadata = $this->discover_metadata();
		if ( is_wp_error( $metadata ) ) {
			return false;
		}

		$revocation_endpoint = isset( $metadata['revocation_endpoint'] )
			? $metadata['revocation_endpoint']
			: '';

		if ( empty( $revocation_endpoint ) ) {
			return false;
		}

		$response = wp_remote_post(
			$revocation_endpoint,
			array(
				'timeout'   => $this->timeout,
				'sslverify' => $this->verify_ssl,
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
				'body'      => wp_json_encode( array( 'token' => $token ) ),
			)
		);

		return ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Get the current access token, refreshing if needed.
	 *
	 * @since 1.9.0
	 * @return string|WP_Error Access token or WP_Error if not available.
	 */
	public function get_access_token() {
		if ( empty( $this->token_data['access_token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_no_token',
				__( 'No OAuth access token available. Please complete the web login flow.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if token is expired or close to expiring (within 60 seconds).
		$expires_at = $this->token_data['issued_at'] + $this->token_data['expires_in'];
		if ( time() >= ( $expires_at - 60 ) ) {
			// Try to refresh.
			if ( ! empty( $this->token_data['refresh_token'] ) ) {
				$result = $this->refresh_token( $this->token_data['refresh_token'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			} else {
				return new WP_Error(
					'wp_mcp_ai_mcp_app_oauth_token_expired',
					__( 'OAuth access token has expired and no refresh token is available. Please re-authenticate.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		return $this->token_data['access_token'];
	}

	/**
	 * Check if the current access token needs refreshing.
	 *
	 * @since 1.9.0
	 * @return bool True if token is expired or will expire within 60 seconds.
	 */
	public function is_token_expired() {
		if ( empty( $this->token_data['access_token'] ) ) {
			return true;
		}

		$expires_at = $this->token_data['issued_at'] + $this->token_data['expires_in'];
		return time() >= ( $expires_at - 60 );
	}

	/**
	 * Load stored token data.
	 *
	 * @since 1.9.0
	 * @param array $token_data Token data from app config storage.
	 * @return void
	 */
	public function set_token_data( array $token_data ) {
		$this->token_data = $token_data;
	}

	/**
	 * Get the token data for storage.
	 *
	 * @since 1.9.0
	 * @return array
	 */
	public function get_token_data() {
		return $this->token_data;
	}

	/**
	 * Get the PKCE code verifier.
	 *
	 * @since 1.9.0
	 * @return string
	 */
	public function get_code_verifier() {
		return $this->code_verifier;
	}

	/**
	 * Get the OAuth state parameter.
	 *
	 * @since 1.9.0
	 * @return string
	 */
	public function get_state() {
		return $this->state;
	}

	/**
	 * Get the redirect URI.
	 *
	 * @since 1.9.0
	 * @return string
	 */
	public function get_redirect_uri() {
		return $this->redirect_uri;
	}

	/**
	 * Get the discovered OAuth metadata.
	 *
	 * @since 1.9.0
	 * @return array|null
	 */
	public function get_metadata() {
		return $this->metadata;
	}

	/**
	 * Get the registered client ID.
	 *
	 * @since 1.9.0
	 * @return string
	 */
	public function get_client_id() {
		return $this->client_id;
	}

	/**
	 * Set the client ID (for restoring from stored config).
	 *
	 * @since 1.9.0
	 * @param string $client_id Client ID.
	 * @return void
	 */
	public function set_client_id( $client_id ) {
		$this->client_id = sanitize_key( $client_id );
	}

	// -----------------------------------------------------------------------
	// Utility Methods
	// -----------------------------------------------------------------------

	/**
	 * Compute PKCE S256 challenge from verifier.
	 *
	 * @since 1.9.0
	 * @param string $verifier Raw code verifier (43-128 chars).
	 * @return string Base64URL-encoded SHA-256 hash.
	 */
	public static function compute_s256_challenge( $verifier ) {
		return self::base64url_encode( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Base64URL-encode raw bytes (per RFC 4648 §5).
	 *
	 * @since 1.9.0
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function base64url_encode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by RFC 4648 §5 for PKCE.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Parse the WWW-Authenticate header to extract OAuth metadata.
	 *
	 * @since 1.9.0
	 * @param string $header WWW-Authenticate header value.
	 * @return array Parsed parameters.
	 */
	protected function parse_www_authenticate( $header ) {
		$params = array();

		// Match key="value" pairs.
		if ( preg_match_all( '/([a-zA-Z_]+)\s*=\s*"([^"]*)"/', $header, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$params[ strtolower( $match[1] ) ] = $match[2];
			}
		}

		return $params;
	}

	/**
	 * Build the well-known OAuth metadata URL from the server URL.
	 *
	 * @since 1.9.0
	 * @return string
	 */
	protected function build_well_known_url() {
		$parts = wp_parse_url( $this->server_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = $parts['host'];
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . $host . $port . '/.well-known/oauth-authorization-server';
	}

	/**
	 * Build the REST API OAuth metadata URL from the server URL.
	 *
	 * For WordPress sites, the metadata is also available at:
	 * /wp-json/mcp-ai/v1/oauth/metadata
	 *
	 * @since 1.9.0
	 * @return string
	 */
	protected function build_rest_metadata_url() {
		$parts = wp_parse_url( $this->server_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = $parts['host'];
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';

		// Extract the WP REST prefix from the MCP URL.
		// e.g. /wp-json/mcp-ai/v1/mcp → /wp-json/mcp-ai/v1/oauth/metadata.
		$rest_prefix = preg_replace( '#/mcp$#', '', $path );

		return $scheme . '://' . $host . $port . $rest_prefix . '/oauth/metadata';
	}
}
