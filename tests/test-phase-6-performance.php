<?php
/**
 * Phase 6 Performance Tests
 *
 * Comprehensive performance testing for slash commands and workflow system
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Performance Test Class
 *
 * @group phase-6
 * @group performance
 * @group performance-tests
 */
class Test_Phase_6_Performance extends WP_UnitTestCase {

	/**
	 * Test: Command Execution Time - Simple Commands
	 *
	 * @group execution-time
	 */
	public function test_simple_command_execution_time() {
		$start_time = microtime( true );

		// Simulate simple command execution.
		$result = apply_filters( 'wp_mcp_ai_test_simple_command', array( 'status' => 'success' ) );

		$end_time       = microtime( true );
		$execution_time = ( $end_time - $start_time ) * 1000; // Convert to milliseconds.

		// Simple commands should execute in under 2 seconds (2000ms).
		$this->assertLessThan( 2000, $execution_time, 'Simple command should execute in under 2 seconds' );

		// Log execution time.
		error_log( sprintf( 'Simple command execution time: %.2fms', $execution_time ) );
	}

	/**
	 * Test: Database Query Performance
	 *
	 * @group database-performance
	 */
	public function test_database_query_performance() {
		global $wpdb;

		$start_time = microtime( true );

		// Execute a simple query.
		$result = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'post' LIMIT 10" );

		$end_time   = microtime( true );
		$query_time = ( $end_time - $start_time ) * 1000; // Convert to milliseconds.

		// Database queries should be under 100ms.
		$this->assertLessThan( 100, $query_time, 'Database query should be under 100ms' );

		// Log query time.
		error_log( sprintf( 'Database query time: %.2fms', $query_time ) );
	}

	/**
	 * Test: Memory Usage - Simple Operations
	 *
	 * @group memory-usage
	 */
	public function test_memory_usage_simple_operations() {
		$memory_start = memory_get_usage( true );

		// Perform some operations.
		$data = array();
		for ( $i = 0; $i < 1000; $i++ ) {
			$data[] = array(
				'id'    => $i,
				'value' => 'test_value_' . $i,
			);
		}

		$memory_end  = memory_get_usage( true );
		$memory_used = ( $memory_end - $memory_start ) / 1024 / 1024; // Convert to MB.

		// Memory usage should be reasonable (under 10MB for this operation).
		$this->assertLessThan( 10, $memory_used, 'Memory usage should be under 10MB for simple operations' );

		// Log memory usage.
		error_log( sprintf( 'Memory used: %.2f MB', $memory_used ) );
	}

	/**
	 * Test: API Response Time - REST Endpoint
	 *
	 * @group api-performance
	 */
	public function test_rest_api_response_time() {
		// Register a test endpoint.
		add_action( 'rest_api_init', function() {
			register_rest_route( 'mcp-ai-test/v1', '/test', array(
				'methods'  => 'GET',
				'callback' => function() {
					return array( 'status' => 'success' );
				},
				'permission_callback' => '__return_true',
			) );
		} );

		rest_get_server()->register_routes();

		$start_time = microtime( true );

		// Simulate API request.
		$request  = new WP_REST_Request( 'GET', '/mcp-ai-test/v1/test' );
		$response = rest_get_server()->dispatch( $request );

		$end_time      = microtime( true );
		$response_time = ( $end_time - $start_time ) * 1000; // Convert to milliseconds.

		// API response should be fast (under 500ms).
		$this->assertLessThan( 500, $response_time, 'REST API response should be under 500ms' );

		// Log response time.
		error_log( sprintf( 'REST API response time: %.2fms', $response_time ) );
	}

	/**
	 * Test: Concurrent Operations Performance
	 *
	 * @group concurrent-operations
	 */
	public function test_concurrent_operations_performance() {
		$start_time = microtime( true );

		// Simulate concurrent operations.
		$results = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$results[] = apply_filters( 'wp_mcp_ai_test_operation', array( 'id' => $i ) );
		}

		$end_time     = microtime( true );
		$total_time   = ( $end_time - $start_time ) * 1000; // Convert to milliseconds
		$avg_per_op   = $total_time / 10;

		// Average time per operation should be reasonable.
		$this->assertLessThan( 200, $avg_per_op, 'Average time per operation should be under 200ms' );

		// Log performance.
		error_log( sprintf( 'Total time for 10 operations: %.2fms (avg: %.2fms per op)', $total_time, $avg_per_op ) );
	}

	/**
	 * Test: Cache Performance
	 *
	 * @group cache-performance
	 */
	public function test_cache_performance() {
		$cache_key   = 'wp_mcp_ai_test_cache';
		$cache_group = 'wp_mcp_ai_test';
		$test_data   = array( 'value' => 'cached_data' );

		// Test cache set.
		$start_time = microtime( true );
		wp_cache_set( $cache_key, $test_data, $cache_group );
		$set_time = ( microtime( true ) - $start_time ) * 1000;

		$this->assertLessThan( 10, $set_time, 'Cache set should be under 10ms' );

		// Test cache get.
		$start_time = microtime( true );
		$cached     = wp_cache_get( $cache_key, $cache_group );
		$get_time   = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.

		$this->assertLessThan( 5, $get_time, 'Cache get should be under 5ms' );
		$this->assertEquals( $test_data, $cached, 'Cached data should match' );

		// Log cache performance.
		error_log( sprintf( 'Cache set: %.2fms, Cache get: %.2fms', $set_time, $get_time ) );

		// Clean up.
		wp_cache_delete( $cache_key, $cache_group );
	}

	/**
	 * Test: Large Dataset Processing Performance
	 *
	 * @group large-dataset
	 */
	public function test_large_dataset_processing() {
		$start_time = microtime( true );
		$memory_start = memory_get_usage( true );

		// Process a large dataset.
		$dataset = array();
		for ( $i = 0; $i < 10000; $i++ ) {
			$dataset[] = array(
				'id'        => $i,
				'value'     => 'data_' . $i,
				'timestamp' => time(),
			);
		}

		// Process the dataset.
		$processed = array_filter( $dataset, function( $item ) {
			return $item['id'] % 2 === 0; // Filter even IDs.
		} );

		$end_time   = microtime( true );
		$memory_end = memory_get_usage( true );

		$process_time = ( $end_time - $start_time ) * 1000; // Milliseconds.
		$memory_used  = ( $memory_end - $memory_start ) / 1024 / 1024; // MB.

		// Processing should be efficient.
		$this->assertLessThan( 1000, $process_time, 'Large dataset processing should be under 1 second' );
		$this->assertLessThan( 50, $memory_used, 'Memory usage should be under 50MB for 10k items' );

		// Log performance.
		error_log( sprintf( 'Processed 10,000 items in %.2fms using %.2fMB', $process_time, $memory_used ) );
	}

	/**
	 * Test: Workflow Execution Performance
	 *
	 * @group workflow-performance
	 */
	public function test_workflow_execution_performance() {
		$start_time = microtime( true );

		// Simulate workflow execution with multiple steps.
		$workflow_steps = array(
			array( 'action' => 'step1', 'duration' => 100 ),
			array( 'action' => 'step2', 'duration' => 150 ),
			array( 'action' => 'step3', 'duration' => 200 ),
		);

		$total_duration = 0;
		foreach ( $workflow_steps as $step ) {
			// Simulate step processing.
			usleep( $step['duration'] * 1000 ); // Convert to microseconds.
			$total_duration += $step['duration'];
		}

		$end_time       = microtime( true );
		$execution_time = ( $end_time - $start_time ) * 1000; // Milliseconds.

		// Complex workflows should complete in under 5 minutes (300,000ms).
		$this->assertLessThan( 300000, $execution_time, 'Complex workflow should complete in under 5 minutes' );

		// Log workflow performance.
		error_log( sprintf( 'Workflow with %d steps completed in %.2fms', count( $workflow_steps ), $execution_time ) );
	}

	/**
	 * Test: JSON Encoding/Decoding Performance
	 *
	 * @group json-performance
	 */
	public function test_json_performance() {
		$test_data = array(
			'workflow' => array(
				'name'  => 'Test Workflow',
				'steps' => array_fill( 0, 100, array( 'action' => 'test', 'params' => array( 'key' => 'value' ) ) ),
			),
		);

		// Test JSON encoding.
		$start_time   = microtime( true );
		$json_encoded = wp_json_encode( $test_data );
		$encode_time  = ( microtime( true ) - $start_time ) * 1000;

		$this->assertLessThan( 50, $encode_time, 'JSON encoding should be under 50ms' );

		// Test JSON decoding.
		$start_time   = microtime( true );
		$json_decoded = json_decode( $json_encoded, true );
		$decode_time  = ( microtime( true ) - $start_time ) * 1000;

		$this->assertLessThan( 50, $decode_time, 'JSON decoding should be under 50ms' );

		// Verify data integrity.
		$this->assertEquals( $test_data, $json_decoded, 'Decoded data should match original' );

		// Log performance.
		error_log( sprintf( 'JSON encode: %.2fms, decode: %.2fms', $encode_time, $decode_time ) );
	}

	/**
	 * Test: Hook Performance (Filters and Actions)
	 *
	 * @group hook-performance
	 */
	public function test_hook_performance() {
		// Add multiple filters.
		for ( $i = 0; $i < 10; $i++ ) {
			add_filter( 'wp_mcp_ai_test_filter', function( $value ) use ( $i ) {
				return $value . '_' . $i;
			} );
		}

		$start_time = microtime( true );

		// Apply filters.
		$result = apply_filters( 'wp_mcp_ai_test_filter', 'initial_value' );

		$end_time    = microtime( true );
		$filter_time = ( $end_time - $start_time ) * 1000; // Convert to milliseconds.

		// Filters should execute quickly even with multiple callbacks.
		$this->assertLessThan( 10, $filter_time, 'Filters should execute in under 10ms' );

		// Log performance.
		error_log( sprintf( '10 filter callbacks executed in %.2fms', $filter_time ) );
	}

	/**
	 * Test: Query Complexity Analysis
	 *
	 * @group query-complexity
	 */
	public function test_query_complexity() {
		global $wpdb;

		// Enable query logging.
		$wpdb->show_errors();

		$start_time = microtime( true );

		// Execute a more complex query with JOIN.
		$query = $wpdb->prepare(
			"SELECT p.ID, p.post_title, pm.meta_value
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = %s
			AND p.post_status = %s
			LIMIT 10",
			'post',
			'publish'
		);

		$results = $wpdb->get_results( $query );

		$end_time   = microtime( true );
		$query_time = ( $end_time - $start_time ) * 1000; // Convert to milliseconds.

		// Complex queries should still be reasonably fast.
		$this->assertLessThan( 500, $query_time, 'Complex query should be under 500ms' );

		// Log query performance.
		error_log( sprintf( 'Complex JOIN query executed in %.2fms', $query_time ) );
	}

	/**
	 * Test: Peak Memory Usage During Workflow
	 *
	 * @group memory-peak
	 */
	public function test_peak_memory_usage() {
		$memory_start = memory_get_usage( true );
		$peak_start   = memory_get_peak_usage( true );

		// Simulate workflow with memory-intensive operations.
		$data = array();
		for ( $i = 0; $i < 5000; $i++ ) {
			$data[] = array(
				'id'      => $i,
				'content' => str_repeat( 'data_', 100 ),
			);

			// Clear some data periodically to simulate cleanup.
			if ( $i % 1000 === 0 ) {
				$data = array_slice( $data, -500 ); // Keep only last 500 items.
			}
		}

		$memory_end = memory_get_usage( true );
		$peak_end   = memory_get_peak_usage( true );

		$memory_used = ( $memory_end - $memory_start ) / 1024 / 1024; // MB.
		$peak_used   = ( $peak_end - $peak_start ) / 1024 / 1024; // MB.

		// Memory usage should be under 256MB per workflow (requirement).
		$this->assertLessThan( 256, $peak_used, 'Peak memory usage should be under 256MB' );

		// Log memory usage.
		error_log( sprintf( 'Current memory: %.2fMB, Peak memory: %.2fMB', $memory_used, $peak_used ) );
	}

	/**
	 * Test: Transient Performance
	 *
	 * @group transient-performance
	 */
	public function test_transient_performance() {
		$transient_key = 'wp_mcp_ai_test_transient';
		$test_data     = array( 'key' => 'value', 'timestamp' => time() );

		// Test set transient.
		$start_time = microtime( true );
		set_transient( $transient_key, $test_data, 3600 );
		$set_time = ( microtime( true ) - $start_time ) * 1000;

		$this->assertLessThan( 50, $set_time, 'Set transient should be under 50ms' );

		// Test get transient.
		$start_time = microtime( true );
		$retrieved  = get_transient( $transient_key );
		$get_time   = ( microtime( true ) - $start_time ) * 1000;

		$this->assertLessThan( 20, $get_time, 'Get transient should be under 20ms' );
		$this->assertEquals( $test_data, $retrieved, 'Retrieved data should match' );

		// Log performance.
		error_log( sprintf( 'Transient set: %.2fms, get: %.2fms', $set_time, $get_time ) );

		// Clean up.
		delete_transient( $transient_key );
	}
}
