<?php
/**
 * Stress Tests for WP oOS Performance Monitor.
 *
 * Tests plugin performance under heavy load conditions including:
 * - Concurrent API requests
 * - Multiple chat sessions
 * - Database query performance
 * - Custom Post Type bulk operations
 * - Tool execution concurrency
 *
 * @package WP_MCP_AI
 */

/**
 * Stress test suite class.
 */
class WP_MCP_AI_Stress_Suite_Test extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user with manage_options capability for REST API calls.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test concurrent API requests.
	 *
	 * Simulates multiple simultaneous API requests to test performance
	 * under concurrent load.
	 */
	public function test_concurrent_api_requests() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$concurrent_requests = 50;
		$start_time          = microtime( true );
		$start_memory        = memory_get_usage();
		$responses           = array();

		// Simulate concurrent REST API requests.
		for ( $i = 0; $i < $concurrent_requests; $i++ ) {
			$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

			$response    = rest_do_request( $request );
			$responses[] = $response;
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000; // Convert to milliseconds.
		$memory_used  = $end_memory - $start_memory;

		// Calculate metrics.
		$avg_response_time = $elapsed_time / $concurrent_requests;
		$success_count     = 0;

		foreach ( $responses as $response ) {
			if ( $response->get_status() === 200 ) {
				++$success_count;
			}
		}

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'stress',
			'rest_api',
			false,
			array(
				'concurrent_requests' => $concurrent_requests,
				'avg_response_time'   => $avg_response_time,
				'max_response_time'   => $elapsed_time,
				'memory_peak_bytes'   => $memory_used,
				'memory_peak_mb'      => $memory_used / 1024 / 1024,
			),
			array(
				'total'  => $concurrent_requests,
				'passed' => $success_count,
				'failed' => $concurrent_requests - $success_count,
			)
		);

		// Assertions.
		$this->assertGreaterThan( 0, $success_count, 'At least some requests should succeed under stress.' );
		$this->assertLessThan( 5000, $avg_response_time, 'Average response time should be under 5 seconds.' );
	}

	/**
	 * Test multiple chat session load.
	 *
	 * Simulates multiple chat sessions to test concurrent chat handling.
	 */
	public function test_multiple_chat_sessions() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$session_count = 10;
		$start_time    = microtime( true );
		$start_memory  = memory_get_usage();

		// Create test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		update_post_meta( $assistant_id, 'provider', 'openai' );
		update_post_meta( $assistant_id, 'model', 'gpt-3.5-turbo' );

		$messages_sent = 0;

		// Simulate multiple chat sessions.
		for ( $i = 0; $i < $session_count; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$request->set_param( 'assistant_id', $assistant_id );
			$request->set_param( 'message', 'Test message ' . $i );

			// Mock the chat request (don't actually call external API).
			add_filter(
				'pre_http_request',
				function () {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'choices' => array(
									array(
										'message' => array(
											'role'    => 'assistant',
											'content' => 'Test response',
										),
									),
								),
							)
						),
					);
				}
			);

			$response = rest_do_request( $request );

			if ( $response->get_status() === 200 ) {
				++$messages_sent;
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'stress',
			'chat_ui',
			false,
			array(
				'concurrent_sessions' => $session_count,
				'avg_response_time'   => $elapsed_time / $session_count,
				'memory_peak_bytes'   => $memory_used,
				'memory_peak_mb'      => $memory_used / 1024 / 1024,
			),
			array(
				'total'  => $session_count,
				'passed' => $messages_sent,
				'failed' => $session_count - $messages_sent,
			)
		);

		$this->assertGreaterThan( 0, $messages_sent, 'At least some chat sessions should succeed.' );
	}

	/**
	 * Test database query performance under load.
	 *
	 * Tests database performance with multiple queries.
	 */
	public function test_database_query_performance() {
		global $wpdb;

		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$query_count  = 100;
		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Get query count before.
		$queries_before = $wpdb->num_queries;

		// Simulate database queries.
		for ( $i = 0; $i < $query_count; $i++ ) {
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->posts} WHERE post_type = %s LIMIT 10",
					'mcp_ai_assistant'
				)
			);
		}

		$end_time      = microtime( true );
		$end_memory    = memory_get_usage();
		$queries_after = $wpdb->num_queries;

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;
		$db_queries   = $queries_after - $queries_before;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'stress',
			'mcp_core',
			false,
			array(
				'query_count'       => $query_count,
				'avg_response_time' => $elapsed_time / $query_count,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'db_queries'        => $db_queries,
			),
			array(
				'total'  => $query_count,
				'passed' => $query_count,
				'failed' => 0,
			)
		);

		$this->assertEquals( $query_count, $db_queries, 'All database queries should execute.' );
		$this->assertLessThan( 1000, $elapsed_time, 'Database queries should complete in under 1 second.' );
	}

	/**
	 * Test Custom Post Type bulk operations.
	 *
	 * Tests performance of bulk CPT operations for ai_peer and mcp_ai_assistant.
	 */
	public function test_cpt_bulk_operations() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$post_count   = 50;
		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		$created_posts = array();

		// Create multiple posts.
		for ( $i = 0; $i < $post_count; $i++ ) {
			$post_id = $this->factory->post->create(
				array(
					'post_type'   => 'mcp_ai_assistant',
					'post_status' => 'publish',
					'post_title'  => 'Bulk Test Assistant ' . $i,
				)
			);

			if ( $post_id ) {
				$created_posts[] = $post_id;
				update_post_meta( $post_id, 'provider', 'openai' );
				update_post_meta( $post_id, 'model', 'gpt-3.5-turbo' );
			}
		}

		// Query posts.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'stress',
			'cpt_assistant',
			false,
			array(
				'bulk_operations'   => $post_count,
				'avg_response_time' => $elapsed_time / $post_count,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'total'  => $post_count,
				'passed' => count( $created_posts ),
				'failed' => $post_count - count( $created_posts ),
			)
		);

		$this->assertEquals( $post_count, count( $created_posts ), 'All posts should be created.' );
		$this->assertGreaterThanOrEqual( $post_count, $query->found_posts, 'All posts should be queryable.' );
	}

	/**
	 * Test tool execution concurrency.
	 *
	 * Tests concurrent tool execution performance.
	 */
	public function test_tool_execution_concurrency() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'Tool Registry class not available.' );
		}

		$tool_count   = 20;
		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		$successful_executions = 0;

		// Simulate concurrent tool executions.
		for ( $i = 0; $i < $tool_count; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$request->set_param( 'tool', 'list_posts' );
			$request->set_param( 'arguments', array( 'post_type' => 'post' ) );

			$response = rest_do_request( $request );

			if ( $response->get_status() === 200 ) {
				++$successful_executions;
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'stress',
			'mcp_core',
			false,
			array(
				'tool_executions'   => $tool_count,
				'avg_response_time' => $elapsed_time / $tool_count,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'total'  => $tool_count,
				'passed' => $successful_executions,
				'failed' => $tool_count - $successful_executions,
			)
		);

		$this->assertGreaterThan( 0, $successful_executions, 'At least some tools should execute successfully.' );
	}
}
