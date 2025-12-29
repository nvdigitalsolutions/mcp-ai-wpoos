<?php
/**
 * Simple logging utility for NV oOS.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	/**
	 * Helper for writing structured log entries.
	 */
	class WP_MCP_AI_Logger {
		/**
		 * Prefix that is added to every log line for easier filtering.
		 */
		const PREFIX = '[WP oOS]';

		/**
		 * Log severity levels.
		 */
		const LEVEL_CRITICAL = 'critical';
		const LEVEL_ERROR    = 'error';
		const LEVEL_WARNING  = 'warning';
		const LEVEL_INFO     = 'info';
		const LEVEL_DEBUG    = 'debug';

		/**
		 * Option key for persisting recent error and warning entries.
		 */
		const RECENT_ERRORS_OPTION = 'wp_mcp_ai_recent_errors';

		/**
		 * Option key for persisting recent activity entries such as tool executions.
		 */
		const RECENT_ACTIVITY_OPTION = 'wp_mcp_ai_recent_activity';

		/**
		 * Maximum number of characters that should be written to the PHP error log
		 * for a single entry. PHP-FPM buffers log lines at 1024 bytes so we keep a
		 * safety margin below that threshold to avoid truncation warnings.
		 */
		const MAX_LOG_LINE_LENGTH = 900;

		/**
		 * Cache of the detected PHP error log path.
		 *
		 * @var string|null
		 */
		protected static $log_file_path = null;

		/**
		 * Reset the cached log file path. Primarily used in automated tests.
		 */
		public static function reset_log_file_cache() {
			self::$log_file_path = null;
		}

		/**
		 * Record a generic log event when logging is enabled.
		 *
		 * @param string $type    Event type (chat_request, tool_result, error, etc.).
		 * @param string $message Human readable description of the event.
		 * @param array  $context Additional context for the entry.
		 */
		public static function log_event( $type, $message, $context = array() ) {
			// Check base logging first.
			if ( ! WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
				return;
			}

			$type        = sanitize_key( $type );
			$message     = (string) $message;
			$raw_context = is_array( $context ) ? $context : array();

			// Check granular logging settings based on event type.
			if ( ! self::should_log_event_type( $type ) ) {
				return;
			}

			$context = self::sanitize_context( $raw_context );

			$entry = array(
				'timestamp' => current_time( 'mysql', true ),
				'type'      => $type,
				'message'   => $message,
				'context'   => $context,
			);

			/**
			 * Allow third parties to filter the final log entry.
			 *
			 * Returning `false` from this filter stops the entry from being logged.
			 *
			 * @param array  $entry   Prepared log entry.
			 * @param string $type    Event type.
			 * @param string $message Log message.
			 * @param array  $context Raw context array prior to sanitization.
			 */
			$entry = apply_filters( 'wp_mcp_ai_log_entry', $entry, $type, $message, $raw_context );
			if ( false === $entry ) {
				return;
			}

			if ( self::should_store_recent_entry( $entry ) ) {
				self::store_recent_entry( $entry );
			}

			if ( self::should_store_recent_activity_entry( $entry ) ) {
				self::store_recent_activity_entry( $entry );
			}

			$line = sprintf( '%s %s: %s', self::PREFIX, strtoupper( $entry['type'] ), $entry['message'] );

			if ( ! empty( $entry['context'] ) ) {
				$context_json = wp_json_encode( $entry['context'] );

				if ( false !== $context_json && '' !== $context_json ) {
					$available = self::MAX_LOG_LINE_LENGTH - self::string_length( $line ) - 1;

					if ( $available > 0 ) {
						if ( self::string_length( $context_json ) > $available ) {
							$preview_limit = max( 0, $available - 40 );
							$preview       = $preview_limit > 0 ? self::truncate_string( $context_json, $preview_limit ) : '';
							$context_json  = wp_json_encode(
								array(
									'truncated' => true,
									'preview'   => $preview,
								)
							);

							if ( false === $context_json ) {
								$context_json = '';
							}
						}

						if ( '' !== $context_json ) {
							if ( self::string_length( $context_json ) > $available ) {
								$context_json = self::truncate_string( $context_json, $available );
							}

							$line .= ' ' . $context_json;
						}
					}
				}
			}

			$line = self::truncate_string( $line, self::MAX_LOG_LINE_LENGTH );

			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		/**
		 * Check if an event type should be logged based on granular settings.
		 *
		 * @param string $type Event type.
		 * @return bool True if the event should be logged.
		 */
		protected static function should_log_event_type( $type ) {
			// Always log errors, warnings, and critical messages.
			if ( in_array( $type, array( self::LEVEL_CRITICAL, self::LEVEL_ERROR, self::LEVEL_WARNING, 'error', 'warning' ), true ) ) {
				return true;
			}

			// Check granular settings for specific event types.
			// Agentic loop events.
			if ( strpos( $type, 'agentic' ) !== false ) {
				return WP_MCP_AI_Admin_Settings::is_agentic_loop_logging_enabled();
			}

			// API request/response events.
			$api_event_types = array(
				'openai_request',
				'openai_response',
				'anthropic_request',
				'anthropic_response',
				'gemini_request',
				'gemini_response',
				'gemini_image_request',
				'gemini_image_response',
				'gemini_list_models_response',
				'gemini_count_tokens',
				'gemini_count_tokens_response',
				'gemini_create_embedding',
				'gemini_embedding_response',
				'gemini_stream_request',
				'gemini_stream_response',
				'lm_studio_request',
				'lm_studio_response',
				'lm_studio_completion_request',
				'lm_studio_completion_response',
				'openai_external_action_request',
				'openai_external_action_response',
			);
			if ( in_array( $type, $api_event_types, true ) ) {
				return WP_MCP_AI_Admin_Settings::is_api_logging_enabled();
			}

			// Tool execution events.
			if ( in_array( $type, array( 'tool_execution', 'tool_error' ), true ) ) {
				return WP_MCP_AI_Admin_Settings::is_tool_execution_logging_enabled();
			}

			// Chat interaction events.
			if ( $type === 'chat_interaction' ) {
				return WP_MCP_AI_Admin_Settings::is_chat_interaction_logging_enabled();
			}

			// Default: allow other event types when base logging is enabled.
			return true;
		}

		/**
		 * Retrieve the absolute path to the PHP error log if available.
		 *
		 * @return string Empty string when the log path cannot be determined or is
		 *                configured to forward to syslog.
		 */
		public static function get_log_file_path() {
			if ( null !== self::$log_file_path ) {
				return self::$log_file_path;
			}

			$path = ini_get( 'error_log' );

			if ( ! is_string( $path ) ) {
				self::$log_file_path = '';
				return self::$log_file_path;
			}

			$path = trim( $path );

			if ( '' === $path ) {
				self::$log_file_path = '';
				return self::$log_file_path;
			}

			if ( 'syslog' === strtolower( $path ) ) {
				self::$log_file_path = '';
				return self::$log_file_path;
			}

			if ( function_exists( 'wp_normalize_path' ) ) {
				$path = wp_normalize_path( $path );
			}

			self::$log_file_path = $path;

			return self::$log_file_path;
		}

		/**
		 * Determine whether the PHP error log exists on disk.
		 *
		 * @return bool
		 */
		public static function does_log_file_exist() {
			$path = self::get_log_file_path();

			if ( '' === $path ) {
				return false;
			}

			return file_exists( $path ) && is_file( $path );
		}

		/**
		 * Retrieve the current size of the PHP error log in bytes.
		 *
		 * @return int|null Returns `null` when the size cannot be determined.
		 */
		public static function get_log_file_size() {
			$path = self::get_log_file_path();

			if ( '' === $path ) {
				return null;
			}

			if ( ! file_exists( $path ) || ! is_file( $path ) ) {
				return null;
			}

			$size = filesize( $path );

			if ( false === $size ) {
				return null;
			}

			return (int) $size;
		}

		/**
		 * Determine whether the current environment allows pruning the PHP error log.
		 *
		 * @return bool
		 */
		public static function can_prune_error_log() {
			$path = self::get_log_file_path();

			if ( '' === $path ) {
				return false;
			}

			if ( ! file_exists( $path ) || ! is_file( $path ) ) {
				return false;
			}

			return is_writable( $path );
		}

		/**
		 * Truncate the PHP error log when it exists and is writable.
		 *
		 * @return true|WP_Error
		 */
		public static function prune_error_log() {
			$path = self::get_log_file_path();

			if ( '' === $path ) {
				return new WP_Error(
					'wp_mcp_ai_log_missing',
					__( 'The PHP error log path could not be determined.', 'wp-mcp-ai' )
				);
			}

			if ( ! file_exists( $path ) || ! is_file( $path ) ) {
				return new WP_Error(
					'wp_mcp_ai_log_unavailable',
					__( 'The PHP error log has not been created yet.', 'wp-mcp-ai' )
				);
			}

			if ( ! is_writable( $path ) ) {
				return new WP_Error(
					'wp_mcp_ai_log_unwritable',
					__( 'The PHP error log is not writable. Update the file permissions and try again.', 'wp-mcp-ai' )
				);
			}

			$handle = fopen( $path, 'w' );

			if ( false === $handle ) {
				return new WP_Error(
					'wp_mcp_ai_log_failed',
					__( 'The PHP error log could not be truncated.', 'wp-mcp-ai' )
				);
			}

			fclose( $handle );

			delete_option( self::RECENT_ERRORS_OPTION );
			delete_option( self::RECENT_ACTIVITY_OPTION );

			return true;
		}

		/**
		 * Convenience wrapper for logging errors.
		 *
		 * @param string $message Error message.
		 * @param array  $context Optional context.
		 */
		public static function log_error( $message, $context = array() ) {
			self::log_event( self::LEVEL_ERROR, $message, $context );
		}

		/**
		 * Log a critical error that requires immediate attention.
		 *
		 * Critical errors indicate system failures or security issues that
		 * prevent core functionality from working.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message Error message.
		 * @param array  $context Optional context including suggested fixes.
		 */
		public static function log_critical( $message, $context = array() ) {
			self::log_event( self::LEVEL_CRITICAL, $message, $context );
		}

		/**
		 * Log a warning message.
		 *
		 * Warnings indicate potential issues that don't prevent functionality
		 * but should be addressed.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message Warning message.
		 * @param array  $context Optional context.
		 */
		public static function log_warning( $message, $context = array() ) {
			self::log_event( self::LEVEL_WARNING, $message, $context );
		}

		/**
		 * Log an informational message.
		 *
		 * Info messages provide general information about plugin operations.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message Info message.
		 * @param array  $context Optional context.
		 */
		public static function log_info( $message, $context = array() ) {
			self::log_event( self::LEVEL_INFO, $message, $context );
		}

		/**
		 * Log a debug message.
		 *
		 * Debug messages provide detailed information for troubleshooting.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message Debug message.
		 * @param array  $context Optional context.
		 */
		public static function log_debug( $message, $context = array() ) {
			self::log_event( self::LEVEL_DEBUG, $message, $context );
		}

		/**
		 * Log a chat request/response interaction.
		 *
		 * @param int   $assistant_id Assistant identifier.
		 * @param array $messages     Sanitized message payload.
		 * @param array $options      Request options.
		 * @param array $response     Response payload (if any).
		 * @param int   $user_id      Acting user ID.
		 */
		public static function log_chat_interaction( $assistant_id, $messages, $options, $response, $user_id ) {
			self::log_event(
				'chat_interaction',
				'Chat request executed.',
				array(
					'assistant_id' => absint( $assistant_id ),
					'user_id'      => absint( $user_id ),
					'messages'     => self::limit_message_payload( $messages ),
					'options'      => $options,
					'response'     => $response,
				)
			);
		}

		/**
		 * Log the result of a tool execution.
		 *
		 * @param string $tool_slug Tool slug.
		 * @param array  $arguments Arguments passed to the tool.
		 * @param mixed  $result    Tool result data (or WP_Error).
		 * @param array  $context   Tool execution context.
		 */
		public static function log_tool_execution( $tool_slug, $arguments, $result, $context = array() ) {
			$context              = self::sanitize_context( $context );
			$context['tool_slug'] = sanitize_key( $tool_slug );
			$context['arguments'] = $arguments;

			if ( is_wp_error( $result ) ) {
				$context['error_code']    = $result->get_error_code();
				$context['error_message'] = $result->get_error_message();
				self::log_event( 'tool_error', 'Tool execution failed.', $context );
				return;
			}

			$context['result_preview'] = self::limit_result_payload( $result );
			self::log_event( 'tool_execution', 'Tool executed successfully.', $context );
		}

		/**
		 * Retrieve the most recent error and warning entries.
		 *
		 * @param int $limit Maximum number of entries to return.
		 * @return array
		 */
		public static function get_recent_error_messages( $limit = 20 ) {
			$limit  = max( 1, absint( $limit ) );
			$recent = get_option( self::RECENT_ERRORS_OPTION, array() );

			if ( ! is_array( $recent ) || empty( $recent ) ) {
				return array();
			}

			$recent = array_slice( array_reverse( $recent ), 0, $limit );

			return array_values( array_map( array( __CLASS__, 'prepare_recent_entry_for_output' ), $recent ) );
		}

		/**
		 * Retrieve the most recent activity entries.
		 *
		 * @param int   $limit Maximum number of entries to return.
		 * @param array $types Optional list of event types to include.
		 * @return array
		 */
		public static function get_recent_activity_entries( $limit = 20, $types = array() ) {
			$limit = max( 1, absint( $limit ) );

			$types = array_filter( array_map( 'sanitize_key', (array) $types ) );

			$recent = get_option( self::RECENT_ACTIVITY_OPTION, array() );

			if ( ! is_array( $recent ) || empty( $recent ) ) {
				return array();
			}

			$recent   = array_reverse( $recent );
			$filtered = array();

			foreach ( $recent as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$type = isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : '';

				if ( ! empty( $types ) && ( '' === $type || ! in_array( $type, $types, true ) ) ) {
					continue;
				}

				$filtered[] = self::prepare_activity_entry_for_output( $entry );

				if ( count( $filtered ) >= $limit ) {
					break;
				}
			}

			return $filtered;
		}

		/**
		 * Remove potentially sensitive information from the context payload.
		 *
		 * @param array $context Raw context data.
		 * @return array
		 */
		protected static function sanitize_context( $context ) {
			if ( ! is_array( $context ) ) {
				return array();
			}

			$context = self::deep_clone_value( $context );

			unset( $context['openai_api_key'] );
			unset( $context['gemini_api_key'] );

			if ( isset( $context['options'] ) && is_array( $context['options'] ) ) {
				$context['options'] = self::sanitize_options_context( $context['options'] );
			}

			if ( array_key_exists( 'response', $context ) ) {
				$context['response'] = self::limit_response_payload( $context['response'] );
			}

			return self::redact_sensitive_data( $context );
		}

		/**
		 * Recursively redact sensitive information from a payload.
		 *
		 * @param mixed $value Value to inspect.
		 * @return mixed
		 */
		protected static function redact_sensitive_data( $value ) {
			if ( $value instanceof \Traversable ) {
				$value = iterator_to_array( $value );
			}

			if ( is_array( $value ) ) {
				$sanitized = array();

				foreach ( $value as $key => $child ) {
					if ( self::is_sensitive_context_key( $key ) ) {
						$sanitized[ $key ] = self::redact_sensitive_value( $child );
						continue;
					}

					$sanitized[ $key ] = self::redact_sensitive_data( $child );
				}

				return $sanitized;
			}

			if ( is_object( $value ) ) {
				return self::redact_sensitive_data( get_object_vars( $value ) );
			}

			return $value;
		}

		/**
		 * Determine whether a context key should be treated as sensitive.
		 *
		 * @param mixed $key Context key.
		 * @return bool
		 */
		protected static function is_sensitive_context_key( $key ) {
			if ( is_int( $key ) ) {
				return false;
			}

			if ( ! is_string( $key ) ) {
				return false;
			}

			$normalized = strtolower( $key );

			$exact_matches = array(
				'api_key',
				'apikey',
				'api-key',
				'access_token',
				'refresh_token',
				'auth_token',
				'authorization',
				'proxy-authorization',
				'proxy_authorization',
				'client_secret',
				'secret',
				'bearer_token',
				'password',
				'private_key',
			);

			if ( in_array( $normalized, $exact_matches, true ) ) {
				return true;
			}

			$suffix_matches = array(
				'_api_key',
				'_apikey',
				'_api-key',
				'_access_token',
				'_refresh_token',
				'_auth_token',
				'_bearer_token',
				'_client_secret',
				'_secret',
				'_password',
				'-api-key',
				'-access-token',
				'-refresh-token',
				'-auth-token',
				'-bearer-token',
				'-client-secret',
				'-secret',
				'-password',
			);

			foreach ( $suffix_matches as $suffix ) {
				if ( self::string_ends_with( $normalized, $suffix ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Replace sensitive payload values with a generic placeholder.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		protected static function redact_sensitive_value( $value ) {
			return '[redacted]';
		}

		/**
		 * Determine if the entry should be stored for quick access in the admin UI.
		 *
		 * @param array $entry Prepared log entry.
		 * @return bool
		 */
		protected static function should_store_recent_entry( $entry ) {
			if ( ! is_array( $entry ) ) {
				return false;
			}

			if ( empty( $entry['type'] ) || empty( $entry['message'] ) ) {
				return false;
			}

			$type = sanitize_key( $entry['type'] );

			// Store critical, error, and warning messages.
			if ( in_array( $type, array( self::LEVEL_CRITICAL, self::LEVEL_ERROR, self::LEVEL_WARNING ), true ) ) {
				return true;
			}

			// Legacy support for 'error' and 'warning' type.
			if ( 'warning' === $type || 'error' === $type ) {
				return true;
			}

			return false !== strpos( $type, 'error' );
		}

		/**
		 * Determine if the entry should be stored as a recent activity item.
		 *
		 * @param array $entry Prepared log entry.
		 * @return bool
		 */
		protected static function should_store_recent_activity_entry( $entry ) {
			if ( ! is_array( $entry ) ) {
				return false;
			}

			if ( empty( $entry['type'] ) || empty( $entry['message'] ) ) {
				return false;
			}

			$type = sanitize_key( $entry['type'] );

			$allowed_types = apply_filters(
				'wp_mcp_ai_recent_activity_types',
				array(
					'tool_execution',
					'tool_error',
					'chat_interaction',
					'openai_request',
					'openai_response',
					'anthropic_request',
					'anthropic_response',
					'gemini_request',
					'gemini_response',
					'gemini_image_request',
					'gemini_image_response',
					'gemini_list_models_response',
					'gemini_count_tokens',
					'gemini_count_tokens_response',
					'gemini_create_embedding',
					'gemini_embedding_response',
					'gemini_stream_request',
					'gemini_stream_response',
					'lm_studio_request',
					'lm_studio_response',
					'lm_studio_completion_request',
					'lm_studio_completion_response',
					'openai_external_action_request',
					'openai_external_action_response',
				)
			);

			if ( ! is_array( $allowed_types ) || empty( $allowed_types ) ) {
				return false;
			}

			$allowed_types = array_map( 'sanitize_key', $allowed_types );

			return in_array( $type, $allowed_types, true );
		}

		/**
		 * Persist a recent entry while keeping the buffer trimmed.
		 *
		 * @param array $entry Prepared log entry.
		 */
		protected static function store_recent_entry( $entry ) {
			$recent = get_option( self::RECENT_ERRORS_OPTION, array() );

			if ( ! is_array( $recent ) ) {
				$recent = array();
			}

			$stored_entry = array(
				'timestamp' => isset( $entry['timestamp'] ) ? (string) $entry['timestamp'] : '',
				'type'      => sanitize_key( $entry['type'] ),
				'message'   => (string) $entry['message'],
			);

			if ( ! empty( $entry['context'] ) ) {
				$stored_entry['context'] = $entry['context'];
			}

			$recent[] = $stored_entry;

			$recent = array_slice( $recent, -50 );

			update_option( self::RECENT_ERRORS_OPTION, $recent, false );
		}

		/**
		 * Persist a recent activity entry while keeping the buffer trimmed.
		 *
		 * @param array $entry Prepared log entry.
		 */
		protected static function store_recent_activity_entry( $entry ) {
			$recent = get_option( self::RECENT_ACTIVITY_OPTION, array() );

			if ( ! is_array( $recent ) ) {
				$recent = array();
			}

			$stored_entry = array(
				'timestamp' => isset( $entry['timestamp'] ) ? (string) $entry['timestamp'] : '',
				'type'      => isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : '',
				'message'   => isset( $entry['message'] ) ? (string) $entry['message'] : '',
			);

			if ( ! empty( $entry['context'] ) ) {
				$stored_entry['context'] = $entry['context'];
			}

			$recent[] = $stored_entry;

			$recent = array_slice( $recent, -100 );

			update_option( self::RECENT_ACTIVITY_OPTION, $recent, false );
		}

		/**
		 * Prepare a stored entry for safe output.
		 *
		 * @param array $entry Stored entry.
		 * @return array
		 */
		protected static function prepare_recent_entry_for_output( $entry ) {
			if ( ! is_array( $entry ) ) {
				return array();
			}

			$prepared = array(
				'timestamp' => isset( $entry['timestamp'] ) ? (string) $entry['timestamp'] : '',
				'type'      => isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : '',
				'message'   => isset( $entry['message'] ) ? (string) $entry['message'] : '',
			);

			if ( isset( $entry['context'] ) ) {
				$prepared['context'] = $entry['context'];
			}

			return $prepared;
		}

		/**
		 * Prepare a stored activity entry for safe output.
		 *
		 * @param array $entry Stored entry.
		 * @return array
		 */
		protected static function prepare_activity_entry_for_output( $entry ) {
			if ( ! is_array( $entry ) ) {
				return array();
			}

			$prepared = array(
				'timestamp' => isset( $entry['timestamp'] ) ? (string) $entry['timestamp'] : '',
				'type'      => isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : '',
				'message'   => isset( $entry['message'] ) ? (string) $entry['message'] : '',
			);

			if ( isset( $entry['context'] ) ) {
				$prepared['context'] = $entry['context'];
			}

			return $prepared;
		}

		/**
		 * Deep clone arbitrary context values so we never mutate the caller's data.
		 *
		 * @param mixed $value Raw value.
		 * @return mixed
		 */
		protected static function deep_clone_value( $value ) {
			if ( is_array( $value ) ) {
				$clone = array();

				foreach ( $value as $key => $child ) {
					$clone[ $key ] = self::deep_clone_value( $child );
				}

				return $clone;
			}

			return $value;
		}

		/**
		 * Sanitize the options payload before it is logged.
		 *
		 * @param array $options Raw options array.
		 * @return array
		 */
		protected static function sanitize_options_context( $options ) {
			$options = self::deep_clone_value( $options );

			if ( isset( $options['attachments'] ) && is_array( $options['attachments'] ) ) {
				$options['attachments'] = self::sanitize_attachments( $options['attachments'] );
			}

			if ( isset( $options['memory_documents'] ) && is_array( $options['memory_documents'] ) ) {
				$options['memory_documents'] = self::sanitize_memory_documents( $options['memory_documents'] );
			}

			return $options;
		}

		/**
		 * Sanitize attachment metadata by removing large binary blobs.
		 *
		 * @param array $attachments Attachment entries.
		 * @return array
		 */
		protected static function sanitize_attachments( $attachments ) {
			$sanitized = array();

			foreach ( $attachments as $index => $attachment ) {
				if ( ! is_array( $attachment ) ) {
					$sanitized[ $index ] = $attachment;
					continue;
				}

				$copy = self::deep_clone_value( $attachment );

				if ( isset( $copy['data'] ) ) {
					$copy['data'] = '[redacted]';
				}

				$sanitized[ $index ] = $copy;
			}

			return $sanitized;
		}

		/**
		 * Limit the amount of memory document data that we persist to the logs.
		 *
		 * @param array $documents Memory document entries.
		 * @return array
		 */
		protected static function sanitize_memory_documents( $documents ) {
			$total   = is_array( $documents ) ? count( $documents ) : 0;
			$preview = array();

			if ( is_array( $documents ) ) {
				$max_preview = 3;
				$index       = 0;

				foreach ( $documents as $document ) {
					if ( $index >= $max_preview ) {
						break;
					}

					$preview[] = self::truncate_strings_in_structure( $document, 160 );
					++$index;
				}
			}

			return array(
				'count'   => $total,
				'preview' => $preview,
			);
		}

		/**
		 * Recursively truncate string values within a structure.
		 *
		 * @param mixed $value  Value to inspect.
		 * @param int   $limit  Maximum characters for string values.
		 * @return mixed
		 */
		protected static function truncate_strings_in_structure( $value, $limit ) {
			if ( is_string( $value ) ) {
				return self::truncate_string( $value, $limit );
			}

			if ( is_array( $value ) ) {
				$truncated = array();

				foreach ( $value as $key => $child ) {
					$truncated[ $key ] = self::truncate_strings_in_structure( $child, $limit );
				}

				return $truncated;
			}

			if ( is_object( $value ) ) {
				$truncated = array();

				foreach ( get_object_vars( $value ) as $property => $child ) {
					$truncated[ $property ] = self::truncate_strings_in_structure( $child, $limit );
				}

				return $truncated;
			}

			return $value;
		}

		/**
		 * Limit the amount of response data written to the logs.
		 *
		 * @param mixed $response Response payload.
		 * @return mixed
		 */
		protected static function limit_response_payload( $response ) {
			if ( is_string( $response ) ) {
				return self::truncate_string( $response, 400 );
			}

			if ( is_array( $response ) || is_object( $response ) ) {
				$encoded = wp_json_encode( $response );

				if ( false === $encoded ) {
					return '[unserializable response]';
				}

				$preview   = self::truncate_string( $encoded, 400 );
				$truncated = $preview !== $encoded;
				$payload   = array(
					'preview'   => $preview,
					'truncated' => $truncated,
				);

				return $payload;
			}

			return $response;
		}

		/**
		 * Truncate large message bodies before logging.
		 *
		 * @param array $messages Chat messages.
		 * @return array
		 */
		protected static function limit_message_payload( $messages ) {
			if ( ! is_array( $messages ) ) {
				return array();
			}

			$limited = array();
			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$limited[] = self::limit_single_message_payload( $message );
			}

			return $limited;
		}

		/**
		 * Limit the payload of an individual message.
		 *
		 * @param array $message Raw message array.
		 * @return array
		 */
		protected static function limit_single_message_payload( array $message ) {
			$limited = self::deep_clone_value( $message );

			if ( isset( $limited['content'] ) ) {
				$limited['content'] = self::limit_message_content( $limited['content'] );
			}

			return $limited;
		}

		/**
		 * Limit structured message content so it can be safely logged.
		 *
		 * @param mixed $content Structured message content.
		 * @return mixed
		 */
		protected static function limit_message_content( $content ) {
			if ( is_string( $content ) ) {
				return self::truncate_string( $content, 160 );
			}

			if ( is_array( $content ) ) {
				$limited = array();

				foreach ( $content as $segment ) {
					if ( is_string( $segment ) ) {
						$limited[] = self::truncate_string( $segment, 160 );
						continue;
					}

					if ( ! is_array( $segment ) ) {
						$limited[] = $segment;
						continue;
					}

					$limited[] = self::limit_message_segment( $segment );
				}

				return $limited;
			}

			if ( is_object( $content ) ) {
				return self::truncate_strings_in_structure( $content, 160 );
			}

			return $content;
		}

		/**
		 * Limit individual structured message segments prior to logging.
		 *
		 * @param array $segment Message segment array.
		 * @return array
		 */
		protected static function limit_message_segment( array $segment ) {
			$limited = self::truncate_strings_in_structure( $segment, 160 );

			if ( isset( $limited['text'] ) ) {
				$limited['text'] = self::limit_segment_text_field( $limited['text'] );
			}

			if ( isset( $limited['content'] ) ) {
				$limited['content'] = self::limit_message_content( $limited['content'] );
			}

			return $limited;
		}

		/**
		 * Normalise different "text" representations within a message segment.
		 *
		 * @param mixed $value Raw text field value.
		 * @return mixed
		 */
		protected static function limit_segment_text_field( $value ) {
			if ( is_string( $value ) ) {
				return self::truncate_string( $value, 160 );
			}

			if ( is_array( $value ) ) {
				$limited = self::truncate_strings_in_structure( $value, 160 );

				if ( isset( $limited['annotations'] ) && is_array( $limited['annotations'] ) ) {
					$limited['annotations'] = array(
						'count' => count( $limited['annotations'] ),
					);
				}

				return $limited;
			}

			if ( is_object( $value ) ) {
				$limited = self::truncate_strings_in_structure( $value, 160 );

				if ( isset( $limited['annotations'] ) && is_array( $limited['annotations'] ) ) {
					$limited['annotations'] = array(
						'count' => count( $limited['annotations'] ),
					);
				}

				return $limited;
			}

			return $value;
		}

		/**
		 * Reduce result payload size prior to logging.
		 *
		 * @param mixed $result Raw tool result.
		 * @return mixed
		 */
		protected static function limit_result_payload( $result ) {
			if ( is_array( $result ) || is_object( $result ) ) {
				$encoded = wp_json_encode( $result );
				if ( false !== $encoded && strlen( $encoded ) > 400 ) {
					return substr( $encoded, 0, 400 ) . '…';
				}
			}

			return $result;
		}

		/**
		 * Generate a user-friendly error message with recovery suggestions.
		 *
		 * This method translates technical error codes into actionable messages
		 * for end users and administrators.
		 *
		 * @since 1.0.0
		 *
		 * @param string $error_code    Machine-readable error code.
		 * @param string $error_message Technical error message.
		 * @param array  $context       Additional context for error message generation.
		 * @return array Array with 'message' and 'suggestions' keys.
		 */
		public static function get_user_friendly_error( $error_code, $error_message, $context = array() ) {
			$friendly_message = '';
			$suggestions      = array();

			// Map common error scenarios to user-friendly messages.
			switch ( $error_code ) {
				case 'openai_api_error':
				case 'gemini_api_error':
				case 'anthropic_api_error':
					$friendly_message = __( 'Unable to connect to the AI service. Please check your API credentials and try again.', 'wp-mcp-ai' );
					$suggestions      = array(
						__( 'Verify your API key is correctly entered in the plugin settings.', 'wp-mcp-ai' ),
						__( 'Check that your API key has not expired or been revoked.', 'wp-mcp-ai' ),
						__( 'Ensure your account has sufficient credits or quota remaining.', 'wp-mcp-ai' ),
						__( 'Verify your server can make outbound HTTPS connections.', 'wp-mcp-ai' ),
					);
					break;

				case 'rate_limit_exceeded':
					$friendly_message = __( 'You have exceeded the rate limit for this service. Please wait before trying again.', 'wp-mcp-ai' );
					$suggestions      = array(
						__( 'Wait a few minutes before making another request.', 'wp-mcp-ai' ),
						__( 'Consider upgrading your API plan for higher rate limits.', 'wp-mcp-ai' ),
						__( 'Review the rate limit settings in the plugin configuration.', 'wp-mcp-ai' ),
					);
					break;

				case 'network_error':
				case 'connection_timeout':
					$friendly_message = __( 'Network connection failed. Please check your internet connection and try again.', 'wp-mcp-ai' );
					$suggestions      = array(
						__( 'Verify your server has an active internet connection.', 'wp-mcp-ai' ),
						__( 'Check if your firewall is blocking outbound connections.', 'wp-mcp-ai' ),
						__( 'Contact your hosting provider if the issue persists.', 'wp-mcp-ai' ),
					);
					break;

				case 'invalid_api_key':
				case 'authentication_failed':
					$friendly_message = __( 'API authentication failed. Your API key appears to be invalid.', 'wp-mcp-ai' );
					$suggestions      = array(
						__( 'Double-check the API key in your plugin settings.', 'wp-mcp-ai' ),
						__( 'Ensure there are no extra spaces before or after the API key.', 'wp-mcp-ai' ),
						__( 'Generate a new API key from your provider\'s dashboard.', 'wp-mcp-ai' ),
					);
					break;

				case 'insufficient_quota':
					$friendly_message = __( 'Your API quota has been exhausted. Please upgrade your plan or wait for the quota to reset.', 'wp-mcp-ai' );
					$suggestions      = array(
						__( 'Check your current usage in the AI provider\'s dashboard.', 'wp-mcp-ai' ),
						__( 'Upgrade to a higher-tier plan if needed.', 'wp-mcp-ai' ),
						__( 'Wait until the next billing cycle for quota to reset.', 'wp-mcp-ai' ),
					);
					break;

				case 'invalid_model':
					$friendly_message = __( 'The selected AI model is not available or invalid.', 'wp-mcp-ai' );
					$suggestions      = array(
						__( 'Verify the model name in your assistant settings.', 'wp-mcp-ai' ),
						__( 'Check if the model is available for your API plan.', 'wp-mcp-ai' ),
						__( 'Try selecting a different model from the available options.', 'wp-mcp-ai' ),
					);
					break;

				case 'tool_execution_failed':
					$tool_name        = isset( $context['tool_slug'] ) ? sanitize_text_field( $context['tool_slug'] ) : 'unknown';
					$friendly_message = sprintf(
						/* translators: %s: Tool name */
						__( 'The tool "%s" failed to execute properly.', 'wp-mcp-ai' ),
						$tool_name
					);
					$suggestions = array(
						__( 'Check the tool configuration and try again.', 'wp-mcp-ai' ),
						__( 'Verify required permissions for the tool are granted.', 'wp-mcp-ai' ),
						__( 'Review the error log for more details.', 'wp-mcp-ai' ),
					);
					break;

				case 'file_upload_error':
					$friendly_message = __( 'File upload failed. Please check the file and try again.', 'wp-mcp-ai' );
					$suggestions      = array(
						__( 'Ensure the file size is within the allowed limit.', 'wp-mcp-ai' ),
						__( 'Verify the file type is supported.', 'wp-mcp-ai' ),
						__( 'Check your server\'s upload_max_filesize setting.', 'wp-mcp-ai' ),
					);
					break;

				default:
					// Fallback for unknown errors.
					$friendly_message = ! empty( $error_message ) ?
						$error_message :
						__( 'An unexpected error occurred. Please try again.', 'wp-mcp-ai' );
					$suggestions      = array(
						__( 'Check the error log for more details.', 'wp-mcp-ai' ),
						__( 'Contact support if the problem persists.', 'wp-mcp-ai' ),
					);
					break;
			}

			/**
			 * Filter the user-friendly error message and suggestions.
			 *
			 * Allows third parties to customize error messages for specific scenarios.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $result        Array with 'message' and 'suggestions'.
			 * @param string $error_code    Original error code.
			 * @param string $error_message Original error message.
			 * @param array  $context       Additional context.
			 */
			return apply_filters(
				'wp_mcp_ai_user_friendly_error',
				array(
					'message'     => $friendly_message,
					'suggestions' => $suggestions,
				),
				$error_code,
				$error_message,
				$context
			);
		}

		/**
		 * Helper for truncating strings while supporting multibyte strings when available.
		 *
		 * @param string $value Raw string.
		 * @param int    $limit Maximum length.
		 * @return string
		 */
		protected static function truncate_string( $value, $limit ) {
			$value  = (string) $value;
			$length = self::string_length( $value );

			if ( $length <= $limit ) {
				return $value;
			}

			return self::string_substr( $value, 0, $limit ) . '…';
		}

		/**
		 * Safe string length helper with multibyte awareness.
		 *
		 * @param string $value String to measure.
		 * @return int
		 */
		protected static function string_length( $value ) {
			if ( function_exists( 'mb_strlen' ) ) {
				return mb_strlen( $value, 'UTF-8' );
			}

			return strlen( $value );
		}

		/**
		 * Determine whether the given string ends with the provided suffix.
		 *
		 * @param string $value  Full string.
		 * @param string $suffix Suffix to compare.
		 * @return bool
		 */
		protected static function string_ends_with( $value, $suffix ) {
			$value  = (string) $value;
			$suffix = (string) $suffix;

			if ( '' === $suffix ) {
				return true;
			}

			$suffix_length = strlen( $suffix );

			if ( $suffix_length > strlen( $value ) ) {
				return false;
			}

			return substr( $value, -$suffix_length ) === $suffix;
		}

		/**
		 * Safe substring helper with multibyte awareness.
		 *
		 * @param string $value  Source string.
		 * @param int    $start  Starting offset.
		 * @param int    $length Length.
		 * @return string
		 */
		protected static function string_substr( $value, $start, $length ) {
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $value, $start, $length, 'UTF-8' );
			}

			return substr( $value, $start, $length );
		}
	}
}
