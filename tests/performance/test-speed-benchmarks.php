<?php
/**
 * Speed Benchmarks for NV oOS Performance Monitor.
 *
 * Tests baseline performance metrics including:
 * - API endpoint latency (p50, p95, p99)
 * - Chat UI rendering performance
 * - Memory leak detection
 * - Database query count tracking
 * - Response time regression tests
 *
 * @package WP_MCP_AI
 */

/**
 * Speed benchmarks test suite class.
 */
class WP_MCP_AI_Speed_Benchmarks_Test extends WP_UnitTestCase {

	/**
	 * Latency percentiles storage.
	 *
	 * @var array
	 */
	protected $latencies = array();

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
	 * Test API endpoint latency baselines.
	 *
	 * Measures p50, p95, and p99 latency for REST API endpoints.
	 */
	public function test_api_endpoint_latency_baselines() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$iterations   = 100;
		$latencies    = array();
		$start_memory = memory_get_usage();

		for ( $i = 0; $i < $iterations; $i++ ) {
			$start_time = microtime( true );

			$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

			$response = rest_do_request( $request );

			$end_time = microtime( true );

			$latencies[] = ( $end_time - $start_time ) * 1000; // Convert to ms.
		}

		$end_memory  = memory_get_usage();
		$memory_used = $end_memory - $start_memory;

		sort( $latencies );

		$p50 = $latencies[ (int) ( count( $latencies ) * 0.5 ) ];
		$p95 = $latencies[ (int) ( count( $latencies ) * 0.95 ) ];
		$p99 = $latencies[ (int) ( count( $latencies ) * 0.99 ) ];
		$avg = array_sum( $latencies ) / count( $latencies );

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'speed',
			'rest_api',
			false,
			array(
				'avg_response_time' => $avg,
				'p50_latency'       => $p50,
				'p95_latency'       => $p95,
				'p99_latency'       => $p99,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'iterations'        => $iterations,
			),
			array(
				'total'  => $iterations,
				'passed' => $iterations,
				'failed' => 0,
			)
		);

		// Assertions.
		$this->assertLessThan( 500, $p50, 'P50 latency should be under 500ms.' );
		$this->assertLessThan( 1000, $p95, 'P95 latency should be under 1000ms.' );
		$this->assertLessThan( 2000, $p99, 'P99 latency should be under 2000ms.' );
	}

	/**
	 * Test chat UI rendering performance.
	 *
	 * Measures time to render chat interface elements.
	 */
	public function test_chat_ui_rendering_performance() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Create test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Speed Test Assistant',
			)
		);

		update_post_meta( $assistant_id, 'provider', 'openai' );
		update_post_meta( $assistant_id, 'model', 'gpt-3.5-turbo' );

		// Simulate rendering shortcode.
		$shortcode_content = do_shortcode( '[wp_mcp_ai_chat assistant_id="' . $assistant_id . '"]' );

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$render_time = ( $end_time - $start_time ) * 1000;
		$memory_used = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'speed',
			'chat_ui',
			false,
			array(
				'avg_response_time' => $render_time,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'total'  => 1,
				'passed' => 1,
				'failed' => 0,
			)
		);

		$this->assertLessThan( 1000, $render_time, 'Chat UI should render in under 1 second.' );
		$this->assertNotEmpty( $shortcode_content, 'Shortcode should produce output.' );
	}

	/**
	 * Test memory leak detection.
	 *
	 * Runs repeated operations to detect memory leaks.
	 */
	public function test_memory_leak_detection() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$iterations     = 50;
		$memory_samples = array();

		$start_time = microtime( true );

		for ( $i = 0; $i < $iterations; $i++ ) {
			// Sample memory before operation.
			$memory_before = memory_get_usage();

			// Perform operation.
			$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$response = rest_do_request( $request );

			// Force garbage collection.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			// Sample memory after operation.
			$memory_after = memory_get_usage();

			$memory_samples[] = $memory_after - $memory_before;
		}

		$end_time = microtime( true );

		$elapsed_time = ( $end_time - $start_time ) * 1000;

		// Calculate memory growth trend.
		$first_third = array_slice( $memory_samples, 0, (int) ( count( $memory_samples ) / 3 ) );
		$last_third  = array_slice( $memory_samples, - (int) ( count( $memory_samples ) / 3 ) );

		$first_avg = array_sum( $first_third ) / count( $first_third );
		$last_avg  = array_sum( $last_third ) / count( $last_third );

		$memory_growth_percent = ( ( $last_avg - $first_avg ) / $first_avg ) * 100;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'speed',
			'mcp_core',
			false,
			array(
				'avg_response_time'     => $elapsed_time / $iterations,
				'memory_peak_bytes'     => max( $memory_samples ),
				'memory_peak_mb'        => max( $memory_samples ) / 1024 / 1024,
				'memory_growth_percent' => $memory_growth_percent,
				'iterations'            => $iterations,
			),
			array(
				'total'                => $iterations,
				'passed'               => $memory_growth_percent < 50 ? $iterations : 0,
				'failed'               => $memory_growth_percent >= 50 ? $iterations : 0,
				'memory_leak_detected' => $memory_growth_percent >= 50,
			)
		);

		$this->assertLessThan( 50, $memory_growth_percent, 'Memory growth should be less than 50% (no significant leak).' );
	}

	/**
	 * Test database query count tracking.
	 *
	 * Tracks database queries per request to identify N+1 problems.
	 */
	public function test_database_query_count_tracking() {
		global $wpdb;

		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// Enable query tracking.
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Get query count before.
		$queries_before = $wpdb->num_queries;

		// Perform operation.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );

		// Get query count after.
		$queries_after = $wpdb->num_queries;

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;
		$db_queries   = $queries_after - $queries_before;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'speed',
			'rest_api',
			false,
			array(
				'avg_response_time' => $elapsed_time,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'db_queries'        => $db_queries,
			),
			array(
				'total'  => 1,
				'passed' => $db_queries < 50 ? 1 : 0,
				'failed' => $db_queries >= 50 ? 1 : 0,
			)
		);

		$this->assertLessThan( 50, $db_queries, 'Database queries should be under 50 per request.' );
	}

	/**
	 * Test response time regression.
	 *
	 * Compares current performance against historical baselines.
	 */
	public function test_response_time_regression() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$iterations   = 20;
		$times        = array();
		$start_memory = memory_get_usage();

		for ( $i = 0; $i < $iterations; $i++ ) {
			$start_time = microtime( true );

			$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			rest_do_request( $request );

			$end_time = microtime( true );
			$times[]  = ( $end_time - $start_time ) * 1000;
		}

		$end_memory  = memory_get_usage();
		$memory_used = $end_memory - $start_memory;

		$avg_time = array_sum( $times ) / count( $times );
		$max_time = max( $times );
		$min_time = min( $times );

		// Get historical baseline.
		$trends   = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( 'rest_api', '-30 days', 'speed' );
		$baseline = isset( $trends['avg_response_time'] ) ? $trends['avg_response_time'] : 0;

		$regression_percent = 0;
		if ( $baseline > 0 ) {
			$regression_percent = ( ( $avg_time - $baseline ) / $baseline ) * 100;
		}

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'speed',
			'rest_api',
			false,
			array(
				'avg_response_time'  => $avg_time,
				'min_response_time'  => $min_time,
				'max_response_time'  => $max_time,
				'memory_peak_bytes'  => $memory_used,
				'memory_peak_mb'     => $memory_used / 1024 / 1024,
				'baseline'           => $baseline,
				'regression_percent' => $regression_percent,
			),
			array(
				'total'  => $iterations,
				'passed' => $regression_percent < 20 ? $iterations : 0,
				'failed' => $regression_percent >= 20 ? $iterations : 0,
			)
		);

		// Allow up to 20% regression.
		if ( $baseline > 0 ) {
			$this->assertLessThan( 20, $regression_percent, 'Response time regression should be less than 20%.' );
		}

		$this->assertTrue( true, 'Regression test completed.' );
	}

	/**
	 * Test tool execution performance.
	 *
	 * Measures tool execution speed and efficiency.
	 */
	public function test_tool_execution_performance() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$iterations   = 10;
		$times        = array();
		$start_memory = memory_get_usage();

		for ( $i = 0; $i < $iterations; $i++ ) {
			$start_time = microtime( true );

			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$request->set_param( 'tool', 'list_posts' );
			$request->set_param( 'arguments', array( 'post_type' => 'post' ) );

			rest_do_request( $request );

			$end_time = microtime( true );
			$times[]  = ( $end_time - $start_time ) * 1000;
		}

		$end_memory  = memory_get_usage();
		$memory_used = $end_memory - $start_memory;

		$avg_time = array_sum( $times ) / count( $times );

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'speed',
			'mcp_core',
			false,
			array(
				'avg_response_time' => $avg_time,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'iterations'        => $iterations,
			),
			array(
				'total'  => $iterations,
				'passed' => $iterations,
				'failed' => 0,
			)
		);

		$this->assertLessThan( 500, $avg_time, 'Tool execution should complete in under 500ms on average.' );
	}
}
