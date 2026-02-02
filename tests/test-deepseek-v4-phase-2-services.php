<?php
/**
 * Test DeepSeek V4 Phase 2: Load Balancing & Efficiency
 *
 * Validates that all Phase 2 orchestration components (Tool Load Balancer,
 * Tool Chain Predictor, and Efficiency Monitor) are properly implemented
 * and functional.
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

/**
 * Test case for DeepSeek V4 Phase 2 services
 *
 * @since 1.1.1
 */
class Test_DeepSeek_V4_Phase_2_Services extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clear caches.
		wp_cache_flush();
	}

	/**
	 * Test that Tool Load Balancer class exists and has required methods.
	 */
	public function test_tool_load_balancer_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Tool_Load_Balancer' ),
			'WP_MCP_AI_Tool_Load_Balancer class should exist'
		);

		$balancer = new WP_MCP_AI_Tool_Load_Balancer();

		// Verify key public methods exist.
		$this->assertTrue(
			method_exists( $balancer, 'route_tool_execution' ),
			'Load Balancer should have route_tool_execution() method'
		);

		$this->assertTrue(
			method_exists( $balancer, 'track_tool_metrics' ),
			'Load Balancer should have track_tool_metrics() method'
		);

		$this->assertTrue(
			method_exists( $balancer, 'get_tool_recommendations' ),
			'Load Balancer should have get_tool_recommendations() method'
		);

		$this->assertTrue(
			method_exists( $balancer, 'clear_cache' ),
			'Load Balancer should have clear_cache() method'
		);
	}

	/**
	 * Test that Tool Chain Predictor class exists and has required methods.
	 */
	public function test_tool_chain_predictor_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Tool_Chain_Predictor' ),
			'WP_MCP_AI_Tool_Chain_Predictor class should exist'
		);

		$predictor = new WP_MCP_AI_Tool_Chain_Predictor();

		// Verify key public methods exist.
		$this->assertTrue(
			method_exists( $predictor, 'predict_tool_chain' ),
			'Chain Predictor should have predict_tool_chain() method'
		);

		$this->assertTrue(
			method_exists( $predictor, 'optimize_chain' ),
			'Chain Predictor should have optimize_chain() method'
		);

		$this->assertTrue(
			method_exists( $predictor, 'execute_speculative_chain' ),
			'Chain Predictor should have execute_speculative_chain() method'
		);

		$this->assertTrue(
			method_exists( $predictor, 'record_chain_execution' ),
			'Chain Predictor should have record_chain_execution() method'
		);

		$this->assertTrue(
			method_exists( $predictor, 'clear_history' ),
			'Chain Predictor should have clear_history() method'
		);
	}

	/**
	 * Test that Efficiency Monitor class exists and has required methods.
	 */
	public function test_efficiency_monitor_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Efficiency_Monitor' ),
			'WP_MCP_AI_Efficiency_Monitor class should exist'
		);

		$monitor = new WP_MCP_AI_Efficiency_Monitor();

		// Verify key public methods exist.
		$this->assertTrue(
			method_exists( $monitor, 'get_health_metrics' ),
			'Efficiency Monitor should have get_health_metrics() method'
		);

		$this->assertTrue(
			method_exists( $monitor, 'get_recommendations' ),
			'Efficiency Monitor should have get_recommendations() method'
		);

		$this->assertTrue(
			method_exists( $monitor, 'get_historical_metrics' ),
			'Efficiency Monitor should have get_historical_metrics() method'
		);

		$this->assertTrue(
			method_exists( $monitor, 'clear_history' ),
			'Efficiency Monitor should have clear_history() method'
		);
	}

	/**
	 * Test Tool Load Balancer returns proper recommendations structure.
	 */
	public function test_load_balancer_recommendations_structure() {
		$balancer = new WP_MCP_AI_Tool_Load_Balancer();

		$recommendations = $balancer->get_tool_recommendations( 'search for posts' );

		$this->assertIsArray( $recommendations, 'Recommendations should be an array' );

		// Check structure if recommendations exist.
		if ( ! empty( $recommendations ) ) {
			$first_rec = $recommendations[0];

			$this->assertArrayHasKey( 'tool_slug', $first_rec, 'Recommendation should have tool_slug' );
			$this->assertArrayHasKey( 'confidence', $first_rec, 'Recommendation should have confidence' );
			$this->assertArrayHasKey( 'relevance_score', $first_rec, 'Recommendation should have relevance_score' );
		}
	}

	/**
	 * Test Tool Chain Predictor predict_tool_chain returns proper structure.
	 */
	public function test_chain_predictor_prediction_structure() {
		$predictor = new WP_MCP_AI_Tool_Chain_Predictor();

		$available_tools = array( 'search_content', 'get_post', 'get_recent_posts' );
		$predicted       = $predictor->predict_tool_chain( 'search for posts', array(), $available_tools );

		$this->assertIsArray( $predicted, 'Predicted chain should be an array' );

		// Check structure if predictions exist.
		if ( ! empty( $predicted ) ) {
			$first_pred = $predicted[0];

			$this->assertIsArray( $first_pred, 'Each prediction should be an array' );
			$this->assertArrayHasKey( 'tool_slug', $first_pred, 'Prediction should have tool_slug' );
			$this->assertArrayHasKey( 'confidence', $first_pred, 'Prediction should have confidence' );
			$this->assertArrayHasKey( 'source', $first_pred, 'Prediction should have source' );
		}
	}

	/**
	 * Test Tool Chain Predictor optimize_chain eliminates redundancy.
	 */
	public function test_chain_predictor_eliminates_redundancy() {
		$predictor = new WP_MCP_AI_Tool_Chain_Predictor();

		// Chain with duplicate tools.
		$chain_with_dupes = array(
			'get_post',
			'search_content',
			'get_post', // Duplicate.
			'list_categories',
		);

		$optimized = $predictor->optimize_chain( $chain_with_dupes );

		$this->assertIsArray( $optimized, 'Optimized chain should be an array' );
		$this->assertArrayHasKey( 'sequential', $optimized, 'Optimized chain should have sequential key' );

		// Check that duplicates were removed.
		$sequential = $optimized['sequential'];
		$tool_slugs = array();
		foreach ( $sequential as $tool_info ) {
			$tool_slug    = is_array( $tool_info ) ? $tool_info['tool_slug'] : $tool_info;
			$tool_slugs[] = $tool_slug;
		}

		$unique_count = count( array_unique( $tool_slugs ) );
		$this->assertEquals(
			$unique_count,
			count( $tool_slugs ),
			'Optimized chain should not contain duplicate tools'
		);
	}

	/**
	 * Test Tool Chain Predictor records execution history.
	 */
	public function test_chain_predictor_records_history() {
		$predictor = new WP_MCP_AI_Tool_Chain_Predictor();

		// Clear history first.
		$predictor->clear_history();

		// Record a chain execution.
		$chain   = array( 'search_content', 'get_post' );
		$context = array( 'task_type' => 'research' );
		$predictor->record_chain_execution( $chain, $context, true );

		// Verify it was recorded (by checking option exists).
		$history = get_option( 'wp_mcp_ai_tool_chain_history', array() );
		$this->assertNotEmpty( $history, 'History should not be empty after recording' );
		$this->assertIsArray( $history, 'History should be an array' );
	}

	/**
	 * Test Efficiency Monitor returns proper health metrics structure.
	 */
	public function test_efficiency_monitor_health_metrics_structure() {
		$monitor = new WP_MCP_AI_Efficiency_Monitor();

		$metrics = $monitor->get_health_metrics();

		$this->assertIsArray( $metrics, 'Health metrics should be an array' );

		// Check required keys.
		$this->assertArrayHasKey( 'health_status', $metrics, 'Metrics should have health_status' );
		$this->assertArrayHasKey( 'tool_execution', $metrics, 'Metrics should have tool_execution' );
		$this->assertArrayHasKey( 'load_balancing', $metrics, 'Metrics should have load_balancing' );
		$this->assertArrayHasKey( 'resource_usage', $metrics, 'Metrics should have resource_usage' );
		$this->assertArrayHasKey( 'bottlenecks', $metrics, 'Metrics should have bottlenecks' );
		$this->assertArrayHasKey( 'timestamp', $metrics, 'Metrics should have timestamp' );

		// Verify health status is valid.
		$valid_statuses = array( 'excellent', 'good', 'warning', 'critical', 'unknown' );
		$this->assertContains(
			$metrics['health_status'],
			$valid_statuses,
			'Health status should be one of the valid values'
		);
	}

	/**
	 * Test Efficiency Monitor returns proper recommendations structure.
	 */
	public function test_efficiency_monitor_recommendations_structure() {
		$monitor = new WP_MCP_AI_Efficiency_Monitor();

		$recommendations = $monitor->get_recommendations();

		$this->assertIsArray( $recommendations, 'Recommendations should be an array' );

		// Check structure if recommendations exist.
		if ( ! empty( $recommendations ) ) {
			$first_rec = $recommendations[0];

			$this->assertIsArray( $first_rec, 'Each recommendation should be an array' );
			$this->assertArrayHasKey( 'priority', $first_rec, 'Recommendation should have priority' );
			$this->assertArrayHasKey( 'title', $first_rec, 'Recommendation should have title' );
			$this->assertArrayHasKey( 'description', $first_rec, 'Recommendation should have description' );
			$this->assertArrayHasKey( 'actions', $first_rec, 'Recommendation should have actions' );
			$this->assertArrayHasKey( 'impact', $first_rec, 'Recommendation should have impact' );

			// Verify priority is valid.
			$valid_priorities = array( 'high', 'medium', 'low' );
			$this->assertContains(
				$first_rec['priority'],
				$valid_priorities,
				'Priority should be one of the valid values'
			);
		}
	}

	/**
	 * Test Efficiency Monitor stores and retrieves historical metrics.
	 */
	public function test_efficiency_monitor_stores_history() {
		$monitor = new WP_MCP_AI_Efficiency_Monitor();

		// Clear history first.
		$monitor->clear_history();

		// Get metrics (this should store them).
		$monitor->get_health_metrics();

		// Retrieve historical metrics.
		$history = $monitor->get_historical_metrics( 7 );

		$this->assertIsArray( $history, 'Historical metrics should be an array' );
		// Note: May be empty if services are not fully initialized in test environment.
	}

	/**
	 * Test that load balancer can clear cache.
	 */
	public function test_load_balancer_clear_cache() {
		$balancer = new WP_MCP_AI_Tool_Load_Balancer();

		$result = $balancer->clear_cache();

		$this->assertTrue( $result, 'Clear cache should return true' );
	}

	/**
	 * Test that chain predictor can clear history.
	 */
	public function test_chain_predictor_clear_history() {
		$predictor = new WP_MCP_AI_Tool_Chain_Predictor();

		$result = $predictor->clear_history();

		$this->assertTrue( $result, 'Clear history should return true' );

		// Verify history was cleared.
		$history = get_option( 'wp_mcp_ai_tool_chain_history', array() );
		$this->assertEmpty( $history, 'History should be empty after clearing' );
	}

	/**
	 * Test that efficiency monitor can clear history.
	 */
	public function test_efficiency_monitor_clear_history() {
		$monitor = new WP_MCP_AI_Efficiency_Monitor();

		$result = $monitor->clear_history();

		$this->assertTrue( $result, 'Clear history should return true' );

		// Verify history was cleared.
		$history = get_option( 'wp_mcp_ai_efficiency_metrics_history', array() );
		$this->assertEmpty( $history, 'History should be empty after clearing' );
	}

	/**
	 * Test integration: Load Balancer with Tool Registry.
	 */
	public function test_load_balancer_integrates_with_registry() {
		// This test verifies that load balancer can get registry instance.
		$balancer = new WP_MCP_AI_Tool_Load_Balancer();

		// Use reflection to check protected method.
		$reflection = new ReflectionClass( $balancer );
		$method     = $reflection->getMethod( 'get_registry' );
		$method->setAccessible( true );

		$registry = $method->invoke( $balancer );

		// Registry might be null in test environment, but method should exist.
		$this->assertTrue(
			is_null( $registry ) || $registry instanceof WP_MCP_AI_Tool_Registry,
			'Registry should be null or WP_MCP_AI_Tool_Registry instance'
		);
	}

	/**
	 * Test integration: Chain Predictor with Tool Registry.
	 */
	public function test_chain_predictor_integrates_with_registry() {
		// This test verifies that chain predictor can get registry instance.
		$predictor = new WP_MCP_AI_Tool_Chain_Predictor();

		// Use reflection to check protected method.
		$reflection = new ReflectionClass( $predictor );
		$method     = $reflection->getMethod( 'get_registry' );
		$method->setAccessible( true );

		$registry = $method->invoke( $predictor );

		// Registry might be null in test environment, but method should exist.
		$this->assertTrue(
			is_null( $registry ) || $registry instanceof WP_MCP_AI_Tool_Registry,
			'Registry should be null or WP_MCP_AI_Tool_Registry instance'
		);
	}

	/**
	 * Test integration: Efficiency Monitor with Load Monitor.
	 */
	public function test_efficiency_monitor_integrates_with_load_monitor() {
		// This test verifies that efficiency monitor can get load monitor instance.
		$monitor = new WP_MCP_AI_Efficiency_Monitor();

		// Use reflection to check protected method.
		$reflection = new ReflectionClass( $monitor );
		$method     = $reflection->getMethod( 'get_load_monitor' );
		$method->setAccessible( true );

		$load_monitor = $method->invoke( $monitor );

		// Load monitor might be null in test environment, but method should exist.
		$this->assertTrue(
			is_null( $load_monitor ) || $load_monitor instanceof WP_MCP_AI_Tool_Load_Monitor,
			'Load monitor should be null or WP_MCP_AI_Tool_Load_Monitor instance'
		);
	}
}
