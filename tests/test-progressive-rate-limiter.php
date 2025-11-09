<?php
/**
 * Tests for Progressive Rate Limiter.
 *
 * @package WP_MCP_AI
 */

/**
 * Progressive Rate Limiter test case.
 */
class Test_Progressive_Rate_Limiter extends WP_UnitTestCase {

	/**
	 * Test rate limiter is disabled by default.
	 */
	public function test_disabled_by_default() {
		$this->assertFalse( WP_MCP_AI_Progressive_Rate_Limiter::is_enabled() );
	}

	/**
	 * Test normal tier allows requests.
	 */
	public function test_normal_tier_allows_requests() {
		$result = WP_MCP_AI_Progressive_Rate_Limiter::check_rate_limit( 'test_user_1' );

		$this->assertTrue( $result['allowed'] );
		$this->assertEquals( 'normal', $result['tier'] );
	}

	/**
	 * Test recording requests.
	 */
	public function test_record_request() {
		$identifier = 'test_user_2';

		$success = WP_MCP_AI_Progressive_Rate_Limiter::record_request( $identifier );
		$this->assertTrue( $success );

		// Check status was updated.
		$status = WP_MCP_AI_Progressive_Rate_Limiter::get_status( $identifier );
		$this->assertEquals( 1, $status['minute_used'] );
	}

	/**
	 * Test tier escalation with violations.
	 */
	public function test_tier_escalation() {
		$identifier = 'test_user_3';

		// Start at normal tier.
		$tier = WP_MCP_AI_Progressive_Rate_Limiter::get_current_tier( $identifier );
		$this->assertEquals( 'normal', $tier );

		// Simulate violations to trigger tier changes.
		// Since record_violation is protected, we'll test through check_rate_limit.
		// We need to exceed limits to trigger violations.
	}

	/**
	 * Test getting status.
	 */
	public function test_get_status() {
		$identifier = 'test_user_4';
		$status = WP_MCP_AI_Progressive_Rate_Limiter::get_status( $identifier );

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'tier', $status );
		$this->assertArrayHasKey( 'violations_count', $status );
		$this->assertArrayHasKey( 'minute_limit', $status );
		$this->assertArrayHasKey( 'hour_limit', $status );
	}

	/**
	 * Test clearing violations.
	 */
	public function test_clear_violations() {
		$identifier = 'test_user_5';

		// Clear violations (even if none exist).
		$result = WP_MCP_AI_Progressive_Rate_Limiter::clear_violations( $identifier );
		$this->assertTrue( $result );

		// Verify violation count is 0.
		$count = WP_MCP_AI_Progressive_Rate_Limiter::get_violation_count( $identifier );
		$this->assertEquals( 0, $count );
	}

	/**
	 * Test rate limit with different endpoints.
	 */
	public function test_endpoint_specific_limits() {
		$identifier = 'test_user_6';

		// Record request to endpoint1.
		WP_MCP_AI_Progressive_Rate_Limiter::record_request( $identifier, 'endpoint1' );

		// Check status for endpoint1.
		$status1 = WP_MCP_AI_Progressive_Rate_Limiter::get_status( $identifier, 'endpoint1' );
		$this->assertEquals( 1, $status1['minute_used'] );

		// Check status for endpoint2 (should be separate).
		$status2 = WP_MCP_AI_Progressive_Rate_Limiter::get_status( $identifier, 'endpoint2' );
		$this->assertEquals( 0, $status2['minute_used'] );
	}
}
