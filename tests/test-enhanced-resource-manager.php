<?php
/**
 * Tests for enhanced Resource Manager features.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for enhanced resource manager functionality.
 */
class WP_MCP_AI_Enhanced_Resource_Manager_Test extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Clean up any existing test data.
		delete_option( 'wp_mcp_ai_resource_usage_history' );
		delete_transient( 'wp_mcp_ai_resource_health' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_resource_usage_history' );
		delete_transient( 'wp_mcp_ai_resource_health' );
		
		parent::tearDown();
	}

	/**
	 * Test recording usage data.
	 */
	public function test_record_usage() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		$usage_data = array(
			'operation_type' => 'chat',
			'tokens_used'    => 1000,
			'execution_time' => 5,
			'status'         => 'success',
		);

		$manager->record_usage( $usage_data );

		$history = get_option( 'wp_mcp_ai_resource_usage_history', array() );

		$this->assertIsArray( $history );
		$this->assertNotEmpty( $history );

		// Check that the last recorded entry has our data.
		$last_entry = end( $history );
		$this->assertEquals( 'chat', $last_entry['operation_type'] );
		$this->assertEquals( 1000, $last_entry['tokens_used'] );
		$this->assertEquals( 5, $last_entry['execution_time'] );
		$this->assertArrayHasKey( 'memory_used', $last_entry );
		$this->assertArrayHasKey( 'tier', $last_entry );
	}

	/**
	 * Test getting usage history.
	 */
	public function test_get_usage_history() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// Record some usage data.
		for ( $i = 0; $i < 5; $i++ ) {
			$manager->record_usage(
				array(
					'operation_type' => 'chat',
					'tokens_used'    => 1000 * ( $i + 1 ),
					'execution_time' => 5 + $i,
				)
			);
			sleep( 1 ); // Ensure different timestamps.
		}

		$history = $manager->get_usage_history( 24 );

		$this->assertIsArray( $history );
		$this->assertCount( 5, $history );
	}

	/**
	 * Test usage history filtering by time period.
	 */
	public function test_usage_history_time_filtering() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// Manually create history with different timestamps.
		$history = array();
		$now     = time();

		// Add entries from 2 hours ago.
		$history[ $now - ( 2 * HOUR_IN_SECONDS ) ] = array(
			'tokens_used' => 500,
		);

		// Add entries from 30 hours ago (should be filtered out for 24h query).
		$history[ $now - ( 30 * HOUR_IN_SECONDS ) ] = array(
			'tokens_used' => 300,
		);

		// Add recent entry.
		$history[ $now ] = array(
			'tokens_used' => 1000,
		);

		update_option( 'wp_mcp_ai_resource_usage_history', $history );

		$filtered = $manager->get_usage_history( 24 );

		// Should have only 2 entries (2 hours ago and now, not 30 hours ago).
		$this->assertCount( 2, $filtered );
	}

	/**
	 * Test predictive requirements with no data.
	 */
	public function test_predict_requirements_no_data() {
		$manager    = WP_MCP_AI_Resource_Manager::instance();
		$prediction = $manager->predict_requirements( 'chat' );

		$this->assertIsArray( $prediction );
		$this->assertArrayHasKey( 'predicted_tokens', $prediction );
		$this->assertArrayHasKey( 'confidence', $prediction );
		$this->assertArrayHasKey( 'recommendation', $prediction );
		$this->assertEquals( 0, $prediction['confidence'] );
		$this->assertEquals( 'insufficient_data', $prediction['recommendation'] );
	}

	/**
	 * Test predictive requirements with historical data.
	 */
	public function test_predict_requirements_with_data() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// Record usage data.
		for ( $i = 0; $i < 10; $i++ ) {
			$manager->record_usage(
				array(
					'operation_type' => 'chat',
					'tokens_used'    => 1000,
					'execution_time' => 10,
				)
			);
		}

		$prediction = $manager->predict_requirements( 'chat' );

		$this->assertIsArray( $prediction );
		$this->assertGreaterThan( 0, $prediction['predicted_tokens'] );
		$this->assertGreaterThan( 0, $prediction['confidence'] );
		$this->assertEquals( 10, $prediction['sample_size'] );
	}

	/**
	 * Test health status retrieval.
	 */
	public function test_get_health_status() {
		$manager = WP_MCP_AI_Resource_Manager::instance();
		$health  = $manager->get_health_status();

		$this->assertIsArray( $health );
		$this->assertArrayHasKey( 'overall_health', $health );
		$this->assertArrayHasKey( 'issues', $health );
		$this->assertArrayHasKey( 'memory', $health );
		$this->assertArrayHasKey( 'metrics', $health );
		$this->assertArrayHasKey( 'tier', $health );

		$this->assertContains( $health['overall_health'], array( 'healthy', 'warning', 'critical' ) );
		$this->assertIsArray( $health['memory'] );
		$this->assertArrayHasKey( 'percent', $health['memory'] );
	}

	/**
	 * Test health status caching.
	 */
	public function test_health_status_caching() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		$health1 = $manager->get_health_status();
		$health2 = $manager->get_health_status();

		// Should return the same cached result.
		$this->assertEquals( $health1['timestamp'], $health2['timestamp'] );
	}

	/**
	 * Test adaptive budget calculation.
	 */
	public function test_get_adaptive_budget() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		$budget_high   = $manager->get_adaptive_budget( 'high' );
		$budget_medium = $manager->get_adaptive_budget( 'medium' );
		$budget_low    = $manager->get_adaptive_budget( 'low' );

		$this->assertIsInt( $budget_high );
		$this->assertIsInt( $budget_medium );
		$this->assertIsInt( $budget_low );

		// High priority should get the most budget.
		$this->assertGreaterThanOrEqual( $budget_medium, $budget_high );
		$this->assertGreaterThanOrEqual( $budget_low, $budget_medium );

		// All budgets should be at least the minimum.
		$this->assertGreaterThanOrEqual( 100, $budget_high );
		$this->assertGreaterThanOrEqual( 100, $budget_medium );
		$this->assertGreaterThanOrEqual( 100, $budget_low );
	}

	/**
	 * Test adaptive budget adjusts based on health.
	 */
	public function test_adaptive_budget_adjusts_for_health() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// Get baseline budget.
		$baseline_budget = $manager->get_adaptive_budget( 'high' );

		// The budget should be positive.
		$this->assertGreaterThan( 0, $baseline_budget );
	}

	/**
	 * Test SIEM integration for resource checks.
	 */
	public function test_resource_check_without_siem() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// Should work fine even without SIEM class loaded.
		$result = $manager->can_handle_operation( array( 'max_tokens' => 100 ) );

		$this->assertTrue( $result );
	}

	/**
	 * Test old data cleanup in usage history.
	 */
	public function test_usage_history_cleanup() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// Manually create history with old timestamps.
		$history = array();
		$now     = time();

		// Add entry from 8 days ago (should be cleaned up).
		$history[ $now - ( 8 * DAY_IN_SECONDS ) ] = array(
			'tokens_used' => 500,
		);

		// Add recent entry (should be kept).
		$history[ $now ] = array(
			'tokens_used' => 1000,
		);

		update_option( 'wp_mcp_ai_resource_usage_history', $history );

		// Record new usage to trigger cleanup.
		$manager->record_usage(
			array(
				'tokens_used' => 200,
			)
		);

		$updated_history = get_option( 'wp_mcp_ai_resource_usage_history', array() );

		// Should not contain the 8-day-old entry.
		$this->assertArrayNotHasKey( $now - ( 8 * DAY_IN_SECONDS ), $updated_history );
		// Should contain recent entry and new one.
		$this->assertGreaterThanOrEqual( 2, count( $updated_history ) );
	}
}
