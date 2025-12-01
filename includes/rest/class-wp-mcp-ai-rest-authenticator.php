<?php
/**
 * REST API Authentication Handler
 *
 * Extracted from WP_MCP_AI_REST to isolate authentication logic.
 * Handles multiple authentication methods:
 * - WordPress nonces
 * - Local assistant credentials (bearer tokens)
 * - Mesh network API keys
 * - Auth0 bearer tokens
 * - Guest tokens
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles authentication and authorization for REST API requests.
 */
class WP_MCP_AI_REST_Authenticator {

	/**
	 * Tracks authentication details for the current request.
	 *
	 * @var array
	 */
	protected $auth_context = array();

	/**
	 * Reset authentication context to initial state.
	 *
	 * @return void
	 */
	public function reset_auth_context() {
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
	 * @return void
	 */
	public function mark_token_authenticated( $type, $context = array() ) {
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
	 * @return void
	 */
	public function set_authenticated_user_id( $user_id ) {
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
	 * @return void
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
	public function get_auth_context() {
		if ( empty( $this->auth_context ) ) {
			$this->reset_auth_context();
		}

		return $this->auth_context;
	}

	/**
	 * Validate a local assistant credential token.
	 *
	 * @param string          $token   Bearer token.
	 * @param WP_REST_Request $request REST request.
	 * @param int             $assistant_hint Optional assistant ID hint.
	 * @return true|WP_Error|null Null if not a local token format.
	 */
	public function validate_local_token( $token, WP_REST_Request $request, $assistant_hint = 0 ) {
		if ( ! WP_MCP_AI_Credentials::is_token_format( $token ) ) {
			return null;
		}

		$validated = WP_MCP_AI_Credentials::validate_token( $token, $assistant_hint );

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
	 * Validate a mesh network API key.
	 *
	 * @param string $key The mesh API key to validate.
	 * @return true|WP_Error
	 */
	public function validate_mesh_key( $key ) {
		if ( empty( $key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_mesh_key',
				__( 'Mesh API key is missing.', 'wp-mcp-ai' ),
				array( 'status' => 401 )
			);
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// Check if mesh networking is enabled.
		if ( empty( $settings['enable_mesh'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mesh_disabled',
				__( 'Mesh networking is not enabled on this site.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Validate the key against the stored inbound API key.
		$inbound_key = isset( $settings['mesh_inbound_api_key'] ) ? $settings['mesh_inbound_api_key'] : '';

		if ( empty( $inbound_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_mesh_not_configured',
				__( 'Mesh networking inbound API key is not configured.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Use hash_equals to prevent timing attacks.
		if ( ! hash_equals( $inbound_key, $key ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_mesh_key',
				__( 'Invalid mesh API key.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate an Auth0 bearer token.
	 *
	 * @param string          $token   Raw bearer token string.
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error
	 */
	public function validate_bearer_token( $token, WP_REST_Request $request ) {
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
			} elseif ( is_array( $pre ) ) {
				$pre_payload = $pre;
				$pre_context = array();

				if ( isset( $pre['payload'] ) && is_array( $pre['payload'] ) ) {
					$pre_payload = $pre['payload'];
				}

				if ( isset( $pre['context'] ) && is_array( $pre['context'] ) ) {
					$pre_context = $pre['context'];
				}

				/**
				 * Filter the decoded bearer token payload after it has been validated.
				 *
				 * Returning a WP_Error will reject the request with that error.
				 *
				 * @param array            $payload Decoded JWT payload.
				 * @param WP_REST_Request  $request Current REST request.
				 */
				$filtered_payload = apply_filters( 'wp_mcp_ai_bearer_token_payload', $pre_payload, $request );
				if ( $filtered_payload instanceof WP_Error ) {
					return $filtered_payload;
				}

				$initial_user = null;
				if ( isset( $pre_context['user_id'] ) && is_numeric( $pre_context['user_id'] ) && (int) $pre_context['user_id'] > 0 ) {
					$initial_user = absint( $pre_context['user_id'] );
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
				$mapped_user = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', $initial_user, $filtered_payload, $request );
				if ( $mapped_user instanceof WP_Error ) {
					return $mapped_user;
				}

				$context            = $pre_context;
				$context['payload'] = $filtered_payload;

				if ( is_numeric( $mapped_user ) && (int) $mapped_user > 0 ) {
					$context['user_id'] = absint( $mapped_user );
					$this->set_authenticated_user_id( $context['user_id'] );
				} elseif ( $initial_user ) {
					$this->set_authenticated_user_id( $initial_user );
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
				__( 'Auth0 authentication is not configured. Set the Auth0 domain in the WP oOS settings screen.', 'wp-mcp-ai' ),
				array(
					'status'  => 500,
					'actions' => array(
						'configure_auth0_domain' => __( 'In WordPress, visit Settings → WP oOS and provide the Auth0 domain.', 'wp-mcp-ai' ),
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
	 * Build a consistent error response when the authenticated user lacks access.
	 *
	 * @param string $capability Required capability name.
	 * @return WP_Error
	 */
	public function insufficient_permissions_error( $capability = 'edit_posts' ) {
		if ( is_string( $capability ) ) {
			$capability = sanitize_key( $capability );
		}

		return new WP_Error(
			'wp_mcp_ai_insufficient_permissions',
			sprintf(
				/* translators: %s: WordPress capability name */
				__( 'The authenticated user cannot access the WP oOS API. Grant the account the "%s" capability or switch to another user.', 'wp-mcp-ai' ),
				$capability
			),
			array(
				'status'  => 403,
				'actions' => array(
					'grant_capability' => sprintf(
						/* translators: %s: WordPress capability name */
						__( 'Assign a role that includes the "%s" capability.', 'wp-mcp-ai' ),
						$capability
					),
				),
			)
		);
	}

	/**
	 * Extract guest token from request headers or parameters.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string Guest token or empty string.
	 */
	public function extract_guest_token( WP_REST_Request $request ) {
		$token = $request->get_header( 'X-WP-MCP-AI-Guest' );

		if ( ! $token ) {
			$token = $request->get_param( 'guest_token' );
		}

		if ( is_string( $token ) ) {
			return trim( $token );
		}

		return '';
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
}
