<?php
/**
 * Token budget manager for API usage optimization.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages token budgets to prevent API limit overruns.
 */
class WP_MCP_AI_Token_Budget_Manager {

	/**
	 * Default safety margin percentage (10%).
	 */
	const DEFAULT_SAFETY_MARGIN = 0.1;

	/**
	 * Minimum chunk size for document splitting.
	 */
	const MIN_CHUNK_SIZE = 1000;

	/**
	 * Maximum input tokens per OpenAI request (12k limit).
	 */
	const MAX_INPUT_TOKENS = 12000;

	/**
	 * Default chunk size for large documents (6-8k tokens).
	 */
	const DEFAULT_CHUNK_SIZE = 7000;

	/**
	 * Default chunk overlap in tokens.
	 */
	const DEFAULT_CHUNK_OVERLAP = 200;

	/**
	 * Model token limits (max context tokens).
	 *
	 * @var array
	 */
	protected static $model_limits = array(
		'gpt-4o'           => 128000,
		'gpt-4o-mini'      => 128000,
		'gpt-4.1'          => 128000,
		'gpt-4.1-mini'     => 128000,
		'o1-preview'       => 128000,
		'o1-mini'          => 128000,
		'gpt-4'            => 8192,
		'gpt-3.5-turbo'    => 16385,
		'gemini-1.5-pro'   => 2097152,
		'gemini-1.5-flash' => 1048576,
	);

	/**
	 * Estimate token count for text.
	 *
	 * Uses a simple heuristic: ~4 characters per token on average.
	 * For more accurate counting, consider using a tokenizer library.
	 *
	 * @param string $text Text to estimate.
	 *
	 * @return int Estimated token count.
	 */
	public static function estimate_tokens( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return 0;
		}

		// Simple heuristic: 4 characters per token on average.
		// This is a rough estimate; actual token counts vary by model.
		$char_count = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		return (int) ceil( $char_count / 4 );
	}

	/**
	 * Get the token limit for a model.
	 *
	 * @param string $model Model identifier.
	 *
	 * @return int Token limit.
	 */
	public static function get_model_limit( $model ) {
		$model = sanitize_text_field( $model );

		// Check exact match first.
		if ( isset( self::$model_limits[ $model ] ) ) {
			return self::$model_limits[ $model ];
		}

		// Try partial match for model families.
		foreach ( self::$model_limits as $key => $limit ) {
			if ( 0 === strpos( $model, $key ) ) {
				return $limit;
			}
		}

		// Default to a conservative limit.
		return 8192;
	}

	/**
	 * Calculate available token budget for a request.
	 *
	 * @param string $model            Model identifier.
	 * @param array  $messages         Array of messages.
	 * @param int    $max_output_tokens Maximum output tokens (optional).
	 * @param float  $safety_margin    Safety margin percentage (0-1).
	 *
	 * @return array Budget information with 'available', 'used', 'limit' keys.
	 */
	public static function calculate_budget( $model, array $messages, $max_output_tokens = 0, $safety_margin = null ) {
		if ( null === $safety_margin ) {
			$safety_margin = self::DEFAULT_SAFETY_MARGIN;
		}

		$safety_margin = max( 0, min( 1, (float) $safety_margin ) );

		$model_limit       = self::get_model_limit( $model );
		$effective_limit   = (int) ( $model_limit * ( 1 - $safety_margin ) );
		$max_output_tokens = absint( $max_output_tokens );

		// Estimate tokens used by messages.
		$used_tokens = 0;
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			// Count role tokens.
			if ( isset( $message['role'] ) ) {
				$used_tokens += self::estimate_tokens( $message['role'] );
			}

			// Count content tokens.
			if ( isset( $message['content'] ) ) {
				if ( is_string( $message['content'] ) ) {
					$used_tokens += self::estimate_tokens( $message['content'] );
				} elseif ( is_array( $message['content'] ) ) {
					foreach ( $message['content'] as $segment ) {
						if ( is_array( $segment ) && isset( $segment['text'] ) ) {
							$used_tokens += self::estimate_tokens( $segment['text'] );
						}
					}
				}
			}

			// Account for message formatting overhead (~4 tokens per message).
			$used_tokens += 4;
		}

		// Reserve space for output.
		$reserved_for_output = $max_output_tokens > 0 ? $max_output_tokens : (int) ( $effective_limit * 0.2 );
		$available           = max( 0, $effective_limit - $used_tokens - $reserved_for_output );

		return array(
			'available' => $available,
			'used'      => $used_tokens,
			'reserved'  => $reserved_for_output,
			'limit'     => $effective_limit,
			'model'     => $model,
		);
	}

	/**
	 * Truncate messages to fit within token budget.
	 *
	 * @param array  $messages Array of messages.
	 * @param string $model    Model identifier.
	 * @param int    $max_tokens Maximum total tokens.
	 *
	 * @return array Truncated messages array.
	 */
	public static function truncate_messages( array $messages, $model, $max_tokens ) {
		$max_tokens = absint( $max_tokens );

		if ( $max_tokens <= 0 ) {
			return array();
		}

		$budget = self::calculate_budget( $model, $messages, $max_tokens );

		// If already within budget, return as-is.
		if ( $budget['used'] <= $budget['limit'] ) {
			return $messages;
		}

		// Truncate from oldest to newest, preserving system and recent messages.
		$system_messages = array();
		$user_messages   = array();
		$result          = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'] ) ) {
				continue;
			}

			if ( 'system' === $message['role'] ) {
				$system_messages[] = $message;
			} else {
				$user_messages[] = $message;
			}
		}

		// Always include system messages.
		$result         = $system_messages;
		$current_tokens = 0;

		foreach ( $system_messages as $message ) {
			$current_tokens += self::estimate_tokens( wp_json_encode( $message ) );
		}

		// Add user messages from newest to oldest until budget is reached.
		$user_messages = array_reverse( $user_messages );

		foreach ( $user_messages as $message ) {
			$message_tokens = self::estimate_tokens( wp_json_encode( $message ) );

			if ( $current_tokens + $message_tokens <= $max_tokens ) {
				array_unshift( $result, $message );
				$current_tokens += $message_tokens;
			} else {
				WP_MCP_AI_Logger::log_event(
					'token_budget_truncated',
					'Messages truncated to fit token budget.',
					array(
						'max_tokens'      => $max_tokens,
						'current_tokens'  => $current_tokens,
						'dropped_message' => isset( $message['role'] ) ? $message['role'] : 'unknown',
					)
				);
				break;
			}
		}

		return $result;
	}

	/**
	 * Split a large document into chunks that fit within token limits.
	 *
	 * @param string $content    Document content to split.
	 * @param int    $chunk_size Target chunk size in tokens.
	 * @param int    $overlap    Number of tokens to overlap between chunks.
	 *
	 * @return array Array of text chunks.
	 */
	public static function split_document( $content, $chunk_size, $overlap = 0 ) {
		$content    = (string) $content;
		$chunk_size = max( self::MIN_CHUNK_SIZE, absint( $chunk_size ) );
		$overlap    = max( 0, min( $chunk_size - 1, absint( $overlap ) ) );

		if ( '' === $content ) {
			return array();
		}

		$chunks = array();

		// Approximate characters per chunk (4 chars per token).
		$chars_per_chunk = $chunk_size * 4;
		$overlap_chars   = $overlap * 4;

		$content_length = function_exists( 'mb_strlen' ) ? mb_strlen( $content, 'UTF-8' ) : strlen( $content );

		if ( $content_length <= $chars_per_chunk ) {
			return array( $content );
		}

		$position = 0;

		while ( $position < $content_length ) {
			$chunk_end = min( $position + $chars_per_chunk, $content_length );

			// Extract chunk.
			if ( function_exists( 'mb_substr' ) ) {
				$chunk = mb_substr( $content, $position, $chunk_end - $position, 'UTF-8' );
			} else {
				$chunk = substr( $content, $position, $chunk_end - $position );
			}

			// Try to break at sentence or paragraph boundaries.
			if ( $chunk_end < $content_length ) {
				$break_positions = array();

				// Look for paragraph breaks.
				$para_pos = strrpos( $chunk, "\n\n" );
				if ( false !== $para_pos ) {
					$break_positions[] = $para_pos + 2;
				}

				// Look for sentence breaks.
				$sentence_pos = max(
					strrpos( $chunk, '. ' ),
					strrpos( $chunk, '! ' ),
					strrpos( $chunk, '? ' )
				);
				if ( false !== $sentence_pos ) {
					$break_positions[] = $sentence_pos + 2;
				}

				// Use the best break position if found.
				if ( ! empty( $break_positions ) ) {
					$best_break = max( $break_positions );
					if ( function_exists( 'mb_substr' ) ) {
						$chunk = mb_substr( $chunk, 0, $best_break, 'UTF-8' );
					} else {
						$chunk = substr( $chunk, 0, $best_break );
					}
				}
			}

			$chunks[] = $chunk;

			// Move position forward, accounting for overlap.
			$chunk_length = function_exists( 'mb_strlen' ) ? mb_strlen( $chunk, 'UTF-8' ) : strlen( $chunk );
			$position    += max( 1, $chunk_length - $overlap_chars );
		}

		return $chunks;
	}

	/**
	 * Check if streaming should be enabled based on estimated response size.
	 *
	 * @param array  $messages         Messages array.
	 * @param string $model            Model identifier.
	 * @param int    $streaming_threshold Minimum tokens to trigger streaming.
	 *
	 * @return bool True if streaming is recommended.
	 */
	public static function should_stream( array $messages, $model, $streaming_threshold = 1000 ) {
		$budget              = self::calculate_budget( $model, $messages );
		$streaming_threshold = absint( $streaming_threshold );

		// Recommend streaming if we expect a large response.
		return $budget['reserved'] >= $streaming_threshold;
	}

	/**
	 * Validate that input tokens are within OpenAI limits.
	 *
	 * @param array  $messages Messages array.
	 * @param string $model    Model identifier.
	 *
	 * @return bool|WP_Error True if valid, WP_Error if exceeds limit.
	 */
	public static function validate_input_tokens( array $messages, $model ) {
		$budget = self::calculate_budget( $model, $messages );

		if ( $budget['used'] > self::MAX_INPUT_TOKENS ) {
			return new WP_Error(
				'wp_mcp_ai_input_tokens_exceeded',
				sprintf(
					/* translators: %1$d: used tokens, %2$d: maximum allowed tokens */
					__( 'Input exceeds maximum token limit. Used: %1$d tokens, Maximum: %2$d tokens. Please reduce message length or split into chunks.', 'wp-mcp-ai' ),
					$budget['used'],
					self::MAX_INPUT_TOKENS
				),
				array(
					'status'      => 400,
					'used_tokens' => $budget['used'],
					'max_tokens'  => self::MAX_INPUT_TOKENS,
				)
			);
		}

		return true;
	}

	/**
	 * Get recommended chunk size for document splitting.
	 *
	 * @param string $model Model identifier (optional).
	 *
	 * @return int Chunk size in tokens.
	 */
	public static function get_recommended_chunk_size( $model = '' ) {
		/**
		 * Filter the recommended chunk size for document splitting.
		 *
		 * @param int    $chunk_size Default chunk size.
		 * @param string $model      Model identifier.
		 */
		return apply_filters( 'wp_mcp_ai_recommended_chunk_size', self::DEFAULT_CHUNK_SIZE, $model );
	}

	/**
	 * Optimize messages for token efficiency.
	 *
	 * @param array  $messages Messages array.
	 * @param string $model    Model identifier.
	 * @param array  $options  Optimization options.
	 *
	 * @return array Optimized messages.
	 */
	public static function optimize_messages( array $messages, $model, array $options = array() ) {
		$max_tokens         = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : self::get_model_limit( $model );
		$enable_truncation  = isset( $options['enable_truncation'] ) ? (bool) $options['enable_truncation'] : true;
		$enable_compression = isset( $options['enable_compression'] ) ? (bool) $options['enable_compression'] : false;

		if ( $enable_truncation ) {
			$messages = self::truncate_messages( $messages, $model, $max_tokens );
		}

		if ( $enable_compression ) {
			// Simple compression: remove excessive whitespace.
			foreach ( $messages as &$message ) {
				if ( is_array( $message ) && isset( $message['content'] ) && is_string( $message['content'] ) ) {
					$message['content'] = preg_replace( '/\s+/', ' ', $message['content'] );
					$message['content'] = trim( $message['content'] );
				}
			}
			unset( $message );
		}

		return $messages;
	}
}
