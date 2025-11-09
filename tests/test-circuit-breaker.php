<?php
/**
 * Tests for Circuit Breaker pattern.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test Circuit Breaker functionality.
 */
class Test_Circuit_Breaker extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clear all circuit breaker transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_circuit_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_mcp_ai_circuit_%'" );
		parent::tearDown();
	}

	/**
	 * Test circuit is initially available.
	 */
	public function test_circuit_initially_available() {
		$this->assertTrue( WP_MCP_AI_Circuit_Breaker::is_available( 'openai' ) );
	}

	/**
	 * Test circuit opens after failure threshold.
	 */
	public function test_circuit_opens_after_failures() {
		$provider = 'test_provider';

		// Record failures up to threshold (default 5).
		for ( $i = 0; $i < 5; $i++ ) {
			WP_MCP_AI_Circuit_Breaker::record_failure( $provider, array( 'error' => 'Test error ' . $i ) );
		}

		// Circuit should now be open.
		$this->assertFalse( WP_MCP_AI_Circuit_Breaker::is_available( $provider ) );
	}

	/**
	 * Test circuit closes after successful requests in half-open state.
	 */
	public function test_circuit_closes_after_half_open_success() {
		$provider = 'test_provider_2';

		// Open the circuit.
		for ( $i = 0; $i < 5; $i++ ) {
			WP_MCP_AI_Circuit_Breaker::record_failure( $provider );
		}

		$this->assertFalse( WP_MCP_AI_Circuit_Breaker::is_available( $provider ) );

		// Manually transition to half-open by simulating timeout expiration.
		// We'll use a filter to set a very short timeout.
		add_filter( 'wp_mcp_ai_circuit_breaker_timeout', function () {
			return 0;
		} );

		// Now circuit should be half-open.
		$this->assertTrue( WP_MCP_AI_Circuit_Breaker::is_available( $provider ) );

		// Record successes (default threshold is 2).
		WP_MCP_AI_Circuit_Breaker::record_success( $provider );
		WP_MCP_AI_Circuit_Breaker::record_success( $provider );

		// Circuit should now be closed.
		$metrics = WP_MCP_AI_Circuit_Breaker::get_health_metrics( $provider );
		$this->assertTrue( $metrics['is_available'] );
	}

	/**
	 * Test circuit reopens on failure in half-open state.
	 */
	public function test_circuit_reopens_on_half_open_failure() {
		$provider = 'test_provider_3';

		// Open the circuit.
		for ( $i = 0; $i < 5; $i++ ) {
			WP_MCP_AI_Circuit_Breaker::record_failure( $provider );
		}

		// Force half-open.
		add_filter( 'wp_mcp_ai_circuit_breaker_timeout', function () {
			return 0;
		} );

		// Record a failure in half-open state.
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider );

		// Circuit should be open again.
		remove_all_filters( 'wp_mcp_ai_circuit_breaker_timeout' );
		$this->assertFalse( WP_MCP_AI_Circuit_Breaker::is_available( $provider ) );
	}

	/**
	 * Test reset circuit clears state.
	 */
	public function test_reset_circuit() {
		$provider = 'test_provider_reset';

		// Open the circuit.
		for ( $i = 0; $i < 5; $i++ ) {
			WP_MCP_AI_Circuit_Breaker::record_failure( $provider );
		}

		$this->assertFalse( WP_MCP_AI_Circuit_Breaker::is_available( $provider ) );

		// Reset the circuit.
		WP_MCP_AI_Circuit_Breaker::reset_circuit( $provider );

		// Circuit should be available again.
		$this->assertTrue( WP_MCP_AI_Circuit_Breaker::is_available( $provider ) );
	}

	/**
	 * Test get_health_metrics returns correct data.
	 */
	public function test_get_health_metrics() {
		$provider = 'test_provider_metrics';

		// Record some failures.
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider, array( 'status' => 500 ) );
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider, array( 'status' => 503 ) );

		$metrics = WP_MCP_AI_Circuit_Breaker::get_health_metrics( $provider );

		$this->assertIsArray( $metrics );
		$this->assertSame( $provider, $metrics['provider'] );
		$this->assertArrayHasKey( 'state', $metrics );
		$this->assertArrayHasKey( 'failure_count', $metrics );
		$this->assertArrayHasKey( 'recent_failures', $metrics );
		$this->assertArrayHasKey( 'is_available', $metrics );

		// Should have 2 failures recorded.
		$this->assertSame( 2, $metrics['failure_count'] );
		$this->assertTrue( $metrics['is_available'] ); // Not enough to open circuit yet.
	}

	/**
	 * Test success in closed state resets failure count.
	 */
	public function test_success_resets_failures() {
		$provider = 'test_provider_success';

		// Record a few failures.
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider );
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider );

		$metrics = WP_MCP_AI_Circuit_Breaker::get_health_metrics( $provider );
		$this->assertSame( 2, $metrics['failure_count'] );

		// Record a success.
		WP_MCP_AI_Circuit_Breaker::record_success( $provider );

		// Failure count should be reset.
		$metrics = WP_MCP_AI_Circuit_Breaker::get_health_metrics( $provider );
		$this->assertSame( 0, $metrics['failure_count'] );
	}

	/**
	 * Test failures outside window are not counted.
	 */
	public function test_failure_window_expiration() {
		$provider = 'test_provider_window';

		// Use a very short window for testing.
		add_filter( 'wp_mcp_ai_circuit_breaker_window_size', function () {
			return 2; // 2 seconds.
		} );

		// Record failures.
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider );
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider );

		$metrics = WP_MCP_AI_Circuit_Breaker::get_health_metrics( $provider );
		$this->assertSame( 2, $metrics['failure_count'] );

		// Wait for window to expire.
		sleep( 3 );

		// Record another failure - this should trigger cleanup of old failures.
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider );

		$metrics = WP_MCP_AI_Circuit_Breaker::get_health_metrics( $provider );
		// Only the new failure should be counted.
		$this->assertSame( 1, $metrics['failure_count'] );
	}

	/**
	 * Test custom failure threshold via filter.
	 */
	public function test_custom_failure_threshold() {
		$provider = 'test_provider_threshold';

		// Set custom threshold to 3.
		add_filter( 'wp_mcp_ai_circuit_breaker_failure_threshold', function () {
			return 3;
		} );

		// Record 2 failures - circuit should stay closed.
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider );
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider );

		$this->assertTrue( WP_MCP_AI_Circuit_Breaker::is_available( $provider ) );

		// Record 1 more failure - circuit should open.
		WP_MCP_AI_Circuit_Breaker::record_failure( $provider );

		$this->assertFalse( WP_MCP_AI_Circuit_Breaker::is_available( $provider ) );
	}
}
