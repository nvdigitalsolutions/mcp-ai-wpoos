<?php
/**
 * Orchestration Health Monitoring Service
 *
 * Handles health status monitoring, metrics collection, and predictive analytics
 * for the orchestration layer. Implements graceful degradation if monitoring fails.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestration Health Service class
 *
 * Responsible for:
 * - System health status calculation
 * - Memory usage tracking
 * - Error rate monitoring
 * - Performance metrics collection
 * - Predictive insights generation
 *
 * Note: All methods use defensive programming to ensure the plugin
 * continues functioning even if health monitoring fails.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Orchestration_Health_Service {

	/**
	 * Settings repository instance
	 *
	 * @var WP_MCP_AI_Settings_Repository
	 */
	private static $settings_repository;

	/**
	 * Get settings repository instance
	 *
	 * @return WP_MCP_AI_Settings_Repository Settings repository instance.
	 */
	private static function get_settings_repository() {
		if ( null === self::$settings_repository ) {
			self::$settings_repository = wp_mcp_ai_get_settings_repository();
		}
		return self::$settings_repository;
	}

	/**
	 * Set settings repository instance (for testing)
	 *
	 * @param WP_MCP_AI_Settings_Repository $repository Settings repository instance.
	 */
	public static function set_settings_repository( $repository ) {
		self::$settings_repository = $repository;
	}

	/**
	 * Get current system health status.
	 *
	 * Returns health status with graceful degradation if components fail.
	 * Uses transient caching for performance on admin pages.
	 *
	 * @param bool $force_refresh Force refresh of cached data. Default false.
	 * @return array Health status array with 'status', 'label', 'icon', and 'metrics'.
	 */
	public static function get_health_status( $force_refresh = false ) {
		// Check cache first for performance.
		if ( ! $force_refresh ) {
			$cached = WP_MCP_AI_Cache_Helper::get( 'health_status' );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		try {
			$metrics = self::get_health_metrics();

			// Determine overall health status based on thresholds.
			$status = self::calculate_status( $metrics );

			$health_status = array(
				'status'  => $status['level'],
				'label'   => $status['label'],
				'icon'    => $status['icon'],
				'metrics' => $metrics,
			);

			// Cache for 1 minute to reduce load on admin dashboard.
			WP_MCP_AI_Cache_Helper::set( 'health_status', $health_status, MINUTE_IN_SECONDS );

			return $health_status;

		} catch ( Exception $e ) {
			// Log the error but don't break the plugin.
			WP_MCP_AI_Logger::log_error(
				'Health status check failed: ' . $e->getMessage(),
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);

			// Return safe defaults.
			return array(
				'status'  => 'unknown',
				'label'   => self::get_translated_label( 'unknown' ),
				'icon'    => '○',
				'metrics' => self::get_default_metrics(),
			);
		}
	}

	/**
	 * Get health metrics.
	 *
	 * @return array Array of health metrics.
	 */
	public static function get_health_metrics() {
		$metrics = array();

		// Memory usage (with fallback).
		try {
			$metrics['memory'] = self::get_memory_usage();
		} catch ( Exception $e ) {
			$metrics['memory'] = array(
				'percent' => 0,
				'usage'   => 0,
				'limit'   => 0,
			);
		}

		// Error rate (with fallback).
		try {
			$metrics['error_rate'] = self::get_error_rate();
		} catch ( Exception $e ) {
			$metrics['error_rate'] = 0;
		}

		// Average response time (with fallback).
		try {
			$metrics['avg_response'] = self::get_average_response_time();
		} catch ( Exception $e ) {
			$metrics['avg_response'] = 0;
		}

		return $metrics;
	}

	/**
	 * Get memory usage information.
	 *
	 * @return array Memory usage data.
	 */
	private static function get_memory_usage() {
		if ( ! class_exists( 'WP_MCP_AI_Resource_Manager' ) ) {
			throw new Exception( 'Resource manager not available' );
		}

		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$memory_limit     = $resource_manager->get_memory_limit();
		$memory_usage     = memory_get_usage( true );
		$memory_percent   = ( $memory_limit > 0 ) ? ( $memory_usage / $memory_limit ) * 100 : 0;

		return array(
			'percent' => round( $memory_percent, 1 ),
			'usage'   => $memory_usage,
			'limit'   => $memory_limit,
		);
	}

	/**
	 * Get error rate from recent activity.
	 *
	 * @return float Error rate as percentage.
	 */
	private static function get_error_rate() {
		// Get recent errors from the last hour.
		$recent_errors = self::get_settings_repository()->get( 'recent_errors', array() );

		if ( empty( $recent_errors ) ) {
			return 0.0;
		}

		// Count errors in the last hour.
		$one_hour_ago = time() - HOUR_IN_SECONDS;
		$error_count  = 0;
		$total_count  = 0;

		foreach ( $recent_errors as $error ) {
			if ( isset( $error['timestamp'] ) && $error['timestamp'] > $one_hour_ago ) {
				++$error_count;
			}
		}

		// Get total activity count (errors + successful operations).
		$recent_activity = self::get_settings_repository()->get( 'recent_activity', array() );
		foreach ( $recent_activity as $activity ) {
			if ( isset( $activity['timestamp'] ) && $activity['timestamp'] > $one_hour_ago ) {
				++$total_count;
			}
		}

		if ( $total_count === 0 ) {
			return 0.0;
		}

		return round( ( $error_count / $total_count ) * 100, 1 );
	}

	/**
	 * Get average response time.
	 *
	 * @return float Average response time in seconds.
	 */
	private static function get_average_response_time() {
		// Get recent activity from the last hour.
		$recent_activity = self::get_settings_repository()->get( 'recent_activity', array() );

		if ( empty( $recent_activity ) ) {
			return 0.0;
		}

		$one_hour_ago = time() - HOUR_IN_SECONDS;
		$total_time   = 0;
		$count        = 0;

		foreach ( $recent_activity as $activity ) {
			if ( isset( $activity['timestamp'], $activity['duration'] )
				&& $activity['timestamp'] > $one_hour_ago ) {
				$total_time += $activity['duration'];
				++$count;
			}
		}

		if ( $count === 0 ) {
			return 0.0;
		}

		return round( $total_time / $count, 1 );
	}

	/**
	 * Calculate health status based on metrics.
	 *
	 * @param array $metrics Health metrics.
	 * @return array Status information.
	 */
	private static function calculate_status( $metrics ) {
		$memory_warning  = WP_MCP_AI_Settings_Registry::get_setting( 'memory_warning_threshold', 75 );
		$memory_critical = WP_MCP_AI_Settings_Registry::get_setting( 'memory_critical_threshold', 90 );
		$error_warning   = WP_MCP_AI_Settings_Registry::get_setting( 'error_rate_warning_threshold', 10 );
		$error_critical  = WP_MCP_AI_Settings_Registry::get_setting( 'error_rate_critical_threshold', 20 );

		$memory_percent = isset( $metrics['memory']['percent'] ) ? $metrics['memory']['percent'] : 0;
		$error_rate     = isset( $metrics['error_rate'] ) ? $metrics['error_rate'] : 0;

		// Critical state.
		if ( $memory_percent >= $memory_critical || $error_rate >= $error_critical ) {
			return array(
				'level' => 'critical',
				'label' => self::get_translated_label( 'critical' ),
				'icon'  => '✖',
			);
		}

		// Warning state.
		if ( $memory_percent >= $memory_warning || $error_rate >= $error_warning ) {
			return array(
				'level' => 'warning',
				'label' => self::get_translated_label( 'warning' ),
				'icon'  => '⚠',
			);
		}

		// Healthy state.
		return array(
			'level' => 'healthy',
			'label' => self::get_translated_label( 'healthy' ),
			'icon'  => '●',
		);
	}

	/**
	 * Get default metrics when health check fails.
	 *
	 * @return array Default metrics.
	 */
	private static function get_default_metrics() {
		return array(
			'memory'       => array(
				'percent' => 0,
				'usage'   => 0,
				'limit'   => 0,
			),
			'error_rate'   => 0,
			'avg_response' => 0,
		);
	}

	/**
	 * Get predictive insights based on historical data.
	 *
	 * Returns empty array if prediction fails - plugin continues normally.
	 *
	 * @return array Array of predictive insights.
	 */
	public static function get_predictive_insights() {
		try {
			// Check if predictive optimization is enabled.
			$enabled = WP_MCP_AI_Settings_Registry::get_setting( 'enable_predictive_optimization', true );
			if ( ! $enabled ) {
				return array();
			}

			$confidence_threshold = WP_MCP_AI_Settings_Registry::get_setting( 'prediction_confidence_threshold', 30 );

			// TODO: Implement actual predictive analytics.
			// For now, return empty array - will be implemented in future enhancement.
			$insights = array();

			// Example structure for future implementation:
			/*
			$insights = array(
				array(
					'message'    => 'Token usage increasing by 15% over past 24h',
					'confidence' => 85,
					'action'     => 'Consider increasing token limits',
				),
			);
			*/

			// Filter insights by confidence threshold.
			return array_filter(
				$insights,
				function ( $insight ) use ( $confidence_threshold ) {
					return isset( $insight['confidence'] ) && $insight['confidence'] >= $confidence_threshold;
				}
			);

		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				'Predictive insights generation failed: ' . $e->getMessage(),
				array(
					'exception' => $e->getMessage(),
				)
			);

			// Return empty array - don't break the plugin.
			return array();
		}
	}

	/**
	 * Record activity for health monitoring.
	 *
	 * @param string $type     Activity type (e.g., 'chat', 'tool_execution').
	 * @param float  $duration Duration in seconds.
	 * @param bool   $is_error Whether this was an error.
	 */
	public static function record_activity( $type, $duration, $is_error = false ) {
		try {
			$activity = array(
				'type'      => $type,
				'timestamp' => time(),
				'duration'  => $duration,
			);

			// Record in recent activity.
			$recent_activity   = self::get_settings_repository()->get( 'recent_activity', array() );
			$recent_activity[] = $activity;

			// Keep only last 100 activities.
			$recent_activity = array_slice( $recent_activity, -100 );
			self::get_settings_repository()->update( 'recent_activity', $recent_activity );

			// Record errors separately.
			if ( $is_error ) {
				$recent_errors   = self::get_settings_repository()->get( 'recent_errors', array() );
				$recent_errors[] = $activity;
				$recent_errors   = array_slice( $recent_errors, -50 );
				self::get_settings_repository()->update( 'recent_errors', $recent_errors );
			}
		} catch ( Exception $e ) {
			// Silent fail - don't break plugin operations just for logging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP_MCP_AI: Failed to record activity: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Clear health monitoring data.
	 *
	 * Useful for troubleshooting or resetting metrics.
	 * Also clears cached health status.
	 */
	public static function clear_health_data() {
		self::get_settings_repository()->delete( 'recent_activity' );
		self::get_settings_repository()->delete( 'recent_errors' );
		WP_MCP_AI_Cache_Helper::delete( 'health_status' );
	}

	/**
	 * Clear cached health status.
	 *
	 * Call this when settings change to force refresh on next load.
	 */
	public static function clear_health_cache() {
		WP_MCP_AI_Cache_Helper::delete( 'health_status' );
	}

	/**
	 * Get translated label for a status level.
	 *
	 * This method provides lazy translation to avoid loading translations too early.
	 *
	 * @param string $level Status level (critical, warning, healthy, unknown).
	 * @return string Translated label.
	 */
	private static function get_translated_label( $level ) {
		$labels = array(
			'critical' => __( 'Critical', 'wp-mcp-ai' ),
			'warning'  => __( 'Warning', 'wp-mcp-ai' ),
			'healthy'  => __( 'Healthy', 'wp-mcp-ai' ),
			'unknown'  => __( 'Unknown', 'wp-mcp-ai' ),
		);

		return isset( $labels[ $level ] ) ? $labels[ $level ] : $labels['unknown'];
	}
}
