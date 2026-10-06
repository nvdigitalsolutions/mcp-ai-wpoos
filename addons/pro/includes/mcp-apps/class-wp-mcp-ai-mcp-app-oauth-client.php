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
	 * Outbound HTTP proxy (host:port) for discovery/token requests.
	 *
	 * Empty when no proxy applies.
	 *
	 * @var string
	 */
	protected $proxy_url = '';

	/**
	 * Outbound HTTP proxy credentials (user:pass).
	 *
	 * @var string
	 */
	protected $proxy_auth = '';

	/**
	 * Timeout for individual discovery probes in seconds.
	 *
	 * Discovery issues several sequential requests; capping each probe
	 * keeps the whole chain responsive even when a server is unreachable.
	 *
	 * @since 1.9.5
	 * @var int
	 */
	protected $discovery_timeout;

	/**
	 * Which discovery step produced the current metadata.
	 *
	 * One of '', 'as_metadata', 'protected_resource', 'www_authenticate'.
	 *
	 * @since 1.9.5
	 * @var string
	 */
	protected $metadata_source = '';

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
	 *     @type int    $timeout    HTTP request timeout in seconds. Default 30.
	 *     @type bool   $verify_ssl Whether to verify SSL. Default true.
	 *     @type string $proxy_url  Optional outbound HTTP proxy (host:port).
	 *     @type string $proxy_auth Optional proxy credentials (user:pass).
	 * }
	 */
	public function __construct( $server_url, array $options = array() ) {
		$this->server_url        = esc_url_raw( $server_url );
		$this->timeout           = isset( $options['timeout'] ) ? max( 1, min( 120, absint( $options['timeout'] ) ) ) : 30;
		$this->discovery_timeout = max( 3, min( 10, $this->timeout ) );
		$this->verify_ssl        = isset( $options['verify_ssl'] ) ? (bool) $options['verify_ssl'] : true;
		$this->proxy_url         = isset( $options['proxy_url'] ) ? (string) $options['proxy_url'] : '';
		$this->proxy_auth        = isset( $options['proxy_auth'] ) ? (string) $options['proxy_auth'] : '';
		$this->redirect_uri      = rest_url( 'mcp-ai/v1/mcp-apps/oauth/callback' );
	}

	/**
	 * Discover OAuth 2.0 Authorization Server metadata from the remote server.
	 *
	 * Follows the MCP Authorization Specification discovery chain:
	 *
	 * 1. RFC 8414 authorization server metadata on the MCP origin, including
	 *    the path-insertion variant (RFC 8414 §3.2) for path-scoped servers
	 *    (e.g. https://mcp.atlassian.com/v1/mcp).
	 * 2. RFC 9728 protected resource metadata
	 *    (/.well-known/oauth-protected-resource, plus the path-insertion
	 *    variant) — every advertised authorization server is tried, not just
	 *    the first.
	 * 3. A 401 WWW-Authenticate probe of the MCP endpoint, following the
	 *    resource_metadata pointer when the server challenges.
	 * 4. OpenID Connect discovery (/.well-known/openid-configuration) as a
	 *    compatibility fallback for gateways fronting Auth0/Okta/Cognito.
	 * 5. The WordPress REST metadata endpoint for self-hosted WP MCP servers.
	 *
	 * Every attempt is recorded so failures surface actionable diagnostics
	 * (URL, HTTP status, or transport error) instead of a generic message.
	 *
	 * @since 1.9.0
	 * @since 1.9.5 Extended with RFC 9728 / path-insertion / OIDC discovery
	 *              and per-attempt diagnostics.
	 * @return array|WP_Error OAuth metadata on success, WP_Error on failure.
	 */
	public function discover_metadata() {
		if ( null !== $this->metadata ) {
			return $this->metadata;
		}

		$attempts        = array();
		$transport_error = '';

		// 1. RFC 8414 authorization server metadata on the MCP origin.
		foreach ( $this->build_as_metadata_urls() as $url ) {
			$data = $this->fetch_metadata_document( $url, 'as', $attempts, $transport_error );
			if ( is_array( $data ) ) {
				$this->metadata        = $data;
				$this->metadata_source = 'as_metadata';
				return $this->metadata;
			}
		}

		// 2. RFC 9728 protected resource metadata → follow every authorization server.
		foreach ( $this->build_prm_urls() as $url ) {
			$prm = $this->fetch_metadata_document( $url, 'prm', $attempts, $transport_error );
			if ( ! is_array( $prm ) ) {
				continue;
			}

			$metadata = $this->resolve_authorization_server_metadata( $prm, $attempts, $transport_error );
			if ( is_array( $metadata ) ) {
				$this->metadata        = $metadata;
				$this->metadata_source = 'protected_resource';
				return $this->metadata;
			}
		}

		// 3. 401 WWW-Authenticate probe of the MCP endpoint.
		$metadata = $this->discover_via_www_authenticate( $attempts, $transport_error );
		if ( is_array( $metadata ) ) {
			$this->metadata        = $metadata;
			$this->metadata_source = 'www_authenticate';
			return $this->metadata;
		}

		$message = __( 'Could not discover OAuth metadata from the remote MCP server. The server may not support OAuth 2.0 authentication.', 'mcp-ai-wpoos-pro' );

		// Surface the underlying transport failure (cURL, DNS, TLS) so the
		// admin can tell "server has no OAuth" from "site could not reach it".
		if ( '' !== $transport_error ) {
			$message .= ' ' . sprintf(
				/* translators: %s: Underlying transport error, e.g. a cURL failure. */
				__( 'The last request failed with: %s', 'mcp-ai-wpoos-pro' ),
				$transport_error
			);
		}

		return new WP_Error(
			'wp_mcp_ai_mcp_app_oauth_no_metadata',
			$message,
			array(
				'attempts'   => $attempts,
				'hint'       => __( 'If this server uses an API key or bearer token instead of browser sign-in, choose that authentication type and skip the web login.', 'mcp-ai-wpoos-pro' ),
				'server_url' => $this->server_url,
			)
		);
	}

	/**
	 * Fetch and validate a discovery candidate document.
	 *
	 * Records the outcome in the attempt log so the caller can surface
	 * per-URL diagnostics when the whole chain fails.
	 *
	 * @since 1.9.5
	 * @param string $url             Candidate URL.
	 * @param string $kind            Document kind: 'as' (RFC 8414) or 'prm' (RFC 9728).
	 * @param array  $attempts        Attempt log (by reference).
	 * @param string $transport_error Last transport error message (by reference).
	 * @return array|null Decoded document, or null when unavailable/invalid.
	 */
	protected function fetch_metadata_document( $url, $kind, &$attempts, &$transport_error ) {
		if ( '' === $url ) {
			return null;
		}

		$response = $this->run_proxied_request(
			function () use ( $url ) {
				return wp_remote_get(
					$url,
					array(
						'timeout'   => $this->discovery_timeout,
						'sslverify' => $this->verify_ssl,
						'headers'   => array( 'Accept' => 'application/json' ),
					)
				);
			}
		);

		if ( is_wp_error( $response ) ) {
			$transport_error = $response->get_error_message();
			$attempts[]      = array(
				'url'    => $url,
				'result' => 'transport_error',
				'status' => 0,
				'error'  => $transport_error,
			);
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $data ) ) {
			$attempts[] = array(
				'url'    => $url,
				'result' => ( 200 === $status ) ? 'invalid_json' : 'http_' . $status,
				'status' => $status,
			);
			return null;
		}

		$valid      = ( 'as' === $kind ) ? $this->metadata_is_valid( $data ) : $this->prm_is_valid( $data );
		$attempts[] = array(
			'url'    => $url,
			'result' => $valid ? 'ok' : 'invalid_metadata',
			'status' => $status,
		);

		return $valid ? $data : null;
	}

	/**
	 * Whether an RFC 8414 authorization server metadata document is usable.
	 *
	 * RFC 8414 §2 requires both authorization_endpoint and token_endpoint
	 * (the token endpoint is omitted only for the implicit grant, which the
	 * MCP authorization code flow never uses).
	 *
	 * @since 1.9.5
	 * @param array $data Decoded metadata document.
	 * @return bool
	 */
	protected function metadata_is_valid( $data ) {
		return ! empty( $data['authorization_endpoint'] ) && ! empty( $data['token_endpoint'] );
	}

	/**
	 * Whether an RFC 9728 protected resource metadata document is usable.
	 *
	 * @since 1.9.5
	 * @param array $data Decoded metadata document.
	 * @return bool
	 */
	protected function prm_is_valid( $data ) {
		return ! empty( $data['authorization_servers'] );
	}

	/**
	 * Fetch RFC 8414 metadata from every authorization server advertised in
	 * a protected resource metadata document (RFC 9728 §2).
	 *
	 * The MCP Authorization Specification instructs clients to try each
	 * advertised server, not just the first — providers commonly rotate or
	 * regionalise their authorization servers.
	 *
	 * @since 1.9.5
	 * @param array  $prm             Protected resource metadata document.
	 * @param array  $attempts        Attempt log (by reference).
	 * @param string $transport_error Last transport error message (by reference).
	 * @return array|null RFC 8414 metadata, or null when none resolve.
	 */
	protected function resolve_authorization_server_metadata( $prm, &$attempts, &$transport_error ) {
		$servers = $prm['authorization_servers'];
		if ( ! is_array( $servers ) ) {
			$servers = array( $servers );
		}

		foreach ( $servers as $auth_server ) {
			$auth_server = is_string( $auth_server ) ? trim( $auth_server ) : '';
			if ( '' === $auth_server ) {
				continue;
			}

			foreach ( $this->build_as_metadata_urls_for_server( $auth_server ) as $url ) {
				$data = $this->fetch_metadata_document( $url, 'as', $attempts, $transport_error );
				if ( is_array( $data ) ) {
					return $data;
				}
			}
		}

		return null;
	}

	/**
	 * Probe the MCP endpoint and follow a 401 WWW-Authenticate challenge.
	 *
	 * Per the MCP Authorization Specification, servers that require OAuth
	 * respond to unauthenticated requests with:
	 * WWW-Authenticate: Bearer resource_metadata="https://…"
	 *
	 * @since 1.9.0
	 * @since 1.9.5 Reworked onto the shared attempt log + PRM/AS resolvers.
	 * @param array  $attempts        Attempt log (by reference).
	 * @param string $transport_error Last transport error message (by reference).
	 * @return array|null RFC 8414 metadata, or null when the probe fails.
	 */
	protected function discover_via_www_authenticate( &$attempts, &$transport_error ) {
		$mcp_response = $this->run_proxied_request(
			function () {
				return wp_remote_post(
					$this->server_url,
					array(
						'timeout'   => $this->discovery_timeout,
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
			}
		);

		$status_code = is_wp_error( $mcp_response ) ? 0 : (int) wp_remote_retrieve_response_code( $mcp_response );
		$attempts[]  = array(
			'url'    => $this->server_url,
			'result' => is_wp_error( $mcp_response ) ? 'transport_error' : 'http_' . $status_code,
			'status' => $status_code,
			'error'  => is_wp_error( $mcp_response ) ? $mcp_response->get_error_message() : '',
		);

		if ( is_wp_error( $mcp_response ) ) {
			$transport_error = $mcp_response->get_error_message();
			return null;
		}

		if ( 401 !== $status_code && 403 !== $status_code ) {
			return null;
		}

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

		if ( empty( $www_auth ) ) {
			return null;
		}

		$parsed = $this->parse_www_authenticate( $www_auth );
		if ( empty( $parsed['resource_metadata'] ) ) {
			return null;
		}

		// Fetch protected resource metadata (RFC 9728).
		$prm = $this->fetch_metadata_document( $parsed['resource_metadata'], 'prm', $attempts, $transport_error );
		if ( ! is_array( $prm ) ) {
			return null;
		}

		return $this->resolve_authorization_server_metadata( $prm, $attempts, $transport_error );
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
				__( 'The remote MCP server does not support dynamic client registration.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$body = array(
			'client_name'                => get_bloginfo( 'name' ) . ' — NV oOS MCP App',
			'redirect_uris'              => array( $this->redirect_uri ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_method' => 'none',
			'application_type'           => 'web',
		);

		$response = $this->run_proxied_request(
			function () use ( $registration_endpoint, $body ) {
				return wp_remote_post(
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
			}
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_registration_failed',
				sprintf(
					/* translators: %s: Error message. */
					__( 'Failed to register OAuth client: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
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
				array(
					'status'      => $status_code,
					// Surface the OAuth error code (e.g. invalid_redirect_uri)
					// so callers can react, e.g. by falling back to a loopback
					// redirect URI for providers like Upwork.
					'oauth_error' => isset( $data['error'] ) ? sanitize_key( $data['error'] ) : '',
				)
			);
		}

		if ( ! is_array( $data ) || empty( $data['client_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_oauth_registration_invalid',
				__( 'Invalid registration response from remote server.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 502 )
			);
		}

		// Preserve the client ID verbatim — dynamic-registration client IDs
		// are opaque strings that may carry characters sanitize_key() would
		// strip (underscores, dots, case), which would break the callback
		// exchange. sanitize_text_field() only removes HTML/whitespace noise.
		$this->client_id = sanitize_text_field( $data['client_id'] );

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
		} elseif ( ! empty( $metadata['default_scope'] ) ) {
			// Providers such as Flowhub advertise a default scope in their
			// metadata; use it when the caller did not request one.
			$params['scope'] = $metadata['default_scope'];
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

		// Public clients (token_endpoint_auth_method=none) still identify
		// themselves in the token request; providers such as Upwork reject
		// the exchange without it ("Missing parameters: client_id").
		if ( ! empty( $this->client_id ) ) {
			$body['client_id'] = $this->client_id;
		}

		$response = $this->post_token_endpoint( $token_endpoint, $body );

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

		// Same as the code exchange: providers such as Upwork require the
		// client ID for public clients on refresh as well.
		if ( ! empty( $this->client_id ) ) {
			$body['client_id'] = $this->client_id;
		}

		$response = $this->post_token_endpoint( $token_endpoint, $body );

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

		$response = $this->post_token_endpoint( $revocation_endpoint, array( 'token' => $token ) );

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
	 * Get which discovery step produced the current metadata.
	 *
	 * One of '', 'as_metadata', 'protected_resource', 'www_authenticate'.
	 *
	 * @since 1.9.5
	 * @return string
	 */
	public function get_metadata_source() {
		return $this->metadata_source;
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

	/**
	 * Set the redirect URI.
	 *
	 * Used to restore the flow-specific redirect URI during the callback
	 * exchange (which runs in a fresh request). Required for manual loopback
	 * flows where the authorize request used a localhost URI that differs
	 * from the default REST callback URL.
	 *
	 * @since 1.9.0
	 * @param string $redirect_uri Redirect URI.
	 * @return void
	 */
	public function set_redirect_uri( $redirect_uri ) {
		$this->redirect_uri = esc_url_raw( $redirect_uri );
	}

	/**
	 * Set the OAuth state value.
	 *
	 * The callback exchange runs in a fresh request, so the CSRF state
	 * generated during initiation must be restored before the code exchange
	 * validates it.
	 *
	 * @since 1.9.0
	 * @param string $state State value.
	 * @return void
	 */
	public function set_state( $state ) {
		$this->state = sanitize_text_field( $state );
	}

	// ----------------------------------------------------------------------- //
	// Utility Methods
	// ----------------------------------------------------------------------- //

	/**
	 * Run an outbound HTTP call through the configured proxy.
	 *
	 * The WordPress HTTP API has no per-request proxy arguments, so the
	 * proxy is attached at the cURL layer via the `http_api_curl` action
	 * and removed again in a finally block — the same pattern the FlowHub
	 * client uses. When no proxy is configured the callback runs unchanged.
	 *
	 * @since 1.1.93
	 * @param callable $request Callable performing the wp_remote_*() call.
	 * @return mixed The callable's return value.
	 */
	protected function run_proxied_request( $request ) {
		if ( '' === $this->proxy_url ) {
			return $request();
		}

		$proxy_url   = $this->proxy_url;
		$proxy_auth  = $this->proxy_auth;
		$apply_proxy = static function ( $handle ) use ( $proxy_url, $proxy_auth ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Proxy support requires cURL-level configuration.
			curl_setopt( $handle, CURLOPT_PROXY, $proxy_url );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
			if ( '' !== $proxy_auth ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				curl_setopt( $handle, CURLOPT_PROXYUSERPWD, $proxy_auth );
			}
		};

		add_action( 'http_api_curl', $apply_proxy, 10, 1 );

		try {
			return $request();
		} finally {
			remove_action( 'http_api_curl', $apply_proxy, 10 );
		}
	}

	/**
	 * POST to an OAuth endpoint with content negotiation.
	 *
	 * OAuth 2.0 token/revocation requests are specified as form-encoded, but
	 * some providers only accept JSON while others (e.g. Upwork) reject JSON
	 * with HTTP 415. Send JSON first to preserve existing behaviour, then
	 * retry with form-encoding when the endpoint refuses the media type.
	 *
	 * @since 1.9.0
	 * @param string $endpoint Endpoint URL.
	 * @param array  $body     Request parameters.
	 * @return array|WP_Error Response array or WP_Error from wp_remote_post.
	 */
	protected function post_token_endpoint( $endpoint, array $body ) {
		$response = $this->run_proxied_request(
			function () use ( $endpoint, $body ) {
				return wp_remote_post(
					$endpoint,
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
			}
		);

		if ( ! is_wp_error( $response ) && 415 === wp_remote_retrieve_response_code( $response ) ) {
			// The endpoint does not accept JSON. Retry with form-encoded
			// parameters, which WordPress encodes from the array body.
			$response = $this->run_proxied_request(
				function () use ( $endpoint, $body ) {
					return wp_remote_post(
						$endpoint,
						array(
							'timeout'   => $this->timeout,
							'sslverify' => $this->verify_ssl,
							'headers'   => array(
								'Content-Type' => 'application/x-www-form-urlencoded',
								'Accept'       => 'application/json',
							),
							'body'      => $body,
						)
					);
				}
			);
		}

		return $response;
	}

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
	 * Handles multiple comma-separated challenges per RFC 7235 §2.1 and
	 * collects the key="value" parameters from each (RFC 7235 §2.2 quoted
	 * strings), which is how the MCP Authorization Specification transports
	 * the resource_metadata and scope pointers:
	 *
	 *   WWW-Authenticate: Bearer resource_metadata="https://…", scope="mcp"
	 *
	 * @since 1.9.0
	 * @since 1.9.5 Rewritten to split challenges instead of matching across
	 *              the whole header, so a leading Basic challenge (or any
	 *              other comma-separated challenge) no longer corrupts the
	 *              parameter extraction.
	 * @param string $header WWW-Authenticate header value.
	 * @return array Parsed parameters (lowercased keys).
	 */
	protected function parse_www_authenticate( $header ) {
		$params = array();

		foreach ( $this->split_challenges( (string) $header ) as $challenge ) {
			// Strip the auth-scheme token when present (e.g. "Bearer …").
			$param_string = $challenge;
			if ( preg_match( '/^([a-zA-Z0-9._~+\/-]+)\s+(.+)$/', $challenge, $scheme_match ) ) {
				$param_string = $scheme_match[2];
			}

			if ( preg_match_all( '/([a-zA-Z_][a-zA-Z0-9_-]*)\s*=\s*"([^"]*)"/', $param_string, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$key = strtolower( $match[1] );
					if ( ! isset( $params[ $key ] ) ) {
						$params[ $key ] = $match[2];
					}
				}
			}
		}

		return $params;
	}

	/**
	 * Split a WWW-Authenticate header into individual challenges.
	 *
	 * Challenges are comma-separated, but commas inside quoted strings are
	 * data — split only on commas outside quotes (RFC 7235 §2.1).
	 *
	 * @since 1.9.5
	 * @param string $header WWW-Authenticate header value.
	 * @return string[] Non-empty trimmed challenges.
	 */
	protected function split_challenges( $header ) {
		$challenges = array();
		$current    = '';
		$in_quotes  = false;
		$length     = strlen( $header );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $header[ $i ];

			if ( '"' === $char ) {
				$in_quotes = ! $in_quotes;
			}

			if ( ',' === $char && ! $in_quotes ) {
				$challenges[] = trim( $current );
				$current      = '';
				continue;
			}

			$current .= $char;
		}

		$challenges[] = trim( $current );

		return array_values( array_filter( $challenges ) );
	}

	/**
	 * Get the origin (scheme://host[:port]) of the configured server URL.
	 *
	 * @since 1.9.5
	 * @return string Origin, or '' when the URL is malformed.
	 */
	protected function get_origin() {
		$parts = wp_parse_url( $this->server_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = $parts['host'];
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . $host . $port;
	}

	/**
	 * Get the normalized path of the configured server URL.
	 *
	 * @since 1.9.5
	 * @return string Path with leading slash and no trailing slash, or ''.
	 */
	protected function get_path() {
		$parts = wp_parse_url( $this->server_url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$path = trim( isset( $parts['path'] ) ? $parts['path'] : '', '/' );

		return '' === $path ? '' : '/' . $path;
	}

	/**
	 * Build RFC 8414 authorization server metadata candidate URLs for the
	 * configured server, in priority order.
	 *
	 * @since 1.9.5
	 * @return string[] Candidate URLs.
	 */
	protected function build_as_metadata_urls() {
		$origin = $this->get_origin();
		if ( '' === $origin ) {
			return array();
		}

		$urls = $this->build_as_metadata_urls_for_server( $origin );

		// RFC 8414 §3.2 path insertion — insert the resource path after the
		// well-known segment (e.g. /.well-known/oauth-authorization-server/v1/mcp).
		$path = $this->get_path();
		if ( '' !== $path ) {
			$urls[] = $origin . '/.well-known/oauth-authorization-server' . $path;
		}

		// Self-hosted WordPress MCP servers expose the same document at the
		// REST endpoint when the .well-known rewrite rule is not active.
		$rest_url = $this->build_rest_metadata_url();
		if ( '' !== $rest_url ) {
			$urls[] = $rest_url;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Build RFC 8414 metadata candidate URLs for an authorization server URL.
	 *
	 * Includes the path-insertion variant (RFC 8414 §3.2) for path-scoped
	 * servers and the OpenID Connect discovery endpoint as a compatibility
	 * fallback for gateways fronting Auth0 / Okta / Cognito / Keycloak.
	 *
	 * @since 1.9.5
	 * @param string $server_url Authorization server URL.
	 * @return string[] Candidate URLs.
	 */
	protected function build_as_metadata_urls_for_server( $server_url ) {
		// Some servers advertise the full metadata URL itself in
		// authorization_servers — fetch it directly in that case.
		if ( false !== strpos( $server_url, '/.well-known/oauth-authorization-server' ) ) {
			return array( rtrim( $server_url, '/' ) );
		}

		$parts = wp_parse_url( $server_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return array();
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$origin = $scheme . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$path   = trim( isset( $parts['path'] ) ? $parts['path'] : '', '/' );

		$urls = array( $origin . '/.well-known/oauth-authorization-server' );

		// RFC 8414 §3.2 path insertion — e.g. /.well-known/oauth-authorization-server/v1/mcp.
		if ( '' !== $path ) {
			$urls[] = $origin . '/.well-known/oauth-authorization-server/' . $path;
		}

		// OIDC discovery fallback (see method docblock).
		$urls[] = $origin . '/.well-known/openid-configuration';

		// Self-hosted WordPress authorization servers expose the document at
		// the REST endpoint when the .well-known rewrite rule is inactive.
		$urls[] = $origin . '/wp-json/mcp-ai/v1/oauth/metadata';

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Build RFC 9728 protected resource metadata candidate URLs.
	 *
	 * @since 1.9.5
	 * @return string[] Candidate URLs.
	 */
	protected function build_prm_urls() {
		$origin = $this->get_origin();
		if ( '' === $origin ) {
			return array();
		}

		$urls = array( $origin . '/.well-known/oauth-protected-resource' );

		// Path-insertion variant — API gateways commonly serve the document
		// at /.well-known/oauth-protected-resource/mcp.
		$path = $this->get_path();
		if ( '' !== $path ) {
			$urls[] = $origin . '/.well-known/oauth-protected-resource' . $path;
		}

		return array_values( array_unique( $urls ) );
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
