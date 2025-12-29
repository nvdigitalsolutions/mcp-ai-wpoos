<?php
/**
 * Centralized error handler for WP oOS.
 *
 * Provides consistent error handling across the plugin with user-friendly
 * messages and recovery suggestions.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Error_Handler' ) ) {
	/**
	 * Handles error creation, logging, and user-friendly error responses.
	 *
	 * This class serves as a centralized error handler that:
	 * - Creates consistent WP_Error objects with appropriate context
	 * - Logs errors with proper severity levels
	 * - Generates user-friendly error messages
	 * - Provides recovery suggestions
	 */
	class WP_MCP_AI_Error_Handler {

		/**
		 * Create a WP_Error with logging and user-friendly message.
		 *
		 * This method creates a WP_Error object, logs the error with appropriate
		 * severity, and optionally enriches it with user-friendly suggestions.
		 *
		 * @since 1.0.0
		 *
		 * @param string $code     Error code (machine-readable identifier).
		 * @param string $message  Technical error message.
		 * @param array  $data     Optional error data (status, context, etc.).
		 * @param string $severity Log severity level (critical, error, warning, info, debug).
		 * @param bool   $add_suggestions Whether to add user-friendly suggestions.
		 * @return WP_Error
		 */
		public static function create_error( $code, $message, $data = array(), $severity = WP_MCP_AI_Logger::LEVEL_ERROR, $add_suggestions = true ) {
			$code    = sanitize_key( $code );
			$message = (string) $message;

			// Ensure data is an array.
			if ( ! is_array( $data ) ) {
				$data = array();
			}

			// Log the error with appropriate severity.
			$log_context = array(
				'error_code' => $code,
				'error_data' => $data,
			);

			switch ( $severity ) {
				case WP_MCP_AI_Logger::LEVEL_CRITICAL:
					WP_MCP_AI_Logger::log_critical( $message, $log_context );
					break;
				case WP_MCP_AI_Logger::LEVEL_WARNING:
					WP_MCP_AI_Logger::log_warning( $message, $log_context );
					break;
				case WP_MCP_AI_Logger::LEVEL_INFO:
					WP_MCP_AI_Logger::log_info( $message, $log_context );
					break;
				case WP_MCP_AI_Logger::LEVEL_DEBUG:
					WP_MCP_AI_Logger::log_debug( $message, $log_context );
					break;
				default:
					WP_MCP_AI_Logger::log_error( $message, $log_context );
					break;
			}

			// Add user-friendly message and suggestions if requested.
			if ( $add_suggestions ) {
				$friendly = WP_MCP_AI_Logger::get_user_friendly_error( $code, $message, $data );
				if ( ! empty( $friendly['message'] ) ) {
					$data['user_message'] = $friendly['message'];
				}
				if ( ! empty( $friendly['suggestions'] ) ) {
					$data['suggestions'] = $friendly['suggestions'];
				}
			}

			return new WP_Error( $code, $message, $data );
		}

		/**
		 * Create a REST API error response with enriched context.
		 *
		 * Generates a WP_Error suitable for REST API endpoints with proper
		 * HTTP status codes and user-friendly messaging.
		 *
		 * @since 1.0.0
		 *
		 * @param string $code    Error code.
		 * @param string $message Technical error message.
		 * @param int    $status  HTTP status code (default 400).
		 * @param array  $context Additional error context.
		 * @return WP_Error
		 */
		public static function create_rest_error( $code, $message, $status = 400, $context = array() ) {
			$data = array_merge(
				array( 'status' => absint( $status ) ),
				$context
			);

			// Determine severity based on status code.
			$severity = self::get_severity_from_status( $status );

			return self::create_error( $code, $message, $data, $severity, true );
		}

		/**
		 * Create an API client error (external API failures).
		 *
		 * Handles errors from external APIs (OpenAI, Gemini, etc.) with
		 * appropriate context and recovery suggestions.
		 *
		 * @since 1.0.0
		 *
		 * @param string $provider     API provider name (openai, gemini, anthropic, etc.).
		 * @param string $message      Technical error message.
		 * @param array  $api_response Raw API response for context.
		 * @param int    $status_code  HTTP status code from API.
		 * @return WP_Error
		 */
		public static function create_api_error( $provider, $message, $api_response = array(), $status_code = 500 ) {
			$provider = sanitize_key( $provider );
			$code     = $provider . '_api_error';

			$context = array(
				'provider'    => $provider,
				'status_code' => absint( $status_code ),
			);

			// Add relevant API response data without sensitive info.
			if ( ! empty( $api_response ) ) {
				$safe_response = self::sanitize_api_response( $api_response );
				if ( ! empty( $safe_response ) ) {
					$context['api_response'] = $safe_response;
				}
			}

			return self::create_rest_error( $code, $message, 500, $context );
		}

		/**
		 * Create a validation error.
		 *
		 * Used for input validation failures with clear guidance on what's wrong.
		 *
		 * @since 1.0.0
		 *
		 * @param string $field   Field name that failed validation.
		 * @param string $message Validation error message.
		 * @param array  $context Additional context.
		 * @return WP_Error
		 */
		public static function create_validation_error( $field, $message, $context = array() ) {
			$code = 'validation_error_' . sanitize_key( $field );

			$data = array_merge(
				array(
					'status' => 400,
					'field'  => sanitize_key( $field ),
				),
				$context
			);

			return self::create_error( $code, $message, $data, WP_MCP_AI_Logger::LEVEL_WARNING, true );
		}

		/**
		 * Create an authentication/authorization error.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message Error message.
		 * @param array  $context Additional context.
		 * @return WP_Error
		 */
		public static function create_auth_error( $message, $context = array() ) {
			$data = array_merge(
				array( 'status' => 401 ),
				$context
			);

			return self::create_error( 'authentication_failed', $message, $data, WP_MCP_AI_Logger::LEVEL_WARNING, true );
		}

		/**
		 * Create a permission denied error.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message     Error message.
		 * @param string $capability  Required capability.
		 * @param array  $context     Additional context.
		 * @return WP_Error
		 */
		public static function create_permission_error( $message, $capability = '', $context = array() ) {
			$data = array_merge(
				array( 'status' => 403 ),
				$context
			);

			if ( ! empty( $capability ) ) {
				$data['required_capability'] = sanitize_key( $capability );
			}

			return self::create_error( 'permission_denied', $message, $data, WP_MCP_AI_Logger::LEVEL_WARNING, true );
		}

		/**
		 * Create a rate limit error.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message        Error message.
		 * @param int    $retry_after    Seconds until retry is allowed.
		 * @param array  $context        Additional context.
		 * @return WP_Error
		 */
		public static function create_rate_limit_error( $message, $retry_after = 60, $context = array() ) {
			$data = array_merge(
				array(
					'status'      => 429,
					'retry_after' => absint( $retry_after ),
				),
				$context
			);

			return self::create_error( 'rate_limit_exceeded', $message, $data, WP_MCP_AI_Logger::LEVEL_WARNING, true );
		}

		/**
		 * Determine log severity based on HTTP status code.
		 *
		 * @since 1.0.0
		 *
		 * @param int $status HTTP status code.
		 * @return string Log severity level.
		 */
		protected static function get_severity_from_status( $status ) {
			$status = absint( $status );

			// 5xx errors are critical server errors.
			if ( $status >= 500 ) {
				return WP_MCP_AI_Logger::LEVEL_CRITICAL;
			}

			// 4xx errors are client errors (warnings).
			if ( $status >= 400 ) {
				return WP_MCP_AI_Logger::LEVEL_WARNING;
			}

			// 3xx redirects shouldn't happen but log as info.
			if ( $status >= 300 ) {
				return WP_MCP_AI_Logger::LEVEL_INFO;
			}

			// 2xx success (shouldn't be logging errors here).
			return WP_MCP_AI_Logger::LEVEL_ERROR;
		}

		/**
		 * Sanitize API response data for logging.
		 *
		 * Removes sensitive information while preserving useful debugging data.
		 *
		 * @since 1.0.0
		 *
		 * @param array $response API response data.
		 * @return array Sanitized response.
		 */
		protected static function sanitize_api_response( $response ) {
			if ( ! is_array( $response ) ) {
				return array();
			}

			$safe_response = array();

			// Include common error fields.
			$safe_fields = array( 'error', 'message', 'type', 'code', 'param' );

			foreach ( $safe_fields as $field ) {
				if ( isset( $response[ $field ] ) ) {
					$safe_response[ $field ] = $response[ $field ];
				}
			}

			// Include error object if present.
			if ( isset( $response['error'] ) && is_array( $response['error'] ) ) {
				$safe_response['error'] = array_intersect_key(
					$response['error'],
					array_flip( array( 'message', 'type', 'code', 'param' ) )
				);
			}

			return $safe_response;
		}

		/**
		 * Check if a WP_Error should be logged.
		 *
		 * Some errors are expected and shouldn't clutter logs.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_Error $error Error object.
		 * @return bool True if should be logged.
		 */
		public static function should_log_error( $error ) {
			if ( ! is_wp_error( $error ) ) {
				return false;
			}

			$code = $error->get_error_code();

			// Don't log expected validation errors in production.
			$skip_codes = array(
				'rest_invalid_param',
				'rest_missing_callback_param',
			);

			/**
			 * Filter the list of error codes that should not be logged.
			 *
			 * @since 1.0.0
			 *
			 * @param array $skip_codes Array of error codes to skip logging.
			 */
			$skip_codes = apply_filters( 'wp_mcp_ai_skip_error_logging', $skip_codes );

			return ! in_array( $code, $skip_codes, true );
		}

		/**
		 * Format a WP_Error for display to end users.
		 *
		 * Extracts the user-friendly message and suggestions if available,
		 * falling back to the technical message.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_Error $error Error object.
		 * @return array Array with 'message' and 'suggestions' keys.
		 */
		public static function format_error_for_display( $error ) {
			if ( ! is_wp_error( $error ) ) {
				return array(
					'message'     => __( 'An unknown error occurred.', 'wp-mcp-ai' ),
					'suggestions' => array(),
				);
			}

			$data = $error->get_error_data();

			// Use user-friendly message if available.
			$message = ! empty( $data['user_message'] )
				? $data['user_message']
				: $error->get_error_message();

			// Get suggestions if available.
			$suggestions = ! empty( $data['suggestions'] ) && is_array( $data['suggestions'] )
				? $data['suggestions']
				: array();

			return array(
				'message'     => $message,
				'suggestions' => $suggestions,
			);
		}
	}
}
