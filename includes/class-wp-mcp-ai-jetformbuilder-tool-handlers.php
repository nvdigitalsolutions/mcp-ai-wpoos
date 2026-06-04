<?php
/**
 * Helper for invoking JetFormBuilder REST controllers on behalf of MCP tools.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Translate MCP tool payloads into JetFormBuilder REST requests.
 */
class WP_MCP_AI_JetFormBuilder_Tool_Handlers {
	const REST_NAMESPACE = 'jet-form-builder/v1';
	const PROXY_HEADER   = 'X-WP-MCP-AI-Proxy';

	/**
	 * Suppress doing_it_wrong notices for JetFormBuilder mock routes during automated tests.
	 *
	 * @var bool
	 */
	protected static $suppress_route_warnings = false;

	/**
	 * Track whether the proxy authentication filter has been registered.
	 *
	 * @var bool
	 */
	protected static $proxy_filter_registered = false;

	/**
	 * Mapping of supported JetFormBuilder operations.
	 *
	 * @var array
	 */
	protected static $operations = array(
		'list_forms'        => array(
			'route'         => 'forms/',
			'method'        => 'GET',
			'args_location' => 'query',
			'requires_id'   => false,
		),
		'get_form_fields'   => array(
			'route'         => 'forms/%s/fields/',
			'method'        => 'GET',
			'args_location' => 'query',
			'requires_id'   => true,
		),
		'fetch_submissions' => array(
			'route'         => 'forms/%s/records/',
			'method'        => 'GET',
			'args_location' => 'query',
			'requires_id'   => true,
		),
		'create_submission' => array(
			'route'         => 'forms/%s/submit/',
			'method'        => 'POST',
			'args_location' => 'body',
			'requires_id'   => true,
		),
	);

	/**
	 * Initialise static hooks.
	 */
	public static function bootstrap() {
		if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) && ! self::$suppress_route_warnings ) {
			add_filter( 'doing_it_wrong_trigger_error', array( __CLASS__, 'maybe_suppress_route_warning' ), 10, 4 );
			self::$suppress_route_warnings = true;
		}

		if ( ! self::$proxy_filter_registered ) {
			add_filter( 'rest_authentication_errors', array( __CLASS__, 'maybe_authenticate_proxy_request' ), 8, 3 );
			self::$proxy_filter_registered = true;
		}
	}

	/**
	 * Determine if JetFormBuilder appears to be available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		self::bootstrap();

		$available = class_exists( 'Jet_Form_Builder\\Plugin' );

		/**
		 * Allow tests or extensions to override JetFormBuilder availability detection.
		 *
		 * @param bool $available Whether JetFormBuilder appears to be active.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_jetformbuilder_is_available', $available );
	}

	/**
	 * Retrieve the supported operation slugs.
	 *
	 * @return string[]
	 */
	public static function get_supported_operations() {
		self::bootstrap();

		return array_keys( self::$operations );
	}

	/**
	 * Retrieve the configuration for a given operation.
	 *
	 * @param string $operation Operation identifier.
	 * @return array|null
	 */
	public static function get_operation_config( $operation ) {
		self::bootstrap();

		$operation = sanitize_key( $operation );

		return isset( self::$operations[ $operation ] ) ? self::$operations[ $operation ] : null;
	}

	/**
	 * Optionally suppress route registration warnings for JetFormBuilder mocks during tests.
	 *
	 * @param bool   $trigger  Whether to trigger a PHP warning.
	 * @param string $function_name Function name.
	 * @param string $message  Warning message.
	 * @param string $version  Version string.
	 * @return bool
	 */
	public static function maybe_suppress_route_warning( $trigger, $function_name = '', $message = '', $version = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress filter signature.
		if ( 'register_rest_route' === $function_name && false !== strpos( $message, '<code>jet-form-builder/v1</code>' ) ) {
			return false;
		}

		return $trigger;
	}

	/**
	 * Dispatch a JetFormBuilder operation using the provided payload and context.
	 *
	 * @param string $operation Operation identifier.
	 * @param array  $payload   Data describing the request.
	 * @param array  $context   Execution context including `user_id`.
	 * @return array|WP_Error Normalised response or WP_Error for validation failures.
	 */
	public static function dispatch( $operation, array $payload = array(), array $context = array() ) {
		self::bootstrap();

		if ( ! self::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_jetformbuilder_missing',
				__( 'JetFormBuilder is not active on this site.', 'mcp-ai-wpoos' )
			);
		}

		$config = self::get_operation_config( $operation );
		if ( null === $config ) {
			return new WP_Error(
				'wp_mcp_ai_jetformbuilder_unknown_operation',
				__( 'The requested JetFormBuilder operation is not supported.', 'mcp-ai-wpoos' )
			);
		}

		$user_id = self::prepare_user_context( $context );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$params  = isset( $payload['params'] ) && is_array( $payload['params'] ) ? self::sanitize_params( $payload['params'] ) : array();
		$form_id = isset( $payload['id'] ) ? sanitize_text_field( (string) $payload['id'] ) : '';

		if ( ! empty( $config['requires_id'] ) && empty( $form_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_jetformbuilder_missing_id',
				__( 'JetFormBuilder requests for this operation must include a form ID.', 'mcp-ai-wpoos' )
			);
		}

		$transport = isset( $payload['transport'] ) ? sanitize_key( $payload['transport'] ) : 'auto';
		if ( ! in_array( $transport, array( 'auto', 'rest', 'http' ), true ) ) {
			$transport = 'auto';
		}

		// Accept a connection_id for remote WordPress sites configured in the Remote Site Manager.
		$connection_id = isset( $payload['connection_id'] ) ? sanitize_key( (string) $payload['connection_id'] ) : '';
		if ( empty( $connection_id ) && isset( $context['remote_connection_id'] ) ) {
			$connection_id = sanitize_key( (string) $context['remote_connection_id'] );
		}

		$route          = self::build_route( $config['route'], $form_id );
		$rest_namespace = isset( $config['namespace'] ) ? $config['namespace'] : self::REST_NAMESPACE;

		$result = null;

		// If a connection_id is provided, force remote dispatch through the saved connection.
		if ( $connection_id && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return self::dispatch_via_connection( $connection_id, $route, $config['method'], $params, $context, $rest_namespace );
		}

		if ( 'http' !== $transport ) {
			$result = self::dispatch_internal( $route, $config['method'], $params, $config['args_location'], $rest_namespace );

			if ( null !== $result ) {
				return $result;
			}

			if ( 'rest' === $transport ) {
				return self::normalise_wp_error(
					new WP_Error(
						'wp_mcp_ai_jetformbuilder_route_unavailable',
						__( 'The JetFormBuilder REST route is not registered.', 'mcp-ai-wpoos' )
					),
					'rest'
				);
			}
		}

		return self::dispatch_remote( $route, $config['method'], $params, $context, $rest_namespace );
	}

	/**
	 * Ensure the request is executed for the intended user context.
	 *
	 * @param array $context Execution context.
	 * @return int|WP_Error
	 */
	protected static function prepare_user_context( array $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_anonymous_user',
				__( 'A valid user is required to execute JetFormBuilder tools.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'The authenticated user does not have access to this site.', 'mcp-ai-wpoos' )
			);
		}

		if ( get_current_user_id() !== $user_id ) {
			// Switch the current-user context to match the verified caller.
			// `$user_id` here originates from the request's authenticated
			// `$context` (populated by the REST authenticator after a
			// bearer token, nonce, or assistant credential has been
			// validated upstream), or from `get_current_user_id()` when
			// the request is already executing under a logged-in user.
			// JetFormBuilder permission checks then run in this user's
			// context for the duration of the dispatched request.
			if ( ! WP_MCP_AI_User_Context_Helper::safe_set_current_user( $user_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_user',
					__( 'The authenticated user could not be resolved on this site.', 'mcp-ai-wpoos' )
				);
			}
		}

		return $user_id;
	}

	/**
	 * Dispatch the request through the WordPress REST server.
	 *
	 * @param string $route           Route relative to the namespace.
	 * @param string $method          HTTP method.
	 * @param array  $params          Request parameters.
	 * @param string $args_location   Whether params belong in the query string or body.
	 * @param string $rest_namespace  REST namespace (defaults to jet-form-builder/v1).
	 * @return array|null
	 */
	protected static function dispatch_internal( $route, $method, array $params, $args_location, $rest_namespace = '' ) {
		if ( ! function_exists( 'rest_do_request' ) ) {
			return null;
		}

		$method     = strtoupper( $method );
		$path       = self::prepare_rest_path( $route, $rest_namespace );
		$request    = new WP_REST_Request( $method, $path );
		$args_place = 'body' === $args_location ? 'body' : 'query';

		if ( 'body' === $args_place && ! empty( $params ) ) {
			$request->set_body_params( $params );
		} elseif ( ! empty( $params ) ) {
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}

		$response = rest_do_request( $request );

		if ( is_wp_error( $response ) ) {
			$codes = $response->get_error_codes();
			if ( in_array( 'rest_no_route', $codes, true ) || in_array( 'rest_no_callback', $codes, true ) ) {
				return null;
			}

			return self::normalise_wp_error( $response, 'rest' );
		}

		if ( ! $response instanceof WP_REST_Response ) {
			return self::normalise_success( $response, 200, array(), 'rest' );
		}

		$status = (int) $response->get_status();
		$data   = $response->get_data();

		if ( $status >= 400 ) {
			return self::normalise_http_error( $status, $data, 'rest' );
		}

		return self::normalise_success( $data, $status, $response->get_headers(), 'rest' );
	}

	/**
	 * Dispatch via a saved Remote Site Manager connection.
	 *
	 * Uses the connection's stored URL and credentials to make an
	 * authenticated HTTP request to the remote WordPress site's
	 * JetFormBuilder REST API.
	 *
	 * @since 1.2.0
	 *
	 * @param string $connection_id   Remote connection identifier.
	 * @param string $route           Route relative to the namespace.
	 * @param string $method          HTTP method.
	 * @param array  $params          Request parameters.
	 * @param array  $context         Execution context.
	 * @param string $rest_namespace  REST namespace (defaults to jet-form-builder/v1).
	 * @return array|WP_Error
	 */
	protected static function dispatch_via_connection( $connection_id, $route, $method, array $params, array $context = array(), $rest_namespace = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $context reserved for future use.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			return new WP_Error(
				'wp_mcp_ai_connection_not_found',
				sprintf(
					/* translators: %s: connection ID */
					__( 'Remote connection "%s" was not found.', 'mcp-ai-wpoos' ),
					$connection_id
				)
			);
		}

		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_connection_disabled',
				sprintf(
					/* translators: %s: connection name */
					__( 'Remote connection "%s" is disabled.', 'mcp-ai-wpoos' ),
					isset( $connection['name'] ) ? $connection['name'] : $connection_id
				)
			);
		}

		if ( empty( $connection['url'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_connection_no_url',
				__( 'The remote connection has no URL configured.', 'mcp-ai-wpoos' )
			);
		}

		$base_url = rtrim( $connection['url'], '/' );
		$rest_url = $base_url . '/wp-json/' . self::REST_NAMESPACE . '/' . ltrim( $route, '/' );

		// Attach query parameters for GET requests.
		if ( 'GET' === strtoupper( $method ) && ! empty( $params ) ) {
			$rest_url = add_query_arg( $params, $rest_url );
		}

		$request_args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);

		// Attach body for non-GET requests.
		if ( 'GET' !== strtoupper( $method ) ) {
			$request_args['body']                    = wp_json_encode( $params );
			$request_args['headers']['Content-Type'] = 'application/json';
		}

		// Apply authentication.
		$auth_type = isset( $connection['auth_type'] ) ? $connection['auth_type'] : 'none';
		switch ( $auth_type ) {
			case 'application_password':
			case 'basic_auth':
				if ( ! empty( $connection['username'] ) && ! empty( $connection['password'] ) ) {
					$password = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['password'] );
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					$encoded                                  = base64_encode( $connection['username'] . ':' . $password );
					$request_args['headers']['Authorization'] = 'Basic ' . $encoded;
				}
				break;

			case 'custom_header':
				if ( ! empty( $connection['api_key'] ) ) {
					$api_key                              = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
					$request_args['headers']['X-API-Key'] = $api_key;
				}
				break;

			case 'jwt':
				if ( ! empty( $connection['token'] ) ) {
					$token                                    = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['token'] );
					$request_args['headers']['Authorization'] = 'Bearer ' . $token;
				}
				break;

			case 'none':
			default:
				break;
		}

		$response = wp_remote_request( $rest_url, $request_args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_remote_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Remote request failed: %s', 'mcp-ai-wpoos' ),
					$response->get_error_message()
				)
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$data    = json_decode( $body, true );

		if ( null === $data && '' !== $body ) {
			$data = $body;
		}

		if ( $status >= 400 ) {
			return self::normalise_http_error( $status, $data, 'http' );
		}

		$header_array = array();
		if ( $headers instanceof \Requests_Utility_CaseInsensitiveDictionary ) {
			$header_array = $headers->getAll();
		} elseif ( is_array( $headers ) ) {
			$header_array = $headers;
		}

		$result = self::normalise_success( $data, $status, $header_array, 'http' );

		// Tag the result with connection metadata.
		if ( is_array( $result ) ) {
			$result['connection_id']   = $connection_id;
			$result['connection_name'] = isset( $connection['name'] ) ? $connection['name'] : $connection_id;
		}

		return $result;
	}

	/**
	 * Dispatch the request using wp_remote_request().
	 *
	 * @param string $route           Route relative to the namespace.
	 * @param string $method          HTTP method.
	 * @param array  $params          Request parameters.
	 * @param array  $context         Execution context.
	 * @param string $rest_namespace  REST namespace (defaults to jet-form-builder/v1).
	 * @return array
	 */
	protected static function dispatch_remote( $route, $method, array $params, array $context = array(), $rest_namespace = '' ) {
		$method  = strtoupper( $method );
		$rest_ns = $rest_namespace ? $rest_namespace : self::REST_NAMESPACE;
		$url     = WP_MCP_AI_Proxy_Utils::build_rest_url( $rest_ns, $route );
		$args    = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(),
		);

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( 'GET' === $method || 'DELETE' === $method ) {
			if ( ! empty( $params ) ) {
				$url = add_query_arg( self::prepare_query_params( $params ), $url );
			}
		} elseif ( ! empty( $params ) ) {
			$args['body']                    = wp_json_encode( $params );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$nonce = wp_create_nonce( 'wp_rest' );
		if ( $nonce ) {
			$args['headers']['X-WP-Nonce'] = $nonce;
		}

		$proxy_token = self::issue_proxy_token( $user_id, $context );
		if ( ! empty( $proxy_token ) ) {
			$args['headers'][ self::PROXY_HEADER ] = $proxy_token;
		}

		if ( ! empty( $_COOKIE ) && class_exists( 'WP_Http_Cookie' ) ) {
			$args['cookies'] = array();
			foreach ( $_COOKIE as $cookie_name => $cookie_value ) {
				$args['cookies'][] = new WP_Http_Cookie(
					array(
						'name'  => sanitize_key( wp_unslash( $cookie_name ) ),
						'value' => is_array( $cookie_value ) ? '' : sanitize_text_field( wp_unslash( $cookie_value ) ),
					)
				);
			}
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return self::normalise_wp_error( $response, 'http' );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$data    = json_decode( $body, true );

		if ( null === $data && '' !== $body ) {
			$data = $body;
		}

		if ( $status >= 400 ) {
			return self::normalise_http_error( $status, $data, 'http' );
		}

		$header_array = array();
		if ( $headers instanceof Requests_Utility_CaseInsensitiveDictionary ) {
			$header_array = $headers->getAll();
		} elseif ( is_array( $headers ) ) {
			$header_array = $headers;
		}

		return self::normalise_success( $data, $status, $header_array, 'http' );
	}


	/**
	 * Build a REST URL suitable for remote proxy requests.
	 *
	 * @param string $route           Route relative to the namespace.
	 * @param string $rest_namespace  REST namespace (defaults to jet-form-builder/v1).
	 * @return string
	 */
	protected static function prepare_remote_rest_url( $route, $rest_namespace = '' ) {
		$rest_ns = $rest_namespace ? $rest_namespace : self::REST_NAMESPACE;
		$route   = ltrim( $route, '/' );
		$url     = rest_url( ltrim( $rest_ns . '/' . $route, '/' ) );

		return WP_MCP_AI_Request_Context::normalise_rest_url( $url );
	}

	/**
	 * Generate and persist a short-lived token to authenticate remote JetFormBuilder requests.
	 *
	 * @param int   $user_id User identifier for the proxied request.
	 * @param array $context Execution context from the MCP tool.
	 * @return string|null   Raw token value to include in the proxy header.
	 */
	protected static function issue_proxy_token( $user_id, array $context ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		$token = wp_generate_password( 32, false, false );
		$key   = self::get_proxy_transient_key( $token );

		$payload = array(
			'user_id'       => $user_id,
			'issued_at'     => time(),
			'token_type'    => isset( $context['token_type'] ) ? sanitize_key( $context['token_type'] ) : '',
			'token_context' => isset( $context['token_context'] ) && is_array( $context['token_context'] ) ? $context['token_context'] : array(),
		);

		$stored = set_transient( $key, $payload, MINUTE_IN_SECONDS );

		if ( ! $stored ) {
			return null;
		}

		return $token;
	}

	/**
	 * Retrieve the transient key associated with a proxy token.
	 *
	 * @param string $token Raw proxy token.
	 * @return string
	 */
	protected static function get_proxy_transient_key( $token ) {
		return 'wp_mcp_ai_jfb_proxy_' . md5( $token );
	}

	/**
	 * Authenticate proxied JetFormBuilder requests dispatched over HTTP.
	 *
	 * @param mixed                $result  Existing authentication error.
	 * @param WP_REST_Request|null $request Current REST request, when provided.
	 * @param WP_REST_Server|null  $server  REST server instance.
	 * @return mixed
	 */
	public static function maybe_authenticate_proxy_request( $result, $request = null, $server = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress filter signature.
		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		if ( null !== $result ) {
			return $result;
		}

		if ( is_user_logged_in() ) {
			return $result;
		}

		$header = $request->get_header( self::PROXY_HEADER );
		if ( empty( $header ) ) {
			return $result;
		}

		$route = ltrim( $request->get_route(), '/' );
		if ( 0 !== strpos( $route, self::REST_NAMESPACE ) ) {
			return $result;
		}

		$key     = self::get_proxy_transient_key( $header );
		$payload = get_transient( $key );

		if ( false === $payload || ! is_array( $payload ) ) {
			return $result;
		}

		delete_transient( $key );

		$user_id = isset( $payload['user_id'] ) ? absint( $payload['user_id'] ) : 0;
		if ( ! $user_id ) {
			return $result;
		}

		// `$user_id` is the verified user from the previously-stored
		// proxy transient (keyed by a request-scoped header); this
		// branch only runs as the response phase of a request that
		// already authenticated. The capability gate for any
		// subsequent JetFormBuilder action lives on the JetFormBuilder
		// REST route's own `permission_callback`. The helper revalidates
		// that the user still exists before we mutate global state.
		WP_MCP_AI_User_Context_Helper::safe_set_current_user( $user_id );

		return $result;
	}

	/**
	 * Sanitise request parameters.
	 *
	 * @param array $params Parameters supplied by the MCP payload.
	 * @return array
	 */
	protected static function sanitize_params( array $params ) {
		$sanitized = array();

		foreach ( $params as $key => $value ) {
			$clean_key = is_string( $key ) ? preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) : $key;

			if ( is_array( $value ) ) {
				$sanitized[ $clean_key ] = self::sanitize_params( $value );
				continue;
			}

			if ( is_string( $value ) ) {
				$value                   = preg_replace( '#<script[^>]*>(.*?)</script>#is', '$1', $value );
				$sanitized_value         = wp_strip_all_tags( $value, true );
				$sanitized[ $clean_key ] = trim( wp_unslash( $sanitized_value ) );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$sanitized[ $clean_key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Prepare the REST path for a JetFormBuilder route.
	 *
	 * @param string $route           Route relative to the namespace.
	 * @param string $rest_namespace  REST namespace (defaults to jet-form-builder/v1).
	 * @return string
	 */
	protected static function prepare_rest_path( $route, $rest_namespace = '' ) {
		$ns    = $rest_namespace ? trim( $rest_namespace, '/' ) : trim( self::REST_NAMESPACE, '/' );
		$route = ltrim( $route, '/' );
		$path  = '/' . $ns . '/' . $route;

		return '/' . trim( $path, '/' );
	}

	/**
	 * Prepare query parameters for HTTP requests.
	 *
	 * @param array $params Request parameters.
	 * @return array
	 */
	protected static function prepare_query_params( array $params ) {
		$prepared = array();

		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				$prepared[ $key ] = wp_json_encode( $value );
			} else {
				$prepared[ $key ] = $value;
			}
		}

		return $prepared;
	}

	/**
	 * Build the route including any item identifiers.
	 *
	 * @param string $route_pattern Route pattern, optionally containing a sprintf placeholder.
	 * @param string $item_id       Item identifier.
	 * @return string
	 */
	protected static function build_route( $route_pattern, $item_id ) {
		if ( false !== strpos( $route_pattern, '%s' ) ) {
			return sprintf( $route_pattern, rawurlencode( $item_id ) );
		}

		return $route_pattern;
	}

	/**
	 * Normalise a successful response payload.
	 *
	 * @param mixed  $data      Response data.
	 * @param int    $status    HTTP status code.
	 * @param array  $headers   Response headers.
	 * @param string $transport Transport identifier.
	 * @return array
	 */
	protected static function normalise_success( $data, $status, $headers, $transport ) {
		$result = array(
			'success'   => true,
			'transport' => $transport,
			'status'    => (int) $status,
			'data'      => $data,
		);

		if ( ! empty( $headers ) ) {
			$result['headers'] = array();
			foreach ( $headers as $key => $value ) {
				$result['headers'][ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Normalise a WP_Error response.
	 *
	 * @param WP_Error $error     Error instance.
	 * @param string   $transport Transport identifier.
	 * @return array
	 */
	protected static function normalise_wp_error( WP_Error $error, $transport ) {
		$codes    = $error->get_error_codes();
		$code     = ! empty( $codes ) ? $codes[0] : 'wp_error';
		$data     = $error->get_error_data( $code );
		$messages = $error->get_error_messages( $code );
		$message  = ! empty( $messages ) ? $messages[0] : __( 'An unexpected error occurred.', 'mcp-ai-wpoos' );

		$status = 500;
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		} elseif ( is_int( $data ) ) {
			$status = (int) $data;
		}

		return new WP_Error(
			'wp_mcp_ai_error',
			$message,
			array(
				'transport' => $transport,
				'status'    => $status,
				'error'     => array(
					'code'    => $code,
					'message' => $message,
					'data'    => $data,
				),
			)
		);
	}

	/**
	 * Normalise an HTTP error response payload.
	 *
	 * @param int    $status    HTTP status code.
	 * @param mixed  $data      Response data payload.
	 * @param string $transport Transport identifier.
	 * @return array
	 */
	protected static function normalise_http_error( $status, $data, $transport ) {
		$message = '';
		$code    = 'http_error';
		if ( is_array( $data ) ) {
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				$message = $data['message'];
			}
			if ( isset( $data['code'] ) && is_string( $data['code'] ) ) {
				$code = $data['code'];
			}
		} elseif ( is_string( $data ) && '' !== $data ) {
			$message = $data;
		}

		if ( empty( $message ) ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'JetFormBuilder request returned HTTP %d.', 'mcp-ai-wpoos' ), (int) $status );
		}

		return new WP_Error(
			'wp_mcp_ai_error',
			$message,
			array(
				'transport' => $transport,
				'status'    => (int) $status,
				'error'     => array(
					'code'    => $code,
					'message' => $message,
					'data'    => $data,
				),
			)
		);
	}
}
