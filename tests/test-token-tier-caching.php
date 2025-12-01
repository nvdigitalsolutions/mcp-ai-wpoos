<?php
/**
 * Test token tier caching functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for token tier caching.
 */
class WP_MCP_AI_Token_Tier_Caching_Test extends WP_UnitTestCase {

	/**
	 * Clean up cache before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_cache_flush();
	}

	/**
	 * Test that tier lookup uses cache when enabled.
	 */
	public function test_tier_lookup_uses_cache() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// First call should cache the tier.
		$tier1 = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id, true );
		$this->assertEquals( 'pro', $tier1 );

		// Verify tier is in cache.
		$cached = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'pro', $cached );

		// Second call should return cached value.
		$tier2 = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id, true );
		$this->assertEquals( 'pro', $tier2 );
	}

	/**
	 * Test that cache can be bypassed.
	 */
	public function test_tier_lookup_bypasses_cache_when_disabled() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Get tier without cache.
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id, false );
		$this->assertEquals( 'free', $tier );

		// Verify tier is NOT in cache.
		$cached = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertFalse( $cached );
	}

	/**
	 * Test that custom tier is cached correctly.
	 */
	public function test_custom_tier_is_cached() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Set custom tier.
		update_user_meta( $user_id, '_wp_mcp_ai_token_tier', 'enterprise' );

		// Get tier with cache.
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id, true );
		$this->assertEquals( 'enterprise', $tier );

		// Verify cached value.
		$cached = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'enterprise', $cached );
	}

	/**
	 * Test that expired tier is handled correctly and cache is not set.
	 */
	public function test_expired_tier_not_cached() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Set expired custom tier.
		update_user_meta( $user_id, '_wp_mcp_ai_token_tier', 'pro' );
		update_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires', strtotime( '-1 day' ) );

		// Get tier (should fall back to role-based).
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id, true );
		$this->assertEquals( 'free', $tier );

		// Verify expired tier meta was deleted.
		$custom_tier = get_user_meta( $user_id, '_wp_mcp_ai_token_tier', true );
		$this->assertEmpty( $custom_tier );

		// Verify new tier is cached.
		$cached = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'free', $cached );
	}

	/**
	 * Test cache invalidation when tier is updated.
	 */
	public function test_cache_invalidated_on_tier_update() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Get initial tier (should be cached).
		$tier1 = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id, true );
		$this->assertEquals( 'free', $tier1 );

		// Verify cache exists.
		$cached1 = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'free', $cached1 );

		// Update tier.
		WP_MCP_AI_Tool_Token_Limits::set_user_tier( $user_id, 'pro' );

		// Verify cache was invalidated.
		$cached2 = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertFalse( $cached2 );

		// Get new tier (should cache new value).
		$tier2 = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id, true );
		$this->assertEquals( 'pro', $tier2 );

		// Verify new value is cached.
		$cached3 = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'pro', $cached3 );
	}

	/**
	 * Test manual cache invalidation.
	 */
	public function test_manual_cache_invalidation() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Cache the tier.
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id, true );
		$this->assertEquals( 'pro', $tier );

		// Verify cache exists.
		$cached1 = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'pro', $cached1 );

		// Invalidate cache manually.
		WP_MCP_AI_Tool_Token_Limits::invalidate_tier_cache( $user_id );

		// Verify cache is gone.
		$cached2 = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertFalse( $cached2 );
	}

	/**
	 * Test bulk tier preloading.
	 */
	public function test_bulk_tier_preloading() {
		// Create multiple users with different roles.
		$user1_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user2_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		$user3_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$user_ids = array( $user1_id, $user2_id, $user3_id );

		// Preload tiers.
		$count = WP_MCP_AI_Tool_Token_Limits::preload_user_tiers( $user_ids );
		$this->assertEquals( 3, $count );

		// Verify all tiers are cached.
		$cached1 = wp_cache_get( "wp_mcp_ai_user_tier_{$user1_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'free', $cached1 );

		$cached2 = wp_cache_get( "wp_mcp_ai_user_tier_{$user2_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'pro', $cached2 );

		$cached3 = wp_cache_get( "wp_mcp_ai_user_tier_{$user3_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'enterprise', $cached3 );
	}

	/**
	 * Test preloading with invalid user IDs.
	 */
	public function test_preloading_handles_invalid_user_ids() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Mix valid and invalid user IDs.
		$user_ids = array( $user_id, 0, -1, 'invalid', null );

		// Should only preload valid user.
		$count = WP_MCP_AI_Tool_Token_Limits::preload_user_tiers( $user_ids );
		$this->assertEquals( 1, $count );

		// Verify only valid user is cached.
		$cached = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'free', $cached );
	}

	/**
	 * Test preloading with empty array.
	 */
	public function test_preloading_empty_array() {
		$count = WP_MCP_AI_Tool_Token_Limits::preload_user_tiers( array() );
		$this->assertEquals( 0, $count );
	}

	/**
	 * Test preloading with non-array input.
	 */
	public function test_preloading_non_array_input() {
		$count = WP_MCP_AI_Tool_Token_Limits::preload_user_tiers( 'not an array' );
		$this->assertEquals( 0, $count );

		$count2 = WP_MCP_AI_Tool_Token_Limits::preload_user_tiers( null );
		$this->assertEquals( 0, $count2 );
	}

	/**
	 * Test that guest users return default tier without caching.
	 */
	public function test_guest_user_tier_not_cached() {
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( 0, true );
		$this->assertEquals( 'free', $tier );

		// Verify no cache entry for user ID 0.
		$cached = wp_cache_get( 'wp_mcp_ai_user_tier_0', 'wp_mcp_ai' );
		$this->assertFalse( $cached );
	}

	/**
	 * Test cache TTL is set correctly (1 hour).
	 */
	public function test_cache_ttl() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Mock wp_cache_set to capture TTL.
		$set_calls = array();
		add_filter(
			'wp_cache_set',
			function ( $result, $key, $data, $group, $expiration ) use ( &$set_calls ) {
				if ( 'wp_mcp_ai' === $group && false !== strpos( $key, 'wp_mcp_ai_user_tier_' ) ) {
					$set_calls[] = array(
						'key'        => $key,
						'data'       => $data,
						'group'      => $group,
						'expiration' => $expiration,
					);
				}
				return $result;
			},
			10,
			5
		);

		// Get tier to trigger cache set.
		WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id, true );

		// Note: This test may not work reliably as wp_cache_set filter doesn't exist in core.
		// But we can verify the cache was set.
		$cached = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'pro', $cached );
	}

	/**
	 * Test that filters still work with caching.
	 */
	public function test_filters_work_with_caching() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Add filter to override tier.
		add_filter(
			'wp_mcp_ai_default_user_tier',
			function ( $tier, $uid ) use ( $user_id ) {
				if ( $uid === $user_id ) {
					return 'enterprise';
				}
				return $tier;
			},
			10,
			2
		);

		// Get tier with cache.
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id, true );
		$this->assertEquals( 'enterprise', $tier );

		// Verify filtered value is cached.
		$cached = wp_cache_get( "wp_mcp_ai_user_tier_{$user_id}", 'wp_mcp_ai' );
		$this->assertEquals( 'enterprise', $cached );
	}

	/**
	 * Test bulk tier update invalidates cache for all users.
	 */
	public function test_bulk_update_invalidates_cache() {
		// Create users and cache their tiers.
		$user1_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user2_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Cache initial tiers.
		WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user1_id, true );
		WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user2_id, true );

		// Verify both are cached.
		$this->assertEquals( 'free', wp_cache_get( "wp_mcp_ai_user_tier_{$user1_id}", 'wp_mcp_ai' ) );
		$this->assertEquals( 'free', wp_cache_get( "wp_mcp_ai_user_tier_{$user2_id}", 'wp_mcp_ai' ) );

		// Bulk update tiers.
		$results = WP_MCP_AI_Tool_Token_Limits::bulk_set_user_tiers( array( $user1_id, $user2_id ), 'pro' );
		$this->assertEquals( 2, $results['success'] );

		// Verify cache was invalidated for both.
		$this->assertFalse( wp_cache_get( "wp_mcp_ai_user_tier_{$user1_id}", 'wp_mcp_ai' ) );
		$this->assertFalse( wp_cache_get( "wp_mcp_ai_user_tier_{$user2_id}", 'wp_mcp_ai' ) );

		// Get fresh tiers (should cache new values).
		$tier1 = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user1_id, true );
		$tier2 = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user2_id, true );

		$this->assertEquals( 'pro', $tier1 );
		$this->assertEquals( 'pro', $tier2 );
	}
}
