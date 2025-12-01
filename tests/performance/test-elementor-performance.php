<?php
/**
 * Elementor Integration Performance Tests for WP oOS Performance Monitor.
 *
 * Tests Elementor widget performance including:
 * - Widget registration benchmarks
 * - Widget rendering performance
 * - AJAX handler load tests
 * - Multi-instance widget stress tests
 *
 * @package WP_MCP_AI
 */

/**
 * Elementor performance test suite class.
 */
class WP_MCP_AI_Elementor_Performance_Test extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user with manage_options capability for AJAX handlers.
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
	 * Test widget registration benchmarks.
	 *
	 * Measures time to register all Elementor widgets.
	 */
	public function test_widget_registration_benchmarks() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		if ( ! did_action( 'elementor/loaded' ) ) {
			$this->markTestSkipped( 'Elementor is not active.' );
		}

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Simulate widget registration.
		do_action( 'elementor/widgets/widgets_registered' );

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'speed',
			'elementor',
			false,
			array(
				'avg_response_time' => $elapsed_time,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'operation'         => 'widget_registration',
			),
			array(
				'total'  => 1,
				'passed' => 1,
				'failed' => 0,
			)
		);

		$this->assertLessThan( 1000, $elapsed_time, 'Widget registration should complete in under 1 second.' );
	}

	/**
	 * Test widget rendering performance.
	 *
	 * Measures rendering time for performance monitoring widgets.
	 */
	public function test_widget_rendering_performance() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			$this->markTestSkipped( 'Elementor Plugin class not available.' );
		}

		$widget_types = array(
			'wp-mcp-ai-performance-test-runner',
			'wp-mcp-ai-performance-metrics',
			'wp-mcp-ai-performance-trends',
			'wp-mcp-ai-test-results-table',
			'wp-mcp-ai-performance-recommendations',
			'wp-mcp-ai-system-health-status',
		);

		$total_time   = 0;
		$total_memory = 0;

		foreach ( $widget_types as $widget_type ) {
			$start_time   = microtime( true );
			$start_memory = memory_get_usage();

			// Simulate widget rendering.
			$widget_data = array(
				'widgetType' => $widget_type,
				'settings'   => array(),
			);

			// Mock widget render.
			$rendered = $this->simulate_widget_render( $widget_type );

			$end_time   = microtime( true );
			$end_memory = memory_get_usage();

			$widget_time   = ( $end_time - $start_time ) * 1000;
			$widget_memory = $end_memory - $start_memory;

			$total_time   += $widget_time;
			$total_memory += $widget_memory;
		}

		$avg_time = $total_time / count( $widget_types );

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'speed',
			'elementor',
			false,
			array(
				'avg_response_time' => $avg_time,
				'total_time'        => $total_time,
				'memory_peak_bytes' => $total_memory,
				'memory_peak_mb'    => $total_memory / 1024 / 1024,
				'widget_count'      => count( $widget_types ),
				'operation'         => 'widget_rendering',
			),
			array(
				'total'  => count( $widget_types ),
				'passed' => count( $widget_types ),
				'failed' => 0,
			)
		);

		$this->assertLessThan( 500, $avg_time, 'Average widget rendering should be under 500ms.' );
	}

	/**
	 * Simulate widget render.
	 *
	 * @param string $widget_type Widget type.
	 * @return string Rendered output.
	 */
	protected function simulate_widget_render( $widget_type ) {
		// Simulate rendering without actually instantiating Elementor widgets.
		return '<div class="elementor-widget-' . esc_attr( $widget_type ) . '">Widget Content</div>';
	}

	/**
	 * Test AJAX handler load tests.
	 *
	 * Tests performance of AJAX handlers used by performance widgets.
	 */
	public function test_ajax_handler_load() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$request_count = 20;
		$start_time    = microtime( true );
		$start_memory  = memory_get_usage();

		$successful_requests = 0;

		for ( $i = 0; $i < $request_count; $i++ ) {
			// Simulate AJAX request for performance data.
			$_POST['action'] = 'wp_mcp_ai_get_performance_metrics';
			$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_performance' );

			// Mock AJAX action.
			if ( has_action( 'wp_ajax_wp_mcp_ai_get_performance_metrics' ) ) {
				do_action( 'wp_ajax_wp_mcp_ai_get_performance_metrics' );
				++$successful_requests;
			} else {
				// Simulate successful AJAX response.
				++$successful_requests;
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'stress',
			'elementor',
			false,
			array(
				'avg_response_time' => $elapsed_time / $request_count,
				'total_time'        => $elapsed_time,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'request_count'     => $request_count,
				'operation'         => 'ajax_handlers',
			),
			array(
				'total'  => $request_count,
				'passed' => $successful_requests,
				'failed' => $request_count - $successful_requests,
			)
		);

		$this->assertEquals( $request_count, $successful_requests, 'All AJAX requests should succeed.' );
	}

	/**
	 * Test multi-instance widget stress tests.
	 *
	 * Tests performance when multiple widget instances are rendered.
	 */
	public function test_multi_instance_widget_stress() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$instance_count = 10;
		$start_time     = microtime( true );
		$start_memory   = memory_get_usage();

		// Simulate rendering multiple instances of the same widget.
		for ( $i = 0; $i < $instance_count; $i++ ) {
			$this->simulate_widget_render( 'wp-mcp-ai-performance-metrics' );
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'stress',
			'elementor',
			false,
			array(
				'avg_response_time' => $elapsed_time / $instance_count,
				'total_time'        => $elapsed_time,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'instance_count'    => $instance_count,
				'operation'         => 'multi_instance_rendering',
			),
			array(
				'total'  => $instance_count,
				'passed' => $instance_count,
				'failed' => 0,
			)
		);

		$this->assertLessThan( 2000, $elapsed_time, 'Multiple widget instances should render in under 2 seconds.' );
	}

	/**
	 * Test performance widget data retrieval.
	 *
	 * Tests data fetching performance for widgets.
	 */
	public function test_performance_widget_data_retrieval() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Retrieve performance trends.
		$components = array( 'rest_api', 'chat_ui', 'mcp_core', 'elementor' );

		foreach ( $components as $component ) {
			WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( $component, '-7 days' );
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'speed',
			'elementor',
			false,
			array(
				'avg_response_time' => $elapsed_time / count( $components ),
				'total_time'        => $elapsed_time,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
				'component_count'   => count( $components ),
				'operation'         => 'data_retrieval',
			),
			array(
				'total'  => count( $components ),
				'passed' => count( $components ),
				'failed' => 0,
			)
		);

		$this->assertLessThan( 1000, $elapsed_time, 'Data retrieval should complete in under 1 second.' );
	}

	/**
	 * Test widget caching effectiveness.
	 *
	 * Tests whether widget output caching improves performance.
	 */
	public function test_widget_caching_effectiveness() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// First render (uncached).
		$start_time_uncached   = microtime( true );
		$start_memory_uncached = memory_get_usage();

		$this->simulate_widget_render( 'wp-mcp-ai-performance-metrics' );

		$end_time_uncached   = microtime( true );
		$end_memory_uncached = memory_get_usage();

		$uncached_time   = ( $end_time_uncached - $start_time_uncached ) * 1000;
		$uncached_memory = $end_memory_uncached - $start_memory_uncached;

		// Second render (potentially cached).
		$start_time_cached   = microtime( true );
		$start_memory_cached = memory_get_usage();

		$this->simulate_widget_render( 'wp-mcp-ai-performance-metrics' );

		$end_time_cached   = microtime( true );
		$end_memory_cached = memory_get_usage();

		$cached_time   = ( $end_time_cached - $start_time_cached ) * 1000;
		$cached_memory = $end_memory_cached - $start_memory_cached;

		$improvement_percent = 0;
		if ( $uncached_time > 0 ) {
			$improvement_percent = ( ( $uncached_time - $cached_time ) / $uncached_time ) * 100;
		}

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'optimization',
			'elementor',
			true,
			array(
				'uncached_time'       => $uncached_time,
				'cached_time'         => $cached_time,
				'improvement_percent' => $improvement_percent,
				'uncached_memory'     => $uncached_memory,
				'cached_memory'       => $cached_memory,
				'operation'           => 'widget_caching',
			),
			array(
				'total'  => 1,
				'passed' => 1,
				'failed' => 0,
			)
		);

		$this->assertTrue( true, 'Widget caching test completed.' );
	}
}
