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
		'gpt-4o'                    => 128000,
		'gpt-4o-mini'               => 128000,
		'gpt-4.1'                   => 1000000,
		'gpt-4.1-mini'              => 1000000,
		'gpt-4.1-nano'              => 1000000,
		'gpt-5'                     => 128000,
		'gpt-5-mini'                => 128000,
		'o1-preview'                => 128000,
		'o1-mini'                   => 128000,
		'o1-2024-12-17'             => 200000,
		'o3-mini'                   => 128000,
		'gpt-4'                     => 8192,
		'gpt-4-turbo'               => 128000,
		'gpt-3.5-turbo'             => 16385,
		'gemini-1.5-pro'            => 2097152,
		'gemini-1.5-flash'          => 1048576,
		'gemini-2.0-flash'          => 1048576,
		'gemini-2.5-flash'          => 1048576,
		'gemini-2.5-flash-image'    => 1048576,
		'gemini-2.0-flash-image'    => 1048576,
		'imagen-3'                  => 8192,
		'claude-3.5-sonnet'         => 200000,
		'claude-3-opus'             => 200000,
		'claude-3-haiku'            => 200000,
		'llama3'                    => 8192,
		'mistral'                   => 8192,
		'codellama'                 => 16384,
		'phi3'                      => 4096,
		'deepseek-coder'            => 16384,
		'deepseek-r1-0528-qwen3-8b' => 32768,
		'qwen2'                     => 32768,
		'gemma2'                    => 8192,
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

		// Try to get limit from CCT first if available.
		if ( class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			$cct_data = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_limits( $model );

			if ( $cct_data && isset( $cct_data['context_window'] ) && $cct_data['context_window'] > 0 ) {
				return absint( $cct_data['context_window'] );
			}
		}

		// Check exact match in hardcoded fallback.
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
		/**
		 * Filter the default token limit fallback for unknown models.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $default_limit Default token limit. Default 8192.
		 * @param string $model         Model identifier.
		 */
		return apply_filters( 'wp_mcp_ai_token_budget_default_limit', 8192, $model );
	}

	/**
	 * Get the TPM (Tokens Per Minute) rate limit for a model.
	 *
	 * @param string $model Model identifier.
	 *
	 * @return int|null TPM limit or null if not configured.
	 */
	public static function get_model_tpm_limit( $model ) {
		$model = sanitize_text_field( $model );

		// Try to get TPM limit from CCT.
		if ( class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			$cct_data = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_limits( $model );

			if ( $cct_data && isset( $cct_data['tpm_limit'] ) && $cct_data['tpm_limit'] > 0 ) {
				return absint( $cct_data['tpm_limit'] );
			}
		}

		return null;
	}

	/**
	 * Get the RPM (Requests Per Minute) rate limit for a model.
	 *
	 * @param string $model Model identifier.
	 *
	 * @return int|null RPM limit or null if not configured.
	 */
	public static function get_model_rpm_limit( $model ) {
		$model = sanitize_text_field( $model );

		// Try to get RPM limit from CCT.
		if ( class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			$cct_data = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_limits( $model );

			if ( $cct_data && isset( $cct_data['rpm_limit'] ) && $cct_data['rpm_limit'] > 0 ) {
				return absint( $cct_data['rpm_limit'] );
			}
		}

		return null;
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
			/**
			 * Filter the default token budget safety margin.
			 *
			 * @since 1.0.0
			 *
			 * @param float $safety_margin Safety margin percentage (0-1). Default 0.1 (10%).
			 */
			$safety_margin = apply_filters( 'wp_mcp_ai_token_budget_safety_margin', self::DEFAULT_SAFETY_MARGIN );
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
		$content = (string) $content;

		/**
		 * Filter the minimum chunk size for document splitting.
		 *
		 * @since 1.0.0
		 *
		 * @param int $min_chunk_size Minimum chunk size in tokens. Default 1000.
		 */
		$min_chunk_size = apply_filters( 'wp_mcp_ai_token_budget_min_chunk_size', self::MIN_CHUNK_SIZE );
		$chunk_size     = max( $min_chunk_size, absint( $chunk_size ) );
		$overlap        = max( 0, min( $chunk_size - 1, absint( $overlap ) ) );

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

		/**
		 * Filter the maximum input tokens limit.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max_input_tokens Maximum input tokens. Default 12000.
		 */
		$max_input_tokens = apply_filters( 'wp_mcp_ai_token_budget_max_input_tokens', self::MAX_INPUT_TOKENS );

		if ( $budget['used'] > $max_input_tokens ) {
			return new WP_Error(
				'wp_mcp_ai_input_tokens_exceeded',
				sprintf(
					/* translators: %1$d: used tokens, %2$d: maximum allowed tokens */
					__( 'Input exceeds maximum token limit. Used: %1$d tokens, Maximum: %2$d tokens. Please reduce message length or split into chunks.', 'wp-mcp-ai' ),
					$budget['used'],
					$max_input_tokens
				),
				array(
					'status'      => 400,
					'used_tokens' => $budget['used'],
					'max_tokens'  => $max_input_tokens,
				)
			);
		}

		return true;
	}

	/**
	 * Validate that total request tokens are within TPM (Tokens Per Minute) limits.
	 *
	 * @param array  $messages         Messages array.
	 * @param string $model            Model identifier.
	 * @param int    $max_output_tokens Optional maximum output tokens.
	 *
	 * @return bool|WP_Error True if valid, WP_Error if exceeds TPM limit.
	 */
	public static function validate_tpm_limit( array $messages, $model, $max_output_tokens = 0 ) {
		$tpm_limit = self::get_model_tpm_limit( $model );

		// If no TPM limit is configured, skip validation (e.g., local models).
		if ( null === $tpm_limit || 0 === $tpm_limit ) {
			return true;
		}

		$budget = self::calculate_budget( $model, $messages, $max_output_tokens );

		// Calculate total tokens for the request (input + estimated output).
		$total_tokens = $budget['used'] + $budget['reserved'];

		// Check if total request tokens exceed the TPM limit.
		if ( $total_tokens > $tpm_limit ) {
			$model_name = sanitize_text_field( $model );

			WP_MCP_AI_Logger::log_error(
				'Request exceeds TPM limit for model.',
				array(
					'model'             => $model_name,
					'tpm_limit'         => $tpm_limit,
					'requested_tokens'  => $total_tokens,
					'input_tokens'      => $budget['used'],
					'reserved_output'   => $budget['reserved'],
					'max_output_tokens' => $max_output_tokens,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_tpm_limit_exceeded',
				sprintf(
					/* translators: %1$s: model name, %2$d: TPM limit, %3$d: requested tokens */
					__( 'Request too large for %1$s. Limit: %2$d TPM, Requested: %3$d tokens. Please reduce the input size, use a smaller max_tokens value, or switch to a model with higher limits.', 'wp-mcp-ai' ),
					$model_name,
					$tpm_limit,
					$total_tokens
				),
				array(
					'status'            => 400,
					'model'             => $model_name,
					'tpm_limit'         => $tpm_limit,
					'requested_tokens'  => $total_tokens,
					'input_tokens'      => $budget['used'],
					'reserved_output'   => $budget['reserved'],
					'max_output_tokens' => $max_output_tokens,
					'suggested_models'  => self::get_higher_limit_models( $model, $total_tokens ),
				)
			);
		}

		return true;
	}

	/**
	 * Get models with higher TPM limits that could handle the request.
	 *
	 * @param string $current_model   Current model identifier.
	 * @param int    $required_tokens Tokens required for the request.
	 *
	 * @return array Array of suggested model names.
	 */
	protected static function get_higher_limit_models( $current_model, $required_tokens ) {
		$suggested = array();

		// Check if the model is from OpenAI.
		$current_model_lower = strtolower( $current_model );
		$is_openai           = false !== strpos( $current_model_lower, 'gpt' ) || false !== strpos( $current_model_lower, 'o1' );
		$is_gemini           = false !== strpos( $current_model_lower, 'gemini' );
		$is_claude           = false !== strpos( $current_model_lower, 'claude' );

		// Suggest models based on the provider.
		if ( $is_openai ) {
			// Suggest OpenAI models with higher limits.
			$openai_alternatives = array(
				'gpt-4o'       => 30000,    // Tier 1.
				'gpt-4.1-mini' => 400000,   // Future model.
				'gpt-4.1'      => 300000,   // Future model.
				'gpt-5-mini'   => 500000,   // Future model.
				'gpt-5'        => 500000,   // Future model.
			);

			foreach ( $openai_alternatives as $model => $tpm ) {
				if ( $model !== $current_model && $tpm >= $required_tokens ) {
					$suggested[] = $model;
				}
			}
		} elseif ( $is_gemini ) {
			// Gemini models have very high TPM limits (1M).
			$suggested[] = 'gemini-1.5-flash';
			$suggested[] = 'gemini-2.0-flash';
			$suggested[] = 'gemini-1.5-pro';
		} elseif ( $is_claude ) {
			// Claude models have varying TPM limits.
			$claude_alternatives = array(
				'claude-3-haiku'    => 50000,
				'claude-3.5-sonnet' => 40000,
			);

			foreach ( $claude_alternatives as $model => $tpm ) {
				if ( $model !== $current_model && $tpm >= $required_tokens ) {
					$suggested[] = $model;
				}
			}
		}

		// Always suggest Gemini as a fallback for very large requests.
		if ( $required_tokens > 200000 && ! $is_gemini ) {
			$suggested[] = 'gemini-1.5-flash';
			$suggested[] = 'gemini-2.0-flash';
		}

		return array_unique( $suggested );
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
