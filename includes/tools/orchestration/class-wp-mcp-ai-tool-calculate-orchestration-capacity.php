<?php
/**
 * Tool: Calculate Orchestration Capacity
 *
 * Uses Little's Law to calculate optimal capacity for autonomous orchestration.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculate Orchestration Capacity Tool
 *
 * Applies Little's Law (L = λ × W) to autonomous orchestration:
 * - L (Queue Length): Number of concurrent sessions that can run
 * - λ (Arrival Rate): Sessions starting per hour
 * - W (Service Time): Average session duration in hours
 */
class WP_MCP_AI_Tool_Calculate_Orchestration_Capacity {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'calculate_orchestration_capacity';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'calculate_orchestration_capacity',
			'description'         => 'Calculate optimal capacity for autonomous orchestration using Little\'s Law (L = λ × W). Helps determine how many concurrent sessions can run without overload.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'mode'             => array(
						'type'        => 'string',
						'enum'        => array( 'calculate_capacity', 'predict_wait_time', 'analyze_current' ),
						'description' => 'Calculation mode',
						'default'     => 'analyze_current',
					),
					'arrival_rate'     => array(
						'type'        => 'number',
						'description' => 'Session arrival rate (sessions per hour) - λ',
					),
					'service_time'     => array(
						'type'        => 'number',
						'description' => 'Average service time (hours per session) - W',
					),
					'max_concurrent'   => array(
						'type'        => 'integer',
						'description' => 'Maximum concurrent sessions limit',
					),
					'current_sessions' => array(
						'type'        => 'integer',
						'description' => 'Current number of active sessions',
					),
				),
			),
			'required_capability' => 'read',
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_MCP_AI_Tool_Interface.
		$mode = ! empty( $arguments['mode'] ) ? $arguments['mode'] : 'analyze_current';

		switch ( $mode ) {
			case 'calculate_capacity':
				return $this->calculate_capacity( $arguments );

			case 'predict_wait_time':
				return $this->predict_wait_time( $arguments );

			case 'analyze_current':
				return $this->analyze_current_load( $arguments );

			default:
				return array(
					'success' => false,
					'error'   => 'Invalid mode. Use: calculate_capacity, predict_wait_time, or analyze_current',
				);
		}
	}

	/**
	 * Calculate optimal capacity using Little's Law
	 *
	 * @param array $arguments Arguments.
	 * @return array
	 */
	private function calculate_capacity( $arguments ) {
		if ( ! isset( $arguments['arrival_rate'] ) || ! isset( $arguments['service_time'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required arguments: arrival_rate and service_time',
			);
		}

		$lambda = floatval( $arguments['arrival_rate'] ); // Sessions per hour.
		$w      = floatval( $arguments['service_time'] ); // Hours per session.

		if ( $lambda <= 0 || $w <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'arrival_rate and service_time must be positive numbers',
			);
		}

		// Little's Law: L = λ × W.
		$l = $lambda * $w;

		// Calculate utilization and recommendations.
		$max_concurrent = ! empty( $arguments['max_concurrent'] ) ? intval( $arguments['max_concurrent'] ) : 10;
		$utilization    = ( $l / $max_concurrent ) * 100;

		// Calculate safety margins.
		$safe_capacity     = floor( $max_concurrent * 0.8 ); // 80% rule.
		$recommended_limit = ceil( $l * 1.5 ); // 50% buffer.

		return array(
			'success'         => true,
			'littles_law'     => array(
				'L'       => round( $l, 2 ),
				'λ'       => $lambda,
				'W'       => $w,
				'formula' => 'L = λ × W',
			),
			'capacity'        => array(
				'expected_queue_length' => round( $l, 2 ),
				'current_limit'         => $max_concurrent,
				'utilization_percent'   => round( $utilization, 1 ),
				'safe_capacity'         => $safe_capacity,
				'recommended_limit'     => $recommended_limit,
			),
			'recommendations' => $this->get_capacity_recommendations( $l, $max_concurrent, $utilization ),
		);
	}

	/**
	 * Predict wait time for new session
	 *
	 * @param array $arguments Arguments.
	 * @return array
	 */
	private function predict_wait_time( $arguments ) {
		$current_sessions = ! empty( $arguments['current_sessions'] ) ? intval( $arguments['current_sessions'] ) : 0;
		$max_concurrent   = ! empty( $arguments['max_concurrent'] ) ? intval( $arguments['max_concurrent'] ) : 10;
		$service_time     = ! empty( $arguments['service_time'] ) ? floatval( $arguments['service_time'] ) : 1.0;

		if ( $current_sessions < $max_concurrent ) {
			return array(
				'success'           => true,
				'wait_time_hours'   => 0,
				'wait_time_minutes' => 0,
				'queue_position'    => 0,
				'message'           => 'Capacity available - session can start immediately',
			);
		}

		// Queue position.
		$queue_position = $current_sessions - $max_concurrent + 1;

		// Estimate wait time (conservative: assume serial execution).
		$wait_time_hours = $queue_position * $service_time;

		return array(
			'success'           => true,
			'wait_time_hours'   => round( $wait_time_hours, 2 ),
			'wait_time_minutes' => round( $wait_time_hours * 60, 0 ),
			'queue_position'    => $queue_position,
			'current_load'      => array(
				'active_sessions' => $current_sessions,
				'max_concurrent'  => $max_concurrent,
				'utilization'     => round( ( $current_sessions / $max_concurrent ) * 100, 1 ),
			),
			'message'           => sprintf(
				'System at capacity. Estimated wait: %.0f minutes (position %d in queue)',
				$wait_time_hours * 60,
				$queue_position
			),
		);
	}

	/**
	 * Analyze current load
	 *
	 * @param array $arguments Arguments.
	 * @return array
	 */
	private function analyze_current_load( $arguments ) {
		// Get active sessions.
		$active_sessions = $this->get_active_session_count();

		// Get historical metrics.
		$metrics = $this->get_historical_metrics();

		// Calculate current λ and W from historical data.
		$lambda = $metrics['arrival_rate']; // Sessions per hour.
		$w      = $metrics['avg_service_time']; // Hours per session.

		// Little's Law prediction.
		$predicted_l = $lambda * $w;

		// Current vs predicted.
		$max_concurrent        = ! empty( $arguments['max_concurrent'] ) ? intval( $arguments['max_concurrent'] ) : 10;
		$current_utilization   = ( $active_sessions / $max_concurrent ) * 100;
		$predicted_utilization = ( $predicted_l / $max_concurrent ) * 100;

		return array(
			'success'            => true,
			'current_state'      => array(
				'active_sessions' => $active_sessions,
				'max_concurrent'  => $max_concurrent,
				'utilization'     => round( $current_utilization, 1 ),
			),
			'historical_metrics' => array(
				'arrival_rate'      => round( $lambda, 2 ),
				'avg_service_time'  => round( $w, 2 ),
				'completed_today'   => $metrics['completed_today'],
				'avg_duration_mins' => $metrics['avg_duration_mins'],
			),
			'littles_law'        => array(
				'predicted_queue_length' => round( $predicted_l, 2 ),
				'predicted_utilization'  => round( $predicted_utilization, 1 ),
				'formula'                => sprintf( 'L = %.2f × %.2f = %.2f', $lambda, $w, $predicted_l ),
			),
			'capacity_analysis'  => array(
				'status'            => $this->get_load_status( $current_utilization ),
				'headroom_sessions' => max( 0, $max_concurrent - $active_sessions ),
				'headroom_percent'  => round( ( ( $max_concurrent - $active_sessions ) / $max_concurrent ) * 100, 1 ),
			),
			'recommendations'    => $this->get_load_recommendations( $current_utilization, $predicted_utilization, $active_sessions, $max_concurrent ),
		);
	}

	/**
	 * Get active session count
	 *
	 * @return int
	 */
	private function get_active_session_count() {
		global $wpdb;

		// Count transients with session prefix and active status.
		$count      = 0; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.IncorrectWarning -- Alignment intentional for readability within this assignment block.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Orchestration capacity calculation requires live counts; cached values would cause incorrect scheduling decisions.
		$transients = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_mcp_ai_session_%'"
		);

		foreach ( $transients as $transient ) {
			$session_key = str_replace( '_transient_', '', $transient );
			$session     = get_transient( str_replace( '_transient_mcp_ai_session_', '', $transient ) );

			if ( $session && isset( $session['status'] ) && 'active' === $session['status'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Get historical metrics
	 *
	 * @return array
	 */
	private function get_historical_metrics() {
		global $wpdb;

		// Get all sessions from last 24 hours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Orchestration capacity calculation requires live counts; cached values would cause incorrect scheduling decisions.
		$transients = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_mcp_ai_session_%'"
		);

		$sessions        = array();
		$completed_count = 0;
		$total_duration  = 0;

		foreach ( $transients as $transient ) {
			$session_key = str_replace( '_transient_mcp_ai_session_', '', str_replace( '_transient_', '', $transient ) );
			$session     = get_transient( $session_key );

			if ( $session ) {
				$sessions[] = $session;

				if ( 'completed' === $session['status'] && ! empty( $session['completed_at'] ) ) {
					++$completed_count;
					$start           = strtotime( $session['started_at'] );
					$end             = strtotime( $session['completed_at'] );
					$total_duration += ( $end - $start );
				}
			}
		}

		// Calculate arrival rate (sessions per hour).
		$arrival_rate = $completed_count > 0 ? $completed_count / 24 : 0.5; // Default: 0.5 sessions/hour.

		// Calculate average service time (hours per session).
		$avg_service_time = $completed_count > 0
			? ( $total_duration / $completed_count ) / 3600
			: 1.0; // Default: 1 hour per session.

		return array(
			'arrival_rate'      => $arrival_rate,
			'avg_service_time'  => $avg_service_time,
			'completed_today'   => $completed_count,
			'avg_duration_mins' => round( ( $total_duration / max( 1, $completed_count ) ) / 60, 0 ),
		);
	}

	/**
	 * Get capacity recommendations
	 *
	 * @param float $predicted_l    Predicted queue length.
	 * @param int   $max_concurrent Max concurrent sessions.
	 * @param float $utilization    Current utilization.
	 * @return array
	 */
	private function get_capacity_recommendations( $predicted_l, $max_concurrent, $utilization ) {
		$recommendations = array();

		if ( $utilization > 90 ) {
			$recommendations[] = '🚨 CRITICAL: System severely overloaded. Increase max_concurrent or reduce arrival rate.';
			$recommendations[] = sprintf( 'Recommended limit: %d sessions (current: %d)', ceil( $predicted_l * 1.5 ), $max_concurrent );
		} elseif ( $utilization > 80 ) {
			$recommendations[] = '⚠️ WARNING: System nearing capacity. Consider increasing limits.';
			$recommendations[] = sprintf( 'Safe capacity: %d sessions (80%% rule)', floor( $max_concurrent * 0.8 ) );
		} elseif ( $utilization > 60 ) {
			$recommendations[] = '✅ OK: System operating normally with moderate load.';
		} else {
			$recommendations[] = '✅ EXCELLENT: System has plenty of headroom.';
			$recommendations[] = sprintf( 'Can handle %.1fx more load', $max_concurrent / $predicted_l );
		}

		return $recommendations;
	}

	/**
	 * Get load status
	 *
	 * @param float $utilization Utilization percentage.
	 * @return string
	 */
	private function get_load_status( $utilization ) {
		if ( $utilization >= 90 ) {
			return 'CRITICAL';
		} elseif ( $utilization >= 80 ) {
			return 'WARNING';
		} elseif ( $utilization >= 60 ) {
			return 'MODERATE';
		} elseif ( $utilization >= 30 ) {
			return 'LIGHT';
		} else {
			return 'IDLE';
		}
	}

	/**
	 * Get load recommendations
	 *
	 * @param float $current_utilization   Current utilization.
	 * @param float $predicted_utilization Predicted utilization.
	 * @param int   $active_sessions       Active sessions.
	 * @param int   $max_concurrent        Max concurrent.
	 * @return array
	 */
	private function get_load_recommendations( $current_utilization, $predicted_utilization, $active_sessions, $max_concurrent ) {
		$recommendations = array();

		// Current state.
		if ( $current_utilization > 90 ) {
			$recommendations[] = '🚨 System at capacity. Consider pausing new sessions until load decreases.';
		} elseif ( $current_utilization > 80 ) {
			$recommendations[] = '⚠️ High load. Monitor closely and prepare to scale if needed.';
		} else {
			$recommendations[] = sprintf(
				'✅ Capacity available: %d of %d slots in use (%.1f%%)',
				$active_sessions,
				$max_concurrent,
				$current_utilization
			);
		}

		// Prediction vs reality.
		$variance = abs( $predicted_utilization - $current_utilization );
		if ( $variance > 20 ) {
			$recommendations[] = sprintf(
				'📊 Load variance: Predicted %.1f%%, actual %.1f%% (%.1f%% difference)',
				$predicted_utilization,
				$current_utilization,
				$variance
			);

			if ( $predicted_utilization > $current_utilization ) {
				$recommendations[] = 'Trend: Load increasing - prepare for higher utilization.';
			} else {
				$recommendations[] = 'Trend: Load decreasing - excess capacity available.';
			}
		}

		return $recommendations;
	}
}
