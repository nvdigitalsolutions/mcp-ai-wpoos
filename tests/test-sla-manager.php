<?php
/**
 * Tests for SLA Manager functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test SLA Manager.
 */
class Test_SLA_Manager extends WP_UnitTestCase {
	/**
	 * Test getting SLA tier for a tool.
	 */
	public function test_get_tier_for_tool() {
		// Mock tool with realtime capability.
		$realtime_tool = $this->create_mock_tool( array( 'realtime', 'interactive' ) );
		$tier          = WP_MCP_AI_SLA_Manager::get_tier_for_tool( $realtime_tool );
		$this->assertEquals( WP_MCP_AI_SLA_Manager::TIER_REALTIME, $tier );

		// Mock tool with async capability.
		$async_tool = $this->create_mock_tool( array( 'async', 'may-timeout' ) );
		$tier       = WP_MCP_AI_SLA_Manager::get_tier_for_tool( $async_tool );
		$this->assertEquals( WP_MCP_AI_SLA_Manager::TIER_NEAR_REALTIME, $tier );

		// Mock tool with background-only capability.
		$batch_tool = $this->create_mock_tool( array( 'background-only', 'long-running' ) );
		$tier       = WP_MCP_AI_SLA_Manager::get_tier_for_tool( $batch_tool );
		$this->assertEquals( WP_MCP_AI_SLA_Manager::TIER_BATCH, $tier );

		// Mock tool with explicit SLA tier.
		$explicit_tool = $this->create_mock_tool( array( 'sla_tier' => 'near_realtime' ) );
		$tier          = WP_MCP_AI_SLA_Manager::get_tier_for_tool( $explicit_tool );
		$this->assertEquals( WP_MCP_AI_SLA_Manager::TIER_NEAR_REALTIME, $tier );
	}

	/**
	 * Test getting priority for a tier.
	 */
	public function test_get_priority() {
		$this->assertEquals( 100, WP_MCP_AI_SLA_Manager::get_priority( WP_MCP_AI_SLA_Manager::TIER_REALTIME ) );
		$this->assertEquals( 50, WP_MCP_AI_SLA_Manager::get_priority( WP_MCP_AI_SLA_Manager::TIER_NEAR_REALTIME ) );
		$this->assertEquals( 10, WP_MCP_AI_SLA_Manager::get_priority( WP_MCP_AI_SLA_Manager::TIER_BATCH ) );
		$this->assertEquals( 10, WP_MCP_AI_SLA_Manager::get_priority( 'invalid_tier' ) ); // Default to batch.
	}

	/**
	 * Test Little's Law capacity calculation.
	 */
	public function test_calculate_capacity() {
		// Real-time tier: < 1s latency.
		// Arrival rate: 2 jobs/sec, Service time: 0.5s.
		$capacity = WP_MCP_AI_SLA_Manager::calculate_capacity(
			WP_MCP_AI_SLA_Manager::TIER_REALTIME,
			2.0,  // lambda = 2 jobs/sec.
			0.5   // service time = 0.5 sec/job.
		);

		$this->assertEquals( WP_MCP_AI_SLA_Manager::TIER_REALTIME, $capacity['tier'] );
		$this->assertEquals( 1.0, $capacity['sla_target'] );
		$this->assertEquals( 2.0, $capacity['arrival_rate'] );
		$this->assertEquals( 0.5, $capacity['service_time'] );

		// Wait time = SLA - service time = 1.0 - 0.5 = 0.5s.
		$this->assertEquals( 0.5, $capacity['wait_time'] );

		// Queue length = λ × W = 2.0 × 0.5 = 1.0.
		$this->assertEquals( 1.0, $capacity['queue_length'] );

		// System capacity = λ × SLA = 2.0 × 1.0 = 2.0.
		$this->assertEquals( 2.0, $capacity['system_capacity'] );

		// Utilization = λ × service_time = 2.0 × 0.5 = 1.0.
		$this->assertEquals( 1.0, $capacity['utilization'] );

		// Required workers = ceil(utilization) = ceil(1.0) = 1.
		$this->assertEquals( 1, $capacity['required_workers'] );

		// Recommended workers should be at least the default for tier.
		$this->assertGreaterThanOrEqual( 1, $capacity['recommended_workers'] );
	}

	/**
	 * Test capacity calculation for high load scenario.
	 */
	public function test_calculate_capacity_high_load() {
		// Near real-time tier: 1-30s latency.
		// High load: 5 jobs/sec, Service time: 10s.
		$capacity = WP_MCP_AI_SLA_Manager::calculate_capacity(
			WP_MCP_AI_SLA_Manager::TIER_NEAR_REALTIME,
			5.0,   // lambda = 5 jobs/sec.
			10.0   // service time = 10 sec/job.
		);

		$this->assertEquals( 30.0, $capacity['sla_target'] );

		// Utilization = 5.0 × 10.0 = 50.0.
		$this->assertEquals( 50.0, $capacity['utilization'] );

		// Required workers = ceil(50.0) = 50.
		$this->assertEquals( 50, $capacity['required_workers'] );

		// This indicates system is severely overloaded.
		$this->assertGreaterThan( 10, $capacity['required_workers'] );
	}

	/**
	 * Test SLA target retrieval.
	 */
	public function test_get_sla_target() {
		$this->assertEquals( 1, WP_MCP_AI_SLA_Manager::get_sla_target( WP_MCP_AI_SLA_Manager::TIER_REALTIME ) );
		$this->assertEquals( 30, WP_MCP_AI_SLA_Manager::get_sla_target( WP_MCP_AI_SLA_Manager::TIER_NEAR_REALTIME ) );
		$this->assertEquals( 300, WP_MCP_AI_SLA_Manager::get_sla_target( WP_MCP_AI_SLA_Manager::TIER_BATCH ) );
	}

	/**
	 * Test default concurrent job limits.
	 */
	public function test_get_default_concurrent() {
		$this->assertEquals( 5, WP_MCP_AI_SLA_Manager::get_default_concurrent( WP_MCP_AI_SLA_Manager::TIER_REALTIME ) );
		$this->assertEquals( 3, WP_MCP_AI_SLA_Manager::get_default_concurrent( WP_MCP_AI_SLA_Manager::TIER_NEAR_REALTIME ) );
		$this->assertEquals( 2, WP_MCP_AI_SLA_Manager::get_default_concurrent( WP_MCP_AI_SLA_Manager::TIER_BATCH ) );
	}

	/**
	 * Test enabled/disabled check.
	 */
	public function test_is_enabled() {
		// Default should be enabled.
		$this->assertTrue( WP_MCP_AI_SLA_Manager::is_enabled() );

		// Disable via setting.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['sla_prioritization_enabled'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertFalse( WP_MCP_AI_SLA_Manager::is_enabled() );

		// Re-enable.
		$settings['sla_prioritization_enabled'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertTrue( WP_MCP_AI_SLA_Manager::is_enabled() );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test getting all tiers info.
	 */
	public function test_get_all_tiers_info() {
		$info = WP_MCP_AI_SLA_Manager::get_all_tiers_info();

		$this->assertIsArray( $info );
		$this->assertCount( 3, $info );
		$this->assertArrayHasKey( WP_MCP_AI_SLA_Manager::TIER_REALTIME, $info );
		$this->assertArrayHasKey( WP_MCP_AI_SLA_Manager::TIER_NEAR_REALTIME, $info );
		$this->assertArrayHasKey( WP_MCP_AI_SLA_Manager::TIER_BATCH, $info );

		// Check structure of tier info.
		$realtime = $info[ WP_MCP_AI_SLA_Manager::TIER_REALTIME ];
		$this->assertArrayHasKey( 'tier', $realtime );
		$this->assertArrayHasKey( 'priority', $realtime );
		$this->assertArrayHasKey( 'sla_target', $realtime );
		$this->assertArrayHasKey( 'concurrent', $realtime );
		$this->assertArrayHasKey( 'description', $realtime );
	}

	/**
	 * Test valid tiers list.
	 */
	public function test_get_valid_tiers() {
		$tiers = WP_MCP_AI_SLA_Manager::get_valid_tiers();

		$this->assertIsArray( $tiers );
		$this->assertCount( 3, $tiers );
		$this->assertContains( WP_MCP_AI_SLA_Manager::TIER_REALTIME, $tiers );
		$this->assertContains( WP_MCP_AI_SLA_Manager::TIER_NEAR_REALTIME, $tiers );
		$this->assertContains( WP_MCP_AI_SLA_Manager::TIER_BATCH, $tiers );
	}

	/**
	 * Test tier inference from no capabilities.
	 */
	public function test_default_tier_for_unknown_tool() {
		$unknown_tool = $this->create_mock_tool( array() );
		$tier         = WP_MCP_AI_SLA_Manager::get_tier_for_tool( $unknown_tool );

		// Should default to batch for safety.
		$this->assertEquals( WP_MCP_AI_SLA_Manager::TIER_BATCH, $tier );
	}

	/**
	 * Helper: Create a mock tool with capabilities.
	 *
	 * @param array $capabilities Tool capabilities.
	 * @return object Mock tool.
	 */
	protected function create_mock_tool( $capabilities ) {
		return new class( $capabilities ) {
			protected $capabilities;

			public function __construct( $capabilities ) {
				$this->capabilities = $capabilities;
			}

			public function get_capabilities() {
				return $this->capabilities;
			}
		};
	}
}
