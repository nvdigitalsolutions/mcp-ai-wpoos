<?php
/**
 * Test database optimizer for token management.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for database optimizer.
 *
 * Tests database optimization functionality separately from business logic.
 */
class WP_MCP_AI_Token_DB_Optimizer_Test extends WP_UnitTestCase {

	/**
	 * Clean up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		// Reset schema version.
		delete_option( WP_MCP_AI_Token_DB_Optimizer::SCHEMA_VERSION_OPTION );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up any indexes created during tests.
		WP_MCP_AI_Token_DB_Optimizer::remove_optimizations();
		parent::tearDown();
	}

	/**
	 * Test that schema version is tracked correctly.
	 */
	public function test_schema_version_tracking() {
		// Initially should be 0 (not set).
		$version = get_option( WP_MCP_AI_Token_DB_Optimizer::SCHEMA_VERSION_OPTION, 0 );
		$this->assertEquals( 0, $version );

		// Run optimization.
		WP_MCP_AI_Token_DB_Optimizer::maybe_optimize_database();

		// Version should be updated.
		$version = get_option( WP_MCP_AI_Token_DB_Optimizer::SCHEMA_VERSION_OPTION, 0 );
		$this->assertEquals( WP_MCP_AI_Token_DB_Optimizer::CURRENT_SCHEMA_VERSION, $version );
	}

	/**
	 * Test that optimization only runs when needed.
	 */
	public function test_optimization_runs_only_when_needed() {
		// First run should optimize.
		WP_MCP_AI_Token_DB_Optimizer::maybe_optimize_database();
		$version1 = get_option( WP_MCP_AI_Token_DB_Optimizer::SCHEMA_VERSION_OPTION, 0 );
		$this->assertEquals( WP_MCP_AI_Token_DB_Optimizer::CURRENT_SCHEMA_VERSION, $version1 );

		// Second run should not change version (no re-optimization).
		WP_MCP_AI_Token_DB_Optimizer::maybe_optimize_database();
		$version2 = get_option( WP_MCP_AI_Token_DB_Optimizer::SCHEMA_VERSION_OPTION, 0 );
		$this->assertEquals( $version1, $version2 );
	}

	/**
	 * Test getting existing indexes.
	 */
	public function test_get_existing_indexes() {
		global $wpdb;

		// Get indexes using reflection since method is protected.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Token_DB_Optimizer' );
		$method     = $reflection->getMethod( 'get_existing_indexes' );
		$method->setAccessible( true );

		$indexes = $method->invoke( null );

		// Should return an array.
		$this->assertIsArray( $indexes );

		// Should have at least PRIMARY key.
		$this->assertContains( 'PRIMARY', $indexes );
	}

	/**
	 * Test optimization stats retrieval.
	 */
	public function test_get_optimization_stats() {
		// Run optimization first.
		WP_MCP_AI_Token_DB_Optimizer::maybe_optimize_database();

		$stats = WP_MCP_AI_Token_DB_Optimizer::get_optimization_stats();

		// Verify stats structure.
		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'schema_version', $stats );
		$this->assertArrayHasKey( 'optimizations_active', $stats );
		$this->assertArrayHasKey( 'tier_index_exists', $stats );
		$this->assertArrayHasKey( 'usage_index_exists', $stats );
		$this->assertArrayHasKey( 'tier_records', $stats );
		$this->assertArrayHasKey( 'usage_records', $stats );
		$this->assertArrayHasKey( 'total_indexes', $stats );
		$this->assertArrayHasKey( 'wp_mcp_ai_indexes', $stats );

		// Verify optimizations are active.
		$this->assertTrue( $stats['optimizations_active'] );
		$this->assertEquals( WP_MCP_AI_Token_DB_Optimizer::CURRENT_SCHEMA_VERSION, $stats['schema_version'] );

		// Verify record counts are non-negative integers.
		$this->assertGreaterThanOrEqual( 0, $stats['tier_records'] );
		$this->assertGreaterThanOrEqual( 0, $stats['usage_records'] );
		$this->assertGreaterThanOrEqual( 0, $stats['total_indexes'] );
		$this->assertGreaterThanOrEqual( 0, $stats['wp_mcp_ai_indexes'] );
	}

	/**
	 * Test query performance analysis.
	 */
	public function test_analyze_query_performance() {
		// Run optimization first.
		WP_MCP_AI_Token_DB_Optimizer::maybe_optimize_database();

		$analysis = WP_MCP_AI_Token_DB_Optimizer::analyze_query_performance();

		// Verify analysis structure.
		$this->assertIsArray( $analysis );
		$this->assertArrayHasKey( 'tier_lookup', $analysis );
		$this->assertArrayHasKey( 'usage_lookup', $analysis );

		// Verify tier lookup analysis.
		if ( ! empty( $analysis['tier_lookup'] ) ) {
			$this->assertArrayHasKey( 'using_index', $analysis['tier_lookup'] );
			$this->assertArrayHasKey( 'index_name', $analysis['tier_lookup'] );
			$this->assertArrayHasKey( 'rows', $analysis['tier_lookup'] );
			$this->assertArrayHasKey( 'type', $analysis['tier_lookup'] );

			// After optimization, should be using an index.
			$this->assertTrue( $analysis['tier_lookup']['using_index'] );
		}

		// Verify usage lookup analysis.
		if ( ! empty( $analysis['usage_lookup'] ) ) {
			$this->assertArrayHasKey( 'using_index', $analysis['usage_lookup'] );
			$this->assertArrayHasKey( 'index_name', $analysis['usage_lookup'] );
			$this->assertArrayHasKey( 'rows', $analysis['usage_lookup'] );
			$this->assertArrayHasKey( 'type', $analysis['usage_lookup'] );

			// After optimization, should be using an index.
			$this->assertTrue( $analysis['usage_lookup']['using_index'] );
		}
	}

	/**
	 * Test removing optimizations.
	 */
	public function test_remove_optimizations() {
		// Run optimization.
		WP_MCP_AI_Token_DB_Optimizer::maybe_optimize_database();

		// Verify optimization is active.
		$stats_before = WP_MCP_AI_Token_DB_Optimizer::get_optimization_stats();
		$this->assertTrue( $stats_before['optimizations_active'] );

		// Remove optimizations.
		$result = WP_MCP_AI_Token_DB_Optimizer::remove_optimizations();
		$this->assertTrue( $result );

		// Verify schema version is removed.
		$version = get_option( WP_MCP_AI_Token_DB_Optimizer::SCHEMA_VERSION_OPTION, 0 );
		$this->assertEquals( 0, $version );

		// Verify optimizations are no longer active.
		$stats_after = WP_MCP_AI_Token_DB_Optimizer::get_optimization_stats();
		$this->assertFalse( $stats_after['optimizations_active'] );
	}

	/**
	 * Test that optimizer logs events properly.
	 */
	public function test_optimizer_logs_events() {
		// Skip if logger not available.
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Logger not available' );
		}

		// Clear any existing logs.
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Run optimization.
		WP_MCP_AI_Token_DB_Optimizer::maybe_optimize_database();

		// Check if event was logged.
		$recent_activity = get_option( 'wp_mcp_ai_recent_activity', array() );

		// Look for optimization event.
		$found_event = false;
		if ( is_array( $recent_activity ) ) {
			foreach ( $recent_activity as $event ) {
				if ( isset( $event['event'] ) && 'token_db_optimized' === $event['event'] ) {
					$found_event = true;
					break;
				}
			}
		}

		$this->assertTrue( $found_event, 'Database optimization event should be logged' );
	}

	/**
	 * Test tier record counting.
	 */
	public function test_tier_record_counting() {
		// Create users with custom tiers.
		$user1_id = $this->factory->user->create();
		$user2_id = $this->factory->user->create();
		$user3_id = $this->factory->user->create();

		update_user_meta( $user1_id, '_wp_mcp_ai_token_tier', 'pro' );
		update_user_meta( $user2_id, '_wp_mcp_ai_token_tier', 'enterprise' );
		update_user_meta( $user3_id, '_wp_mcp_ai_token_tier', 'free' );

		// Get stats.
		$stats = WP_MCP_AI_Token_DB_Optimizer::get_optimization_stats();

		// Should have at least 3 tier records.
		$this->assertGreaterThanOrEqual( 3, $stats['tier_records'] );
	}

	/**
	 * Test usage record counting.
	 */
	public function test_usage_record_counting() {
		// Create users with usage data.
		$user1_id = $this->factory->user->create();
		$user2_id = $this->factory->user->create();

		update_user_meta(
			$user1_id,
			'_wp_mcp_ai_tool_token_usage',
			array(
				'test_tool' => array(
					'total_tokens' => 1000,
					'requests'     => 10,
				),
			)
		);

		update_user_meta(
			$user2_id,
			'_wp_mcp_ai_tool_token_usage',
			array(
				'test_tool' => array(
					'total_tokens' => 2000,
					'requests'     => 20,
				),
			)
		);

		// Get stats.
		$stats = WP_MCP_AI_Token_DB_Optimizer::get_optimization_stats();

		// Should have at least 2 usage records.
		$this->assertGreaterThanOrEqual( 2, $stats['usage_records'] );
	}

	/**
	 * Test that init hook is registered.
	 */
	public function test_init_registers_hooks() {
		// Remove existing hooks.
		remove_all_actions( 'admin_init' );

		// Call init.
		WP_MCP_AI_Token_DB_Optimizer::init();

		// Verify hook is registered.
		$this->assertTrue( has_action( 'admin_init' ) );
	}

	/**
	 * Test index creation is idempotent.
	 */
	public function test_index_creation_is_idempotent() {
		// Run optimization multiple times.
		WP_MCP_AI_Token_DB_Optimizer::optimize_database();
		WP_MCP_AI_Token_DB_Optimizer::optimize_database();
		WP_MCP_AI_Token_DB_Optimizer::optimize_database();

		// Should not cause errors and stats should remain consistent.
		$stats = WP_MCP_AI_Token_DB_Optimizer::get_optimization_stats();
		$this->assertIsArray( $stats );
		$this->assertGreaterThan( 0, $stats['total_indexes'] );
	}

	/**
	 * Test that schema version constant is defined.
	 */
	public function test_schema_version_constant_exists() {
		$this->assertTrue( defined( 'WP_MCP_AI_Token_DB_Optimizer::CURRENT_SCHEMA_VERSION' ) );
		$this->assertGreaterThan( 0, WP_MCP_AI_Token_DB_Optimizer::CURRENT_SCHEMA_VERSION );
	}

	/**
	 * Test that option key constant is defined.
	 */
	public function test_option_key_constant_exists() {
		$this->assertTrue( defined( 'WP_MCP_AI_Token_DB_Optimizer::SCHEMA_VERSION_OPTION' ) );
		$this->assertNotEmpty( WP_MCP_AI_Token_DB_Optimizer::SCHEMA_VERSION_OPTION );
	}
}
