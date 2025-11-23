<?php
/**
 * JetEngine Custom Content Type registration for Plugin Performance Monitoring.
 *
 * Stores test results from stress, security, and speed tests to help AI assistants
 * diagnose and fix plugin performance issues.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the Plugin Performance Monitor CCT exists and expose helper accessors.
 */
class WP_MCP_AI_Performance_Monitor_CCT {
	const SLUG = 'plugin_performance_monitor';

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
	 * Hook into JetEngine to provision the performance monitoring content type.
	 */
	public static function bootstrap() {
		// Run after JetEngine initialises the Custom Content Types module but before
		// the manager registers existing instances (priority 10).
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 0 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 0 );
	}

	/**
	 * Retrieve the performance monitor CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the performance monitor content type.
	 *
	 * Consumers can use the returned handler similarly to `jet_engine()->cct->item_handler`
	 * when interacting with the performance records programmatically.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return null;
		}

		if ( empty( $module->manager ) ) {
			return null;
		}

		$instance = $module->manager->get_content_types( self::SLUG );

		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}

	/**
	 * Retrieve the JetEngine content type instance.
	 *
	 * This provides access to the type object which has the db property for querying.
	 *
	 * @return object|null
	 */
	protected static function get_content_type() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return null;
		}

		if ( empty( $module->manager ) ) {
			return null;
		}

		return $module->manager->get_content_types( self::SLUG );
	}

	/**
	 * Query items from JetEngine CCT with compatibility for both old and new API.
	 *
	 * This method handles the API change in JetEngine where query_items() was removed
	 * from Item_Handler and replaced with direct database queries.
	 *
	 * @param array $args Query arguments.
	 * @param int   $limit Maximum number of items to return.
	 * @param int   $offset Offset for pagination.
	 * @return array Array of items.
	 */
	public static function query_items( $args, $limit = 100, $offset = 0 ) {
		$type_object = self::get_content_type();

		if ( ! $type_object ) {
			return array();
		}

		// Try new API first (JetEngine 3.3+).
		if ( ! empty( $type_object->db ) && method_exists( $type_object->db, 'query' ) ) {
			// Convert simple args to JetEngine query format.
			$query_args = self::prepare_jetengine_query_args( $args, $type_object );

			// Set result format to associative array.
			if ( method_exists( $type_object->db, 'set_format_flag' ) ) {
				$type_object->db->set_format_flag( ARRAY_A );
			}

			// Execute query.
			$items = $type_object->db->query( $query_args, $limit, $offset );

			return is_array( $items ) ? $items : array();
		}

		// Fallback to old API (JetEngine < 3.3).
		$handler = $type_object->get_item_handler();
		if ( $handler && method_exists( $handler, 'query_items' ) ) {
			$items = $handler->query_items( $args );
			return is_array( $items ) ? $items : array();
		}

		return array();
	}

	/**
	 * Prepare query arguments for JetEngine's new query format.
	 *
	 * Converts simple key-value pairs to JetEngine's query builder format.
	 *
	 * @param array  $args        Simple query arguments.
	 * @param object $type_object JetEngine content type instance.
	 * @return array Prepared query arguments.
	 */
	protected static function prepare_jetengine_query_args( $args, $type_object ) {
		$query_args = array();

		foreach ( $args as $field => $value ) {
			// Handle date range queries.
			if ( is_array( $value ) && isset( $value['type'] ) && 'DATE' === $value['type'] ) {
				if ( isset( $value['value'] ) && is_array( $value['value'] ) && 2 === count( $value['value'] ) ) {
					$query_args[] = array(
						'field'    => $field,
						'operator' => 'BETWEEN',
						'value'    => $value['value'],
						'type'     => 'DATE',
					);
				}
			} else {
				// Simple equality check.
				$query_args[] = array(
					'field'    => $field,
					'operator' => '=',
					'value'    => $value,
				);
			}
		}

		return $query_args;
	}

	/**
	 * Store a test result for assistant-friendly diagnostics.
	 *
	 * This method stores performance test results in a format optimized for
	 * AI assistants to analyze and provide diagnostic recommendations.
	 *
	 * @param string $test_type            Type of test (stress, security, speed, optimization).
	 * @param string $component            Component tested (rest_api, chat_ui, mcp_core, elementor, cpt).
	 * @param bool   $optimizations_enabled Whether optimizations were enabled during test.
	 * @param array  $metrics              Performance metrics array.
	 * @param array  $test_results         Detailed test results array.
	 * @return int|false Item ID on success, false on failure.
	 */
	public static function store_test_result( $test_type, $component, $optimizations_enabled, $metrics = array(), $test_results = array() ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			// Fall back to WordPress options if JetEngine CCT is not available.
			return self::store_test_result_fallback( $test_type, $component, $optimizations_enabled, $metrics, $test_results );
		}

		// Calculate summary statistics for assistant diagnostics.
		$diagnostic_summary = self::generate_diagnostic_summary( $test_type, $component, $metrics, $test_results );

		$data = array(
			'test_type'             => sanitize_key( $test_type ),
			'component'             => sanitize_key( $component ),
			'optimizations_enabled' => $optimizations_enabled ? 'yes' : 'no',
			'response_time_ms'      => isset( $metrics['avg_response_time'] ) ? floatval( $metrics['avg_response_time'] ) : 0,
			'memory_usage_bytes'    => isset( $metrics['memory_peak_bytes'] ) ? absint( $metrics['memory_peak_bytes'] ) : 0,
			'db_queries'            => isset( $metrics['db_queries'] ) ? absint( $metrics['db_queries'] ) : 0,
			'error_rate'            => isset( $metrics['error_rate'] ) ? floatval( $metrics['error_rate'] ) : 0,
			'total_errors'          => isset( $metrics['total_errors'] ) ? absint( $metrics['total_errors'] ) : 0,
			'metrics_json'          => wp_json_encode( $metrics ),
			'test_results_json'     => wp_json_encode( $test_results ),
			'diagnostic_summary'    => sanitize_textarea_field( $diagnostic_summary ),
			'test_status'           => self::determine_test_status( $metrics, $test_results ),
			'recommendations'       => wp_json_encode( self::generate_recommendations( $test_type, $metrics, $test_results ) ),
			'tested_at'             => current_time( 'mysql' ),
			'php_version'           => PHP_VERSION,
			'wp_version'            => get_bloginfo( 'version' ),
			'plugin_version'        => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
		);

		// JetEngine CCT requires cct_created field.
		$data['cct_created'] = current_time( 'mysql' );

		return $handler->update_item( $data );
	}

	/**
	 * Generate a human-readable diagnostic summary for AI assistants.
	 *
	 * @param string $test_type    Test type.
	 * @param string $component    Component tested.
	 * @param array  $metrics      Performance metrics.
	 * @param array  $test_results Test results.
	 * @return string Diagnostic summary.
	 */
	protected static function generate_diagnostic_summary( $test_type, $component, $metrics, $test_results ) {
		$summary = array();

		$summary[] = sprintf(
			'Test: %s test on %s component',
			ucfirst( $test_type ),
			$component
		);

		// Add key metrics.
		if ( isset( $metrics['avg_response_time'] ) ) {
			$summary[] = sprintf( 'Avg Response Time: %.2f ms', $metrics['avg_response_time'] );
		}

		if ( isset( $metrics['memory_peak_mb'] ) ) {
			$summary[] = sprintf( 'Peak Memory: %.2f MB', $metrics['memory_peak_mb'] );
		}

		if ( isset( $metrics['db_queries'] ) ) {
			$summary[] = sprintf( 'DB Queries: %d', $metrics['db_queries'] );
		}

		if ( isset( $metrics['error_rate'] ) ) {
			$summary[] = sprintf( 'Error Rate: %.2f%%', $metrics['error_rate'] );
		}

		if ( isset( $metrics['total_errors'] ) && $metrics['total_errors'] > 0 ) {
			$summary[] = sprintf( 'Errors: %d', $metrics['total_errors'] );
		}

		// Add test-specific insights.
		if ( 'stress' === $test_type && isset( $metrics['concurrent_requests'] ) ) {
			$summary[] = sprintf( 'Handled %d concurrent requests', $metrics['concurrent_requests'] );
		}

		if ( 'security' === $test_type && isset( $test_results['vulnerabilities_found'] ) ) {
			$summary[] = sprintf( 'Vulnerabilities: %d', $test_results['vulnerabilities_found'] );
		}

		if ( isset( $test_results['passed'] ) && isset( $test_results['failed'] ) ) {
			$summary[] = sprintf(
				'Results: %d passed, %d failed',
				$test_results['passed'],
				$test_results['failed']
			);
		}

		return implode( ' | ', $summary );
	}

	/**
	 * Determine test status based on metrics and results.
	 *
	 * @param array $metrics      Performance metrics.
	 * @param array $test_results Test results.
	 * @return string Status: 'passed', 'warning', or 'failed'.
	 */
	protected static function determine_test_status( $metrics, $test_results ) {
		// Check for explicit failures.
		if ( isset( $test_results['failed'] ) && $test_results['failed'] > 0 ) {
			return 'failed';
		}

		if ( isset( $test_results['vulnerabilities_found'] ) && $test_results['vulnerabilities_found'] > 0 ) {
			return 'failed';
		}

		// Check for performance warnings.
		if ( isset( $metrics['avg_response_time'] ) && $metrics['avg_response_time'] > 1000 ) {
			return 'warning'; // Over 1 second is slow.
		}

		if ( isset( $metrics['memory_peak_mb'] ) && $metrics['memory_peak_mb'] > 256 ) {
			return 'warning'; // Over 256 MB is high.
		}

		if ( isset( $metrics['db_queries'] ) && $metrics['db_queries'] > 100 ) {
			return 'warning'; // Over 100 queries per request is high.
		}

		return 'passed';
	}

	/**
	 * Generate actionable recommendations for AI assistants.
	 *
	 * @param string $test_type    Test type.
	 * @param array  $metrics      Performance metrics.
	 * @param array  $test_results Test results.
	 * @return array Recommendations array.
	 */
	protected static function generate_recommendations( $test_type, $metrics, $test_results ) {
		$recommendations = array();

		// Response time recommendations.
		if ( isset( $metrics['avg_response_time'] ) ) {
			if ( $metrics['avg_response_time'] > 2000 ) {
				$recommendations[] = array(
					'severity' => 'high',
					'issue'    => 'Slow response times detected',
					'action'   => 'Enable caching and optimize database queries',
				);
			} elseif ( $metrics['avg_response_time'] > 1000 ) {
				$recommendations[] = array(
					'severity' => 'medium',
					'issue'    => 'Response times above optimal threshold',
					'action'   => 'Consider enabling optimization features',
				);
			}
		}

		// Memory recommendations.
		if ( isset( $metrics['memory_peak_mb'] ) ) {
			if ( $metrics['memory_peak_mb'] > 256 ) {
				$recommendations[] = array(
					'severity' => 'high',
					'issue'    => 'High memory usage detected',
					'action'   => 'Review memory-intensive operations and implement pagination',
				);
			}
		}

		// Database query recommendations.
		if ( isset( $metrics['db_queries'] ) ) {
			if ( $metrics['db_queries'] > 100 ) {
				$recommendations[] = array(
					'severity' => 'medium',
					'issue'    => 'Excessive database queries',
					'action'   => 'Implement query caching or reduce N+1 query patterns',
				);
			}
		}

		// Error rate recommendations.
		if ( isset( $metrics['error_rate'] ) ) {
			if ( $metrics['error_rate'] > 10 ) {
				$recommendations[] = array(
					'severity' => 'critical',
					'issue'    => sprintf( 'High error rate detected: %.2f%%', $metrics['error_rate'] ),
					'action'   => 'Review error logs and fix critical issues immediately',
				);
			} elseif ( $metrics['error_rate'] > 5 ) {
				$recommendations[] = array(
					'severity' => 'high',
					'issue'    => sprintf( 'Elevated error rate: %.2f%%', $metrics['error_rate'] ),
					'action'   => 'Investigate and address error sources',
				);
			} elseif ( $metrics['error_rate'] > 1 ) {
				$recommendations[] = array(
					'severity' => 'medium',
					'issue'    => sprintf( 'Error rate above baseline: %.2f%%', $metrics['error_rate'] ),
					'action'   => 'Monitor error patterns and consider preventive measures',
				);
			}
		}

		// Security recommendations.
		if ( 'security' === $test_type && isset( $test_results['vulnerabilities_found'] ) && $test_results['vulnerabilities_found'] > 0 ) {
			$recommendations[] = array(
				'severity' => 'critical',
				'issue'    => sprintf( '%d security vulnerabilities found', $test_results['vulnerabilities_found'] ),
				'action'   => 'Review and patch identified vulnerabilities immediately',
			);
		}

		return $recommendations;
	}

	/**
	 * Retrieve performance trends for diagnostic analysis.
	 *
	 * @param string $component  Component to analyze.
	 * @param string $since      Date string for how far back to analyze (e.g., '-7 days').
	 * @param string $test_type  Optional test type filter.
	 * @return array Performance trends data.
	 */
	public static function get_performance_trends( $component, $since = '-7 days', $test_type = '' ) {
		$args = array(
			'component' => sanitize_key( $component ),
		);

		if ( ! empty( $test_type ) ) {
			$args['test_type'] = sanitize_key( $test_type );
		}

		$since_timestamp = strtotime( $since );
		if ( $since_timestamp ) {
			$args['tested_at'] = array(
				'type'  => 'DATE',
				'value' => array( gmdate( 'Y-m-d H:i:s', $since_timestamp ), current_time( 'mysql' ) ),
			);
		}

		$items = self::query_items( $args );

		// Fallback to WordPress options if JetEngine is unavailable.
		if ( empty( $items ) && ! self::get_content_type() ) {
			return self::get_performance_trends_fallback( $component, $since, $test_type );
		}

		return self::analyze_trends( $items );
	}

	/**
	 * Analyze trends from performance data.
	 *
	 * @param array $items Performance test items.
	 * @return array Analyzed trends.
	 */
	protected static function analyze_trends( $items ) {
		if ( empty( $items ) ) {
			return array(
				'trend'               => 'no_data',
				'avg_response_time'   => 0,
				'avg_memory_usage'    => 0,
				'avg_db_queries'      => 0,
				'status_distribution' => array(),
			);
		}

		$response_times = array();
		$memory_usages  = array();
		$db_queries     = array();
		$statuses       = array();

		foreach ( $items as $item ) {
			if ( isset( $item['response_time_ms'] ) ) {
				$response_times[] = floatval( $item['response_time_ms'] );
			}
			if ( isset( $item['memory_usage_bytes'] ) ) {
				$memory_usages[] = floatval( $item['memory_usage_bytes'] ) / 1024 / 1024; // Convert to MB.
			}
			if ( isset( $item['db_queries'] ) ) {
				$db_queries[] = intval( $item['db_queries'] );
			}
			if ( isset( $item['test_status'] ) ) {
				$status              = $item['test_status'];
				$statuses[ $status ] = isset( $statuses[ $status ] ) ? $statuses[ $status ] + 1 : 1;
			}
		}

		$trend = 'stable';
		if ( count( $response_times ) >= 3 ) {
			// Simple trend analysis: compare first third to last third.
			$first_third = array_slice( $response_times, 0, (int) ( count( $response_times ) / 3 ) );
			$last_third  = array_slice( $response_times, - (int) ( count( $response_times ) / 3 ) );

			$first_avg = array_sum( $first_third ) / count( $first_third );
			$last_avg  = array_sum( $last_third ) / count( $last_third );

			if ( $last_avg > $first_avg * 1.2 ) {
				$trend = 'degrading';
			} elseif ( $last_avg < $first_avg * 0.8 ) {
				$trend = 'improving';
			}
		}

		return array(
			'trend'               => $trend,
			'avg_response_time'   => ! empty( $response_times ) ? array_sum( $response_times ) / count( $response_times ) : 0,
			'avg_memory_usage'    => ! empty( $memory_usages ) ? array_sum( $memory_usages ) / count( $memory_usages ) : 0,
			'avg_db_queries'      => ! empty( $db_queries ) ? array_sum( $db_queries ) / count( $db_queries ) : 0,
			'status_distribution' => $statuses,
			'total_tests'         => count( $items ),
		);
	}

	/**
	 * Fallback method to store test results in WordPress options when JetEngine is not available.
	 *
	 * @param string $test_type            Test type.
	 * @param string $component            Component tested.
	 * @param bool   $optimizations_enabled Optimizations state.
	 * @param array  $metrics              Performance metrics.
	 * @param array  $test_results         Test results.
	 * @return int|false
	 */
	protected static function store_test_result_fallback( $test_type, $component, $optimizations_enabled, $metrics, $test_results ) {
		$option_key = 'wp_mcp_ai_performance_tests';
		$tests      = self::get_settings_repository()->get( 'performance_tests', array() );

		if ( ! is_array( $tests ) ) {
			$tests = array();
		}

		$test_id = uniqid( 'test_', true );

		$tests[ $test_id ] = array(
			'test_type'             => sanitize_key( $test_type ),
			'component'             => sanitize_key( $component ),
			'optimizations_enabled' => $optimizations_enabled,
			'metrics'               => $metrics,
			'test_results'          => $test_results,
			'diagnostic_summary'    => self::generate_diagnostic_summary( $test_type, $component, $metrics, $test_results ),
			'test_status'           => self::determine_test_status( $metrics, $test_results ),
			'recommendations'       => self::generate_recommendations( $test_type, $metrics, $test_results ),
			'tested_at'             => current_time( 'mysql' ),
			'php_version'           => PHP_VERSION,
			'wp_version'            => get_bloginfo( 'version' ),
			'plugin_version'        => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
		);

		// Keep only the last 100 tests to avoid bloating options.
		if ( count( $tests ) > 100 ) {
			$tests = array_slice( $tests, -100, 100, true );
		}

		self::get_settings_repository()->update( 'performance_tests', $tests );

		return $test_id;
	}

	/**
	 * Fallback method to retrieve performance trends from WordPress options.
	 *
	 * @param string $component Component.
	 * @param string $since     Since date.
	 * @param string $test_type Test type.
	 * @return array
	 */
	protected static function get_performance_trends_fallback( $component, $since, $test_type ) {
		$option_key = 'wp_mcp_ai_performance_tests';
		$tests      = self::get_settings_repository()->get( 'performance_tests', array() );

		if ( ! is_array( $tests ) ) {
			return array( 'trend' => 'no_data' );
		}

		$since_timestamp = strtotime( $since );
		$filtered_tests  = array();

		foreach ( $tests as $test ) {
			if ( isset( $test['component'] ) && $test['component'] === $component ) {
				if ( empty( $test_type ) || ( isset( $test['test_type'] ) && $test['test_type'] === $test_type ) ) {
					if ( isset( $test['tested_at'] ) ) {
						$test_timestamp = strtotime( $test['tested_at'] );
						if ( $test_timestamp >= $since_timestamp ) {
							$filtered_tests[] = $test;
						}
					}
				}
			}
		}

		return self::analyze_trends( $filtered_tests );
	}

	/**
	 * Automatically enable the JetEngine data stores module if it's not already active.
	 */
	public static function maybe_enable_data_stores() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return;
		}

		// Check if data stores module is already active.
		if ( $engine->modules->is_module_active( 'data-stores' ) ) {
			return;
		}

		// Check if the module exists.
		if ( ! method_exists( $engine->modules, 'get_module' ) ) {
			return;
		}

		$module = $engine->modules->get_module( 'data-stores' );

		if ( ! $module ) {
			return;
		}

		// Activate the data stores module.
		if ( method_exists( $engine->modules, 'activate_module' ) ) {
			$engine->modules->activate_module( 'data-stores' );
		}
	}

	/**
	 * Register the performance monitor CCT if it is missing.
	 */
	public static function maybe_register_cct() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return;
		}

		if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
			return;
		}

		if ( self::cct_exists( $module ) ) {
			return;
		}

		$data    = $module->manager->data;
		$request = self::get_registration_request();

		$data->set_request( $request );

		if ( method_exists( $data, 'sanitize_item_request' ) && ! $data->sanitize_item_request() ) {
			return;
		}

		$item = $data->sanitize_item_from_request();

		if ( empty( $item ) || ! is_array( $item ) ) {
			return;
		}

		$data->before_item_update( $item, true );

		$item_id = $data->update_item_in_db( $item );

		if ( ! $item_id ) {
			return;
		}

		$item['id'] = $item_id;

		$data->after_item_update( $item, true );

		if ( ! empty( $data->db ) && method_exists( $data->db, 'query_raw' ) ) {
			$data->db->query_raw( 'post_types' );
		}
	}

	/**
	 * Determine whether the performance monitor CCT already exists.
	 *
	 * @param \Jet_Engine\Modules\Custom_Content_Types\Module $module Module instance.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		$data = $module->manager->data;

		if ( empty( $data->db ) ) {
			return false;
		}

		$records = $data->db->query(
			'post_types',
			array(
				'slug'   => self::SLUG,
				'status' => 'content-type',
			),
			null,
			false
		);

		return ! empty( $records );
	}

	/**
	 * Retrieve the JetEngine Custom Content Types module instance.
	 *
	 * @return \Jet_Engine\Modules\Custom_Content_Types\Module|null
	 */
	protected static function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return null;
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return null;
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );

		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return null;
		}

		return $module_wrapper->instance;
	}

	/**
	 * Build the request payload used to register the content type.
	 *
	 * @return array
	 */
	protected static function get_registration_request() {
		$label = __( 'Plugin Performance Monitor', 'wp-mcp-ai' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Assemble the JetEngine arguments for the performance monitor CCT.
	 *
	 * @param string $label Human-readable label for the content type.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-performance',
			'capability'          => 'manage_options',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => true,
			'rest_post_enabled'   => true,
			'rest_delete_enabled' => false,
			'rest_get_access'     => 'manage_options',
			'rest_put_access'     => 'manage_options',
			'rest_post_access'    => 'manage_options',
			'rest_delete_access'  => 'manage_options',
			'admin_columns'       => array(
				'_ID'                   => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'test_type'             => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'component'             => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'test_status'           => array(
					'enabled' => true,
				),
				'response_time_ms'      => array(
					'enabled' => true,
					'is_num'  => true,
				),
				'optimizations_enabled' => array(
					'enabled' => true,
				),
				'tested_at'             => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Define the performance monitor meta field configuration.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		$fields = array(
			self::build_field(
				10001,
				'test_type',
				__( 'Test Type', 'wp-mcp-ai' ),
				'select',
				array(
					'is_required' => true,
					'options'     => array(
						array(
							'key'   => 'stress',
							'value' => __( 'Stress Test', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'security',
							'value' => __( 'Security Test', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'speed',
							'value' => __( 'Speed Benchmark', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'optimization',
							'value' => __( 'Optimization Comparison', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'monitoring',
							'value' => __( 'Error Monitoring', 'wp-mcp-ai' ),
						),
					),
					'description' => __( 'Type of performance test conducted.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10002,
				'component',
				__( 'Component', 'wp-mcp-ai' ),
				'select',
				array(
					'is_required' => true,
					'options'     => array(
						array(
							'key'   => 'rest_api',
							'value' => __( 'REST API', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'chat_ui',
							'value' => __( 'Chat UI', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'mcp_core',
							'value' => __( 'MCP Core', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'elementor',
							'value' => __( 'Elementor Integration', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'cpt_ai_peer',
							'value' => __( 'CPT: AI Peer', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'cpt_assistant',
							'value' => __( 'CPT: Assistant', 'wp-mcp-ai' ),
						),
					),
					'description' => __( 'Plugin component that was tested.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10003,
				'optimizations_enabled',
				__( 'Optimizations Enabled', 'wp-mcp-ai' ),
				'radio',
				array(
					'is_required' => true,
					'options'     => array(
						array(
							'key'   => 'yes',
							'value' => __( 'Yes', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'no',
							'value' => __( 'No', 'wp-mcp-ai' ),
						),
					),
					'description' => __( 'Whether optimization features were enabled during the test.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10004,
				'response_time_ms',
				__( 'Response Time (ms)', 'wp-mcp-ai' ),
				'number',
				array(
					'description' => __( 'Average response time in milliseconds.', 'wp-mcp-ai' ),
					'step'        => '0.01',
				)
			),
			self::build_field(
				10005,
				'memory_usage_bytes',
				__( 'Memory Usage (bytes)', 'wp-mcp-ai' ),
				'number',
				array(
					'description' => __( 'Peak memory usage in bytes.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10006,
				'db_queries',
				__( 'Database Queries', 'wp-mcp-ai' ),
				'number',
				array(
					'description' => __( 'Number of database queries executed.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10016,
				'error_rate',
				__( 'Error Rate (%)', 'wp-mcp-ai' ),
				'number',
				array(
					'description' => __( 'Percentage of requests that resulted in errors.', 'wp-mcp-ai' ),
					'step'        => '0.01',
				)
			),
			self::build_field(
				10017,
				'total_errors',
				__( 'Total Errors', 'wp-mcp-ai' ),
				'number',
				array(
					'description' => __( 'Total number of errors encountered during the test.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10007,
				'metrics_json',
				__( 'Performance Metrics (JSON)', 'wp-mcp-ai' ),
				'textarea',
				array(
					'description' => __( 'Complete performance metrics in JSON format for AI assistant analysis.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10008,
				'test_results_json',
				__( 'Test Results (JSON)', 'wp-mcp-ai' ),
				'textarea',
				array(
					'description' => __( 'Detailed test results in JSON format for AI assistant diagnostics.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10009,
				'diagnostic_summary',
				__( 'Diagnostic Summary', 'wp-mcp-ai' ),
				'textarea',
				array(
					'description' => __( 'Human-readable summary for quick AI assistant interpretation.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10010,
				'test_status',
				__( 'Test Status', 'wp-mcp-ai' ),
				'select',
				array(
					'options'     => array(
						array(
							'key'   => 'passed',
							'value' => __( 'Passed', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'warning',
							'value' => __( 'Warning', 'wp-mcp-ai' ),
						),
						array(
							'key'   => 'failed',
							'value' => __( 'Failed', 'wp-mcp-ai' ),
						),
					),
					'description' => __( 'Overall test result status for quick filtering.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10011,
				'recommendations',
				__( 'AI Recommendations (JSON)', 'wp-mcp-ai' ),
				'textarea',
				array(
					'description' => __( 'Actionable recommendations generated for AI assistants to suggest fixes.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10012,
				'tested_at',
				__( 'Tested At', 'wp-mcp-ai' ),
				'datetime-local',
				array(
					'is_required' => true,
					'description' => __( 'Timestamp when the test was executed.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10013,
				'php_version',
				__( 'PHP Version', 'wp-mcp-ai' ),
				'text',
				array(
					'description' => __( 'PHP version during test execution.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10014,
				'wp_version',
				__( 'WordPress Version', 'wp-mcp-ai' ),
				'text',
				array(
					'description' => __( 'WordPress version during test execution.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10015,
				'plugin_version',
				__( 'Plugin Version', 'wp-mcp-ai' ),
				'text',
				array(
					'description' => __( 'WP MCP AI plugin version during test execution.', 'wp-mcp-ai' ),
				)
			),
		);

		return $fields;
	}

	/**
	 * Build a single meta field definition.
	 *
	 * @param int    $id      Field ID.
	 * @param string $name    Field name (slug).
	 * @param string $title   Field title.
	 * @param string $type    Field type.
	 * @param array  $options Additional field options.
	 * @return array
	 */
	protected static function build_field( $id, $name, $title, $type, $options = array() ) {
		$base = array(
			'id'    => $id,
			'value' => $name,
			'title' => $title,
			'name'  => $name,
			'type'  => $type,
		);

		return array_merge( $base, $options );
	}
}

// Bootstrap the Performance Monitor CCT.
WP_MCP_AI_Performance_Monitor_CCT::bootstrap();
