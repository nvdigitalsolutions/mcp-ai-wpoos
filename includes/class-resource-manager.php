<?php
/**
 * Resource Manager for dynamic AI resource management.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Resource_Manager' ) ) {
	/**
	 * Manages server resources and provides intelligent limits for AI operations.
	 */
	class WP_MCP_AI_Resource_Manager {

		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_Resource_Manager
		 */
		private static $instance;

		/**
		 * Cached memory limit in bytes.
		 *
		 * @var int|null
		 */
		private $memory_limit_bytes = null;

		/**
		 * Cached max execution time in seconds.
		 *
		 * @var int|null
		 */
		private $max_execution_time = null;

		/**
		 * Cached workload tier.
		 *
		 * @var string|null
		 */
		private $workload_tier = null;

/**
 * Resource usage history for predictive forecasting.
 *
 * @var array|null
 */
private $usage_history = null;

/**
 * Health status cache.
 *
 * @var array|null
 */
private $health_status = null;

		/**
		 * Returns the singleton instance.
		 *
		 * @return WP_MCP_AI_Resource_Manager
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private constructor to prevent direct instantiation.
		 */
		private function __construct() {
			// Initialization happens via method calls.
		}

		/**
		 * Get the PHP memory limit in bytes.
		 *
		 * @return int Memory limit in bytes.
		 */
		public function get_memory_limit() {
			if ( null !== $this->memory_limit_bytes ) {
				return $this->memory_limit_bytes;
			}

			$memory_limit = ini_get( 'memory_limit' );

			if ( empty( $memory_limit ) || '-1' === $memory_limit ) {
				// Unlimited or not set - assume a high value.
				$this->memory_limit_bytes = 512 * 1024 * 1024; // 512MB default for unlimited.
				return $this->memory_limit_bytes;
			}

			$this->memory_limit_bytes = $this->parse_size_to_bytes( $memory_limit );

			return $this->memory_limit_bytes;
		}

		/**
		 * Get the PHP max execution time in seconds.
		 *
		 * @return int Max execution time in seconds.
		 */
		public function get_max_execution_time() {
			if ( null !== $this->max_execution_time ) {
				return $this->max_execution_time;
			}

			$max_execution_time = ini_get( 'max_execution_time' );

			if ( empty( $max_execution_time ) || '0' === $max_execution_time ) {
				// Unlimited or not set - assume a reasonable default.
				$this->max_execution_time = 30; // 30 seconds default.
			} else {
				$this->max_execution_time = absint( $max_execution_time );
			}

			return $this->max_execution_time;
		}

		/**
		 * Determine the workload tier based on available resources.
		 *
		 * Tiers:
		 * - low: memory_limit < 128M
		 * - medium: 128M <= memory_limit < 512M
		 * - high: memory_limit >= 512M
		 *
		 * @return string The workload tier: 'low', 'medium', or 'high'.
		 */
		public function get_workload_tier() {
			if ( null !== $this->workload_tier ) {
				return $this->workload_tier;
			}

			$memory_limit = $this->get_memory_limit();

			// Define tier thresholds.
			$low_threshold    = 128 * 1024 * 1024; // 128MB.
			$medium_threshold = 512 * 1024 * 1024; // 512MB.

			if ( $memory_limit < $low_threshold ) {
				$this->workload_tier = 'low';
			} elseif ( $memory_limit < $medium_threshold ) {
				$this->workload_tier = 'medium';
			} else {
				$this->workload_tier = 'high';
			}

			/**
			 * Filter the determined workload tier.
			 *
			 * @param string $tier         The workload tier: 'low', 'medium', or 'high'.
			 * @param int    $memory_limit Memory limit in bytes.
			 */
			$this->workload_tier = apply_filters( 'wp_mcp_ai_workload_tier', $this->workload_tier, $memory_limit );

			return $this->workload_tier;
		}

		/**
		 * Get the recommended maximum tokens based on the current workload tier.
		 *
		 * @return int Recommended maximum tokens.
		 */
		public function get_max_tokens() {
			$tier = $this->get_workload_tier();

			$max_tokens_map = array(
				'low'    => WP_MCP_AI_Settings_Registry::get_setting( 'max_tokens_low_tier', 1000 ),
				'medium' => WP_MCP_AI_Settings_Registry::get_setting( 'max_tokens_medium_tier', 4000 ),
				'high'   => WP_MCP_AI_Settings_Registry::get_setting( 'max_tokens_high_tier', 16000 ),
			);

			$max_tokens = isset( $max_tokens_map[ $tier ] ) ? $max_tokens_map[ $tier ] : 4000;

			/**
			 * Filter the recommended maximum tokens.
			 *
			 * @param int    $max_tokens The recommended maximum tokens.
			 * @param string $tier       The current workload tier.
			 */
			return apply_filters( 'wp_mcp_ai_resource_max_tokens', $max_tokens, $tier );
		}

		/**
		 * Get the recommended request timeout based on the current workload tier.
		 *
		 * @return int Recommended timeout in seconds.
		 */
		public function get_request_timeout() {
			$tier               = $this->get_workload_tier();
			$max_execution_time = $this->get_max_execution_time();

			$timeout_map = array(
				'low'    => 30,
				'medium' => 60,
				'high'   => 120,
			);

			$base_timeout = isset( $timeout_map[ $tier ] ) ? $timeout_map[ $tier ] : 60;

			// Ensure timeout doesn't exceed max_execution_time minus a buffer.
			$max_allowed_timeout = max( 5, $max_execution_time - 5 );
			$timeout             = min( $base_timeout, $max_allowed_timeout );

			/**
			 * Filter the recommended request timeout.
			 *
			 * @param int    $timeout             The recommended timeout in seconds.
			 * @param string $tier                The current workload tier.
			 * @param int    $max_execution_time  The PHP max_execution_time setting.
			 */
			return apply_filters( 'wp_mcp_ai_resource_request_timeout', $timeout, $tier, $max_execution_time );
		}

		/**
		 * Parse a size string (e.g., '256M', '1G') to bytes.
		 *
		 * @param string $size The size string to parse.
		 * @return int The size in bytes.
		 */
		private function parse_size_to_bytes( $size ) {
			$size = trim( $size );
			$unit = strtoupper( substr( $size, -1 ) );
			$num  = (int) $size;

			switch ( $unit ) {
				case 'G':
					$num *= 1024 * 1024 * 1024;
					break;
				case 'M':
					$num *= 1024 * 1024;
					break;
				case 'K':
					$num *= 1024;
					break;
			}

			return $num;
		}

		/**
		 * Check if the current environment can handle a specific operation.
		 *
		 * @param array $requirements Operation requirements (e.g., ['max_tokens' => 8000]).
		 * @return bool|WP_Error True if operation can be handled, WP_Error otherwise.
		 */
		public function can_handle_operation( $requirements = array() ) {
			$tier       = $this->get_workload_tier();
			$max_tokens = $this->get_max_tokens();

			if ( isset( $requirements['max_tokens'] ) && $requirements['max_tokens'] > $max_tokens ) {
				return new WP_Error(
					'wp_mcp_ai_insufficient_resources',
					sprintf(
						/* translators: 1: Requested tokens, 2: Maximum tokens, 3: Workload tier */
						__( 'The requested operation requires %1$d tokens, but the server is configured for a maximum of %2$d tokens (workload tier: %3$s).', 'wp-mcp-ai' ),
						$requirements['max_tokens'],
						$max_tokens,
						$tier
					),
					array(
						'status'           => 503,
						'tier'             => $tier,
						'max_tokens'       => $max_tokens,
						'requested_tokens' => $requirements['max_tokens'],
					)
				);
			}

			return true;
		}

	/**
	 * Get resource usage history for predictive forecasting.
	 *
	 * @param int $hours Number of hours of history to retrieve.
	 * @return array Usage history data.
	 */
	public function get_usage_history( $hours = 24 ) {
		$cache_key = 'wp_mcp_ai_resource_history_' . absint( $hours );
		$history   = get_transient( $cache_key );

		if ( false !== $history && is_array( $history ) ) {
			return $history;
		}

		// Get historical usage data from WordPress options.
		$history_data = get_option( 'wp_mcp_ai_resource_usage_history', array() );

		if ( ! is_array( $history_data ) ) {
			$history_data = array();
		}

		$cutoff_time = time() - ( $hours * HOUR_IN_SECONDS );
		$filtered    = array();

		foreach ( $history_data as $timestamp => $data ) {
			if ( $timestamp >= $cutoff_time ) {
				$filtered[ $timestamp ] = $data;
			}
		}

		// Cache for 5 minutes.
		set_transient( $cache_key, $filtered, 5 * MINUTE_IN_SECONDS );

		return $filtered;
	}

	/**
	 * Record resource usage for historical tracking.
	 *
	 * @param array $usage_data Usage data to record.
	 */
	public function record_usage( $usage_data ) {
		$history = get_option( 'wp_mcp_ai_resource_usage_history', array() );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$timestamp = time();

		$history[ $timestamp ] = array_merge(
			$usage_data,
			array(
				'timestamp'    => $timestamp,
				'memory_used'  => memory_get_usage( true ),
				'memory_peak'  => memory_get_peak_usage( true ),
				'memory_limit' => $this->get_memory_limit(),
				'tier'         => $this->get_workload_tier(),
			)
		);

		// Keep only last 7 days of data.
		$cutoff_time = time() - ( 7 * DAY_IN_SECONDS );
		foreach ( $history as $ts => $data ) {
			if ( $ts < $cutoff_time ) {
				unset( $history[ $ts ] );
			}
		}

		update_option( 'wp_mcp_ai_resource_usage_history', $history, false );

		// Clear the cache.
		delete_transient( 'wp_mcp_ai_resource_history_24' );
	}

	/**
	 * Predict resource requirements based on historical data.
	 *
	 * @param string $operation_type Type of operation to predict for.
	 * @return array Predicted resource requirements.
	 */
	public function predict_requirements( $operation_type = 'chat' ) {
		$history = $this->get_usage_history( 24 );

		if ( empty( $history ) ) {
			// No history available, return default predictions.
			return array(
				'predicted_tokens'  => $this->get_max_tokens() * 0.5,
				'predicted_memory'  => $this->get_memory_limit() * 0.3,
				'predicted_time'    => 10,
				'confidence'        => 0,
				'recommendation'    => 'insufficient_data',
			);
		}

		// Filter by operation type if available.
		$relevant_history = array();
		foreach ( $history as $timestamp => $data ) {
			if ( isset( $data['operation_type'] ) && $data['operation_type'] === $operation_type ) {
				$relevant_history[ $timestamp ] = $data;
			}
		}

		if ( empty( $relevant_history ) ) {
			$relevant_history = $history; // Use all data if no type-specific data.
		}

		// Calculate averages and trends.
		$token_usage  = array();
		$memory_usage = array();
		$time_usage   = array();

		foreach ( $relevant_history as $data ) {
			if ( isset( $data['tokens_used'] ) ) {
				$token_usage[] = $data['tokens_used'];
			}
			if ( isset( $data['memory_used'] ) ) {
				$memory_usage[] = $data['memory_used'];
			}
			if ( isset( $data['execution_time'] ) ) {
				$time_usage[] = $data['execution_time'];
			}
		}

		$avg_tokens = ! empty( $token_usage ) ? array_sum( $token_usage ) / count( $token_usage ) : 0;
		$avg_memory = ! empty( $memory_usage ) ? array_sum( $memory_usage ) / count( $memory_usage ) : 0;
		$avg_time   = ! empty( $time_usage ) ? array_sum( $time_usage ) / count( $time_usage ) : 0;

		// Add safety buffer for prediction (configurable via settings).
		$buffer_multiplier = 1.0 + ( WP_MCP_AI_Settings_Registry::get_setting( 'prediction_buffer_percent', 20 ) / 100.0 );
		$predicted_tokens  = $avg_tokens * $buffer_multiplier;
		$predicted_memory  = $avg_memory * $buffer_multiplier;
		$predicted_time    = $avg_time * $buffer_multiplier;

		// Calculate confidence based on sample size.
		$sample_size = count( $relevant_history );
		$confidence  = min( 1, $sample_size / 50 ); // 100% confidence at 50+ samples.

		// Determine recommendation.
		$recommendation = 'proceed';
		if ( $predicted_tokens > $this->get_max_tokens() * 0.9 ) {
			$recommendation = 'consider_larger_tier';
		} elseif ( $predicted_memory > $this->get_memory_limit() * 0.8 ) {
			$recommendation = 'monitor_memory';
		}

		return array(
			'predicted_tokens'  => (int) $predicted_tokens,
			'predicted_memory'  => (int) $predicted_memory,
			'predicted_time'    => (int) $predicted_time,
			'confidence'        => $confidence,
			'sample_size'       => $sample_size,
			'recommendation'    => $recommendation,
			'avg_tokens'        => (int) $avg_tokens,
			'avg_memory'        => (int) $avg_memory,
			'avg_time'          => (int) $avg_time,
		);
	}

	/**
	 * Get health status of resource management system.
	 *
	 * @return array Health status information.
	 */
	public function get_health_status() {
		$cache_key = 'wp_mcp_ai_resource_health';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$memory_limit     = $this->get_memory_limit();
		$memory_used      = memory_get_usage( true );
		$memory_peak      = memory_get_peak_usage( true );
		$memory_available = $memory_limit - $memory_used;
		$memory_percent   = ( $memory_used / $memory_limit ) * 100;

		$tier       = $this->get_workload_tier();
		$max_tokens = $this->get_max_tokens();

		// Get recent usage history.
		$history = $this->get_usage_history( 1 ); // Last hour.

		$recent_failures = 0;
		$recent_requests = count( $history );
		$avg_response_time = 0;

		foreach ( $history as $data ) {
			if ( isset( $data['status'] ) && 'error' === $data['status'] ) {
				$recent_failures++;
			}
			if ( isset( $data['execution_time'] ) ) {
				$avg_response_time += $data['execution_time'];
			}
		}

		if ( $recent_requests > 0 ) {
			$avg_response_time = $avg_response_time / $recent_requests;
		}

		$error_rate = $recent_requests > 0 ? ( $recent_failures / $recent_requests ) * 100 : 0;

		// Determine overall health.
		$health = 'healthy';
		$issues = array();

		$memory_critical_threshold = WP_MCP_AI_Settings_Registry::get_setting( 'memory_critical_threshold', 90 );
		$memory_warning_threshold  = WP_MCP_AI_Settings_Registry::get_setting( 'memory_warning_threshold', 75 );
		$error_critical_threshold  = WP_MCP_AI_Settings_Registry::get_setting( 'error_rate_critical_threshold', 20 );
		$error_warning_threshold   = WP_MCP_AI_Settings_Registry::get_setting( 'error_rate_warning_threshold', 10 );

		if ( $memory_percent > $memory_critical_threshold ) {
			$health   = 'critical';
			$issues[] = 'memory_critical';
		} elseif ( $memory_percent > $memory_warning_threshold ) {
			$health   = 'warning';
			$issues[] = 'memory_high';
		}

		if ( $error_rate > $error_critical_threshold ) {
			$health   = 'critical';
			$issues[] = 'high_error_rate';
		} elseif ( $error_rate > $error_warning_threshold ) {
			if ( 'healthy' === $health ) {
				$health = 'warning';
			}
			$issues[] = 'elevated_error_rate';
		}

		$status = array(
			'overall_health'    => $health,
			'issues'            => $issues,
			'memory'            => array(
				'limit'     => $memory_limit,
				'used'      => $memory_used,
				'peak'      => $memory_peak,
				'available' => $memory_available,
				'percent'   => round( $memory_percent, 2 ),
			),
			'tier'              => $tier,
			'max_tokens'        => $max_tokens,
			'metrics'           => array(
				'recent_requests'   => $recent_requests,
				'recent_failures'   => $recent_failures,
				'error_rate'        => round( $error_rate, 2 ),
				'avg_response_time' => round( $avg_response_time, 2 ),
			),
			'timestamp'         => time(),
		);

		// Log critical health issues to SIEM.
		if ( 'critical' === $health && class_exists( 'WP_MCP_AI_SIEM_Logger' ) ) {
			$siem = WP_MCP_AI_SIEM_Logger::get_instance();
			$siem->export_event(
				'resource_health_critical',
				'Resource manager health status is critical',
				array(
					'issues'         => $issues,
					'memory_percent' => $memory_percent,
					'error_rate'     => $error_rate,
				),
				'critical'
			);
		}

		// Cache for 1 minute.
		set_transient( $cache_key, $status, MINUTE_IN_SECONDS );

		return $status;
	}

	/**
	 * Get adaptive budget recommendation based on current load.
	 *
	 * @param string $priority Priority level: 'high', 'medium', 'low'.
	 * @return int Recommended token budget.
	 */
	public function get_adaptive_budget( $priority = 'medium' ) {
		$health   = $this->get_health_status();
		$base_max = $this->get_max_tokens();

		// Adjust based on health status (settings are percentages, convert to decimal).
		$multiplier = 1.0;

		if ( 'critical' === $health['overall_health'] ) {
			$multiplier = WP_MCP_AI_Settings_Registry::get_setting( 'budget_critical_health_percent', 50 ) / 100.0;
		} elseif ( 'warning' === $health['overall_health'] ) {
			$multiplier = WP_MCP_AI_Settings_Registry::get_setting( 'budget_warning_health_percent', 75 ) / 100.0;
		}

		// Adjust based on priority (settings are percentages, convert to decimal).
		$priority_multipliers = array(
			'high'   => WP_MCP_AI_Settings_Registry::get_setting( 'budget_high_priority_percent', 100 ) / 100.0,
			'medium' => WP_MCP_AI_Settings_Registry::get_setting( 'budget_medium_priority_percent', 80 ) / 100.0,
			'low'    => WP_MCP_AI_Settings_Registry::get_setting( 'budget_low_priority_percent', 50 ) / 100.0,
		);

		$priority_mult = isset( $priority_multipliers[ $priority ] ) ? $priority_multipliers[ $priority ] : 0.8;

		$adaptive_budget = (int) ( $base_max * $multiplier * $priority_mult );

		// Ensure minimum budget.
		return max( 100, $adaptive_budget );
	}
	}
}
