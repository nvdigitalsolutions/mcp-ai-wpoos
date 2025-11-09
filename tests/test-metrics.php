<?php
/**
 * Tests for Metrics and Observability.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test Metrics functionality.
 */
class Test_Metrics extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Reset all metrics.
		WP_MCP_AI_Metrics::reset();
		parent::tearDown();
	}

	/**
	 * Test increment creates and increments counters.
	 */
	public function test_increment_counter() {
		$category = WP_MCP_AI_Metrics::CATEGORY_API_CALLS;
		$metric   = 'total';
		$tags     = array( 'provider' => 'openai' );

		// Initially should be 0.
		$value = WP_MCP_AI_Metrics::get( $category, $metric, $tags );
		$this->assertSame( 0, $value );

		// Increment once.
		WP_MCP_AI_Metrics::increment( $category, $metric, 1, $tags );
		$value = WP_MCP_AI_Metrics::get( $category, $metric, $tags );
		$this->assertSame( 1, $value );

		// Increment again.
		WP_MCP_AI_Metrics::increment( $category, $metric, 1, $tags );
		$value = WP_MCP_AI_Metrics::get( $category, $metric, $tags );
		$this->assertSame( 2, $value );

		// Increment by 3.
		WP_MCP_AI_Metrics::increment( $category, $metric, 3, $tags );
		$value = WP_MCP_AI_Metrics::get( $category, $metric, $tags );
		$this->assertSame( 5, $value );
	}

	/**
	 * Test metrics with different tags are independent.
	 */
	public function test_metrics_with_different_tags_independent() {
		$category = WP_MCP_AI_Metrics::CATEGORY_FAILURES;
		$metric   = 'total';

		WP_MCP_AI_Metrics::increment( $category, $metric, 1, array( 'provider' => 'openai' ) );
		WP_MCP_AI_Metrics::increment( $category, $metric, 2, array( 'provider' => 'gemini' ) );

		$openai_value = WP_MCP_AI_Metrics::get( $category, $metric, array( 'provider' => 'openai' ) );
		$gemini_value = WP_MCP_AI_Metrics::get( $category, $metric, array( 'provider' => 'gemini' ) );

		$this->assertSame( 1, $openai_value );
		$this->assertSame( 2, $gemini_value );
	}

	/**
	 * Test record_timing stores timing statistics.
	 */
	public function test_record_timing() {
		$category = WP_MCP_AI_Metrics::CATEGORY_API_CALLS;
		$metric   = 'chat_completion';
		$tags     = array( 'provider' => 'openai' );

		// Record some timings.
		WP_MCP_AI_Metrics::record_timing( $category, $metric, 1.5, $tags );
		WP_MCP_AI_Metrics::record_timing( $category, $metric, 2.0, $tags );
		WP_MCP_AI_Metrics::record_timing( $category, $metric, 1.0, $tags );

		$stats = WP_MCP_AI_Metrics::get_timing( $category, $metric, $tags );

		$this->assertIsArray( $stats );
		$this->assertSame( 3, $stats['count'] );
		$this->assertSame( 4.5, $stats['total'] );
		$this->assertSame( 1.0, $stats['min'] );
		$this->assertSame( 2.0, $stats['max'] );
		$this->assertSame( 1.5, $stats['average'] );
	}

	/**
	 * Test record_api_call helper method.
	 */
	public function test_record_api_call() {
		$provider = 'openai';
		$endpoint = 'chat_completion';

		// Successful call.
		WP_MCP_AI_Metrics::record_api_call( $provider, $endpoint, true, 1.2, array() );

		// Failed call.
		WP_MCP_AI_Metrics::record_api_call(
			$provider,
			$endpoint,
			false,
			0.8,
			array( 'type' => 'timeout' )
		);

		// Check counters.
		$total   = WP_MCP_AI_Metrics::get(
			WP_MCP_AI_Metrics::CATEGORY_API_CALLS,
			'total',
			array( 'provider' => $provider, 'endpoint' => $endpoint )
		);
		$success = WP_MCP_AI_Metrics::get(
			WP_MCP_AI_Metrics::CATEGORY_API_CALLS,
			'success',
			array( 'provider' => $provider, 'endpoint' => $endpoint )
		);
		$failure = WP_MCP_AI_Metrics::get(
			WP_MCP_AI_Metrics::CATEGORY_FAILURES,
			'total',
			array( 'provider' => $provider, 'endpoint' => $endpoint )
		);

		$this->assertSame( 2, $total );
		$this->assertSame( 1, $success );
		$this->assertSame( 1, $failure );

		// Check timing stats.
		$timing = WP_MCP_AI_Metrics::get_timing(
			WP_MCP_AI_Metrics::CATEGORY_API_CALLS,
			$endpoint,
			array( 'provider' => $provider, 'endpoint' => $endpoint )
		);

		$this->assertSame( 2, $timing['count'] );
		$this->assertSame( 2.0, $timing['total'] );
	}

	/**
	 * Test record_timeout helper method.
	 */
	public function test_record_timeout() {
		$provider = 'gemini';
		$endpoint = 'chat_completion';

		WP_MCP_AI_Metrics::record_timeout( $provider, $endpoint );
		WP_MCP_AI_Metrics::record_timeout( $provider, $endpoint );

		$count = WP_MCP_AI_Metrics::get(
			WP_MCP_AI_Metrics::CATEGORY_TIMEOUTS,
			'total',
			array( 'provider' => $provider, 'endpoint' => $endpoint )
		);

		$this->assertSame( 2, $count );
	}

	/**
	 * Test record_retry helper method.
	 */
	public function test_record_retry() {
		$provider = 'openai';

		WP_MCP_AI_Metrics::record_retry( $provider, 1 );
		WP_MCP_AI_Metrics::record_retry( $provider, 2 );
		WP_MCP_AI_Metrics::record_retry( $provider, 2 );

		$total     = WP_MCP_AI_Metrics::get(
			WP_MCP_AI_Metrics::CATEGORY_RETRIES,
			'total',
			array( 'provider' => $provider )
		);
		$attempt_1 = WP_MCP_AI_Metrics::get(
			WP_MCP_AI_Metrics::CATEGORY_RETRIES,
			'attempt_1',
			array( 'provider' => $provider )
		);
		$attempt_2 = WP_MCP_AI_Metrics::get(
			WP_MCP_AI_Metrics::CATEGORY_RETRIES,
			'attempt_2',
			array( 'provider' => $provider )
		);

		$this->assertSame( 3, $total );
		$this->assertSame( 1, $attempt_1 );
		$this->assertSame( 2, $attempt_2 );
	}

	/**
	 * Test record_circuit_state helper method.
	 */
	public function test_record_circuit_state() {
		$provider = 'openai';

		WP_MCP_AI_Metrics::record_circuit_state( $provider, 'open' );
		WP_MCP_AI_Metrics::record_circuit_state( $provider, 'half_open' );
		WP_MCP_AI_Metrics::record_circuit_state( $provider, 'closed' );

		$state_changes = WP_MCP_AI_Metrics::get(
			WP_MCP_AI_Metrics::CATEGORY_CIRCUIT,
			'state_change',
			array( 'provider' => $provider )
		);
		$open_count    = WP_MCP_AI_Metrics::get(
			WP_MCP_AI_Metrics::CATEGORY_CIRCUIT,
			'open',
			array( 'provider' => $provider )
		);

		$this->assertSame( 3, $state_changes );
		$this->assertSame( 1, $open_count );
	}

	/**
	 * Test get_category_metrics returns all metrics in category.
	 */
	public function test_get_category_metrics() {
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'type_a', 5 );
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'type_b', 3 );

		$metrics = WP_MCP_AI_Metrics::get_category_metrics( WP_MCP_AI_Metrics::CATEGORY_FAILURES );

		$this->assertIsArray( $metrics );
		$this->assertNotEmpty( $metrics );
	}

	/**
	 * Test get_metrics_summary returns all categories.
	 */
	public function test_get_metrics_summary() {
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total', 10 );
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total', 2 );

		$summary = WP_MCP_AI_Metrics::get_metrics_summary();

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'api_calls', $summary );
		$this->assertArrayHasKey( 'failures', $summary );
		$this->assertArrayHasKey( 'timeouts', $summary );
		$this->assertArrayHasKey( 'retries', $summary );
		$this->assertArrayHasKey( 'circuit_breaker', $summary );
	}

	/**
	 * Test reset clears all metrics.
	 */
	public function test_reset_all_metrics() {
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total', 10 );
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total', 5 );

		// Verify metrics exist.
		$value1 = WP_MCP_AI_Metrics::get( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total' );
		$value2 = WP_MCP_AI_Metrics::get( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total' );
		$this->assertSame( 10, $value1 );
		$this->assertSame( 5, $value2 );

		// Reset all.
		WP_MCP_AI_Metrics::reset();

		// Verify metrics are cleared.
		$value1 = WP_MCP_AI_Metrics::get( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total' );
		$value2 = WP_MCP_AI_Metrics::get( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total' );
		$this->assertSame( 0, $value1 );
		$this->assertSame( 0, $value2 );
	}

	/**
	 * Test reset specific category.
	 */
	public function test_reset_specific_category() {
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total', 10 );
		WP_MCP_AI_Metrics::increment( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total', 5 );

		// Reset only failures category.
		WP_MCP_AI_Metrics::reset( WP_MCP_AI_Metrics::CATEGORY_FAILURES );

		// API calls should still exist.
		$value1 = WP_MCP_AI_Metrics::get( WP_MCP_AI_Metrics::CATEGORY_API_CALLS, 'total' );
		$this->assertSame( 10, $value1 );

		// Failures should be cleared.
		$value2 = WP_MCP_AI_Metrics::get( WP_MCP_AI_Metrics::CATEGORY_FAILURES, 'total' );
		$this->assertSame( 0, $value2 );
	}
}
