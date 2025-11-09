<?php
/**
 * Correlation ID Manager for WP oOS
 *
 * Generates and tracks correlation IDs for distributed request tracing.
 * Each request gets a unique UUID that is threaded through all logs,
 * API calls, and responses.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Correlation_ID' ) ) {
	/**
	 * Correlation ID manager for request tracing.
	 */
	class WP_MCP_AI_Correlation_ID {

		/**
		 * Header name for correlation ID.
		 */
		const HEADER_NAME = 'X-Correlation-ID';

		/**
		 * Current correlation ID for this request.
		 *
		 * @var string|null
		 */
		private static $current_id = null;

		/**
		 * Parent correlation IDs (for nested requests).
		 *
		 * @var array
		 */
		private static $parent_ids = array();

		/**
		 * Initialize correlation ID system.
		 */
		public static function init() {
			// Generate or retrieve correlation ID early in request lifecycle.
			add_action( 'init', array( __CLASS__, 'ensure_correlation_id' ), 1 );

			// Add correlation ID to REST API responses.
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_to_rest_response' ), 10, 3 );

			// Add correlation ID to all logs.
			add_filter( 'wp_mcp_ai_log_context', array( __CLASS__, 'add_to_log_context' ), 10, 1 );
		}

		/**
		 * Ensure correlation ID exists for current request.
		 */
		public static function ensure_correlation_id() {
			if ( null === self::$current_id ) {
				self::$current_id = self::get_or_generate_id();
			}
		}

		/**
		 * Get or generate correlation ID for current request.
		 *
		 * @return string UUID correlation ID.
		 */
		private static function get_or_generate_id() {
			// Check if correlation ID was provided in request header.
			$header_id = self::get_from_header();
			if ( $header_id ) {
				return $header_id;
			}

			// Check if correlation ID exists in query parameter.
			$query_id = self::get_from_query();
			if ( $query_id ) {
				return $query_id;
			}

			// Generate new correlation ID.
			return self::generate_uuid();
		}

		/**
		 * Get correlation ID from request header.
		 *
		 * @return string|null
		 */
		private static function get_from_header() {
			// Check standard header.
			$header_value = null;

			if ( function_exists( 'getallheaders' ) ) {
				$headers = getallheaders();
				if ( isset( $headers[ self::HEADER_NAME ] ) ) {
					$header_value = $headers[ self::HEADER_NAME ];
				}
			}

			// Fallback to $_SERVER.
			if ( ! $header_value ) {
				$server_key = 'HTTP_' . str_replace( '-', '_', strtoupper( self::HEADER_NAME ) );
				if ( isset( $_SERVER[ $server_key ] ) ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
					$header_value = wp_unslash( $_SERVER[ $server_key ] );
				}
			}

			if ( $header_value && self::validate_uuid( $header_value ) ) {
				return sanitize_text_field( $header_value );
			}

			return null;
		}

		/**
		 * Get correlation ID from query parameter.
		 *
		 * @return string|null
		 */
		private static function get_from_query() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only correlation ID.
			if ( isset( $_GET['correlation_id'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
				$query_id = wp_unslash( $_GET['correlation_id'] );
				if ( self::validate_uuid( $query_id ) ) {
					return sanitize_text_field( $query_id );
				}
			}

			return null;
		}

		/**
		 * Generate a UUID v4.
		 *
		 * @return string UUID.
		 */
		private static function generate_uuid() {
			// Use WordPress function if available (WP 6.7+).
			if ( function_exists( 'wp_generate_uuid4' ) ) {
				return wp_generate_uuid4();
			}

			// Fallback UUID v4 generation.
			$data = random_bytes( 16 );

			// Set version to 0100.
			$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
			// Set bits 6-7 to 10.
			$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );

			return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
		}

		/**
		 * Validate UUID format.
		 *
		 * @param string $uuid UUID to validate.
		 * @return bool
		 */
		private static function validate_uuid( $uuid ) {
			$pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
			return (bool) preg_match( $pattern, $uuid );
		}

		/**
		 * Get current correlation ID.
		 *
		 * @return string
		 */
		public static function get_current_id() {
			self::ensure_correlation_id();
			return self::$current_id;
		}

		/**
		 * Set correlation ID for current request.
		 *
		 * Useful for setting a specific ID when making outbound requests.
		 *
		 * @param string $id Correlation ID.
		 * @return bool True if set, false if invalid.
		 */
		public static function set_current_id( $id ) {
			if ( ! self::validate_uuid( $id ) ) {
				return false;
			}

			self::$current_id = $id;
			return true;
		}

		/**
		 * Create a child correlation ID for nested operations.
		 *
		 * @return string Child correlation ID.
		 */
		public static function create_child_id() {
			self::ensure_correlation_id();

			// Store parent ID.
			self::$parent_ids[] = self::$current_id;

			// Generate new child ID.
			self::$current_id = self::generate_uuid();

			return self::$current_id;
		}

		/**
		 * Restore parent correlation ID.
		 *
		 * @return string|null Restored parent ID or null if no parent.
		 */
		public static function restore_parent_id() {
			if ( empty( self::$parent_ids ) ) {
				return null;
			}

			self::$current_id = array_pop( self::$parent_ids );
			return self::$current_id;
		}

		/**
		 * Get parent correlation IDs.
		 *
		 * @return array
		 */
		public static function get_parent_ids() {
			return self::$parent_ids;
		}

		/**
		 * Add correlation ID to REST API response headers.
		 *
		 * @param WP_HTTP_Response $result  Result to send to the client.
		 * @param WP_REST_Server   $server  Server instance.
		 * @param WP_REST_Request  $request Request used to generate the response.
		 * @return WP_HTTP_Response
		 */
		public static function add_to_rest_response( $result, $server, $request ) {
			$result->header( self::HEADER_NAME, self::get_current_id() );

			// Also add to response body if debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$data = $result->get_data();
				if ( is_array( $data ) ) {
					$data['_correlation_id'] = self::get_current_id();
					$result->set_data( $data );
				}
			}

			return $result;
		}

		/**
		 * Add correlation ID to log context.
		 *
		 * @param array $context Log context.
		 * @return array
		 */
		public static function add_to_log_context( $context ) {
			if ( ! isset( $context['correlation_id'] ) ) {
				$context['correlation_id'] = self::get_current_id();
			}

			// Add parent IDs if present.
			if ( ! empty( self::$parent_ids ) ) {
				$context['parent_correlation_ids'] = self::$parent_ids;
			}

			return $context;
		}

		/**
		 * Get correlation ID for outbound HTTP request.
		 *
		 * Adds correlation ID header to HTTP request args.
		 *
		 * @param array $args HTTP request args.
		 * @return array Modified args with correlation ID header.
		 */
		public static function add_to_http_args( $args ) {
			if ( ! isset( $args['headers'] ) ) {
				$args['headers'] = array();
			}

			$args['headers'][ self::HEADER_NAME ] = self::get_current_id();

			return $args;
		}

		/**
		 * Create a scoped correlation ID.
		 *
		 * Executes a callback with a new correlation ID, then restores the previous ID.
		 *
		 * @param callable $callback Function to execute with new correlation ID.
		 * @param array    $args     Arguments to pass to callback.
		 * @return mixed Callback return value.
		 */
		public static function with_new_id( $callback, $args = array() ) {
			// Save current ID.
			$previous_id = self::$current_id;

			// Create new child ID.
			self::create_child_id();

			// Execute callback.
			$result = call_user_func_array( $callback, $args );

			// Restore previous ID.
			self::$current_id = $previous_id;
			if ( ! empty( self::$parent_ids ) ) {
				array_pop( self::$parent_ids );
			}

			return $result;
		}

		/**
		 * Get correlation chain as string.
		 *
		 * Returns a string representation of the full correlation chain.
		 *
		 * @return string
		 */
		public static function get_correlation_chain() {
			$chain = array_merge( self::$parent_ids, array( self::get_current_id() ) );
			return implode( ' > ', $chain );
		}

		/**
		 * Reset correlation ID state (for testing).
		 */
		public static function reset() {
			self::$current_id  = null;
			self::$parent_ids = array();
		}
	}
}
