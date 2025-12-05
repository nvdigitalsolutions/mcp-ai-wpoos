<?php
/**
 * Model selector for intelligent routing between models.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intelligently selects between gpt-4o-mini and gpt-4o based on task complexity.
 */
class WP_MCP_AI_Model_Selector {

	/**
	 * Token threshold for considering a task "long-form".
	 * Tasks with more input tokens than this will use gpt-4o.
	 */
	const LONG_FORM_TOKEN_THRESHOLD = 4000;

	/**
	 * Keywords that indicate complex tasks requiring gpt-4o.
	 *
	 * @var array
	 */
	protected static $complex_keywords = array(
		'analyze',
		'detailed analysis',
		'comprehensive',
		'in-depth',
		'thorough',
		'research',
		'complex',
		'sophisticated',
		'advanced',
		'expert',
		'professional',
		'write a long',
		'write an article',
		'write a detailed',
		'create a comprehensive',
	);

	/**
	 * Select the appropriate model based on task complexity.
	 *
	 * @param array  $messages   Messages array.
	 * @param array  $options    Request options.
	 * @param string $base_model Default/base model if specified.
	 *
	 * @return string Selected model identifier.
	 */
	public static function select_model( array $messages, array $options = array(), $base_model = '' ) {
		// If a specific model is already set and it's not a routing placeholder, use it.
		if ( ! empty( $options['model'] ) && ! self::is_routing_placeholder( $options['model'] ) ) {
			$model = sanitize_text_field( $options['model'] );

			// Check if the selected model can handle the token requirements.
			$fallback = self::check_tpm_and_suggest_fallback( $messages, $model, $options );
			if ( $fallback !== $model ) {
				return $fallback;
			}

			return $model;
		}

		// If base_model is provided and not a routing placeholder, use it.
		if ( ! empty( $base_model ) && ! self::is_routing_placeholder( $base_model ) ) {
			$model = sanitize_text_field( $base_model );

			// Check if the base model can handle the token requirements.
			$fallback = self::check_tpm_and_suggest_fallback( $messages, $model, $options );
			if ( $fallback !== $model ) {
				return $fallback;
			}

			return $model;
		}

		// Check if auto-routing is disabled.
		if ( isset( $options['disable_auto_routing'] ) && $options['disable_auto_routing'] ) {
			$model    = self::get_default_light_model();
			$fallback = self::check_tpm_and_suggest_fallback( $messages, $model, $options );
			return $fallback !== $model ? $fallback : $model;
		}

		// Determine complexity based on various factors.
		$is_complex = self::is_complex_task( $messages, $options );

		if ( $is_complex ) {
			$model = 'gpt-4o';

			// Check if gpt-4o can handle the token requirements.
			$fallback = self::check_tpm_and_suggest_fallback( $messages, $model, $options );
			if ( $fallback !== $model ) {
				WP_MCP_AI_Logger::log_event(
					'model_routing_fallback',
					'Routing to alternative model due to TPM constraints.',
					array(
						'original_model' => $model,
						'fallback_model' => $fallback,
						'reason'         => 'tpm_limit_exceeded',
					)
				);
				return $fallback;
			}

			WP_MCP_AI_Logger::log_event(
				'model_routing_complex',
				'Routing to gpt-4o for complex/long-form task.',
				array(
					'reason' => self::get_complexity_reason( $messages, $options ),
				)
			);
			return $model;
		}

		$model    = self::get_default_light_model();
		$fallback = self::check_tpm_and_suggest_fallback( $messages, $model, $options );

		if ( $fallback !== $model ) {
			WP_MCP_AI_Logger::log_event(
				'model_routing_fallback',
				'Routing to alternative model due to TPM constraints.',
				array(
					'original_model' => $model,
					'fallback_model' => $fallback,
					'reason'         => 'tpm_limit_exceeded',
				)
			);
			return $fallback;
		}

		WP_MCP_AI_Logger::log_event(
			'model_routing_light',
			'Routing to gpt-4o-mini for light task.',
			array()
		);

		return $model;
	}

	/**
	 * Check if a model can handle the token requirements and suggest a fallback if needed.
	 *
	 * @param array  $messages Messages array.
	 * @param string $model    Model identifier.
	 * @param array  $options  Request options.
	 *
	 * @return string Model identifier (original or fallback).
	 */
	protected static function check_tpm_and_suggest_fallback( array $messages, $model, array $options ) {
		// Get the TPM limit for the model.
		$tpm_limit = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( $model );

		// If no TPM limit is configured, return the original model (e.g., local models).
		if ( null === $tpm_limit || 0 === $tpm_limit ) {
			return $model;
		}

		// Calculate token budget.
		$max_output_tokens = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 0;
		$budget            = WP_MCP_AI_Token_Budget_Manager::calculate_budget( $model, $messages, $max_output_tokens );
		$total_tokens      = $budget['used'] + $budget['reserved'];

		// If within limits, return the original model.
		if ( $total_tokens <= $tpm_limit ) {
			return $model;
		}

		// Token requirement exceeds TPM limit - suggest a fallback.
		WP_MCP_AI_Logger::log_event(
			'model_tpm_exceeded',
			'Model TPM limit would be exceeded. Checking for fallback options.',
			array(
				'model'           => $model,
				'tpm_limit'       => $tpm_limit,
				'required_tokens' => $total_tokens,
				'input_tokens'    => $budget['used'],
				'reserved_output' => $budget['reserved'],
			)
		);

		// Check if auto-switching to high-capacity model is enabled.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		if ( ! empty( $settings['enable_high_token_model_switch'] ) ) {
			// First, try to get per-model fallback from settings.
			$high_capacity_model = null;
			$fallback_source     = 'global';

			if ( ! empty( $settings['per_model_fallback'][ $model ] ) ) {
				$high_capacity_model = sanitize_text_field( $settings['per_model_fallback'][ $model ] );
				$fallback_source     = 'settings_per_model';
			}

			// Second, try to get per-model fallback from CCT if available.
			if ( empty( $high_capacity_model ) && class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
				$cct_fallback = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_fallback( $model );
				if ( ! empty( $cct_fallback ) ) {
					$high_capacity_model = $cct_fallback;
					$fallback_source     = 'cct_per_model';
				}
			}

			// Fall back to global setting if no per-model fallback is configured.
			if ( empty( $high_capacity_model ) && ! empty( $settings['high_token_fallback_model'] ) ) {
				$high_capacity_model = sanitize_text_field( $settings['high_token_fallback_model'] );
				$fallback_source     = 'global';
			}

			// If we have a fallback model, verify it can handle the token requirement.
			if ( ! empty( $high_capacity_model ) ) {
				// Verify the high-capacity model can handle the token requirement.
				$fallback_tpm_limit = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( $high_capacity_model );

				// If the high-capacity model has no limit or a higher limit, use it.
				if ( null === $fallback_tpm_limit || 0 === $fallback_tpm_limit || $total_tokens <= $fallback_tpm_limit ) {
					WP_MCP_AI_Logger::log_event(
						'model_switched_to_high_capacity',
						'Automatically switched to configured high-capacity fallback model.',
						array(
							'original_model'     => $model,
							'fallback_model'     => $high_capacity_model,
							'required_tokens'    => $total_tokens,
							'fallback_tpm_limit' => $fallback_tpm_limit,
							'fallback_source'    => $fallback_source,
						)
					);
					return $high_capacity_model;
				}
			}
		}

		// Determine the provider and suggest an appropriate fallback.
		$model_lower = strtolower( $model );

		// OpenAI models - try gpt-4o if currently on gpt-4o-mini, or high-capacity Gemini for very large requests.
		if ( false !== strpos( $model_lower, 'gpt' ) || false !== strpos( $model_lower, 'o1' ) ) {
			// If on gpt-4o-mini and request is too large, try gpt-4o.
			if ( false !== strpos( $model_lower, 'mini' ) && $total_tokens <= 30000 ) {
				return 'gpt-4o';
			}

			// For very large requests (> 30k tokens), fallback to configured high-capacity model or Gemini.
			if ( $total_tokens > 30000 ) {
				return self::get_high_capacity_fallback_model( $model );
			}

			// Otherwise, fallback to gpt-4o.
			return 'gpt-4o';
		}

		// Gemini models already have high TPM limits (1M), unlikely to hit this.
		if ( false !== strpos( $model_lower, 'gemini' ) ) {
			// Fallback to configured high-capacity model or gemini-1.5-pro for very large requests.
			if ( $total_tokens > 1000000 ) {
				// Even Gemini can't handle this - log error but return high-capacity fallback.
				WP_MCP_AI_Logger::log_error(
					'Request too large even for Gemini.',
					array(
						'required_tokens' => $total_tokens,
						'tpm_limit'       => 1000000,
					)
				);
			}

			return self::get_high_capacity_fallback_model( $model );
		}

		// Claude models - try higher tier or fallback to high-capacity model.
		if ( false !== strpos( $model_lower, 'claude' ) ) {
			// If on claude-3-haiku or claude-3.5-sonnet and request is too large, try high-capacity model.
			if ( $total_tokens > 50000 ) {
				return self::get_high_capacity_fallback_model( $model );
			}

			// Otherwise, try claude-3-haiku.
			return 'claude-3-haiku';
		}

		// Default fallback for unknown models - use configured high-capacity model.
		return self::get_high_capacity_fallback_model( $model );
	}

	/**
	 * Get the configured high-capacity fallback model.
	 *
	 * Returns the model configured in settings for handling high token volumes,
	 * or a sensible default if not configured. Checks per-model settings first,
	 * then falls back to global settings.
	 *
	 * @param string $original_model Optional. The model that needs a fallback.
	 * @return string Model identifier for high-capacity fallback.
	 */
	protected static function get_high_capacity_fallback_model( $original_model = '' ) {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// First, try to get per-model fallback from settings if a model is specified.
		if ( ! empty( $original_model ) && ! empty( $settings['per_model_fallback'][ $original_model ] ) ) {
			return sanitize_text_field( $settings['per_model_fallback'][ $original_model ] );
		}

		// Second, try to get per-model fallback from CCT if available.
		if ( ! empty( $original_model ) && class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			$per_model_fallback = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_fallback( $original_model );
			if ( ! empty( $per_model_fallback ) ) {
				return sanitize_text_field( $per_model_fallback );
			}
		}

		// Fall back to global settings.
		// Use configured high-capacity fallback model if available.
		if ( ! empty( $settings['high_token_fallback_model'] ) ) {
			return sanitize_text_field( $settings['high_token_fallback_model'] );
		}

		// Default to gemini-2.5-flash which has high token capacity.
		return 'gemini-2.5-flash';
	}

	/**
	 * Check if the provided model is a routing placeholder.
	 *
	 * @param string $model Model identifier.
	 *
	 * @return bool True if it's a routing placeholder.
	 */
	protected static function is_routing_placeholder( $model ) {
		$model            = strtolower( sanitize_text_field( $model ) );
		$routing_keywords = array( 'auto', 'smart', 'intelligent', 'adaptive' );

		foreach ( $routing_keywords as $keyword ) {
			if ( false !== strpos( $model, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine if a task is complex and requires gpt-4o.
	 *
	 * @param array $messages Messages array.
	 * @param array $options  Request options.
	 *
	 * @return bool True if task is complex.
	 */
	protected static function is_complex_task( array $messages, array $options ) {
		// Check token count - long-form content needs gpt-4o.
		$token_count = WP_MCP_AI_Token_Budget_Manager::estimate_tokens(
			wp_json_encode( $messages )
		);

		if ( $token_count > self::LONG_FORM_TOKEN_THRESHOLD ) {
			return true;
		}

		// Check for explicit complexity flag.
		if ( isset( $options['use_advanced_model'] ) && $options['use_advanced_model'] ) {
			return true;
		}

		// Check for complex keywords in the latest user message.
		$latest_user_message = self::get_latest_user_message( $messages );
		if ( $latest_user_message && self::contains_complex_keywords( $latest_user_message ) ) {
			return true;
		}

		// Check if tools are involved (may indicate complex workflow).
		if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) && count( $options['tools'] ) > 3 ) {
			return true;
		}

		// Check if response_format is structured (indicates complex output).
		if ( ! empty( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the latest user message from messages array.
	 *
	 * @param array $messages Messages array.
	 *
	 * @return string|null Latest user message content or null.
	 */
	protected static function get_latest_user_message( array $messages ) {
		// Iterate in reverse to find the most recent user message.
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$message = $messages[ $i ];

			if ( ! is_array( $message ) || ! isset( $message['role'] ) ) {
				continue;
			}

			if ( 'user' === $message['role'] && isset( $message['content'] ) ) {
				if ( is_string( $message['content'] ) ) {
					return $message['content'];
				}

				if ( is_array( $message['content'] ) ) {
					$text_parts = array();
					foreach ( $message['content'] as $segment ) {
						if ( is_array( $segment ) && isset( $segment['text'] ) ) {
							$text_parts[] = $segment['text'];
						}
					}
					return implode( ' ', $text_parts );
				}
			}
		}

		return null;
	}

	/**
	 * Check if message contains complex task keywords.
	 *
	 * @param string $message Message content.
	 *
	 * @return bool True if contains complex keywords.
	 */
	protected static function contains_complex_keywords( $message ) {
		$message = strtolower( $message );

		foreach ( self::$complex_keywords as $keyword ) {
			if ( false !== strpos( $message, strtolower( $keyword ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get reason for complexity determination (for logging).
	 *
	 * @param array $messages Messages array.
	 * @param array $options  Request options.
	 *
	 * @return string Reason description.
	 */
	protected static function get_complexity_reason( array $messages, array $options ) {
		$token_count = WP_MCP_AI_Token_Budget_Manager::estimate_tokens(
			wp_json_encode( $messages )
		);

		if ( $token_count > self::LONG_FORM_TOKEN_THRESHOLD ) {
			return sprintf( 'high_token_count (%d tokens)', $token_count );
		}

		if ( isset( $options['use_advanced_model'] ) && $options['use_advanced_model'] ) {
			return 'explicit_advanced_flag';
		}

		$latest_user_message = self::get_latest_user_message( $messages );
		if ( $latest_user_message && self::contains_complex_keywords( $latest_user_message ) ) {
			return 'complex_keywords_detected';
		}

		if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) && count( $options['tools'] ) > 3 ) {
			return sprintf( 'multiple_tools (%d tools)', count( $options['tools'] ) );
		}

		if ( ! empty( $options['response_format'] ) ) {
			return 'structured_output_requested';
		}

		return 'unknown';
	}

	/**
	 * Get default light model for simple tasks.
	 *
	 * @return string Model identifier.
	 */
	protected static function get_default_light_model() {
		/**
		 * Filter the default light model.
		 *
		 * @param string $model Default light model identifier.
		 */
		return apply_filters( 'wp_mcp_ai_default_light_model', 'gpt-4.1-mini' );
	}

	/**
	 * Get default advanced model for complex tasks.
	 *
	 * @return string Model identifier.
	 */
	protected static function get_default_advanced_model() {
		/**
		 * Filter the default advanced model.
		 *
		 * @param string $model Default advanced model identifier.
		 */
		return apply_filters( 'wp_mcp_ai_default_advanced_model', 'gpt-4.1' );
	}
}
