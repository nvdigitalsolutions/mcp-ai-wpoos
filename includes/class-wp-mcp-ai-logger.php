<?php
/**
 * Simple logging utility for WP MCP AI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper for writing structured log entries.
 */
class WP_MCP_AI_Logger {
	/**
	 * Prefix that is added to every log line for easier filtering.
	 */
	const PREFIX = '[WP MCP AI]';

	/**
	 * Maximum number of characters that should be written to the PHP error log
	 * for a single entry. PHP-FPM buffers log lines at 1024 bytes so we keep a
	 * safety margin below that threshold to avoid truncation warnings.
	 */
	const MAX_LOG_LINE_LENGTH = 900;

	/**
	 * Record a generic log event when logging is enabled.
	 *
	 * @param string $type    Event type (chat_request, tool_result, error, etc.).
	 * @param string $message Human readable description of the event.
	 * @param array  $context Additional context for the entry.
	 */
	public static function log_event( $type, $message, $context = array() ) {
		if ( ! WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
			return;
		}

		$type        = sanitize_key( $type );
		$message     = (string) $message;
		$raw_context = is_array( $context ) ? $context : array();
		$context     = self::sanitize_context( $raw_context );

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
	 * Convenience wrapper for logging errors.
	 *
	 * @param string $message Error message.
	 * @param array  $context Optional context.
	 */
	public static function log_error( $message, $context = array() ) {
		self::log_event( 'error', $message, $context );
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

		return $context;
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
