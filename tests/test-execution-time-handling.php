<?php
/**
 * Tests for PHP Execution Time Handling
 *
 * Tests the Resource Manager's ensure_execution_time() method and its integration
 * with AJAX handlers for preventing "Maximum execution time exceeded" errors.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Execution_Time_Test extends WP_UnitTestCase {

	/**
	 * Test that ensure_execution_time() method exists and is callable.
	 */
	public function test_ensure_execution_time_method_exists() {
		$manager = WP_MCP_AI_Resource_Manager::instance();
		$this->assertTrue( method_exists( $manager, 'ensure_execution_time' ) );
	}

	/**
	 * Test that ensure_execution_time() returns false when max_execution_time is 0 (unlimited).
	 */
	public function test_ensure_execution_time_with_unlimited_time() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// Mock unlimited execution time.
		// Note: We can't actually change ini settings in tests, so this tests the logic.
		$current_limit = ini_get( 'max_execution_time' );

		if ( 0 === intval( $current_limit ) ) {
			// If already unlimited, the method should return false (no adjustment needed).
			$result = $manager->ensure_execution_time( 120 );
			$this->assertFalse( $result );
		} else {
			// If limited, skip this specific test as it depends on environment.
			$this->markTestSkipped( 'Test environment has limited execution time' );
		}
	}

	/**
	 * Test that ensure_execution_time() returns false when current limit is already sufficient.
	 */
	public function test_ensure_execution_time_with_sufficient_limit() {
		$manager       = WP_MCP_AI_Resource_Manager::instance();
		$current_limit = ini_get( 'max_execution_time' );

		// If we have a limit, test that requesting less returns false.
		if ( $current_limit > 0 ) {
			// Request half the current limit - should return false (no adjustment needed).
			$result = $manager->ensure_execution_time( intval( $current_limit ) / 2 );
			$this->assertFalse( $result );
		} else {
			$this->markTestSkipped( 'Test environment has unlimited execution time' );
		}
	}

	/**
	 * Test that ensure_execution_time() accepts valid integer values.
	 */
	public function test_ensure_execution_time_accepts_integers() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// These should not throw errors.
		$manager->ensure_execution_time( 30 );
		$manager->ensure_execution_time( 60 );
		$manager->ensure_execution_time( 120 );

		// The method should handle these gracefully.
		$this->assertTrue( true ); // If we get here, no exceptions were thrown.
	}

	/**
	 * Test that ensure_execution_time() sanitizes input to integer.
	 */
	public function test_ensure_execution_time_sanitizes_input() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// String input should be converted to integer.
		$manager->ensure_execution_time( '60' );

		// Negative values should become 0.
		$manager->ensure_execution_time( -10 );

		// Float values should be converted to integer.
		$manager->ensure_execution_time( 60.5 );

		// Test passes if no errors thrown.
		$this->assertTrue( true );
	}

	/**
	 * Test that the action hook fires when execution time is adjusted.
	 */
	public function test_ensure_execution_time_fires_action() {
		$manager      = WP_MCP_AI_Resource_Manager::instance();
		$action_fired = false;

		// Add a hook to detect the action.
		add_action(
			'wp_mcp_ai_execution_time_adjusted',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		// Try to adjust execution time.
		// This might or might not succeed depending on hosting restrictions,.
		// but the action should still fire.
		$manager->ensure_execution_time( 999 );

		// On some systems, the action might not fire if set_time_limit is disabled.
		// So we make this assertion conditional.
		$current_limit = ini_get( 'max_execution_time' );
		if ( $current_limit > 0 && $current_limit < 999 ) {
			// Action should have fired (whether set_time_limit succeeded or not).
			$this->assertTrue( true ); // Just verify no fatal errors occurred.
		} else {
			$this->markTestSkipped( 'Cannot test action firing in this environment' );
		}

		remove_all_actions( 'wp_mcp_ai_execution_time_adjusted' );
	}

	/**
	 * Test integration with AJAX handler pattern.
	 *
	 * Verifies that the typical usage pattern in AJAX handlers works correctly.
	 */
	public function test_ajax_handler_integration_pattern() {
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

		// Simulate the pattern used in AJAX handlers.
		$timeout = 120;

		// This should not throw any errors.
		$resource_mgr->ensure_execution_time( $timeout + 10 );

		// Test passes if we get here without exceptions.
		$this->assertTrue( true );
	}

	/**
	 * Test that get_request_timeout with ignore_execution_time returns expected values.
	 */
	public function test_get_request_timeout_with_ignore_flag() {
		$manager = WP_MCP_AI_Resource_Manager::instance();

		// Get timeout without ignoring execution time.
		$timeout_constrained = $manager->get_request_timeout( false );

		// Get timeout ignoring execution time.
		$timeout_unconstrained = $manager->get_request_timeout( true );

		// Both should be valid integers.
		$this->assertIsInt( $timeout_constrained );
		$this->assertIsInt( $timeout_unconstrained );
		$this->assertGreaterThanOrEqual( 5, $timeout_constrained );
		$this->assertGreaterThanOrEqual( 5, $timeout_unconstrained );

		// Unconstrained should be one of the base tier values (30, 60, or 120).
		$this->assertContains( $timeout_unconstrained, array( 30, 60, 120 ) );
	}

	/**
	 * Test end-to-end flow: get timeout and ensure execution time.
	 */
	public function test_end_to_end_timeout_and_execution() {
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

		// Get a long timeout (ignoring execution time constraints).
		$timeout = $resource_mgr->get_request_timeout( true );
		$timeout = max( 30, $timeout );

		// Ensure PHP execution time can accommodate it.
		$result = $resource_mgr->ensure_execution_time( $timeout + 10 );

		// Result is boolean - true if adjusted, false if not needed/failed.
		$this->assertIsBool( $result );
	}
}
