<?php
/**
 * Helper utilities for working with WordPress HTTP responses.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_HTTP' ) ) {
	/**
	 * Provides helpers for normalising WordPress HTTP transport errors.
	 */
	class WP_MCP_AI_HTTP {

		/**
		 * Maximum number of characters to include in body previews.
		 */
		const BODY_PREVIEW_LIMIT = 300;

		/**
		 * Track whether hooks have already been registered.
		 *
		 * @var bool
		 */
		protected static $bootstrapped = false;

		/**
		 * Register hooks that capture HTTP activity for logging.
		 */
		public static function bootstrap() {
			if ( self::$bootstrapped ) {
				return;
			}

			add_action( 'http_api_debug', array( __CLASS__, 'log_http_api_debug' ), 10, 5 );

			self::$bootstrapped = true;
		}

		/**
		 * Log HTTP API responses when logging is enabled.
		 *
		 * @param mixed  $response Response or WP_Error instance.
		 * @param string $type     Debug context type.
		 * @param mixed  $class    Transport class or instance.
		 * @param array  $args     Request arguments.
		 * @param string $url      Requested URL.
		 */
		public static function log_http_api_debug( $response, $type, $class, $args, $url ) {
			if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) || ! WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
				return;
			}

			if ( 'request' === $type ) {
				$request_args = self::extract_request_args_from_debug_hook( $response, $args );
				$context      = self::build_http_request_context( $request_args, $url, $class );

				WP_MCP_AI_Logger::log_event( 'http_request_outbound', 'HTTP request dispatched.', $context );
				return;
			}

			if ( 'response' !== $type ) {
				return;
			}

			$context = self::build_http_response_context( $response, $args, $url, $class );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_event( 'http_response_error', 'HTTP request failed.', $context );
				return;
			}

			WP_MCP_AI_Logger::log_event( 'http_response_inbound', 'HTTP response received.', $context );
		}

		/**
		 * Extract the best-effort request arguments from the debug hook parameters.
		 *
		 * WordPress passes the parsed request arguments as the first parameter for the
		 * `request` debug entries, but some transports may populate the fourth
		 * argument instead. We attempt to accommodate both to ensure outbound
		 * logging always has the request metadata available.
		 *
		 * @param mixed $response First parameter from the debug hook.
		 * @param mixed $args     Fourth parameter from the debug hook.
		 *
		 * @return array
		 */
		protected static function extract_request_args_from_debug_hook( $response, $args ) {
			if ( is_array( $response ) ) {
				return $response;
			}

			if ( is_array( $args ) ) {
				return $args;
			}

			return array();
		}

		/**
		 * Build structured context information for outbound HTTP logging.
		 *
		 * @param array  $args  Request arguments.
		 * @param string $url   Requested URL.
		 * @param mixed  $class Transport class or instance.
		 *
		 * @return array
		 */
		protected static function build_http_request_context( $args, $url, $class ) {
			$context = array(
				'url'    => is_string( $url ) ? esc_url_raw( $url ) : '',
				'method' => isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET',
			);

			if ( is_object( $class ) ) {
				$context['transport'] = get_class( $class );
			} elseif ( is_string( $class ) && '' !== $class ) {
				$context['transport'] = $class;
			}

			if ( isset( $args['timeout'] ) ) {
				$context['timeout'] = (float) $args['timeout'];
			}

			if ( isset( $args['redirection'] ) ) {
				$context['redirection'] = (int) $args['redirection'];
			}

			if ( isset( $args['blocking'] ) ) {
				$context['blocking'] = (bool) $args['blocking'];
			}

			if ( isset( $args['headers'] ) ) {
				$context['request_headers'] = self::normalise_headers_for_logging( $args['headers'] );
			}

			if ( array_key_exists( 'body', $args ) ) {
				$request_body = self::summarize_body_for_logging( $args['body'], false );

				if ( ! empty( $request_body ) ) {
					$context['request_body'] = $request_body;
				}
			}

			return $context;
		}

		/**
		 * Build structured context information for HTTP response logging.
		 *
		 * @param mixed  $response HTTP response or WP_Error.
		 * @param array  $args     Original request arguments.
		 * @param string $url      Requested URL.
		 * @param mixed  $class    Transport class or instance.
		 *
		 * @return array
		 */
		protected static function build_http_response_context( $response, $args, $url, $class ) {
			$context = self::build_http_request_context( $args, $url, $class );

			if ( is_wp_error( $response ) ) {
				$context['error'] = self::summarize_wp_error_for_logging( $response );
				return $context;
			}

			if ( is_array( $response ) ) {
				if ( isset( $response['response']['code'] ) ) {
					$context['status_code'] = (int) $response['response']['code'];
				}

				if ( isset( $response['response']['message'] ) ) {
					$context['response_message'] = (string) $response['response']['message'];
				}

				if ( isset( $response['headers'] ) ) {
					$context['response_headers'] = self::normalise_headers_for_logging( $response['headers'] );
				}

				if ( array_key_exists( 'body', $response ) ) {
					$response_body = self::summarize_body_for_logging( $response['body'], true );

					if ( ! empty( $response_body ) ) {
						$context['response_body'] = $response_body;
					}
				}
			}

			return $context;
		}

		/**
		 * Normalise HTTP headers for structured logging.
		 *
		 * @param mixed $headers Header collection from WordPress.
		 *
		 * @return array
		 */
		protected static function normalise_headers_for_logging( $headers ) {
			if ( $headers instanceof WP_HTTP_Headers ) {
				$headers = $headers->getAll();
			}

			if ( ! is_array( $headers ) ) {
				return array();
			}

			$normalised = array();

			foreach ( $headers as $key => $value ) {
				$key = strtolower( (string) $key );

				if ( is_array( $value ) ) {
					$normalised[ $key ] = implode( ', ', array_map( 'strval', $value ) );
				} else {
					$normalised[ $key ] = (string) $value;
				}
			}

			return $normalised;
		}

		/**
		 * Summarize a request or response body for logging.
		 *
		 * @param mixed $body            Request or response body value.
		 * @param bool  $include_preview Whether to include a truncated preview string.
		 *
		 * @return array
		 */
		protected static function summarize_body_for_logging( $body, $include_preview ) {
			if ( null === $body ) {
				return array();
			}

			if ( is_string( $body ) ) {
				$summary = array(
					'type'   => 'string',
					'length' => self::string_length( $body ),
				);

				if ( $include_preview && '' !== $body ) {
					$summary['preview']   = self::truncate_string( $body, self::BODY_PREVIEW_LIMIT );
					$summary['truncated'] = ( self::string_length( $body ) > self::BODY_PREVIEW_LIMIT );
				}

				return $summary;
			}

			if ( is_array( $body ) || is_object( $body ) ) {
				$encoded = wp_json_encode( $body );

				if ( false === $encoded ) {
					return array(
						'type'    => is_object( $body ) ? 'object' : 'array',
						'preview' => '[unserializable body]',
					);
				}

				$summary = array(
					'type'   => is_object( $body ) ? 'object' : 'array',
					'length' => self::string_length( $encoded ),
				);

				if ( $include_preview && '' !== $encoded ) {
					$summary['preview']   = self::truncate_string( $encoded, self::BODY_PREVIEW_LIMIT );
					$summary['truncated'] = ( self::string_length( $encoded ) > self::BODY_PREVIEW_LIMIT );
				}

				return $summary;
			}

			if ( is_scalar( $body ) ) {
				$scalar = (string) $body;

				$summary = array(
					'type'   => 'scalar',
					'length' => self::string_length( $scalar ),
				);

				if ( $include_preview && '' !== $scalar ) {
					$summary['preview']   = self::truncate_string( $scalar, self::BODY_PREVIEW_LIMIT );
					$summary['truncated'] = ( self::string_length( $scalar ) > self::BODY_PREVIEW_LIMIT );
				}

				return $summary;
			}

			return array();
		}

		/**
		 * Build a summary of a WP_Error for logging.
		 *
		 * @param WP_Error $error Error instance.
		 *
		 * @return array
		 */
		protected static function summarize_wp_error_for_logging( WP_Error $error ) {
			$messages = array();

			foreach ( $error->get_error_codes() as $code ) {
				$messages[ $code ] = $error->get_error_messages( $code );
			}

			return array(
				'message'  => $error->get_error_message(),
				'codes'    => $error->get_error_codes(),
				'messages' => $messages,
				'data'     => $error->get_all_error_data(),
			);
		}

		/**
		 * Truncate a string to the configured preview limit.
		 *
		 * @param string $value Source string.
		 * @param int    $limit Maximum number of characters.
		 *
		 * @return string
		 */
		protected static function truncate_string( $value, $limit ) {
			$value = (string) $value;
			$limit = absint( $limit );

			if ( 0 === $limit ) {
				return '';
			}

			if ( self::string_length( $value ) <= $limit ) {
				return $value;
			}

			return self::string_substr( $value, 0, $limit ) . '…';
		}

		/**
		 * Determine the length of a string with multibyte awareness.
		 *
		 * @param string $value Input string.
		 *
		 * @return int
		 */
		protected static function string_length( $value ) {
			if ( function_exists( 'mb_strlen' ) ) {
				return mb_strlen( $value, 'UTF-8' );
			}

			return strlen( $value );
		}

		/**
		 * Multibyte aware substring helper.
		 *
		 * @param string $value Source string.
		 * @param int    $start Starting offset.
		 * @param int    $length Number of characters to return.
		 *
		 * @return string
		 */
		protected static function string_substr( $value, $start, $length ) {
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $value, $start, $length, 'UTF-8' );
			}

			return substr( $value, $start, $length );
		}

		/**
		 * Prepare a transport error, promoting WordPress timeout failures and connection refused errors to actionable guidance.
		 *
		 * @param WP_Error $transport_error Raw transport error returned by WordPress.
		 * @param string   $default_code    Error code to use when the error is not a timeout or connection refused.
		 * @param string   $default_message Fallback error message.
		 * @param string   $service_label   Optional human readable service name.
		 * @param array    $data            Optional error data to merge.
		 *
		 * @return WP_Error
		 */
		public static function prepare_transport_error(
			$transport_error,
			$default_code,
			$default_message,
			$service_label = '',
			array $data = array()
		) {
			if ( ! $transport_error instanceof WP_Error ) {
				return new WP_Error( $default_code, $default_message, $data );
			}

			$data          = is_array( $data ) ? $data : array();
			$data['error'] = $transport_error;

			// Check for connection refused errors first (more specific than timeout).
			if ( self::is_connection_refused_error( $transport_error ) ) {
				$message = self::build_connection_refused_message( $service_label );

				$actions = array(
					'check_service_running'   => __( 'Ensure the service is running and accepting connections.', 'wp-mcp-ai' ),
					'verify_endpoint_url'     => __( 'Verify the endpoint URL and port number are correct in Settings → NV oOS.', 'wp-mcp-ai' ),
					'check_firewall'          => __( 'Check that no firewall is blocking connections to the service.', 'wp-mcp-ai' ),
					'check_service_listening' => __( 'Confirm the service is listening on the correct interface (0.0.0.0 or the specific IP).', 'wp-mcp-ai' ),
				);

				if ( isset( $data['actions'] ) && is_array( $data['actions'] ) ) {
					$data['actions'] = $actions + $data['actions'];
				} else {
					$data['actions'] = $actions;
				}

				if ( ! isset( $data['status'] ) ) {
					$data['status'] = 502;
				}

				return new WP_Error( 'wp_mcp_ai_connection_refused', $message, $data );
			}

			if ( self::is_wordpress_timeout_error( $transport_error ) ) {
				$message = self::build_timeout_message( $service_label );

				$actions = array(
					'configure_request_timeout' => __( 'Increase the request timeout under Settings → NV oOS.', 'wp-mcp-ai' ),
					'check_server_connectivity' => __( 'Confirm your server can reach the remote service without firewall or network blocks.', 'wp-mcp-ai' ),
				);

				if ( isset( $data['actions'] ) && is_array( $data['actions'] ) ) {
					$data['actions'] = $actions + $data['actions'];
				} else {
					$data['actions'] = $actions;
				}

				if ( ! isset( $data['status'] ) ) {
					$data['status'] = 504;
				}

				return new WP_Error( 'wp_mcp_ai_wordpress_timeout', $message, $data );
			}

			return new WP_Error( $default_code, $default_message, $data );
		}

		/**
		 * Determine whether the supplied error represents a WordPress transport timeout.
		 *
		 * @param WP_Error $error Error object returned by the HTTP API.
		 *
		 * @return bool
		 */
		public static function is_wordpress_timeout_error( $error ) {
			if ( ! $error instanceof WP_Error ) {
				return false;
			}

			foreach ( $error->get_error_codes() as $code ) {
				if ( 'http_request_timeout' === $code ) {
					return true;
				}

				$messages = $error->get_error_messages( $code );
				foreach ( $messages as $message ) {
					if ( self::message_indicates_timeout( $message ) ) {
						return true;
					}
				}
			}

			foreach ( $error->get_error_messages() as $message ) {
				if ( self::message_indicates_timeout( $message ) ) {
					return true;
				}
			}

			$data = $error->get_error_data();
			if ( is_array( $data ) ) {
				if ( isset( $data['timeout'] ) && $data['timeout'] ) {
					return true;
				}

				if ( isset( $data['status'] ) && 504 === (int) $data['status'] ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Build a timeout error message tailored to the service label.
		 *
		 * @param string $service_label Optional human readable service name.
		 *
		 * @return string
		 */
		protected static function build_timeout_message( $service_label ) {
			$service_label = is_string( $service_label ) ? trim( wp_strip_all_tags( $service_label ) ) : '';

			if ( '' !== $service_label ) {
				/* translators: %s: Human readable remote service label. */
				return sprintf( __( 'WordPress timed out waiting for a response from %s.', 'wp-mcp-ai' ), $service_label );
			}

			return __( 'WordPress timed out waiting for a response.', 'wp-mcp-ai' );
		}

		/**
		 * Detect whether the supplied error message indicates a timeout condition.
		 *
		 * @param string $message Error message from WordPress.
		 *
		 * @return bool
		 */
		protected static function message_indicates_timeout( $message ) {
			if ( ! is_string( $message ) || '' === $message ) {
				return false;
			}

			$normalised = strtolower( $message );

			$needles = array(
				'timed out',
				'timeout',
				'time-out',
				'operation timed out',
				'request timed out',
				'curl error 28',
			);

			foreach ( $needles as $needle ) {
				if ( false !== strpos( $normalised, $needle ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Determine whether the supplied error represents a connection refused error.
		 *
		 * Connection refused errors occur when:
		 * - The target service is not running
		 * - The service is not listening on the specified port
		 * - The service is listening on a different interface (e.g., 127.0.0.1 instead of 0.0.0.0)
		 * - A firewall is blocking the connection
		 *
		 * Common error patterns:
		 * - "Connection refused" (ECONNREFUSED - errno 111 on Linux, 10061 on Windows)
		 * - "No connection could be made because the target machine actively refused it" (Windows)
		 * - "dial tcp [::1]:1234: connectex: No connection could be made" (Go/Cloudflared)
		 * - "cURL error 7: Failed to connect"
		 * - "context canceled" (when connection is aborted)
		 *
		 * @param WP_Error $error Error object returned by the HTTP API.
		 *
		 * @return bool
		 */
		public static function is_connection_refused_error( $error ) {
			if ( ! $error instanceof WP_Error ) {
				return false;
			}

			foreach ( $error->get_error_codes() as $code ) {
				// Check for known connection refused error codes.
				if ( in_array( $code, array( 'http_request_failed', 'http_failure', 'http_no_url' ), true ) ) {
					$messages = $error->get_error_messages( $code );
					foreach ( $messages as $message ) {
						if ( self::message_indicates_connection_refused( $message ) ) {
							return true;
						}
					}
				}
			}

			foreach ( $error->get_error_messages() as $message ) {
				if ( self::message_indicates_connection_refused( $message ) ) {
					return true;
				}
			}

			$data = $error->get_error_data();
			if ( is_array( $data ) ) {
				// Check for connection refused status codes.
				if ( isset( $data['status'] ) && in_array( (int) $data['status'], array( 502, 503 ), true ) ) {
					// Only treat as connection refused if the message also indicates it.
					foreach ( $error->get_error_messages() as $message ) {
						if ( self::message_indicates_connection_refused( $message ) ) {
							return true;
						}
					}
				}
			}

			return false;
		}

		/**
		 * Detect whether the supplied error message indicates a connection refused condition.
		 *
		 * @param string $message Error message from WordPress or cURL.
		 *
		 * @return bool
		 */
		protected static function message_indicates_connection_refused( $message ) {
			if ( ! is_string( $message ) || '' === $message ) {
				return false;
			}

			$normalised = strtolower( $message );

			$needles = array(
				'connection refused',
				'no connection could be made',
				'target machine actively refused',
				'failed to connect',
				'curl error 7',
				'couldn\'t connect to',
				'unable to connect',
				'unable to reach',
				'context canceled',
				'context cancelled',
				'connection reset',
				'errno 111',  // Linux ECONNREFUSED.
				'errno 10061', // Windows WSAECONNREFUSED.
			);

			foreach ( $needles as $needle ) {
				if ( false !== strpos( $normalised, $needle ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Build a connection refused error message tailored to the service label.
		 *
		 * @param string $service_label Optional human readable service name.
		 *
		 * @return string
		 */
		protected static function build_connection_refused_message( $service_label ) {
			$service_label = is_string( $service_label ) ? trim( wp_strip_all_tags( $service_label ) ) : '';

			if ( '' !== $service_label ) {
				/* translators: %s: Human readable remote service label. */
				return sprintf( __( 'Could not connect to %s. The service may not be running or is refusing connections.', 'wp-mcp-ai' ), $service_label );
			}

			return __( 'Could not connect to the service. It may not be running or is refusing connections.', 'wp-mcp-ai' );
		}
	}
}
