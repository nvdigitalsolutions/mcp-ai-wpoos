<?php
/**
 * Tracks per-user language model usage for billing purposes.
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

		// Common model pricing (as of June 2026).
		// See: https://openai.com/api/pricing/.
		$pricing_map = array(
			// OpenAI GPT-5.5 series (April 2026).
			'gpt-5.5'                                      => array(
				'input_cost_per_1k'  => 0.005,  // $5.00/1M.
				'output_cost_per_1k' => 0.03,   // $30.00/1M.
			),
			'gpt-5.5-pro'                                  => array(
				'input_cost_per_1k'  => 0.03,   // $30.00/1M.
				'output_cost_per_1k' => 0.18,   // $180.00/1M.
			),
			// OpenAI GPT-5.4 series (March 2026).
			'gpt-5.4'                                      => array(
				'input_cost_per_1k'  => 0.0025, // $2.50/1M.
				'output_cost_per_1k' => 0.015,  // $15.00/1M.
			),
			'gpt-5.4-mini'                                 => array(
				'input_cost_per_1k'  => 0.00075, // $0.75/1M.
				'output_cost_per_1k' => 0.0045,  // $4.50/1M.
			),
			'gpt-5.4-nano'                                 => array(
				'input_cost_per_1k'  => 0.0002,  // $0.20/1M.
				'output_cost_per_1k' => 0.00125, // $1.25/1M.
			),
			'gpt-5.4-pro'                                  => array(
				'input_cost_per_1k'  => 0.03,   // $30.00/1M.
				'output_cost_per_1k' => 0.18,   // $180.00/1M.
			),
			// OpenAI GPT-5.x legacy.
			'gpt-5.3-codex'                                => array(
				'input_cost_per_1k'  => 0.00175, // $1.75/1M.
				'output_cost_per_1k' => 0.014,   // $14.00/1M.
			),
			'gpt-5.2'                                      => array(
				'input_cost_per_1k'  => 0.00175, // $1.75/1M.
				'output_cost_per_1k' => 0.014,   // $14.00/1M.
			),
			'gpt-5.2-pro'                                  => array(
				'input_cost_per_1k'  => 0.021,  // $21.00/1M.
				'output_cost_per_1k' => 0.168,  // $168.00/1M.
			),
			'gpt-5.1'                                      => array(
				'input_cost_per_1k'  => 0.00125, // $1.25/1M.
				'output_cost_per_1k' => 0.01,    // $10.00/1M.
			),
			'gpt-5'                                        => array(
				'input_cost_per_1k'  => 0.00125, // $1.25/1M.
				'output_cost_per_1k' => 0.01,    // $10.00/1M.
			),
			'gpt-5-mini'                                   => array(
				'input_cost_per_1k'  => 0.00025, // $0.25/1M.
				'output_cost_per_1k' => 0.002,   // $2.00/1M.
			),
			'gpt-5-nano'                                   => array(
				'input_cost_per_1k'  => 0.00005, // $0.05/1M.
				'output_cost_per_1k' => 0.0004,  // $0.40/1M.
			),
			// OpenAI GPT-4.1 series.
			'gpt-4.1'                                      => array(
				'input_cost_per_1k'  => 0.002,  // $2.00/1M.
				'output_cost_per_1k' => 0.008,  // $8.00/1M.
			),
			'gpt-4.1-mini'                                 => array(
				'input_cost_per_1k'  => 0.0004, // $0.40/1M.
				'output_cost_per_1k' => 0.0016, // $1.60/1M.
			),
			'gpt-4.1-nano'                                 => array(
				'input_cost_per_1k'  => 0.0001, // $0.10/1M.
				'output_cost_per_1k' => 0.0004, // $0.40/1M.
			),
			// OpenAI GPT-4o series.
			'gpt-4o'                                       => array(
				'input_cost_per_1k'  => 0.0025, // $2.50/1M.
				'output_cost_per_1k' => 0.01,   // $10.00/1M.
			),
			'gpt-4o-mini'                                  => array(
				'input_cost_per_1k'  => 0.00015, // $0.15/1M.
				'output_cost_per_1k' => 0.0006,  // $0.60/1M.
			),
			// OpenAI o-series reasoning.
			'o3'                                           => array(
				'input_cost_per_1k'  => 0.002,  // $2.00/1M.
				'output_cost_per_1k' => 0.008,  // $8.00/1M.
			),
			'o4-mini'                                      => array(
				'input_cost_per_1k'  => 0.0011, // $1.10/1M.
				'output_cost_per_1k' => 0.0044, // $4.40/1M.
			),
			'o1'                                           => array(
				'input_cost_per_1k'  => 0.015,  // $15.00/1M.
				'output_cost_per_1k' => 0.06,   // $60.00/1M.
			),
			'o1-mini'                                      => array(
				'input_cost_per_1k'  => 0.003,  // $3.00/1M.
				'output_cost_per_1k' => 0.012,  // $12.00/1M.
			),
			'o3-mini'                                      => array(
				'input_cost_per_1k'  => 0.0011, // $1.10/1M.
				'output_cost_per_1k' => 0.0044, // $4.40/1M.
			),
			// Legacy OpenAI.
			'gpt-4-turbo'                                  => array(
				'input_cost_per_1k'  => 0.01,
				'output_cost_per_1k' => 0.03,
			),
			'gpt-4'                                        => array(
				'input_cost_per_1k'  => 0.03,
				'output_cost_per_1k' => 0.06,
			),
			'gpt-3.5-turbo'                                => array(
				'input_cost_per_1k'  => 0.0005,
				'output_cost_per_1k' => 0.0015,
			),
			'gemini-1.5-pro'                               => array(
				'input_cost_per_1k'  => 0.00125,
				'output_cost_per_1k' => 0.005,
			),
			'gemini-1.5-pro-002'                           => array(
				'input_cost_per_1k'  => 0.00125,
				'output_cost_per_1k' => 0.005,
			),
			'gemini-1.5-flash'                             => array(
				'input_cost_per_1k'  => 0.000075,
				'output_cost_per_1k' => 0.0003,
			),
			'gemini-1.5-flash-002'                         => array(
				'input_cost_per_1k'  => 0.000075,
				'output_cost_per_1k' => 0.0003,
			),
			'gemini-2.0-flash'                             => array(
				'input_cost_per_1k'  => 0.0001,
				'output_cost_per_1k' => 0.0004,
			),
			'gemini-2.5-flash'                             => array(
				'input_cost_per_1k'  => 0.000075,
				'output_cost_per_1k' => 0.0003,
			),
			'gemini-2.5-pro'                               => array(
				'input_cost_per_1k'  => 0.00125,
				'output_cost_per_1k' => 0.01,
			),
			// Anthropic Claude 4.6 series (February 2026).
			'claude-sonnet-4-6'                            => array(
				'input_cost_per_1k'  => 0.003,
				'output_cost_per_1k' => 0.015,
			),
			'claude-opus-4-6'                              => array(
				'input_cost_per_1k'  => 0.005,
				'output_cost_per_1k' => 0.025,
			),
			'claude-opus-4-7'                              => array(
				'input_cost_per_1k'  => 0.005,
				'output_cost_per_1k' => 0.025,
			),
			'claude-opus-4-8'                              => array(
				'input_cost_per_1k'  => 0.005,
				'output_cost_per_1k' => 0.025,
			),
			// Anthropic Claude 4.5 series (January 2026).
			'claude-sonnet-4-5'                            => array(
				'input_cost_per_1k'  => 0.003,
				'output_cost_per_1k' => 0.015,
			),
			'claude-sonnet-4-5-20250929'                   => array(
				'input_cost_per_1k'  => 0.003,
				'output_cost_per_1k' => 0.015,
			),
			'claude-haiku-4-5'                             => array(
				'input_cost_per_1k'  => 0.001,
				'output_cost_per_1k' => 0.005,
			),
			'claude-haiku-4-5-20251001'                    => array(
				'input_cost_per_1k'  => 0.001,
				'output_cost_per_1k' => 0.005,
			),
			'claude-opus-4-5'                              => array(
				'input_cost_per_1k'  => 0.005,
				'output_cost_per_1k' => 0.025,
			),
			'claude-opus-4-1-20250805'                     => array(
				'input_cost_per_1k'  => 0.015,
				'output_cost_per_1k' => 0.075,
			),
			// Anthropic Claude 3.5 series (legacy).
			'claude-3-5-sonnet-20241022'                   => array(
				'input_cost_per_1k'  => 0.003,
				'output_cost_per_1k' => 0.015,
			),
			'claude-3-5-haiku-20241022'                    => array(
				'input_cost_per_1k'  => 0.0008,
				'output_cost_per_1k' => 0.004,
			),
			'claude-3.5-sonnet'                            => array(
				'input_cost_per_1k'  => 0.003,
				'output_cost_per_1k' => 0.015,
			),
			'claude-3.5-sonnet-v2'                         => array(
				'input_cost_per_1k'  => 0.003,
				'output_cost_per_1k' => 0.015,
			),
			// Anthropic Claude 3 series (legacy).
			'claude-3-sonnet'                              => array(
				'input_cost_per_1k'  => 0.003,
				'output_cost_per_1k' => 0.015,
			),
			'claude-3-opus'                                => array(
				'input_cost_per_1k'  => 0.015,
				'output_cost_per_1k' => 0.075,
			),
			'claude-3-opus-20240229'                       => array(
				'input_cost_per_1k'  => 0.015,
				'output_cost_per_1k' => 0.075,
			),
			'claude-3-haiku'                               => array(
				'input_cost_per_1k'  => 0.00025,
				'output_cost_per_1k' => 0.00125,
			),
			// Cloudflare Workers AI models (as of January 2025).
			// Function Calling Models.
			'@cf/meta/llama-3.3-70b-instruct-fp8-fast'     => array(
				'input_cost_per_1k'  => 0.000293,
				'output_cost_per_1k' => 0.002253,
			),
			'@cf/meta/llama-4-scout-17b-16e-instruct'      => array(
				'input_cost_per_1k'  => 0.000270,
				'output_cost_per_1k' => 0.000850,
			),
			'@cf/ibm-granite/granite-4.0-h-micro'          => array(
				'input_cost_per_1k'  => 0.000100,
				'output_cost_per_1k' => 0.000300,
			),
			'@cf/qwen/qwen3-30b-a3b-fp8'                   => array(
				'input_cost_per_1k'  => 0.000400,
				'output_cost_per_1k' => 0.001200,
			),
			'@cf/mistralai/mistral-small-3.1-24b-instruct' => array(
				'input_cost_per_1k'  => 0.000351,
				'output_cost_per_1k' => 0.000555,
			),
			'@hf/nousresearch/hermes-2-pro-mistral-7b'     => array(
				'input_cost_per_1k'  => 0.000110,
				'output_cost_per_1k' => 0.000190,
			),
			// Text Generation Models.
			'@cf/google/gemma-4-26b-it'                    => array(
				'input_cost_per_1k'  => 0.000400,
				'output_cost_per_1k' => 0.001200,
			),
			'@cf/aisingapore/gemma-sea-lion-v4-27b-it'     => array(
				'input_cost_per_1k'  => 0.000350,
				'output_cost_per_1k' => 0.001000,
			),
			'@cf/openai/gpt-oss-20b'                       => array(
				'input_cost_per_1k'  => 0.000250,
				'output_cost_per_1k' => 0.000800,
			),
			'@cf/openai/gpt-oss-120b'                      => array(
				'input_cost_per_1k'  => 0.000600,
				'output_cost_per_1k' => 0.002000,
			),
			'@cf/google/gemma-3-12b-it'                    => array(
				'input_cost_per_1k'  => 0.000150,
				'output_cost_per_1k' => 0.000450,
			),
			'@cf/qwen/qwq-32b'                             => array(
				'input_cost_per_1k'  => 0.000400,
				'output_cost_per_1k' => 0.001200,
			),
			'@cf/qwen/qwen2.5-coder-32b-instruct'          => array(
				'input_cost_per_1k'  => 0.000400,
				'output_cost_per_1k' => 0.001200,
			),
			'@cf/deepseek-ai/deepseek-r1-distill-qwen-32b' => array(
				'input_cost_per_1k'  => 0.000497,
				'output_cost_per_1k' => 0.004881,
			),
			'@cf/meta/llama-3.2-1b-instruct'               => array(
				'input_cost_per_1k'  => 0.000027,
				'output_cost_per_1k' => 0.000201,
			),
			'@cf/meta/llama-3.1-8b-instruct'               => array(
				'input_cost_per_1k'  => 0.000282,
				'output_cost_per_1k' => 0.000827,
			),
			'@cf/mistral/mistral-7b-instruct-v0.1'         => array(
				'input_cost_per_1k'  => 0.000110,
				'output_cost_per_1k' => 0.000190,
			),
			'@cf/meta/llama-3.2-3b-instruct'               => array(
				'input_cost_per_1k'  => 0.000051,
				'output_cost_per_1k' => 0.000335,
			),
			// HuggingFace Inference API models (as of January 2026).
			'deepseek-ai/deepseek-v3.2'                    => array(
				'input_cost_per_1k'  => 0.00028, // $0.28 per 1M tokens = $0.00028 per 1K.
				'output_cost_per_1k' => 0.00042, // $0.42 per 1M tokens = $0.00042 per 1K.
			),
			'meta-llama/llama-3.3-70b-instruct'            => array(
				'input_cost_per_1k'  => 0.001, // $1.00 per 1M tokens = $0.001 per 1K.
				'output_cost_per_1k' => 0.001,
			),
			'meta-llama/llama-3.1-8b-instruct'             => array(
				'input_cost_per_1k'  => 0.0003, // $0.30 per 1M tokens = $0.0003 per 1K.
				'output_cost_per_1k' => 0.0003,
			),
			'mistralai/mistral-7b-instruct-v0.3'           => array(
				'input_cost_per_1k'  => 0.0002, // $0.20 per 1M tokens = $0.0002 per 1K.
				'output_cost_per_1k' => 0.0002,
			),
			'microsoft/phi-3-mini-4k-instruct'             => array(
				'input_cost_per_1k'  => 0.0001, // $0.10 per 1M tokens = $0.0001 per 1K.
				'output_cost_per_1k' => 0.0001,
			),
			'qwen/qwen2.5-72b-instruct'                    => array(
				'input_cost_per_1k'  => 0.001, // $1.00 per 1M tokens = $0.001 per 1K.
				'output_cost_per_1k' => 0.001,
			),
			'qwen/qwen2.5-7b-instruct'                     => array(
				'input_cost_per_1k'  => 0.0002, // $0.20 per 1M tokens = $0.0002 per 1K.
				'output_cost_per_1k' => 0.0002,
			),
			// Baseten Model APIs (as of June 2026).
			// See: https://www.baseten.co/pricing/.
			'deepseek-ai/deepseek-v3'                      => array(
				'input_cost_per_1k'  => 0.0005, // $0.50 per 1M tokens = $0.0005 per 1K.
				'output_cost_per_1k' => 0.0015, // $1.50 per 1M tokens = $0.0015 per 1K.
			),
			'deepseek-ai/deepseek-r1'                      => array(
				'input_cost_per_1k'  => 0.0008, // $0.80 per 1M tokens = $0.0008 per 1K.
				'output_cost_per_1k' => 0.003,   // $3.00 per 1M tokens = $0.003 per 1K.
			),
			// DeepSeek direct API models (as of June 2026).
			// See: https://api-docs.deepseek.com/quick_start/pricing.
			'deepseek-v4-flash'                            => array(
				'input_cost_per_1k'  => 0.00014, // $0.14 per 1M tokens = $0.00014 per 1K (cache miss).
				'output_cost_per_1k' => 0.00028, // $0.28 per 1M tokens = $0.00028 per 1K.
			),
			'deepseek-v4-pro'                              => array(
				'input_cost_per_1k'  => 0.000435, // Promotional: $0.435/1M (regular: $1.74/1M).
				'output_cost_per_1k' => 0.00087,  // Promotional: $0.87/1M (regular: $3.48/1M).
			),
			'deepseek-chat'                                => array(
				'input_cost_per_1k'  => 0.00027, // $0.27 per 1M tokens (deprecated, use v4-flash).
				'output_cost_per_1k' => 0.0011,  // $1.10 per 1M tokens.
			),
			'deepseek-reasoner'                            => array(
				'input_cost_per_1k'  => 0.00055, // $0.55 per 1M tokens (deprecated, use v4-flash thinking).
				'output_cost_per_1k' => 0.00219, // $2.19 per 1M tokens.
			),
			'deepseek-coder'                               => array(
				'input_cost_per_1k'  => 0.00027, // $0.27 per 1M tokens (deprecated, use v4-flash/v4-pro).
				'output_cost_per_1k' => 0.0011,  // $1.10 per 1M tokens.
			),
			'zai-org/glm-4'                                => array(
				'input_cost_per_1k'  => 0.0006, // $0.60 per 1M tokens = $0.0006 per 1K.
				'output_cost_per_1k' => 0.0022, // $2.20 per 1M tokens = $0.0022 per 1K.
			),
			// Z.AI GLM-5.x direct models (as of June 2026).
			'glm-5.2'                                      => array(
				'input_cost_per_1k'  => 0.0014, // $1.40 per 1M tokens.
				'output_cost_per_1k' => 0.0044, // $4.40 per 1M tokens.
			),
			'glm-5'                                        => array(
				'input_cost_per_1k'  => 0.0014, // $1.40 per 1M tokens.
				'output_cost_per_1k' => 0.0044, // $4.40 per 1M tokens.
			),
			'glm-5-turbo'                                  => array(
				'input_cost_per_1k'  => 0.0007, // Estimated ~50% of GLM-5.2.
				'output_cost_per_1k' => 0.0022, // Estimated ~50% of GLM-5.2.
			),
			'glm-4.7'                                      => array(
				'input_cost_per_1k'  => 0.0006,
				'output_cost_per_1k' => 0.0022,
			),
			'moonshotai/kimi-k2'                           => array(
				'input_cost_per_1k'  => 0.0006, // $0.60 per 1M tokens = $0.0006 per 1K.
				'output_cost_per_1k' => 0.0025, // $2.50 per 1M tokens = $0.0025 per 1K.
			),
			// Kimi (Moonshot AI) direct models (as of June 2026).
			// See: https://platform.moonshot.ai/docs/pricing/chat.
			'kimi-k2.6'                                    => array(
				'input_cost_per_1k'  => 0.00095, // $0.95 per 1M tokens = $0.00095 per 1K.
				'output_cost_per_1k' => 0.004,   // $4.00 per 1M tokens = $0.004 per 1K.
			),
			'kimi-k2.5'                                    => array(
				'input_cost_per_1k'  => 0.0006, // $0.60 per 1M tokens = $0.0006 per 1K.
				'output_cost_per_1k' => 0.003,   // $3.00 per 1M tokens = $0.003 per 1K.
			),
			'kimi-k2'                                      => array(
				'input_cost_per_1k'  => 0.0006, // $0.60 per 1M tokens = $0.0006 per 1K.
				'output_cost_per_1k' => 0.0025, // $2.50 per 1M tokens = $0.0025 per 1K.
			),
			'kimi-k2-thinking'                             => array(
				'input_cost_per_1k'  => 0.0006, // $0.60 per 1M tokens = $0.0006 per 1K.
				'output_cost_per_1k' => 0.0025, // $2.50 per 1M tokens = $0.0025 per 1K.
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
