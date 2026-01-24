<?php
/**
 * Tool Load Monitor Service
 *
 * Monitors tool execution load using Little's Law to provide capacity-aware
 * orchestration. Tracks tool execution metrics and calculates system capacity
 * in real-time to prevent overload and optimize performance.
 *
 * Little's Law: L = λ × W
 * - L = average number of items in system (queue length)
 * - λ (lambda) = average arrival rate (executions per second)
 * - W = average service time (execution time in seconds)
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitors and tracks tool execution load for capacity-aware orchestration.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Load_Monitor {

	/**
	 * Storage keys for tracking data
	 */
	const ACTIVE_EXECUTIONS_KEY   = 'wp_mcp_ai_tool_active_executions';
	const PERFORMANCE_HISTORY_KEY = 'wp_mcp_ai_tool_performance_history_';
	const METRICS_CACHE_KEY       = 'wp_mcp_ai_tool_load_metrics_';
	const ARRIVAL_RATE_KEY        = 'wp_mcp_ai_tool_arrival_rate_';

	/**
	 * Configuration constants
	 */
	const MAX_HISTORY_ENTRIES = 1000;  // Ring buffer size per tool.
	const METRICS_CACHE_TTL   = 60;    // Cache TTL in seconds.
	const ARRIVAL_WINDOW      = 60;    // Window for calculating arrival rate (seconds).
	const SERVICE_TIME_WINDOW = 100;   // Number of executions for service time average.

	/**
	 * SLA Manager instance
	 *
	 * @var WP_MCP_AI_SLA_Manager|null
	 */
	protected $sla_manager;

	/**
	 * Constructor
	 */
	public function __construct() {
		// SLA Manager is used for tier information.
		if ( class_exists( 'WP_MCP_AI_SLA_Manager' ) ) {
			$this->sla_manager = new WP_MCP_AI_SLA_Manager();
		}
	}

	/**
	 * Record tool execution start
	 *
	 * Increments active execution counter and records start timestamp.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $context Execution context.
	 * @return bool Success status.
	 */
	public function record_execution_start( $tool_slug, $context = array() ) {
		$tool_slug = sanitize_key( $tool_slug );

		// Get current active executions.
		$active = get_transient( self::ACTIVE_EXECUTIONS_KEY );
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		// Initialize tool counter if needed.
		if ( ! isset( $active[ $tool_slug ] ) ) {
			$active[ $tool_slug ] = array(
				'count'      => 0,
				'executions' => array(),
			);
		}

		// Increment counter and record start time.
		++$active[ $tool_slug ]['count'];

		$execution_id                                        = uniqid( 'exec_', true );
		$active[ $tool_slug ]['executions'][ $execution_id ] = array(
			'start_time' => microtime( true ),
			'context'    => $context,
		);

		// Store updated active executions (30 minute TTL).
		set_transient( self::ACTIVE_EXECUTIONS_KEY, $active, 30 * MINUTE_IN_SECONDS );

		// Update arrival rate tracking.
		$this->record_arrival( $tool_slug );

		// Clear cached metrics.
		wp_cache_delete( self::METRICS_CACHE_KEY . $tool_slug, 'mcp_ai_tool_metrics' );

		return true;
	}

	/**
	 * Record tool execution completion
	 *
	 * Decrements active counter, calculates duration, and stores performance data.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param float  $duration Execution duration in seconds.
	 * @param bool   $success Whether execution succeeded.
	 * @param array  $context Execution context.
	 * @return bool Success status.
	 */
	public function record_execution_complete( $tool_slug, $duration, $success, $context = array() ) {
		$tool_slug = sanitize_key( $tool_slug );

		// Update active executions.
		$active = get_transient( self::ACTIVE_EXECUTIONS_KEY );
		if ( is_array( $active ) && isset( $active[ $tool_slug ] ) ) {
			if ( $active[ $tool_slug ]['count'] > 0 ) {
				--$active[ $tool_slug ]['count'];
			}

			// Clean up completed execution records (keep only active ones).
			if ( ! empty( $active[ $tool_slug ]['executions'] ) ) {
				// Remove oldest execution record.
				$keys = array_keys( $active[ $tool_slug ]['executions'] );
				if ( ! empty( $keys ) ) {
					unset( $active[ $tool_slug ]['executions'][ $keys[0] ] );
				}
			}

			set_transient( self::ACTIVE_EXECUTIONS_KEY, $active, 30 * MINUTE_IN_SECONDS );
		}

		// Store performance data.
		$this->store_performance_data( $tool_slug, $duration, $success, $context );

		// Clear cached metrics.
		wp_cache_delete( self::METRICS_CACHE_KEY . $tool_slug, 'mcp_ai_tool_metrics' );

		return true;
	}

	/**
	 * Get current load metrics for a tool
	 *
	 * Returns comprehensive metrics including Little's Law calculations.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return array Load metrics with arrival_rate, service_time, queue_length, utilization, capacity_score.
	 */
	public function get_load_metrics( $tool_slug ) {
		$tool_slug = sanitize_key( $tool_slug );

		// Check cache first.
		$cache_key = self::METRICS_CACHE_KEY . $tool_slug;
		$cached    = wp_cache_get( $cache_key, 'mcp_ai_tool_metrics' );

		if ( false !== $cached ) {
			return $cached;
		}

		// Calculate metrics.
		$arrival_rate = $this->calculate_arrival_rate( $tool_slug );
		$service_time = $this->calculate_service_time( $tool_slug );
		$active_count = $this->get_active_count( $tool_slug );

		// Little's Law: L = λ × W.
		$queue_length = $arrival_rate * $service_time;

		// Utilization: ρ = λ × W.
		$utilization = $queue_length;

		// Capacity score: 100 when idle, 0 when fully utilized or overloaded.
		$capacity_score = max( 0, min( 100, 100 * ( 1 - $utilization ) ) );

		// Get SLA tier for this tool.
		$sla_tier = $this->get_tool_sla_tier( $tool_slug );

		$metrics = array(
			'tool_slug'      => $tool_slug,
			'arrival_rate'   => $arrival_rate,      // λ (executions per second).
			'service_time'   => $service_time,      // W (average execution time in seconds).
			'active_count'   => $active_count,      // Currently executing.
			'queue_length'   => $queue_length,      // L = λ × W (calculated).
			'utilization'    => $utilization,       // ρ = λ × W.
			'capacity_score' => $capacity_score,    // 0-100 (100 = available).
			'sla_tier'       => $sla_tier,          // realtime/near_realtime/batch.
			'timestamp'      => current_time( 'mysql' ),
		);

		// Cache for 60 seconds.
		wp_cache_set( $cache_key, $metrics, 'mcp_ai_tool_metrics', self::METRICS_CACHE_TTL );

		return $metrics;
	}

	/**
	 * Get system-wide load metrics
	 *
	 * Aggregates metrics across all tools to provide system health status.
	 *
	 * @return array System-wide metrics with health status and recommendations.
	 */
	public function get_system_load_metrics() {
		// Get all tools with recent activity.
		$active_tools = $this->get_active_tools();

		if ( empty( $active_tools ) ) {
			return array(
				'health_status'       => 'excellent',
				'overall_utilization' => 0,
				'available_capacity'  => 100,
				'active_tools'        => 0,
				'total_executions'    => 0,
				'recommendations'     => array(),
				'top_tools'           => array(),
			);
		}

		$total_utilization  = 0;
		$total_active_count = 0;
		$tool_metrics       = array();

		foreach ( $active_tools as $tool_slug ) {
			$metrics = $this->get_load_metrics( $tool_slug );

			$total_utilization  += $metrics['utilization'];
			$total_active_count += $metrics['active_count'];

			$tool_metrics[ $tool_slug ] = $metrics;
		}

		$tool_count         = count( $active_tools );
		$avg_utilization    = $tool_count > 0 ? $total_utilization / $tool_count : 0;
		$available_capacity = max( 0, 100 - ( $avg_utilization * 100 ) );

		// Determine health status.
		if ( $avg_utilization < 0.5 ) {
			$health_status = 'excellent';
		} elseif ( $avg_utilization < 0.7 ) {
			$health_status = 'good';
		} elseif ( $avg_utilization < 0.85 ) {
			$health_status = 'warning';
		} else {
			$health_status = 'critical';
		}

		// Generate recommendations.
		$recommendations = $this->generate_recommendations( $health_status, $tool_metrics );

		// Get top tools by utilization.
		uasort(
			$tool_metrics,
			function ( $a, $b ) {
				return $b['utilization'] <=> $a['utilization'];
			}
		);

		$top_tools = array_slice( $tool_metrics, 0, 10, true );

		return array(
			'health_status'       => $health_status,
			'overall_utilization' => $avg_utilization,
			'available_capacity'  => $available_capacity,
			'active_tools'        => $tool_count,
			'total_executions'    => $total_active_count,
			'recommendations'     => $recommendations,
			'top_tools'           => $top_tools,
			'timestamp'           => current_time( 'mysql' ),
		);
	}

	/**
	 * Get tool performance statistics
	 *
	 * Calculates P50, P95, P99 latencies and success rates.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param int    $hours Number of hours to analyze (default 24).
	 * @return array Performance statistics.
	 */
	public function get_tool_performance_stats( $tool_slug, $hours = 24 ) {
		$tool_slug = sanitize_key( $tool_slug );

		$history = $this->get_performance_history( $tool_slug, $hours );

		if ( empty( $history ) ) {
			return array(
				'tool_slug'    => $tool_slug,
				'total_count'  => 0,
				'success_rate' => 0,
				'avg_duration' => 0,
				'p50_latency'  => 0,
				'p95_latency'  => 0,
				'p99_latency'  => 0,
			);
		}

		// Extract durations and success flags.
		$durations = array_column( $history, 'duration' );
		$successes = array_filter(
			$history,
			function ( $entry ) {
				return ! empty( $entry['success'] );
			}
		);

		// Sort durations for percentile calculation.
		sort( $durations, SORT_NUMERIC );

		$total_count  = count( $history );
		$success_rate = $total_count > 0 ? ( count( $successes ) / $total_count ) * 100 : 0;
		$avg_duration = $total_count > 0 ? array_sum( $durations ) / $total_count : 0;

		// Calculate percentiles.
		$p50_latency = $this->calculate_percentile( $durations, 50 );
		$p95_latency = $this->calculate_percentile( $durations, 95 );
		$p99_latency = $this->calculate_percentile( $durations, 99 );

		return array(
			'tool_slug'    => $tool_slug,
			'total_count'  => $total_count,
			'success_rate' => round( $success_rate, 2 ),
			'avg_duration' => round( $avg_duration, 3 ),
			'p50_latency'  => round( $p50_latency, 3 ),
			'p95_latency'  => round( $p95_latency, 3 ),
			'p99_latency'  => round( $p99_latency, 3 ),
		);
	}

	/**
	 * Get capacity for a specific SLA tier
	 *
	 * @param string $tier SLA tier (realtime, near_realtime, batch).
	 * @return float Capacity score (0-100).
	 */
	public function get_tier_capacity( $tier ) {
		// Get all tools in this tier.
		$tools_in_tier = $this->get_tools_by_tier( $tier );

		if ( empty( $tools_in_tier ) ) {
			return 100; // No tools in tier = full capacity.
		}

		$total_capacity = 0;
		foreach ( $tools_in_tier as $tool_slug ) {
			$metrics         = $this->get_load_metrics( $tool_slug );
			$total_capacity += $metrics['capacity_score'];
		}

		return count( $tools_in_tier ) > 0 ? $total_capacity / count( $tools_in_tier ) : 100;
	}

	/**
	 * Record an arrival event for arrival rate calculation
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return void
	 */
	protected function record_arrival( $tool_slug ) {
		$key      = self::ARRIVAL_RATE_KEY . $tool_slug;
		$arrivals = get_transient( $key );

		if ( ! is_array( $arrivals ) ) {
			$arrivals = array();
		}

		$arrivals[] = microtime( true );

		// Keep only arrivals within the window.
		$cutoff   = microtime( true ) - self::ARRIVAL_WINDOW;
		$arrivals = array_filter(
			$arrivals,
			function ( $timestamp ) use ( $cutoff ) {
				return $timestamp > $cutoff;
			}
		);

		set_transient( $key, $arrivals, self::ARRIVAL_WINDOW + 60 );
	}

	/**
	 * Calculate arrival rate (λ) for a tool
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return float Arrival rate in executions per second.
	 */
	protected function calculate_arrival_rate( $tool_slug ) {
		$key      = self::ARRIVAL_RATE_KEY . $tool_slug;
		$arrivals = get_transient( $key );

		if ( ! is_array( $arrivals ) || empty( $arrivals ) ) {
			return 0.0;
		}

		// Filter to recent arrivals.
		$cutoff = microtime( true ) - self::ARRIVAL_WINDOW;
		$recent = array_filter(
			$arrivals,
			function ( $timestamp ) use ( $cutoff ) {
				return $timestamp > $cutoff;
			}
		);

		$count = count( $recent );

		// Arrivals per second = count / window_size.
		return $count / self::ARRIVAL_WINDOW;
	}

	/**
	 * Calculate average service time (W) for a tool
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return float Average service time in seconds.
	 */
	protected function calculate_service_time( $tool_slug ) {
		$history = $this->get_performance_history( $tool_slug, 1 ); // Last hour.

		if ( empty( $history ) ) {
			return 0.0;
		}

		// Use last N executions for service time.
		$recent    = array_slice( $history, -self::SERVICE_TIME_WINDOW );
		$durations = array_column( $recent, 'duration' );

		if ( empty( $durations ) ) {
			return 0.0;
		}

		return array_sum( $durations ) / count( $durations );
	}

	/**
	 * Get active execution count for a tool
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return int Number of currently executing instances.
	 */
	protected function get_active_count( $tool_slug ) {
		$active = get_transient( self::ACTIVE_EXECUTIONS_KEY );

		if ( ! is_array( $active ) || ! isset( $active[ $tool_slug ] ) ) {
			return 0;
		}

		return (int) $active[ $tool_slug ]['count'];
	}

	/**
	 * Store performance data in history
	 *
	 * Implements a ring buffer to limit history size.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param float  $duration Execution duration.
	 * @param bool   $success Whether execution succeeded.
	 * @param array  $context Execution context.
	 * @return void
	 */
	protected function store_performance_data( $tool_slug, $duration, $success, $context ) {
		$key     = self::PERFORMANCE_HISTORY_KEY . $tool_slug;
		$history = get_option( $key, array() );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		// Add new entry.
		$history[] = array(
			'duration'  => (float) $duration,
			'success'   => (bool) $success,
			'timestamp' => microtime( true ),
			'context'   => $context,
		);

		// Implement ring buffer - keep only last N entries.
		if ( count( $history ) > self::MAX_HISTORY_ENTRIES ) {
			$history = array_slice( $history, -self::MAX_HISTORY_ENTRIES );
		}

		update_option( $key, $history, false ); // No autoload.
	}

	/**
	 * Get performance history for a tool
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param int    $hours Number of hours to retrieve (default 24).
	 * @return array Performance history entries.
	 */
	protected function get_performance_history( $tool_slug, $hours = 24 ) {
		$key     = self::PERFORMANCE_HISTORY_KEY . $tool_slug;
		$history = get_option( $key, array() );

		if ( ! is_array( $history ) ) {
			return array();
		}

		// Filter by time window.
		$cutoff = microtime( true ) - ( $hours * HOUR_IN_SECONDS );

		return array_filter(
			$history,
			function ( $entry ) use ( $cutoff ) {
				return isset( $entry['timestamp'] ) && $entry['timestamp'] > $cutoff;
			}
		);
	}

	/**
	 * Get SLA tier for a tool
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return string SLA tier.
	 */
	protected function get_tool_sla_tier( $tool_slug ) {
		if ( ! $this->sla_manager || ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return 'batch';
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( $tool_slug );

		if ( ! $tool ) {
			return 'batch';
		}

		return WP_MCP_AI_SLA_Manager::get_tier_for_tool( $tool );
	}

	/**
	 * Get list of active tools
	 *
	 * @return array Tool slugs with recent activity.
	 */
	protected function get_active_tools() {
		$active = get_transient( self::ACTIVE_EXECUTIONS_KEY );

		if ( ! is_array( $active ) ) {
			return array();
		}

		return array_keys( $active );
	}

	/**
	 * Get tools by SLA tier
	 *
	 * @param string $tier SLA tier.
	 * @return array Tool slugs in the specified tier.
	 */
	protected function get_tools_by_tier( $tier ) {
		$active_tools  = $this->get_active_tools();
		$tools_in_tier = array();

		foreach ( $active_tools as $tool_slug ) {
			if ( $this->get_tool_sla_tier( $tool_slug ) === $tier ) {
				$tools_in_tier[] = $tool_slug;
			}
		}

		return $tools_in_tier;
	}

	/**
	 * Calculate percentile from sorted array
	 *
	 * @param array $sorted_values Sorted numeric array.
	 * @param int   $percentile Percentile to calculate (0-100).
	 * @return float Percentile value.
	 */
	protected function calculate_percentile( $sorted_values, $percentile ) {
		if ( empty( $sorted_values ) ) {
			return 0;
		}

		$count = count( $sorted_values );
		$index = ceil( ( $percentile / 100 ) * $count ) - 1;
		$index = max( 0, min( $index, $count - 1 ) );

		return $sorted_values[ $index ];
	}

	/**
	 * Generate recommendations based on system health
	 *
	 * @param string $health_status Health status.
	 * @param array  $tool_metrics Tool metrics.
	 * @return array Recommendations.
	 */
	protected function generate_recommendations( $health_status, $tool_metrics ) {
		$recommendations = array();

		if ( 'critical' === $health_status ) {
			$recommendations[] = __( 'System capacity is critical. Consider scaling resources or deferring non-critical tasks.', 'mcp-ai-wpoos' );
		} elseif ( 'warning' === $health_status ) {
			$recommendations[] = __( 'System utilization is high. Monitor for potential capacity issues.', 'mcp-ai-wpoos' );
		}

		// Find heavily utilized tools.
		foreach ( $tool_metrics as $tool_slug => $metrics ) {
			if ( $metrics['utilization'] > 0.9 ) {
				$recommendations[] = sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" is heavily utilized. Consider optimization or async execution.', 'mcp-ai-wpoos' ),
					$tool_slug
				);
			}
		}

		return $recommendations;
	}
}
