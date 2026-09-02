<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * Token budget manager for API usage optimization.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		// OpenAI GPT-5 family (June 2026).
		'gpt-5.5'                   => 1050000,
		'gpt-5.5-mini'              => 270000,
		'gpt-5.4'                   => 1050000,
		'gpt-5.4-mini'              => 270000,
		'gpt-5.4-nano'              => 128000,
		'gpt-5.3'                   => 922000,
		'gpt-5.2'                   => 270000,
		'gpt-5.1'                   => 128000,
		'gpt-5'                     => 128000,
		'gpt-5-mini'                => 128000,
		// OpenAI o-series reasoning models.
		'o4'                        => 200000,
		'o4-mini'                   => 200000,
		'o3'                        => 200000,
		'o3-mini'                   => 128000,
		'o1-2024-12-17'             => 200000,
		'o1-preview'                => 128000,
		'o1-mini'                   => 128000,
		// OpenAI legacy (still in use via OpenRouter / older configs).
		'gpt-4.1-mini'              => 1000000,
		'gpt-4.1-nano'              => 1000000,
		'gpt-4.1'                   => 128000,
		'gpt-4o-mini'               => 128000,
		'gpt-4-turbo'               => 128000,
		'gpt-4'                     => 8192,
		'gpt-3.5-turbo'             => 16385,
		// Anthropic Claude (June 2026).
		// Opus 4.6-4.8 and Sonnet 4.6: 1M context at standard pricing.
		'claude-mythos-preview'     => 1000000,
		'claude-opus-4-8'           => 1000000,
		'claude-opus-4-7'           => 1000000,
		'claude-opus-4-6'           => 1000000,
		'claude-sonnet-4-6'         => 1000000,
		'claude-opus-4-5'           => 200000,
		'claude-sonnet-4-5'         => 200000,
		'claude-haiku-4-5'          => 200000,
		'claude-3-5-sonnet'         => 200000,
		'claude-3-opus'             => 200000,
		'claude-3-haiku'            => 200000,
		// Google Gemini (June 2026).
		'gemini-3.5-flash'          => 1048576,
		'gemini-3.1-pro'            => 2000000,
		'gemini-3.1-pro-preview'    => 1000000,
		'gemini-3.1-flash'          => 1000000,
		'gemini-3.1-flash-lite'     => 1000000,
		'gemini-3-pro-preview'      => 1000000,
		'gemini-3-flash-preview'    => 1000000,
		'gemini-2.5-pro'            => 1048576,
		'gemini-3.1-flash-image'    => 131072,
		'gemini-2.5-flash-image'    => 1048576,
		'gemini-2.0-flash-image'    => 1048576,
		'gemini-2.5-flash'          => 2097152,
		'gemini-1.5-flash'          => 1048576,
		'imagen-3'                  => 8192,
		// DeepSeek.
		'deepseek-v4-flash'         => 1048576,
		'deepseek-v4-pro'           => 1048576,
		'deepseek-chat'             => 65536,
		'deepseek-reasoner'         => 65536,
		'deepseek-v3'               => 65536,
		'deepseek-coder'            => 16384,
		'deepseek-r1-0528-qwen3-8b' => 32768,
		// Kimi / Moonshot AI.
		'kimi-k2.6'                 => 262144,
		'kimi-k2.5'                 => 262144,
		'kimi-k2'                   => 262144,
		'kimi-k2-thinking'          => 262144,
		// Meta Llama.
		'llama4'                    => 131072,
		'llama3.3'                  => 131072,
		'llama3.2'                  => 131072,
		'llama3.1'                  => 131072,
		'llama3'                    => 8192,
		// Mistral AI.
		'mixtral'                   => 32768,
		'mistral-large'             => 131072,
		'mistral-small'             => 32768,
		'mistral'                   => 8192,
		// Qwen (Alibaba).
		'qwen3.5'                   => 131072,
		'qwen3'                     => 131072,
		'qwen2.5'                   => 131072,
		'qwen2'                     => 32768,
		// NVIDIA Nemotron.
		'nemotron-3'                => 1048576,
		// Other open models.
		'codellama'                 => 16384,
		'phi4'                      => 16384,
		'phi3'                      => 4096,
		'gemma4'                    => 262144,
		'gemma3'                    => 32768,
		'gemma2'                    => 8192,
	);

	/**
	 * Hardcoded TPM (Tokens Per Minute) fallback limits.
	 *
	 * Used when no CCT-based limit is configured. Values reflect Anthropic Tier 1
	 * defaults and conservative estimates for other providers. These ensure that
	 * TPM validation has a reasonable safety net even without explicit configuration.
	 *
	 * @var array
	 */
	protected static $default_tpm_limits = array(
		// Anthropic Claude models — Tier 1 defaults.
		'claude-mythos-preview' => 40000,
		'claude-opus-4-6'       => 40000,
		'claude-sonnet-4-6'     => 80000,
		'claude-opus-4-5'       => 40000,
		'claude-sonnet-4-5'     => 80000,
		'claude-haiku-4-5'      => 50000,
		'claude-3-5-sonnet'     => 80000,
		'claude-3-opus'         => 40000,
		'claude-3-haiku'        => 50000,
	);

	/**
	 * Maximum output tokens per model.
	 *
	 * Used to cap the reserved output tokens when calculating budgets so we
	 * do not over-reserve and accidentally exceed the TPM limit.
	 *
	 * @var array
	 */
	protected static $model_max_output_tokens = array(
		'claude-mythos-preview' => 128000,
		'claude-opus-4-6'       => 128000,
		'claude-sonnet-4-6'     => 64000,
		'claude-opus-4-5'       => 128000,
		'claude-sonnet-4-5'     => 64000,
		'claude-haiku-4-5'      => 64000,
		'claude-3-5-sonnet'     => 8192,
		'claude-3-opus'         => 4096,
		'claude-3-haiku'        => 4096,
	);

	/**
	 * Estimate token count for text.
	 *
	 * When the tiktoken-php library is available, uses OpenAI's byte-pair
	 * encoding tokenizer (o200k_base for GPT-4o family, cl100k_base for
	 * GPT-4/GPT-3.5, p50k_base for Davinci). Falls back to the chars/4
	 * heuristic when tiktoken is not installed.
	 *
	 * @since 1.0.0
	 * @since 2.7.0 Added tiktoken-backed accurate counting with heuristic fallback.
	 *
	 * @param string      $text  Text to estimate.
	 * @param string|null $model Optional model slug for encoding selection (default: 'gpt-4.1').
	 *
	 * @return int Estimated token count.
	 */
	public static function estimate_tokens( $text, $model = null ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return 0;
		}

		// Try tiktoken-php for accurate, model-aware counting.
		if ( class_exists( 'Rahul900day\Tiktoken\Tiktoken' ) ) {
			try {
				$encoding = self::resolve_tiktoken_encoding( $model );
				$encoder  = \Rahul900day\Tiktoken\Tiktoken::getEncoding( $encoding );
				$tokens   = $encoder->encode( $text );
				return count( $tokens );
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentional fallback to heuristic.
			}
		}

		// Fallback heuristic: 4 characters per token on average.
		$char_count = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		return (int) ceil( $char_count / 4 );
	}

	/**
	 * Resolve the tiktoken encoding name for a model slug.
	 *
	 * Maps model families to OpenAI encoding schemes:
	 *   - GPT-4o family → o200k_base (most efficient for newer models)
	 *   - GPT-4, GPT-3.5 → cl100k_base
	 *   - Davinci, text-* → p50k_base
	 *   - Unknown → cl100k_base (safe default)
	 *
	 * @since 2.7.0
	 *
	 * @param string|null $model Model slug.
	 * @return string Encoding name.
	 */
	protected static function resolve_tiktoken_encoding( $model ) {
		$model = is_string( $model ) ? strtolower( trim( $model ) ) : '';

		$encoding_map = array(
			'gpt-4.1'        => 'o200k_base',
			'gpt-5'          => 'o200k_base',
			'o1'             => 'o200k_base',
			'o3'             => 'o200k_base',
			'o4'             => 'o200k_base',
			'gpt-4'          => 'cl100k_base',
			'gpt-3.5'        => 'cl100k_base',
			'text-davinci'   => 'p50k_base',
			'text-embedding' => 'cl100k_base',
		);

		if ( '' !== $model ) {
			foreach ( $encoding_map as $prefix => $encoding ) {
				if ( 0 === strpos( $model, $prefix ) ) {
					return $encoding;
				}
			}
		}

		// Safe default for unknown models.
		return 'cl100k_base';
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
	 * Get the maximum output tokens a model can produce per request.
	 *
	 * @param string $model Model identifier.
	 *
	 * @return int Maximum output tokens, or 0 if unknown.
	 */
	public static function get_model_max_output_tokens( $model ) {
		$model = sanitize_text_field( $model );

		// Exact match.
		if ( isset( self::$model_max_output_tokens[ $model ] ) ) {
			return self::$model_max_output_tokens[ $model ];
		}

		// Prefix match for date-versioned model IDs.
		foreach ( self::$model_max_output_tokens as $key => $limit ) {
			if ( 0 === strpos( $model, $key ) ) {
				return $limit;
			}
		}

		return 0;
	}

	/**
	 * Get the TPM (Tokens Per Minute) rate limit for a model.
	 *
	 * @param string $model Model identifier.
	 *
	 * @return int|null TPM limit or null if not configured.
	 */
	public static function get_model_tpm_limit( $model ) {
		$model     = sanitize_text_field( $model );
		$tpm_limit = null;

		// Try to get TPM limit from CCT first (user-configured or auto-discovered).
		if ( class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			$cct_data = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_limits( $model );

			if ( $cct_data && isset( $cct_data['tpm_limit'] ) && $cct_data['tpm_limit'] > 0 ) {
				$tpm_limit = absint( $cct_data['tpm_limit'] );
			}
		}

		// Fall back to the bundled model catalog when the CCT has no entry
		// (e.g. JetEngine is inactive or the CCT has not been populated yet).
		if ( null === $tpm_limit && class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			$catalog_entry = self::find_catalog_model(
				WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data(),
				$model
			);

			if ( $catalog_entry && ! empty( $catalog_entry['tpm_limit'] ) ) {
				$tpm_limit = absint( $catalog_entry['tpm_limit'] );
			}
		}

		// Fall back to hardcoded TPM defaults (Anthropic Tier 1 defaults, etc.).
		// Check exact match first, then try prefix matching for date-versioned model IDs
		// (e.g. 'claude-opus-4-6-20260301' matches 'claude-opus-4-6').
		if ( null === $tpm_limit ) {
			if ( isset( self::$default_tpm_limits[ $model ] ) ) {
				$tpm_limit = self::$default_tpm_limits[ $model ];
			} else {
				foreach ( self::$default_tpm_limits as $key => $limit ) {
					if ( 0 === strpos( $model, $key ) ) {
						$tpm_limit = $limit;
						break;
					}
				}
			}
		}

		/**
		 * Filter the TPM (Tokens Per Minute) rate limit for a model.
		 *
		 * @since 1.0.0
		 *
		 * @param int|null $tpm_limit Resolved TPM limit, or null when unconfigured.
		 * @param string   $model     Model identifier.
		 */
		return apply_filters( 'wp_mcp_ai_model_tpm_limit', $tpm_limit, $model );
	}

	/**
	 * Find a model entry in the bundled catalog by exact name or longest prefix.
	 *
	 * Mirrors the CCT's prefix-matching behaviour so date-versioned model IDs
	 * (e.g. 'gpt-5-2025-08-07') resolve to their base family entry.
	 *
	 * @param array  $catalog Model catalog entries.
	 * @param string $model   Model identifier.
	 *
	 * @return array|null Matching entry or null.
	 */
	protected static function find_catalog_model( $catalog, $model ) {
		if ( ! is_array( $catalog ) ) {
			return null;
		}

		$best_match        = null;
		$best_match_length = 0;

		foreach ( $catalog as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['model_name'] ) ) {
				continue;
			}

			$stored_model = sanitize_text_field( $entry['model_name'] );

			if ( 0 === strpos( $model, $stored_model ) ) {
				$match_length = strlen( $stored_model );

				if ( $match_length > $best_match_length ) {
					$best_match        = $entry;
					$best_match_length = $match_length;
				}
			}
		}

		return $best_match;
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
		// When max_output_tokens is not explicitly provided, use a model-aware default
		// rather than a blanket 20% of the context window. This prevents over-reserving
		// for models with low TPM limits (e.g. Anthropic Tier 1: 40K TPM).
		if ( $max_output_tokens > 0 ) {
			$reserved_for_output = $max_output_tokens;
		} else {
			// Check for a known model-specific output limit.
			$model_output_cap = self::get_model_max_output_tokens( $model );

			if ( $model_output_cap > 0 ) {
				// Use the lesser of 20% of context window or the model's output cap.
				$reserved_for_output = min( (int) ( $effective_limit * 0.2 ), $model_output_cap );
			} else {
				$reserved_for_output = (int) ( $effective_limit * 0.2 );
			}

			// When a TPM limit is configured, ensure reserved output does not cause the
			// total (input + reserved) to exceed it. Cap to at most 25% of TPM.
			$tpm_limit = self::get_model_tpm_limit( $model );
			if ( null !== $tpm_limit && $tpm_limit > 0 ) {
				$tpm_output_cap      = (int) ( $tpm_limit * 0.25 );
				$reserved_for_output = min( $reserved_for_output, $tpm_output_cap );
			}

			// Ensure we always reserve at least some tokens for output.
			$reserved_for_output = max( 1024, $reserved_for_output );
		}

		$available = max( 0, $effective_limit - $used_tokens - $reserved_for_output );

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

		// Estimate message tokens only — pass 0 for max_output_tokens so that
		// the budget calculation does not include output-token reservation, which
		// would inflate `$budget['used']` relative to the caller's $max_tokens.
		$budget = self::calculate_budget( $model, $messages, 0 );

		// If message tokens already fit within the requested budget, return as-is.
		// Compare against $max_tokens (the caller's target) rather than the model's
		// context-window limit so that TPM-based truncation works correctly when
		// TPM << context window (e.g. 40 000 TPM vs 200 000 context for Claude).
		if ( $budget['used'] <= $max_tokens ) {
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
					__( 'Input exceeds maximum token limit. Used: %1$d tokens, Maximum: %2$d tokens. Please reduce message length or split into chunks.', 'mcp-ai-wpoos' ),
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
					__( 'Request too large for %1$s. Limit: %2$d TPM, Requested: %3$d tokens. Please reduce the input size, use a smaller max_tokens value, or switch to a model with higher limits.', 'mcp-ai-wpoos' ),
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
			$suggested[] = 'gemini-2.5-flash';
			$suggested[] = 'gemini-2.5-flash';
		} elseif ( $is_claude ) {
			// Claude models — suggest models with higher TPM limits.
			// Uses the same hardcoded defaults as $default_tpm_limits.
			foreach ( self::$default_tpm_limits as $model => $tpm ) {
				if ( $model !== $current_model && $tpm >= $required_tokens ) {
					$suggested[] = $model;
				}
			}
		}

		// Always suggest Gemini as a fallback for very large requests.
		if ( $required_tokens > 200000 && ! $is_gemini ) {
			$suggested[] = 'gemini-1.5-flash';
			$suggested[] = 'gemini-2.5-flash';
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

	/**
	 * Validate that a built chat-completion payload fits within the model's
	 * context window before sending it to the provider.
	 *
	 * This is the shared pre-flight check used by OpenAI, Anthropic, DeepSeek,
	 * OpenRouter, Baseten, and DigitalOcean clients. It estimates the serialised
	 * payload size, adds the output budget, and returns a clear WP_Error with
	 * actionable suggestions when the total exceeds the window.
	 *
	 * @since 2.7.0
	 *
	 * @param array  $payload    The fully-built request payload (model, messages, tools, etc.).
	 * @param string $model      Resolved model slug.
	 * @param string $provider   Provider key for logging (e.g. 'deepseek', 'openrouter').
	 * @param array  $options    Original request options (used to count tools).
	 * @param array  $messages   Original messages (used for count in logs).
	 * @return null|WP_Error     Null if payload fits, WP_Error if it exceeds the context window.
	 */
	public static function validate_context_window( array $payload, $model, $provider, array $options = array(), array $messages = array() ) {
		$context_limit = self::get_model_limit( $model );

		if ( $context_limit <= 0 ) {
			return null; // Unknown model — cannot validate.
		}

		$payload_json     = wp_json_encode( $payload );
		$estimated_tokens = self::estimate_tokens( $payload_json, $model );

		// Resolve output budget from the payload.
		$output_budget = 4096;
		if ( isset( $payload['max_tokens'] ) ) {
			$output_budget = (int) $payload['max_tokens'];
		} elseif ( isset( $payload['max_completion_tokens'] ) ) {
			$output_budget = (int) $payload['max_completion_tokens'];
		} elseif ( isset( $payload['max_output_tokens'] ) ) {
			$output_budget = (int) $payload['max_output_tokens'];
		}

		$total_estimated = $estimated_tokens + $output_budget;
		$usage_pct       = round( ( $total_estimated / $context_limit ) * 100, 1 );
		$tool_count      = isset( $options['tools'] ) && is_array( $options['tools'] ) ? count( $options['tools'] ) : 0;

		// Log for diagnostics.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				$provider . '_preflight_tokens',
				'Pre-flight token estimate',
				array(
					'model'            => $model,
					'provider'         => $provider,
					'context_limit'    => $context_limit,
					'estimated_prompt' => $estimated_tokens,
					'output_budget'    => $output_budget,
					'total_estimated'  => $total_estimated,
					'usage_pct'        => $usage_pct,
					'tool_count'       => $tool_count,
					'message_count'    => count( $messages ),
				)
			);
		}

		// Hard-reject when the estimate exceeds the context window.
		if ( $total_estimated > $context_limit ) {
			$provider_label = ucfirst( $provider );

			return new WP_Error(
				'wp_mcp_ai_context_window_exceeded',
				sprintf(
					/* translators: 1: estimated total tokens, 2: model context window limit, 3: percentage, 4: model name */
					__( 'The request payload (~%1$s tokens) exceeds the model context window of %2$s tokens (%3$s%%). Reduce the system prompt, limit conversation history, or deselect tools (currently %5$d selected). Consider switching to a model with a larger context window.', 'mcp-ai-wpoos' ),
					number_format_i18n( $total_estimated ),
					number_format_i18n( $context_limit ),
					$usage_pct,
					$model,
					$tool_count
				),
				array(
					'status'           => 400,
					'estimated_tokens' => $total_estimated,
					'context_limit'    => $context_limit,
					'model'            => $model,
					'provider'         => $provider,
					'tool_count'       => $tool_count,
					'actions'          => array(
						'reduce_tools'          => __( 'Deselect tools on the assistant edit page.', 'mcp-ai-wpoos' ),
						'shorten_system_prompt' => __( 'Shorten the system prompt in the assistant defaults.', 'mcp-ai-wpoos' ),
						'limit_history'         => __( 'Start a new conversation or use semantic compression.', 'mcp-ai-wpoos' ),
						'upgrade_model'         => __( 'Switch to a model with a larger context window.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		// Soft-warn at high usage.
		if ( $usage_pct > 85 && class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				$provider . '_high_context_usage',
				sprintf(
					'Request payload estimated at %s%% of context window. Consider reducing prompt size to avoid errors with long conversations.',
					$usage_pct
				),
				array(
					'model'      => $model,
					'provider'   => $provider,
					'usage_pct'  => $usage_pct,
					'estimated'  => $total_estimated,
					'limit'      => $context_limit,
					'tool_count' => $tool_count,
				)
			);
		}

		return null;
	}
}
