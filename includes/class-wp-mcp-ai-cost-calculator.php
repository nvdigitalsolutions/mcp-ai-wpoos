<?php
/**
 * Cost Calculator for AI Token Usage
 *
 * Calculates costs based on provider-specific pricing models.
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
 * Cost Calculator class.
 */
class WP_MCP_AI_Cost_Calculator {

	/**
	 * Provider pricing models (USD per 1M tokens).
	 *
	 * Prices updated as of April 2026.
	 * Source: Official provider pricing pages and Hugging Face Inference API.
	 *
	 * Retired ids (e.g., gpt-4, gpt-4-turbo, gpt-3.5-turbo, o1*, gemini-1.5-*,
	 * gemini-pro, claude-3-opus-20240229) have been removed; the prefix-matching
	 * fallback below covers any stored snapshot ids (e.g., gpt-5-2025-08-07).
	 */
	const PRICING = array(
		'openai'       => array(
			// GPT-5.5 series (April 2026 flagship).
			'gpt-5.5'                      => array(
				'input'        => 5.00,  // $5 per 1M input tokens.
				'output'       => 30.00, // $30 per 1M output tokens.
				'cached_input' => 0.50,  // $0.50 per 1M cached input tokens (90% off).
			),
			// GPT-5.4 series (April 2026).
			'gpt-5.4'                      => array(
				'input'        => 2.50,  // $2.50 per 1M input tokens.
				'output'       => 15.00, // $15 per 1M output tokens.
				'cached_input' => 0.25,  // $0.25 per 1M cached input tokens (90% off).
			),
			'gpt-5.4-mini'                 => array(
				'input'        => 0.75,  // $0.75 per 1M input tokens.
				'output'       => 4.50,  // $4.50 per 1M output tokens.
				'cached_input' => 0.075, // $0.075 per 1M cached input tokens (90% off).
			),
			'gpt-5.4-nano'                 => array(
				'input'        => 0.20,  // $0.20 per 1M input tokens.
				'output'       => 1.25,  // $1.25 per 1M output tokens.
				'cached_input' => 0.02,  // $0.02 per 1M cached input tokens (90% off).
			),
			'gpt-5.4-pro'                  => array(
				'input'        => 30.00,  // $30 per 1M input tokens.
				'output'       => 180.00, // $180 per 1M output tokens.
				'cached_input' => 3.00,   // $3 per 1M cached input tokens (90% off).
			),
			'gpt-5.4-codex'                => array(
				'input'        => 2.50,  // $2.50 per 1M input tokens.
				'output'       => 12.00, // $12 per 1M output tokens.
				'cached_input' => 0.25,  // $0.25 per 1M cached input tokens (90% off).
			),
			// GPT-5.3 Codex (updated April 2026).
			'gpt-5.3-codex'                => array(
				'input'        => 3.00,  // $3 per 1M input tokens.
				'output'       => 15.00, // $15 per 1M output tokens.
				'cached_input' => 0.30,  // $0.30 per 1M cached input tokens (90% off).
			),
			'gpt-5'                        => array(
				'input'  => 1.25,
				'output' => 10.00,
			),
			'gpt-5-mini'                   => array(
				'input'  => 0.25,
				'output' => 2.00,
			),
			'gpt-5-nano'                   => array(
				'input'  => 0.05,
				'output' => 0.40,
			),
			'gpt-4.1'                      => array(
				'input'  => 2.00,
				'output' => 8.00,
			),
			'gpt-4.1-mini'                 => array(
				'input'  => 0.40,
				'output' => 1.60,
			),
			'gpt-4.1-nano'                 => array(
				'input'  => 0.10,
				'output' => 0.40,
			),
			'gpt-4o'                       => array(
				'input'  => 2.50,
				'output' => 10.00,
			),
			'gpt-4o-mini'                  => array(
				'input'  => 0.15,
				'output' => 0.60,
			),
			// o-series reasoning models (December 2025 - updated pricing).
			'o3'                           => array(
				'input'        => 2.00, // $2 per 1M tokens.
				'output'       => 8.00, // $8 per 1M tokens.
				'cached_input' => 0.50, // $0.50 per 1M tokens.
			),
			'o3-pro'                       => array(
				'input'  => 20.00, // $20 per 1M tokens.
				'output' => 80.00, // $80 per 1M tokens.
			),
			'o3-mini'                      => array(
				'input'        => 1.10, // $1.10 per 1M tokens.
				'output'       => 4.40, // $4.40 per 1M tokens.
				'cached_input' => 0.55, // $0.55 per 1M tokens.
			),
			'o4-mini'                      => array(
				'input'        => 1.10,
				'output'       => 4.40,
				'cached_input' => 0.275,
			),
			// GPT-4o Realtime models (audio/speech).
			// December 2024 update: 60% cheaper pricing, WebRTC support.
			'gpt-4o-realtime-preview'      => array(
				'input'        => 100.00, // Audio input: $100 per 1M tokens.
				'output'       => 200.00, // Audio output: $200 per 1M tokens.
				'cached_input' => 20.00,  // Cached audio input: $20 per 1M tokens.
			),
			'gpt-4o-mini-realtime-preview' => array(
				'input'        => 10.00, // Audio input: ~$10 per 1M tokens (10x cheaper).
				'output'       => 20.00, // Audio output: ~$20 per 1M tokens.
				'cached_input' => 2.00,  // Cached audio input: ~$2 per 1M tokens.
			),
			'gpt-4o-audio-preview'         => array(
				'input'        => 100.00, // Audio input: $100 per 1M tokens.
				'output'       => 200.00, // Audio output: $200 per 1M tokens.
				'cached_input' => 20.00,  // Cached audio input: $20 per 1M tokens.
			),
			// GPT Realtime Mini (December 2025 - new naming convention).
			'gpt-realtime-mini'            => array(
				'input'        => 10.00, // Audio input: $10 per 1M tokens.
				'output'       => 20.00, // Audio output: $20 per 1M tokens.
				'cached_input' => 0.30,  // Cached audio input: $0.30 per 1M tokens.
			),
			// Sora video generation models.
			// Pricing is per second of generated video.
			'sora-2'                       => array(
				'per_second' => 0.10, // $0.10 per second of generated video (estimated).
			),
			'sora-2-pro'                   => array(
				'per_second' => 0.20, // $0.20 per second of generated video (estimated).
			),
		),
		'gemini'       => array(
			// Gemini 3.5 series (May 2026).
			'gemini-3.5-flash'              => array(
				'input'        => 1.50,   // $1.50 per 1M.
				'output'       => 9.00,   // $9.00 per 1M.
				'cached_input' => 0.15,   // $0.15 per 1M cached input (90% off).
			),
			// Gemini 3.1 series (May 2026).
			'gemini-3.1-pro'                => array(
				'input'  => 2.00,   // $2.00 per 1M (<=200K ctx).
				'output' => 12.00,  // $12.00 per 1M (<=200K ctx).
			),
			'gemini-3.1-flash-lite'         => array(
				'input'  => 0.25,   // $0.25 per 1M.
				'output' => 1.50,   // $1.50 per 1M.
			),
			// Gemini 3 Flash Preview.
			'gemini-3-flash-preview'        => array(
				'input'  => 0.50,   // $0.50 per 1M.
				'output' => 3.00,   // $3.00 per 1M.
			),
			'gemini-3.1-flash-live-preview' => array(
				'input'  => 0.75,   // $0.75 per 1M (text).
				'output' => 4.50,   // $4.50 per 1M (text).
			),
			// Gemini 2.5 series (still supported, May 2026 pricing).
			'gemini-2.5-pro'                => array(
				'input'  => 1.25,   // $1.25 per 1M (<=200K ctx).
				'output' => 10.00,  // $10.00 per 1M (<=200K ctx).
			),
			'gemini-2.5-flash'              => array(
				'input'  => 0.30,   // $0.30 per 1M.
				'output' => 2.50,   // $2.50 per 1M.
			),
			'gemini-2.5-flash-lite'         => array(
				'input'  => 0.10,   // $0.10 per 1M.
				'output' => 0.40,   // $0.40 per 1M.
			),
			'gemini-2.5-flash-image'        => array(
				'input'  => 0.30,   // $0.30 per 1M (text).
				'output' => 30.00,  // $30 per 1M (images, ~$0.039/image at 1024x1024).
			),
			// [DEPRECATED] Legacy entries.
			'gemini-3.1-flash'              => array(
				'input'  => 0.50,   // $0.50 per 1M (use gemini-3-flash-preview).
				'output' => 3.00,   // $3.00 per 1M.
			),
			// Veo 3.1 video generation ($0.40/sec standard).
			'veo-3.1-generate-001'          => array(
				'per_second' => 0.025,
			),
			'veo-2.0-generate-001'          => array(
				'per_second' => 0.020,
			),
		),
		'anthropic'    => array(
			// Claude 4.7 series (May 2026 flagship).
			'claude-opus-4-7'            => array(
				'input'  => 15.00,  // $15 per 1M input tokens.
				'output' => 75.00,  // $75 per 1M output tokens.
			),
			// Claude 4.6 series.
			'claude-sonnet-4-6'          => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-opus-4-6'            => array(
				'input'  => 15.00,  // $15 per 1M input tokens.
				'output' => 75.00,  // $75 per 1M output tokens.
			),
			// Claude 4.5 series (deprecated alias retained for backward compatibility).
			'claude-sonnet-4-5'          => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-sonnet-4-5-20250929' => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-opus-4-5'            => array(
				'input'  => 15.00,
				'output' => 75.00,
			),
			'claude-haiku-4-5'           => array(
				'input'  => 1.00,
				'output' => 5.00,
			),
			'claude-haiku-4-5-20251001'  => array(
				'input'  => 1.00,
				'output' => 5.00,
			),
			// Claude 3.5 series (deprecated; sunset 2026-09-30).
			'claude-3-5-sonnet-20241022' => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-3-5-haiku-20241022'  => array(
				'input'  => 0.80,
				'output' => 4.00,
			),
		),
		'ollama'       => array(
			'default' => array(
				'input'  => 0.00,
				'output' => 0.00,
			),
		),
		'lm_studio'    => array(
			'default' => array(
				'input'  => 0.00,
				'output' => 0.00,
			),
		),
		'deepseek'     => array(
			// DeepSeek-V4-Flash — current flagship (cache-miss pricing).
			// Standard: $0.14/$0.28 per 1M tokens. Cache hit: $0.0028.
			'deepseek-v4-flash'     => array(
				'input'  => 0.14,   // $0.14 per 1M input tokens (cache miss).
				'output' => 0.28,   // $0.28 per 1M output tokens.
			),
			// DeepSeek-V4-Pro — reasoning/agentic (75% promo through 2026-05-31).
			// Promo: $0.435/$0.87 per 1M tokens. Regular: $1.74/$3.48.
			'deepseek-v4-pro'       => array(
				'input'  => 0.435,  // $0.435 per 1M input tokens (promo cache miss).
				'output' => 0.87,   // $0.87 per 1M output tokens (promo).
			),
			// [DEPRECATED] Legacy DeepSeek-V3 general-purpose + tool-calling model.
			'deepseek-chat'         => array(
				'input'  => 0.27,   // $0.27 per 1M input tokens (legacy standard).
				'output' => 1.10,   // $1.10 per 1M output tokens (legacy standard).
			),
			// [DEPRECATED] Legacy DeepSeek-R1 chain-of-thought reasoning model.
				'deepseek-reasoner' => array(
					'input'  => 0.55,   // $0.55 per 1M input tokens.
					'output' => 2.19,   // $2.19 per 1M output tokens.
				),
			// [DEPRECATED] Legacy DeepSeek Coder variant.
			'deepseek-coder'        => array(
				'input'  => 0.27,
				'output' => 1.10,
			),
		),
		'huggingface'  => array(
			// DeepSeek V3.2 (January 2026).
			'deepseek-ai/DeepSeek-V3.2'          => array(
				'input'  => 0.28, // $0.28 per 1M tokens (cache miss).
				'output' => 0.42, // $0.42 per 1M tokens.
			),
			// Llama 3.3 70B Instruct.
			'meta-llama/Llama-3.3-70B-Instruct'  => array(
				'input'  => 1.00, // $1.00 per 1M tokens ($0.001 per 1K).
				'output' => 1.00,
			),
			// Llama 3.1 8B Instruct.
			'meta-llama/Llama-3.1-8B-Instruct'   => array(
				'input'  => 0.30, // $0.30 per 1M tokens ($0.0003 per 1K).
				'output' => 0.30,
			),
			// Mistral 7B Instruct v0.3.
			'mistralai/Mistral-7B-Instruct-v0.3' => array(
				'input'  => 0.20, // $0.20 per 1M tokens ($0.0002 per 1K).
				'output' => 0.20,
			),
			// Phi-3 Mini 4K Instruct.
			'microsoft/Phi-3-mini-4k-instruct'   => array(
				'input'  => 0.10, // $0.10 per 1M tokens ($0.0001 per 1K).
				'output' => 0.10,
			),
			// Qwen 2.5 72B Instruct.
			'Qwen/Qwen2.5-72B-Instruct'          => array(
				'input'  => 1.00, // $1.00 per 1M tokens ($0.001 per 1K).
				'output' => 1.00,
			),
			// Qwen 2.5 7B Instruct.
			'Qwen/Qwen2.5-7B-Instruct'           => array(
				'input'  => 0.20, // $0.20 per 1M tokens ($0.0002 per 1K).
				'output' => 0.20,
			),
			// Default fallback for unknown Hugging Face models.
			// Uses average pricing for estimation when specific model is not listed.
			'default'                            => array(
				'input'  => 0.50, // $0.50 per 1M tokens (estimated average).
				'output' => 0.50, // $0.50 per 1M tokens (estimated average).
			),
		),
		'openrouter'   => array(
			// OpenRouter passes through provider pricing; use OpenRouter's
			// own pricing when known, otherwise fall back to estimated average.
			// Prices as of June 2026.
			'default' => array(
				'input'  => 1.00, // $1.00 per 1M tokens (blended average).
				'output' => 3.00, // $3.00 per 1M tokens (blended average).
			),
		),
		'nvidia'       => array(
			// NVIDIA NIM pricing varies by model. Default to estimated average.
			'default' => array(
				'input'  => 1.00, // $1.00 per 1M tokens (estimated).
				'output' => 3.00, // $3.00 per 1M tokens (estimated).
			),
		),
		'cloudflare'   => array(
			// Cloudflare Workers AI — free during beta, token-based pricing
			// after GA. Conservative estimates as of June 2026.
			// Model-specific rates from Workers AI docs.
			'@cf/meta/llama-3.2-1b-instruct' => array(
				'input'  => 0.027,  // $0.027 per 1M input tokens.
				'output' => 0.201,  // $0.201 per 1M output tokens.
			),
			'@cf/meta/llama-3.1-8b-instruct' => array(
				'input'  => 0.10,  // $0.10 per 1M tokens.
				'output' => 0.10,  // $0.10 per 1M tokens.
			),
			'default'                        => array(
				'input'  => 0.10,  // $0.10 per 1M tokens (estimated average).
				'output' => 0.10,  // $0.10 per 1M tokens (estimated average).
			),
		),
		'digitalocean' => array(
			// DigitalOcean GenAI Platform pricing (June 2026).
			'default' => array(
				'input'  => 1.00, // $1.00 per 1M tokens (estimated).
				'output' => 3.00, // $3.00 per 1M tokens (estimated).
			),
		),
		'baseten'      => array(
			// Baseten model-dependent pricing. Default to conservative estimate.
			'default' => array(
				'input'  => 1.00, // $1.00 per 1M tokens (estimated).
				'output' => 3.00, // $3.00 per 1M tokens (estimated).
			),
		),
		'nv_hosted'    => array(
			// NV oOS Cloud hosted models — wholesale cost plus 7 % service fee.
			// The billing observer derives the exact cost per request; this
			// fallback enables dashboard aggregation before the observer fires.
			'default' => array(
				'input'  => 0.50, // $0.50 per 1M tokens (wholesale estimate).
				'output' => 2.00, // $2.00 per 1M tokens (wholesale estimate).
			),
		),
	);

	/**
	 * Calculate cost for a specific usage record.
	 *
	 * @param string $provider      Provider name (e.g., 'openai', 'gemini').
	 * @param string $model         Model name (e.g., 'gpt-4o', 'gemini-1.5-pro').
	 * @param int    $input_tokens  Input token count.
	 * @param int    $output_tokens Output token count.
	 * @return float Cost in USD.
	 */
	public static function calculate_cost( $provider, $model, $input_tokens, $output_tokens ) {
		$pricing = self::get_model_pricing( $provider, $model );

		if ( ! $pricing ) {
			return 0.0;
		}

		$input_cost  = ( $input_tokens / 1000000 ) * $pricing['input'];
		$output_cost = ( $output_tokens / 1000000 ) * $pricing['output'];

		return $input_cost + $output_cost;
	}

	/**
	 * Get pricing for a specific model.
	 *
	 * @param string $provider Provider name.
	 * @param string $model    Model name.
	 * @return array|null Pricing array with 'input' and 'output' keys, or null if not found.
	 */
	public static function get_model_pricing( $provider, $model ) {
		$provider = sanitize_key( $provider );
		$model    = sanitize_text_field( $model );

		// Normalize model name (remove version suffixes for matching).
		$model_normalized = self::normalize_model_name( $model );

		// Check if provider exists in pricing.
		if ( ! isset( self::PRICING[ $provider ] ) ) {
			return null;
		}

		$provider_pricing = self::PRICING[ $provider ];

		// Try exact match first.
		if ( isset( $provider_pricing[ $model ] ) ) {
			return $provider_pricing[ $model ];
		}

		// Try normalized model name.
		if ( isset( $provider_pricing[ $model_normalized ] ) ) {
			return $provider_pricing[ $model_normalized ];
		}

		// For ollama, lm_studio, huggingface, openrouter, nvidia, cloudflare,
		// digitalocean, baseten, and nv_hosted, return default pricing if available.
		if ( in_array( $provider, array( 'ollama', 'lm_studio', 'huggingface', 'openrouter', 'nvidia', 'cloudflare', 'digitalocean', 'baseten', 'nv_hosted' ), true ) ) {
			if ( isset( $provider_pricing['default'] ) ) {
				return $provider_pricing['default'];
			}
		}

		// Try to find the longest matching prefix (e.g., 'gpt-5-2025-08-07' should match 'gpt-5').
		// This ensures we get the most specific match when multiple models share a prefix.
		$best_match        = null;
		$best_match_length = 0;

		foreach ( $provider_pricing as $known_model => $pricing ) {
			if ( 0 === strpos( $model, $known_model ) ) {
				$match_length = strlen( $known_model );

				// Keep the longest matching prefix.
				if ( $match_length > $best_match_length ) {
					$best_match        = $pricing;
					$best_match_length = $match_length;
				}
			}
		}

		if ( $best_match ) {
			return $best_match;
		}

		// No pricing found.
		return null;
	}

	/**
	 * Normalize model name for matching.
	 *
	 * Removes version suffixes and dates from model names.
	 *
	 * @param string $model Model name.
	 * @return string Normalized model name.
	 */
	private static function normalize_model_name( $model ) {
		// Remove common version suffixes.
		$model = preg_replace( '/-\d{4}(-\d{2})?(-\d{2})?$/', '', $model );
		$model = preg_replace( '/-preview$/', '', $model );
		$model = preg_replace( '/-turbo-preview$/', '-turbo', $model );

		return $model;
	}

	/**
	 * Calculate cost breakdown from usage data.
	 *
	 * Pure calculation function - does not access database or other services.
	 *
	 * @param array  $usage_data Usage data structure (from token tracking).
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return array Cost breakdown with totals by provider, model, and tool.
	 */
	public static function calculate_cost_breakdown( $usage_data, $start_date, $end_date ) {
		$breakdown = array(
			'total_cost'  => 0.0,
			'by_provider' => array(),
			'by_model'    => array(),
			'by_tool'     => array(),
			'by_date'     => array(),
		);

		// Parse date range.
		$start_timestamp = strtotime( $start_date );
		$end_timestamp   = strtotime( $end_date );

		if ( ! $start_timestamp || ! $end_timestamp || ! is_array( $usage_data ) ) {
			return $breakdown;
		}

		// Process each tool's usage.
		foreach ( $usage_data as $tool_slug => $tool_data ) {
			if ( ! isset( $tool_data['daily'] ) || ! is_array( $tool_data['daily'] ) ) {
				continue;
			}

			// Process daily usage within date range.
			foreach ( $tool_data['daily'] as $date_key => $tokens ) {
				$date_timestamp = strtotime( $date_key );

				if ( $date_timestamp < $start_timestamp || $date_timestamp > $end_timestamp ) {
					continue;
				}

				// Estimate cost based on tokens (we need provider/model info for accurate costs).
				// For now, use a default estimation. This will be enhanced when we track provider/model.
				$cost = self::estimate_cost_from_tokens( $tokens );

				$breakdown['total_cost'] += $cost;

				// Aggregate by date.
				if ( ! isset( $breakdown['by_date'][ $date_key ] ) ) {
					$breakdown['by_date'][ $date_key ] = 0.0;
				}
				$breakdown['by_date'][ $date_key ] += $cost;

				// Aggregate by tool.
				if ( ! isset( $breakdown['by_tool'][ $tool_slug ] ) ) {
					$breakdown['by_tool'][ $tool_slug ] = 0.0;
				}
				$breakdown['by_tool'][ $tool_slug ] += $cost;
			}
		}

		return $breakdown;
	}

	/**
	 * Estimate cost from total tokens (when provider/model is unknown).
	 *
	 * Uses an average cost based on common models.
	 *
	 * @param int $tokens Total token count.
	 * @return float Estimated cost in USD.
	 */
	private static function estimate_cost_from_tokens( $tokens ) {
		// April 2026: blended baseline anchored to GPT-5.4-mini (input $0.75 + output $4.50,
		// weighted toward input-heavy workloads). Yields a conservative ~$0.50 per 1M tokens.
		$avg_cost_per_million = 0.50;

		return ( $tokens / 1000000 ) * $avg_cost_per_million;
	}

	/**
	 * Get all providers with their models.
	 *
	 * @return array Provider => models array.
	 */
	public static function get_all_providers() {
		return self::PRICING;
	}

	/**
	 * Get models for a specific provider.
	 *
	 * @param string $provider Provider name.
	 * @return array Model names, or empty array if provider not found.
	 */
	public static function get_provider_models( $provider ) {
		$provider = sanitize_key( $provider );

		if ( ! isset( self::PRICING[ $provider ] ) ) {
			return array();
		}

		return array_keys( self::PRICING[ $provider ] );
	}

	/**
	 * Calculate ROI from cost and productivity metrics.
	 *
	 * Pure calculation function - does not access database or other services.
	 *
	 * @param float $total_cost Total cost in USD.
	 * @param array $metrics    Productivity metrics (time_saved_hours, tasks_automated, hourly_rate).
	 * @return array ROI data.
	 */
	public static function calculate_roi( $total_cost, $metrics ) {
		$roi = array(
			'total_cost'      => floatval( $total_cost ),
			'time_saved'      => isset( $metrics['time_saved_hours'] ) ? floatval( $metrics['time_saved_hours'] ) : 0,
			'tasks_automated' => isset( $metrics['tasks_automated'] ) ? intval( $metrics['tasks_automated'] ) : 0,
			'cost_per_task'   => 0.0,
			'hourly_rate'     => isset( $metrics['hourly_rate'] ) ? floatval( $metrics['hourly_rate'] ) : 50.0,
			'value_generated' => 0.0,
			'roi_percentage'  => 0.0,
		);

		if ( $roi['tasks_automated'] > 0 ) {
			$roi['cost_per_task'] = $roi['total_cost'] / $roi['tasks_automated'];
		}

		$roi['value_generated'] = $roi['time_saved'] * $roi['hourly_rate'];

		if ( $roi['total_cost'] > 0 ) {
			$roi['roi_percentage'] = ( ( $roi['value_generated'] - $roi['total_cost'] ) / $roi['total_cost'] ) * 100;
		}

		return $roi;
	}

	/**
	 * Format cost for display.
	 *
	 * @param float $cost Cost in USD.
	 * @return string Formatted cost string (e.g., "$1.23").
	 */
	public static function format_cost( $cost ) {
		return '$' . number_format( $cost, 4 );
	}
}
