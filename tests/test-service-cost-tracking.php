<?php
/**
 * Tests for WP_MCP_AI_Cost_Tracking_Service.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Cost_Tracking_Service.
 */
class Test_Service_Cost_Tracking extends WP_UnitTestCase {

	/**
	 * Test that get_dashboard_cost_summary returns an array with all required keys.
	 *
	 * When no tracking database class is present the method short-circuits and
	 * returns zeros — we just assert the shape is correct.
	 */
	public function test_get_dashboard_cost_summary_returns_required_keys() {
		$result = WP_MCP_AI_Cost_Tracking_Service::get_dashboard_cost_summary( 7 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total_cost', $result );
		$this->assertArrayHasKey( 'total_tokens', $result );
		$this->assertArrayHasKey( 'by_provider', $result );
		$this->assertArrayHasKey( 'by_model', $result );
		$this->assertArrayHasKey( 'by_tool', $result );
		$this->assertArrayHasKey( 'period_start', $result );
		$this->assertArrayHasKey( 'period_end', $result );
	}

	/**
	 * Test that get_dashboard_cost_summary total_cost is a float.
	 */
	public function test_get_dashboard_cost_summary_total_cost_is_float() {
		$result = WP_MCP_AI_Cost_Tracking_Service::get_dashboard_cost_summary( 7 );
		$this->assertIsFloat( $result['total_cost'] );
	}

	/**
	 * Test that get_cost_trend_data returns chart-ready labels and datasets arrays.
	 */
	public function test_get_cost_trend_data_returns_chart_structure() {
		$result = WP_MCP_AI_Cost_Tracking_Service::get_cost_trend_data( 7 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'labels', $result );
		$this->assertArrayHasKey( 'datasets', $result );
		$this->assertIsArray( $result['labels'] );
		$this->assertIsArray( $result['datasets'] );
	}

	/**
	 * Test that get_cost_trend_data labels count matches requested days + 1.
	 */
	public function test_get_cost_trend_data_labels_span_requested_days() {
		$days   = 7;
		$result = WP_MCP_AI_Cost_Tracking_Service::get_cost_trend_data( $days );

		// Labels cover start_date through end_date inclusive = days + 1.
		$this->assertCount( $days + 1, $result['labels'] );
	}

	/**
	 * Test that get_cost_by_provider_data returns correct structure when no data exists.
	 */
	public function test_get_cost_by_provider_data_returns_empty_structure_when_no_data() {
		$result = WP_MCP_AI_Cost_Tracking_Service::get_cost_by_provider_data( 7 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'labels', $result );
		$this->assertArrayHasKey( 'datasets', $result );
	}

	/**
	 * Test that get_site_cost_breakdown returns zeroed totals when DB class absent.
	 */
	public function test_get_site_cost_breakdown_returns_zeroed_totals_without_db() {
		if ( class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Token_Tracking_Database is present; cannot test fallback path.' );
		}

		$result = WP_MCP_AI_Cost_Tracking_Service::get_site_cost_breakdown( '2025-01-01', '2025-01-31' );

		$this->assertIsArray( $result );
		$this->assertSame( 0.0, $result['total_cost'] );
		$this->assertSame( 0, $result['total_tokens'] );
		$this->assertIsArray( $result['by_provider'] );
		$this->assertIsArray( $result['by_model'] );
		$this->assertIsArray( $result['by_tool'] );
	}

	/**
	 * Test that get_dashboard_cost_summary period dates are valid date strings.
	 */
	public function test_get_dashboard_cost_summary_period_dates_are_valid() {
		$result = WP_MCP_AI_Cost_Tracking_Service::get_dashboard_cost_summary( 30 );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $result['period_start'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $result['period_end'] );
	}
}
