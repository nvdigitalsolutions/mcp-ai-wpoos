<?php
/**
 * REST API Endpoints for Orchestration Metrics.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metrics REST API Controller class.
 */
class WP_MCP_AI_REST_Metrics {

	/**
	 * Namespace for metrics endpoints.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		// Overview metrics endpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/metrics/overview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_overview' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
			)
		);

		// Trends data endpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/metrics/trends',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_trends' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'args'                => array(
					'period' => array(
						'required'          => false,
						'default'           => '7d',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_period' ),
					),
					'metric' => array(
						'required'          => false,
						'default'           => 'tokens',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_metric' ),
					),
				),
			)
		);

		// Assistants metrics endpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/metrics/assistants',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_assistants_metrics' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'args'                => array(
					'period' => array(
						'required'          => false,
						'default'           => '7d',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_period' ),
					),
				),
			)
		);

		// Cost analysis endpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/metrics/cost',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_cost_analysis' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
			)
		);

		// Export endpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/metrics/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'export_metrics' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
				'args'                => array(
					'format' => array(
						'required'          => false,
						'default'           => 'json',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_export_format' ),
					),
					'range'  => array(
						'required'          => false,
						'default'           => '7d',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_period' ),
					),
				),
			)
		);
	}

	/**
	 * Check permissions for metrics endpoints.
	 *
	 * @return bool|WP_Error True if user has permission, error otherwise.
	 */
	public static function check_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view metrics data.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate period parameter.
	 *
	 * @param string $value Period value.
	 * @return bool True if valid.
	 */
	public static function validate_period( $value ) {
		$valid_periods = array( '1h', '24h', '7d', '30d' );
		return in_array( $value, $valid_periods, true );
	}

	/**
	 * Validate metric parameter.
	 *
	 * @param string $value Metric value.
	 * @return bool True if valid.
	 */
	public static function validate_metric( $value ) {
		$valid_metrics = array( 'tokens', 'requests', 'response_time', 'errors' );
		return in_array( $value, $valid_metrics, true );
	}

	/**
	 * Validate export format parameter.
	 *
	 * @param string $value Format value.
	 * @return bool True if valid.
	 */
	public static function validate_export_format( $value ) {
		$valid_formats = array( 'csv', 'json' );
		return in_array( $value, $valid_formats, true );
	}

	/**
	 * Get overview metrics.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public static function get_overview() {
		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$usage_history    = $resource_manager->get_usage_history( 24 );

		$total_requests      = count( $usage_history );
		$total_tokens        = 0;
		$total_response_time = 0;
		$success_count       = 0;

		foreach ( $usage_history as $entry ) {
			$total_tokens       += isset( $entry['tokens_used'] ) ? $entry['tokens_used'] : 0;
			$total_response_time += isset( $entry['execution_time'] ) ? $entry['execution_time'] : 0;
			if ( isset( $entry['status'] ) && 'success' === $entry['status'] ) {
				$success_count++;
			}
		}

		$avg_response_time = $total_requests > 0 ? $total_response_time / $total_requests : 0;
		$success_rate      = $total_requests > 0 ? ( $success_count / $total_requests ) * 100 : 0;

		$health_status = $resource_manager->get_health_status();

		return new WP_REST_Response(
			array(
				'total_requests'    => $total_requests,
				'total_tokens'      => $total_tokens,
				'avg_response_time' => round( $avg_response_time, 2 ),
				'success_rate'      => round( $success_rate, 1 ),
				'health_status'     => $health_status['overall_health'],
				'timestamp'         => current_time( 'mysql' ),
			),
			200
		);
	}

	/**
	 * Get trends data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function get_trends( $request ) {
		$period = $request->get_param( 'period' );
		$metric = $request->get_param( 'metric' );

		$hours = self::period_to_hours( $period );

		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$usage_history    = $resource_manager->get_usage_history( $hours );

		$trends_data = self::aggregate_trends( $usage_history, $metric, $period );

		return new WP_REST_Response(
			array(
				'period'     => $period,
				'metric'     => $metric,
				'data_points' => $trends_data,
				'timestamp'  => current_time( 'mysql' ),
			),
			200
		);
	}

	/**
	 * Get assistants metrics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function get_assistants_metrics( $request ) {
		$period = $request->get_param( 'period' );
		$hours  = self::period_to_hours( $period );

		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$usage_history    = $resource_manager->get_usage_history( $hours );

		$assistants_data = array();

		foreach ( $usage_history as $entry ) {
			if ( ! isset( $entry['assistant_id'] ) ) {
				continue;
			}

			$assistant_id = $entry['assistant_id'];

			if ( ! isset( $assistants_data[ $assistant_id ] ) ) {
				$assistants_data[ $assistant_id ] = array(
					'assistant_id'    => $assistant_id,
					'requests'        => 0,
					'tokens'          => 0,
					'response_time'   => 0,
					'success_count'   => 0,
				);
			}

			$assistants_data[ $assistant_id ]['requests']++;
			$assistants_data[ $assistant_id ]['tokens'] += isset( $entry['tokens_used'] ) ? $entry['tokens_used'] : 0;
			$assistants_data[ $assistant_id ]['response_time'] += isset( $entry['execution_time'] ) ? $entry['execution_time'] : 0;
			if ( isset( $entry['status'] ) && 'success' === $entry['status'] ) {
				$assistants_data[ $assistant_id ]['success_count']++;
			}
		}

		// Calculate averages and success rates.
		foreach ( $assistants_data as &$data ) {
			$data['avg_response_time'] = $data['requests'] > 0 ? round( $data['response_time'] / $data['requests'], 2 ) : 0;
			$data['success_rate']      = $data['requests'] > 0 ? round( ( $data['success_count'] / $data['requests'] ) * 100, 1 ) : 0;
			unset( $data['response_time'], $data['success_count'] );
		}

		return new WP_REST_Response(
			array(
				'period'     => $period,
				'assistants' => array_values( $assistants_data ),
				'timestamp'  => current_time( 'mysql' ),
			),
			200
		);
	}

	/**
	 * Get cost analysis.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public static function get_cost_analysis() {
		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$usage_history    = $resource_manager->get_usage_history( 24 * 30 ); // Last 30 days.

		$total_tokens = 0;
		foreach ( $usage_history as $entry ) {
			$total_tokens += isset( $entry['tokens_used'] ) ? $entry['tokens_used'] : 0;
		}

		// Estimated costs (example pricing - adjust as needed).
		$cost_per_1k_tokens = 0.002; // $0.002 per 1K tokens (example).
		$estimated_cost     = ( $total_tokens / 1000 ) * $cost_per_1k_tokens;

		// Generate recommendations.
		$recommendations = self::generate_cost_recommendations( $usage_history );

		return new WP_REST_Response(
			array(
				'total_tokens'       => $total_tokens,
				'estimated_cost'     => round( $estimated_cost, 2 ),
				'period'             => '30d',
				'recommendations'    => $recommendations,
				'cost_per_1k_tokens' => $cost_per_1k_tokens,
				'timestamp'          => current_time( 'mysql' ),
			),
			200
		);
	}

	/**
	 * Export metrics data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function export_metrics( $request ) {
		$format = $request->get_param( 'format' );
		$range  = $request->get_param( 'range' );
		$hours  = self::period_to_hours( $range );

		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$usage_history    = $resource_manager->get_usage_history( $hours );

		if ( 'csv' === $format ) {
			$csv_data = self::generate_csv( $usage_history );
			return new WP_REST_Response(
				array(
					'format'   => 'csv',
					'data'     => $csv_data,
					'filename' => 'wp-mcp-ai-metrics-' . gmdate( 'Y-m-d' ) . '.csv',
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'format'   => 'json',
				'data'     => $usage_history,
				'filename' => 'wp-mcp-ai-metrics-' . gmdate( 'Y-m-d' ) . '.json',
			),
			200
		);
	}

	/**
	 * Convert period string to hours.
	 *
	 * @param string $period Period string.
	 * @return int Hours.
	 */
	private static function period_to_hours( $period ) {
		$periods = array(
			'1h'  => 1,
			'24h' => 24,
			'7d'  => 24 * 7,
			'30d' => 24 * 30,
		);

		return isset( $periods[ $period ] ) ? $periods[ $period ] : 24;
	}

	/**
	 * Aggregate trends data.
	 *
	 * @param array  $usage_history Usage history data.
	 * @param string $metric        Metric to aggregate.
	 * @param string $period        Time period.
	 * @return array Aggregated data points.
	 */
	private static function aggregate_trends( $usage_history, $metric, $period ) {
		$data_points = array();

		// Group by time intervals.
		$interval = '24h' === $period ? 'hour' : 'day';

		foreach ( $usage_history as $ts_key => $entry ) {
			// Get timestamp from entry or fall back to array key for backwards compatibility.
			$timestamp = isset( $entry['timestamp'] ) ? $entry['timestamp'] : $ts_key;
			
			if ( ! $timestamp ) {
				continue;
			}

			$time_key = 'hour' === $interval ? gmdate( 'Y-m-d H:00', $timestamp ) : gmdate( 'Y-m-d', $timestamp );

			if ( ! isset( $data_points[ $time_key ] ) ) {
				$data_points[ $time_key ] = array(
					'timestamp' => $time_key,
					'value'     => 0,
					'count'     => 0,
				);
			}

			switch ( $metric ) {
				case 'tokens':
					$data_points[ $time_key ]['value'] += isset( $entry['tokens_used'] ) ? $entry['tokens_used'] : 0;
					break;
				case 'requests':
					$data_points[ $time_key ]['count']++;
					break;
				case 'response_time':
					$data_points[ $time_key ]['value'] += isset( $entry['execution_time'] ) ? $entry['execution_time'] : 0;
					$data_points[ $time_key ]['count']++;
					break;
				case 'errors':
					if ( isset( $entry['status'] ) && 'error' === $entry['status'] ) {
						$data_points[ $time_key ]['value']++;
					}
					break;
			}
		}

		// Calculate averages for response_time.
		if ( 'response_time' === $metric ) {
			foreach ( $data_points as &$point ) {
				if ( $point['count'] > 0 ) {
					$point['value'] = $point['value'] / $point['count'];
				}
			}
		}

		// For requests metric, use count as value.
		if ( 'requests' === $metric ) {
			foreach ( $data_points as &$point ) {
				$point['value'] = $point['count'];
			}
		}

		return array_values( $data_points );
	}

	/**
	 * Generate cost optimization recommendations.
	 *
	 * @param array $usage_history Usage history data.
	 * @return array Recommendations.
	 */
	private static function generate_cost_recommendations( $usage_history ) {
		$recommendations = array();

		// Analyze usage patterns.
		$total_tokens = 0;
		$peak_usage   = 0;
		$low_usage    = PHP_INT_MAX;

		foreach ( $usage_history as $entry ) {
			$tokens = isset( $entry['tokens_used'] ) ? $entry['tokens_used'] : 0;
			$total_tokens += $tokens;
			$peak_usage = max( $peak_usage, $tokens );
			$low_usage  = min( $low_usage, $tokens );
		}

		$avg_tokens = count( $usage_history ) > 0 ? $total_tokens / count( $usage_history ) : 0;

		// Generate recommendations based on usage patterns.
		if ( $peak_usage > $avg_tokens * 3 ) {
			$recommendations[] = array(
				'type'        => 'optimization',
				'title'       => __( 'High Token Variance Detected', 'wp-mcp-ai' ),
				'description' => __( 'Consider implementing rate limiting or budget caps to control token spikes.', 'wp-mcp-ai' ),
				'impact'      => 'medium',
			);
		}

		if ( $avg_tokens > 5000 ) {
			$recommendations[] = array(
				'type'        => 'cost_savings',
				'title'       => __( 'Consider Lower-Tier Model', 'wp-mcp-ai' ),
				'description' => __( 'High token usage detected. Using gpt-4o-mini instead of gpt-4o could reduce costs by ~90%.', 'wp-mcp-ai' ),
				'impact'      => 'high',
			);
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = array(
				'type'        => 'info',
				'title'       => __( 'Optimal Usage Pattern', 'wp-mcp-ai' ),
				'description' => __( 'Your current usage patterns are well-optimized. Continue monitoring for any changes.', 'wp-mcp-ai' ),
				'impact'      => 'low',
			);
		}

		return $recommendations;
	}

	/**
	 * Generate CSV from usage history.
	 *
	 * @param array $usage_history Usage history data.
	 * @return string CSV data.
	 */
	private static function generate_csv( $usage_history ) {
		$csv = "Timestamp,Operation Type,Tokens Used,Execution Time,Status\n";

		foreach ( $usage_history as $ts_key => $entry ) {
			// Get timestamp from entry or fall back to array key for backwards compatibility.
			$timestamp = isset( $entry['timestamp'] ) ? $entry['timestamp'] : $ts_key;
			
			$csv .= sprintf(
				"%s,%s,%d,%.2f,%s\n",
				$timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '',
				isset( $entry['operation_type'] ) ? $entry['operation_type'] : '',
				isset( $entry['tokens_used'] ) ? $entry['tokens_used'] : 0,
				isset( $entry['execution_time'] ) ? $entry['execution_time'] : 0,
				isset( $entry['status'] ) ? $entry['status'] : ''
			);
		}

		return $csv;
	}
}
