<?php
/**
 * Tests for the Resource Manager class.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Resource_Manager_Test extends WP_UnitTestCase {

	/**
	 * Test that Resource_Manager is a singleton.
	 */
	public function test_resource_manager_is_singleton() {
		$instance1 = WP_MCP_AI_Resource_Manager::instance();
		$instance2 = WP_MCP_AI_Resource_Manager::instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test that get_memory_limit returns a positive integer.
	 */
	public function test_get_memory_limit_returns_positive_integer() {
		$manager      = WP_MCP_AI_Resource_Manager::instance();
		$memory_limit = $manager->get_memory_limit();

		$this->assertIsInt( $memory_limit );
		$this->assertGreaterThan( 0, $memory_limit );
	}

	/**
	 * Test that get_max_execution_time returns a positive integer.
	 */
	public function test_get_max_execution_time_returns_positive_integer() {
		$manager            = WP_MCP_AI_Resource_Manager::instance();
		$max_execution_time = $manager->get_max_execution_time();

		$this->assertIsInt( $max_execution_time );
		$this->assertGreaterThanOrEqual( 0, $max_execution_time );
	}

	/**
	 * Test that get_workload_tier returns a valid tier.
	 */
	public function test_get_workload_tier_returns_valid_tier() {
		$manager = WP_MCP_AI_Resource_Manager::instance();
		$tier    = $manager->get_workload_tier();

		$this->assertContains( $tier, array( 'low', 'medium', 'high' ) );
	}

	/**
	 * Test that get_max_tokens returns appropriate values for different tiers.
	 */
	public function test_get_max_tokens_returns_positive_integer() {
		$manager    = WP_MCP_AI_Resource_Manager::instance();
		$max_tokens = $manager->get_max_tokens();

		$this->assertIsInt( $max_tokens );
		$this->assertGreaterThan( 0, $max_tokens );
	}

	/**
	 * Test that get_request_timeout returns a reasonable timeout value.
	 */
	public function test_get_request_timeout_returns_reasonable_value() {
		$manager = WP_MCP_AI_Resource_Manager::instance();
		$timeout = $manager->get_request_timeout();

		$this->assertIsInt( $timeout );
		$this->assertGreaterThanOrEqual( 5, $timeout );
		$this->assertLessThanOrEqual( 300, $timeout );
	}

	/**
	 * Test that can_handle_operation returns true for acceptable requirements.
	 */
	public function test_can_handle_operation_accepts_reasonable_requirements() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		$result = $manager->can_handle_operation( array( 'max_tokens' => 100 ) );

		$this->assertTrue( $result );
	}

	/**
	 * Test that can_handle_operation returns WP_Error for excessive requirements.
	 */
	public function test_can_handle_operation_rejects_excessive_requirements() {
		$manager    = WP_MCP_AI_Resource_Manager::instance();
		$max_tokens = $manager->get_max_tokens();

		// Request more tokens than allowed.
		$result = $manager->can_handle_operation( array( 'max_tokens' => $max_tokens + 1000 ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_insufficient_resources', $result->get_error_code() );
	}

	/**
	 * Test that workload tier filter works correctly.
	 */
	public function test_workload_tier_filter() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		add_filter(
			'wp_mcp_ai_workload_tier',
			function ( $tier, $memory_limit ) {
				return 'high';
			},
			10,
			2
		);

		// Clear the cached tier to force recalculation.
		$reflection = new ReflectionClass( $manager );
		$property   = $reflection->getProperty( 'workload_tier' );
		$property->setAccessible( true );
		$property->setValue( $manager, null );

		$tier = $manager->get_workload_tier();

		$this->assertSame( 'high', $tier );

		remove_all_filters( 'wp_mcp_ai_workload_tier' );
	}

	/**
	 * Test that max_tokens filter works correctly.
	 */
	public function test_max_tokens_filter() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		add_filter(
			'wp_mcp_ai_resource_max_tokens',
			function ( $max_tokens, $tier ) {
				return 5000;
			},
			10,
			2
		);

		$max_tokens = $manager->get_max_tokens();

		$this->assertSame( 5000, $max_tokens );

		remove_all_filters( 'wp_mcp_ai_resource_max_tokens' );
	}

	/**
	 * Test that request_timeout filter works correctly.
	 */
	public function test_request_timeout_filter() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		add_filter(
			'wp_mcp_ai_resource_request_timeout',
			function ( $timeout, $tier, $max_execution_time, $ignore_execution_time ) {
				return 90;
			},
			10,
			4
		);

		$timeout = $manager->get_request_timeout();

		$this->assertSame( 90, $timeout );

		remove_all_filters( 'wp_mcp_ai_resource_request_timeout' );
	}

	/**
	 * Test that get_request_timeout respects ignore_execution_time parameter.
	 */
	public function test_get_request_timeout_ignores_execution_time_when_requested() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// Get timeout with execution time constraint (default).
		$timeout_with_constraint = $manager->get_request_timeout( false );

		// Get timeout without execution time constraint.
		$timeout_without_constraint = $manager->get_request_timeout( true );

		// Both should be integers and at least 5 seconds.
		$this->assertIsInt( $timeout_with_constraint );
		$this->assertIsInt( $timeout_without_constraint );
		$this->assertGreaterThanOrEqual( 5, $timeout_with_constraint );
		$this->assertGreaterThanOrEqual( 5, $timeout_without_constraint );

		// When ignoring execution time, timeout should not be capped by max_execution_time.
		// It should match the base timeout for the tier (30, 60, or 120).
		$this->assertContains( $timeout_without_constraint, array( 30, 60, 120 ) );
	}

	/**
	 * Test that get_max_tokens reads from orchestration settings.
	 */
	public function test_get_max_tokens_reads_from_orchestration_settings() {
		$manager = WP_MCP_AI_Resource_Manager::instance();
		$tier    = $manager->get_workload_tier();

		// Set a custom value in orchestration settings.
		$custom_value = 15000;
		$setting_key  = $tier . '_tier_max_tokens';
		update_option( 'wp_mcp_ai_settings', array( $setting_key => $custom_value ) );

		// Clear any cached values.
		wp_cache_flush();

		// Get max tokens - should read from settings.
		$max_tokens = $manager->get_max_tokens();

		// Should return the custom value from settings.
		$this->assertSame( $custom_value, $max_tokens );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that get_max_tokens falls back to defaults when settings not available.
	 */
	public function test_get_max_tokens_falls_back_to_defaults() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// Ensure no settings exist.
		delete_option( 'wp_mcp_ai_settings' );
		wp_cache_flush();

		// Get max tokens - should use defaults.
		$max_tokens = $manager->get_max_tokens();

		// Should return one of the modern default values.
		$this->assertContains( $max_tokens, array( 2000, 8000, 32000 ) );
	}
}
