<?php
/**
 * Simple logging utility for NV oOS.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		const PREFIX = '[NV oOS]';

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
		 * Maximum number of JSON-encoded bytes of context that may be persisted
		 * alongside a single entry in the rolling recent-errors / recent-activity
		 * buffers.
		 *
		 * Those buffers live in `wp_options`, are read and rewritten on every log
		 * write, and are polled by admin dashboards. Raw contexts routinely carry an
		 * entire assistant `system_prompt` plus unbounded tool arguments, which grows
		 * the option row into the megabytes and makes every subsequent write more
		 * expensive. Contexts above this budget are reduced by
		 * {@see self::slim_context_for_storage()}.
		 *
		 * This cap applies only to the persisted buffers. The `wp_mcp_ai_log_entry`
		 * filter and the PHP error-log line still receive the full sanitized context.
		 *
		 * @since 1.8.0
		 */
		const MAX_STORED_CONTEXT_BYTES = 2048;

		/**
		 * Per-entry context budget when Extended Logging is enabled.
		 *
		 * Kept modest deliberately: the recent-activity buffer retains 100 entries,
		 * so doubling this constant doubles the worst-case size of that option row.
		 *
		 * @since 1.8.0
		 */
		const MAX_STORED_CONTEXT_BYTES_EXTENDED = 8192;

		/**
		 * Maximum length of an individual string value inside a persisted context.
		 *
		 * @since 1.8.0
		 */
		const MAX_STORED_CONTEXT_STRING_LENGTH = 512;

		/**
		 * Maximum nesting depth retained in a persisted context.
		 *
		 * @since 1.8.0
		 */
		const MAX_STORED_CONTEXT_DEPTH = 6;

		/**
		 * Number of entries retained in the recent-errors buffer.
		 *
		 * @since 1.8.0
		 */
		const MAX_RECENT_ERRORS = 50;

		/**
		 * Number of entries retained in the recent-activity buffer.
		 *
		 * @since 1.8.0
		 */
		const MAX_RECENT_ACTIVITY = 100;

		/**
		 * Marker prefix used when a value is replaced by a size descriptor.
		 *
		 * Recognising the marker keeps slimming idempotent, so repeated passes (for
		 * example from {@see self::compact_recent_buffers()}) do not describe an
		 * existing descriptor.
		 *
		 * @since 1.8.0
		 */
		const OMITTED_VALUE_PREFIX = '[omitted:';

		/**
		 * Marker prefix used when a prompt is replaced by its fingerprint.
		 *
		 * @since 1.8.0
		 */
		const OMITTED_PROMPT_PREFIX = '[prompt omitted:';

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
		 * Compiled query-parameter redaction pattern.
		 *
		 * Built once per request because `redact_sensitive_string_patterns()` runs
		 * on every string leaf of every log context.
		 *
		 * @since 1.1.64
		 *
		 * @var string|null
		 */
		protected static $sensitive_query_pattern = null;

		/**
		 * Reset the cached log file path. Primarily used in automated tests.
		 */
		public static function reset_log_file_cache() {
			self::$log_file_path = null;
		}

		/**
		 * Reset the cached query-parameter redaction pattern.
		 *
		 * Required after `wp_mcp_ai_sensitive_query_parameters` callbacks are added
		 * or removed at runtime. Primarily used in automated tests.
		 *
		 * @since 1.1.64
		 */
		public static function reset_sensitive_query_pattern_cache() {
			self::$sensitive_query_pattern = null;
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

			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- error_log used as a diagnostic fallback logger; active only when WP_DEBUG is enabled or as last-resort error capture in catch blocks.
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
			if ( 'chat_interaction' === $type ) {
				return WP_MCP_AI_Admin_Settings::is_chat_interaction_logging_enabled();
			}

			// Schedule run events (start, completion, failure).
			if ( 'schedule_run' === $type ) {
				return true;
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

			return is_writable( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
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
					__( 'The PHP error log path could not be determined.', 'mcp-ai-wpoos' )
				);
			}

			if ( ! file_exists( $path ) || ! is_file( $path ) ) {
				return new WP_Error(
					'wp_mcp_ai_log_unavailable',
					__( 'The PHP error log has not been created yet.', 'mcp-ai-wpoos' )
				);
			}

			// Path bounding: verify the log file resides in a standard location.
			$bounded = self::is_path_bounded( $path );
			if ( ! $bounded ) {
				return new WP_Error(
					'wp_mcp_ai_log_unbounded',
					__( 'The PHP error log path is outside allowed directories.', 'mcp-ai-wpoos' )
				);
			}

			if ( ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
				return new WP_Error(
					'wp_mcp_ai_log_unwritable',
					__( 'The PHP error log is not writable. Update the file permissions and try again.', 'mcp-ai-wpoos' )
				);
			}

			$handle = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct filesystem operation required; WP_Filesystem not available in this execution context.

			if ( false === $handle ) {
				return new WP_Error(
					'wp_mcp_ai_log_failed',
					__( 'The PHP error log could not be truncated.', 'mcp-ai-wpoos' )
				);
			}

			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct filesystem operation required; WP_Filesystem not available in this execution context.

			delete_option( self::RECENT_ERRORS_OPTION );
			delete_option( self::RECENT_ACTIVITY_OPTION );

			return true;
		}

		/**
		 * Verify that a log file path falls within allowed directories.
		 *
		 * Prevents truncation of files outside standard WordPress and system
		 * log locations.
		 *
		 * @since 1.1.20
		 *
		 * @param string $path Absolute path to validate.
		 * @return bool True when the path is within an allowed directory.
		 */
		private static function is_path_bounded( $path ) {
			if ( '' === $path ) {
				return false;
			}

			if ( ! function_exists( 'wp_normalize_path' ) ) {
				return true; // Defensive: cannot validate without WP; allow.
			}

			$normalized = wp_normalize_path( $path );

			// Build the allowed-directory list.
			$allowed = array();

			// WordPress content directory.
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$allowed[] = wp_normalize_path( WP_CONTENT_DIR );
			}

			// System temp directory.
			$sys_temp = sys_get_temp_dir();
			if ( is_string( $sys_temp ) && '' !== $sys_temp ) {
				$allowed[] = wp_normalize_path( $sys_temp );
			}

			// WordPress root.
			if ( defined( 'ABSPATH' ) ) {
				$allowed[] = wp_normalize_path( ABSPATH );
			}

			// Standard Linux/Unix log directories (common PHP error log locations).
			$allowed[] = '/var/log';
			$allowed[] = '/var/log/php';

			foreach ( $allowed as $dir ) {
				if ( '' === $dir ) {
					continue;
				}
				if ( 0 === strpos( $normalized, trailingslashit( $dir ) ) || $normalized === $dir ) {
					return true;
				}
			}

			return false;
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
		 * Log a Pro Schedule Manager run event.
		 *
		 * Centralises schedule run telemetry into a single structured entry so
		 * the activity feed and log file both capture meaningful schedule context.
		 * Recognised events:
		 * - schedule_run_start    : dispatch() was entered for this schedule.
		 * - schedule_run_complete : the schedule ran to completion (success).
		 * - schedule_run_failed   : the schedule reported a failure.
		 *
		 * @param string $event       Event key: schedule_run_start, schedule_run_complete, or schedule_run_failed.
		 * @param string $schedule_id Unique schedule identifier.
		 * @param string $name        Human-readable schedule name.
		 * @param string $type        Schedule type (task, workflow, assistant_run, channel_broadcast, workflow_builder).
		 * @param array  $extra       Optional extra context (duration, error, action_log, etc.).
		 */
		public static function log_schedule_run( $event, $schedule_id, $name, $type, array $extra = array() ) {
			$label   = ucwords( str_replace( '_', ' ', (string) $event ) );
			$message = sprintf(
				/* translators: 1: human-readable event label, 2: schedule type, 3: schedule name, 4: schedule ID */
				'%1$s [%2$s]: "%3$s" (ID: %4$s)',
				$label,
				$type,
				$name,
				$schedule_id
			);

			self::log_event(
				'schedule_run',
				$message,
				array_merge(
					$extra,
					array(
						'event'       => sanitize_key( $event ),
						'schedule_id' => sanitize_text_field( $schedule_id ),
						'name'        => sanitize_text_field( $name ),
						'type'        => sanitize_key( $type ),
					)
				)
			);
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
		 * Describe the rolling buffers and their retention limits.
		 *
		 * @since 1.8.0
		 *
		 * @return array Keyed by short buffer name.
		 */
		protected static function get_recent_buffer_map() {
			return array(
				'errors'   => array(
					'option' => self::RECENT_ERRORS_OPTION,
					'label'  => __( 'Recent errors', 'mcp-ai-wpoos' ),
					'limit'  => self::MAX_RECENT_ERRORS,
				),
				'activity' => array(
					'option' => self::RECENT_ACTIVITY_OPTION,
					'label'  => __( 'Recent activity', 'mcp-ai-wpoos' ),
					'limit'  => self::MAX_RECENT_ACTIVITY,
				),
			);
		}

		/**
		 * Measure the stored size of a value as WordPress would persist it.
		 *
		 * @since 1.8.0
		 *
		 * @param mixed $value Option value.
		 * @return int Byte length of the serialized value.
		 */
		protected static function measure_option_bytes( $value ) {
			if ( empty( $value ) ) {
				return 0;
			}

			$serialized = maybe_serialize( $value );

			return is_string( $serialized ) ? strlen( $serialized ) : 0;
		}

		/**
		 * Report the stored size of the rolling log buffers.
		 *
		 * Used by the Data Management screen to surface how much space the
		 * `wp_mcp_ai_recent_errors` and `wp_mcp_ai_recent_activity` option rows are
		 * consuming.
		 *
		 * @since 1.8.0
		 *
		 * @return array {
		 *     @type array $buffers       Per-buffer option name, label, limit, entries, bytes.
		 *     @type int   $total_bytes   Combined serialized byte length.
		 *     @type int   $total_entries Combined entry count.
		 * }
		 */
		public static function get_recent_buffer_stats() {
			$stats = array(
				'buffers'       => array(),
				'total_bytes'   => 0,
				'total_entries' => 0,
			);

			foreach ( self::get_recent_buffer_map() as $key => $buffer ) {
				$entries = get_option( $buffer['option'], array() );
				$entries = is_array( $entries ) ? $entries : array();
				$bytes   = self::measure_option_bytes( $entries );

				$stats['buffers'][ $key ] = array(
					'option'  => $buffer['option'],
					'label'   => $buffer['label'],
					'limit'   => $buffer['limit'],
					'entries' => count( $entries ),
					'bytes'   => $bytes,
				);

				$stats['total_bytes']   += $bytes;
				$stats['total_entries'] += count( $entries );
			}

			return $stats;
		}

		/**
		 * Re-slim every entry already stored in the rolling buffers.
		 *
		 * The per-entry budget is applied at write time, so rows written before it
		 * existed still carry their full context — including complete assistant
		 * system prompts. This rewrites those rows through
		 * {@see self::slim_context_for_storage()} so the space is reclaimed
		 * immediately, without discarding the entries themselves.
		 *
		 * Safe to run repeatedly; it is a no-op once every entry already fits.
		 *
		 * @since 1.8.0
		 *
		 * @return array {
		 *     @type array $buffers           Per-buffer before/after byte counts.
		 *     @type int   $bytes_before      Combined size before compaction.
		 *     @type int   $bytes_after       Combined size after compaction.
		 *     @type int   $bytes_saved       Bytes reclaimed.
		 *     @type int   $entries_rewritten Number of entries whose context changed.
		 * }
		 */
		public static function compact_recent_buffers() {
			$result = array(
				'buffers'           => array(),
				'bytes_before'      => 0,
				'bytes_after'       => 0,
				'bytes_saved'       => 0,
				'entries_rewritten' => 0,
			);

			foreach ( self::get_recent_buffer_map() as $key => $buffer ) {
				$entries = get_option( $buffer['option'], array() );
				$entries = is_array( $entries ) ? $entries : array();

				$before    = self::measure_option_bytes( $entries );
				$compacted = array();
				$rewritten = 0;

				foreach ( $entries as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}

					if ( ! empty( $entry['context'] ) ) {
						$context = self::slim_context_for_storage( $entry['context'] );

						if ( $context !== $entry['context'] ) {
							++$rewritten;
						}

						if ( empty( $context ) ) {
							unset( $entry['context'] );
						} else {
							$entry['context'] = $context;
						}
					}

					$compacted[] = $entry;
				}

				// Apply the current retention limit in case it was lowered.
				$compacted = array_slice( $compacted, - $buffer['limit'] );
				$after     = self::measure_option_bytes( $compacted );

				if ( $after !== $before || count( $compacted ) !== count( $entries ) ) {
					update_option( $buffer['option'], $compacted, false );
				}

				$result['buffers'][ $key ] = array(
					'option'       => $buffer['option'],
					'label'        => $buffer['label'],
					'entries'      => count( $compacted ),
					'bytes_before' => $before,
					'bytes_after'  => $after,
					'rewritten'    => $rewritten,
				);

				$result['bytes_before']      += $before;
				$result['bytes_after']       += $after;
				$result['entries_rewritten'] += $rewritten;
			}

			$result['bytes_saved'] = max( 0, $result['bytes_before'] - $result['bytes_after'] );

			return $result;
		}

		/**
		 * Delete both rolling log buffers outright.
		 *
		 * Prefer {@see self::compact_recent_buffers()} when the recent history is
		 * still wanted; this discards it.
		 *
		 * @since 1.8.0
		 *
		 * @return array {
		 *     @type array $buffers         Per-buffer entry counts and byte sizes removed.
		 *     @type int   $bytes_freed     Combined bytes removed.
		 *     @type int   $entries_removed Combined entries removed.
		 * }
		 */
		public static function clear_recent_buffers() {
			$result = array(
				'buffers'         => array(),
				'bytes_freed'     => 0,
				'entries_removed' => 0,
			);

			foreach ( self::get_recent_buffer_map() as $key => $buffer ) {
				$entries = get_option( $buffer['option'], array() );
				$entries = is_array( $entries ) ? $entries : array();

				$bytes = self::measure_option_bytes( $entries );
				$count = count( $entries );

				delete_option( $buffer['option'] );

				$result['buffers'][ $key ] = array(
					'option'  => $buffer['option'],
					'label'   => $buffer['label'],
					'entries' => $count,
					'bytes'   => $bytes,
				);

				$result['bytes_freed']     += $bytes;
				$result['entries_removed'] += $count;
			}

			return $result;
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
		 * Two layers of protection:
		 * 1. Key-based: any value whose key is recognised as sensitive is replaced
		 *    wholesale with the `[redacted]` placeholder.
		 * 2. Value-based: any plain string value (regardless of key name) is
		 *    scanned for well-known secret patterns (Bearer tokens, OpenAI / Google
		 *    API keys) and those sub-strings are masked in-place.
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

			// Layer 2: scan plain string leaves for embedded secret patterns.
			if ( is_string( $value ) ) {
				return self::redact_sensitive_string_patterns( $value );
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
				// Additions: generic token key, JWT variants, OpenAI-style keys.
				'token',
				'jwt',
				'id_token',
				'openai_token',
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
				// Catches any *_token key (id_token, openai_token, service_token, etc.).
				'_token',
				'-api-key',
				'-access-token',
				'-refresh-token',
				'-auth-token',
				'-bearer-token',
				'-client-secret',
				'-secret',
				'-password',
				'-token',
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
		protected static function redact_sensitive_value( $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for context-aware redaction.
			return '[redacted]';
		}

		/**
		 * Redact well-known secret patterns embedded in a plain string value.
		 *
		 * This provides a second layer of defence for secrets that appear in
		 * log-message strings or response-body fields where key-based redaction
		 * would not trigger (e.g. a raw HTTP response body that contains an
		 * Authorization header, or an error message that echoes back an API key).
		 *
		 * Patterns:
		 *   - Bearer <token>   — OAuth 2.0 / Auth0 JWTs / plugin credentials.
		 *   - sk-<...>         — OpenAI API keys (classic, project, service-account).
		 *   - AIza<...>        — Google / Gemini API keys.
		 *   - ?<param>=<...>   — credential-bearing URL query parameters.
		 *
		 * @since 1.8.0
		 * @since 1.1.64 Added URL query-parameter redaction.
		 *
		 * @param string $value Raw string value from a log context.
		 * @return string String with matching secret patterns replaced.
		 */
		protected static function redact_sensitive_string_patterns( $value ) {
			// Bearer tokens (OAuth, Auth0 JWTs, plugin credentials, etc.).
			// Matches "Bearer " followed by at least 10 URL-safe or base64 chars.
			$value = preg_replace(
				'/\bBearer\s+[A-Za-z0-9\-._~+\/]{10,}(?:=[*]{0,2})?/i',
				'Bearer [redacted]',
				$value
			);

			// OpenAI API keys: sk-<anything> and sk-proj-<anything>, sk-svcacct-<anything>.
			$value = preg_replace(
				'/\bsk-[A-Za-z0-9\-_]{20,}/',
				'sk-[redacted]',
				$value
			);

			// Google / Gemini API keys begin with AIza.
			$value = preg_replace(
				'/\bAIza[0-9A-Za-z_\-]{30,}/',
				'AIza[redacted]',
				$value
			);

			// Credential-bearing URL query parameters (?state=, &code=, #access_token=).
			$value = self::redact_sensitive_query_parameters( (string) $value );

			return (string) $value;
		}

		/**
		 * Redact credential-bearing query parameters inside a string value.
		 *
		 * Key-based redaction cannot help here: the credential is not the value of
		 * a sensitive *key*, it is embedded in the query string of an otherwise
		 * diagnostic value such as `url`, `redirect_url`, or `callback_url`. Those
		 * key names are far too useful to add to the deny-list — they appear across
		 * crawler results, media items, permalinks, and remote-site payloads — so
		 * instead only the secret-bearing parameters are masked and the scheme,
		 * host, and path are preserved:
		 *
		 *     https://example.test/link/lk_abc123?state=SECRET
		 *  →  https://example.test/link/lk_abc123?state=[redacted]
		 *
		 * A parameter is only matched when preceded by `?`, `&`, or `#` (so
		 * `redirect_state=` and `error_code=` are left alone) and followed
		 * immediately by `=` (so `token_secret=` does not match `token`). Values
		 * terminate on the query/fragment separators plus quote, backslash, and
		 * angle-bracket characters, so URLs embedded in JSON or HTML are masked
		 * without consuming the surrounding delimiters.
		 *
		 * No URL scheme is required, so relative URLs and bare query fragments are
		 * covered too.
		 *
		 * @since 1.1.64
		 *
		 * @param string $value Raw string value from a log context.
		 * @return string String with credential-bearing parameters masked.
		 */
		protected static function redact_sensitive_query_parameters( $value ) {
			if ( '' === $value || false === strpos( $value, '=' ) ) {
				return (string) $value;
			}

			$pattern = self::get_sensitive_query_pattern();

			if ( '' === $pattern ) {
				return (string) $value;
			}

			$redacted = preg_replace( $pattern, '${1}${2}=[redacted]', $value );

			return null === $redacted ? (string) $value : (string) $redacted;
		}

		/**
		 * Build (and memoise) the query-parameter redaction pattern.
		 *
		 * @since 1.1.64
		 *
		 * @return string Compiled pattern, or an empty string when there is nothing to match.
		 */
		protected static function get_sensitive_query_pattern() {
			if ( null !== self::$sensitive_query_pattern ) {
				return self::$sensitive_query_pattern;
			}

			$parameters = self::get_sensitive_query_parameters();

			// Parameter names are validated to /^[a-z0-9_-]+$/ so they are safe to
			// interpolate directly into the alternation group.
			self::$sensitive_query_pattern = empty( $parameters )
				? ''
				: '/([?&#])(' . implode( '|', $parameters ) . ')=[^&#\s"\'\\\\<>]+/i';

			return self::$sensitive_query_pattern;
		}

		/**
		 * Query parameter names whose values must never be persisted to a log.
		 *
		 * Scoped deliberately to the query-string context. Several of these words
		 * are too generic to deny-list as context *keys* (`state` also names a
		 * postal region, `code` also names a coupon), but as query parameters they
		 * are overwhelmingly credentials or single-use grants.
		 *
		 * `code` is included despite the false-positive cost because a leaked OAuth
		 * authorization code is directly exchangeable for a long-lived refresh
		 * token — the highest-impact leak in the list.
		 *
		 * @since 1.1.64
		 *
		 * @return string[] Lower-cased parameter names.
		 */
		protected static function get_sensitive_query_parameters() {
			$defaults = array(
				// OAuth 2.0 / OIDC grants and tokens.
				'access_token',
				'code',
				'code_challenge',
				'code_verifier',
				'id_token',
				'refresh_token',
				'state',
				'token',
				// OAuth 1.0a.
				'oauth_token',
				'oauth_verifier',
				// API keys and shared secrets.
				'api_key',
				'apikey',
				'auth',
				'authorization',
				'client_secret',
				'key',
				'secret',
				// Request signing.
				'hmac',
				'sig',
				'signature',
				// Single-use sessions and tickets.
				'session_uri',
				'ticket',
				'_wpnonce',
				// Credentials.
				'passwd',
				'password',
				'pwd',
			);

			/**
			 * Filter the query parameters masked in persisted log context.
			 *
			 * Additive only: the returned list is unioned with the built-in
			 * defaults, so this filter can widen redaction but never weaken it.
			 * Names that are not `/^[a-z0-9_-]+$/` are discarded.
			 *
			 * @since 1.1.64
			 *
			 * @param string[] $defaults Built-in parameter names.
			 */
			$filtered = apply_filters( 'wp_mcp_ai_sensitive_query_parameters', $defaults );

			if ( ! is_array( $filtered ) ) {
				$filtered = array();
			}

			$parameters = array();

			foreach ( array_merge( $defaults, $filtered ) as $parameter ) {
				if ( ! is_string( $parameter ) ) {
					continue;
				}

				$parameter = strtolower( trim( $parameter ) );

				if ( '' === $parameter || ! preg_match( '/^[a-z0-9_-]+$/', $parameter ) ) {
					continue;
				}

				$parameters[ $parameter ] = true;
			}

			$parameters = array_keys( $parameters );
			sort( $parameters );

			return $parameters;
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
					'schedule_run',
					'cloudflare_invalid_tool_call',
					'cloudflare_tool_calls_detected',
					'cloudflare_tool_calls_filtered',
					'cloudflare_tool_call_normalized',
					'cloudflare_xml_tool_calls_parsed',
					'cloudflare_json_tool_calls_parsed',
				)
			);

			if ( ! is_array( $allowed_types ) || empty( $allowed_types ) ) {
				return false;
			}

			$allowed_types = array_map( 'sanitize_key', $allowed_types );

			return in_array( $type, $allowed_types, true );
		}

		/**
		 * Reduce a sanitized log context to a size that is safe to persist in
		 * `wp_options`.
		 *
		 * Applied only on the persistence path, so the `wp_mcp_ai_log_entry` filter
		 * and the PHP error-log line continue to see the full sanitized context.
		 *
		 * Three passes:
		 * 1. Structural — drop keys that carry no diagnostic value, replace assistant
		 *    configurations and system prompts with fingerprints, truncate long
		 *    strings, and clamp nesting depth.
		 * 2. Budget — while the encoded context exceeds the budget, replace the
		 *    largest remaining top-level values with a size descriptor, biggest
		 *    first, never touching the preserved diagnostic keys.
		 * 3. Fallback — if the preserved keys alone still exceed the budget, hard
		 *    truncate every remaining string.
		 *
		 * @since 1.8.0
		 *
		 * @param mixed $context Sanitized context from {@see self::sanitize_context()}.
		 * @return array Context safe for option storage.
		 */
		protected static function slim_context_for_storage( $context ) {
			if ( ! is_array( $context ) || empty( $context ) ) {
				return array();
			}

			$budget = WP_MCP_AI_Admin_Settings::is_extended_logging_enabled()
				? self::MAX_STORED_CONTEXT_BYTES_EXTENDED
				: self::MAX_STORED_CONTEXT_BYTES;

			/**
			 * Filter the per-entry context byte budget for persisted log buffers.
			 *
			 * Raising this grows the `wp_mcp_ai_recent_errors` and
			 * `wp_mcp_ai_recent_activity` option rows, which are re-read and
			 * rewritten on every log write. Raise it deliberately.
			 *
			 * @since 1.8.0
			 *
			 * @param int   $budget  Maximum JSON-encoded bytes of context per entry.
			 * @param array $context Sanitized context prior to slimming.
			 */
			$budget = (int) apply_filters( 'wp_mcp_ai_stored_context_budget', $budget, $context );
			$budget = max( 256, $budget );

			return self::enforce_context_budget( self::slim_context_branch( $context, 0 ), $budget );
		}

		/**
		 * Recursively slim a context branch.
		 *
		 * @since 1.8.0
		 *
		 * @param mixed $value Value to slim.
		 * @param int   $depth Current nesting depth.
		 * @return mixed
		 */
		protected static function slim_context_branch( $value, $depth ) {
			if ( is_string( $value ) ) {
				return self::truncate_string( $value, self::MAX_STORED_CONTEXT_STRING_LENGTH );
			}

			if ( ! is_array( $value ) ) {
				return $value;
			}

			if ( $depth >= self::MAX_STORED_CONTEXT_DEPTH ) {
				return self::describe_omitted_value( $value );
			}

			$dropped = self::get_dropped_context_keys();
			$slim    = array();

			foreach ( $value as $key => $child ) {
				if ( is_string( $key ) ) {
					$normalized = strtolower( $key );

					if ( in_array( $normalized, $dropped, true ) ) {
						continue;
					}

					if ( 'assistant_config' === $normalized && is_array( $child ) ) {
						$slim[ $key ] = self::fingerprint_assistant_config( $child );
						continue;
					}

					if ( 'system_prompt' === $normalized && is_string( $child ) ) {
						$slim[ $key ] = self::fingerprint_prompt( $child );
						continue;
					}
				}

				$slim[ $key ] = self::slim_context_branch( $child, $depth + 1 );
			}

			return $slim;
		}

		/**
		 * Drop oversized top-level values until the context fits its budget.
		 *
		 * @since 1.8.0
		 *
		 * @param array $context Structurally slimmed context.
		 * @param int   $budget  Maximum JSON-encoded bytes.
		 * @return array
		 */
		protected static function enforce_context_budget( array $context, $budget ) {
			$encoded = wp_json_encode( $context );

			if ( false === $encoded ) {
				return array( 'context' => '[unserializable context]' );
			}

			if ( strlen( $encoded ) <= $budget ) {
				return $context;
			}

			$preserved = self::get_preserved_context_keys();
			$sizes     = array();

			foreach ( $context as $key => $child ) {
				if ( in_array( strtolower( (string) $key ), $preserved, true ) ) {
					continue;
				}

				$child_encoded = wp_json_encode( $child );
				$sizes[ $key ] = ( false === $child_encoded ) ? 0 : strlen( $child_encoded );
			}

			// Largest first, so the fewest keys are sacrificed.
			arsort( $sizes );

			foreach ( array_keys( $sizes ) as $key ) {
				$context[ $key ] = self::describe_omitted_value( $context[ $key ] );

				$encoded = wp_json_encode( $context );

				if ( false === $encoded || strlen( $encoded ) <= $budget ) {
					return $context;
				}
			}

			// Preserved keys alone exceed the budget; hard truncate what is left.
			return self::truncate_strings_in_structure( $context, 128 );
		}

		/**
		 * Replace an assistant configuration with a compact diagnostic fingerprint.
		 *
		 * The full configuration carries the resolved `system_prompt`, which includes
		 * any primary-role and Agent Skills prompt text and can run to tens of
		 * kilobytes. Keep only what is actionable when reading a log entry.
		 *
		 * @since 1.8.0
		 *
		 * @param array $config Assistant configuration.
		 * @return array Fingerprint.
		 */
		protected static function fingerprint_assistant_config( array $config ) {
			if ( isset( $config['tools'] ) && is_array( $config['tools'] ) ) {
				$tool_count = count( $config['tools'] );
			} else {
				// Carry the count forward when re-slimming an existing fingerprint.
				$tool_count = isset( $config['tool_count'] ) ? (int) $config['tool_count'] : 0;
			}

			return array(
				'provider'            => isset( $config['provider'] ) ? (string) $config['provider'] : '',
				'model'               => isset( $config['model'] ) ? (string) $config['model'] : '',
				'temperature'         => isset( $config['temperature'] ) ? $config['temperature'] : null,
				'tool_count'          => $tool_count,
				'required_capability' => isset( $config['required_capability'] ) ? (string) $config['required_capability'] : '',
				'system_prompt'       => isset( $config['system_prompt'] ) && is_string( $config['system_prompt'] )
					? self::fingerprint_prompt( $config['system_prompt'] )
					: '',
			);
		}

		/**
		 * Describe a prompt by length and hash instead of storing its text.
		 *
		 * Returns a string so that readers which cast the value stay valid.
		 *
		 * @since 1.8.0
		 *
		 * @param string $prompt Prompt text.
		 * @return string Fingerprint, or an empty string for an empty prompt.
		 */
		protected static function fingerprint_prompt( $prompt ) {
			$prompt = (string) $prompt;

			if ( '' === $prompt ) {
				return '';
			}

			// Never re-fingerprint a fingerprint: that would replace the original
			// length and hash with those of the marker itself.
			if ( 0 === strpos( $prompt, self::OMITTED_PROMPT_PREFIX ) ) {
				return $prompt;
			}

			return sprintf(
				'%1$s %2$d chars, md5:%3$s]',
				self::OMITTED_PROMPT_PREFIX,
				self::string_length( $prompt ),
				substr( md5( $prompt ), 0, 12 )
			);
		}

		/**
		 * Describe an omitted value by type and encoded size.
		 *
		 * @since 1.8.0
		 *
		 * @param mixed $value Omitted value.
		 * @return string
		 */
		protected static function describe_omitted_value( $value ) {
			// An existing descriptor is already minimal; describing it again would
			// discard the size it reports.
			if ( is_string( $value ) && 0 === strpos( $value, self::OMITTED_VALUE_PREFIX ) ) {
				return $value;
			}

			$encoded = wp_json_encode( $value );
			$bytes   = ( false === $encoded ) ? 0 : strlen( $encoded );
			$type    = is_array( $value ) ? sprintf( 'array(%d)', count( $value ) ) : gettype( $value );

			return sprintf( '%1$s %2$s, %3$d bytes]', self::OMITTED_VALUE_PREFIX, $type, $bytes );
		}

		/**
		 * Context keys that are never persisted.
		 *
		 * `request` holds a WP_REST_Request whose properties are all protected, so it
		 * already collapses to an empty array during redaction. Dropping it keeps the
		 * stored shape honest.
		 *
		 * @since 1.8.0
		 *
		 * @return array Lowercase key names.
		 */
		protected static function get_dropped_context_keys() {
			return array( 'request' );
		}

		/**
		 * Context keys that survive budget enforcement.
		 *
		 * These identify what failed and where, so an entry stays actionable even
		 * after everything else has been dropped.
		 *
		 * @since 1.8.0
		 *
		 * @return array Lowercase key names.
		 */
		protected static function get_preserved_context_keys() {
			return array(
				'assistant_id',
				'endpoint',
				'error_code',
				'error_message',
				'event',
				'guest_request',
				'iteration',
				'max_iterations',
				'reason',
				'schedule_id',
				'tool_slug',
				'user_id',
			);
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
				$context = self::slim_context_for_storage( $entry['context'] );

				if ( ! empty( $context ) ) {
					$stored_entry['context'] = $context;
				}
			}

			$recent[] = $stored_entry;

			$recent = array_slice( $recent, - self::MAX_RECENT_ERRORS );

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
				$context = self::slim_context_for_storage( $entry['context'] );

				if ( ! empty( $context ) ) {
					$stored_entry['context'] = $context;
				}
			}

			$recent[] = $stored_entry;

			$recent = array_slice( $recent, - self::MAX_RECENT_ACTIVITY );

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
					$friendly_message = __( 'Unable to connect to the AI service. Please check your API credentials and try again.', 'mcp-ai-wpoos' );
					$suggestions      = array(
						__( 'Verify your API key is correctly entered in the plugin settings.', 'mcp-ai-wpoos' ),
						__( 'Check that your API key has not expired or been revoked.', 'mcp-ai-wpoos' ),
						__( 'Ensure your account has sufficient credits or quota remaining.', 'mcp-ai-wpoos' ),
						__( 'Verify your server can make outbound HTTPS connections.', 'mcp-ai-wpoos' ),
					);
					break;

				case 'rate_limit_exceeded':
					$friendly_message = __( 'You have exceeded the rate limit for this service. Please wait before trying again.', 'mcp-ai-wpoos' );
					$suggestions      = array(
						__( 'Wait a few minutes before making another request.', 'mcp-ai-wpoos' ),
						__( 'Consider upgrading your API plan for higher rate limits.', 'mcp-ai-wpoos' ),
						__( 'Review the rate limit settings in the plugin configuration.', 'mcp-ai-wpoos' ),
					);
					break;

				case 'network_error':
				case 'connection_timeout':
					$friendly_message = __( 'Network connection failed. Please check your internet connection and try again.', 'mcp-ai-wpoos' );
					$suggestions      = array(
						__( 'Verify your server has an active internet connection.', 'mcp-ai-wpoos' ),
						__( 'Check if your firewall is blocking outbound connections.', 'mcp-ai-wpoos' ),
						__( 'Contact your hosting provider if the issue persists.', 'mcp-ai-wpoos' ),
					);
					break;

				case 'invalid_api_key':
				case 'authentication_failed':
					$friendly_message = __( 'API authentication failed. Your API key appears to be invalid.', 'mcp-ai-wpoos' );
					$suggestions      = array(
						__( 'Double-check the API key in your plugin settings.', 'mcp-ai-wpoos' ),
						__( 'Ensure there are no extra spaces before or after the API key.', 'mcp-ai-wpoos' ),
						__( 'Generate a new API key from your provider\'s dashboard.', 'mcp-ai-wpoos' ),
					);
					break;

				case 'insufficient_quota':
					$friendly_message = __( 'Your API quota has been exhausted. Please upgrade your plan or wait for the quota to reset.', 'mcp-ai-wpoos' );
					$suggestions      = array(
						__( 'Check your current usage in the AI provider\'s dashboard.', 'mcp-ai-wpoos' ),
						__( 'Upgrade to a higher-tier plan if needed.', 'mcp-ai-wpoos' ),
						__( 'Wait until the next billing cycle for quota to reset.', 'mcp-ai-wpoos' ),
					);
					break;

				case 'invalid_model':
					$friendly_message = __( 'The selected AI model is not available or invalid.', 'mcp-ai-wpoos' );
					$suggestions      = array(
						__( 'Verify the model name in your assistant settings.', 'mcp-ai-wpoos' ),
						__( 'Check if the model is available for your API plan.', 'mcp-ai-wpoos' ),
						__( 'Try selecting a different model from the available options.', 'mcp-ai-wpoos' ),
					);
					break;

				case 'tool_execution_failed':
					$tool_name        = isset( $context['tool_slug'] ) ? sanitize_text_field( $context['tool_slug'] ) : 'unknown';
					$friendly_message = sprintf(
						/* translators: %s: Tool name */
						__( 'The tool "%s" failed to execute properly.', 'mcp-ai-wpoos' ),
						$tool_name
					);
					$suggestions = array(
						__( 'Check the tool configuration and try again.', 'mcp-ai-wpoos' ),
						__( 'Verify required permissions for the tool are granted.', 'mcp-ai-wpoos' ),
						__( 'Review the error log for more details.', 'mcp-ai-wpoos' ),
					);
					break;

				case 'file_upload_error':
					$friendly_message = __( 'File upload failed. Please check the file and try again.', 'mcp-ai-wpoos' );
					$suggestions      = array(
						__( 'Ensure the file size is within the allowed limit.', 'mcp-ai-wpoos' ),
						__( 'Verify the file type is supported.', 'mcp-ai-wpoos' ),
						__( 'Check your server\'s upload_max_filesize setting.', 'mcp-ai-wpoos' ),
					);
					break;

				default:
					// Fallback for unknown errors.
					$friendly_message = ! empty( $error_message ) ?
						$error_message :
						__( 'An unexpected error occurred. Please try again.', 'mcp-ai-wpoos' );
					$suggestions      = array(
						__( 'Check the error log for more details.', 'mcp-ai-wpoos' ),
						__( 'Contact support if the problem persists.', 'mcp-ai-wpoos' ),
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
