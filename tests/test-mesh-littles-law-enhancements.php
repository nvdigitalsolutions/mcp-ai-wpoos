<?php
/**
 * Tests for Mesh Router Little's Law enhancements.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Mesh Router Little's Law functionality.
 */
class Test_Mesh_Littles_Law_Enhancements extends WP_UnitTestCase {
	/**
	 * Test predicted wait time calculation.
	 */
	public function test_get_predicted_wait_time() {
		$health = array(
			'current_load'      => 10,
			'avg_response_time' => 5.0,
			'total_requests'    => 100,
		);

		$service_time = 2.0;
		$wait_time    = WP_MCP_AI_Mesh_Router::get_predicted_wait_time( $health, $service_time );

		// Wait time should be non-negative.
		$this->assertGreaterThanOrEqual( 0, $wait_time );
		$this->assertIsFloat( $wait_time );
	}

	/**
	 * Test mesh capacity metrics calculation.
	 */
	public function test_get_mesh_capacity_metrics() {
		// Create mock peer sites in settings.
		$settings = array(
			'mesh_peer_sites' => array(
				array(
					'name'    => 'peer1',
					'url'     => 'https://peer1.example.com',
					'api_key' => 'key1',
				),
				array(
					'name'    => 'peer2',
					'url'     => 'https://peer2.example.com',
					'api_key' => 'key2',
				),
			),
		);

		update_option( 'wp_mcp_ai_settings', $settings );

		// Create mock health metrics.
		$health_metrics = array(
			'peer1' => array(
				'status'            => 'healthy',
				'current_load'      => 5,
				'avg_response_time' => 2.0,
				'total_requests'    => 50,
				'success_rate'      => 98,
				'last_update'       => time(),
			),
			'peer2' => array(
				'status'            => 'healthy',
				'current_load'      => 8,
				'avg_response_time' => 3.0,
				'total_requests'    => 80,
				'success_rate'      => 95,
				'last_update'       => time(),
			),
		);

		update_option( 'wp_mcp_ai_mesh_health_metrics', $health_metrics );

		$metrics = WP_MCP_AI_Mesh_Router::get_mesh_capacity_metrics();

		// Verify metrics structure.
		$this->assertIsArray( $metrics );
		$this->assertArrayHasKey( 'total_peers', $metrics );
		$this->assertArrayHasKey( 'healthy_peers', $metrics );
		$this->assertArrayHasKey( 'avg_capacity_score', $metrics );
		$this->assertArrayHasKey( 'avg_utilization', $metrics );
		$this->assertArrayHasKey( 'mesh_health', $metrics );
		$this->assertArrayHasKey( 'recommended_action', $metrics );

		// Verify values.
		$this->assertEquals( 2, $metrics['total_peers'] );
		$this->assertEquals( 2, $metrics['healthy_peers'] );
		$this->assertIsFloat( $metrics['avg_capacity_score'] );
		$this->assertIsFloat( $metrics['avg_utilization'] );
		$this->assertContains( $metrics['mesh_health'], array( 'excellent', 'good', 'warning', 'critical' ) );
	}

	/**
	 * Test mesh health status calculation.
	 */
	public function test_mesh_health_status() {
		$settings = array(
			'mesh_peer_sites' => array(
				array( 'name' => 'peer1', 'url' => 'https://peer1.example.com', 'api_key' => 'key1' ),
				array( 'name' => 'peer2', 'url' => 'https://peer2.example.com', 'api_key' => 'key2' ),
				array( 'name' => 'peer3', 'url' => 'https://peer3.example.com', 'api_key' => 'key3' ),
				array( 'name' => 'peer4', 'url' => 'https://peer4.example.com', 'api_key' => 'key4' ),
			),
		);

		update_option( 'wp_mcp_ai_settings', $settings );

		// Scenario: All peers healthy = excellent.
		$health_metrics = array(
			'peer1' => array( 'status' => 'healthy', 'success_rate' => 99, 'last_update' => time() ),
			'peer2' => array( 'status' => 'healthy', 'success_rate' => 98, 'last_update' => time() ),
			'peer3' => array( 'status' => 'healthy', 'success_rate' => 97, 'last_update' => time() ),
			'peer4' => array( 'status' => 'healthy', 'success_rate' => 96, 'last_update' => time() ),
		);

		update_option( 'wp_mcp_ai_mesh_health_metrics', $health_metrics );

		$metrics = WP_MCP_AI_Mesh_Router::get_mesh_capacity_metrics();
		$this->assertEquals( 'excellent', $metrics['mesh_health'] );
	}

	/**
	 * Test capacity score calculation with different loads.
	 */
	public function test_capacity_score_varies_with_load() {
		// Low load scenario.
		$low_load_health = array(
			'current_load'      => 2,
			'avg_response_time' => 1.0,
			'total_requests'    => 20,
		);

		// High load scenario.
		$high_load_health = array(
			'current_load'      => 15,
			'avg_response_time' => 8.0,
			'total_requests'    => 150,
		);

		// Use reflection to access protected method for testing.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Mesh_Router' );
		$method     = $reflection->getMethod( 'calculate_peer_capacity_score' );
		$method->setAccessible( true );

		$low_score  = $method->invoke( null, $low_load_health, 2.0 );
		$high_score = $method->invoke( null, $high_load_health, 2.0 );

		// Low load should have higher capacity score.
		$this->assertGreaterThan( $high_score, $low_score );
		$this->assertGreaterThanOrEqual( 0, $low_score );
		$this->assertLessThanOrEqual( 100, $low_score );
		$this->assertGreaterThanOrEqual( 0, $high_score );
		$this->assertLessThanOrEqual( 100, $high_score );
	}

	/**
	 * Test AI-optimized selection includes capacity scoring.
	 */
	public function test_ai_optimized_selection_with_capacity() {
		// Create mock healthy peers with different capacity profiles.
		$healthy_peers = array(
			array(
				'name'   => 'high_capacity_peer',
				'url'    => 'https://high.example.com',
				'health' => array(
					'status'            => 'healthy',
					'current_load'      => 2,
					'avg_response_time' => 1.5,
					'success_rate'      => 99,
					'total_requests'    => 20,
				),
			),
			array(
				'name'   => 'low_capacity_peer',
				'url'    => 'https://low.example.com',
				'health' => array(
					'status'            => 'healthy',
					'current_load'      => 15,
					'avg_response_time' => 7.0,
					'success_rate'      => 85,
					'total_requests'    => 150,
				),
			),
		);

		$prompt     = 'Simple test prompt';
		$hub_config = array();
		$context    = array();

		$reflection = new ReflectionClass( 'WP_MCP_AI_Mesh_Router' );
		$method     = $reflection->getMethod( 'select_peer_ai_optimized' );
		$method->setAccessible( true );

		$selected = $method->invoke( null, $healthy_peers, $prompt, $hub_config, $context );

		// Should select the high capacity peer.
		$this->assertEquals( 'high_capacity_peer', $selected['name'] );
	}

	/**
	 * Test recommendation message for critical scenarios.
	 */
	public function test_mesh_recommendation_critical_scenario() {
		$settings = array(
			'mesh_peer_sites' => array(
				array( 'name' => 'peer1', 'url' => 'https://peer1.example.com', 'api_key' => 'key1' ),
				array( 'name' => 'peer2', 'url' => 'https://peer2.example.com', 'api_key' => 'key2' ),
			),
		);

		update_option( 'wp_mcp_ai_settings', $settings );

		// All peers down.
		$health_metrics = array(
			'peer1' => array( 'status' => 'down', 'success_rate' => 20, 'last_update' => time() ),
			'peer2' => array( 'status' => 'down', 'success_rate' => 15, 'last_update' => time() ),
		);

		update_option( 'wp_mcp_ai_mesh_health_metrics', $health_metrics );

		$metrics = WP_MCP_AI_Mesh_Router::get_mesh_capacity_metrics();

		$this->assertEquals( 'critical', $metrics['mesh_health'] );
		$this->assertStringContainsString( 'CRITICAL', $metrics['recommended_action'] );
	}

	/**
	 * Test that empty mesh returns error.
	 */
	public function test_empty_mesh_returns_error() {
		update_option( 'wp_mcp_ai_settings', array() );

		$metrics = WP_MCP_AI_Mesh_Router::get_mesh_capacity_metrics();

		$this->assertArrayHasKey( 'error', $metrics );
		$this->assertEquals( 0, $metrics['total_peers'] );
	}
}
