<?php
/**
 * Tests for stock metric registration.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock metric tests.
 */
class Test_WP_MCP_AI_Stock_Metrics extends WP_UnitTestCase {

	/**
	 * Test definitions include expected metrics.
	 */
	public function test_definitions_include_expected_metrics() {
		$defs = WP_MCP_AI_Stock_Metrics::definitions();
		$ids  = array_column( $defs, 'id' );

		$this->assertContains( 'tool.execution.count', $ids );
		$this->assertContains( 'tool.execution.duration_ms', $ids );
		$this->assertContains( 'tool.execution.success.count', $ids );
		$this->assertContains( 'tool.execution.error.count', $ids );
		$this->assertContains( 'tool.execution.in_flight', $ids );
	}

	/**
	 * Test every stock metric declares counter.
	 */
	public function test_every_stock_metric_declares_counter() {
		// The measurement registry's Goodhart policy: every metric must
		// pair with a counter_metric. Guarding this in a test prevents
		// future additions from silently regressing.
		foreach ( WP_MCP_AI_Stock_Metrics::definitions() as $def ) {
			$this->assertNotEmpty(
				$def['counter_metric'],
				sprintf( 'Stock metric %s is missing a counter_metric pairing.', $def['id'] )
			);
		}
	}

	/**
	 * Test filter can suppress all stock metrics.
	 */
	public function test_filter_can_suppress_all_stock_metrics() {
		$filter = static function () {
			return array();
		};
		add_filter( 'wp_mcp_ai_stock_metrics_definitions', $filter );
		$this->assertSame( array(), WP_MCP_AI_Stock_Metrics::definitions() );
		remove_filter( 'wp_mcp_ai_stock_metrics_definitions', $filter );
	}

	/**
	 * Test filter non array return ignored.
	 */
	public function test_filter_non_array_return_ignored() {
		$filter = static function () {
			return 'not an array';
		};
		add_filter( 'wp_mcp_ai_stock_metrics_definitions', $filter );
		$defs = WP_MCP_AI_Stock_Metrics::definitions();
		$this->assertIsArray( $defs );
		$this->assertNotEmpty( $defs );
		remove_filter( 'wp_mcp_ai_stock_metrics_definitions', $filter );
	}

	/**
	 * Test register returns count of new registrations.
	 */
	public function test_register_returns_count_of_new_registrations() {
		WP_MCP_AI_Measurement_Registry::reset_instance();
		$registry = WP_MCP_AI_Measurement_Registry::get_instance();
		$count    = WP_MCP_AI_Stock_Metrics::register( $registry );
		$this->assertGreaterThanOrEqual( 5, $count );
		$this->assertNotNull( $registry->get( 'tool.execution.count' ) );
		WP_MCP_AI_Measurement_Registry::reset_instance();
	}

	/**
	 * Test register non registry returns zero.
	 */
	public function test_register_non_registry_returns_zero() {
		$this->assertSame( 0, WP_MCP_AI_Stock_Metrics::register( 'not a registry' ) );
	}
}
