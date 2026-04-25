<?php
/**
 * Tests for SSE stock metric registration.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SSE stock metric tests.
 */
class Test_WP_MCP_AI_SSE_Metrics extends WP_UnitTestCase {

	/**
	 * Test definitions include expected metrics.
	 */
	public function test_definitions_include_expected_metrics() {
		$defs = WP_MCP_AI_SSE_Metrics::definitions();
		$ids  = array_column( $defs, 'id' );

		$this->assertContains( 'stream.count', $ids );
		$this->assertContains( 'stream.error.count', $ids );
		$this->assertContains( 'stream.cancelled.count', $ids );
		$this->assertContains( 'stream.ttfb_ms', $ids );
		$this->assertContains( 'stream.chunk_interval_ms', $ids );
		$this->assertContains( 'stream.total_duration_ms', $ids );
		$this->assertContains( 'stream.chunks.count', $ids );
	}

	/**
	 * Every SSE metric declares a counter pairing.
	 */
	public function test_every_sse_metric_declares_counter() {
		foreach ( WP_MCP_AI_SSE_Metrics::definitions() as $def ) {
			$this->assertNotEmpty(
				$def['counter_metric'],
				sprintf( 'SSE metric %s is missing a counter_metric pairing.', $def['id'] )
			);
		}
	}

	/**
	 * Every SSE metric stays in the internal privacy tier.
	 */
	public function test_every_sse_metric_is_internal_tier() {
		foreach ( WP_MCP_AI_SSE_Metrics::definitions() as $def ) {
			$this->assertSame(
				WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				$def['privacy_tier'],
				sprintf( 'SSE metric %s must stay in the internal privacy tier; richer payloads require registry re-classification.', $def['id'] )
			);
		}
	}

	/**
	 * Cancellation is a first-class outcome, not an error.
	 *
	 * Guards against the anti-pattern of bundling cancellations into
	 * stream.error.count — it must have its own metric so quality
	 * regressions are distinguishable from user behaviour.
	 */
	public function test_cancelled_count_is_separate_from_error_count() {
		$defs = array_column( WP_MCP_AI_SSE_Metrics::definitions(), null, 'id' );

		$this->assertArrayHasKey( 'stream.cancelled.count', $defs );
		$this->assertArrayHasKey( 'stream.error.count', $defs );
		$this->assertNotSame(
			$defs['stream.cancelled.count']['id'],
			$defs['stream.error.count']['id']
		);
		// Cancelled is neutral direction, error is lower-is-better.
		$this->assertSame( WP_MCP_AI_Measurement_Registry::DIRECTION_NEUTRAL, $defs['stream.cancelled.count']['direction'] );
		$this->assertSame( WP_MCP_AI_Measurement_Registry::DIRECTION_LOWER_IS_BETTER, $defs['stream.error.count']['direction'] );
	}

	/**
	 * Filter can suppress all SSE metrics.
	 */
	public function test_filter_can_suppress_all_sse_metrics() {
		$filter = static function () {
			return array();
		};
		add_filter( 'wp_mcp_ai_sse_metrics_definitions', $filter );
		$this->assertSame( array(), WP_MCP_AI_SSE_Metrics::definitions() );
		remove_filter( 'wp_mcp_ai_sse_metrics_definitions', $filter );
	}

	/**
	 * Non-array filter return is ignored (defensive).
	 */
	public function test_filter_non_array_return_ignored() {
		$filter = static function () {
			return 'not an array';
		};
		add_filter( 'wp_mcp_ai_sse_metrics_definitions', $filter );
		$defs = WP_MCP_AI_SSE_Metrics::definitions();
		$this->assertIsArray( $defs );
		$this->assertNotEmpty( $defs );
		remove_filter( 'wp_mcp_ai_sse_metrics_definitions', $filter );
	}

	/**
	 * Register returns count of new registrations.
	 */
	public function test_register_returns_count_of_new_registrations() {
		WP_MCP_AI_Measurement_Registry::reset_instance();
		$registry = WP_MCP_AI_Measurement_Registry::get_instance();
		$count    = WP_MCP_AI_SSE_Metrics::register( $registry );
		$this->assertGreaterThanOrEqual( 7, $count );
		$this->assertNotNull( $registry->get( 'stream.count' ) );
		$this->assertNotNull( $registry->get( 'stream.ttfb_ms' ) );
		$this->assertNotNull( $registry->get( 'stream.cancelled.count' ) );
		WP_MCP_AI_Measurement_Registry::reset_instance();
	}

	/**
	 * Register ignores non-registry arguments.
	 */
	public function test_register_non_registry_returns_zero() {
		$this->assertSame( 0, WP_MCP_AI_SSE_Metrics::register( 'not a registry' ) );
	}
}
