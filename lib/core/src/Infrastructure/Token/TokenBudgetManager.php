<?php
/**
 * Token budget manager — pre-flight context window validation.
 *
 * Provides model context-window limits, token estimation (chars/4
 * heuristic), and a shared validateContextWindow() pre-flight check
 * that every provider client calls before sending a chat request.
 *
 * This is the framework-agnostic equivalent of the WordPress-layer
 * WP_MCP_AI_Token_Budget_Manager in includes/services/.
 *
 * @package Nvoos\Core
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Token;

class TokenBudgetManager {

	/**
	 * Characters-per-token ratio for the heuristic estimator.
	 *
	 * GPT-family English text averages ~4 chars per BPE token.
	 * Code and non-English languages vary; this is a reasonable
	 * approximation for budget-guard decisions.
	 */
	private const CHARS_PER_TOKEN = 4;

	/**
	 * Per-message overhead tokens (role encoding + formatting).
	 */
	private const MESSAGE_OVERHEAD = 4;

	/**
	 * Default safety margin applied to context limits (10 %).
	 */
	public const DEFAULT_SAFETY_MARGIN = 0.10;

	/**
	 * Default output token budget when none is specified.
	 */
	private const DEFAULT_OUTPUT_BUDGET = 4096;

	/**
	 * Soft-warning threshold — log when estimated usage exceeds
	 * this percentage of the context window.
	 */
	private const WARN_THRESHOLD = 0.85;

	/**
	 * Model context window limits (max input + output tokens).
	 *
	 * Mirrors the canonical model-catalog.json and the legacy
	 * WP_MCP_AI_Token_Budget_Manager::$model_limits table.
	 *
	 * Keyed by model slug; snapshot as of June 2026.
	 *
	 * @var array<string, int>
	 */
	public const MODEL_LIMITS = array(
		// OpenAI GPT-5 family.
		'gpt-5.5'         => 1050000,
		'gpt-5.5-mini'    => 270000,
		'gpt-5.4'         => 1050000,
		'gpt-5.4-mini'    => 270000,
		'gpt-5.4-nano'    => 128000,
		'gpt-5.3'         => 922000,
		'gpt-5.2'         => 270000,
		'gpt-5.1'         => 128000,
		'gpt-5'           => 128000,
		'gpt-5-mini'      => 128000,
		'gpt-5-nano'      => 128000,
		// OpenAI reasoning models.
		'o4'              => 200000,
		'o4-mini'         => 200000,
		'o3'              => 200000,
		'o3-mini'         => 128000,
		'o1-2024-12-17'   => 200000,
		'o1-preview'      => 128000,
		'o1-mini'         => 128000,
		// OpenAI legacy.
		'gpt-4.1'         => 1000000,
		'gpt-4.1-mini'    => 1000000,
		'gpt-4.1-nano'    => 1000000,
		'gpt-4o'          => 128000,
		'gpt-4o-mini'     => 128000,
		'gpt-4-turbo'     => 128000,
		'gpt-4'           => 8192,
		'gpt-3.5-turbo'   => 16385,
		// Anthropic Claude.
		'claude-mythos-preview' => 1000000,
		'claude-opus-4-8'       => 1000000,
		'claude-opus-4-7'       => 1000000,
		'claude-opus-4-6'       => 1000000,
		'claude-sonnet-4-6'     => 1000000,
		'claude-opus-4-5'       => 200000,
		'claude-sonnet-4-5'     => 200000,
		'claude-haiku-4-5'      => 200000,
		'claude-3-5-sonnet'     => 200000,
		'claude-3-opus'         => 200000,
		'claude-3-haiku'        => 200000,
		// Google Gemini.
		'gemini-3.5-flash'      => 1048576,
		'gemini-3.1-pro'        => 2000000,
		'gemini-3.1-pro-preview' => 1000000,
		'gemini-3.1-flash'      => 1000000,
		'gemini-3.1-flash-lite' => 1000000,
		'gemini-3-pro-preview'  => 1000000,
		'gemini-3-flash-preview' => 1000000,
		'gemini-2.5-pro'        => 1048576,
		'gemini-2.5-flash'      => 1048576,
		'gemini-2.5-flash-image' => 1048576,
		'gemini-2.0-flash'      => 1048576,
		'gemini-2.0-flash-image' => 1048576,
		'gemini-1.5-pro'        => 2097152,
		'gemini-1.5-flash'      => 1048576,
		// DeepSeek.
		'deepseek-v4-flash'  => 1048576,
		'deepseek-v4-pro'    => 1048576,
		'deepseek-chat'      => 65536,
		'deepseek-reasoner'  => 65536,
		'deepseek-v3'        => 65536,
		'deepseek-coder'     => 16384,
		'deepseek-r1-0528-qwen3-8b' => 32768,
		// Kimi / Moonshot AI.
		'kimi-k2.6'          => 262144,
		'kimi-k2.5'          => 262144,
		'kimi-k2'            => 262144,
		'kimi-k2-thinking'   => 262144,
		// Meta Llama.
		'llama4'             => 131072,
		'llama3.3'           => 131072,
		'llama3.2'           => 131072,
		'llama3.1'           => 131072,
		'llama3'             => 8192,
		// Mistral AI.
		'mixtral'            => 32768,
		'mistral-large'      => 131072,
		'mistral-small'      => 32768,
		'mistral'            => 8192,
		// Qwen (Alibaba).
		'qwen3.5'            => 131072,
		'qwen3'              => 131072,
		'qwen2.5'            => 131072,
		'qwen2'              => 32768,
		// NVIDIA.
		'nemotron-3'         => 1048576,
		// Other open models.
		'codellama'          => 16384,
		'phi4'               => 16384,
		'phi3'               => 4096,
		'gemma4'             => 262144,
		'gemma3'             => 32768,
		'gemma2'             => 8192,
	);

	/**
	 * Maximum output tokens per model.
	 *
	 * When the caller does not specify max_tokens / max_completion_tokens,
	 * we reserve this much for the response. Mirrors the legacy
	 * WP_MCP_AI_Token_Budget_Manager::$model_max_output_tokens.
	 *
	 * @var array<string, int>
	 */
	public const MAX_OUTPUT_TOKENS = array(
		'claude-mythos-preview' => 128000,
		'claude-opus-4-6'       => 128000,
		'claude-opus-4-5'       => 128000,
		'claude-sonnet-4-6'     => 64000,
		'claude-sonnet-4-5'     => 64000,
		'claude-haiku-4-5'      => 64000,
		'claude-3-5-sonnet'     => 8192,
		'claude-3-opus'         => 4096,
		'claude-3-haiku'        => 4096,
	);

	// ─── Public API ────────────────────────────────────────────────────

	/**
	 * Get the context window limit (max tokens) for a model.
	 *
	 * Looks up the hardcoded MODEL_LIMITS table first, then falls back
	 * to prefix matching, and finally returns 0 when unknown.
	 *
	 * @param string $model  Model identifier.
	 * @return int  Context window limit, or 0 if unknown.
	 */
	public function getModelLimit( string $model ): int {
		$model = \strtolower( \trim( $model ) );

		if ( '' === $model ) {
			return 0;
		}

		if ( isset( self::MODEL_LIMITS[ $model ] ) ) {
			return self::MODEL_LIMITS[ $model ];
		}

		// Prefix match — pick the longest-matching prefix.
		$bestLimit  = 0;
		$bestLength = 0;

		foreach ( self::MODEL_LIMITS as $key => $limit ) {
			$len = \strlen( $key );
			if ( $len > $bestLength && 0 === \strpos( $model, $key ) ) {
				$bestLimit  = $limit;
				$bestLength = $len;
			}
		}

		return $bestLimit;
	}

	/**
	 * Estimate tokens for a string using the chars/4 heuristic.
	 *
	 * When the `rahul900day/tiktoken-php` library is available, platforms
	 * should inject a more accurate estimator.  This method is the
	 * framework-agnostic fallback.
	 */
	public function estimateTokens( string $text, ?string $model = null ): int {
		if ( '' === $text ) {
			return 0;
		}

		$charCount = \mb_strlen( $text, 'UTF-8' );

		return (int) \ceil( $charCount / self::CHARS_PER_TOKEN );
	}

	/**
	 * Estimate tokens for an array of messages.
	 *
	 * Includes per-message overhead (4 tokens/message) plus content.
	 */
	public function estimateMessageTokens( array $messages ): int {
		$total = 3; // Assistant reply priming.

		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) ) {
				continue;
			}

			$total += self::MESSAGE_OVERHEAD;

			$role = $msg['role'] ?? '';
			if ( is_string( $role ) && '' !== $role ) {
				$total += $this->estimateTokens( $role );
			}

			$content = $msg['content'] ?? '';
			if ( is_string( $content ) && '' !== $content ) {
				$total += $this->estimateTokens( $content );
			} elseif ( is_array( $content ) ) {
				foreach ( $content as $part ) {
					if ( is_string( $part ) ) {
						$total += $this->estimateTokens( $part );
					} elseif ( is_array( $part ) && isset( $part['text'] ) ) {
						$total += $this->estimateTokens( (string) $part['text'] );
					}
				}
			}

			if ( ! empty( $msg['tool_calls'] ) && is_array( $msg['tool_calls'] ) ) {
				foreach ( $msg['tool_calls'] as $tc ) {
					$fn = $tc['function'] ?? array();
					$total += $this->estimateTokens( (string) ( $fn['name'] ?? '' ) );
					$total += $this->estimateTokens( (string) ( $fn['arguments'] ?? '' ) );
				}
			}

			if ( isset( $msg['tool_call_id'] ) ) {
				$total += $this->estimateTokens( (string) $msg['tool_call_id'] );
			}
		}

		return $total;
	}

	/**
	 * Get the maximum output tokens for a model.
	 */
	public function getMaxOutputTokens( string $model ): int {
		$model = \strtolower( \trim( $model ) );

		if ( isset( self::MAX_OUTPUT_TOKENS[ $model ] ) ) {
			return self::MAX_OUTPUT_TOKENS[ $model ];
		}

		return self::DEFAULT_OUTPUT_BUDGET;
	}

	/**
	 * Validate that a payload fits within the model's context window.
	 *
	 * Called by every provider client before create_chat_completion().
	 *
	 * @param array  $payload   The full request payload.
	 * @param string $model     Model identifier.
	 * @param string $provider  Provider slug (for error context).
	 * @return array|null  Error shape when context window is exceeded; null when OK.
	 */
	public function validateContextWindow(
		array $payload,
		string $model,
		string $provider,
	): ?array {
		$contextLimit = $this->getModelLimit( $model );

		if ( $contextLimit <= 0 ) {
			return null;
		}

		try {
			$payloadJson = \json_encode( $payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return array(
				'code'    => 'payload_serialization_failed',
				'message' => 'Failed to serialise payload for context-window check.',
				'data'    => array( 'error' => $e->getMessage() ),
			);
		}

		$estimatedTokens = $this->estimateTokens( $payloadJson, $model );

		$outputBudget = self::DEFAULT_OUTPUT_BUDGET;
		if ( isset( $payload['max_tokens'] ) ) {
			$outputBudget = (int) $payload['max_tokens'];
		} elseif ( isset( $payload['max_completion_tokens'] ) ) {
			$outputBudget = (int) $payload['max_completion_tokens'];
		} elseif ( isset( $payload['max_output_tokens'] ) ) {
			$outputBudget = (int) $payload['max_output_tokens'];
		}

		$totalEstimated = $estimatedTokens + $outputBudget;
		$usagePct       = \round( ( $totalEstimated / $contextLimit ) * 100, 1 );

		if ( $totalEstimated > $contextLimit ) {
			return array(
				'code'    => 'context_window_exceeded',
				'message' => \sprintf(
					'Estimated request size (%d tokens) exceeds the %s model context window (%d tokens).',
					$totalEstimated,
					$model,
					$contextLimit,
				),
				'data'    => array(
					'estimated_tokens' => $totalEstimated,
					'context_limit'    => $contextLimit,
					'model'            => $model,
					'provider'         => $provider,
					'actions'          => array(
						'reduce_tools'          => 'Deselect tools on the assistant configuration.',
						'shorten_system_prompt' => 'Shorten the system prompt.',
						'limit_history'         => 'Start a new conversation or enable semantic compression.',
						'upgrade_model'         => 'Switch to a model with a larger context window.',
					),
				),
			);
		}

		if ( $usagePct > ( self::WARN_THRESHOLD * 100 ) ) {
			return array(
				'code'    => 'context_window_high_usage',
				'message' => \sprintf(
					'Estimated usage %s%% of the %s context window (%d / %d tokens).',
					$usagePct,
					$model,
					$totalEstimated,
					$contextLimit,
				),
				'data'    => array(
					'estimated_tokens' => $totalEstimated,
					'context_limit'    => $contextLimit,
					'usage_pct'        => $usagePct,
					'model'            => $model,
					'provider'         => $provider,
					'warning'          => true,
				),
			);
		}

		return null;
	}

	/**
	 * Calculate token budget for a conversation.
	 *
	 * @return array{available: int, used: int, reserved: int, limit: int, model: string}
	 */
	public function calculateBudget(
		string $model,
		array $messages,
		int $maxOutputTokens = 0,
		?float $safetyMargin = null,
	): array {
		$safetyMargin ??= self::DEFAULT_SAFETY_MARGIN;
		$limit          = $this->getModelLimit( $model );

		if ( $limit <= 0 ) {
			$limit = 128000;
		}

		$reserved = $maxOutputTokens > 0
			? $maxOutputTokens
			: $this->getMaxOutputTokens( $model );

		$safeLimit = (int) \round( $limit * ( 1.0 - $safetyMargin ) );
		$used      = $this->estimateMessageTokens( $messages );

		return array(
			'available' => \max( 0, $safeLimit - $used - $reserved ),
			'used'      => $used,
			'reserved'  => $reserved,
			'limit'     => $limit,
			'model'     => $model,
		);
	}
}
