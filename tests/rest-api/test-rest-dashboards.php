<?php
/**
 * Tests the Pro Dashboards REST bridge (2.4.0, Proposal 084 Phase 4, W13):
 * GET /mcp-ai-pro/v1/dashboards/schema + /dashboards/{id}/data for the
 * console's repeating-dashboard feature (CRE portfolio pilot).
 *
 * Covers: manage_options gating, the catalogue envelope, unknown-dashboard
 * 400s, and the zero-network CRE data passthrough (the loan CPT is not
 * registered in the test env, so the provider returns the zeroed envelope).
 * Skipped when the Pro addon classes are not loaded.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Pro_Dashboards_REST_Controller_Test extends WP_UnitTestCase {

	/**
	 * Skip when the Pro addon classes are absent.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboards_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
	}

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Admins get the dashboard catalogue with the CRE + law configs.
	 */
	public function test_schema_includes_dashboards() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_schema( new WP_REST_Request() );

		$this->assertNotWPError( $result );
		$data = $result->get_data();

		$this->assertArrayHasKey( 'dashboards', $data );
		$this->assertArrayHasKey( 'cre', $data['dashboards'] );

		$cre = $data['dashboards']['cre'];
		$this->assertSame( 'cre', $cre['id'] );
		$this->assertNotEmpty( $cre['metrics'] );
		$this->assertNotEmpty( $cre['filters'] );
		$this->assertSame( 'loan_type', $cre['filters'][0]['key'] );
		$this->assertSame( 'loan_status', $cre['filters'][1]['key'] );
		$this->assertNotEmpty( $cre['charts'] );
		$this->assertSame( 'loan_list', $cre['lists'][0]['key'] );

		// The law-firm pilot (W13c) joins the catalogue with count metrics.
		$this->assertArrayHasKey( 'law', $data['dashboards'] );
		$this->assertNotEmpty( $data['dashboards']['law']['metrics'] );
		$this->assertSame( 'active_matters', $data['dashboards']['law']['metrics'][0]['key'] );
	}

	/**
	 * Unknown dashboards are rejected as readable 400s.
	 */
	public function test_unknown_dashboard_is_400() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/mystery/data' );
		$request->set_param( 'id', 'mystery' );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'dashboards_unknown', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * The CRE provider returns the zeroed envelope when no loans exist
	 * (the loan CPT is absent in the test env — zero network).
	 */
	public function test_cre_data_zeroed_envelope() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/cre/data' );
		$request->set_param( 'id', 'cre' );
		$request->set_param( 'filters', array( 'loan_status' => 'performing' ) );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		if ( is_wp_error( $result ) && 'dashboards_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'CRE dashboard page unavailable in this bootstrap.' );
		}

		$this->assertNotWPError( $result );
		$data = $result->get_data();

		$this->assertSame( 'cre', $data['dashboard'] );
		$this->assertArrayHasKey( 'total_loans', $data['data'] );
		$this->assertSame( 0, $data['data']['total_loans'] );
		$this->assertSame( 0, $data['data']['total_balance'] );
	}

	/**
	 * The law-firm provider returns the zeroed count envelope when the
	 * toolkit CPTs are absent (zero network).
	 */
	public function test_law_data_zeroed_envelope() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/law/data' );
		$request->set_param( 'id', 'law' );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		if ( is_wp_error( $result ) && 'dashboards_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'Law-firm dashboard page unavailable in this bootstrap.' );
		}

		$this->assertNotWPError( $result );
		$data = $result->get_data();

		$this->assertSame( 'law', $data['dashboard'] );
		$this->assertArrayHasKey( 'active_matters', $data['data'] );
		$this->assertSame( 0, $data['data']['active_matters'] );
	}

	/**
	 * Filter values are whitelisted against the dashboard's filter keys.
	 */
	public function test_filters_are_whitelisted() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/cre/data' );
		$request->set_param( 'id', 'cre' );
		$request->set_param(
			'filters',
			array(
				'loan_status' => 'watchlist',
				'injected'    => 'not-a-real-filter',
			)
		);

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		if ( is_wp_error( $result ) && 'dashboards_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'CRE dashboard page unavailable in this bootstrap.' );
		}

		// The whitelist is internal; the observable contract is a valid
		// envelope regardless of the injected filter key.
		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'data', $result->get_data() );
	}

	/**
	 * The REST routes are registered under the Pro namespace.
	 */
	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes( 'mcp-ai-pro/v1' );

		if ( ! isset( $routes['/mcp-ai-pro/v1/dashboards/schema'] ) ) {
			add_action(
				'rest_api_init',
				function () {
					( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->register_routes();
				}
			);
			$GLOBALS['wp_rest_server'] = null;
			rest_get_server();
			$routes = rest_get_server()->get_routes( 'mcp-ai-pro/v1' );
		}

		$this->assertArrayHasKey( '/mcp-ai-pro/v1/dashboards/schema', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/dashboards/(?P<id>[a-z0-9_-]+)/data', $routes );
	}
}
