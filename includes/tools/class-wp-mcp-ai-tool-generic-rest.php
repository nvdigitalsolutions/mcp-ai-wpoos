<?php
/**
 * Generic REST Tool for calling arbitrary REST API endpoints.
 *
 * Enables AI assistants to integrate with plugins and services
 * that haven't been explicitly integrated into WP oOS.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a generic REST API client tool for AI assistants.
 */
class WP_MCP_AI_Tool_Generic_REST implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Allowed HTTP methods.
	 *
	 * @var array<string>
	 */
	protected const ALLOWED_METHODS = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Maximum response body size in bytes (5MB).
	 *
	 * @var int
	 */
	protected const MAX_RESPONSE_SIZE = 5242880;

	/**
	 * Default request timeout in seconds.
	 *
	 * @var int
	 */
	protected const DEFAULT_TIMEOUT = 30;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generic_rest';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generic REST API', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Make HTTP requests to REST API endpoints for plugins or external services not explicitly integrated. Supports GET, POST, PUT, PATCH, and DELETE methods with custom headers and request bodies.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'          => array(
					'type'        => 'string',
					'description' => __( 'The full URL of the REST API endpoint to call. Must be a valid HTTP or HTTPS URL.', 'wp-mcp-ai' ),
				),
				'method'       => array(
					'type'        => 'string',
					'enum'        => self::ALLOWED_METHODS,
					'description' => __( 'HTTP method to use for the request. Defaults to GET.', 'wp-mcp-ai' ),
					'default'     => 'GET',
				),
				'headers'      => array(
					'type'                 => 'object',
					'description'          => __( 'Optional HTTP headers to include in the request. Common headers like Authorization, Content-Type, and Accept are supported.', 'wp-mcp-ai' ),
					'additionalProperties' => array(
						'type' => 'string',
					),
				),
				'body'         => array(
					'description' => __( 'Optional request body for POST, PUT, or PATCH requests. Can be a JSON object, string, or form data.', 'wp-mcp-ai' ),
					'oneOf'       => array(
						array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
						array(
							'type' => 'string',
						),
						array(
							'type'  => 'array',
							'items' => array(
								'type' => 'object',
							),
						),
					),
				),
				'query_params' => array(
					'type'                 => 'object',
					'description'          => __( 'Optional query parameters to append to the URL.', 'wp-mcp-ai' ),
					'additionalProperties' => array(
						'type' => array( 'string', 'number', 'boolean' ),
					),
				),
				'timeout'      => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds. Defaults to 30 seconds, maximum 120 seconds.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 120,
					'default'     => 30,
				),
				'auth_type'    => array(
					'type'        => 'string',
					'enum'        => array( 'none', 'basic', 'bearer', 'header' ),
					'description' => __( 'Authentication type. Use "bearer" for OAuth tokens, "basic" for username/password, "header" for custom header auth, or "none" for no auth.', 'wp-mcp-ai' ),
					'default'     => 'none',
				),
				'auth_value'   => array(
					'type'        => 'string',
					'description' => __( 'Authentication credentials. For bearer: the token. For basic: "username:password". For header: the header value.', 'wp-mcp-ai' ),
				),
				'auth_header'  => array(
					'type'        => 'string',
					'description' => __( 'Custom authentication header name when auth_type is "header". Defaults to "X-API-Key".', 'wp-mcp-ai' ),
					'default'     => 'X-API-Key',
				),
			),
			'required'             => array( 'url' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Require manage_options capability for security.
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to make generic REST API requests.', 'wp-mcp-ai' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai' )
			);
		}

		// Validate and sanitize URL.
		$url = isset( $arguments['url'] ) ? trim( $arguments['url'] ) : '';

		if ( '' === $url ) {
			return new WP_Error(
				'wp_mcp_ai_missing_url',
				__( 'A URL is required for the REST API request.', 'wp-mcp-ai' )
			);
		}

		$validated_url = $this->validate_url( $url );

		if ( is_wp_error( $validated_url ) ) {
			return $validated_url;
		}

		// Get HTTP method.
		$method = isset( $arguments['method'] ) ? strtoupper( trim( $arguments['method'] ) ) : 'GET';

		if ( ! in_array( $method, self::ALLOWED_METHODS, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_method',
				sprintf(
					/* translators: %s: comma-separated list of allowed methods */
					__( 'Invalid HTTP method. Allowed methods are: %s.', 'wp-mcp-ai' ),
					implode( ', ', self::ALLOWED_METHODS )
				)
			);
		}

		// Add query parameters to URL.
		if ( ! empty( $arguments['query_params'] ) && is_array( $arguments['query_params'] ) ) {
			$validated_url = add_query_arg(
				$this->sanitize_query_params( $arguments['query_params'] ),
				$validated_url
			);
		}

		// Build request headers.
		$headers = $this->build_headers( $arguments );

		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		// Build request body.
		$body = $this->build_body( $arguments, $method, $headers );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Determine timeout.
		$timeout = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : self::DEFAULT_TIMEOUT;
		$timeout = max( 1, min( 120, $timeout ) );

		// Build request arguments.
		$request_args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout,
		);

		if ( null !== $body ) {
			$request_args['body'] = $body;
		}

		/**
		 * Filter the request arguments before sending the generic REST request.
		 *
		 * @param array  $request_args Request arguments for wp_remote_request.
		 * @param string $validated_url The validated target URL.
		 * @param array  $arguments Original tool arguments.
		 * @param array  $context Execution context.
		 */
		$request_args = apply_filters( 'wp_mcp_ai_generic_rest_request_args', $request_args, $validated_url, $arguments, $context );

		// Log the request (without sensitive data).
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'generic_rest_request',
				'Making generic REST API request.',
				array(
					'url'    => $validated_url,
					'method' => $method,
				)
			);
		}

		// Execute the request.
		$response = wp_remote_request( $validated_url, $request_args );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Generic REST API request failed.',
					array(
						'error' => $response->get_error_message(),
						'url'   => $validated_url,
					)
				);
			}

			return new WP_Error(
				'wp_mcp_ai_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'The REST API request failed: %s', 'wp-mcp-ai' ),
					$response->get_error_message()
				)
			);
		}

		// Process and return the response.
		return $this->process_response( $response, $validated_url, $method );
	}

	/**
	 * Validate the target URL.
	 *
	 * @param string $url URL to validate.
	 * @return string|WP_Error Validated URL or error.
	 */
	protected function validate_url( $url ) {
		// Check for valid URL structure.
		$parsed = wp_parse_url( $url );

		if ( false === $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_url',
				__( 'The provided URL is not valid. Please provide a complete URL with scheme (http:// or https://).', 'wp-mcp-ai' )
			);
		}

		// Only allow HTTP and HTTPS.
		if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_scheme',
				__( 'Only HTTP and HTTPS URLs are allowed.', 'wp-mcp-ai' )
			);
		}

		// Block localhost and internal IPs for security (unless filtered).
		$host = strtolower( $parsed['host'] );

		/**
		 * Filter whether to allow requests to internal/localhost addresses.
		 *
		 * @param bool   $allow_internal Whether to allow internal requests.
		 * @param string $host The target host.
		 * @param string $url The full URL.
		 */
		$allow_internal = apply_filters( 'wp_mcp_ai_generic_rest_allow_internal', false, $host, $url );

		if ( ! $allow_internal ) {
			$blocked_patterns = array(
				'localhost',
				'127.0.0.1',
				'::1',
				'0.0.0.0',
			);

			foreach ( $blocked_patterns as $pattern ) {
				if ( $host === $pattern || 0 === strpos( $host, $pattern . ':' ) ) {
					return new WP_Error(
						'wp_mcp_ai_blocked_host',
						__( 'Requests to localhost or internal addresses are not allowed.', 'wp-mcp-ai' )
					);
				}
			}

			// Block private IP ranges.
			$ip = gethostbyname( $host );

			if ( $ip !== $host ) {
				// Resolution succeeded, check if it's a private IP.
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
					return new WP_Error(
						'wp_mcp_ai_blocked_ip',
						__( 'Requests to private or reserved IP addresses are not allowed.', 'wp-mcp-ai' )
					);
				}
			}
		}

		// Return sanitized URL.
		return esc_url_raw( $url );
	}

	/**
	 * Sanitize query parameters.
	 *
	 * @param array $params Query parameters.
	 * @return array Sanitized parameters.
	 */
	protected function sanitize_query_params( array $params ) {
		$sanitized = array();

		foreach ( $params as $key => $value ) {
			$clean_key = sanitize_key( $key );

			if ( '' === $clean_key ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$sanitized[ $clean_key ] = $value ? '1' : '0';
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $clean_key ] = (string) $value;
			} else {
				$sanitized[ $clean_key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Build request headers including authentication.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Headers array or error.
	 */
	protected function build_headers( array $arguments ) {
		$headers = array(
			'Accept' => 'application/json',
		);

		// Add custom headers.
		if ( ! empty( $arguments['headers'] ) && is_array( $arguments['headers'] ) ) {
			foreach ( $arguments['headers'] as $name => $value ) {
				$clean_name = $this->sanitize_header_name( $name );

				if ( '' !== $clean_name ) {
					$headers[ $clean_name ] = sanitize_text_field( (string) $value );
				}
			}
		}

		// Handle authentication.
		$auth_type = isset( $arguments['auth_type'] ) ? sanitize_key( $arguments['auth_type'] ) : 'none';

		if ( 'none' !== $auth_type ) {
			$auth_value = isset( $arguments['auth_value'] ) ? $arguments['auth_value'] : '';

			if ( '' === $auth_value ) {
				return new WP_Error(
					'wp_mcp_ai_missing_auth',
					__( 'Authentication value is required when auth_type is specified.', 'wp-mcp-ai' )
				);
			}

			switch ( $auth_type ) {
				case 'bearer':
					$headers['Authorization'] = 'Bearer ' . sanitize_text_field( $auth_value );
					break;

				case 'basic':
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					$headers['Authorization'] = 'Basic ' . base64_encode( $auth_value );
					break;

				case 'header':
					$auth_header             = isset( $arguments['auth_header'] )
						? $this->sanitize_header_name( $arguments['auth_header'] )
						: 'X-API-Key';
					$headers[ $auth_header ] = sanitize_text_field( $auth_value );
					break;
			}
		}

		return $headers;
	}

	/**
	 * Sanitize an HTTP header name.
	 *
	 * @param string $name Header name.
	 * @return string Sanitized header name.
	 */
	protected function sanitize_header_name( $name ) {
		// Remove any characters that aren't valid in HTTP headers.
		return preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $name );
	}

	/**
	 * Build request body.
	 *
	 * @param array  $arguments Tool arguments.
	 * @param string $method HTTP method.
	 * @param array  $headers Request headers (may be modified).
	 * @return string|array|null Request body or null if not applicable.
	 */
	protected function build_body( array $arguments, $method, array &$headers ) {
		// GET and DELETE typically don't have bodies.
		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
			return null;
		}

		if ( ! isset( $arguments['body'] ) ) {
			return null;
		}

		$body = $arguments['body'];

		// If body is already a string, use it directly.
		if ( is_string( $body ) ) {
			// Auto-detect JSON and set header if not already set.
			if ( ! isset( $headers['Content-Type'] ) ) {
				$decoded = json_decode( $body );

				if ( null !== $decoded || 'null' === $body ) {
					$headers['Content-Type'] = 'application/json';
				} else {
					$headers['Content-Type'] = 'text/plain';
				}
			}

			return $body;
		}

		// If body is array/object, encode as JSON.
		if ( is_array( $body ) || is_object( $body ) ) {
			if ( ! isset( $headers['Content-Type'] ) ) {
				$headers['Content-Type'] = 'application/json';
			}

			if ( 'application/json' === $headers['Content-Type'] ) {
				return wp_json_encode( $body );
			}

			// For form data, return array for WP to handle.
			return $body;
		}

		return null;
	}

	/**
	 * Process the HTTP response.
	 *
	 * @param array  $response wp_remote_request response.
	 * @param string $url Target URL.
	 * @param string $method HTTP method used.
	 * @return array Processed response data.
	 */
	protected function process_response( $response, $url, $method ) {
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$headers     = wp_remote_retrieve_headers( $response );

		// Check response size.
		if ( strlen( $body ) > self::MAX_RESPONSE_SIZE ) {
			return array(
				'success'     => false,
				'status_code' => $status_code,
				'error'       => __( 'Response body exceeds maximum allowed size (5MB).', 'wp-mcp-ai' ),
				'url'         => $url,
				'method'      => $method,
				'truncated'   => true,
				'body'        => substr( $body, 0, 10000 ) . '... [truncated]',
			);
		}

		// Try to parse as JSON.
		$parsed_body = json_decode( $body, true );
		$is_json     = ( null !== $parsed_body || 'null' === trim( $body ) );

		// Extract relevant headers for response.
		$response_headers = array();

		if ( $headers instanceof ArrayAccess || $headers instanceof Traversable ) {
			foreach ( $headers as $name => $value ) {
				$response_headers[ $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		}

		// Determine success based on status code.
		$is_success = $status_code >= 200 && $status_code < 300;

		$result = array(
			'success'     => $is_success,
			'status_code' => $status_code,
			'url'         => $url,
			'method'      => $method,
		);

		if ( $is_json ) {
			$result['body']      = $parsed_body;
			$result['body_type'] = 'json';
		} else {
			// For non-JSON, return raw body but truncate if very large.
			$result['body']      = strlen( $body ) > 50000 ? substr( $body, 0, 50000 ) . '... [truncated]' : $body;
			$result['body_type'] = 'raw';
		}

		// Include content-type header for context.
		if ( isset( $response_headers['content-type'] ) ) {
			$result['content_type'] = $response_headers['content-type'];
		}

		// Log success/failure.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			if ( $is_success ) {
				WP_MCP_AI_Logger::log_event(
					'generic_rest_response',
					'Generic REST API request completed successfully.',
					array(
						'url'         => $url,
						'method'      => $method,
						'status_code' => $status_code,
					)
				);
			} else {
				WP_MCP_AI_Logger::log_error(
					'Generic REST API request returned error status.',
					array(
						'url'         => $url,
						'method'      => $method,
						'status_code' => $status_code,
					)
				);
			}
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires manage_options capability.
			'external-api',         // Makes external HTTP requests.
			'network-dependent',    // Requires internet connectivity.
			'may-timeout',          // May exceed typical request timeout.
			'non-deterministic',    // Results may vary for same inputs.
			'rate-limited',         // May be subject to external rate limits.
		);
	}
}
