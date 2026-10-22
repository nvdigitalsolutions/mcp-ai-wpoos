<?php
/**
 * Tests the cost + analytics REST surfaces (Proposal 084 Phase 5b):
 * GET /mcp-ai/v1/cost/{dashboard-summary,trend,by-provider} and the
 * analytics-manager routes — all manage_options-gated and wrapping the
 * existing cost-tracking service / analytics engine (zero-network).
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Cost_Manager_Test extends WP_UnitTestCase {

	/**
	 * Non-admins are refused on the site-wide cost endpoints.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = WP_MCP_AI_REST_Cost_Manager::check_admin_permission();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The dashboard summary carries the full widget-parity envelope.
	 */
	public function test_admin_dashboard_summary_shape() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cost/dashboard-summary' );
		$request->set_param( 'days', 7 );

		$response = WP_MCP_AI_REST_Cost_Manager::get_dashboard_summary( $request );
		$data     = $response->get_data();

		$this->assertSame( 7, $data['days'] );
		foreach (
			array(
				'total_cost',
				'total_tokens',
				'by_provider',
				'by_model',
				'by_tool',
				'period_start',
				'period_end',
				'top_tools',
				'top_users',
			) as $key
		) {
			$this->assertArrayHasKey( $key, $data['summary'], "Missing summary key: {$key}" );
		}
		$this->assertIsFloat( $data['summary']['total_cost'] );
		$this->assertIsInt( $data['summary']['total_tokens'] );
	}

	/**
	 * The cost trend is chart-ready: one label + data point per day,
	 * inclusive of today (zero-filled for costless days).
	 */
	public function test_trend_chart_shape() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cost/trend' );
		$request->set_param( 'days', 7 );

		$response = WP_MCP_AI_REST_Cost_Manager::get_cost_trend( $request );
		$data     = $response->get_data();

		$this->assertSame( 7, $data['days'] );
		$this->assertCount( 8, $data['data']['labels'] );
		$this->assertCount( 8, $data['data']['datasets'][0]['data'] );
		foreach ( $data['data']['datasets'][0]['data'] as $value ) {
			$this->assertIsFloat( $value );
		}
	}

	/**
	 * The provider breakdown is chart-ready (labels aligned with values).
	 */
	public function test_by_provider_chart_shape() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cost/by-provider' );
		$request->set_param( 'days', 30 );

		$response = WP_MCP_AI_REST_Cost_Manager::get_cost_by_provider( $request );
		$data     = $response->get_data();

		$this->assertSame( 30, $data['days'] );
		$this->assertArrayHasKey( 'labels', $data['data'] );
		$this->assertArrayHasKey( 'datasets', $data['data'] );
		$this->assertCount(
			count( $data['data']['labels'] ),
			$data['data']['datasets'][0]['data']
		);
	}

	/**
	 * The cost + analytics routes are registered under the base namespace.
	 */
	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes( 'mcp-ai/v1' );

		if ( ! isset( $routes['/mcp-ai/v1/cost/dashboard-summary'] ) ) {
			add_action(
				'rest_api_init',
				function () {
					WP_MCP_AI_REST_Cost_Manager::register_routes();
					WP_MCP_AI_REST_Analytics_Manager::register_routes();
				}
			);
			$GLOBALS['wp_rest_server'] = null;
			rest_get_server();
			$routes = rest_get_server()->get_routes( 'mcp-ai/v1' );
		}

		$this->assertArrayHasKey( '/mcp-ai/v1/cost/dashboard-summary', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/cost/trend', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/cost/by-provider', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/analytics/anomalies', $routes );
	}
}
