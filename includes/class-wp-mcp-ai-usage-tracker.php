<?php
/**
 * Tracks per-user language model usage for billing purposes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persist aggregate token usage metrics for each user/model/provider pair.
 */
class WP_MCP_AI_Usage_Tracker {
	/**
	 * User meta key used to persist aggregated usage totals.
	 */
	const USER_META_KEY = '_wp_mcp_ai_usage_totals';

	/**
	 * Bootstrap usage tracking hooks.
	 */
	public static function init() {
		add_action( 'delete_user', array( __CLASS__, 'delete_usage_for_user' ) );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'delete_usage_for_user' ) );
	}

	/**
	 * Remove persisted usage when a user account is deleted.
	 *
	 * @param int $user_id User identifier.
	 */
	public static function delete_usage_for_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		delete_user_meta( $user_id, self::USER_META_KEY );
	}

	/**
	 * Record usage information from a chat response.
	 *
	 * @param int   $user_id      WordPress user identifier.
	 * @param int   $assistant_id Assistant post identifier.
	 * @param array $options      Sanitized request options.
	 * @param array $response     Language model response payload.
	 */
	public static function record_chat_usage( $user_id, $assistant_id, array $options, array $response ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		if ( ! is_array( $settings ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		}

		$provider = self::determine_provider( $options, $response, $settings );
		$model    = self::determine_model( $options, $response, $provider, $settings );

		$usage = self::extract_usage_from_response( $response );
		if ( empty( $usage ) ) {
			return;
		}

		/**
		 * Allow extensions to adjust the usage snapshot before it is recorded.
		 *
		 * Returning an empty value prevents the usage data from being stored.
		 *
		 * @param array $usage        Normalized usage array.
		 * @param int   $user_id      Acting user identifier.
		 * @param int   $assistant_id Assistant identifier.
		 * @param array $options      Request options.
		 * @param array $response     Language model response payload.
		 * @param string $provider    Language model provider key.
		 * @param string $model       Resolved model identifier.
		 */
		$usage = apply_filters( 'wp_mcp_ai_usage_snapshot', $usage, $user_id, $assistant_id, $options, $response, $provider, $model );
		$usage = self::normalize_usage_array( $usage );

		if ( empty( $usage ) ) {
			return;
		}

		$totals = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $totals ) ) {
			$totals = array();
		}

		if ( ! isset( $totals[ $provider ] ) || ! is_array( $totals[ $provider ] ) ) {
			$totals[ $provider ] = array();
		}

		if ( ! isset( $totals[ $provider ][ $model ] ) || ! is_array( $totals[ $provider ][ $model ] ) ) {
			$totals[ $provider ][ $model ] = self::get_initial_model_totals();
		}

		$totals[ $provider ][ $model ] = self::increment_totals( $totals[ $provider ][ $model ], $usage, $assistant_id );

		update_user_meta( $user_id, self::USER_META_KEY, $totals );

		/**
		 * Fires after usage totals have been updated for a user.
		 *
		 * @param int    $user_id  Acting user identifier.
		 * @param int    $assistant_id Assistant identifier associated with the usage.
		 * @param string $provider Provider key (e.g. openai, gemini).
		 * @param string $model    Model identifier.
		 * @param array  $totals   Updated totals for the model.
		 * @param array  $usage    Usage delta applied to the totals.
		 */
		do_action( 'wp_mcp_ai_after_usage_recorded', $user_id, $assistant_id, $provider, $model, $totals[ $provider ][ $model ], $usage );
	}

	/**
	 * Retrieve stored usage totals for a user.
	 *
	 * @param int $user_id WordPress user identifier.
	 * @return array
	 */
	public static function get_usage_for_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		$usage = get_user_meta( $user_id, self::USER_META_KEY, true );

		return is_array( $usage ) ? $usage : array();
	}

	/**
	 * Determine the provider associated with the response.
	 *
	 * @param array $options  Sanitized request options.
	 * @param array $response Language model response payload.
	 * @param array $settings Plugin settings array.
	 * @return string
	 */
	protected static function determine_provider( array $options, array $response, array $settings ) {
		$provider = '';

		if ( isset( $response['provider'] ) ) {
			$provider = sanitize_key( $response['provider'] );
		}

		if ( '' === $provider && isset( $options['provider'] ) ) {
			$provider = sanitize_key( $options['provider'] );
		}

		if ( '' === $provider && isset( $settings['default_provider'] ) ) {
			$provider = sanitize_key( $settings['default_provider'] );
		}

		if ( '' === $provider ) {
			$provider = 'openai';
		}

		return $provider;
	}

	/**
	 * Determine the model identifier for the response.
	 *
	 * @param array  $options  Sanitized request options.
	 * @param array  $response Language model response payload.
	 * @param string $provider Provider identifier.
	 * @param array  $settings Plugin settings array.
	 * @return string
	 */
	protected static function determine_model( array $options, array $response, $provider, array $settings ) {
		$model = '';

		if ( isset( $response['model'] ) && is_string( $response['model'] ) ) {
			$model = sanitize_text_field( $response['model'] );
		}

		if ( '' === $model && isset( $options['model'] ) && '' !== $options['model'] ) {
			$model = sanitize_text_field( $options['model'] );
		}

		if ( '' === $model ) {
			if ( 'gemini' === $provider && ! empty( $settings['default_gemini_model'] ) ) {
				$model = sanitize_text_field( $settings['default_gemini_model'] );
			} elseif ( ! empty( $settings['default_model'] ) ) {
				$model = sanitize_text_field( $settings['default_model'] );
			}
		}

		if ( '' === $model ) {
			$model = 'unknown-model';
		}

		return $model;
	}

	/**
	 * Extract usage information from a response payload.
	 *
	 * @param array $response Language model response payload.
	 * @return array
	 */
	protected static function extract_usage_from_response( array $response ) {
		if ( empty( $response['usage'] ) || ! is_array( $response['usage'] ) ) {
			return array();
		}

		return self::normalize_usage_array( $response['usage'] );
	}

	/**
	 * Normalize a usage array to ensure consistent keys and integer values.
	 *
	 * @param mixed $usage Raw usage data.
	 * @return array
	 */
	protected static function normalize_usage_array( $usage ) {
		if ( ! is_array( $usage ) ) {
			return array();
		}

		$prompt_tokens     = isset( $usage['prompt_tokens'] ) ? max( 0, (int) $usage['prompt_tokens'] ) : 0;
		$completion_tokens = isset( $usage['completion_tokens'] ) ? max( 0, (int) $usage['completion_tokens'] ) : 0;
		$total_tokens      = isset( $usage['total_tokens'] ) ? max( 0, (int) $usage['total_tokens'] ) : 0;
		$cached_tokens     = isset( $usage['cached_tokens'] ) ? max( 0, (int) $usage['cached_tokens'] ) : 0;

		if ( 0 === $total_tokens ) {
			$total_tokens = $prompt_tokens + $completion_tokens;
		}

		if ( 0 === $prompt_tokens && 0 === $completion_tokens && 0 === $total_tokens && 0 === $cached_tokens ) {
			return array();
		}

		return array(
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'total_tokens'      => $total_tokens,
			'cached_tokens'     => $cached_tokens,
		);
	}

	/**
	 * Provide the default structure for model-level totals.
	 *
	 * @return array
	 */
	protected static function get_initial_model_totals() {
		return array(
			'requests'          => 0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
			'cached_tokens'     => 0,
			'last_used_gmt'     => '',
			'assistants'        => array(),
		);
	}

	/**
	 * Provide the default structure for assistant-level totals.
	 *
	 * @return array
	 */
	protected static function get_initial_assistant_totals() {
		return array(
			'requests'          => 0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
			'cached_tokens'     => 0,
			'last_used_gmt'     => '',
		);
	}

	/**
	 * Increment totals with a usage delta.
	 *
	 * @param array $existing_totals Existing totals for the model.
	 * @param array $usage           Usage delta to apply.
	 * @param int   $assistant_id    Assistant identifier.
	 * @return array
	 */
	protected static function increment_totals( array $existing_totals, array $usage, $assistant_id ) {
		$timestamp = current_time( 'mysql', true );

		if ( ! isset( $existing_totals['requests'] ) ) {
			$existing_totals['requests'] = 0;
		}

		$existing_totals['requests']          = (int) $existing_totals['requests'] + 1;
		$existing_totals['prompt_tokens']     = isset( $existing_totals['prompt_tokens'] ) ? (int) $existing_totals['prompt_tokens'] : 0;
		$existing_totals['completion_tokens'] = isset( $existing_totals['completion_tokens'] ) ? (int) $existing_totals['completion_tokens'] : 0;
		$existing_totals['total_tokens']      = isset( $existing_totals['total_tokens'] ) ? (int) $existing_totals['total_tokens'] : 0;
		$existing_totals['cached_tokens']     = isset( $existing_totals['cached_tokens'] ) ? (int) $existing_totals['cached_tokens'] : 0;

		$existing_totals['prompt_tokens']     += isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0;
		$existing_totals['completion_tokens'] += isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0;
		$existing_totals['total_tokens']      += isset( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : 0;
		$existing_totals['cached_tokens']     += isset( $usage['cached_tokens'] ) ? (int) $usage['cached_tokens'] : 0;
		$existing_totals['last_used_gmt']      = $timestamp;

		if ( ! isset( $existing_totals['assistants'] ) || ! is_array( $existing_totals['assistants'] ) ) {
			$existing_totals['assistants'] = array();
		}

		$assistant_id = absint( $assistant_id );
		if ( $assistant_id > 0 ) {
			if ( ! isset( $existing_totals['assistants'][ $assistant_id ] ) || ! is_array( $existing_totals['assistants'][ $assistant_id ] ) ) {
				$existing_totals['assistants'][ $assistant_id ] = self::get_initial_assistant_totals();
			}

			$assistant_totals = $existing_totals['assistants'][ $assistant_id ];

			if ( ! isset( $assistant_totals['requests'] ) ) {
				$assistant_totals['requests'] = 0;
			}

			$assistant_totals['requests']          = (int) $assistant_totals['requests'] + 1;
			$assistant_totals['prompt_tokens']     = isset( $assistant_totals['prompt_tokens'] ) ? (int) $assistant_totals['prompt_tokens'] : 0;
			$assistant_totals['completion_tokens'] = isset( $assistant_totals['completion_tokens'] ) ? (int) $assistant_totals['completion_tokens'] : 0;
			$assistant_totals['total_tokens']      = isset( $assistant_totals['total_tokens'] ) ? (int) $assistant_totals['total_tokens'] : 0;
			$assistant_totals['cached_tokens']     = isset( $assistant_totals['cached_tokens'] ) ? (int) $assistant_totals['cached_tokens'] : 0;

			$assistant_totals['prompt_tokens']     += isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0;
			$assistant_totals['completion_tokens'] += isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0;
			$assistant_totals['total_tokens']      += isset( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : 0;
			$assistant_totals['cached_tokens']     += isset( $usage['cached_tokens'] ) ? (int) $usage['cached_tokens'] : 0;
			$assistant_totals['last_used_gmt']      = $timestamp;

			$existing_totals['assistants'][ $assistant_id ] = $assistant_totals;
		}

		return $existing_totals;
	}

	/**
	 * Calculate cost for usage data based on model pricing.
	 *
	 * @param string $provider         Provider key (openai, gemini, anthropic, etc).
	 * @param string $model            Model identifier.
	 * @param int    $prompt_tokens    Number of input/prompt tokens.
	 * @param int    $completion_tokens Number of output/completion tokens.
	 * @return float Cost in USD. Returns 0 if pricing data unavailable.
	 */
	public static function calculate_cost( $provider, $model, $prompt_tokens, $completion_tokens ) {
		$provider          = sanitize_key( $provider );
		$model             = sanitize_text_field( $model );
		$prompt_tokens     = max( 0, (int) $prompt_tokens );
		$completion_tokens = max( 0, (int) $completion_tokens );

		// Get pricing from Model Rate Limits CCT if available.
		$pricing = self::get_model_pricing( $model );

		if ( ! $pricing ) {
			return 0.0;
		}

		$input_cost  = ( $prompt_tokens / 1000 ) * $pricing['input_cost_per_1k'];
		$output_cost = ( $completion_tokens / 1000 ) * $pricing['output_cost_per_1k'];

		return (float) ( $input_cost + $output_cost );
	}

	/**
	 * Calculate total cost for all usage data for a user.
	 *
	 * @param int $user_id WordPress user identifier.
	 * @return float Total cost in USD.
	 */
	public static function calculate_user_total_cost( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return 0.0;
		}

		$usage      = self::get_usage_for_user( $user_id );
		$total_cost = 0.0;

		if ( ! is_array( $usage ) || empty( $usage ) ) {
			return 0.0;
		}

		foreach ( $usage as $provider => $models ) {
			if ( ! is_array( $models ) ) {
				continue;
			}

			foreach ( $models as $model => $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}

				$prompt_tokens     = isset( $data['prompt_tokens'] ) ? (int) $data['prompt_tokens'] : 0;
				$completion_tokens = isset( $data['completion_tokens'] ) ? (int) $data['completion_tokens'] : 0;

				$total_cost += self::calculate_cost( $provider, $model, $prompt_tokens, $completion_tokens );
			}
		}

		return $total_cost;
	}

	/**
	 * Get pricing information for a specific model.
	 *
	 * @param string $model Model identifier.
	 * @return array|null Array with 'input_cost_per_1k' and 'output_cost_per_1k' keys, or null if not available.
	 */
	protected static function get_model_pricing( $model ) {
		$model = sanitize_text_field( $model );

		// Try to get from Model Rate Limits CCT first.
		if ( class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			try {
				$model_data = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_limits( $model );

				if ( $model_data && is_array( $model_data ) ) {
					$input_cost  = isset( $model_data['cost_per_1k_input_tokens'] ) ? (float) $model_data['cost_per_1k_input_tokens'] : 0;
					$output_cost = isset( $model_data['cost_per_1k_output_tokens'] ) ? (float) $model_data['cost_per_1k_output_tokens'] : 0;

					if ( $input_cost > 0 || $output_cost > 0 ) {
						return array(
							'input_cost_per_1k'  => $input_cost,
							'output_cost_per_1k' => $output_cost,
						);
					}
				}
			} catch ( Exception $e ) {
				// Silently fail and use fallback pricing.
				WP_MCP_AI_Logger::log_error(
					'Error getting model pricing from CCT',
					array(
						'model'     => $model,
						'error'     => $e->getMessage(),
						'exception' => get_class( $e ),
					)
				);
			}
		}

		// Fallback to hardcoded pricing for common models.
		return self::get_fallback_pricing( $model );
	}

	/**
	 * Get fallback pricing for common models.
	 *
	 * @param string $model Model identifier.
	 * @return array|null Array with pricing or null if model not found.
	 */
	protected static function get_fallback_pricing( $model ) {
		$model = strtolower( sanitize_text_field( $model ) );

		// Common model pricing (as of November 2024).
		$pricing_map = array(
			'gpt-5'                => array(
				'input_cost_per_1k'  => 0.01,
				'output_cost_per_1k' => 0.03,
			),
			'gpt-5-mini'           => array(
				'input_cost_per_1k'  => 0.002,
				'output_cost_per_1k' => 0.006,
			),
			'gpt-4.1'              => array(
				'input_cost_per_1k'  => 0.001,
				'output_cost_per_1k' => 0.004,
			),
			'gpt-4.1-mini'         => array(
				'input_cost_per_1k'  => 0.0004,
				'output_cost_per_1k' => 0.0016,
			),
			'gpt-4.1-nano'         => array(
				'input_cost_per_1k'  => 0.0002,
				'output_cost_per_1k' => 0.0008,
			),
			'gpt-4o'               => array(
				'input_cost_per_1k'  => 0.0025,
				'output_cost_per_1k' => 0.01,
			),
			'gpt-4o-mini'          => array(
				'input_cost_per_1k'  => 0.00015,
				'output_cost_per_1k' => 0.0006,
			),
			'gpt-4-turbo'          => array(
				'input_cost_per_1k'  => 0.01,
				'output_cost_per_1k' => 0.03,
			),
			'gpt-4'                => array(
				'input_cost_per_1k'  => 0.03,
				'output_cost_per_1k' => 0.06,
			),
			'gpt-3.5-turbo'        => array(
				'input_cost_per_1k'  => 0.0005,
				'output_cost_per_1k' => 0.0015,
			),
			'o1-2024-12-17'        => array(
				'input_cost_per_1k'  => 0.015,
				'output_cost_per_1k' => 0.06,
			),
			'o1-preview'           => array(
				'input_cost_per_1k'  => 0.015,
				'output_cost_per_1k' => 0.06,
			),
			'o1-mini'              => array(
				'input_cost_per_1k'  => 0.003,
				'output_cost_per_1k' => 0.012,
			),
			'o3-mini'              => array(
				'input_cost_per_1k'  => 0.00110,
				'output_cost_per_1k' => 0.00440,
			),
			'gemini-1.5-pro'       => array(
				'input_cost_per_1k'  => 0.00125,
				'output_cost_per_1k' => 0.005,
			),
			'gemini-1.5-pro-002'   => array(
				'input_cost_per_1k'  => 0.00125,
				'output_cost_per_1k' => 0.005,
			),
			'gemini-1.5-flash'     => array(
				'input_cost_per_1k'  => 0.000075,
				'output_cost_per_1k' => 0.0003,
			),
			'gemini-1.5-flash-002' => array(
				'input_cost_per_1k'  => 0.000075,
				'output_cost_per_1k' => 0.0003,
			),
			'gemini-2.0-flash'     => array(
				'input_cost_per_1k'  => 0.0001,
				'output_cost_per_1k' => 0.0004,
			),
			'gemini-2.5-flash'     => array(
				'input_cost_per_1k'  => 0.000075,
				'output_cost_per_1k' => 0.0003,
			),
			'gemini-2.5-pro'       => array(
				'input_cost_per_1k'  => 0.00125,
				'output_cost_per_1k' => 0.01,
			),
			'claude-3.5-sonnet'    => array(
				'input_cost_per_1k'  => 0.003,
				'output_cost_per_1k' => 0.015,
			),
			'claude-3.5-sonnet-v2' => array(
				'input_cost_per_1k'  => 0.003,
				'output_cost_per_1k' => 0.015,
			),
			'claude-3-opus'        => array(
				'input_cost_per_1k'  => 0.015,
				'output_cost_per_1k' => 0.075,
			),
			'claude-3-haiku'       => array(
				'input_cost_per_1k'  => 0.00025,
				'output_cost_per_1k' => 0.00125,
			),
		);

		// Try exact match first.
		if ( isset( $pricing_map[ $model ] ) ) {
			return $pricing_map[ $model ];
		}

		// Try prefix match for model families, preferring the longest match.
		// This ensures "gpt-5-2025-08-07" matches "gpt-5" correctly,.
		// even when both "gpt-5" and "gpt-5-mini" exist in the map.
		$best_match        = null;
		$best_match_length = 0;

		foreach ( $pricing_map as $model_key => $pricing ) {
			if ( 0 === strpos( $model, $model_key ) ) {
				$match_length = strlen( $model_key );

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

		// No pricing data available.
		return null;
	}
}
