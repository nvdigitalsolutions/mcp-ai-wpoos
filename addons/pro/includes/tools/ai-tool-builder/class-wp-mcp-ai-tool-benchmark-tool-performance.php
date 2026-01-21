<?php
/**
 * Tool for benchmarking AI tool performance.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Benchmark AI tool performance and resource usage.
 *
 * This tool measures execution time, memory usage, database queries,
 * and provides performance optimization recommendations. Supports
 * multiple test scenarios and statistical analysis.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Benchmark_Tool_Performance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'benchmark_tool_performance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Benchmark Tool Performance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Benchmark AI tool performance and resource usage. Measures execution time, memory consumption, database queries, HTTP requests, and provides optimization recommendations. Supports multiple test runs and statistical analysis.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tool_slug'         => array(
					'type'        => 'string',
					'description' => __( 'Tool slug to benchmark', 'mcp-ai-wpoos-pro' ),
				),
				'test_arguments'    => array(
					'type'        => 'object',
					'description' => __( 'Arguments to pass to tool for testing', 'mcp-ai-wpoos-pro' ),
					'default'     => array(),
				),
				'iterations'        => array(
					'type'        => 'integer',
					'description' => __( 'Number of test iterations to run', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
				),
				'metrics'           => array(
					'type'        => 'array',
					'description' => __( 'Performance metrics to measure', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'time', 'memory', 'queries', 'http-requests', 'cache-hits' ),
					),
					'default'     => array( 'time', 'memory', 'queries' ),
				),
				'warmup_runs'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of warmup runs before benchmarking', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 10,
					'default'     => 2,
				),
				'include_statistics' => array(
					'type'        => 'boolean',
					'description' => __( 'Include statistical analysis (mean, median, std dev)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'compare_baseline'  => array(
					'type'        => 'string',
					'description' => __( 'Baseline benchmark ID to compare against', 'mcp-ai-wpoos-pro' ),
				),
				'save_results'      => array(
					'type'        => 'boolean',
					'description' => __( 'Save benchmark results for future comparison', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'tool_slug' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read',
			'requires-capability',
			'performance-impact',
			'long-running',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['tool_slug'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Tool slug is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$tool_slug       = sanitize_text_field( $arguments['tool_slug'] );
		$test_arguments  = isset( $arguments['test_arguments'] ) ? (array) $arguments['test_arguments'] : array();
		$iterations      = isset( $arguments['iterations'] ) ? absint( $arguments['iterations'] ) : 10;
		$metrics         = isset( $arguments['metrics'] ) ? array_map( 'sanitize_text_field', (array) $arguments['metrics'] ) : array( 'time', 'memory', 'queries' );
		$warmup_runs     = isset( $arguments['warmup_runs'] ) ? absint( $arguments['warmup_runs'] ) : 2;
		$include_stats   = isset( $arguments['include_statistics'] ) ? (bool) $arguments['include_statistics'] : true;
		$save_results    = isset( $arguments['save_results'] ) ? (bool) $arguments['save_results'] : true;

		// Get tool instance.
		$tool = $this->get_tool_instance( $tool_slug );

		if ( is_wp_error( $tool ) ) {
			return array(
				'success' => false,
				'error'   => $tool->get_error_message(),
			);
		}

		// Perform warmup runs.
		if ( $warmup_runs > 0 ) {
			for ( $i = 0; $i < $warmup_runs; $i++ ) {
				$tool->execute( $test_arguments, $context );
			}
		}

		// Run benchmark iterations.
		$results = array();

		for ( $i = 0; $i < $iterations; $i++ ) {
			$iteration_result = $this->benchmark_single_run( $tool, $test_arguments, $context, $metrics );
			$results[] = $iteration_result;
		}

		// Aggregate results.
		$aggregated = $this->aggregate_results( $results, $metrics );

		// Calculate statistics if requested.
		if ( $include_stats ) {
			$aggregated['statistics'] = $this->calculate_statistics( $results, $metrics );
		}

		// Generate recommendations.
		$recommendations = $this->generate_performance_recommendations( $aggregated );

		// Save results if requested.
		$benchmark_id = null;
		if ( $save_results ) {
			$benchmark_id = $this->save_benchmark_results( $tool_slug, $aggregated );
		}

		// Compare with baseline if provided.
		$comparison = null;
		if ( isset( $arguments['compare_baseline'] ) && ! empty( $arguments['compare_baseline'] ) ) {
			$comparison = $this->compare_with_baseline( $aggregated, $arguments['compare_baseline'] );
		}

		return array(
			'success'         => true,
			'message'         => sprintf(
				/* translators: 1: tool slug, 2: iteration count */
				__( 'Benchmark completed for %1$s over %2$d iterations.', 'mcp-ai-wpoos-pro' ),
				$tool_slug,
				$iterations
			),
			'tool_slug'       => $tool_slug,
			'iterations'      => $iterations,
			'results'         => $aggregated,
			'recommendations' => $recommendations,
			'benchmark_id'    => $benchmark_id,
			'comparison'      => $comparison,
		);
	}

	/**
	 * Get tool instance by slug.
	 *
	 * @param string $tool_slug Tool slug.
	 * @return object|WP_Error Tool instance or error.
	 */
	private function get_tool_instance( $tool_slug ) {
		// Get tool registry.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return new WP_Error(
				'registry_unavailable',
				__( 'Tool registry not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool = $registry->get_tool( $tool_slug );

		if ( ! $tool ) {
			return new WP_Error(
				'tool_not_found',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool not found: %s', 'mcp-ai-wpoos-pro' ),
					$tool_slug
				)
			);
		}

		return $tool;
	}

	/**
	 * Benchmark a single tool execution.
	 *
	 * @param object $tool           Tool instance.
	 * @param array  $test_arguments Test arguments.
	 * @param array  $context        Execution context.
	 * @param array  $metrics        Metrics to measure.
	 * @return array Benchmark results.
	 */
	private function benchmark_single_run( $tool, $test_arguments, $context, $metrics ) {
		global $wpdb;

		$result = array();

		// Measure execution time.
		if ( in_array( 'time', $metrics, true ) ) {
			$start_time = microtime( true );
		}

		// Measure memory usage.
		if ( in_array( 'memory', $metrics, true ) ) {
			$start_memory = memory_get_usage( true );
		}

		// Track database queries.
		if ( in_array( 'queries', $metrics, true ) ) {
			$start_queries = $wpdb->num_queries;
		}

		// Track HTTP requests.
		if ( in_array( 'http-requests', $metrics, true ) ) {
			$http_count = 0;
			add_filter( 'pre_http_request', function( $response ) use ( &$http_count ) {
				$http_count++;
				return $response;
			}, 10, 3 );
		}

		// Execute tool.
		$tool_result = $tool->execute( $test_arguments, $context );

		// Calculate metrics.
		if ( in_array( 'time', $metrics, true ) ) {
			$result['execution_time'] = microtime( true ) - $start_time;
		}

		if ( in_array( 'memory', $metrics, true ) ) {
			$result['memory_used'] = memory_get_usage( true ) - $start_memory;
			$result['peak_memory'] = memory_get_peak_usage( true );
		}

		if ( in_array( 'queries', $metrics, true ) ) {
			$result['db_queries'] = $wpdb->num_queries - $start_queries;
		}

		if ( in_array( 'http-requests', $metrics, true ) ) {
			$result['http_requests'] = $http_count;
		}

		$result['success'] = isset( $tool_result['success'] ) ? $tool_result['success'] : true;

		return $result;
	}

	/**
	 * Aggregate benchmark results.
	 *
	 * @param array $results Results from all iterations.
	 * @param array $metrics Metrics measured.
	 * @return array Aggregated results.
	 */
	private function aggregate_results( $results, $metrics ) {
		$aggregated = array();

		foreach ( $metrics as $metric ) {
			$metric_key = $this->get_metric_key( $metric );
			$values = array_column( $results, $metric_key );
			$values = array_filter( $values, function( $v ) {
				return $v !== null;
			} );

			if ( ! empty( $values ) ) {
				$aggregated[ $metric_key ] = array(
					'min'     => min( $values ),
					'max'     => max( $values ),
					'average' => array_sum( $values ) / count( $values ),
					'total'   => array_sum( $values ),
				);
			}
		}

		// Success rate.
		$success_count = count( array_filter( $results, function( $r ) {
			return isset( $r['success'] ) && $r['success'];
		} ) );
		$aggregated['success_rate'] = ( $success_count / count( $results ) ) * 100;

		return $aggregated;
	}

	/**
	 * Calculate statistical analysis.
	 *
	 * @param array $results Results from all iterations.
	 * @param array $metrics Metrics measured.
	 * @return array Statistical analysis.
	 */
	private function calculate_statistics( $results, $metrics ) {
		$statistics = array();

		foreach ( $metrics as $metric ) {
			$metric_key = $this->get_metric_key( $metric );
			$values = array_column( $results, $metric_key );
			$values = array_filter( $values, function( $v ) {
				return $v !== null;
			} );

			if ( ! empty( $values ) ) {
				sort( $values );
				$count = count( $values );
				$mean = array_sum( $values ) / $count;

				// Calculate median.
				$middle = floor( $count / 2 );
				$median = ( $count % 2 === 0 ) ? 
					( $values[ $middle - 1 ] + $values[ $middle ] ) / 2 : 
					$values[ $middle ];

				// Calculate standard deviation.
				$variance = 0;
				foreach ( $values as $value ) {
					$variance += pow( $value - $mean, 2 );
				}
				$std_dev = sqrt( $variance / $count );

				$statistics[ $metric_key ] = array(
					'mean'   => $mean,
					'median' => $median,
					'std_dev' => $std_dev,
					'variance' => $variance / $count,
				);
			}
		}

		return $statistics;
	}

	/**
	 * Get metric key from metric name.
	 *
	 * @param string $metric Metric name.
	 * @return string Metric key.
	 */
	private function get_metric_key( $metric ) {
		$map = array(
			'time'          => 'execution_time',
			'memory'        => 'memory_used',
			'queries'       => 'db_queries',
			'http-requests' => 'http_requests',
		);

		return isset( $map[ $metric ] ) ? $map[ $metric ] : $metric;
	}

	/**
	 * Generate performance recommendations.
	 *
	 * @param array $results Aggregated results.
	 * @return array Recommendations.
	 */
	private function generate_performance_recommendations( $results ) {
		$recommendations = array();

		// Check execution time.
		if ( isset( $results['execution_time']['average'] ) ) {
			$avg_time = $results['execution_time']['average'];
			
			if ( $avg_time > 5 ) {
				$recommendations[] = array(
					'priority' => 'high',
					'message'  => sprintf(
						/* translators: %s: time in seconds */
						__( 'Average execution time is high (%ss). Consider optimizing algorithm or using caching.', 'mcp-ai-wpoos-pro' ),
						number_format( $avg_time, 2 )
					),
				);
			} elseif ( $avg_time > 2 ) {
				$recommendations[] = array(
					'priority' => 'medium',
					'message'  => sprintf(
						/* translators: %s: time in seconds */
						__( 'Execution time is moderate (%ss). Room for optimization.', 'mcp-ai-wpoos-pro' ),
						number_format( $avg_time, 2 )
					),
				);
			}
		}

		// Check memory usage.
		if ( isset( $results['memory_used']['average'] ) ) {
			$avg_memory = $results['memory_used']['average'];
			$memory_mb = $avg_memory / 1024 / 1024;
			
			if ( $memory_mb > 100 ) {
				$recommendations[] = array(
					'priority' => 'high',
					'message'  => sprintf(
						/* translators: %s: memory in MB */
						__( 'High memory usage (%sMB). Consider processing data in chunks or reducing data structures.', 'mcp-ai-wpoos-pro' ),
						number_format( $memory_mb, 2 )
					),
				);
			} elseif ( $memory_mb > 50 ) {
				$recommendations[] = array(
					'priority' => 'medium',
					'message'  => sprintf(
						/* translators: %s: memory in MB */
						__( 'Moderate memory usage (%sMB). May cause issues on shared hosting.', 'mcp-ai-wpoos-pro' ),
						number_format( $memory_mb, 2 )
					),
				);
			}
		}

		// Check database queries.
		if ( isset( $results['db_queries']['average'] ) ) {
			$avg_queries = $results['db_queries']['average'];
			
			if ( $avg_queries > 50 ) {
				$recommendations[] = array(
					'priority' => 'high',
					'message'  => sprintf(
						/* translators: %s: number of queries */
						__( 'Excessive database queries (%s). Use caching or optimize queries with joins.', 'mcp-ai-wpoos-pro' ),
						number_format( $avg_queries, 0 )
					),
				);
			} elseif ( $avg_queries > 20 ) {
				$recommendations[] = array(
					'priority' => 'medium',
					'message'  => sprintf(
						/* translators: %s: number of queries */
						__( 'Consider reducing database queries (%s) with object caching.', 'mcp-ai-wpoos-pro' ),
						number_format( $avg_queries, 0 )
					),
				);
			}
		}

		// Check HTTP requests.
		if ( isset( $results['http_requests']['average'] ) && $results['http_requests']['average'] > 0 ) {
			$recommendations[] = array(
				'priority' => 'medium',
				'message'  => sprintf(
					/* translators: %s: number of requests */
					__( 'Tool makes external HTTP requests (%s). Implement caching and error handling.', 'mcp-ai-wpoos-pro' ),
					number_format( $results['http_requests']['average'], 1 )
				),
			);
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = array(
				'priority' => 'info',
				'message'  => __( 'Tool performance is good. No major optimizations needed.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return $recommendations;
	}

	/**
	 * Save benchmark results for future comparison.
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $results   Benchmark results.
	 * @return string Benchmark ID.
	 */
	private function save_benchmark_results( $tool_slug, $results ) {
		$benchmark_id = 'bench_' . $tool_slug . '_' . time();
		
		update_option( 'wp_mcp_ai_benchmark_' . $benchmark_id, array(
			'tool_slug'  => $tool_slug,
			'timestamp'  => current_time( 'mysql' ),
			'results'    => $results,
		) );

		return $benchmark_id;
	}

	/**
	 * Compare with baseline benchmark.
	 *
	 * @param array  $current  Current results.
	 * @param string $baseline_id Baseline benchmark ID.
	 * @return array|null Comparison results or null.
	 */
	private function compare_with_baseline( $current, $baseline_id ) {
		$baseline = get_option( 'wp_mcp_ai_benchmark_' . $baseline_id );

		if ( ! $baseline || ! isset( $baseline['results'] ) ) {
			return null;
		}

		$comparison = array();
		$baseline_results = $baseline['results'];

		// Compare execution time.
		if ( isset( $current['execution_time']['average'] ) && isset( $baseline_results['execution_time']['average'] ) ) {
			$change = ( ( $current['execution_time']['average'] - $baseline_results['execution_time']['average'] ) / $baseline_results['execution_time']['average'] ) * 100;
			$comparison['execution_time'] = array(
				'change_percent' => $change,
				'improved'       => $change < 0,
			);
		}

		// Compare memory usage.
		if ( isset( $current['memory_used']['average'] ) && isset( $baseline_results['memory_used']['average'] ) ) {
			$change = ( ( $current['memory_used']['average'] - $baseline_results['memory_used']['average'] ) / $baseline_results['memory_used']['average'] ) * 100;
			$comparison['memory_used'] = array(
				'change_percent' => $change,
				'improved'       => $change < 0,
			);
		}

		return $comparison;
	}
}
