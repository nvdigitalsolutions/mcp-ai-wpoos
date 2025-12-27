<?php
/**
 * Cost Calculator for AI Token Usage
 *
 * Calculates costs based on provider-specific pricing models.
 *
 * @package WP_MCP_AI
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
	 * Prices updated as of November 2025.
	 * Source: Official provider pricing pages.
	 */
	const PRICING = array(
		'openai'    => array(
			'gpt-5'         => array(
				'input'  => 10.00,
				'output' => 30.00,
			),
			'gpt-5-mini'    => array(
				'input'  => 2.00,
				'output' => 6.00,
			),
			'gpt-4.1'       => array(
				'input'  => 1.00,
				'output' => 4.00,
			),
			'gpt-4.1-mini'  => array(
				'input'  => 0.40,
				'output' => 1.60,
			),
			'gpt-4.1-nano'  => array(
				'input'  => 0.20,
				'output' => 0.80,
			),
			'gpt-4o'        => array(
				'input'  => 2.50,
				'output' => 10.00,
			),
			'gpt-4o-mini'   => array(
				'input'  => 0.15,
				'output' => 0.60,
			),
			'gpt-4-turbo'   => array(
				'input'  => 10.00,
				'output' => 30.00,
			),
			'gpt-4'         => array(
				'input'  => 30.00,
				'output' => 60.00,
			),
			'gpt-3.5-turbo' => array(
				'input'  => 0.50,
				'output' => 1.50,
			),
			// o-series reasoning models (December 2025 - updated pricing).
			'o3'            => array(
				'input'        => 2.00, // $2 per 1M tokens.
				'output'       => 8.00, // $8 per 1M tokens.
				'cached_input' => 0.50, // $0.50 per 1M tokens.
			),
			'o3-pro'        => array(
				'input'  => 20.00, // $20 per 1M tokens.
				'output' => 80.00, // $80 per 1M tokens.
			),
			'o3-mini'       => array(
				'input'        => 1.10, // $1.10 per 1M tokens.
				'output'       => 4.40, // $4.40 per 1M tokens.
				'cached_input' => 0.55, // $0.55 per 1M tokens.
			),
			'o4-mini'       => array(
				'input'  => 2.00,
				'output' => 8.00,
			),
			// o1 series (legacy reasoning models, still active).
			'o1'            => array(
				'input'        => 15.00, // $15 per 1M tokens.
				'output'       => 60.00, // $60 per 1M tokens.
				'cached_input' => 7.50,  // $7.50 per 1M tokens.
			),
			'o1-pro'        => array(
				'input'  => 150.00, // $150 per 1M tokens.
				'output' => 600.00, // $600 per 1M tokens.
			),
			'o1-2024-12-17' => array(
				'input'        => 15.00,
				'output'       => 60.00,
				'cached_input' => 7.50,
			),
			'o1-preview'    => array(
				'input'        => 15.00,
				'output'       => 60.00,
				'cached_input' => 7.50,
			),
			'o1-mini'       => array(
				'input'        => 1.10, // $1.10 per 1M tokens (updated from $3).
				'output'       => 4.40, // $4.40 per 1M tokens (updated from $12).
				'cached_input' => 0.55, // $0.55 per 1M tokens.
			),
			// GPT-4o Realtime models (audio/speech).
			// December 2024 update: 60% cheaper pricing, WebRTC support.
			'gpt-4o-realtime-preview'                 => array(
				'input'        => 100.00, // Audio input: $100 per 1M tokens.
				'output'       => 200.00, // Audio output: $200 per 1M tokens.
				'cached_input' => 20.00,  // Cached audio input: $20 per 1M tokens.
			),
			'gpt-4o-realtime-preview-2024-12-17'      => array(
				'input'        => 100.00, // Audio input: $100 per 1M tokens.
				'output'       => 200.00, // Audio output: $200 per 1M tokens.
				'cached_input' => 20.00,  // Cached audio input: $20 per 1M tokens.
			),
			'gpt-4o-realtime-preview-2025-01-06'      => array(
				'input'        => 100.00, // Audio input: $100 per 1M tokens.
				'output'       => 200.00, // Audio output: $200 per 1M tokens.
				'cached_input' => 20.00,  // Cached audio input: $20 per 1M tokens.
			),
			'gpt-4o-realtime-preview-2025-06-03'      => array(
				'input'        => 100.00, // Audio input: $100 per 1M tokens.
				'output'       => 200.00, // Audio output: $200 per 1M tokens.
				'cached_input' => 20.00,  // Cached audio input: $20 per 1M tokens.
			),
			'gpt-4o-mini-realtime-preview'            => array(
				'input'        => 10.00, // Audio input: ~$10 per 1M tokens (10x cheaper).
				'output'       => 20.00, // Audio output: ~$20 per 1M tokens.
				'cached_input' => 2.00,  // Cached audio input: ~$2 per 1M tokens.
			),
			'gpt-4o-mini-realtime-preview-2024-12-17' => array(
				'input'        => 10.00, // Audio input: ~$10 per 1M tokens (10x cheaper).
				'output'       => 20.00, // Audio output: ~$20 per 1M tokens.
				'cached_input' => 2.00,  // Cached audio input: ~$2 per 1M tokens.
			),
			'gpt-4o-audio-preview'                    => array(
				'input'        => 100.00, // Audio input: $100 per 1M tokens.
				'output'       => 200.00, // Audio output: $200 per 1M tokens.
				'cached_input' => 20.00,  // Cached audio input: $20 per 1M tokens.
			),
			'gpt-4o-audio-preview-2024-12-17'         => array(
				'input'        => 100.00, // Audio input: $100 per 1M tokens.
				'output'       => 200.00, // Audio output: $200 per 1M tokens.
				'cached_input' => 20.00,  // Cached audio input: $20 per 1M tokens.
			),
			// GPT Realtime Mini (December 2025 - new naming convention).
			'gpt-realtime-mini'                       => array(
				'input'        => 10.00, // Audio input: $10 per 1M tokens.
				'output'       => 20.00, // Audio output: $20 per 1M tokens.
				'cached_input' => 0.30,  // Cached audio input: $0.30 per 1M tokens.
			),
			'gpt-realtime-mini-2025-12-15'            => array(
				'input'        => 10.00, // Audio input: $10 per 1M tokens.
				'output'       => 20.00, // Audio output: $20 per 1M tokens.
				'cached_input' => 0.30,  // Cached audio input: $0.30 per 1M tokens.
			),
			// Sora video generation models.
			// Pricing is per second of generated video.
			'sora-2'        => array(
				'per_second' => 0.10, // $0.10 per second of generated video (estimated).
			),
			'sora-2-pro'    => array(
				'per_second' => 0.20, // $0.20 per second of generated video (estimated).
			),
		),
		'gemini'    => array(
			// Gemini 2.5 series (November 2025).
			'gemini-2.5-pro'         => array(
				'input'  => 1.20,
				'output' => 4.80,
			),
			'gemini-2.5-flash'       => array(
				'input'  => 0.10,
				'output' => 0.40,
			),
			'gemini-2.5-flash-lite'  => array(
				'input'  => 0.05,
				'output' => 0.20,
			),
			'gemini-2.5-flash-image' => array(
				'input'  => 39.00,  // $0.039 per image (1024x1024).
				'output' => 39.00,
			),
			// Gemini 2.0 series.
			'gemini-2.0-flash'       => array(
				'input'  => 0.10,
				'output' => 0.40,
			),
			// Gemini 1.5 series (legacy).
			'gemini-1.5-pro'         => array(
				'input'  => 1.25,
				'output' => 5.00,
			),
			'gemini-1.5-pro-002'     => array(
				'input'  => 1.25,
				'output' => 5.00,
			),
			'gemini-1.5-flash'       => array(
				'input'  => 0.075,
				'output' => 0.30,
			),
			'gemini-1.5-flash-002'   => array(
				'input'  => 0.075,
				'output' => 0.30,
			),
			// Deprecated Gemini 1.0.
			'gemini-pro'             => array(
				'input'  => 0.50,
				'output' => 1.50,
			),
			// Veo video generation models.
			// Pricing is per second of generated video.
			// Based on Google Cloud Vertex AI documentation.
			// Note: Verify current pricing at https://cloud.google.com/vertex-ai/generative-ai/pricing.

			'veo-3.1-generate-001'   => array(
				'per_second' => 0.025,  // $0.025 per second of generated video.
			),
			'veo-2.0-generate-001'   => array(
				'per_second' => 0.020,  // $0.020 per second of generated video.
			),
		),
		'anthropic' => array(
			// Claude 4.5 series (November 2025).
			'claude-sonnet-4.5'          => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-haiku-4.5'           => array(
				'input'  => 1.00,
				'output' => 5.00,
			),
			'claude-opus-4.1'            => array(
				'input'  => 15.00,
				'output' => 75.00,
			),
			'claude-opus-4.0'            => array(
				'input'  => 15.00,
				'output' => 75.00,
			),
			// Claude 3.5 series (deprecated Nov 10, 2025).
			'claude-3.5-sonnet'          => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-3.5-sonnet-v2'       => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-3-5-sonnet-20241022' => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-3-5-haiku-20241022'  => array(
				'input'  => 0.80,
				'output' => 4.00,
			),
			// Claude 3 series (legacy).
			'claude-3-opus'              => array(
				'input'  => 15.00,
				'output' => 75.00,
			),
			'claude-3-opus-20240229'     => array(
				'input'  => 15.00,
				'output' => 75.00,
			),
			'claude-3-sonnet'            => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-3-haiku'             => array(
				'input'  => 0.25,
				'output' => 1.25,
			),
		),
		'ollama'    => array(
			'default' => array(
				'input'  => 0.00,
				'output' => 0.00,
			),
		),
		'lm_studio' => array(
			'default' => array(
				'input'  => 0.00,
				'output' => 0.00,
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

		// For ollama and lm_studio, return default pricing.
		if ( in_array( $provider, array( 'ollama', 'lm_studio' ), true ) ) {
			return $provider_pricing['default'];
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
		// Use an average of common models: gpt-4o-mini as a reasonable default.
		// Average of input (0.15) and output (0.60) = 0.375 per 1M tokens.
		$avg_cost_per_million = 0.375;

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
