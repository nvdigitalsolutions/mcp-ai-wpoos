<?php
/**
 * Model configuration store (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Model_Config — byte-identical option key, cache group,
 * cache keys, and filter/action hooks; per-mode seam).
 *
 * The base plugin stores per-model orchestrator configs in the
 * `wp_mcp_ai_model_configs` WordPress option (NOT the Content Graph
 * settings option). This class preserves that storage contract so the
 * ported add_model_config / research_model / discover_new_models tools
 * read and write the exact same option the base would in monolith
 * installs. Standalone, it implements the option + wp_cache behaviour
 * directly (the base's JetEngine CCT fallback is unavailable by
 * definition, matching the base's own no-CCT path).
 *
 * @package NvoosContentGraphAi\Model
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent model configuration storage with per-mode seam.
 *
 * @since 1.0.4
 */
final class ModelConfigStore {

	/**
	 * Option name for storing model configurations (base-identical).
	 *
	 * @var string
	 */
	public const CONFIGS_OPTION = 'wp_mcp_ai_model_configs';

	/**
	 * Cache group for model configs (base-identical).
	 *
	 * @var string
	 */
	public const CACHE_GROUP = 'wp_mcp_ai_model_configs';

	/**
	 * Whether the base plugin's storage class owns the surface.
	 *
	 * @return bool
	 */
	private static function base_available(): bool {
		return defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Model_Config' );
	}

	/**
	 * Get all model configurations (cache-first, filter-identical).
	 *
	 * @return array Array of model configurations keyed by model identifier.
	 */
	public static function get_all_configs() {
		if ( self::base_available() ) {
			return \WP_MCP_AI_Model_Config::get_all_configs();
		}

		$configs = wp_cache_get( 'all_configs', self::CACHE_GROUP );

		if ( false !== $configs ) {
			return $configs;
		}

		$configs = get_option( self::CONFIGS_OPTION, array() );

		if ( ! is_array( $configs ) ) {
			$configs = array();
		}

		/**
		 * Filter all model configurations (base-identical hook name).
		 *
		 * @param array $configs Model configurations.
		 */
		$configs = apply_filters( 'wp_mcp_ai_all_model_configs', $configs );

		wp_cache_set( 'all_configs', $configs, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $configs;
	}

	/**
	 * Get configuration for a specific model (exact then longest-prefix).
	 *
	 * @param string $model Model identifier.
	 * @return array|null Model configuration or null if not found.
	 */
	public static function get_model_config( $model ) {
		if ( self::base_available() ) {
			return \WP_MCP_AI_Model_Config::get_model_config( $model );
		}

		$model = sanitize_text_field( $model );

		if ( empty( $model ) ) {
			return null;
		}

		$cache_key = 'model_' . md5( $model );
		$config    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $config ) {
			return $config;
		}

		$all_configs = self::get_all_configs();

		if ( isset( $all_configs[ $model ] ) ) {
			$config = $all_configs[ $model ];
			wp_cache_set( $cache_key, $config, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
			return $config;
		}

		// Prefix match for model families (longest match wins).
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

		return null;
	}

	/**
	 * Set configuration for a specific model (option + cache + action).
	 *
	 * @param string $model  Model identifier.
	 * @param array  $config Configuration array.
	 * @return bool True on success, false on failure.
	 */
	public static function set_model_config( $model, $config ) {
		if ( self::base_available() ) {
			return \WP_MCP_AI_Model_Config::set_model_config( $model, $config );
		}

		$model = sanitize_text_field( $model );

		if ( empty( $model ) || ! is_array( $config ) ) {
			return false;
		}

		$all_configs = get_option( self::CONFIGS_OPTION, array() );

		if ( ! is_array( $all_configs ) ) {
			$all_configs = array();
		}

		$all_configs[ $model ] = $config;

		$result = update_option( self::CONFIGS_OPTION, $all_configs, false );

		if ( $result ) {
			wp_cache_delete( 'all_configs', self::CACHE_GROUP );
			wp_cache_delete( 'model_' . md5( $model ), self::CACHE_GROUP );

			/**
			 * Fires after a model configuration is updated
			 * (base-identical hook name).
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
		if ( self::base_available() ) {
			return \WP_MCP_AI_Model_Config::delete_model_config( $model );
		}

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
			wp_cache_delete( 'all_configs', self::CACHE_GROUP );
			wp_cache_delete( 'model_' . md5( $model ), self::CACHE_GROUP );
		}

		return $result;
	}
}
