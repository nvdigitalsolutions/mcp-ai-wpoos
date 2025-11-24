<?php
/**
 * REST API controller for WP MCP AI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's REST API endpoints.
 */
class WP_MCP_AI_REST {
	const REST_NAMESPACE            = 'mcp-ai/v1';
	const MEMORY_MAX_DOCUMENT_CHARS = 4000;
	const MEMORY_CHUNK_CHARS        = 1200;
	const MEMORY_MAX_TOTAL_CHARS    = 12000;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Language model router.
	 *
	 * @var WP_MCP_AI_Language_Model_Router
	 */
	protected $client;

	/**
	 * Tracks authentication details for the current request.
	 *
	 * @var array
	 */
	protected $auth_context = array();

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Tool_Registry         $registry Tool registry instance.
	 * @param WP_MCP_AI_Language_Model_Router $client   Language model router.
	 */
	public function __construct( WP_MCP_AI_Tool_Registry $registry, WP_MCP_AI_Language_Model_Router $client ) {
		$this->registry = $registry;
		$this->client   = $client;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_request_after_callbacks', array( $this, 'format_actionable_error' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'augment_error_actions' ), 10, 3 );
	}

	/**
	 * Ensure permission errors expose actionable guidance for MCP routes.
	 *
	 * @param mixed           $response Result from the endpoint callbacks.
	 * @param array           $handler  Route handler configuration.
	 * @param WP_REST_Request $request  Current REST request.
	 * @return mixed
	 */
	public function format_actionable_error( $response, $handler, $request ) {
		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
			return $response;
		}

		$data = $response->get_error_data();
		if ( ! is_array( $data ) || empty( $data['actions'] ) ) {
			return $response;
		}

		$status = isset( $data['status'] ) ? (int) $data['status'] : 500;

		$payload = array(
			'code'    => $response->get_error_code(),
			'message' => $response->get_error_message(),
			'actions' => $data['actions'],
			'data'    => $data,
		);

		return new WP_REST_Response( $payload, $status );
	}

	/**
	 * Ensure actionable guidance is surfaced at the top-level of REST error responses.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_REST_Server   $server   REST server instance.
	 * @param WP_REST_Request  $request  Original request object.
	 * @return WP_REST_Response
	 */
	public function augment_error_actions( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		if ( isset( $data['actions'] ) ) {
			return $response;
		}

		if ( isset( $data['data'] ) && is_array( $data['data'] ) && isset( $data['data']['actions'] ) ) {
			$data['actions'] = $data['data']['actions'];
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Reset the stored authentication context for the current request.
	 */
	protected function reset_auth_context() {
		$this->auth_context = array(
			'user_id'             => absint( get_current_user_id() ),
			'token_authenticated' => false,
			'token_type'          => null,
			'token_context'       => array(),
			'assistant_id'        => 0,
		);
	}

	/**
	 * Persist information about token-based authentication.
	 *
	 * @param string $type    Authentication method identifier.
	 * @param array  $context Additional context information.
	 */
	protected function mark_token_authenticated( $type, $context = array() ) {
		if ( empty( $this->auth_context ) ) {
			$this->reset_auth_context();
		}

		$this->auth_context['token_authenticated'] = true;
		$this->auth_context['token_type']          = sanitize_key( $type );
		$this->auth_context['token_context']       = is_array( $context ) ? $context : array();

		if ( isset( $context['user_id'] ) ) {
			$user_id                       = absint( $context['user_id'] );
			$this->auth_context['user_id'] = $user_id;
			$this->maybe_set_current_user( $user_id );
		}

		$assistant_id = 0;
		if ( isset( $context['assistant_id'] ) ) {
			$assistant_id = absint( $context['assistant_id'] );
		} elseif ( isset( $context['credential']['assistant_id'] ) ) {
			$assistant_id = absint( $context['credential']['assistant_id'] );
		}

		if ( $assistant_id ) {
			$this->auth_context['assistant_id'] = $assistant_id;
		}
	}

	/**
	 * Store the resolved WordPress user ID for the request.
	 *
	 * @param int $user_id WordPress user identifier.
	 */
	protected function set_authenticated_user_id( $user_id ) {
		if ( empty( $this->auth_context ) ) {
			$this->reset_auth_context();
		}

		$user_id                       = absint( $user_id );
		$this->auth_context['user_id'] = $user_id;
		$this->maybe_set_current_user( $user_id );
	}

	/**
	 * Sync the global current user with the authenticated context when available.
	 *
	 * @param int $user_id WordPress user identifier.
	 */
	protected function maybe_set_current_user( $user_id ) {
		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		}
	}

	/**
	 * Retrieve the authentication context for the current request.
	 *
	 * @return array
	 */
	protected function get_auth_context() {
		if ( empty( $this->auth_context ) ) {
			$this->reset_auth_context();
		}

		return $this->auth_context;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_chat_request' ),
					'args'                => array(
						'assistant_id' => array(
							'type'     => 'integer',
							'required' => false,
						),
						'messages'     => array(
							'type'     => 'array',
							'required' => true,
						),
						'options'      => array(
							'type'     => 'object',
							'required' => false,
						),
					),
				),
			),
			true
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/tools',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_tool_request' ),
					'args'                => array(
						'assistant_id' => array(
							'type'     => 'integer',
							'required' => false,
						),
						'tool'         => array(
							'type'     => 'string',
							'required' => true,
						),
						'arguments'    => array(
							'type'     => 'object',
							'required' => false,
						),
					),
				),
			),
			true
		);
	}

	/**
	 * Check permissions for REST requests, validating the nonce and capability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		$this->reset_auth_context();

		$assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
		$capability   = wp_mcp_ai_get_required_chat_capability( $assistant_id, 'rest' );

		if ( is_string( $capability ) ) {
			$capability = sanitize_key( $capability );
		}

		$requires_authenticated_user = ! empty( $capability ) && 'public' !== $capability;

		$bearer = $request->get_header( 'Authorization' );
		if ( ! empty( $bearer ) && preg_match( '/^Bearer\s+(.*)$/i', $bearer, $matches ) ) {
			$token = trim( $matches[1] );
			$local = $this->validate_local_token( $token, $request );

			if ( true === $local ) {
				return true;
			} elseif ( $local instanceof WP_Error ) {
				return $local;
			}

			$validated = $this->validate_bearer_token( $token, $request );

			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			return true;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $requires_authenticated_user ) {
			if ( ! empty( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				$this->set_authenticated_user_id( get_current_user_id() );
			}

			return true;
		}

		if ( empty( $nonce ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'Authentication is required. Provide an Auth0 bearer token or a WordPress REST nonce.', 'wp-mcp-ai' ),
				array(
					'status'  => 401,
					'actions' => array(
						'supply_bearer_token' => __( 'Include an Auth0-issued access token using the Authorization: Bearer YOUR_TOKEN header.', 'wp-mcp-ai' ),
						'include_rest_nonce'  => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_invalid_nonce',
				__( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
				array(
					'status'  => rest_authorization_required_code(),
					'actions' => array(
						'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		if ( $capability && ! current_user_can( $capability ) ) {
			return $this->insufficient_permissions_error( $capability );
		}

		$this->set_authenticated_user_id( get_current_user_id() );

		return true;
	}

	/**
	 * Build a consistent error response when the authenticated user lacks access.
	 *
	 * @return WP_Error
	 */
	protected function insufficient_permissions_error( $capability = 'edit_posts' ) {
		if ( is_string( $capability ) ) {
			$capability = sanitize_key( $capability );
		}

		return new WP_Error(
			'wp_mcp_ai_insufficient_permissions',
			sprintf(
				__( 'The authenticated user cannot access the MCP AI API. Grant the account the "%s" capability or switch to another user.', 'wp-mcp-ai' ),
				$capability
			),
			array(
				'status'  => 403,
				'actions' => array(
					'grant_capability' => sprintf(
						__( 'Assign a role that includes the "%s" capability.', 'wp-mcp-ai' ),
						$capability
					),
				),
			)
		);
	}

	/**
	 * Attempt to validate a plugin-issued credential token.
	 *
	 * @param string          $token   Raw token string.
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error|null True when valid, WP_Error when rejected, null when the token should be treated as a JWT.
	 */
	protected function validate_local_token( $token, WP_REST_Request $request ) {
		if ( ! WP_MCP_AI_Credentials::is_token_format( $token ) ) {
			return null;
		}

		$assistant_hint = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
		$validated      = WP_MCP_AI_Credentials::validate_token( $token, $assistant_hint );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$this->mark_token_authenticated(
			'local_token',
			array(
				'credential'   => $validated,
				'assistant_id' => isset( $validated['assistant_id'] ) ? absint( $validated['assistant_id'] ) : 0,
			)
		);

		/**
		 * Fires when a request authenticates using a stored credential token.
		 *
		 * @param array            $credential Credential metadata including assistant_id and credential_id.
		 * @param WP_REST_Request  $request    Current REST request.
		 */
		do_action( 'wp_mcp_ai_authenticated_with_credential', $validated, $request );

		return true;
	}

	/**
	 * Validate an Auth0 bearer token.
	 *
	 * @param string          $token   Raw bearer token string.
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error
	 */
	protected function validate_bearer_token( $token, WP_REST_Request $request ) {
		/**
		 * Allow short-circuiting bearer token validation.
		 *
		 * Returning a boolean true grants access, false denies access, and a WP_Error bubbles to the client.
		 *
		 * @param null|bool|WP_Error $pre     Pre-determined validation result.
		 * @param string             $token   Raw bearer token.
		 * @param WP_REST_Request    $request Current request object.
		 */
		$pre = apply_filters( 'wp_mcp_ai_pre_validate_bearer_token', null, $token, $request );
		if ( null !== $pre ) {
			if ( true === $pre ) {
				/**
				 * Allow mapping a pre-validated bearer token to a WordPress user.
				 *
				 * @param int|null        $user_id Previously mapped user identifier.
				 * @param array|null      $payload Decoded token payload when available, or null for pre-validation shortcuts.
				 * @param WP_REST_Request $request Current REST request.
				 */
				$mapped_user = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, null, $request );
				if ( $mapped_user instanceof WP_Error ) {
					return $mapped_user;
				}

				$context = array( 'prevalidated' => true );
				if ( is_numeric( $mapped_user ) && (int) $mapped_user > 0 ) {
					$context['user_id'] = absint( $mapped_user );
					$this->set_authenticated_user_id( $context['user_id'] );
				}

				$this->mark_token_authenticated( 'bearer', $context );

				return true;
			}

			return ( $pre instanceof WP_Error ) ? $pre : new WP_Error(
				'wp_mcp_ai_invalid_bearer_token',
				__( 'The supplied bearer token is invalid.', 'wp-mcp-ai' ),
				array(
					'status'  => 401,
					'actions' => array(
						'obtain_new_token' => __( 'Request a fresh Auth0 access token and retry the call.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		if ( empty( $token ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_bearer_token',
				__( 'The supplied bearer token is invalid.', 'wp-mcp-ai' ),
				array(
					'status'  => 401,
					'actions' => array(
						'obtain_new_token' => __( 'Request a fresh Auth0 access token and retry the call.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$segments = explode( '.', $token );
		if ( 3 !== count( $segments ) ) {
			return $this->invalid_bearer_error();
		}

		$header  = json_decode( $this->base64_url_decode( $segments[0] ), true );
		$payload = json_decode( $this->base64_url_decode( $segments[1] ), true );

		if ( ! is_array( $header ) || ! is_array( $payload ) ) {
			return $this->invalid_bearer_error();
		}

		if ( ! function_exists( 'openssl_verify' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_openssl',
				__( 'PHP OpenSSL support is required to validate Auth0 bearer tokens.', 'wp-mcp-ai' ),
				array(
					'status'  => 500,
					'actions' => array(
						'enable_openssl' => __( 'Enable the PHP OpenSSL extension on the web server.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$domain   = isset( $settings['auth0_domain'] ) ? $settings['auth0_domain'] : '';

		if ( empty( $domain ) ) {
			return new WP_Error(
				'wp_mcp_ai_auth0_not_configured',
				__( 'Auth0 authentication is not configured. Set the Auth0 domain in the WP OOS settings screen.', 'wp-mcp-ai' ),
				array(
					'status'  => 500,
					'actions' => array(
						'configure_auth0_domain' => __( 'In WordPress, visit Settings → WP OOS and provide the Auth0 domain.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$domain = rtrim( preg_replace( '#^https?://#', '', $domain ), '/' );

		if ( empty( $header['kid'] ) || ( isset( $header['alg'] ) && 'RS256' !== $header['alg'] ) ) {
			return $this->invalid_bearer_error();
		}

		$jwks = $this->get_auth0_jwks( $domain );
		if ( is_wp_error( $jwks ) ) {
			return $jwks;
		}

		$key = null;
		foreach ( $jwks as $jwk ) {
			if ( isset( $jwk['kid'] ) && $header['kid'] === $jwk['kid'] ) {
				$key = $jwk;
				break;
			}
		}

		if ( null === $key ) {
			return $this->invalid_bearer_error();
		}

		$pem = $this->jwk_to_pem( $key );

		if ( is_wp_error( $pem ) ) {
			return $pem;
		}

		$signature = $this->base64_url_decode( $segments[2] );
		$signed    = $segments[0] . '.' . $segments[1];
		$verified  = openssl_verify( $signed, $signature, $pem, OPENSSL_ALGO_SHA256 );

		if ( 1 !== $verified ) {
			return $this->invalid_bearer_error();
		}

		if ( empty( $payload['exp'] ) || time() >= (int) $payload['exp'] ) {
			return new WP_Error(
				'wp_mcp_ai_expired_bearer_token',
				__( 'The provided bearer token has expired.', 'wp-mcp-ai' ),
				array(
					'status'  => 401,
					'actions' => array(
						'obtain_new_token' => __( 'Request a fresh Auth0 access token and retry the call.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$expected_issuer = 'https://' . $domain . '/';
		if ( empty( $payload['iss'] ) || $expected_issuer !== $payload['iss'] ) {
			return $this->invalid_bearer_error();
		}

		$audience = isset( $settings['auth0_audience'] ) ? $settings['auth0_audience'] : '';
		if ( ! empty( $audience ) && ! $this->audience_matches( $payload, $audience ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_bearer_audience',
				__( 'The bearer token was not issued for this MCP API.', 'wp-mcp-ai' ),
				array(
					'status'  => 403,
					'actions' => array(
						'request_correct_audience' => __( 'Request an Auth0 access token that includes the configured API audience.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$required_scope = isset( $settings['auth0_required_scope'] ) ? $settings['auth0_required_scope'] : '';
		if ( ! empty( $required_scope ) && ! $this->scope_satisfied( $payload, $required_scope ) ) {
			return new WP_Error(
				'wp_mcp_ai_insufficient_bearer_scope',
				__( 'The bearer token is missing the required scope to call this endpoint.', 'wp-mcp-ai' ),
				array(
					'status'  => 403,
					'actions' => array(
						'request_scope' => sprintf(
							/* translators: %s: required Auth0 scope name */
							__( 'Request an Auth0 access token that includes the "%s" scope.', 'wp-mcp-ai' ),
							$required_scope
						),
					),
				)
			);
		}

		/**
		 * Filter the decoded bearer token payload after it has been validated.
		 *
		 * Returning a WP_Error will reject the request with that error.
		 *
		 * @param array            $payload Decoded JWT payload.
		 * @param WP_REST_Request  $request Current REST request.
		 */
		$filtered_payload = apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );
		if ( $filtered_payload instanceof WP_Error ) {
			return $filtered_payload;
		}

		/**
		 * Allow mapping a validated bearer token payload to a WordPress user for logging/auditing.
		 *
		 * Returning a WP_Error will surface the error to the client.
		 *
		 * @param int|null        $user_id Previously mapped user identifier.
		 * @param array           $payload Decoded JWT payload.
		 * @param WP_REST_Request $request Current REST request instance.
		 */
		$mapped_user = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $filtered_payload, $request );
		if ( $mapped_user instanceof WP_Error ) {
			return $mapped_user;
		}

		$context = array(
			'payload' => $filtered_payload,
		);

		if ( is_numeric( $mapped_user ) && (int) $mapped_user > 0 ) {
			$mapped_user        = absint( $mapped_user );
			$context['user_id'] = $mapped_user;
			$this->set_authenticated_user_id( $mapped_user );
		}

		$this->mark_token_authenticated( 'bearer', $context );

		return true;
	}

	/**
	 * Retrieve the JWKS for the configured Auth0 domain.
	 *
	 * @param string $domain Auth0 domain (without scheme).
	 * @return array|WP_Error
	 */
	protected function get_auth0_jwks( $domain ) {
		$transient_key = 'wp_mcp_ai_auth0_jwks_' . md5( $domain );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( 'https://' . $domain . '/.well-known/jwks.json', array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_auth0_jwks_fetch_failed',
				__( 'Unable to contact Auth0 to validate the bearer token.', 'wp-mcp-ai' ),
				array(
					'status'  => 502,
					'actions' => array(
						'retry_request' => __( 'Retry the request once connectivity with Auth0 is restored.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'wp_mcp_ai_auth0_jwks_fetch_failed',
				__( 'Auth0 rejected the JWKS request while validating the bearer token.', 'wp-mcp-ai' ),
				array(
					'status'  => 502,
					'actions' => array(
						'retry_request' => __( 'Retry the request once connectivity with Auth0 is restored.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['keys'] ) || ! is_array( $body['keys'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_auth0_jwks_fetch_failed',
				__( 'Auth0 did not return a valid JWKS response.', 'wp-mcp-ai' ),
				array(
					'status'  => 502,
					'actions' => array(
						'retry_request' => __( 'Retry the request once connectivity with Auth0 is restored.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		set_transient( $transient_key, $body['keys'], HOUR_IN_SECONDS );

		return $body['keys'];
	}

	/**
	 * Convert an RSA JWK to a PEM encoded public key.
	 *
	 * @param array $jwk JWK data.
	 * @return string|WP_Error
	 */
	protected function jwk_to_pem( $jwk ) {
		if ( empty( $jwk['kty'] ) || 'RSA' !== $jwk['kty'] || empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
			return $this->invalid_bearer_error();
		}

		$modulus  = $this->base64_url_decode( $jwk['n'] );
		$exponent = $this->base64_url_decode( $jwk['e'] );

		if ( false === $modulus || false === $exponent ) {
			return $this->invalid_bearer_error();
		}

		$modulus  = ltrim( $modulus, "\x00" );
		$exponent = ltrim( $exponent, "\x00" );

		$components = array(
			$this->encode_asn1_integer( $modulus ),
			$this->encode_asn1_integer( $exponent ),
		);

		$sequence   = $this->encode_asn1_sequence( implode( '', $components ) );
		$bitstring  = "\x03" . $this->encode_asn1_length( strlen( "\x00" . $sequence ) ) . "\x00" . $sequence;
		$rsa_oid    = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
		$public_key = $this->encode_asn1_sequence( $rsa_oid . $bitstring );

		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $public_key ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/**
	 * Determine whether the audience claim matches the configured audience.
	 *
	 * @param array  $payload Token payload.
	 * @param string $expected Expected audience string.
	 * @return bool
	 */
	protected function audience_matches( $payload, $expected ) {
		if ( empty( $payload['aud'] ) ) {
			return false;
		}

		if ( is_string( $payload['aud'] ) ) {
			return $payload['aud'] === $expected;
		}

		if ( is_array( $payload['aud'] ) ) {
			return in_array( $expected, $payload['aud'], true );
		}

		return false;
	}

	/**
	 * Determine whether the scope claim satisfies the requirement.
	 *
	 * @param array  $payload Token payload.
	 * @param string $required Scope string required.
	 * @return bool
	 */
	protected function scope_satisfied( $payload, $required ) {
		if ( empty( $required ) ) {
			return true;
		}

		if ( ! empty( $payload['scope'] ) && is_string( $payload['scope'] ) ) {
			$scopes = preg_split( '/\s+/', $payload['scope'] );
			if ( in_array( $required, $scopes, true ) ) {
				return true;
			}
		}

		if ( ! empty( $payload['permissions'] ) && is_array( $payload['permissions'] ) && in_array( $required, $payload['permissions'], true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Decode a base64url string.
	 *
	 * @param string $input Encoded string.
	 * @return string|false
	 */
	protected function base64_url_decode( $input ) {
		$remainder = strlen( $input ) % 4;
		if ( 2 === $remainder ) {
			$input .= '==';
		} elseif ( 3 === $remainder ) {
			$input .= '=';
		} elseif ( 1 === $remainder ) {
			return false;
		}

		$input = strtr( $input, '-_', '+/' );

		return base64_decode( $input );
	}

	/**
	 * Encode an ASN.1 integer.
	 *
	 * @param string $value Integer bytes.
	 * @return string
	 */
	protected function encode_asn1_integer( $value ) {
		if ( '' === $value ) {
			$value = "\x00";
		}

		if ( ord( $value[0] ) > 0x7f ) {
			$value = "\x00" . $value;
		}

		return "\x02" . $this->encode_asn1_length( strlen( $value ) ) . $value;
	}

	/**
	 * Encode an ASN.1 sequence.
	 *
	 * @param string $value Sequence content.
	 * @return string
	 */
	protected function encode_asn1_sequence( $value ) {
		return "\x30" . $this->encode_asn1_length( strlen( $value ) ) . $value;
	}

	/**
	 * Encode an ASN.1 length field.
	 *
	 * @param int $length Length value.
	 * @return string
	 */
	protected function encode_asn1_length( $length ) {
		if ( $length <= 0x7f ) {
			return chr( $length );
		}

		$temp = ltrim( pack( 'N', $length ), "\x00" );

		return chr( 0x80 | strlen( $temp ) ) . $temp;
	}

	/**
	 * Return a standard invalid bearer token error.
	 *
	 * @return WP_Error
	 */
	protected function invalid_bearer_error() {
		return new WP_Error(
			'wp_mcp_ai_invalid_bearer_token',
			__( 'The supplied bearer token is invalid.', 'wp-mcp-ai' ),
			array(
				'status'  => 401,
				'actions' => array(
					'obtain_new_token' => __( 'Request a fresh Auth0 access token and retry the call.', 'wp-mcp-ai' ),
				),
			)
		);
	}

	/**
	 * Handle chat completion requests.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_chat_request( WP_REST_Request $request ) {
		$assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
		$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );
		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		$assistant_id = $scoped_id;

		if ( ! $assistant_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'No assistant was provided and no default assistant is configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$assistant_post = $this->validate_assistant_access( $assistant_id );
		if ( is_wp_error( $assistant_post ) ) {
			return $assistant_post;
		}

		$sanitized_messages = $this->sanitize_messages( $request->get_param( 'messages' ) );
		if ( is_wp_error( $sanitized_messages ) ) {
			return $sanitized_messages;
		}

		$messages    = $sanitized_messages['messages'];
		$attachments = $sanitized_messages['attachments'];

		if ( empty( $messages ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_messages', __( 'Messages must be provided as an array of role/content pairs.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		$options          = $this->sanitize_options( $request->get_param( 'options' ), $assistant_config );
		$tools            = $this->build_tools_payload( $assistant_config );
		if ( is_wp_error( $tools ) ) {
			return $tools;
		}

		$options['tools'] = $tools;

		if ( ! empty( $options['memory_files'] ) ) {
			$memory_documents = $this->prepare_memory_documents( $options['memory_files'] );

			if ( is_wp_error( $memory_documents ) ) {
				return $memory_documents;
			}

			if ( ! empty( $memory_documents ) ) {
				$options['memory_documents'] = $memory_documents;
				$options['memory_files']     = wp_list_pluck( $memory_documents, 'id' );
			} else {
				$options['memory_files'] = array();
			}
		}

		if ( ! empty( $attachments ) ) {
			$options['attachments'] = $attachments;
		}

		$user_id = get_current_user_id();

		/**
		 * Fires before a chat request is sent to the language model.
		 *
		 * @param int              $assistant_id Assistant identifier.
		 * @param array            $messages     Chat messages.
		 * @param array            $options      Prepared options.
		 * @param WP_REST_Request  $request      REST request instance.
		 */
		do_action( 'wp_mcp_ai_before_chat_request', $assistant_id, $messages, $options, $request );

		$options = apply_filters( 'wp_mcp_ai_chat_options', $options, $assistant_config, $request );

		$response = $this->client->create_chat_completion( $messages, $options );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Chat request failed.',
				array(
					'assistant_id' => $assistant_id,
					'user_id'      => $user_id,
					'error_code'   => $response->get_error_code(),
					'error'        => $response->get_error_message(),
				)
			);
			return $response;
		}

		WP_MCP_AI_Logger::log_chat_interaction( $assistant_id, $messages, $options, $response, $user_id );

		/**
		 * Fires after a chat response has been received from the language model.
		 *
		 * @param int              $assistant_id Assistant identifier.
		 * @param array            $response     Raw response array.
		 * @param WP_REST_Request  $request      REST request instance.
		 */
		do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

		return rest_ensure_response(
			array(
				'assistant_id' => $assistant_id,
				'data'         => $response,
			)
		);
	}

	/**
	 * Handle requests to execute a specific tool.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_tool_request( WP_REST_Request $request ) {
		$assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
		$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );
		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		$assistant_id = $scoped_id;

		if ( ! $assistant_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'No assistant was provided and no default assistant is configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$assistant_post = $this->validate_assistant_access( $assistant_id );
		if ( is_wp_error( $assistant_post ) ) {
			return $assistant_post;
		}

		$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		$tool_slug        = sanitize_key( $request->get_param( 'tool' ) );
		$arguments        = $request->get_param( 'arguments' );
		$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

		if ( ! in_array( $tool_slug, $allowed_tools, true ) ) {
			return new WP_Error( 'wp_mcp_ai_tool_forbidden', __( 'This assistant is not allowed to execute the requested tool.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
		}

		$tool = $this->registry->get_tool( $tool_slug );
		if ( ! $tool ) {
			return new WP_Error( 'wp_mcp_ai_tool_missing', __( 'The requested tool is not registered.', 'wp-mcp-ai' ), array( 'status' => 404 ) );
		}

		$auth_context = $this->get_auth_context();
		$user_id      = isset( $auth_context['user_id'] ) ? absint( $auth_context['user_id'] ) : 0;

		$context = array(
			'user_id'          => $user_id,
			'assistant_id'     => $assistant_id,
			'request'          => $request,
			'assistant_config' => $assistant_config,
		);

		if ( ! empty( $auth_context['token_authenticated'] ) ) {
			$context['token_authenticated'] = true;
			$context['token_type']          = $auth_context['token_type'];

			if ( ! empty( $auth_context['token_context'] ) ) {
				$context['token_context'] = $auth_context['token_context'];
			}
		}

		if ( empty( $context['user_id'] ) && empty( $auth_context['token_authenticated'] ) ) {
			return new WP_Error( 'wp_mcp_ai_anonymous_user', __( 'You must be logged in to execute tools.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		/**
		 * Fires immediately before executing a registered tool.
		 *
		 * @param string           $tool_slug Tool identifier.
		 * @param array            $arguments Arguments passed in the request.
		 * @param array            $context   Execution context including user_id and assistant_id.
		 */
		$prepared_arguments = is_array( $arguments ) ? $arguments : array();

		if ( 'run_openai_external_action' === $tool_slug ) {
			if ( empty( $prepared_arguments['action_type'] ) && ! empty( $assistant_config['external_action_type'] ) ) {
				$prepared_arguments['action_type'] = $assistant_config['external_action_type'];
			}

			if ( empty( $prepared_arguments['identifier'] ) && ! empty( $assistant_config['external_action_identifier'] ) ) {
				$prepared_arguments['identifier'] = $assistant_config['external_action_identifier'];
			}
		}

		do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $prepared_arguments, $context );

		$result = $tool->execute( $prepared_arguments, $context );

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $prepared_arguments, $result, $context );
			return $result;
		}

		$result = apply_filters( 'wp_mcp_ai_tool_output', $result, $tool_slug, $prepared_arguments, $context );

		WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $prepared_arguments, $result, $context );

		/**
		 * Fires after a registered tool has completed execution.
		 *
		 * @param string           $tool_slug Tool identifier.
		 * @param array            $arguments Arguments passed in the request.
		 * @param array            $context   Execution context including user_id and assistant_id.
		 * @param mixed            $result    Tool result after filters have been applied.
		 */
		do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $prepared_arguments, $context, $result );

		return rest_ensure_response(
			array(
				'assistant_id' => $assistant_id,
				'tool'         => $tool_slug,
				'result'       => $result,
			)
		);
	}

	/**
	 * Retrieve the assistant ID to use for a request.
	 *
	 * @param mixed $assistant_id Assistant ID from the request.
	 * @return int
	 */
	protected function resolve_assistant_id( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( $assistant_id ) {
			return $assistant_id;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$default  = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;

		return $default;
	}

	/**
	 * Ensure the active assistant aligns with the authenticated token scope.
	 *
	 * @param int $assistant_id Assistant identifier resolved from the request.
	 * @return int|WP_Error Scoped assistant identifier or error when the token cannot access the requested assistant.
	 */
	protected function apply_token_assistant_scope( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		$auth_context = $this->get_auth_context();

		if ( empty( $auth_context['token_authenticated'] ) || 'local_token' !== $auth_context['token_type'] ) {
			return $assistant_id;
		}

		$token_assistant = 0;

		if ( isset( $auth_context['assistant_id'] ) ) {
			$token_assistant = absint( $auth_context['assistant_id'] );
		}

		if ( ! $token_assistant && isset( $auth_context['token_context']['credential']['assistant_id'] ) ) {
			$token_assistant = absint( $auth_context['token_context']['credential']['assistant_id'] );
		}

		if ( ! $token_assistant ) {
			return $assistant_id;
		}

		if ( ! $assistant_id ) {
			return $token_assistant;
		}

		if ( $assistant_id !== $token_assistant ) {
			return new WP_Error(
				'wp_mcp_ai_assistant_scope_mismatch',
				__( 'The provided credential cannot access the requested assistant.', 'wp-mcp-ai' ),
				array(
					'status'  => 403,
					'actions' => array(
						'use_scoped_assistant' => __( 'Retry the request without overriding the assistant or request a credential for the desired assistant.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		return $token_assistant;
	}

	/**
	 * Ensure the current user can access the requested assistant post.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return WP_Post|WP_Error
	 */
	protected function validate_assistant_access( $assistant_id ) {
		$assistant_id   = absint( $assistant_id );
		$assistant_post = $assistant_id ? get_post( $assistant_id ) : null;

		if ( ! $assistant_post || WP_MCP_AI_Assistant_CPT::POST_TYPE !== $assistant_post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_assistant_forbidden',
				__( 'You do not have access to this assistant.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		$token_bypasses_visibility = false;

		$auth_context = $this->get_auth_context();
		if ( ! empty( $auth_context['token_authenticated'] ) && 'local_token' === $auth_context['token_type'] ) {
			$token_assistant = isset( $auth_context['assistant_id'] ) ? absint( $auth_context['assistant_id'] ) : 0;

			if ( ! $token_assistant && isset( $auth_context['token_context']['credential']['assistant_id'] ) ) {
				$token_assistant = absint( $auth_context['token_context']['credential']['assistant_id'] );
			}

			if ( $token_assistant && $token_assistant === $assistant_id ) {
				$token_bypasses_visibility = true;
			}
		}

		if ( 'publish' !== $assistant_post->post_status && ! current_user_can( 'read_post', $assistant_id ) && ! $token_bypasses_visibility ) {
			return new WP_Error(
				'wp_mcp_ai_assistant_forbidden',
				__( 'You do not have access to this assistant.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		return $assistant_post;
	}

	/**
	 * Sanitize the messages payload.
	 *
	 * Allowed roles can be customized via the `wp_mcp_ai_allowed_message_roles` filter.
	 *
	 * @param mixed $messages Raw messages.
	 * @return array|WP_Error
	 */
	protected function sanitize_messages( $messages ) {
		if ( ! is_array( $messages ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_messages', __( 'Messages must be provided as an array of role/content pairs.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$attachments_helper = new WP_MCP_AI_Message_Attachments();
		$sanitized          = array();

		$default_roles = array( 'user', 'assistant', 'system', 'tool' );
		$allowed_roles = apply_filters( 'wp_mcp_ai_allowed_message_roles', $default_roles );

		if ( ! is_array( $allowed_roles ) ) {
			$allowed_roles = $default_roles;
		}

		$allowed_roles = array_values(
			array_filter(
				array_unique(
					array_map( 'sanitize_key', $allowed_roles )
				)
			)
		);

		if ( empty( $allowed_roles ) ) {
			$allowed_roles = $default_roles;
		}

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$raw_role = isset( $message['role'] ) ? $message['role'] : '';
			$role     = sanitize_key( $raw_role );
			if ( empty( $role ) ) {
				continue;
			}

			if ( ! in_array( $role, $allowed_roles, true ) ) {
				$display_role = is_scalar( $raw_role ) ? (string) $raw_role : $role;
				$display_role = sanitize_text_field( $display_role );

				return new WP_Error(
					'wp_mcp_ai_invalid_message_role',
					sprintf(
						/* translators: 1: Provided role, 2: list of supported roles. */
						__( 'The message role "%1$s" is not supported. Supported roles: %2$s.', 'wp-mcp-ai' ),
						$display_role,
						implode( ', ', $allowed_roles )
					),
					array( 'status' => 400 )
				);
			}

			$content  = isset( $message['content'] ) ? $message['content'] : '';
			$segments = $this->sanitize_message_content( $content, $attachments_helper );

			if ( is_wp_error( $segments ) ) {
				return $segments;
			}

			$metadata = $this->sanitize_message_metadata( $message );

			if ( empty( $segments ) && empty( $metadata ) ) {
				continue;
			}

			$sanitized[] = array_merge(
				array(
					'role'    => $role,
					'content' => $segments,
				),
				$metadata
			);
		}

		return array(
			'messages'    => $sanitized,
			'attachments' => $attachments_helper->get_attachments(),
		);
	}

	/**
	 * Sanitize additional metadata attached to a message.
	 *
	 * @param array $message Raw message data.
	 * @return array
	 */
	protected function sanitize_message_metadata( array $message ) {
		$metadata = array();

		if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
			$tool_calls = array();

			foreach ( $message['tool_calls'] as $tool_call ) {
				if ( ! is_array( $tool_call ) ) {
					continue;
				}

				$sanitized_call = array();

				if ( isset( $tool_call['id'] ) ) {
					$sanitized_call['id'] = sanitize_text_field( $tool_call['id'] );
				}

				if ( isset( $tool_call['type'] ) ) {
					$sanitized_call['type'] = sanitize_text_field( $tool_call['type'] );
				}

				if ( isset( $tool_call['function'] ) && is_array( $tool_call['function'] ) ) {
					$function = array();

					if ( isset( $tool_call['function']['name'] ) ) {
						$function['name'] = sanitize_text_field( $tool_call['function']['name'] );
					}

					if ( isset( $tool_call['function']['arguments'] ) ) {
						$function['arguments'] = wp_check_invalid_utf8( (string) $tool_call['function']['arguments'], true );
					}

					if ( ! empty( $function ) ) {
						$sanitized_call['function'] = $function;
					}
				}

				if ( isset( $tool_call['index'] ) ) {
					$sanitized_call['index'] = absint( $tool_call['index'] );
				}

				if ( ! empty( $sanitized_call ) ) {
					$tool_calls[] = $sanitized_call;
				}
			}

			if ( ! empty( $tool_calls ) ) {
				$metadata['tool_calls'] = $tool_calls;
			}
		}

		if ( isset( $message['tool_call_id'] ) ) {
			$metadata['tool_call_id'] = sanitize_text_field( $message['tool_call_id'] );
		}

		if ( isset( $message['name'] ) ) {
			$metadata['name'] = sanitize_text_field( $message['name'] );
		}

		return $metadata;
	}

	/**
	 * Sanitize the content of a single message and normalise into segments.
	 *
	 * @param mixed                         $content             Raw content provided by the client.
	 * @param WP_MCP_AI_Message_Attachments $attachments_helper Attachment helper instance.
	 * @return array|WP_Error
	 */
	protected function sanitize_message_content( $content, WP_MCP_AI_Message_Attachments $attachments_helper ) {
		if ( is_string( $content ) || is_numeric( $content ) ) {
			$segment = $attachments_helper->prepare_input_text_segment( $content );

			return '' === $segment['text'] ? array() : array( $segment );
		}

		if ( empty( $content ) ) {
			return array();
		}

		if ( ! is_array( $content ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_message_content', __( 'Message content must be a string or an array of segments.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$segments = array();

		foreach ( $content as $segment ) {
			if ( is_string( $segment ) || is_numeric( $segment ) ) {
				$prepared = $attachments_helper->prepare_input_text_segment( $segment );

				if ( '' !== $prepared['text'] ) {
					$segments[] = $prepared;
				}

				continue;
			}

			if ( ! is_array( $segment ) ) {
				continue;
			}

			$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : 'text';

			switch ( $type ) {
				case 'text':
				case 'input_text':
					if ( isset( $segment['text'] ) ) {
						$prepared = $attachments_helper->prepare_input_text_segment( $segment['text'] );
					} elseif ( isset( $segment['content'] ) ) {
						$prepared = $attachments_helper->prepare_input_text_segment( $segment['content'] );
					} else {
						$prepared = $attachments_helper->prepare_input_text_segment( '' );
					}

					if ( '' !== $prepared['text'] ) {
						$segments[] = $prepared;
					}
					break;

				case 'input_image':
					$prepared = $attachments_helper->prepare_input_image_segment( $segment );
					if ( is_wp_error( $prepared ) ) {
						return $prepared;
					}
					$segments[] = $prepared;
					break;

				case 'input_file':
					$prepared = $attachments_helper->prepare_input_file_segment( $segment );
					if ( is_wp_error( $prepared ) ) {
						return $prepared;
					}
					$segments[] = $prepared;
					break;

				default:
					return new WP_Error( 'wp_mcp_ai_invalid_message_segment', __( 'One or more message segments use an unsupported type.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}
		}

		return $segments;
	}

	/**
	 * Sanitize request options and merge with assistant defaults.
	 *
	 * @param mixed $options          Raw options from the request.
	 * @param array $assistant_config Assistant configuration array.
	 * @return array
	 */
	protected function sanitize_options( $options, array $assistant_config ) {
		$options = is_array( $options ) ? $options : array();

		$provider = '';
		if ( isset( $options['provider'] ) ) {
			$provider = sanitize_key( $options['provider'] );
		}

		if ( empty( $provider ) && ! empty( $assistant_config['provider'] ) ) {
			$provider = sanitize_key( $assistant_config['provider'] );
		}

		if ( empty( $provider ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$provider = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';
		}

		$allowed_providers = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );
		if ( ! is_array( $allowed_providers ) ) {
			$allowed_providers = array( 'openai', 'gemini' );
		}

		if ( ! in_array( $provider, $allowed_providers, true ) ) {
			$provider = 'openai';
		}

		$options['provider'] = $provider;

		if ( isset( $options['model'] ) ) {
			$options['model'] = sanitize_text_field( $options['model'] );
		}

		if ( empty( $options['model'] ) && ! empty( $assistant_config['model'] ) ) {
			$options['model'] = sanitize_text_field( $assistant_config['model'] );
		}

		$assistant_temperature = ( isset( $assistant_config['temperature'] ) && null !== $assistant_config['temperature'] )
			? floatval( $assistant_config['temperature'] )
			: null;

		$has_request_temperature = array_key_exists( 'temperature', $options );
		$raw_temperature         = $has_request_temperature ? $options['temperature'] : null;

		if ( $has_request_temperature && '' !== $raw_temperature && null !== $raw_temperature ) {
			$temperature = floatval( $raw_temperature );

			if ( ( $temperature < 0 || $temperature > 2 ) && null !== $assistant_temperature ) {
				$temperature = $assistant_temperature;
			}
		} elseif ( ! $has_request_temperature && null !== $assistant_temperature ) {
			$temperature = $assistant_temperature;
		} else {
			$temperature = null;
		}

		if ( null !== $temperature ) {
			$options['temperature'] = (float) max( 0, min( 2, $temperature ) );
		} elseif ( $has_request_temperature ) {
			unset( $options['temperature'] );
		}

		if ( isset( $options['system_prompt'] ) ) {
			$options['system_prompt'] = wp_kses_post( $options['system_prompt'] );
		}

		if ( empty( $options['system_prompt'] ) && ! empty( $assistant_config['system_prompt'] ) ) {
			$options['system_prompt'] = wp_kses_post( $assistant_config['system_prompt'] );
		}

		if ( isset( $options['memory_files'] ) ) {
			$options['memory_files'] = $this->sanitize_memory_files( $options['memory_files'] );
		} elseif ( ! empty( $assistant_config['memory_files'] ) ) {
			$options['memory_files'] = $this->sanitize_memory_files( $assistant_config['memory_files'] );
		} else {
			$options['memory_files'] = array();
		}

		if ( isset( $options['vector_store_id'] ) ) {
			$options['vector_store_id'] = sanitize_text_field( $options['vector_store_id'] );
		} elseif ( isset( $assistant_config['vector_store_id'] ) && '' !== $assistant_config['vector_store_id'] ) {
			$options['vector_store_id'] = sanitize_text_field( $assistant_config['vector_store_id'] );
		} else {
			$options['vector_store_id'] = '';
		}

		if ( isset( $options['response_format'] ) && ! is_array( $options['response_format'] ) ) {
			unset( $options['response_format'] );
		}

		return $options;
	}

	/**
	 * Build the tool payload to send to OpenAI.
	 *
	 * @param array $assistant_config Assistant configuration array.
	 * @return array|WP_Error
	 */
	protected function build_tools_payload( array $assistant_config ) {
		$allowed_tool_slugs = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

		if ( empty( $allowed_tool_slugs ) ) {
			return array();
		}

		$tools_payload = array();
		foreach ( $allowed_tool_slugs as $slug ) {
			$tool = $this->registry->get_tool( $slug );
			if ( ! $tool ) {
				WP_MCP_AI_Admin_Settings::log( 'Assistant references missing tool.', array( 'tool' => $slug ) );
				continue;
			}

			$tools_payload[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $tool->get_slug(),
					'description' => $tool->get_description(),
					'parameters'  => $tool->get_parameters_schema(),
				),
			);
		}

		return $tools_payload;
	}

	/**
	 * Sanitize memory file identifiers.
	 *
	 * @param mixed $files Raw file identifiers.
	 * @return array
	 */
	protected function sanitize_memory_files( $files ) {
		if ( ! is_array( $files ) ) {
			$files = array( $files );
		}

		$sanitized = array();
		foreach ( $files as $file_id ) {
			$file_id = absint( $file_id );
			if ( $file_id ) {
				$sanitized[] = $file_id;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Prepare memory documents for inclusion with a chat request.
	 *
	 * @param array $file_ids Attachment identifiers.
	 * @return array
	 */
	protected function prepare_memory_documents( array $file_ids ) {
		if ( empty( $file_ids ) ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			WP_Filesystem();
		}

		$documents             = array();
		$total_chars           = 0;
		$forbidden_file_ids    = array();
		$encountered_permitted = false;

		foreach ( $file_ids as $file_id ) {
			$file_id = absint( $file_id );
			if ( ! $file_id ) {
				continue;
			}

			$attachment = get_post( $file_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				continue;
			}

			if ( ! WP_MCP_AI_Message_Attachments::user_can_access_attachment( $file_id ) ) {
				$forbidden_file_ids[] = $file_id;
				continue;
			}

			$encountered_permitted = true;

			$file_path = get_attached_file( $file_id );
			if ( ! $file_path ) {
				continue;
			}

			$mime_type = get_post_mime_type( $file_id );
			$raw_text  = $this->extract_memory_text( $file_path, $mime_type );

			if ( '' === $raw_text ) {
				continue;
			}

			$normalized = $this->normalize_memory_text( $raw_text, $mime_type );
			if ( '' === $normalized ) {
				continue;
			}

			$chunk_data = $this->chunk_memory_text( $normalized, $total_chars );

			if ( empty( $chunk_data['chunks'] ) ) {
				continue;
			}

			$total_chars = $chunk_data['total_chars'];

			$documents[] = array(
				'id'        => $file_id,
				'title'     => get_the_title( $attachment ),
				'mime_type' => $mime_type,
				'chunks'    => $chunk_data['chunks'],
				'truncated' => $chunk_data['truncated'],
			);

			if ( $total_chars >= self::MEMORY_MAX_TOTAL_CHARS ) {
				break;
			}
		}

		if ( empty( $documents ) && ! $encountered_permitted && ! empty( $forbidden_file_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_memory_files_forbidden',
				__( 'You do not have permission to use the requested memory files.', 'wp-mcp-ai' ),
				array(
					'status'        => 403,
					'forbidden_ids' => array_values( array_unique( $forbidden_file_ids ) ),
				)
			);
		}

		return $documents;
	}

	/**
	 * Extract text content from an attachment.
	 *
	 * @param string $file_path File system path.
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	protected function extract_memory_text( $file_path, $mime_type ) {
		if ( 'application/pdf' === $mime_type ) {
			if ( function_exists( 'wp_read_pdf' ) ) {
				$pdf_content = wp_read_pdf( $file_path );

				if ( is_array( $pdf_content ) && isset( $pdf_content['text'] ) ) {
					return (string) $pdf_content['text'];
				}

				if ( is_string( $pdf_content ) ) {
					return $pdf_content;
				}
			}

			return '';
		}

		$docx_mimes = array(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
			'application/vnd.ms-word.document.macroEnabled.12',
			'application/vnd.ms-word.template.macroEnabled.12',
		);

		if ( in_array( $mime_type, $docx_mimes, true ) ) {
			return $this->extract_docx_text( $file_path );
		}

		$textual_mimes = array(
			'text/',
			'application/json',
			'application/javascript',
			'application/xml',
			'application/rss+xml',
			'application/xhtml+xml',
		);

		$is_textual = 0 === strpos( $mime_type, 'text/' ) || in_array( $mime_type, $textual_mimes, true );

		if ( ! $is_textual ) {
			return '';
		}

		return (string) $this->read_file_contents( $file_path );
	}

	/**
	 * Extract text from a DOCX-based file.
	 *
	 * @param string $file_path File system path.
	 * @return string
	 */
	protected function extract_docx_text( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		$zip    = new ZipArchive();
		$opened = $zip->open( $file_path );

		if ( true !== $opened ) {
			return '';
		}

		$flags = 0;

		if ( defined( 'ZipArchive::FL_NOCASE' ) ) {
			$flags |= ZipArchive::FL_NOCASE;
		}

		if ( defined( 'ZipArchive::FL_NODIR' ) ) {
			$flags |= ZipArchive::FL_NODIR;
		}

		$document_index = $zip->locateName( 'word/document.xml', $flags );

		if ( false === $document_index && 0 !== $flags ) {
			$document_index = $zip->locateName( 'word/document.xml' );
		}

		if ( false === $document_index ) {
			$zip->close();
			return '';
		}

		$xml_content = $zip->getFromIndex( $document_index );
		$zip->close();

		if ( false === $xml_content || '' === $xml_content ) {
			return '';
		}

		$xml_content = preg_replace( '/<w:br[^>]*\/>/i', "\n", $xml_content );
		$xml_content = preg_replace( '/<w:cr[^>]*\/>/i', "\n", $xml_content );
		$xml_content = preg_replace( '/<w:tab[^>]*\/>/i', "\t", $xml_content );
		$xml_content = preg_replace( '/<\/w:p>/i', "</w:p>\n", $xml_content );

		$previous = libxml_use_internal_errors( true );

		$document                     = new DOMDocument();
		$document->preserveWhiteSpace = false;
		$loaded                       = $document->loadXML( $xml_content );

		if ( ! $loaded ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return '';
		}

		$text = $document->textContent;

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! is_string( $text ) ) {
			return '';
		}

		return trim( $text );
	}

	/**
	 * Read a file from disk using the WordPress filesystem when available.
	 *
	 * @param string $file_path File path.
	 * @return string
	 */
	protected function read_file_contents( $file_path ) {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base && $wp_filesystem->exists( $file_path ) ) {
			$contents = $wp_filesystem->get_contents( $file_path );
			return is_string( $contents ) ? $contents : '';
		}

		if ( is_readable( $file_path ) ) {
			return (string) file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return '';
	}

	/**
	 * Normalise extracted text for downstream processing.
	 *
	 * @param string $text      Raw text.
	 * @param string $mime_type MIME type of the file.
	 * @return string
	 */
	protected function normalize_memory_text( $text, $mime_type ) {
		$text = (string) $text;

		if ( 'text/html' === $mime_type ) {
			$text = wp_strip_all_tags( $text );
		}

		// Remove null bytes first to prevent "Null byte in regex" errors.
		$text = str_replace( "\x00", '', $text );

		$text = preg_replace( "/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/", ' ', $text );
		$text = preg_replace( "/\r\n|\r/", "\n", $text );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Chunk and truncate text to the configured limits.
	 *
	 * @param string $text          Normalized text.
	 * @param int    $current_total Characters already accounted for in this request.
	 * @return array
	 */
	protected function chunk_memory_text( $text, &$current_total ) {
		$available_total = max( 0, self::MEMORY_MAX_TOTAL_CHARS - $current_total );

		if ( $available_total <= 0 ) {
			return array(
				'chunks'      => array(),
				'total_chars' => $current_total,
				'truncated'   => true,
			);
		}

		$length = $this->mb_strlen( $text );
		$limit  = min( $available_total, min( $length, self::MEMORY_MAX_DOCUMENT_CHARS ) );

		$chunks = array();

		for ( $offset = 0; $offset < $limit; $offset += self::MEMORY_CHUNK_CHARS ) {
			$remaining = $limit - $offset;
			$take      = min( self::MEMORY_CHUNK_CHARS, $remaining );
			$chunk     = trim( $this->mb_substr( $text, $offset, $take ) );

			if ( '' !== $chunk ) {
				$chunks[] = $chunk;
			}
		}

		$truncated = $limit < $length;

		if ( $truncated && ! empty( $chunks ) ) {
			$chunks[ count( $chunks ) - 1 ] .= "\n\n[" . __( 'Truncated', 'wp-mcp-ai' ) . ']';
		}

		$current_total += $limit;

		return array(
			'chunks'      => array_values( $chunks ),
			'total_chars' => $current_total,
			'truncated'   => $truncated,
		);
	}

	/**
	 * Multibyte-safe string length helper.
	 *
	 * @param string $string String to measure.
	 * @return int
	 */
	protected function mb_strlen( $string ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $string ) : strlen( $string );
	}

	/**
	 * Multibyte-safe substring helper.
	 *
	 * @param string $string Input string.
	 * @param int    $start  Start position.
	 * @param int    $length Length of substring.
	 * @return string
	 */
	protected function mb_substr( $string, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $string, $start, $length ) : substr( $string, $start, $length );
	}
}
