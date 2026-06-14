<?php
/**
 * Manages per-model configuration and settings.
 *
 * This class stores model configuration primarily in WordPress options
 * with optional JetEngine CCT backup for enhanced queryability.
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
	public static function sync_to_cct( $model, $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for future JetEngine CCT integration.
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
	 * Pulls from WP_MCP_AI_Model_Rate_Limits_CCT to ensure single source of truth.
	 *
	 * @return array Default model configurations.
	 */
	protected static function get_default_configs() {
		// Use Model Rate Limits CCT as the single source of truth for model data.
		if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			return array();
		}

		// Get default model data from CCT.
		$cct_models = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();

		if ( empty( $cct_models ) || ! is_array( $cct_models ) ) {
			return array();
		}

		// Convert CCT format to Model Config format.
		$configs = array();
		foreach ( $cct_models as $model_data ) {
			if ( ! isset( $model_data['model_name'] ) ) {
				continue;
			}

			$model_id = $model_data['model_name'];

			// Build display name from model name and notes.
			$name = $model_id;
			if ( isset( $model_data['notes'] ) && ! empty( $model_data['notes'] ) ) {
				// Extract a short display name from notes.
				// Notes follow the format: "Model Name - Description." or "First sentence. More details.".
				if ( false !== strpos( $model_data['notes'], ' - ' ) ) {
					// Preferred format: split on " - " separator.
					$dash_parts = explode( ' - ', $model_data['notes'], 2 );
					if ( ! empty( $dash_parts[0] ) ) {
						$name = trim( $dash_parts[0] );
					}
				} elseif ( preg_match( '/^(.+?)\.\s+[^0-9]/', $model_data['notes'], $matches ) ) {
					// Fallback: extract first sentence, splitting at a period followed
					// by a space and a non-digit character to preserve version numbers
					// like "GPT-5.2" while still splitting at sentence boundaries.
					$name = trim( $matches[1] );
				} else {
					$name = rtrim( trim( $model_data['notes'] ), '.' );
				}
			}

			// Convert CCT model data to config format.
			$configs[ $model_id ] = array(
				'name'                      => $name,
				'provider'                  => isset( $model_data['provider'] ) ? $model_data['provider'] : '',
				'tpm'                       => isset( $model_data['tpm_limit'] ) ? absint( $model_data['tpm_limit'] ) : 0,
				'rpm'                       => isset( $model_data['rpm_limit'] ) ? absint( $model_data['rpm_limit'] ) : 0,
				'tpd'                       => 0, // Not in CCT, calculate from TPM if needed.
				'rpd'                       => 0, // Not in CCT, calculate from RPM if needed.
				'context_window'            => isset( $model_data['context_window'] ) ? absint( $model_data['context_window'] ) : 0,
				'max_completion_tokens'     => isset( $model_data['max_output_tokens'] ) ? absint( $model_data['max_output_tokens'] ) : 0,
				'supports_function_calling' => isset( $model_data['supports_function_calling'] ) ? (bool) $model_data['supports_function_calling'] : true,
				'fallback_model'            => isset( $model_data['fallback_model'] ) ? sanitize_text_field( $model_data['fallback_model'] ) : '',
				'cost_per_1k'               => isset( $model_data['cost_per_1k_input_tokens'] ) ? floatval( $model_data['cost_per_1k_input_tokens'] ) : 0.0,
				'status'                    => 'active',
			);

			// Calculate TPD and RPD from TPM and RPM (rough estimate: 24 hours * 60 minutes).
			if ( isset( $model_data['tpm_limit'] ) && $model_data['tpm_limit'] > 0 ) {
				$configs[ $model_id ]['tpd'] = absint( $model_data['tpm_limit'] ) * 60 * 24;
			}
			if ( isset( $model_data['rpm_limit'] ) && $model_data['rpm_limit'] > 0 ) {
				$configs[ $model_id ]['rpd'] = absint( $model_data['rpm_limit'] ) * 60 * 24;
			}
		}

		return $configs;
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
		$int_fields = array( 'tpm', 'rpm', 'tpd', 'rpd', 'context_window', 'max_completion_tokens' );
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
			'name'                      => isset( $cct_data['model_name'] ) ? $cct_data['model_name'] : '',
			'provider'                  => isset( $cct_data['provider'] ) ? $cct_data['provider'] : '',
			'tpm'                       => isset( $cct_data['tpm'] ) ? absint( $cct_data['tpm'] ) : 0,
			'rpm'                       => isset( $cct_data['rpm'] ) ? absint( $cct_data['rpm'] ) : 0,
			'tpd'                       => isset( $cct_data['tpd'] ) ? absint( $cct_data['tpd'] ) : 0,
			'rpd'                       => isset( $cct_data['rpd'] ) ? absint( $cct_data['rpd'] ) : 0,
			'context_window'            => isset( $cct_data['context_window'] ) ? absint( $cct_data['context_window'] ) : 0,
			'max_completion_tokens'     => isset( $cct_data['max_output_tokens'] ) ? absint( $cct_data['max_output_tokens'] ) : 0,
			'supports_function_calling' => isset( $cct_data['supports_function_calling'] ) ? (bool) $cct_data['supports_function_calling'] : true,
			'fallback_model'            => isset( $cct_data['fallback_model'] ) ? sanitize_text_field( $cct_data['fallback_model'] ) : null,
			'cost_per_1k'               => isset( $cct_data['input_cost_per_1k'] ) ? floatval( $cct_data['input_cost_per_1k'] ) : 0.0,
			'status'                    => 'active',
		);
	}

	/**
	 * Get available providers from settings.
	 *
	 * Returns only providers that are both enabled (via enable_* checkbox)
	 * and properly configured (have required API keys/endpoints).
	 *
	 * @return array Array of available providers.
	 */
	public static function get_available_providers() {
		$settings  = get_option( 'wp_mcp_ai_settings', array() );
		$providers = array();

		/*
		 * API-key providers now resolve credentials through
		 * WP_MCP_AI_Credential_Resolver, which checks four sources
		 * in priority order:
		 *   1. NV oOS settings (wp_mcp_ai_settings)
		 *   2. WP 7.0 Connector DB (connectors_ai_{id}_api_key)
		 *   3. Environment variable ({PROVIDER}_API_KEY)
		 *   4. PHP constant ({PROVIDER}_API_KEY)
		 *
		 * When WP < 7.0, only source 1 is checked — identical to
		 * the previous behaviour.  See includes/bridge/.
		 */

		// Check enable_openai setting (defaults to false if not set).
		$enable_openai = isset( $settings['enable_openai'] ) ? $settings['enable_openai'] : false;
		if ( $enable_openai && WP_MCP_AI_Credential_Resolver::has_credentials( 'openai' ) ) {
			$providers['openai'] = __( 'OpenAI', 'mcp-ai-wpoos' );
		}

		// Check enable_anthropic setting (defaults to false if not set).
		$enable_anthropic = isset( $settings['enable_anthropic'] ) ? $settings['enable_anthropic'] : false;
		if ( $enable_anthropic && WP_MCP_AI_Credential_Resolver::has_credentials( 'anthropic' ) ) {
			$providers['anthropic'] = __( 'Anthropic (Claude)', 'mcp-ai-wpoos' );
		}

		// Check enable_gemini setting (defaults to false if not set).
		$enable_gemini = isset( $settings['enable_gemini'] ) ? $settings['enable_gemini'] : false;
		if ( $enable_gemini && WP_MCP_AI_Credential_Resolver::has_credentials( 'gemini' ) ) {
			$providers['gemini'] = __( 'Google Gemini', 'mcp-ai-wpoos' );
		}

		// Check enable_ollama setting (defaults to false if not set).
		// Ollama uses an endpoint URL, not an API key, so the
		// credential resolver delegates to has_credentials() which
		// returns true for no-key providers — but the enable toggle
		// + endpoint URL check is still needed for the admin UI.
		$enable_ollama = isset( $settings['enable_ollama'] ) ? $settings['enable_ollama'] : false;
		if ( $enable_ollama && ! empty( $settings['ollama_endpoint_url'] ) ) {
			$providers['ollama'] = __( 'Ollama', 'mcp-ai-wpoos' );
		}

		// Check enable_lm_studio setting (defaults to false if not set).
		// Same rationale as Ollama — endpoint URL, not API key.
		$enable_lm_studio = isset( $settings['enable_lm_studio'] ) ? $settings['enable_lm_studio'] : false;
		if ( $enable_lm_studio && ! empty( $settings['lm_studio_endpoint_url'] ) ) {
			$providers['lm_studio'] = __( 'LM Studio (Local)', 'mcp-ai-wpoos' );
		}

		// Check enable_cloudflare setting (defaults to false if not set).
		// Cloudflare needs both a token AND an account ID — the
		// Credential_Resolver only handles single API keys, so this
		// provider keeps its two-field check.
		$enable_cloudflare = isset( $settings['enable_cloudflare'] ) ? $settings['enable_cloudflare'] : false;
		if ( $enable_cloudflare && ! empty( $settings['cloudflare_api_token'] ) && ! empty( $settings['cloudflare_account_id'] ) ) {
			$providers['cloudflare'] = __( 'Cloudflare Workers AI', 'mcp-ai-wpoos' );
		}

		// Check enable_nvidia setting (defaults to false if not set).
		$enable_nvidia = isset( $settings['enable_nvidia'] ) ? $settings['enable_nvidia'] : false;
		if ( $enable_nvidia && WP_MCP_AI_Credential_Resolver::has_credentials( 'nvidia' ) ) {
			$providers['nvidia'] = __( 'NVIDIA NIM', 'mcp-ai-wpoos' );
		}

		// Check enable_deepseek setting (defaults to false if not set).
		$enable_deepseek = isset( $settings['enable_deepseek'] ) ? $settings['enable_deepseek'] : false;
		if ( $enable_deepseek && WP_MCP_AI_Credential_Resolver::has_credentials( 'deepseek' ) ) {
			$providers['deepseek'] = __( 'DeepSeek', 'mcp-ai-wpoos' );
		}

		// Check enable_kimi setting (defaults to false if not set).
		$enable_kimi = isset( $settings['enable_kimi'] ) ? $settings['enable_kimi'] : false;
		if ( $enable_kimi && WP_MCP_AI_Credential_Resolver::has_credentials( 'kimi' ) ) {
			$providers['kimi'] = __( 'Kimi (Moonshot AI)', 'mcp-ai-wpoos' );
		}

		// Check enable_baseten setting (defaults to false if not set).
		$enable_baseten = isset( $settings['enable_baseten'] ) ? $settings['enable_baseten'] : false;
		if ( $enable_baseten && WP_MCP_AI_Credential_Resolver::has_credentials( 'baseten' ) ) {
			$providers['baseten'] = __( 'Baseten', 'mcp-ai-wpoos' );
		}

		// Check enable_openrouter setting (defaults to false if not set).
		$enable_openrouter = isset( $settings['enable_openrouter'] ) ? $settings['enable_openrouter'] : false;
		if ( $enable_openrouter && WP_MCP_AI_Credential_Resolver::has_credentials( 'openrouter' ) ) {
			$providers['openrouter'] = __( 'OpenRouter', 'mcp-ai-wpoos' );
		}

		// Check enable_digitalocean setting (defaults to false if not set).
		$enable_digitalocean = isset( $settings['enable_digitalocean'] ) ? $settings['enable_digitalocean'] : false;
		if ( $enable_digitalocean && WP_MCP_AI_Credential_Resolver::has_credentials( 'digitalocean' ) ) {
			$providers['digitalocean'] = __( 'DigitalOcean', 'mcp-ai-wpoos' );
		}

		// Check enable_huggingface setting (defaults to false if not set).
		$enable_huggingface = isset( $settings['enable_huggingface'] ) ? $settings['enable_huggingface'] : false;
		if ( $enable_huggingface && WP_MCP_AI_Credential_Resolver::has_credentials( 'huggingface' ) ) {
			$providers['huggingface'] = __( 'Hugging Face', 'mcp-ai-wpoos' );
		}

		// Check enable_embedded setting (defaults to true when Pro is active, matching field definition).
		// Embedded LLM runs in the browser, so no API key is required - just check if enabled and a model is selected.
		// Note: Embedded LLM is only available when Pro addon is active.
		// Auto-enable when Pro is active to match the field's 'default' => true in Pro Providers section.
		$embedded_settings = WP_MCP_AI_Admin_Settings::get_embedded_provider_effective_settings( $settings );
		$enable_embedded   = $embedded_settings['enabled'];
		$embedded_model    = $embedded_settings['model'];
		if ( $enable_embedded && ! empty( $embedded_model ) && defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$providers['embedded'] = __( 'Embedded LLM', 'mcp-ai-wpoos' );
		}

		return $providers;
	}

	/**
	 * Get all provider slugs from Model Config.
	 *
	 * Returns a unique list of all provider slugs that have models configured,
	 * regardless of whether API keys are set up.
	 *
	 * @since 1.0.0
	 * @return array Array of provider slugs (e.g., ['openai', 'anthropic', 'gemini']).
	 */
	public static function get_all_provider_slugs() {
		$all_configs = self::get_all_configs();
		$providers   = array();

		foreach ( $all_configs as $config ) {
			if ( isset( $config['provider'] ) && ! in_array( $config['provider'], $providers, true ) ) {
				$providers[] = $config['provider'];
			}
		}

		// Sort providers alphabetically for consistency.
		sort( $providers );

		return $providers;
	}

	/**
	 * Get models for a specific provider from Model Config.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider slug (e.g., 'openai', 'anthropic').
	 * @return array Associative array of model_id => model_name.
	 */
	public static function get_models_by_provider( $provider ) {
		$all_configs = self::get_all_configs();
		$models      = array();

		foreach ( $all_configs as $model_id => $config ) {
			if ( isset( $config['provider'] ) && $config['provider'] === $provider ) {
				$models[ $model_id ] = isset( $config['name'] ) ? $config['name'] : $model_id;
			}
		}

		return $models;
	}
}
