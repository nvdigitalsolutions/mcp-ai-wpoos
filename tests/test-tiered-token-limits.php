<?php
/**
 * Test tiered token limits functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for tiered token limits.
 */
class WP_MCP_AI_Tiered_Token_Limits_Test extends WP_UnitTestCase {

	/**
	 * Test user tier assignment by role.
	 */
	public function test_user_tier_by_role() {
		// Test administrator gets enterprise tier.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$tier     = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $admin_id );
		$this->assertEquals( 'enterprise', $tier );

		// Test editor gets pro tier.
		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		$tier      = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $editor_id );
		$this->assertEquals( 'pro', $tier );

		// Test author gets pro tier.
		$author_id = $this->factory->user->create( array( 'role' => 'author' ) );
		$tier      = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $author_id );
		$this->assertEquals( 'pro', $tier );

		// Test subscriber gets free tier.
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$tier          = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $subscriber_id );
		$this->assertEquals( 'free', $tier );

		// Test contributor gets free tier.
		$contributor_id = $this->factory->user->create( array( 'role' => 'contributor' ) );
		$tier           = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $contributor_id );
		$this->assertEquals( 'free', $tier );
	}

	/**
	 * Test custom tier override.
	 */
	public function test_custom_tier_override() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Default tier should be free.
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
		$this->assertEquals( 'free', $tier );

		// Set custom tier to pro.
		WP_MCP_AI_Tool_Token_Limits::set_user_tier( $user_id, 'pro' );

		// Tier should now be pro.
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
		$this->assertEquals( 'pro', $tier );

		// Set custom tier to enterprise.
		WP_MCP_AI_Tool_Token_Limits::set_user_tier( $user_id, 'enterprise' );

		// Tier should now be enterprise.
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
		$this->assertEquals( 'enterprise', $tier );
	}

	/**
	 * Test tier expiration.
	 */
	public function test_tier_expiration() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Set tier to pro with past expiration.
		$past_expiry = strtotime( '-1 day' );
		WP_MCP_AI_Tool_Token_Limits::set_user_tier( $user_id, 'pro', $past_expiry );

		// Tier should revert to default (free) because it's expired.
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
		$this->assertEquals( 'free', $tier );

		// Set tier to pro with future expiration.
		$future_expiry = strtotime( '+7 days' );
		WP_MCP_AI_Tool_Token_Limits::set_user_tier( $user_id, 'pro', $future_expiry );

		// Tier should be pro because it hasn't expired.
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
		$this->assertEquals( 'pro', $tier );
	}

	/**
	 * Test tier-based limit calculation.
	 */
	public function test_tier_based_limits() {
		// Free tier user.
		$free_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$limit     = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $free_user, 'general_tools' );
		$this->assertEquals( 50000, $limit ); // Free tier base limit.

		// Pro tier user.
		$pro_user = $this->factory->user->create( array( 'role' => 'editor' ) );
		$limit    = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $pro_user, 'general_tools' );
		$this->assertEquals( 200000, $limit ); // Pro tier base limit.

		// Enterprise tier user.
		$enterprise_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$limit           = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $enterprise_user, 'general_tools' );
		$this->assertEquals( 1000000, $limit ); // Enterprise tier base limit.
	}

	/**
	 * Test tool multipliers.
	 */
	public function test_tool_multipliers() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) ); // Pro tier (200k base).

		// General tool should use 1.0 multiplier.
		$limit = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, 'general_tools' );
		$this->assertEquals( 200000, $limit );

		// Crawl4AI should use 2.0 multiplier.
		$limit = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, 'run_crawl4ai_job' );
		$this->assertEquals( 400000, $limit ); // 200k * 2.0.

		// Search content should use 1.5 multiplier.
		$limit = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, 'search_content' );
		$this->assertEquals( 300000, $limit ); // 200k * 1.5.
	}

	/**
	 * Test hourly usage tracking.
	 */
	public function test_hourly_usage_tracking() {
		$user_id   = $this->factory->user->create();
		$tool_slug = 'test_tool';

		// Initially, hourly usage should be 0.
		$hour_key = gmdate( 'Y-m-d-H', current_time( 'timestamp', true ) );
		$usage    = WP_MCP_AI_Tool_Token_Limits::get_user_tool_hourly_usage( $user_id, $tool_slug, $hour_key );
		$this->assertEquals( 0, $usage );

		// Simulate tool usage recording.
		$context = array( 'user_id' => $user_id );
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, 'Test result with some content' );

		// Hourly usage should now be greater than 0.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_hourly_usage( $user_id, $tool_slug, $hour_key );
		$this->assertGreaterThan( 0, $usage );
	}

	/**
	 * Test bulk tier assignment.
	 */
	public function test_bulk_tier_assignment() {
		// Create multiple users.
		$user_ids = array(
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
			$this->factory->user->create( array( 'role' => 'subscriber' ) ),
		);

		// Assign all to pro tier (as admin).
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$results = WP_MCP_AI_Tool_Token_Limits::bulk_set_user_tiers( $user_ids, 'pro' );

		$this->assertEquals( 3, $results['success'] );
		$this->assertEquals( 0, $results['failed'] );

		// Verify all users have pro tier.
		foreach ( $user_ids as $user_id ) {
			$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
			$this->assertEquals( 'pro', $tier );
		}
	}

	/**
	 * Test forecast calculation.
	 */
	public function test_forecast_calculation() {
		$user_id   = $this->factory->user->create();
		$tool_slug = 'test_tool';

		// Forecast should be null with no data.
		$forecast = WP_MCP_AI_Tool_Token_Limits::forecast_limit_exhaustion( $user_id, $tool_slug );
		$this->assertNull( $forecast );

		// Create some hourly usage data (simulate 48 hours of usage).
		$usage = array(
			$tool_slug => array(
				'total_tokens' => 0,
				'requests'     => 0,
				'first_used'   => '',
				'last_used'    => '',
				'daily'        => array(),
				'hourly'       => array(),
			),
		);

		for ( $i = 0; $i < 48; $i++ ) {
			$hour_key                            = gmdate( 'Y-m-d-H', strtotime( "-{$i} hours" ) );
			$usage[ $tool_slug ]['hourly'][ $hour_key ] = 1000; // 1000 tokens per hour.
		}

		$date_key                                     = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );
		$usage[ $tool_slug ]['daily'][ $date_key ] = 10000; // 10k tokens used today.

		update_user_meta( $user_id, '_wp_mcp_ai_tool_token_usage', $usage );

		// Now forecast should work.
		$forecast = WP_MCP_AI_Tool_Token_Limits::forecast_limit_exhaustion( $user_id, $tool_slug );

		$this->assertIsArray( $forecast );
		$this->assertArrayHasKey( 'will_exceed', $forecast );
		$this->assertArrayHasKey( 'projected_usage', $forecast );
		$this->assertArrayHasKey( 'confidence', $forecast );
		$this->assertGreaterThan( 70, $forecast['confidence'] ); // Should have high confidence with 48 hours of data.
	}
}
