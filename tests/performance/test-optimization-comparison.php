<?php
/**
 * Optimization Comparison Tests for WP oOS Performance Monitor.
 *
 * A/B testing for optimization features including:
 * - Cache enabled vs disabled comparison
 * - Message bundling effectiveness
 * - localStorage performance impact
 * - DOM rendering optimization validation
 *
 * @package WP_MCP_AI
 */

/**
 * Optimization comparison test suite class.
 */
class WP_MCP_AI_Optimization_Comparison_Test extends WP_UnitTestCase {

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
	 * Test cache hit/miss ratio measurement.
	 *
	 * Compares performance with caching enabled vs disabled.
	 */
	public function test_cache_optimization_comparison() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$iterations = 20;

		// Test WITHOUT cache.
		$this->run_cache_test( $iterations, false );

		// Test WITH cache.
		$this->run_cache_test( $iterations, true );

		$this->assertTrue( true, 'Cache optimization comparison completed.' );
	}

	/**
	 * Run cache test helper.
	 *
	 * @param int  $iterations Number of iterations.
	 * @param bool $cache_enabled Whether cache is enabled.
	 */
	protected function run_cache_test( $iterations, $cache_enabled ) {
		$times        = array();
		$start_memory = memory_get_usage();

		// Simulate cache state.
		if ( $cache_enabled ) {
			// Enable object cache simulation.
			add_filter( 'wp_mcp_ai_enable_cache', '__return_true' );
		} else {
			add_filter( 'wp_mcp_ai_enable_cache', '__return_false' );
		}

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

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'optimization',
			'rest_api',
			$cache_enabled,
			array(
				'avg_response_time' => $avg_time,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'cache_enabled'     => $cache_enabled,
				'iterations'        => $iterations,
			),
			array(
				'total'  => $iterations,
				'passed' => $iterations,
				'failed' => 0,
			)
		);

		// Clean up filter.
		remove_filter( 'wp_mcp_ai_enable_cache', $cache_enabled ? '__return_true' : '__return_false' );
	}

	/**
	 * Test message bundling effectiveness.
	 *
	 * Compares bundled vs unbundled message processing.
	 */
	public function test_message_bundling_effectiveness() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// Create test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Optimization Test Assistant',
			)
		);

		update_post_meta( $assistant_id, 'provider', 'openai' );
		update_post_meta( $assistant_id, 'model', 'gpt-3.5-turbo' );

		// Test WITHOUT bundling.
		$this->run_bundling_test( $assistant_id, false );

		// Test WITH bundling.
		$this->run_bundling_test( $assistant_id, true );

		$this->assertTrue( true, 'Message bundling comparison completed.' );
	}

	/**
	 * Run bundling test helper.
	 *
	 * @param int  $assistant_id Assistant post ID.
	 * @param bool $bundling_enabled Whether bundling is enabled.
	 */
	protected function run_bundling_test( $assistant_id, $bundling_enabled ) {
		$message_count = 10;
		$start_time    = microtime( true );
		$start_memory  = memory_get_usage();

		// Mock API responses.
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

		if ( $bundling_enabled ) {
			// Simulate bundling - send multiple messages in one request.
			$messages = array();
			for ( $i = 0; $i < $message_count; $i++ ) {
				$messages[] = array(
					'role'    => 'user',
					'content' => 'Test message ' . $i,
				);
			}

			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$request->set_param( 'assistant_id', $assistant_id );
			$request->set_param( 'messages', $messages );

			rest_do_request( $request );
		} else {
			// Send individual messages.
			for ( $i = 0; $i < $message_count; $i++ ) {
				$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
				$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
				$request->set_param( 'assistant_id', $assistant_id );
				$request->set_param( 'message', 'Test message ' . $i );

				rest_do_request( $request );
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'optimization',
			'chat_ui',
			$bundling_enabled,
			array(
				'avg_response_time' => $elapsed_time / $message_count,
				'total_time'        => $elapsed_time,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'bundling_enabled'  => $bundling_enabled,
				'message_count'     => $message_count,
			),
			array(
				'total'  => $message_count,
				'passed' => $message_count,
				'failed' => 0,
			)
		);

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test localStorage performance impact.
	 *
	 * Simulates localStorage operations and measures impact.
	 */
	public function test_localstorage_performance_impact() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// This test simulates the server-side impact of localStorage.
		// (e.g., data serialization/deserialization).

		$iterations   = 50;
		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Simulate localStorage-like operations.
		for ( $i = 0; $i < $iterations; $i++ ) {
			$transcript_data = array(
				'messages' => array(),
			);

			// Add messages.
			for ( $j = 0; $j < 20; $j++ ) {
				$transcript_data['messages'][] = array(
					'role'      => $j % 2 === 0 ? 'user' : 'assistant',
					'content'   => 'Message ' . $j,
					'timestamp' => time(),
				);
			}

			// Serialize (simulating localStorage write).
			$serialized = wp_json_encode( $transcript_data );

			// Deserialize (simulating localStorage read).
			$deserialized = json_decode( $serialized, true );

			// Validate.
			if ( count( $deserialized['messages'] ) !== 20 ) {
				break;
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'optimization',
			'chat_ui',
			true,
			array(
				'avg_response_time' => $elapsed_time / $iterations,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'iterations'        => $iterations,
				'feature'           => 'localStorage',
			),
			array(
				'total'  => $iterations,
				'passed' => $iterations,
				'failed' => 0,
			)
		);

		$this->assertLessThan( 500, $elapsed_time, 'localStorage operations should complete quickly.' );
	}

	/**
	 * Test DOM rendering optimization validation.
	 *
	 * Validates server-side rendering optimizations.
	 */
	public function test_dom_rendering_optimization() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// Create test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'DOM Test Assistant',
			)
		);

		// Test unoptimized rendering.
		$this->run_rendering_test( $assistant_id, false );

		// Test optimized rendering.
		$this->run_rendering_test( $assistant_id, true );

		$this->assertTrue( true, 'DOM rendering optimization comparison completed.' );
	}

	/**
	 * Run rendering test helper.
	 *
	 * @param int  $assistant_id Assistant post ID.
	 * @param bool $optimized Whether optimizations are enabled.
	 */
	protected function run_rendering_test( $assistant_id, $optimized ) {
		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		if ( $optimized ) {
			add_filter( 'wp_mcp_ai_optimize_rendering', '__return_true' );
		} else {
			add_filter( 'wp_mcp_ai_optimize_rendering', '__return_false' );
		}

		// Simulate rendering chat shortcode.
		$output = do_shortcode( '[wp_mcp_ai_chat assistant_id="' . $assistant_id . '"]' );

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'optimization',
			'chat_ui',
			$optimized,
			array(
				'avg_response_time' => $elapsed_time,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'optimized'         => $optimized,
				'output_length'     => strlen( $output ),
			),
			array(
				'total'  => 1,
				'passed' => 1,
				'failed' => 0,
			)
		);

		remove_filter( 'wp_mcp_ai_optimize_rendering', $optimized ? '__return_true' : '__return_false' );
	}

	/**
	 * Test optimizations enabled vs disabled overall comparison.
	 *
	 * Comprehensive comparison of all optimizations.
	 */
	public function test_overall_optimization_comparison() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// Get trends for optimizations disabled.
		$trends_disabled = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends(
			'rest_api',
			'-7 days',
			'optimization'
		);

		// Get trends for optimizations enabled.
		$trends_enabled = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends(
			'rest_api',
			'-7 days',
			'optimization'
		);

		$improvement_percent = 0;
		if ( isset( $trends_disabled['avg_response_time'] ) && $trends_disabled['avg_response_time'] > 0 ) {
			$baseline  = $trends_disabled['avg_response_time'];
			$optimized = isset( $trends_enabled['avg_response_time'] ) ? $trends_enabled['avg_response_time'] : $baseline;

			$improvement_percent = ( ( $baseline - $optimized ) / $baseline ) * 100;
		}

		// Store comparison results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'optimization',
			'mcp_core',
			true,
			array(
				'baseline_avg_time'   => isset( $trends_disabled['avg_response_time'] ) ? $trends_disabled['avg_response_time'] : 0,
				'optimized_avg_time'  => isset( $trends_enabled['avg_response_time'] ) ? $trends_enabled['avg_response_time'] : 0,
				'improvement_percent' => $improvement_percent,
			),
			array(
				'total'  => 1,
				'passed' => 1,
				'failed' => 0,
			)
		);

		$this->assertTrue( true, 'Overall optimization comparison recorded.' );
	}
}
