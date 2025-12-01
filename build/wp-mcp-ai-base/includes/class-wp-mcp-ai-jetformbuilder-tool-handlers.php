<?php
/**
 * Helper for invoking JetFormBuilder REST controllers on behalf of MCP tools.
 *
 * @package WP_MCP_AI
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

		$available = class_exists( 'Jet_Form_Builder' );

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
	 * @param string $function Function name.
	 * @param string $message  Warning message.
	 * @param string $version  Version string.
	 * @return bool
	 */
	public static function maybe_suppress_route_warning( $trigger, $function = '', $message = '', $version = '' ) {
		if ( 'register_rest_route' === $function && false !== strpos( $message, '<code>jet-form-builder/v1</code>' ) ) {
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
				__( 'JetFormBuilder is not active on this site.', 'wp-mcp-ai' )
			);
		}

		$config = self::get_operation_config( $operation );
		if ( null === $config ) {
			return new WP_Error(
				'wp_mcp_ai_jetformbuilder_unknown_operation',
				__( 'The requested JetFormBuilder operation is not supported.', 'wp-mcp-ai' )
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
				__( 'JetFormBuilder requests for this operation must include a form ID.', 'wp-mcp-ai' )
			);
		}

		$transport = isset( $payload['transport'] ) ? sanitize_key( $payload['transport'] ) : 'auto';
		if ( ! in_array( $transport, array( 'auto', 'rest', 'http' ), true ) ) {
			$transport = 'auto';
		}

		$route = self::build_route( $config['route'], $form_id );

		$result = null;
		if ( 'http' !== $transport ) {
			$result = self::dispatch_internal( $route, $config['method'], $params, $config['args_location'] );

			if ( null !== $result ) {
				return $result;
			}

			if ( 'rest' === $transport ) {
				return self::normalise_wp_error(
					new WP_Error(
						'wp_mcp_ai_jetformbuilder_route_unavailable',
						__( 'The JetFormBuilder REST route is not registered.', 'wp-mcp-ai' )
					),
					'rest'
				);
			}
		}

		return self::dispatch_remote( $route, $config['method'], $params, $context );
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
				__( 'A valid user is required to execute JetFormBuilder tools.', 'wp-mcp-ai' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'The authenticated user does not have access to this site.', 'wp-mcp-ai' )
			);
		}

		if ( get_current_user_id() !== $user_id ) {
			wp_set_current_user( $user_id );
		}

		return $user_id;
	}

	/**
	 * Dispatch the request through the WordPress REST server.
	 *
	 * @param string $route         Route relative to the JetFormBuilder namespace.
	 * @param string $method        HTTP method.
	 * @param array  $params        Request parameters.
	 * @param string $args_location Whether params belong in the query string or body.
	 * @return array|null
	 */
	protected static function dispatch_internal( $route, $method, array $params, $args_location ) {
		if ( ! function_exists( 'rest_do_request' ) ) {
			return null;
		}

		$method     = strtoupper( $method );
		$path       = self::prepare_rest_path( $route );
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
	 * Dispatch the request using wp_remote_request().
	 *
	 * @param string $route  Route relative to the JetFormBuilder namespace.
	 * @param string $method HTTP method.
	 * @param array  $params Request parameters.
	 * @param array  $context Execution context.
	 * @return array
	 */
	protected static function dispatch_remote( $route, $method, array $params, array $context = array() ) {
		$method = strtoupper( $method );
		$url    = WP_MCP_AI_Proxy_Utils::build_rest_url( self::REST_NAMESPACE, $route );
		$args   = array(
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
						'name'  => $cookie_name,
						'value' => $cookie_value,
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
	 * @param string $route Route relative to the JetFormBuilder namespace.
	 * @return string
	 */
	protected static function prepare_remote_rest_url( $route ) {
		$route = ltrim( $route, '/' );
		$url   = rest_url( ltrim( self::REST_NAMESPACE . '/' . $route, '/' ) );

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
	public static function maybe_authenticate_proxy_request( $result, $request = null, $server = null ) {
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

		wp_set_current_user( $user_id );

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
	 * @param string $route Route relative to the namespace.
	 * @return string
	 */
	protected static function prepare_rest_path( $route ) {
		$namespace = trim( self::REST_NAMESPACE, '/' );
		$route     = ltrim( $route, '/' );
		$path      = '/' . $namespace . '/' . $route;

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
		$message  = ! empty( $messages ) ? $messages[0] : __( 'An unexpected error occurred.', 'wp-mcp-ai' );

		$status = 500;
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		} elseif ( is_int( $data ) ) {
			$status = (int) $data;
		}

		return array(
			'success'   => false,
			'transport' => $transport,
			'status'    => $status,
			'error'     => array(
				'code'    => $code,
				'message' => $message,
				'data'    => $data,
			),
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
			$message = sprintf( __( 'JetFormBuilder request returned HTTP %d.', 'wp-mcp-ai' ), (int) $status );
		}

		return array(
			'success'   => false,
			'transport' => $transport,
			'status'    => (int) $status,
			'error'     => array(
				'code'    => $code,
				'message' => $message,
				'data'    => $data,
			),
		);
	}
}
