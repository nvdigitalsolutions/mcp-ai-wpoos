<?php
/**
 * Orchestration Preset Service
 *
 * Handles configuration preset management for the orchestration layer.
 * Provides business logic for preset definitions, application, and auto-detection.
 *
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestration Preset Service class
 *
 * Responsible for:
 * - Preset definitions and metadata
 * - Auto preset detection based on server capabilities
 * - Preset application logic
 * - Preset configuration validation
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Orchestration_Preset_Service {

	/**
	 * Get all available configuration presets.
	 *
	 * @return array Array of preset configurations.
	 */
	public static function get_presets() {
		return array(
			'custom'              => self::get_custom_preset(),
			'auto'                => self::get_auto_preset(),
			'balanced'            => self::get_balanced_preset(),
			'conservative'        => self::get_conservative_preset(),
			'aggressive'          => self::get_aggressive_preset(),
			'development'         => self::get_development_preset(),
			'high_traffic'        => self::get_high_traffic_preset(),
			'burst_workload'      => self::get_burst_workload_preset(),
			'cost_optimized'      => self::get_cost_optimized_preset(),
			'enterprise'          => self::get_enterprise_preset(),
			'failsafe'            => self::get_failsafe_preset(),
			'predictive_first'    => self::get_predictive_first_preset(),
			'design_professional' => self::get_design_professional_preset(),
		);
	}

	/**
	 * Custom preset - preserves current settings.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_custom_preset() {
		return array(
			'name'        => __( 'Custom', 'wp-mcp-ai' ),
			'description' => __( 'Your current customized settings.', 'wp-mcp-ai' ),
			'settings'    => array(), // No changes - keeps current settings.
		);
	}

	/**
	 * Auto preset - intelligently detects best configuration.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_auto_preset() {
		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$memory_limit     = $resource_manager->get_memory_limit();

		// Auto-detect best preset based on server resources.
		$detected_preset_id = 'balanced';
		if ( $memory_limit >= 1024 * 1024 * 1024 ) { // 1GB+.
			$detected_preset_id = 'aggressive';
		} elseif ( $memory_limit < 256 * 1024 * 1024 ) { // Less than 256MB.
			$detected_preset_id = 'conservative';
		}

		// Call the specific preset method directly to avoid circular dependency.
		$preset_method = 'get_' . $detected_preset_id . '_preset';
		$base_preset   = call_user_func( array( self::class, $preset_method ) );

		return array(
			'name'        => __( 'Auto', 'wp-mcp-ai' ),
			'description' => sprintf(
				/* translators: %s: detected preset name */
				__( 'Auto-detected: %s configuration based on your server capabilities.', 'wp-mcp-ai' ),
				$base_preset['name']
			),
			'settings'    => $base_preset['settings'],
		);
	}

	/**
	 * Balanced preset - works for most production sites.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_balanced_preset() {
		return array(
			'name'        => __( 'Balanced', 'wp-mcp-ai' ),
			'description' => __( 'Modern balanced settings for production sites with moderate traffic.', 'wp-mcp-ai' ),
			'settings'    => array(
				// Health monitoring - Cloud-native standards (2024).
				'memory_warning_threshold'        => 70,
				'memory_critical_threshold'       => 85,
				'error_rate_warning_threshold'    => 5,
				'error_rate_critical_threshold'   => 10,
				// Budget allocation - Simplified tiers.
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 75,
				'low_priority_budget'             => 50,
				'critical_health_reduction'       => 50,
				'warning_health_reduction'        => 75,
				// Token limits - Modern AI models (GPT-4, Claude 3, Gemini).
				'low_tier_max_tokens'             => 2000,
				'medium_tier_max_tokens'          => 8000,
				'high_tier_max_tokens'            => 32000,
				// Per-call and per-session limits.
				'enable_per_call_limits'          => true,
				'per_call_token_limit'            => 10000,
				'enable_per_session_limits'       => true,
				'per_session_token_limit'         => 50000,
				// Predictive analytics - Optimized confidence.
				'prediction_confidence_threshold' => 40,
				'prediction_safety_buffer'        => 15,
			),
		);
	}

	/**
	 * Conservative preset - strict limits for resource-constrained environments.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_conservative_preset() {
		return array(
			'name'        => __( 'Conservative', 'wp-mcp-ai' ),
			'description' => __( 'Lightweight limits for resource-constrained environments or shared hosting.', 'wp-mcp-ai' ),
			'settings'    => array(
				'memory_warning_threshold'        => 60,
				'memory_critical_threshold'       => 75,
				'error_rate_warning_threshold'    => 5,
				'error_rate_critical_threshold'   => 8,
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 75,
				'low_priority_budget'             => 50,
				'critical_health_reduction'       => 40,
				'warning_health_reduction'        => 65,
				'low_tier_max_tokens'             => 1000,
				'medium_tier_max_tokens'          => 4000,
				'high_tier_max_tokens'            => 16000,
				// Per-call and per-session limits - More restrictive.
				'enable_per_call_limits'          => true,
				'per_call_token_limit'            => 5000,
				'enable_per_session_limits'       => true,
				'per_session_token_limit'         => 25000,
				'prediction_confidence_threshold' => 50,
				'prediction_safety_buffer'        => 20,
			),
		);
	}

	/**
	 * Aggressive preset - maximum performance for dedicated servers.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_aggressive_preset() {
		return array(
			'name'        => __( 'Performance', 'wp-mcp-ai' ),
			'description' => __( 'High-performance settings for dedicated servers with ample resources.', 'wp-mcp-ai' ),
			'settings'    => array(
				'memory_warning_threshold'        => 80,
				'memory_critical_threshold'       => 90,
				'error_rate_warning_threshold'    => 8,
				'error_rate_critical_threshold'   => 15,
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 100,
				'low_priority_budget'             => 75,
				'critical_health_reduction'       => 65,
				'warning_health_reduction'        => 85,
				'low_tier_max_tokens'             => 4000,
				'medium_tier_max_tokens'          => 16000,
				'high_tier_max_tokens'            => 64000,
				// Per-call and per-session limits - More generous.
				'enable_per_call_limits'          => true,
				'per_call_token_limit'            => 25000,
				'enable_per_session_limits'       => true,
				'per_session_token_limit'         => 150000,
				'prediction_confidence_threshold' => 30,
				'prediction_safety_buffer'        => 10,
			),
		);
	}

	/**
	 * Development preset - relaxed limits for development/testing.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_development_preset() {
		return array(
			'name'        => __( 'Development', 'wp-mcp-ai' ),
			'description' => __( 'Relaxed limits for development and testing environments.', 'wp-mcp-ai' ),
			'settings'    => array(
				'memory_warning_threshold'        => 85,
				'memory_critical_threshold'       => 95,
				'error_rate_warning_threshold'    => 15,
				'error_rate_critical_threshold'   => 25,
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 100,
				'low_priority_budget'             => 100,
				'critical_health_reduction'       => 75,
				'warning_health_reduction'        => 90,
				'low_tier_max_tokens'             => 4000,
				'medium_tier_max_tokens'          => 16000,
				'high_tier_max_tokens'            => 64000,
				// Per-call and per-session limits - Very relaxed for testing.
				'enable_per_call_limits'          => false,
				'per_call_token_limit'            => 50000,
				'enable_per_session_limits'       => false,
				'per_session_token_limit'         => 200000,
				'prediction_confidence_threshold' => 20,
				'prediction_safety_buffer'        => 10,
			),
		);
	}

	/**
	 * High Traffic preset - optimized for high-volume sites.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_high_traffic_preset() {
		return array(
			'name'        => __( 'High Traffic', 'wp-mcp-ai' ),
			'description' => __( 'Optimized for high-volume sites with consistent traffic patterns.', 'wp-mcp-ai' ),
			'settings'    => array(
				'memory_warning_threshold'        => 75,
				'memory_critical_threshold'       => 88,
				'error_rate_warning_threshold'    => 6,
				'error_rate_critical_threshold'   => 12,
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 85,
				'low_priority_budget'             => 60,
				'critical_health_reduction'       => 55,
				'warning_health_reduction'        => 80,
				'low_tier_max_tokens'             => 2000,
				'medium_tier_max_tokens'          => 8000,
				'high_tier_max_tokens'            => 32000,
				// Per-call and per-session limits - Moderate to handle traffic.
				'enable_per_call_limits'          => true,
				'per_call_token_limit'            => 8000,
				'enable_per_session_limits'       => true,
				'per_session_token_limit'         => 40000,
				'prediction_confidence_threshold' => 35,
				'prediction_safety_buffer'        => 15,
			),
		);
	}

	/**
	 * Burst Workload preset - handles sudden traffic spikes.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_burst_workload_preset() {
		return array(
			'name'        => __( 'Burst Workload', 'wp-mcp-ai' ),
			'description' => __( 'Handles sudden traffic spikes and variable load patterns efficiently.', 'wp-mcp-ai' ),
			'settings'    => array(
				'memory_warning_threshold'        => 78,
				'memory_critical_threshold'       => 90,
				'error_rate_warning_threshold'    => 8,
				'error_rate_critical_threshold'   => 15,
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 80,
				'low_priority_budget'             => 50,
				'critical_health_reduction'       => 45,
				'warning_health_reduction'        => 70,
				'low_tier_max_tokens'             => 2000,
				'medium_tier_max_tokens'          => 8000,
				'high_tier_max_tokens'            => 32000,
				// Per-call and per-session limits - Flexible for burst patterns.
				'enable_per_call_limits'          => true,
				'per_call_token_limit'            => 15000,
				'enable_per_session_limits'       => true,
				'per_session_token_limit'         => 75000,
				'prediction_confidence_threshold' => 40,
				'prediction_safety_buffer'        => 20,
			),
		);
	}

	/**
	 * Cost Optimized preset - minimizes API token usage.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_cost_optimized_preset() {
		return array(
			'name'        => __( 'Cost Optimized', 'wp-mcp-ai' ),
			'description' => __( 'Minimizes API token usage and operational costs while maintaining quality.', 'wp-mcp-ai' ),
			'settings'    => array(
				'memory_warning_threshold'        => 65,
				'memory_critical_threshold'       => 80,
				'error_rate_warning_threshold'    => 5,
				'error_rate_critical_threshold'   => 10,
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 65,
				'low_priority_budget'             => 40,
				'critical_health_reduction'       => 40,
				'warning_health_reduction'        => 60,
				'low_tier_max_tokens'             => 1000,
				'medium_tier_max_tokens'          => 4000,
				'high_tier_max_tokens'            => 16000,
				// Per-call and per-session limits - Strict to control costs.
				'enable_per_call_limits'          => true,
				'per_call_token_limit'            => 3000,
				'enable_per_session_limits'       => true,
				'per_session_token_limit'         => 15000,
				'prediction_confidence_threshold' => 45,
				'prediction_safety_buffer'        => 20,
			),
		);
	}

	/**
	 * Enterprise preset - fine-tuned for enterprise deployments with SLAs.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_enterprise_preset() {
		return array(
			'name'        => __( 'Enterprise', 'wp-mcp-ai' ),
			'description' => __( 'Fine-tuned for enterprise deployments with SLA requirements and high reliability.', 'wp-mcp-ai' ),
			'settings'    => array(
				'memory_warning_threshold'        => 72,
				'memory_critical_threshold'       => 87,
				'error_rate_warning_threshold'    => 5,
				'error_rate_critical_threshold'   => 10,
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 85,
				'low_priority_budget'             => 65,
				'critical_health_reduction'       => 50,
				'warning_health_reduction'        => 75,
				'low_tier_max_tokens'             => 2000,
				'medium_tier_max_tokens'          => 8000,
				'high_tier_max_tokens'            => 32000,
				// Per-call and per-session limits - Balanced for reliability.
				'enable_per_call_limits'          => true,
				'per_call_token_limit'            => 12000,
				'enable_per_session_limits'       => true,
				'per_session_token_limit'         => 60000,
				'prediction_confidence_threshold' => 35,
				'prediction_safety_buffer'        => 15,
			),
		);
	}

	/**
	 * Failsafe preset - maximum protection against resource exhaustion.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_failsafe_preset() {
		return array(
			'name'        => __( 'Failsafe', 'wp-mcp-ai' ),
			'description' => __( 'Maximum protection against resource exhaustion with early intervention.', 'wp-mcp-ai' ),
			'settings'    => array(
				'memory_warning_threshold'        => 55,
				'memory_critical_threshold'       => 70,
				'error_rate_warning_threshold'    => 5,
				'error_rate_critical_threshold'   => 8,
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 70,
				'low_priority_budget'             => 45,
				'critical_health_reduction'       => 35,
				'warning_health_reduction'        => 55,
				'low_tier_max_tokens'             => 1000,
				'medium_tier_max_tokens'          => 4000,
				'high_tier_max_tokens'            => 16000,
				// Per-call and per-session limits - Very strict for safety.
				'enable_per_call_limits'          => true,
				'per_call_token_limit'            => 2000,
				'enable_per_session_limits'       => true,
				'per_session_token_limit'         => 10000,
				'prediction_confidence_threshold' => 55,
				'prediction_safety_buffer'        => 25,
			),
		);
	}

	/**
	 * Predictive-First preset - emphasizes ML predictions for proactive management.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_predictive_first_preset() {
		return array(
			'name'        => __( 'Predictive-First', 'wp-mcp-ai' ),
			'description' => __( 'Emphasizes machine learning predictions for proactive resource management.', 'wp-mcp-ai' ),
			'settings'    => array(
				'memory_warning_threshold'        => 70,
				'memory_critical_threshold'       => 85,
				'error_rate_warning_threshold'    => 5,
				'error_rate_critical_threshold'   => 10,
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 75,
				'low_priority_budget'             => 50,
				'critical_health_reduction'       => 50,
				'warning_health_reduction'        => 70,
				'low_tier_max_tokens'             => 2000,
				'medium_tier_max_tokens'          => 8000,
				'high_tier_max_tokens'            => 32000,
				// Per-call and per-session limits - Standard balanced limits.
				'enable_per_call_limits'          => true,
				'per_call_token_limit'            => 10000,
				'enable_per_session_limits'       => true,
				'per_session_token_limit'         => 50000,
				'prediction_confidence_threshold' => 25,
				'prediction_safety_buffer'        => 12,
			),
		);
	}

	/**
	 * Design Professional preset - optimized for creative professionals.
	 *
	 * Tailored for image generation, video production, vision analysis, and data visualization workflows.
	 * Higher token limits accommodate resource-intensive operations like AI image/video generation.
	 *
	 * @return array Preset configuration.
	 */
	private static function get_design_professional_preset() {
		return array(
			'name'        => __( 'Design Professional', 'wp-mcp-ai' ),
			'description' => __( 'Optimized for creative professionals with image generation, video production, and vision tools. Higher token limits for resource-intensive design workflows.', 'wp-mcp-ai' ),
			'settings'    => array(
				'memory_warning_threshold'        => 75,
				'memory_critical_threshold'       => 88,
				'error_rate_warning_threshold'    => 6,
				'error_rate_critical_threshold'   => 12,
				'high_priority_budget'            => 100,
				'medium_priority_budget'          => 90,
				'low_priority_budget'             => 70,
				'critical_health_reduction'       => 55,
				'warning_health_reduction'        => 80,
				// Higher token limits for design tools (image/video generation, vision analysis).
				// 2x balanced preset to accommodate DALL-E, Gemini Imagen, Veo video generation.
				'low_tier_max_tokens'             => 4000,
				'medium_tier_max_tokens'          => 16000,
				'high_tier_max_tokens'            => 64000, // Double enterprise for AI-generated media workflows.
				// Per-call limits - Higher for design operations.
				// Design tools often require multiple API calls per operation (e.g., generate + edit image).
				'enable_per_call_limits'          => true,
				'per_call_token_limit'            => 20000,
				'enable_per_session_limits'       => true,
				'per_session_token_limit'         => 100000, // Double enterprise for iterative creative workflows.
				'prediction_confidence_threshold' => 35,
				'prediction_safety_buffer'        => 15,
			),
		);
	}

	/**
	 * Apply a preset configuration.
	 *
	 * @param string $preset_id Preset identifier.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function apply_preset( $preset_id ) {
		try {
			$presets = self::get_presets();

			if ( ! isset( $presets[ $preset_id ] ) ) {
				return new WP_Error(
					'invalid_preset',
					__( 'Invalid preset identifier.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			$preset = $presets[ $preset_id ];

			// Custom preset doesn't change anything.
			if ( 'custom' === $preset_id ) {
				WP_MCP_AI_Settings_Registry::update_setting( 'orchestration_preset', $preset_id );
				return true;
			}

			// Clear cache before reading settings to ensure fresh comparison.
			// This prevents false "value unchanged" returns from update_option()
			// when the cached value is stale but database value matches the new value.
			wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );

			// Apply preset settings.
			if ( ! empty( $preset['settings'] ) ) {
				foreach ( $preset['settings'] as $key => $value ) {
					// Get current value to check if it's already set.
					$current_value = WP_MCP_AI_Settings_Registry::get_setting( $key );

					// Only update if value is different to avoid false error logs.
					// WordPress update_option() returns false when value is unchanged.
					if ( $current_value !== $value ) {
						$result = WP_MCP_AI_Settings_Registry::update_setting( $key, $value );
						if ( false === $result ) {
							WP_MCP_AI_Logger::log_error(
								sprintf( 'Failed to update setting: %s', $key ),
								array(
									'preset_id' => $preset_id,
									'setting'   => $key,
									'value'     => $value,
								)
							);
						}
					}
				}
			}

			// Update the active preset.
			WP_MCP_AI_Settings_Registry::update_setting( 'orchestration_preset', $preset_id );

			// Clear WordPress object cache to ensure fresh values on next read.
			wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );

			// Log the preset application.
			WP_MCP_AI_Logger::log_event(
				'orchestration_preset_applied',
				sprintf( 'Applied orchestration preset: %s', $preset_id ),
				array(
					'preset_id' => $preset_id,
					'user_id'   => get_current_user_id(),
				)
			);

			return true;

		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				sprintf( 'Preset application failed: %s', $e->getMessage() ),
				array(
					'preset_id' => $preset_id,
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);

			return new WP_Error(
				'preset_application_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to apply preset: %s', 'wp-mcp-ai' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get current active preset ID.
	 *
	 * @return string Active preset ID.
	 */
	public static function get_active_preset() {
		return WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset', 'custom' );
	}

	/**
	 * Check if current settings match a preset.
	 *
	 * @param string $preset_id Preset identifier to check against.
	 * @param array  $settings Optional. Settings array to check. If not provided, uses current saved settings.
	 * @return bool True if settings match the preset.
	 */
	public static function matches_preset( $preset_id, $settings = null ) {
		$presets = self::get_presets();

		if ( ! isset( $presets[ $preset_id ] ) ) {
			return false;
		}

		$preset = $presets[ $preset_id ];

		if ( empty( $preset['settings'] ) ) {
			return true; // Custom preset always matches.
		}

		// Check if all preset settings match current values.
		foreach ( $preset['settings'] as $key => $expected_value ) {
			if ( null !== $settings && isset( $settings[ $key ] ) ) {
				// Use provided settings array.
				$current_value = $settings[ $key ];
			} else {
				// Fall back to saved settings.
				$current_value = WP_MCP_AI_Settings_Registry::get_setting( $key );
			}
			if ( $current_value !== $expected_value ) {
				return false;
			}
		}

		return true;
	}
}
