<?php
/**
 * OAuth 2.0 REST Endpoints for MCP
 *
 * Registers REST API routes that implement the MCP Authorization
 * Specification 2025-06-18:
 *
 *  - GET  /.well-known/oauth-authorization-server  – RFC 8414 metadata
 *  - POST /mcp-ai/v1/oauth/register                 – RFC 7591 DCR
 *  - GET  /mcp-ai/v1/oauth/authorize                – authorization endpoint
 *  - POST /mcp-ai/v1/oauth/token                    – token endpoint
 *  - POST /mcp-ai/v1/oauth/revoke                   – revocation endpoint
 *  - GET  /mcp-ai/v1/.well-known/oauth-protected-resource – RFC 9728
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for MCP OAuth endpoints.
 */
class WP_MCP_AI_OAuth_REST {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_well_known' ), 5 );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = 'mcp-ai/v1';

		// RFC 8414 — OAuth 2.0 Authorization Server Metadata.
		register_rest_route(
			$namespace,
			'/oauth/metadata',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_metadata' ),
					'permission_callback' => '__return_true',
				),
			),
			true
		);

		// RFC 9728 — Protected Resource Metadata.
		register_rest_route(
			$namespace,
			'/.well-known/oauth-protected-resource',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_protected_resource_metadata' ),
					'permission_callback' => '__return_true',
				),
			),
			true
		);

		// RFC 7591 — Dynamic Client Registration.
		register_rest_route(
			$namespace,
			'/oauth/register',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_register' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'client_name'   => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'redirect_uris' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type'   => 'string',
								'format' => 'uri',
							),
						),
					),
				),
			),
			true
		);

		// Authorization endpoint — requires user to be logged in. GET renders
		// the consent page for browser requests; POST carries the Allow/Deny
		// consent decision, nonce-bound to the authorization request.
		$authorize_args = array(
			'response_type'         => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => array( 'code' ),
			),
			'client_id'             => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'redirect_uri'          => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
			),
			'code_challenge'        => array(
				'type'     => 'string',
				'required' => true,
			),
			'code_challenge_method' => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => array( 'S256' ),
			),
			'scope'                 => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'resource'              => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
			),
			'state'                 => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'consent'               => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
		);

		register_rest_route(
			$namespace,
			'/oauth/authorize',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_authorize' ),
					'permission_callback' => array( $this, 'check_authorize_permission' ),
					'args'                => $authorize_args,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_authorize' ),
					'permission_callback' => array( $this, 'check_authorize_permission' ),
					'args'                => $authorize_args,
				),
			),
			true
		);

		// Token endpoint.
		register_rest_route(
			$namespace,
			'/oauth/token',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_token' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'grant_type'    => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'authorization_code', 'refresh_token' ),
						),
						'code'          => array(
							'type'     => 'string',
							'required' => false,
						),
						'redirect_uri'  => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'esc_url_raw',
						),
						'code_verifier' => array(
							'type'     => 'string',
							'required' => false,
						),
						'refresh_token' => array(
							'type'     => 'string',
							'required' => false,
						),
						'resource'      => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'esc_url_raw',
						),
					),
				),
			),
			true
		);

		// Revocation endpoint.
		register_rest_route(
			$namespace,
			'/oauth/revoke',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_revoke' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			),
			true
		);
	}

	/**
	 * Add rewrite rules for the well-known endpoints.
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/?$',
			'index.php?wp_mcp_ai_oauth_server_meta=1',
			'top'
		);
	}

	/**
	 * Register query vars for well-known endpoints.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'wp_mcp_ai_oauth_server_meta';
		return $vars;
	}

	/**
	 * Serve well-known endpoints on template_redirect.
	 *
	 * @return void
	 */
	public function handle_well_known() {
		if ( get_query_var( 'wp_mcp_ai_oauth_server_meta' ) ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: public, max-age=3600' );

			if ( class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
				echo wp_json_encode(
					WP_MCP_AI_OAuth_Server::get_instance()->get_metadata(),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				);
			} else {
				wp_send_json_error( array( 'message' => 'OAuth server is not available.' ), 404 );
			}
			exit;
		}
	}

	// -----------------------------------------------------------------------
	// Callbacks
	// -----------------------------------------------------------------------

	/**
	 * Handle OAuth metadata request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_metadata( $request ) {
			unset( $request ); // Required by WP REST API callback signature.
		if ( ! class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
			return new WP_REST_Response(
				array( 'error' => 'OAuth server is not available.' ),
				404
			);
		}

		$response = new WP_REST_Response(
			WP_MCP_AI_OAuth_Server::get_instance()->get_metadata(),
			200
		);
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		return $response;
	}

	/**
	 * Handle protected resource metadata (RFC 9728).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_protected_resource_metadata( $request ) {
			unset( $request ); // Required by WP REST API callback signature.
		if ( ! class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
			return new WP_REST_Response(
				array( 'error' => 'OAuth server is not available.' ),
				404
			);
		}

		$response = new WP_REST_Response(
			WP_MCP_AI_OAuth_Server::get_instance()->get_protected_resource_metadata(),
			200
		);
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		return $response;
	}

	/**
	 * Handle client registration (RFC 7591).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_register( $request ) {
		// Gate: check if open registration is disabled (1.2.0).
		if ( self::is_open_registration_disabled() ) {
			return new WP_Error(
				'registration_disabled',
				__( 'Open OAuth client registration is disabled. Please contact the site administrator.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
			return new WP_Error( 'server_error', __( 'OAuth server is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] )
			? $body['redirect_uris']
			: array();

		if ( empty( $redirect_uris ) ) {
			return new WP_Error(
				'invalid_client_metadata',
				__( 'At least one redirect_uri is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$client_id = isset( $body['client_id'] ) ? sanitize_key( $body['client_id'] ) : '';

		// MCP clients may provide a pre-generated client_id via the
		// client_id_issued_at parameter (RFC 7591 §3.2.1). We accept it
		// as long as the client isn't already registered.
		$metadata = array(
			'client_name'   => isset( $body['client_name'] ) ? $body['client_name'] : 'MCP Client',
			'redirect_uris' => $redirect_uris,
		);

		$result = WP_MCP_AI_OAuth_Server::get_instance()->register_client( $metadata );

		if ( is_wp_error( $result ) ) {
			// Log failed registration attempts (SEC-4-002 audit).
			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					'warning',
					sprintf(
						'OAuth client registration failed: %s (IP: %s)',
						$result->get_error_message(),
						self::get_client_ip()
					)
				);
			}
			return $result;
		}

		// Log successful registrations for audit trail.
		if ( function_exists( 'wp_mcp_ai_log' ) ) {
			$client_name   = isset( $metadata['client_name'] ) ? $metadata['client_name'] : 'Unknown';
			$new_client_id = isset( $result['client_id'] ) ? $result['client_id'] : '';
			wp_mcp_ai_log(
				'info',
				sprintf(
					'OAuth client registered: %s (%s, IP: %s)',
					$client_name,
					$new_client_id,
					self::get_client_ip()
				)
			);
		}

		$response = new WP_REST_Response( $result, 201 );
		return $response;
	}

	/**
	 * Handle authorization request.
	 *
	 * Requires the user to be logged into WordPress. Generates an authorization
	 * code and redirects to the client's redirect URI.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_authorize( $request ) {
		if ( ! class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
			return new WP_Error( 'server_error', __( 'OAuth server is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$oauth          = WP_MCP_AI_OAuth_Server::get_instance();
		$client_id      = sanitize_key( $request->get_param( 'client_id' ) );
		$redirect_uri   = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );
		$code_challenge = $request->get_param( 'code_challenge' );
		$scope          = sanitize_text_field( (string) $request->get_param( 'scope' ) );
		$resource       = esc_url_raw( (string) $request->get_param( 'resource' ) );
		$state          = sanitize_text_field( (string) $request->get_param( 'state' ) );

		// redirect_uri is required (RFC 6749 §3.1.2).
		if ( '' === $redirect_uri ) {
			return new WP_Error(
				'invalid_request',
				__( 'redirect_uri is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Resolve the registered redirect URI list. Unknown clients may only
		// proceed as ephemeral loopback clients (RFC 8252 native apps) and
		// only while open registration is enabled.
		if ( $oauth->is_client_registered( $client_id ) ) {
			$allowed_uris = $oauth->get_client_redirect_uris( $client_id );
		} else {
			if ( self::is_open_registration_disabled() ) {
				return new WP_Error(
					'invalid_client',
					__( 'Unknown client. Open registration is disabled.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 401 )
				);
			}

			if ( ! WP_MCP_AI_OAuth_Server::is_loopback_redirect_uri( $redirect_uri ) ) {
				return new WP_Error(
					'invalid_client',
					__( 'Unknown client. The redirect URI must be registered for this client.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 401 )
				);
			}

			$allowed_uris = array( $redirect_uri );
		}

		// Exact string match only (RFC 9700 §4.1.3). An empty registered
		// list rejects every request — it is never a wildcard.
		if ( ! in_array( $redirect_uri, $allowed_uris, true ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Redirect URI is not registered for this client.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Validate PKCE challenge.
		if ( empty( $code_challenge ) || 43 > strlen( $code_challenge ) || 128 < strlen( $code_challenge ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Invalid code_challenge. Must be a Base64URL-encoded SHA-256 hash (43-128 characters).', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Reject unknown scopes (RFC 9700 §2.2.1).
		$scope_check = WP_MCP_AI_OAuth_Server::validate_scope_string( $scope );
		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		// Resource indicators must be HTTPS (loopback HTTP allowed).
		if ( '' !== $resource ) {
			$resource_check = WP_MCP_AI_OAuth_Server::validate_redirect_uri( $resource );
			if ( is_wp_error( $resource_check ) ) {
				return new WP_Error(
					'invalid_target',
					$resource_check->get_error_message(),
					array( 'status' => 400 )
				);
			}
		}

		// Browser requests go through an explicit consent step before a code
		// is issued; authenticated non-browser requests proceed directly.
		if ( $this->is_browser_request() ) {
			return $this->handle_consent_step( $request, $oauth, $client_id, $redirect_uri, $code_challenge, $state );
		}

		// Generate authorization code.
		$code = $oauth->generate_auth_code(
			get_current_user_id(),
			$client_id,
			$redirect_uri,
			$code_challenge,
			$resource,
			$scope
		);

		return $this->build_authorize_redirect( $redirect_uri, $code, $state );
	}

	/**
	 * Whether the current request advertises an HTML-capable (browser) client.
	 *
	 * @since 1.2.8
	 *
	 * @return bool
	 */
	protected function is_browser_request() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

		return false !== strpos( $accept, 'text/html' );
	}

	/**
	 * Consent step for browser authorization requests: GET renders the
	 * consent page, POST processes the Allow/Deny decision.
	 *
	 * @since 1.2.8
	 *
	 * @param WP_REST_Request        $request        REST request.
	 * @param WP_MCP_AI_OAuth_Server $oauth         OAuth server instance.
	 * @param string                 $client_id      Client ID.
	 * @param string                 $redirect_uri   Validated redirect URI.
	 * @param string                 $code_challenge PKCE challenge.
	 * @param string                 $state          Client state parameter (may be empty).
	 * @return WP_REST_Response|WP_Error
	 */
	protected function handle_consent_step( $request, $oauth, $client_id, $redirect_uri, $code_challenge, $state ) {
		$client       = $oauth->get_client( $client_id );
		$client_name  = ( $client && ! empty( $client['client_name'] ) ) ? $client['client_name'] : __( 'MCP Client', 'mcp-ai-wpoos-pro' );
		$nonce_action = $this->get_consent_nonce_action( $client_id, $redirect_uri, $code_challenge );

		if ( 'POST' === strtoupper( $request->get_method() ) ) {
			$consent = sanitize_key( (string) $request->get_param( 'consent' ) );
			$nonce   = (string) $request->get_param( '_wpnonce' );

			if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
				return new WP_Error(
					'invalid_request',
					__( 'Consent verification failed. Please return to the application and try again.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}

			if ( 'deny' === $consent ) {
				// RFC 6749 §4.1.2.1 — redirect back with access_denied.
				return $this->build_authorize_error_redirect( $redirect_uri, 'access_denied', $state );
			}

			if ( 'allow' !== $consent ) {
				return new WP_Error(
					'invalid_request',
					__( 'Invalid consent decision.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}
		} else {
			$nonce    = wp_create_nonce( $nonce_action );
			$response = new WP_REST_Response( $this->render_consent_page( $request, $client_name, $nonce ), 200 );
			$response->header( 'Content-Type', 'text/html; charset=UTF-8' );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		// POST + allow: issue the authorization code and redirect.
		$user_id  = get_current_user_id();
		$scope    = sanitize_text_field( (string) $request->get_param( 'scope' ) );
		$resource = esc_url_raw( (string) $request->get_param( 'resource' ) );
		$code     = $oauth->generate_auth_code( $user_id, $client_id, $redirect_uri, $code_challenge, $resource, $scope );

		return $this->build_authorize_redirect( $redirect_uri, $code, $state );
	}

	/**
	 * Nonce action binding a consent decision to one authorization request.
	 *
	 * @since 1.2.8
	 *
	 * @param string $client_id      Client ID.
	 * @param string $redirect_uri   Validated redirect URI.
	 * @param string $code_challenge PKCE challenge.
	 * @return string
	 */
	public function get_consent_nonce_action( $client_id, $redirect_uri, $code_challenge ) {
		return 'wp_mcp_ai_oauth_consent_' . md5( $client_id . '|' . $redirect_uri . '|' . $code_challenge );
	}

	/**
	 * Render the authorization consent page.
	 *
	 * @since 1.2.8
	 *
	 * @param WP_REST_Request $request     REST request.
	 * @param string          $client_name Display name of the requesting client.
	 * @param string          $nonce       Consent nonce for the Allow/Deny POST.
	 * @return string HTML document.
	 */
	protected function render_consent_page( $request, $client_name, $nonce ) {
		$redirect_uri = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );
		$scope        = sanitize_text_field( (string) $request->get_param( 'scope' ) );
		$requested    = ( '' === $scope )
			? array( WP_MCP_AI_OAuth_Server::SCOPE_MCP )
			: array_filter( array_map( 'trim', explode( ' ', $scope ) ) );
		$supported    = WP_MCP_AI_OAuth_Server::get_supported_scopes();

		$scope_rows = '';
		foreach ( $requested as $requested_scope ) {
			$description = isset( $supported[ $requested_scope ] ) ? $supported[ $requested_scope ]['description'] : $requested_scope;
			$scope_rows .= '<li><code>' . esc_html( $requested_scope ) . '</code> &mdash; ' . esc_html( $description ) . "</li>\n";
		}

		$hidden_fields = '';
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'scope', 'resource', 'state' ) as $field ) {
			$value = (string) $request->get_param( $field );
			if ( '' !== $value ) {
				$hidden_fields .= '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '" />' . "\n";
			}
		}
		$hidden_fields .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />' . "\n";

		$form_action = esc_url( rest_url( 'mcp-ai/v1/oauth/authorize' ) );

		return sprintf(
			'<!DOCTYPE html>
<html lang="%1$s">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>%2$s</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;margin:0;padding:48px 16px;}
.card{max-width:520px;margin:0 auto;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:32px;}
h1{font-size:22px;margin:0 0 8px;}
p{font-size:14px;line-height:1.6;margin:8px 0;}
code{background:#f6f7f7;padding:2px 6px;border-radius:3px;font-size:13px;}
ul{font-size:14px;line-height:1.6;padding-left:20px;}
.actions{margin-top:24px;}
button{font-size:14px;padding:8px 16px;border-radius:3px;cursor:pointer;}
.allow{background:#2271b1;border:1px solid #2271b1;color:#fff;}
.deny{background:#fff;border:1px solid #c3c4c7;color:#1d2327;margin-left:8px;}
.uri{word-break:break-all;}
</style>
</head>
<body>
<div class="card">
<h1>%2$s</h1>
<p>%3$s</p>
<p><strong>%4$s</strong></p>
<ul>%5$s</ul>
<p>%6$s <span class="uri"><code>%7$s</code></span></p>
<form method="post" action="%8$s">
%9$s
<div class="actions">
<button type="submit" name="consent" value="allow" class="allow">%10$s</button>
<button type="submit" name="consent" value="deny" class="deny">%11$s</button>
</div>
</form>
</div>
</body>
</html>',
			esc_attr( get_bloginfo( 'language' ) ),
			esc_html( __( 'Authorize application', 'mcp-ai-wpoos-pro' ) ),
			esc_html(
				sprintf(
					/* translators: 1: Client name, 2: Site name. */
					__( '%1$s wants to connect to your account on %2$s.', 'mcp-ai-wpoos-pro' ),
					$client_name,
					get_bloginfo( 'name' )
				)
			),
			esc_html( __( 'This application is requesting access to:', 'mcp-ai-wpoos-pro' ) ),
			$scope_rows,
			esc_html( __( 'After you authorize, you will be redirected to:', 'mcp-ai-wpoos-pro' ) ),
			esc_html( $redirect_uri ),
			$form_action,
			$hidden_fields,
			esc_html( __( 'Allow', 'mcp-ai-wpoos-pro' ) ),
			esc_html( __( 'Deny', 'mcp-ai-wpoos-pro' ) )
		);
	}

	/**
	 * Build the 302 response redirecting to the client's validated redirect
	 * URI with a fresh authorization code.
	 *
	 * @since 1.2.8
	 *
	 * @param string $redirect_uri Exact-matched client redirect URI.
	 * @param string $code         Authorization code.
	 * @param string $state        Client state parameter (may be empty).
	 * @return WP_REST_Response
	 */
	protected function build_authorize_redirect( $redirect_uri, $code, $state ) {
		$redirect_params = array( 'code' => $code );
		if ( ! empty( $state ) ) {
			$redirect_params['state'] = $state;
		}

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', add_query_arg( $redirect_params, $redirect_uri ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Build the 302 error response redirecting to the client's validated
	 * redirect URI (RFC 6749 §4.1.2.1).
	 *
	 * @since 1.2.8
	 *
	 * @param string $redirect_uri Exact-matched client redirect URI.
	 * @param string $error        OAuth error code.
	 * @param string $state        Client state parameter (may be empty).
	 * @return WP_REST_Response
	 */
	protected function build_authorize_error_redirect( $redirect_uri, $error, $state ) {
		$redirect_params = array( 'error' => $error );
		if ( ! empty( $state ) ) {
			$redirect_params['state'] = $state;
		}

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', add_query_arg( $redirect_params, $redirect_uri ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Handle token exchange.
	 *
	 * Supports authorization_code and refresh_token grant types with PKCE.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_token( $request ) {
		// Gate: rate limit the token endpoint (1.2.0).
		$rate_check = self::check_token_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		if ( ! class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
			return new WP_Error( 'server_error', __( 'OAuth server is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$oauth      = WP_MCP_AI_OAuth_Server::get_instance();
		$grant_type = $request->get_param( 'grant_type' );
		$resource   = esc_url_raw( (string) $request->get_param( 'resource' ) );

		if ( 'authorization_code' === $grant_type ) {
			$code          = (string) $request->get_param( 'code' );
			$code_verifier = (string) $request->get_param( 'code_verifier' );
			$redirect_uri  = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );

			if ( empty( $code ) || empty( $code_verifier ) ) {
				return new WP_Error(
					'invalid_request',
					__( 'Both code and code_verifier are required.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			$entry = $oauth->exchange_code( $code, $code_verifier );
			if ( is_wp_error( $entry ) ) {
				// Log failed token exchanges for audit (SEC-4-001).
				if ( function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log(
						'warning',
						sprintf(
							'OAuth token exchange failed (%s): %s (IP: %s)',
							$grant_type,
							$entry->get_error_message(),
							self::get_client_ip()
						)
					);
				}
				return $entry;
			}

			// The token request's redirect_uri (when supplied) must match the
			// one stored with the authorization code (RFC 6749 §4.1.3).
			if ( '' !== $redirect_uri && ! empty( $entry['redirect_uri'] ) && ! hash_equals( (string) $entry['redirect_uri'], $redirect_uri ) ) {
				return new WP_Error(
					'invalid_grant',
					__( 'redirect_uri does not match the authorization request.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			$audience = '';
			if ( ! empty( $resource ) ) {
				$audience = $resource;
			} elseif ( ! empty( $entry['audience'] ) ) {
				$audience = $entry['audience'];
			} else {
				$audience = rest_url( 'mcp-ai/v1/mcp' );
			}

			$result = $oauth->issue_tokens(
				$entry['user_id'],
				$audience,
				isset( $entry['scope'] ) ? $entry['scope'] : null
			);

			$response = new WP_REST_Response( $result, 200 );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		if ( 'refresh_token' === $grant_type ) {
			$refresh_token = (string) $request->get_param( 'refresh_token' );

			if ( empty( $refresh_token ) ) {
				return new WP_Error(
					'invalid_request',
					__( 'refresh_token is required.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			$result = $oauth->refresh_access_token( $refresh_token );
			if ( is_wp_error( $result ) ) {
				// Log failed refresh attempts for audit (SEC-4-001).
				if ( function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log(
						'warning',
						sprintf(
							'OAuth token refresh failed: %s (IP: %s)',
							$result->get_error_message(),
							self::get_client_ip()
						)
					);
				}
				return $result;
			}

			$response = new WP_REST_Response( $result, 200 );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		return new WP_Error(
			'unsupported_grant_type',
			__( 'Grant type must be authorization_code or refresh_token.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Handle token revocation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_revoke( $request ) {
		if ( ! class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
			return new WP_Error( 'server_error', __( 'OAuth server is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$token = (string) $request->get_param( 'token' );
		if ( empty( $token ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'token parameter is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		WP_MCP_AI_OAuth_Server::get_instance()->revoke_token( $token );

		return new WP_REST_Response( null, 200 );
	}

	// -----------------------------------------------------------------------
	// Permission Callbacks
	// -----------------------------------------------------------------------

	/**
	 * Permission check for the authorization endpoint.
	 *
	 * Requires a logged-in WordPress user. If the user is not logged in,
	 * redirects to the WordPress login page with the current URL as the
	 * redirect target.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function check_authorize_permission( $request ) {
			// Check if user is already authenticated (could be via session cookie,
			// application password in Authorization header, or nonce).
			$user_id = get_current_user_id();

			// Use WordPress's built-in cookie validation.
			// wp_validate_logged_in_cookie() reads the COOKIEPATH-scoped
			// auth cookie and returns the user ID. This is more reliable
			// than manually iterating $_COOKIE which may not be populated
			// for REST API requests.
		if ( $user_id <= 0 ) {
			$cookie_user_id = wp_validate_logged_in_cookie( 0 );
			if ( $cookie_user_id ) {
				wp_set_current_user( $cookie_user_id );
				$user_id = $cookie_user_id;
			}
		}

		// Additional fallback: manually scan $_COOKIE for cases where
		// the built-in function can't find the cookie (e.g. different
		// COOKIEPATH or COOKIE_DOMAIN settings).
		if ( $user_id <= 0 && ! empty( $_COOKIE ) ) {
			foreach ( $_COOKIE as $name => $value ) {
				if ( 0 === strpos( $name, 'wordpress_logged_in_' ) ) {
					$cookie_user_id = wp_validate_auth_cookie( $value, 'logged_in' );
					if ( $cookie_user_id ) {
						wp_set_current_user( $cookie_user_id );
						$user_id = $cookie_user_id;
						break;
					}
				}
			}
		}

		if ( $user_id > 0 ) {
			return true;
		}

		// If this is a browser request, redirect to login.
		// For non-browser clients, return an error.
		if ( $this->is_browser_request() ) {
			// This is a browser request. Redirect to WordPress login.
			$current_url  = rest_url( 'mcp-ai/v1/oauth/authorize' );
			$query_params = $request->get_params();
			if ( ! empty( $query_params ) ) {
				$current_url = add_query_arg( $query_params, $current_url );
			}
			$login_url = wp_login_url( $current_url );
			wp_safe_redirect( $login_url );
			exit;
		}

		return new WP_Error(
			'rest_not_authenticated',
			__( 'You must be logged in to authorize an MCP client. Open this URL in a browser to log in.', 'mcp-ai-wpoos-pro' ),
			array(
				'status'  => 401,
				'actions' => array(
					'open_in_browser' => __( 'Open this authorization URL in your web browser to sign in with your WordPress account.', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	// ---------------------------------------------------------------- //
	// Security helpers (1.2.0)                                          //
	// ---------------------------------------------------------------- //

	/**
	 * Check whether open OAuth client registration is disabled.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	private static function is_open_registration_disabled() {
		if ( function_exists( 'wp_mcp_ai_get_settings_repository' ) ) {
			return (bool) wp_mcp_ai_get_settings_repository()->get(
				'oauth_disable_open_registration',
				true
			);
		}

		return true; // Default: disabled (fail-safe).
	}

	/**
	 * Rate-limit the OAuth token endpoint by IP.
	 *
	 * Tracks failed token exchanges per IP using transients.
	 * After 10 failures in 5 minutes, the IP is locked out for 15 minutes.
	 *
	 * Only active when enable_oauth_token_rate_limit setting is enabled.
	 *
	 * @since 1.2.0
	 * @return true|WP_Error
	 */
	private static function check_token_rate_limit() {
		// Check if the rate limit setting is enabled.
		if ( function_exists( 'wp_mcp_ai_get_settings_repository' ) ) {
			$enabled = wp_mcp_ai_get_settings_repository()->get(
				'enable_oauth_token_rate_limit',
				true
			);
			if ( ! $enabled ) {
				return true;
			}
		}

		$ip = self::get_client_ip();
		if ( empty( $ip ) ) {
			return true; // Can't determine IP — allow (don't lock everyone out).
		}

		$ip_hash   = md5( $ip );
		$lock_key  = 'wp_mcp_ai_oauth_token_lock_' . $ip_hash;
		$count_key = 'wp_mcp_ai_oauth_token_count_' . $ip_hash;

		// Check if currently locked out.
		if ( get_transient( $lock_key ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many token requests. Please try again later.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'      => 429,
					'retry_after' => 900, // 15 minutes.
				)
			);
		}

		// Increment failure counter.
		$count = absint( get_transient( $count_key ) ) + 1;
		set_transient( $count_key, $count, 300 ); // 5-minute window.

		// Lock out after threshold.
		if ( $count >= 10 ) {
			set_transient( $lock_key, 1, 900 ); // 15-minute lockout.
			delete_transient( $count_key );

			// Log lockout initiation for audit trail.
			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					'warning',
					sprintf(
						'OAuth token rate limit: IP locked out for 15 min (%d failures)',
						$count
					)
				);
			}

			return new WP_Error(
				'rate_limited',
				__( 'Too many token requests. Please try again in 15 minutes.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'      => 429,
					'retry_after' => 900,
				)
			);
		}

		return true;
	}

	/**
	 * Get the client IP address, respecting proxies.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	private static function get_client_ip() {
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// Take the first IP in the chain.
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return sanitize_text_field( trim( $ips[0] ) );
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return '';
	}
}
