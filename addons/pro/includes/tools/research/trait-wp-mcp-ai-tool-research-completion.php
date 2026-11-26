<?php
/**
 * Trait for thinking-model-aware AI research completions.
 *
 * The Pro research tools each perform a single AI completion to synthesize
 * research output. Thinking-mode models (DeepSeek V4/V3.2 and other
 * reasoning models) share the output token budget between the chain of
 * thought and the final answer, so a low `max_tokens` budget can be fully
 * consumed by reasoning and return an empty `content` with
 * `reasoning_content` populated and `finish_reason: length`. This trait
 * centralizes the industry-standard recoveries so every research tool gets
 * them without duplicating the ladder:
 *
 * 1. Provider-aware sampling: DeepSeek reasoning models collapse their
 *    chain of thought at low temperatures (vendor V4 guidance recommends
 *    temperature 1.0 / top_p 1.0).
 * 2. `finish_reason: length` with empty content + reasoning -> retry once
 *    with a multiplied budget (thinking exhausted the budget).
 * 3. Reasoning-only output with a non-length finish -> one continuation
 *    retry asking for the finished output, before falling back to the
 *    reasoning text (flagged with `used_reasoning_fallback`).
 * 4. Truncated non-empty output -> one larger-budget retry.
 * 5. Array content parts are flattened to strings (Gemini and
 *    OpenAI-compatible gateways).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-tool-research-content-normalization.php';

/**
 * Trait WP_MCP_AI_Tool_Research_Completion
 *
 * Shared completion helpers for the Pro research tools.
 *
 * Usage:
 * ```php
 * class WP_MCP_AI_Tool_Research_Place {
 *     use WP_MCP_AI_Tool_Research_Completion;
 *
 *     protected function perform_ai_research( $prompt, $context ) {
 *         // ... resolve provider/model/client and build messages ...
 *         return $this->perform_research_completion(
 *             $client,
 *             $messages,
 *             $provider,
 *             $model,
 *             array(
 *                 'temperature' => 0.2,
 *                 'max_tokens'  => 2000,
 *             )
 *         );
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Research_Completion {

	use WP_MCP_AI_Tool_Research_Content_Normalization;

	/**
	 * Run a research completion with thinking-model recovery.
	 *
	 * Returns the tool's canonical research envelope —
	 * `array( content, provider, model, attempts, ... )` — or a `WP_Error`.
	 * The envelope carries the extra keys `attempts`, `truncated`, and
	 * `used_reasoning_fallback` so callers can surface degraded results.
	 *
	 * @param object $client   AI client instance.
	 * @param array  $messages Chat messages (system + user).
	 * @param string $provider Provider id.
	 * @param string $model    Model identifier.
	 * @param array  $options  Completion options (temperature, max_tokens,
	 *                         response_format, ...). `model` is always set.
	 * @return array|WP_Error Research envelope or error.
	 */
	protected function perform_research_completion( $client, array $messages, $provider, $model, array $options = array() ) {
		$options['model'] = $model;

		// Provider-aware sampling: DeepSeek's V4 reasoning models collapse
		// their chain of thought at low temperatures; the vendor recommends
		// temperature 1.0 / top_p 1.0 for reasoning-mode models.
		if ( 'deepseek' === $provider ) {
			/**
			 * Filter the synthesis temperature for research completions on
			 * DeepSeek reasoning models.
			 *
			 * @since 1.2.0
			 *
			 * @param float  $temperature Default 1.0 (vendor V4 guidance).
			 * @param string $provider    Provider id.
			 * @param string $model       Model identifier.
			 * @param float  $requested   Temperature the tool originally asked for.
			 */
			$options['temperature'] = (float) apply_filters(
				'wp_mcp_ai_research_completion_temperature',
				1.0,
				$provider,
				$model,
				isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.3
			);

			if ( ! isset( $options['top_p'] ) ) {
				$options['top_p'] = 1.0;
			}
		}

		// Resolve the token-budget key once. Some current models reject
		// `max_tokens` in favour of `max_completion_tokens` (DeepSeek V4.1
		// Flash; OpenAI o-series and gpt-5). Clients accept either spelling,
		// but sending the one the model expects avoids a rejected first
		// request, and the larger-budget retry below must scale the key that
		// is actually on the wire.
		$options    = $this->normalize_research_token_budget( $options, $provider, $model );
		$budget_key = isset( $options['max_completion_tokens'] )
			? 'max_completion_tokens'
			: ( isset( $options['max_tokens'] ) ? 'max_tokens' : '' );

		/**
		 * Filter the maximum completion attempts for research tools.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_attempts Default 2.
		 */
		$max_attempts = (int) apply_filters( 'wp_mcp_ai_research_completion_max_attempts', 2 );
		$max_attempts = max( 1, min( $max_attempts, 4 ) );

		/**
		 * Filter the budget multiplier for larger-budget retries.
		 *
		 * @since 1.2.0
		 *
		 * @param int $multiplier Default 4.
		 */
		$multiplier = (int) apply_filters( 'wp_mcp_ai_research_completion_budget_multiplier', 4 );
		$multiplier = max( 2, $multiplier );

		/**
		 * Filter the absolute ceiling for retry token budgets.
		 *
		 * @since 1.2.0
		 *
		 * @param int $ceiling Default 32768.
		 */
		$ceiling = (int) apply_filters( 'wp_mcp_ai_research_completion_max_tokens_ceiling', 32768 );
		$ceiling = max( 1000, $ceiling );

		$last_error         = null;
		$retry_larger       = false;
		$continue_reasoning = false;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			if ( $retry_larger && '' !== $budget_key ) {
				$options[ $budget_key ] = min( (int) $options[ $budget_key ] * $multiplier, $ceiling );
			}
			$retry_larger = false;

			// Continuation instruction for reasoning-only outputs: ask for
			// the finished deliverable instead of more chain-of-thought.
			$call_messages = $messages;
			if ( $continue_reasoning ) {
				$call_messages[]    = array(
					'role'    => 'user',
					'content' => __( 'Produce the finished output NOW as your final response. Do not narrate your reasoning; write only the requested result.', 'mcp-ai-wpoos-pro' ),
				);
				$continue_reasoning = false;
			}

			$result = $client->create_chat_completion( $call_messages, $options );

			if ( is_wp_error( $result ) ) {
				$last_error = $result;
				continue;
			}

			if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
				$last_error = new WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Invalid response from AI provider.', 'mcp-ai-wpoos-pro' )
				);
				continue;
			}

			// Some providers (Gemini) and OpenAI-compatible gateways return
			// message content as an array of parts; flatten to a string so
			// downstream parsers can run string functions on it.
			$raw_content = $result['choices'][0]['message']['content'];
			$content     = is_string( $raw_content ) ? trim( $raw_content ) : trim( $this->normalize_content_parts( $raw_content ) );

			// Clients differ on where finish_reason lives: DeepSeek
			// normalises it to the top level, OpenAI keeps it inside
			// choices[0].
			$finish_reason = '';
			if ( isset( $result['finish_reason'] ) ) {
				$finish_reason = sanitize_key( (string) $result['finish_reason'] );
			} elseif ( isset( $result['choices'][0]['finish_reason'] ) ) {
				$finish_reason = sanitize_key( (string) $result['choices'][0]['finish_reason'] );
			}

			// Reasoning content lives at the top level in the DeepSeek
			// client's normalized response and inside the message for some
			// OpenAI-compatible gateways.
			$reasoning = '';
			if ( ! empty( $result['reasoning_content'] ) && is_string( $result['reasoning_content'] ) ) {
				$reasoning = trim( $result['reasoning_content'] );
			} elseif ( ! empty( $result['choices'][0]['message']['reasoning_content'] ) && is_string( $result['choices'][0]['message']['reasoning_content'] ) ) {
				$reasoning = trim( $result['choices'][0]['message']['reasoning_content'] );
			}

			// Reasoning-only completions: thinking consumed the budget
			// (length) or the model stopped without writing. Recover before
			// falling back to the reasoning text.
			if ( '' === $content && '' !== $reasoning ) {
				if ( 'length' === $finish_reason && ( $attempt + 1 ) < $max_attempts ) {
					$retry_larger = true;
					continue;
				}

				if ( ( $attempt + 1 ) < $max_attempts ) {
					$continue_reasoning = true;
					continue;
				}

				return array(
					'content'                 => $reasoning,
					'provider'                => $provider,
					'model'                   => $model,
					'attempts'                => $attempt + 1,
					'used_reasoning_fallback' => true,
				);
			}

			// Empty completion without reasoning: transient failure.
			if ( '' === $content ) {
				$last_error = new WP_Error(
					'wp_mcp_ai_empty_completion',
					sprintf(
						/* translators: %s: provider name */
						__( 'The AI provider (%s) returned an empty completion.', 'mcp-ai-wpoos-pro' ),
						$provider
					)
				);
				continue;
			}

			// Truncated output: one larger-budget retry so the report can
			// complete before the result is handed to the parser.
			if ( 'length' === $finish_reason && ( $attempt + 1 ) < $max_attempts ) {
				$retry_larger = true;
				continue;
			}

			$envelope = array(
				'content'  => $content,
				'provider' => $provider,
				'model'    => $model,
				'attempts' => $attempt + 1,
			);

			if ( 'length' === $finish_reason ) {
				$envelope['truncated'] = true;
			}

			return $envelope;
		}

		// All attempts exhausted: surface the most specific failure.
		if ( $last_error instanceof WP_Error ) {
			return $last_error;
		}

		return new WP_Error(
			'wp_mcp_ai_invalid_response',
			__( 'Invalid response from AI provider.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Select the token-budget key the provider/model expects.
	 *
	 * DeepSeek-V4.1-Flash rejects `max_tokens` with
	 * "Unsupported parameter: 'max_tokens' … Use 'max_completion_tokens'
	 * instead", and the OpenAI o-series / gpt-5 family follow the same rule.
	 * Tools in this family pass `max_tokens`; this helper renames the key for
	 * the models that require it so the first request is accepted and the
	 * budget-retry ladder scales the value that is actually sent.
	 *
	 * @since 1.2.24
	 *
	 * @param array  $options  Completion options.
	 * @param string $provider Provider id.
	 * @param string $model    Model identifier.
	 * @return array Options with a single canonical budget key.
	 */
	protected function normalize_research_token_budget( array $options, $provider, $model ) {
		if ( ! isset( $options['max_tokens'] ) || ! is_numeric( $options['max_tokens'] ) || isset( $options['max_completion_tokens'] ) ) {
			return $options;
		}

		$model_id = strtolower( sanitize_text_field( (string) $model ) );
		$requires = false;

		if ( 'deepseek' === $provider ) {
			$requires = 0 === strpos( $model_id, 'deepseek-flash' )
				|| 0 === strpos( $model_id, 'deepseek-v4-flash' )
				|| 0 === strpos( $model_id, 'deepseek-v4.1' );
		} elseif ( 'openai' === $provider ) {
			$requires = 0 === strpos( $model_id, 'gpt-5' )
				|| ( class_exists( 'WP_MCP_AI_OpenAI_Client' ) && WP_MCP_AI_OpenAI_Client::is_reasoning_model( $model_id ) );
		}

		/**
		 * Filters whether a research completion must send
		 * `max_completion_tokens` instead of `max_tokens`.
		 *
		 * @since 1.2.24
		 *
		 * @param bool   $requires Default capability for the provider/model.
		 * @param string $provider Provider id.
		 * @param string $model    Model identifier.
		 */
		$requires = (bool) apply_filters( 'wp_mcp_ai_research_completion_requires_max_completion_tokens', $requires, $provider, $model );

		if ( $requires ) {
			$options['max_completion_tokens'] = absint( $options['max_tokens'] );
			unset( $options['max_tokens'] );
		}

		return $options;
	}
}
