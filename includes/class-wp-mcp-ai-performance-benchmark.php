<?php
/**
 * Performance Benchmarking Helper
 *
 * Provides tools for measuring and monitoring plugin performance,
 * including query analysis, execution time tracking, and memory usage.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance Benchmarking class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Performance_Benchmark {

	/**
	 * Benchmark start times keyed by identifier.
	 *
	 * @var array
	 */
	private static $timers = array();

	/**
	 * Benchmark data for analysis.
	 *
	 * @var array
	 */
	private static $benchmarks = array();

	/**
	 * Performance SLA thresholds.
	 *
	 * @var array
	 */
	private static $sla_thresholds = array(
		'tool_execution'  => 2.0,  // 2 seconds.
		'api_request'     => 2.0,  // 2 seconds.
		'cache_operation' => 0.1,  // 100ms.
		'database_query'  => 0.05, // 50ms.
	);

	/**
	 * Start a performance timer.
	 *
	 * @param string $identifier Unique identifier for this benchmark.
	 * @param array  $context    Optional context data.
	 * @return void
	 */
	public static function start( $identifier, $context = array() ) {
		self::$timers[ $identifier ] = array(
			'start_time'   => microtime( true ),
			'start_memory' => memory_get_usage(),
			'context'      => $context,
		);
	}

	/**
	 * End a performance timer and record results.
	 *
	 * @param string $identifier Benchmark identifier.
	 * @param array  $metadata   Optional metadata to store with results.
	 * @return array Benchmark results.
	 */
	public static function end( $identifier, $metadata = array() ) {
		if ( ! isset( self::$timers[ $identifier ] ) ) {
			return array(
				'error' => 'Timer not found',
			);
		}

		$timer      = self::$timers[ $identifier ];
		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$results = array(
			'identifier'     => $identifier,
			'execution_time' => $end_time - $timer['start_time'],
			'memory_used'    => $end_memory - $timer['start_memory'],
			'peak_memory'    => memory_get_peak_usage(),
			'timestamp'      => current_time( 'mysql' ),
			'context'        => $timer['context'],
			'metadata'       => $metadata,
		);

		// Store benchmark data.
		self::$benchmarks[] = $results;

		// Check against SLA thresholds.
		$threshold = self::get_threshold( $identifier );
		if ( $results['execution_time'] > $threshold ) {
			self::log_slow_execution( $identifier, $results, $threshold );
		}

		// Clean up timer.
		unset( self::$timers[ $identifier ] );

		return $results;
	}

	/**
	 * Get SLA threshold for a benchmark type.
	 *
	 * @param string $identifier Benchmark identifier.
	 * @return float Threshold in seconds.
	 */
	private static function get_threshold( $identifier ) {
		// Try to match identifier with threshold type.
		foreach ( self::$sla_thresholds as $type => $threshold ) {
			if ( strpos( $identifier, $type ) !== false ) {
				return $threshold;
			}
		}

		// Default threshold.
		return 5.0;
	}

	/**
	 * Log slow execution warning.
	 *
	 * @param string $identifier Benchmark identifier.
	 * @param array  $results    Benchmark results.
	 * @param float  $threshold  SLA threshold.
	 * @return void
	 */
	private static function log_slow_execution( $identifier, $results, $threshold ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log(
				'warning',
				sprintf(
					/* translators: %1$s: identifier, %2$s: execution time, %3$s: threshold */
					__( 'Performance SLA exceeded: %1$s took %2$ss (threshold: %3$ss)', 'mcp-ai-wpoos' ),
					$identifier,
					number_format( $results['execution_time'], 3 ),
					number_format( $threshold, 3 )
				),
				array(
					'benchmark' => $results,
					'threshold' => $threshold,
				)
			);
		}

		/**
		 * Fires when performance threshold is exceeded.
		 *
		 * @param string $identifier Benchmark identifier.
		 * @param array  $results    Benchmark results.
		 * @param float  $threshold  SLA threshold.
		 */
		do_action( 'wp_mcp_ai_performance_threshold_exceeded', $identifier, $results, $threshold );
	}

	/**
	 * Measure execution time of a callback.
	 *
	 * @param string   $identifier Benchmark identifier.
	 * @param callable $callback   Function to measure.
	 * @param array    $args       Arguments to pass to callback.
	 * @return array Result with 'value' and 'benchmark' keys.
	 */
	public static function measure( $identifier, $callback, $args = array() ) {
		self::start( $identifier );

		$value = call_user_func_array( $callback, $args );

		$benchmark = self::end(
			$identifier,
			array(
				'callback' => is_array( $callback ) ?
					get_class( $callback[0] ) . '::' . $callback[1] :
					( is_string( $callback ) ? $callback : 'closure' ),
			)
		);

		return array(
			'value'     => $value,
			'benchmark' => $benchmark,
		);
	}

	/**
	 * Get all recorded benchmarks.
	 *
	 * @return array Benchmark data.
	 */
	public static function get_benchmarks() {
		return self::$benchmarks;
	}

	/**
	 * Get benchmarks summary statistics.
	 *
	 * @return array Summary statistics.
	 */
	public static function get_summary() {
		if ( empty( self::$benchmarks ) ) {
			return array();
		}

		$execution_times = wp_list_pluck( self::$benchmarks, 'execution_time' );
		$memory_used     = wp_list_pluck( self::$benchmarks, 'memory_used' );

		return array(
			'total_benchmarks' => count( self::$benchmarks ),
			'total_time'       => array_sum( $execution_times ),
			'avg_time'         => array_sum( $execution_times ) / count( $execution_times ),
			'min_time'         => min( $execution_times ),
			'max_time'         => max( $execution_times ),
			'total_memory'     => array_sum( $memory_used ),
			'avg_memory'       => array_sum( $memory_used ) / count( $memory_used ),
			'peak_memory'      => memory_get_peak_usage(),
		);
	}

	/**
	 * Clear all benchmark data.
	 *
	 * @return void
	 */
	public static function clear() {
		self::$benchmarks = array();
		self::$timers     = array();
	}

	/**
	 * Check if Query Monitor is available.
	 *
	 * @return bool True if Query Monitor is active.
	 */
	public static function has_query_monitor() {
		return class_exists( 'QM_Collectors' );
	}

	/**
	 * Add custom data to Query Monitor.
	 *
	 * @param string $label Custom label.
	 * @param mixed  $data  Data to display.
	 * @return void
	 */
	public static function query_monitor_log( $label, $data ) {
		if ( ! self::has_query_monitor() ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- External Query Monitor hook uses slash-separated naming convention.
		do_action(
			'qm/debug',
			array(
				'label' => 'NV oOS: ' . $label,
				'data'  => $data,
			)
		);
	}

	/**
	 * Get WordPress database query count.
	 *
	 * @return int Number of queries.
	 */
	public static function get_query_count() {
		global $wpdb;
		return (int) $wpdb->num_queries;
	}

	/**
	 * Get WordPress database queries.
	 *
	 * @return array Query log (if SAVEQUERIES is enabled).
	 */
	public static function get_queries() {
		global $wpdb;
		return isset( $wpdb->queries ) ? $wpdb->queries : array();
	}

	/**
	 * Analyze database queries for performance issues.
	 *
	 * @return array Analysis results.
	 */
	public static function analyze_queries() {
		$queries = self::get_queries();
		if ( empty( $queries ) ) {
			return array(
				'error' => 'Query logging not enabled. Define SAVEQUERIES constant.',
			);
		}

		$slow_queries      = array();
		$duplicate_queries = array();
		$query_types       = array();
		$total_time        = 0;

		// Analyze each query.
		foreach ( $queries as $query ) {
			$sql         = $query[0];
			$time        = $query[1];
			$total_time += $time;

			// Check for slow queries (> 50ms).
			if ( $time > 0.05 ) {
				$slow_queries[] = array(
					'sql'  => $sql,
					'time' => $time,
				);
			}

			// Track query types.
			$type                 = strtoupper( strtok( $sql, ' ' ) );
			$query_types[ $type ] = ( $query_types[ $type ] ?? 0 ) + 1;

			// Check for duplicates.
			$hash = md5( $sql );
			if ( isset( $duplicate_queries[ $hash ] ) ) {
				++$duplicate_queries[ $hash ]['count'];
			} else {
				$duplicate_queries[ $hash ] = array(
					'sql'   => $sql,
					'count' => 1,
				);
			}
		}

		// Filter out non-duplicates.
		$duplicate_queries = array_filter(
			$duplicate_queries,
			function ( $item ) {
				return $item['count'] > 1;
			}
		);

		return array(
			'total_queries'     => count( $queries ),
			'total_time'        => $total_time,
			'avg_time'          => $total_time / count( $queries ),
			'slow_queries'      => $slow_queries,
			'slow_query_count'  => count( $slow_queries ),
			'duplicate_queries' => array_values( $duplicate_queries ),
			'query_types'       => $query_types,
		);
	}

	/**
	 * Generate performance report.
	 *
	 * @return string HTML report.
	 */
	public static function generate_report() {
		$summary        = self::get_summary();
		$query_analysis = self::analyze_queries();

		ob_start();
		?>
		<div class="wp-mcp-ai-performance-report">
			<h2><?php esc_html_e( 'Performance Report', 'mcp-ai-wpoos' ); ?></h2>
			
			<h3><?php esc_html_e( 'Benchmark Summary', 'mcp-ai-wpoos' ); ?></h3>
			<table class="widefat">
				<tr>
					<th><?php esc_html_e( 'Total Benchmarks', 'mcp-ai-wpoos' ); ?></th>
					<td><?php echo esc_html( $summary['total_benchmarks'] ?? 0 ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Total Time', 'mcp-ai-wpoos' ); ?></th>
					<td><?php echo esc_html( number_format( $summary['total_time'] ?? 0, 3 ) ); ?>s</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Average Time', 'mcp-ai-wpoos' ); ?></th>
					<td><?php echo esc_html( number_format( $summary['avg_time'] ?? 0, 3 ) ); ?>s</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Peak Memory', 'mcp-ai-wpoos' ); ?></th>
					<td><?php echo esc_html( size_format( $summary['peak_memory'] ?? 0 ) ); ?></td>
				</tr>
			</table>

			<?php if ( ! empty( $query_analysis['total_queries'] ) ) : ?>
				<h3><?php esc_html_e( 'Database Query Analysis', 'mcp-ai-wpoos' ); ?></h3>
				<table class="widefat">
					<tr>
						<th><?php esc_html_e( 'Total Queries', 'mcp-ai-wpoos' ); ?></th>
						<td><?php echo esc_html( $query_analysis['total_queries'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Slow Queries', 'mcp-ai-wpoos' ); ?></th>
						<td><?php echo esc_html( $query_analysis['slow_query_count'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Duplicate Queries', 'mcp-ai-wpoos' ); ?></th>
						<td><?php echo esc_html( count( $query_analysis['duplicate_queries'] ) ); ?></td>
					</tr>
				</table>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Add performance data to Site Health info.
	 *
	 * @param array $info Site Health info.
	 * @return array Modified info.
	 */
	public static function add_to_site_health( $info ) {
		$summary = self::get_summary();

		if ( empty( $summary ) ) {
			return $info;
		}

		$info['wp_mcp_ai_performance'] = array(
			'label'  => __( 'NV oOS Performance', 'mcp-ai-wpoos' ),
			'fields' => array(
				'benchmarks_count'   => array(
					'label' => __( 'Total Benchmarks', 'mcp-ai-wpoos' ),
					'value' => $summary['total_benchmarks'],
				),
				'avg_execution_time' => array(
					'label' => __( 'Average Execution Time', 'mcp-ai-wpoos' ),
					'value' => number_format( $summary['avg_time'], 3 ) . 's',
				),
				'peak_memory'        => array(
					'label' => __( 'Peak Memory Usage', 'mcp-ai-wpoos' ),
					'value' => size_format( $summary['peak_memory'] ),
				),
			),
		);

		return $info;
	}
}
