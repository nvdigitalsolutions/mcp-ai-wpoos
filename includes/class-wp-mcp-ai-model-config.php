<?php
/**
 * Manages per-model configuration and settings.
 *
 * This class stores model configuration primarily in WordPress options
 * with optional JetEngine CCT backup for enhanced queryability.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages model configurations for the orchestration layer.
 *
 * Stores:
 * - Rate limits (TPM, RPM, TPD, RPD)
 * - Context window sizes
 * - Fallback models
 * - Provider settings
 * - Cost per token
 * - Status (active/disabled)
 */
class WP_MCP_AI_Model_Config {

	/**
	 * Option name for storing model configurations.
	 */
	const CONFIGS_OPTION = 'wp_mcp_ai_model_configs';

	/**
	 * CCT slug for JetEngine integration.
	 */
	const CCT_SLUG = 'ai_model_configs';

	/**
	 * Cache group for model configs.
	 */
	const CACHE_GROUP = 'wp_mcp_ai_model_configs';

	/**
	 * Initialize the model config system.
	 */
	public static function init() {
		// Sync to CCT when JetEngine is available.
		add_action( 'wp_mcp_ai_model_config_updated', array( __CLASS__, 'sync_to_cct' ), 10, 2 );
	}

	/**
	 * Get all model configurations.
	 *
	 * @return array Array of model configurations keyed by model identifier.
	 */
	public static function get_all_configs() {
		// Try cache first.
		$configs = wp_cache_get( 'all_configs', self::CACHE_GROUP );

		if ( false !== $configs ) {
			return $configs;
		}

		// Get from options (primary storage).
		$configs = get_option( self::CONFIGS_OPTION, array() );

		if ( ! is_array( $configs ) ) {
			$configs = array();
		}

		// Merge with defaults for known models.
		$configs = self::merge_with_defaults( $configs );

		/**
		 * Filter all model configurations.
		 *
		 * @since 1.0.0
		 *
		 * @param array $configs Model configurations.
		 */
		$configs = apply_filters( 'wp_mcp_ai_all_model_configs', $configs );

		// Cache for 5 minutes.
		wp_cache_set( 'all_configs', $configs, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $configs;
	}

	/**
	 * Get configuration for a specific model.
	 *
	 * @param string $model Model identifier.
	 * @return array|null Model configuration or null if not found.
	 */
	public static function get_model_config( $model ) {
		$model = sanitize_text_field( $model );

		if ( empty( $model ) ) {
			return null;
		}

		// Try cache first.
		$cache_key = 'model_' . md5( $model );
		$config    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $config ) {
			return $config;
		}

		$all_configs = self::get_all_configs();

		// Exact match first.
		if ( isset( $all_configs[ $model ] ) ) {
			$config = $all_configs[ $model ];
			wp_cache_set( $cache_key, $config, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
			return $config;
		}

		// Try prefix match for model families (longest match wins).
		$best_match        = null;
		$best_match_length = 0;

		foreach ( $all_configs as $model_key => $model_config ) {
			if ( 0 === strpos( $model, $model_key ) ) {
				$match_length = strlen( $model_key );
				if ( $match_length > $best_match_length ) {
					$best_match        = $model_config;
					$best_match_length = $match_length;
				}
			}
		}

		if ( $best_match ) {
			wp_cache_set( $cache_key, $best_match, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
			return $best_match;
		}

		// Fallback to CCT if available.
		if ( class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			$cct_data = WP_MCP_AI_Model_Rate_Limits_CCT::get_model_limits( $model, false );
			if ( $cct_data ) {
				$config = self::convert_cct_to_config( $cct_data );
				wp_cache_set( $cache_key, $config, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
				return $config;
			}
		}

		return null;
	}

	/**
	 * Set configuration for a specific model.
	 *
	 * @param string $model  Model identifier.
	 * @param array  $config Configuration array.
	 * @return bool True on success, false on failure.
	 */
	public static function set_model_config( $model, $config ) {
		$model = sanitize_text_field( $model );

		if ( empty( $model ) || ! is_array( $config ) ) {
			return false;
		}

		// Get all configs.
		$all_configs = get_option( self::CONFIGS_OPTION, array() );

		if ( ! is_array( $all_configs ) ) {
			$all_configs = array();
		}

		// Sanitize config.
		$config = self::sanitize_config( $config );

		// Update config.
		$all_configs[ $model ] = $config;

		// Save to options (primary storage).
		$result = update_option( self::CONFIGS_OPTION, $all_configs, false );

		if ( $result ) {
			// Clear cache.
			wp_cache_delete( 'all_configs', self::CACHE_GROUP );
			wp_cache_delete( 'model_' . md5( $model ), self::CACHE_GROUP );

			/**
			 * Fires after a model configuration is updated.
			 *
			 * @since 1.0.0
			 *
			 * @param string $model  Model identifier.
			 * @param array  $config Model configuration.
			 */
			do_action( 'wp_mcp_ai_model_config_updated', $model, $config );
		}

		return $result;
	}

	/**
	 * Delete configuration for a specific model.
	 *
	 * @param string $model Model identifier.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_model_config( $model ) {
		$model = sanitize_text_field( $model );

		if ( empty( $model ) ) {
			return false;
		}

		$all_configs = get_option( self::CONFIGS_OPTION, array() );

		if ( ! is_array( $all_configs ) || ! isset( $all_configs[ $model ] ) ) {
			return false;
		}

		unset( $all_configs[ $model ] );

		$result = update_option( self::CONFIGS_OPTION, $all_configs, false );

		if ( $result ) {
			// Clear cache.
			wp_cache_delete( 'all_configs', self::CACHE_GROUP );
			wp_cache_delete( 'model_' . md5( $model ), self::CACHE_GROUP );
		}

		return $result;
	}

	/**
	 * Sync model configuration to JetEngine CCT (if available).
	 *
	 * @param string $model  Model identifier.
	 * @param array  $config Model configuration.
	 */
	public static function sync_to_cct( $model, $config ) {
		// Only sync if JetEngine and the model rate limits CCT are available.
		if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			return;
		}

		// This is a future enhancement - for now, we rely on the existing CCT structure.
		// The Model_Rate_Limits_CCT class already handles rate limits.
		// This method is a placeholder for future bidirectional sync.
	}

	/**
	 * Get default configurations for known models.
	 *
	 * @return array Default model configurations.
	 */
	protected static function get_default_configs() {
		return array(
			// OpenAI Models (November 2025).
			// GPT-5 series: Flagship models (multimodal - vision capable) - 2025.
			'gpt-5.1'              => array(
				'name'           => 'GPT-5.1 (Flagship)',
				'provider'       => 'openai',
				'tpm'            => 100000,
				'rpm'            => 1000,
				'tpd'            => 10000000,
				'rpd'            => 50000,
				'context_window' => 200000,
				'fallback_model' => 'gpt-5',
				'cost_per_1k'    => 0.01,
				'status'         => 'active',
			),
			'gpt-5.1-2025-11-13'   => array(
				'name'           => 'GPT-5.1 (Nov 2025)',
				'provider'       => 'openai',
				'tpm'            => 100000,
				'rpm'            => 1000,
				'tpd'            => 10000000,
				'rpd'            => 50000,
				'context_window' => 200000,
				'fallback_model' => 'gpt-5.1',
				'cost_per_1k'    => 0.01,
				'status'         => 'active',
			),
			'gpt-5'                => array(
				'name'           => 'GPT-5',
				'provider'       => 'openai',
				'tpm'            => 80000,
				'rpm'            => 800,
				'tpd'            => 8000000,
				'rpd'            => 40000,
				'context_window' => 200000,
				'fallback_model' => 'gpt-5-mini',
				'cost_per_1k'    => 0.008,
				'status'         => 'active',
			),
			'gpt-5-2025-08-07'     => array(
				'name'           => 'GPT-5 (Aug 2025)',
				'provider'       => 'openai',
				'tpm'            => 80000,
				'rpm'            => 800,
				'tpd'            => 8000000,
				'rpd'            => 40000,
				'context_window' => 200000,
				'fallback_model' => 'gpt-5',
				'cost_per_1k'    => 0.008,
				'status'         => 'active',
			),
			'gpt-5-mini'           => array(
				'name'           => 'GPT-5 Mini',
				'provider'       => 'openai',
				'tpm'            => 150000,
				'rpm'            => 1500,
				'tpd'            => 15000000,
				'rpd'            => 75000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-4o-mini',
				'cost_per_1k'    => 0.002,
				'status'         => 'active',
			),
			'gpt-5-nano'           => array(
				'name'           => 'GPT-5 Nano',
				'provider'       => 'openai',
				'tpm'            => 200000,
				'rpm'            => 2000,
				'tpd'            => 20000000,
				'rpd'            => 100000,
				'context_window' => 64000,
				'fallback_model' => 'gpt-5-mini',
				'cost_per_1k'    => 0.0005,
				'status'         => 'active',
			),
			'gpt-5-pro'            => array(
				'name'           => 'GPT-5 Pro',
				'provider'       => 'openai',
				'tpm'            => 50000,
				'rpm'            => 500,
				'tpd'            => 5000000,
				'rpd'            => 25000,
				'context_window' => 256000,
				'fallback_model' => 'gpt-5.1',
				'cost_per_1k'    => 0.02,
				'status'         => 'active',
			),
			// GPT-5 Codex variants (coding-optimized, text-only).
			'gpt-5-codex'          => array(
				'name'           => 'GPT-5 Codex',
				'provider'       => 'openai',
				'tpm'            => 100000,
				'rpm'            => 1000,
				'tpd'            => 10000000,
				'rpd'            => 50000,
				'context_window' => 200000,
				'fallback_model' => 'gpt-5-codex-mini',
				'cost_per_1k'    => 0.008,
				'status'         => 'active',
			),
			'gpt-5-codex-mini'     => array(
				'name'           => 'GPT-5 Codex Mini',
				'provider'       => 'openai',
				'tpm'            => 150000,
				'rpm'            => 1500,
				'tpd'            => 15000000,
				'rpd'            => 75000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-4o-mini',
				'cost_per_1k'    => 0.002,
				'status'         => 'active',
			),
			// GPT-4o series: Multimodal models.
			'gpt-4o'               => array(
				'name'           => 'GPT-4o',
				'provider'       => 'openai',
				'tpm'            => 30000,
				'rpm'            => 500,
				'tpd'            => 2000000,
				'rpd'            => 10000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-4o-mini',
				'cost_per_1k'    => 0.005,
				'status'         => 'active',
			),
			'gpt-4o-mini'          => array(
				'name'           => 'GPT-4o Mini',
				'provider'       => 'openai',
				'tpm'            => 200000,
				'rpm'            => 500,
				'tpd'            => 10000000,
				'rpd'            => 10000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-3.5-turbo',
				'cost_per_1k'    => 0.00015,
				'status'         => 'active',
			),
			'gpt-4o-2024-11-20'    => array(
				'name'           => 'GPT-4o (Nov 2024)',
				'provider'       => 'openai',
				'tpm'            => 30000,
				'rpm'            => 500,
				'tpd'            => 2000000,
				'rpd'            => 10000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-4o',
				'cost_per_1k'    => 0.005,
				'status'         => 'active',
			),
			'gpt-4o-2024-08-06'    => array(
				'name'           => 'GPT-4o (Aug 2024)',
				'provider'       => 'openai',
				'tpm'            => 30000,
				'rpm'            => 500,
				'tpd'            => 2000000,
				'rpd'            => 10000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-4o',
				'cost_per_1k'    => 0.005,
				'status'         => 'active',
			),
			'gpt-4o-2024-05-13'    => array(
				'name'           => 'GPT-4o (May 2024)',
				'provider'       => 'openai',
				'tpm'            => 30000,
				'rpm'            => 500,
				'tpd'            => 2000000,
				'rpd'            => 10000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-4o',
				'cost_per_1k'    => 0.005,
				'status'         => 'active',
			),
			'chatgpt-4o-latest'    => array(
				'name'           => 'ChatGPT-4o (Latest)',
				'provider'       => 'openai',
				'tpm'            => 30000,
				'rpm'            => 500,
				'tpd'            => 2000000,
				'rpd'            => 10000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-4o',
				'cost_per_1k'    => 0.005,
				'status'         => 'active',
			),
			// Legacy models (text-only).
			'gpt-4-turbo'          => array(
				'name'           => 'GPT-4 Turbo (Legacy)',
				'provider'       => 'openai',
				'tpm'            => 30000,
				'rpm'            => 500,
				'tpd'            => 2000000,
				'rpd'            => 10000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-4o',
				'cost_per_1k'    => 0.01,
				'status'         => 'active',
			),
			'gpt-4'                => array(
				'name'           => 'GPT-4 (Legacy)',
				'provider'       => 'openai',
				'tpm'            => 10000,
				'rpm'            => 500,
				'tpd'            => 1000000,
				'rpd'            => 10000,
				'context_window' => 8192,
				'fallback_model' => 'gpt-4-turbo',
				'cost_per_1k'    => 0.03,
				'status'         => 'active',
			),
			'gpt-3.5-turbo'        => array(
				'name'           => 'GPT-3.5 Turbo (Legacy)',
				'provider'       => 'openai',
				'tpm'            => 60000,
				'rpm'            => 3500,
				'tpd'            => 5000000,
				'rpd'            => 10000,
				'context_window' => 16385,
				'fallback_model' => null,
				'cost_per_1k'    => 0.0005,
				'status'         => 'active',
			),
			// Deprecated models (Nov 2025) - kept for backward compatibility.
			'o3'                   => array(
				'name'           => 'o3 (Deprecated - use GPT-5)',
				'provider'       => 'openai',
				'tpm'            => 30000,
				'rpm'            => 500,
				'tpd'            => 2000000,
				'rpd'            => 10000,
				'context_window' => 200000,
				'fallback_model' => 'gpt-5',
				'cost_per_1k'    => 0.015,
				'status'         => 'deprecated',
			),
			'o3-mini'              => array(
				'name'           => 'o3 Mini (Deprecated - use GPT-5 Mini)',
				'provider'       => 'openai',
				'tpm'            => 80000,
				'rpm'            => 800,
				'tpd'            => 5000000,
				'rpd'            => 20000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-5-mini',
				'cost_per_1k'    => 0.003,
				'status'         => 'deprecated',
			),
			'o4-mini'              => array(
				'name'           => 'o4 Mini (Deprecated - use GPT-5 Mini)',
				'provider'       => 'openai',
				'tpm'            => 100000,
				'rpm'            => 1000,
				'tpd'            => 6000000,
				'rpd'            => 25000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-5-mini',
				'cost_per_1k'    => 0.002,
				'status'         => 'deprecated',
			),
			'o1'                   => array(
				'name'           => 'o1 (Deprecated - use GPT-5)',
				'provider'       => 'openai',
				'tpm'            => 30000,
				'rpm'            => 500,
				'tpd'            => 2000000,
				'rpd'            => 10000,
				'context_window' => 200000,
				'fallback_model' => 'gpt-5',
				'cost_per_1k'    => 0.015,
				'status'         => 'deprecated',
			),
			'o1-preview'           => array(
				'name'           => 'o1 Preview (Deprecated - use GPT-5)',
				'provider'       => 'openai',
				'tpm'            => 30000,
				'rpm'            => 500,
				'tpd'            => 2000000,
				'rpd'            => 10000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-5',
				'cost_per_1k'    => 0.015,
				'status'         => 'deprecated',
			),
			'o1-mini'              => array(
				'name'           => 'o1 Mini (Deprecated - use GPT-5 Mini)',
				'provider'       => 'openai',
				'tpm'            => 80000,
				'rpm'            => 800,
				'tpd'            => 5000000,
				'rpd'            => 20000,
				'context_window' => 128000,
				'fallback_model' => 'gpt-5-mini',
				'cost_per_1k'    => 0.003,
				'status'         => 'deprecated',
			),

			// Anthropic Models (November 2025).
			// Claude 4 series (multimodal - vision capable) - 2025.
			'claude-sonnet-4.5'         => array(
				'name'           => 'Claude Sonnet 4.5 (Recommended)',
				'provider'       => 'anthropic',
				'tpm'            => 80000,
				'rpm'            => 1000,
				'tpd'            => 5000000,
				'rpd'            => 50000,
				'context_window' => 200000,
				'fallback_model' => 'claude-haiku-4.5',
				'cost_per_1k'    => 0.003,
				'status'         => 'active',
			),
			'claude-sonnet-4-5-20250929' => array(
				'name'           => 'Claude Sonnet 4.5 (Sep 2025)',
				'provider'       => 'anthropic',
				'tpm'            => 80000,
				'rpm'            => 1000,
				'tpd'            => 5000000,
				'rpd'            => 50000,
				'context_window' => 200000,
				'fallback_model' => 'claude-sonnet-4.5',
				'cost_per_1k'    => 0.003,
				'status'         => 'active',
			),
			'claude-haiku-4.5'          => array(
				'name'           => 'Claude Haiku 4.5 (Fastest)',
				'provider'       => 'anthropic',
				'tpm'            => 100000,
				'rpm'            => 2000,
				'tpd'            => 8000000,
				'rpd'            => 100000,
				'context_window' => 200000,
				'fallback_model' => null,
				'cost_per_1k'    => 0.001,
				'status'         => 'active',
			),
			'claude-opus-4.1'           => array(
				'name'           => 'Claude Opus 4.1 (Flagship)',
				'provider'       => 'anthropic',
				'tpm'            => 40000,
				'rpm'            => 1000,
				'tpd'            => 3000000,
				'rpd'            => 50000,
				'context_window' => 200000,
				'fallback_model' => 'claude-sonnet-4.5',
				'cost_per_1k'    => 0.015,
				'status'         => 'active',
			),
			'claude-opus-4.0'           => array(
				'name'           => 'Claude Opus 4.0',
				'provider'       => 'anthropic',
				'tpm'            => 40000,
				'rpm'            => 1000,
				'tpd'            => 3000000,
				'rpd'            => 50000,
				'context_window' => 200000,
				'fallback_model' => 'claude-opus-4.1',
				'cost_per_1k'    => 0.015,
				'status'         => 'active',
			),
			// Claude 3.5 series (legacy - for backward compatibility).
			'claude-3-5-sonnet-20241022' => array(
				'name'           => 'Claude 3.5 Sonnet (Legacy)',
				'provider'       => 'anthropic',
				'tpm'            => 80000,
				'rpm'            => 1000,
				'tpd'            => 5000000,
				'rpd'            => 50000,
				'context_window' => 200000,
				'fallback_model' => 'claude-sonnet-4.5',
				'cost_per_1k'    => 0.003,
				'status'         => 'active',
			),
			'claude-3-5-haiku-20241022'  => array(
				'name'           => 'Claude 3.5 Haiku (Legacy)',
				'provider'       => 'anthropic',
				'tpm'            => 100000,
				'rpm'            => 2000,
				'tpd'            => 8000000,
				'rpd'            => 100000,
				'context_window' => 200000,
				'fallback_model' => 'claude-haiku-4.5',
				'cost_per_1k'    => 0.001,
				'status'         => 'active',
			),
			'claude-3-opus-20240229'    => array(
				'name'           => 'Claude 3 Opus (Legacy)',
				'provider'       => 'anthropic',
				'tpm'            => 40000,
				'rpm'            => 1000,
				'tpd'            => 3000000,
				'rpd'            => 50000,
				'context_window' => 200000,
				'fallback_model' => 'claude-opus-4.1',
				'cost_per_1k'    => 0.015,
				'status'         => 'active',
			),

			// Google Gemini Models (November 2025).
			// Gemini 3 series (multimodal - latest generation) - Preview.
			'gemini-3-pro-preview'   => array(
				'name'           => 'Gemini 3 Pro (Preview)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 360,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 2000000,
				'fallback_model' => 'gemini-2.5-pro',
				'cost_per_1k'    => 0.001,
				'status'         => 'active',
			),
			// Gemini 2.5 series (multimodal - text, image, video) - Stable.
			'gemini-2.5-pro'         => array(
				'name'           => 'Gemini 2.5 Pro',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 360,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 2000000,
				'fallback_model' => 'gemini-2.5-flash',
				'cost_per_1k'    => 0.003,
				'status'         => 'active',
			),
			'gemini-2.5-flash'       => array(
				'name'           => 'Gemini 2.5 Flash (Recommended)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 1000000,
				'fallback_model' => null,
				'cost_per_1k'    => 0.0001,
				'status'         => 'active',
			),
			'gemini-2.5-flash-lite'  => array(
				'name'           => 'Gemini 2.5 Flash Lite',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 2000,
				'context_window' => 1000000,
				'fallback_model' => 'gemini-2.5-flash',
				'cost_per_1k'    => 0.00005,
				'status'         => 'active',
			),
			'gemini-2.5-flash-preview-09-2025' => array(
				'name'           => 'Gemini 2.5 Flash (Sep 2025 Preview)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 1000000,
				'fallback_model' => 'gemini-2.5-flash',
				'cost_per_1k'    => 0.0001,
				'status'         => 'active',
			),
			// Gemini 2.5 specialized models.
			'gemini-live-2.5-flash-preview' => array(
				'name'           => 'Gemini Live 2.5 Flash (Voice/Multimodal)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 1000000,
				'fallback_model' => 'gemini-2.5-flash',
				'cost_per_1k'    => 0.0001,
				'status'         => 'active',
			),
			'gemini-2.5-flash-preview-native-audio-dialog' => array(
				'name'           => 'Gemini 2.5 Native Audio Dialog',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 1000000,
				'fallback_model' => 'gemini-2.5-flash',
				'cost_per_1k'    => 0.0001,
				'status'         => 'active',
			),
			'gemini-2.5-flash-preview-tts' => array(
				'name'           => 'Gemini 2.5 Flash TTS',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 1000000,
				'fallback_model' => 'gemini-2.5-flash',
				'cost_per_1k'    => 0.0001,
				'status'         => 'active',
			),
			'gemini-2.5-pro-preview-tts'   => array(
				'name'           => 'Gemini 2.5 Pro TTS',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 360,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 2000000,
				'fallback_model' => 'gemini-2.5-pro',
				'cost_per_1k'    => 0.00125,
				'status'         => 'active',
			),
			'gemini-2.5-flash-image' => array(
				'name'           => 'Gemini 2.5 Flash Image',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 1000000,
				'fallback_model' => 'gemini-2.5-flash',
				'cost_per_1k'    => 0.039,  // Per image cost (1024x1024).
				'status'         => 'active',
			),
			// Gemini 2.0 series (stable).
			'gemini-2.0-flash'       => array(
				'name'           => 'Gemini 2.0 Flash',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 1000000,
				'fallback_model' => 'gemini-1.5-flash',
				'cost_per_1k'    => 0.0001,
				'status'         => 'active',
			),
			'gemini-2.0-flash-lite'  => array(
				'name'           => 'Gemini 2.0 Flash Lite',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 2000,
				'context_window' => 1000000,
				'fallback_model' => 'gemini-2.0-flash',
				'cost_per_1k'    => 0.00005,
				'status'         => 'active',
			),
			'gemini-2.0-flash-exp'   => array(
				'name'           => 'Gemini 2.0 Flash (Experimental)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 1000000,
				'fallback_model' => 'gemini-2.0-flash',
				'cost_per_1k'    => 0.0001,
				'status'         => 'experimental',
			),
			// Experimental models (unstable, for testing only).
			'gemini-exp-1206'        => array(
				'name'           => 'Gemini Exp 1206 (Experimental)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 50,
				'tpd'            => 50000000,
				'rpd'            => 50,
				'context_window' => 2000000,
				'fallback_model' => 'gemini-2.5-flash',
				'cost_per_1k'    => 0.0,
				'status'         => 'experimental',
			),
			'gemini-exp-1121'        => array(
				'name'           => 'Gemini Exp 1121 (Experimental)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 50,
				'tpd'            => 50000000,
				'rpd'            => 50,
				'context_window' => 2000000,
				'fallback_model' => 'gemini-2.5-flash',
				'cost_per_1k'    => 0.0,
				'status'         => 'experimental',
			),
			// Gemini 1.5 series (legacy but still supported).
			'gemini-1.5-pro'         => array(
				'name'           => 'Gemini 1.5 Pro (Legacy)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 360,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 2000000,
				'fallback_model' => 'gemini-2.5-pro',
				'cost_per_1k'    => 0.00125,
				'status'         => 'active',
			),
			'gemini-1.5-flash'       => array(
				'name'           => 'Gemini 1.5 Flash (Legacy)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 1000000,
				'fallback_model' => null,
				'cost_per_1k'    => 0.000075,
				'status'         => 'active',
			),
			// Deprecated Gemini models.
			'gemini-pro'             => array(
				'name'           => 'Gemini Pro (Deprecated - use 2.5 Pro)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 360,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 32000,
				'fallback_model' => 'gemini-2.5-pro',
				'cost_per_1k'    => 0.00125,
				'status'         => 'deprecated',
			),
			'gemini-pro-vision'      => array(
				'name'           => 'Gemini Pro Vision (Deprecated - use 2.5 Flash)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 360,
				'tpd'            => 50000000,
				'rpd'            => 1500,
				'context_window' => 16000,
				'fallback_model' => 'gemini-2.5-flash',
				'cost_per_1k'    => 0.00125,
				'status'         => 'deprecated',
			),
			// Gemma models (Google's open models - text-only).
			'gemma-2-27b-it'         => array(
				'name'           => 'Gemma 2 27B (Instruct)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 10000,
				'context_window' => 8192,
				'fallback_model' => 'gemma-2-9b-it',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'gemma-2-9b-it'          => array(
				'name'           => 'Gemma 2 9B (Instruct)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 10000,
				'context_window' => 8192,
				'fallback_model' => 'gemma-2-2b-it',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'gemma-2-2b-it'          => array(
				'name'           => 'Gemma 2 2B (Instruct)',
				'provider'       => 'gemini',
				'tpm'            => 1000000,
				'rpm'            => 2000,
				'tpd'            => 50000000,
				'rpd'            => 10000,
				'context_window' => 8192,
				'fallback_model' => null,
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),

			// LM Studio Models (Local AI).
			// Qwen models (function calling, coding, vision) - Top performers.
			'qwen/qwen3-coder-30b'        => array(
				'name'           => 'Qwen 3 Coder 30B',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 32768,
				'fallback_model' => 'qwen/qwen2.5-coder-32b',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'qwen/qwen3-vl-30b'           => array(
				'name'           => 'Qwen 3 Vision-Language 30B',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 32768,
				'fallback_model' => 'qwen/qwen3-coder-30b',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'qwen/qwen2.5-coder-32b'      => array(
				'name'           => 'Qwen 2.5 Coder 32B',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 32768,
				'fallback_model' => 'qwen/qwen2.5-32b',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'qwen/qwen2.5-32b'            => array(
				'name'           => 'Qwen 2.5 32B',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 32768,
				'fallback_model' => 'qwen/qwen2.5-14b',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'qwen/qwen2.5-14b'            => array(
				'name'           => 'Qwen 2.5 14B',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 32768,
				'fallback_model' => 'qwen/qwen2.5-7b',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'qwen/qwen2.5-7b'             => array(
				'name'           => 'Qwen 2.5 7B',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 32768,
				'fallback_model' => null,
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			// Meta Llama models.
			'meta-llama/llama-3.3-70b-instruct' => array(
				'name'           => 'Llama 3.3 70B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 128000,
				'fallback_model' => 'meta-llama/llama-3.1-8b-instruct',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'meta-llama/llama-3.2-3b-instruct'  => array(
				'name'           => 'Llama 3.2 3B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 128000,
				'fallback_model' => 'meta-llama/llama-3.2-1b-instruct',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'meta-llama/llama-3.2-1b-instruct'  => array(
				'name'           => 'Llama 3.2 1B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 128000,
				'fallback_model' => null,
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'meta-llama/llama-3.1-8b-instruct'  => array(
				'name'           => 'Llama 3.1 8B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 128000,
				'fallback_model' => null,
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			// Mistral models (efficient reasoning).
			'mistralai/mistral-large-2411'      => array(
				'name'           => 'Mistral Large 2411',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 128000,
				'fallback_model' => 'mistralai/mistral-nemo-2407',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'mistralai/mistral-nemo-2407'       => array(
				'name'           => 'Mistral Nemo 2407',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 128000,
				'fallback_model' => 'mistralai/mistral-7b-instruct-v0.3',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'mistralai/mistral-7b-instruct-v0.3' => array(
				'name'           => 'Mistral 7B Instruct v0.3',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 32768,
				'fallback_model' => null,
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'mistralai/mixtral-8x7b-instruct'   => array(
				'name'           => 'Mixtral 8x7B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 32768,
				'fallback_model' => 'mistralai/mistral-7b-instruct-v0.3',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'mistralai/mixtral-8x22b-instruct'  => array(
				'name'           => 'Mixtral 8x22B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 65536,
				'fallback_model' => 'mistralai/mixtral-8x7b-instruct',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			// DeepSeek models (coding specialist).
			'deepseek-ai/deepseek-coder-33b-instruct' => array(
				'name'           => 'DeepSeek Coder 33B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 16384,
				'fallback_model' => null,
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'deepseek-ai/deepseek-v3'                 => array(
				'name'           => 'DeepSeek V3',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 65536,
				'fallback_model' => 'deepseek-ai/deepseek-coder-33b-instruct',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'deepseek-ai/deepseek-r1'                 => array(
				'name'           => 'DeepSeek R1 (Reasoning)',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 65536,
				'fallback_model' => 'deepseek-ai/deepseek-v3',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			// Microsoft Phi models (small but capable).
			'microsoft/phi-4'                   => array(
				'name'           => 'Phi-4',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 16384,
				'fallback_model' => 'microsoft/phi-3.5-mini-instruct',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'microsoft/phi-3.5-mini-instruct'   => array(
				'name'           => 'Phi-3.5 Mini Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 128000,
				'fallback_model' => null,
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			// Google Gemma models.
			'google/gemma-3-12b-it'             => array(
				'name'           => 'Gemma 3 12B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 8192,
				'fallback_model' => 'google/gemma-2-27b-it',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'google/gemma-2-27b-it'             => array(
				'name'           => 'Gemma 2 27B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 8192,
				'fallback_model' => 'google/gemma-2-9b-it',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'google/gemma-2-9b-it'              => array(
				'name'           => 'Gemma 2 9B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 8192,
				'fallback_model' => 'google/gemma-2-2b-it',
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
			'google/gemma-2-2b-it'              => array(
				'name'           => 'Gemma 2 2B Instruct',
				'provider'       => 'lm_studio',
				'tpm'            => 1000000,
				'rpm'            => 10000,
				'tpd'            => 100000000,
				'rpd'            => 100000,
				'context_window' => 8192,
				'fallback_model' => null,
				'cost_per_1k'    => 0.0,
				'status'         => 'active',
			),
		);
	}

	/**
	 * Merge user configs with defaults.
	 *
	 * @param array $user_configs User-defined configurations.
	 * @return array Merged configurations.
	 */
	protected static function merge_with_defaults( $user_configs ) {
		$defaults = self::get_default_configs();

		// Merge: user configs override defaults.
		foreach ( $defaults as $model => $default_config ) {
			if ( ! isset( $user_configs[ $model ] ) ) {
				$user_configs[ $model ] = $default_config;
			} else {
				// Merge user config with defaults (user values take precedence).
				$user_configs[ $model ] = array_merge( $default_config, $user_configs[ $model ] );
			}
		}

		return $user_configs;
	}

	/**
	 * Sanitize model configuration.
	 *
	 * @param array $config Configuration array.
	 * @return array Sanitized configuration.
	 */
	protected static function sanitize_config( $config ) {
		$sanitized = array();

		// String fields.
		$string_fields = array( 'name', 'provider', 'fallback_model', 'status' );
		foreach ( $string_fields as $field ) {
			if ( isset( $config[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( $config[ $field ] );
			}
		}

		// Integer fields.
		$int_fields = array( 'tpm', 'rpm', 'tpd', 'rpd', 'context_window' );
		foreach ( $int_fields as $field ) {
			if ( isset( $config[ $field ] ) ) {
				$sanitized[ $field ] = absint( $config[ $field ] );
			}
		}

		// Float fields.
		if ( isset( $config['cost_per_1k'] ) ) {
			$sanitized['cost_per_1k'] = floatval( $config['cost_per_1k'] );
		}

		return $sanitized;
	}

	/**
	 * Convert CCT data to config format.
	 *
	 * @param array $cct_data CCT data.
	 * @return array Config format.
	 */
	protected static function convert_cct_to_config( $cct_data ) {
		return array(
			'name'           => isset( $cct_data['model_name'] ) ? $cct_data['model_name'] : '',
			'provider'       => isset( $cct_data['provider'] ) ? $cct_data['provider'] : '',
			'tpm'            => isset( $cct_data['tpm'] ) ? absint( $cct_data['tpm'] ) : 0,
			'rpm'            => isset( $cct_data['rpm'] ) ? absint( $cct_data['rpm'] ) : 0,
			'tpd'            => isset( $cct_data['tpd'] ) ? absint( $cct_data['tpd'] ) : 0,
			'rpd'            => isset( $cct_data['rpd'] ) ? absint( $cct_data['rpd'] ) : 0,
			'context_window' => isset( $cct_data['context_window'] ) ? absint( $cct_data['context_window'] ) : 0,
			'fallback_model' => isset( $cct_data['fallback_model'] ) ? sanitize_text_field( $cct_data['fallback_model'] ) : null,
			'cost_per_1k'    => isset( $cct_data['input_cost_per_1k'] ) ? floatval( $cct_data['input_cost_per_1k'] ) : 0.0,
			'status'         => 'active',
		);
	}

	/**
	 * Get available providers from settings.
	 *
	 * @return array Array of available providers.
	 */
	public static function get_available_providers() {
		$settings  = get_option( 'wp_mcp_ai_settings', array() );
		$providers = array();

		if ( ! empty( $settings['openai_api_key'] ) ) {
			$providers['openai'] = __( 'OpenAI', 'wp-mcp-ai' );
		}

		if ( ! empty( $settings['anthropic_api_key'] ) ) {
			$providers['anthropic'] = __( 'Anthropic (Claude)', 'wp-mcp-ai' );
		}

		if ( ! empty( $settings['gemini_api_key'] ) ) {
			$providers['gemini'] = __( 'Google Gemini', 'wp-mcp-ai' );
		}

		if ( ! empty( $settings['ollama_endpoint_url'] ) ) {
			$providers['ollama'] = __( 'Ollama (Local)', 'wp-mcp-ai' );
		}

		if ( ! empty( $settings['lm_studio_endpoint_url'] ) ) {
			$providers['lm_studio'] = __( 'LM Studio (Local)', 'wp-mcp-ai' );
		}

		return $providers;
	}
}
