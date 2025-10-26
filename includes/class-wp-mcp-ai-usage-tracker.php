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

		if ( 0 === $total_tokens ) {
			$total_tokens = $prompt_tokens + $completion_tokens;
		}

		if ( 0 === $prompt_tokens && 0 === $completion_tokens && 0 === $total_tokens ) {
			return array();
		}

		return array(
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'total_tokens'      => $total_tokens,
		);
	}

	/**
	 * Provide the default structure for model-level totals.
	 *
	 * @return array
	 */
	protected static function get_initial_model_totals() {
		return array(
			'requests'         => 0,
			'prompt_tokens'    => 0,
			'completion_tokens'=> 0,
			'total_tokens'     => 0,
			'last_used_gmt'    => '',
			'assistants'       => array(),
		);
	}

	/**
	 * Provide the default structure for assistant-level totals.
	 *
	 * @return array
	 */
	protected static function get_initial_assistant_totals() {
		return array(
			'requests'      => 0,
			'prompt_tokens' => 0,
			'completion_tokens' => 0,
			'total_tokens'  => 0,
			'last_used_gmt' => '',
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

		$existing_totals['requests'] = (int) $existing_totals['requests'] + 1;
		$existing_totals['prompt_tokens'] = isset( $existing_totals['prompt_tokens'] ) ? (int) $existing_totals['prompt_tokens'] : 0;
		$existing_totals['completion_tokens'] = isset( $existing_totals['completion_tokens'] ) ? (int) $existing_totals['completion_tokens'] : 0;
		$existing_totals['total_tokens'] = isset( $existing_totals['total_tokens'] ) ? (int) $existing_totals['total_tokens'] : 0;

		$existing_totals['prompt_tokens']    += $usage['prompt_tokens'];
		$existing_totals['completion_tokens'] += $usage['completion_tokens'];
		$existing_totals['total_tokens']     += $usage['total_tokens'];
		$existing_totals['last_used_gmt']     = $timestamp;

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

			$assistant_totals['requests'] = (int) $assistant_totals['requests'] + 1;
			$assistant_totals['prompt_tokens'] = isset( $assistant_totals['prompt_tokens'] ) ? (int) $assistant_totals['prompt_tokens'] : 0;
			$assistant_totals['completion_tokens'] = isset( $assistant_totals['completion_tokens'] ) ? (int) $assistant_totals['completion_tokens'] : 0;
			$assistant_totals['total_tokens'] = isset( $assistant_totals['total_tokens'] ) ? (int) $assistant_totals['total_tokens'] : 0;

			$assistant_totals['prompt_tokens']    += $usage['prompt_tokens'];
			$assistant_totals['completion_tokens'] += $usage['completion_tokens'];
			$assistant_totals['total_tokens']     += $usage['total_tokens'];
			$assistant_totals['last_used_gmt']     = $timestamp;

			$existing_totals['assistants'][ $assistant_id ] = $assistant_totals;
		}

		return $existing_totals;
	}
}
