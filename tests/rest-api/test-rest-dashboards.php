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

		// Toolkits are default-off; clear the settings between tests so
		// each case starts from the unconfigured state.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Enable every dashboard-gating toolkit flag.
	 */
	private function seed_toolkit_flags(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_cre_debt_toolkit'               => 1,
				'enable_law_firm_toolkit'               => 1,
				'enable_regulatory_registration_toolkit' => 1,
				'enable_eca_management'                 => 1,
				'enable_health_wellness_management'     => 1,
				'enable_medical_vitals'                 => 1,
				// Proposal 089 Phase 4f — commerce toolkit dashboards.
				'enable_ezuite_toolkit'                 => 1,
				'enable_shopify_sync_toolkit'           => 1,
				'enable_flowhub_toolkit'                => 1,
			)
		);
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
		$this->seed_toolkit_flags();
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

		// The registration dashboard joins with stats + the country list.
		$this->assertArrayHasKey( 'registration', $data['dashboards'] );
		$this->assertSame( 'countries', $data['dashboards']['registration']['lists'][0]['key'] );

		// The ECA dashboard joins with KPIs, charts and the activity list.
		$this->assertArrayHasKey( 'eca', $data['dashboards'] );
		$this->assertSame( 'total_ecas', $data['dashboards']['eca']['metrics'][0]['key'] );
		$this->assertSame( 'categories', $data['dashboards']['eca']['charts'][0]['key'] );
		$this->assertSame( 'ecas', $data['dashboards']['eca']['lists'][0]['key'] );

		// 158 D1 — the member-filtered pair is ONE surface now. The filters and
		// the shape were identical; only the toolkit toggles differed, and those
		// survive as per-section visibility.
		$this->assertArrayHasKey( 'members', $data['dashboards'] );
		$this->assertSame( 'member', $data['dashboards']['members']['filters'][0]['type'] );
		$this->assertArrayNotHasKey( 'sections', $data['dashboards']['members'], 'Gating internals must not leak into the render contract.' );

		// Both sections are on (every flag is seeded), so both lists are there,
		// namespaced by section — two lists called `history` cannot coexist.
		$this->assertSame(
			array( 'health_history', 'vitals_history' ),
			array_column( $data['dashboards']['members']['lists'], 'key' )
		);

		// The commerce trio is one surface: identical render specs, one data
		// source each. `ezuite` and `flowhub` both publish `in_stock`, which is
		// exactly why the keys are namespaced.
		$this->assertArrayHasKey( 'connectors', $data['dashboards'] );

		$metric_keys = array_column( $data['dashboards']['connectors']['metrics'], 'key' );
		$this->assertCount( 15, $metric_keys );
		$this->assertSame( $metric_keys, array_unique( $metric_keys ) );
		$this->assertContains( 'ezuite_in_stock', $metric_keys );
		$this->assertContains( 'flowhub_in_stock', $metric_keys );
		$this->assertContains( 'shopify_cost_remaining_pct', $metric_keys );

		$list_keys = array_column( $data['dashboards']['connectors']['lists'], 'key' );
		$this->assertSame( array( 'ezuite_warehouses', 'flowhub_locations' ), $list_keys );

		// The retired ids are gone from the catalogue...
		foreach ( array( 'health', 'vitals', 'ezuite', 'shopify', 'flowhub' ) as $retired ) {
			$this->assertArrayNotHasKey( $retired, $data['dashboards'], $retired . ' should be retired.' );
		}

		// ...and published as aliases, so a saved id resolves instead of 404ing.
		$this->assertSame( 'members', $data['aliases']['health'] );
		$this->assertSame( 'members', $data['aliases']['vitals'] );
		$this->assertSame( 'connectors', $data['aliases']['ezuite'] );
		$this->assertSame( 'connectors', $data['aliases']['shopify'] );
		$this->assertSame( 'connectors', $data['aliases']['flowhub'] );

		// With every toolkit on, the guidance map is empty.
		$this->assertArrayHasKey( 'disabled', $data );
		$this->assertSame( array(), $data['disabled'] );
	}

	/**
	 * Disabled toolkits never appear in the catalogue — the console renders
	 * this list verbatim, so feature-gating happens server-side. They DO
	 * appear in the `disabled` metadata map, which powers the console's
	 * empty-state guidance when the whole catalogue is off (the default).
	 */
	public function test_schema_hides_disabled_dashboards() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_schema( new WP_REST_Request() );
		$data   = $result->get_data();

		// 158 D1 retired five ids into two, so six surfaces can be off.
		foreach ( array( 'cre', 'law', 'registration', 'eca', 'members', 'connectors' ) as $id ) {
			$this->assertArrayNotHasKey( $id, $data['dashboards'], $id . ' should hide when its toolkit is off.' );
			$this->assertArrayHasKey( $id, $data['disabled'], $id . ' should be listed in the disabled map.' );
		}

		// The retired ids are neither served nor offered as guidance — they
		// are aliases, and offering one would resurrect the surface.
		foreach ( array( 'health', 'vitals', 'ezuite', 'shopify', 'flowhub' ) as $retired ) {
			$this->assertArrayNotHasKey( $retired, $data['dashboards'] );
			$this->assertArrayNotHasKey( $retired, $data['disabled'] );
		}

		// The disabled entries carry the console-guidance metadata: the
		// display label/icon and the settings flag that would enable them.
		$this->assertSame( 'CRE Debt Portfolio', $data['disabled']['cre']['label'] );
		$this->assertSame( 'enable_cre_debt_toolkit', $data['disabled']['cre']['toolkit_flag'] );

		// A merged surface names EVERY flag that would enable it. Naming one
		// of three is incomplete advice; the single-flag key stays for BC.
		$this->assertSame( 'enable_ezuite_toolkit', $data['disabled']['connectors']['toolkit_flag'] );
		$this->assertSame(
			array( 'enable_ezuite_toolkit', 'enable_shopify_sync_toolkit', 'enable_flowhub_toolkit' ),
			$data['disabled']['connectors']['toolkit_flags']
		);

		// Vitals follows the Health & Wellness toggle when the dedicated flag
		// was never saved (BC fallback), so the candidate list collapses to
		// the key that actually decides it.
		$this->assertSame(
			array( 'enable_health_wellness_management' ),
			$data['disabled']['members']['toolkit_flags']
		);

		// Enabling only Health & Wellness restores the whole members surface —
		// including the vitals section, via the same BC fallback.
		update_option( 'wp_mcp_ai_settings', array( 'enable_health_wellness_management' => 1 ) );
		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_schema( new WP_REST_Request() );
		$data   = $result->get_data();

		$this->assertArrayHasKey( 'members', $data['dashboards'] );
		$this->assertArrayNotHasKey( 'members', $data['disabled'] );
		$this->assertSame(
			array( 'health_history', 'vitals_history' ),
			array_column( $data['dashboards']['members']['lists'], 'key' )
		);
		$this->assertArrayNotHasKey( 'cre', $data['dashboards'] );
		$this->assertArrayNotHasKey( 'law', $data['dashboards'] );
		$this->assertArrayNotHasKey( 'eca', $data['dashboards'] );
		$this->assertArrayNotHasKey( 'connectors', $data['dashboards'], 'One member flag must not enable a connector surface.' );

		// The dedicated vitals toggle, once saved, wins over the BC fallback —
		// and because the surface itself is still enabled by health, only the
		// vitals SECTION disappears.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_health_wellness_management' => 1,
				'enable_medical_vitals'             => 0,
			)
		);
		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_schema( new WP_REST_Request() );
		$data   = $result->get_data();

		$this->assertArrayHasKey( 'members', $data['dashboards'] );
		$this->assertSame(
			array( 'health_history' ),
			array_column( $data['dashboards']['members']['lists'], 'key' ),
			'A saved `0` on the dedicated toggle must hide the vitals section.'
		);

		// And with both member toggles off, the surface leaves the catalogue.
		update_option( 'wp_mcp_ai_settings', array( 'enable_medical_vitals' => 0 ) );
		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_schema( new WP_REST_Request() );
		$data   = $result->get_data();

		$this->assertArrayNotHasKey( 'members', $data['dashboards'] );
		$this->assertArrayHasKey( 'members', $data['disabled'] );

		// The merged surface is gated by BOTH member flags (158 D1), so the
		// single-flag guidance names the first that would enable it
		// (`effective_flag_key()` is `effective_flag_keys()[0]`) while
		// `toolkit_flags` carries every candidate — naming one of two would be
		// incomplete advice for a client that can render a list.
		$this->assertSame( 'enable_health_wellness_management', $data['disabled']['members']['toolkit_flag'] );
		$this->assertSame(
			array( 'enable_health_wellness_management', 'enable_medical_vitals' ),
			$data['disabled']['members']['toolkit_flags'],
			'`enable_medical_vitals` is saved as 0 here, so it stays in the candidate list.'
		);
	}

	/**
	 * A single enabled connector reveals its own section and nobody else's.
	 *
	 * This is the property that makes the 3 -> 1 merge safe: the old dashboards
	 * were gated independently, and a merge that gated them together would
	 * publish one tenant's connector count under another connector's heading.
	 */
	public function test_one_connector_flag_reveals_only_its_own_section() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_flowhub_toolkit' => 1 ) );
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_schema( new WP_REST_Request() );
		$data   = $result->get_data();

		$this->assertArrayHasKey( 'connectors', $data['dashboards'] );

		$metric_keys = array_column( $data['dashboards']['connectors']['metrics'], 'key' );

		$this->assertCount( 5, $metric_keys, 'Only the enabled connector contributes.' );
		$this->assertSame( 'flowhub_in_stock', $metric_keys[0] );
		$this->assertNotContains( 'ezuite_in_stock', $metric_keys );
		$this->assertNotContains( 'shopify_cost_remaining_pct', $metric_keys );

		$this->assertSame(
			array( 'flowhub_locations' ),
			array_column( $data['dashboards']['connectors']['lists'], 'key' )
		);
	}

	/**
	 * A retired id resolves to its replacement and the response says so.
	 */
	public function test_a_retired_id_resolves_instead_of_404ing() {
		$this->seed_toolkit_flags();
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/ezuite/data' );
		$request->set_param( 'id', 'ezuite' );
		$request->set_param( 'filters', array() );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		if ( is_wp_error( $result ) && 'dashboards_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'Connector widget unavailable in this bootstrap.' );
		}

		$this->assertNotWPError( $result );

		// The resolved id is reported, which is the one piece of information a
		// client needs to migrate its own saved state.
		$this->assertSame( 'connectors', $result->get_data()['dashboard'] );
	}

	/**
	 * Resolving an alias is not a grant: the replacement's own gate still applies.
	 */
	public function test_a_retired_id_is_still_refused_when_its_replacement_is_off() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/ezuite/data' );
		$request->set_param( 'id', 'ezuite' );
		$request->set_param( 'filters', array() );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'dashboards_unavailable', $result->get_error_code() );
	}

	/**
	 * The BC accessors still answer for a retired id.
	 */
	public function test_the_flag_map_still_answers_for_a_retired_id() {
		$this->assertSame( 'members', WP_MCP_AI_Pro_Dashboards_REST_Controller::resolve_alias( 'health' ) );
		$this->assertSame( 'connectors', WP_MCP_AI_Pro_Dashboards_REST_Controller::resolve_alias( 'flowhub' ) );
		$this->assertSame( 'cre', WP_MCP_AI_Pro_Dashboards_REST_Controller::resolve_alias( 'cre' ) );
		$this->assertSame( 'nope', WP_MCP_AI_Pro_Dashboards_REST_Controller::resolve_alias( 'nope' ) );

		// The single-flag accessor answers for a retired id, because the id
		// itself is still what a stored console state carries.
		$this->assertSame( 'enable_ezuite_toolkit', WP_MCP_AI_Pro_Dashboards_REST_Controller::effective_flag_key( 'ezuite' ) );
		$this->assertSame( '', WP_MCP_AI_Pro_Dashboards_REST_Controller::effective_flag_key( 'observability' ) );
	}

	/**
	 * Direct data requests for a disabled dashboard are refused — not just
	 * hidden from the picker.
	 */
	public function test_data_rejects_disabled_dashboard() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/members/data' );
		$request->set_param( 'id', 'members' );
		$request->set_param( 'filters', array() );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'dashboards_unavailable', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
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
		$this->seed_toolkit_flags();
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
		$this->seed_toolkit_flags();
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
	 * The registration provider returns the zeroed envelope when the
	 * toolkit CPTs are absent (zero network).
	 */
	public function test_registration_data_zeroed_envelope() {
		$this->seed_toolkit_flags();
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/registration/data' );
		$request->set_param( 'id', 'registration' );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		if ( is_wp_error( $result ) && 'dashboards_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'Registration dashboard page unavailable in this bootstrap.' );
		}

		$this->assertNotWPError( $result );
		$data = $result->get_data();

		$this->assertSame( 'registration', $data['dashboard'] );
		$this->assertArrayHasKey( 'total_products', $data['data'] );
		$this->assertSame( 0, $data['data']['total_products'] );
		$this->assertSame( array(), $data['data']['countries'] );
	}

	/**
	 * The ECA provider returns the zeroed envelope when the toolkit CPTs
	 * are absent (zero network).
	 */
	public function test_eca_data_zeroed_envelope() {
		$this->seed_toolkit_flags();
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/eca/data' );
		$request->set_param( 'id', 'eca' );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		if ( is_wp_error( $result ) && 'dashboards_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'ECA dashboard page unavailable in this bootstrap.' );
		}

		$this->assertNotWPError( $result );
		$data = $result->get_data();

		$this->assertSame( 'eca', $data['dashboard'] );
		$this->assertArrayHasKey( 'total_ecas', $data['data'] );
		$this->assertSame( 0, $data['data']['total_ecas'] );
		$this->assertSame( array(), $data['data']['categories'] );
		$this->assertSame( array(), $data['data']['ecas'] );
	}

	/**
	 * The member-metrics surface requires a member — 400 without one
	 * (deterministic: the provider is never reached).
	 *
	 * The 400 is one section's answer to "which member?", so it must survive
	 * the merge rather than be swallowed as a per-section degradation.
	 */
	public function test_member_dashboards_require_a_member() {
		$this->seed_toolkit_flags();
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/members/data' );
		$request->set_param( 'id', 'members' );
		$request->set_param( 'filters', array() );

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		if ( is_wp_error( $result ) && 'dashboards_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'Member dashboard page unavailable in this bootstrap.' );
		}

		$this->assertWPError( $result, 'members should refuse a missing member.' );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * With a real member and no stored metrics, both enabled sections return
	 * their zeroed envelope (zero network — the tools read the member's option,
	 * which is absent in the test env).
	 */
	public function test_health_data_zeroed_envelope() {
		$this->seed_toolkit_flags();
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		if ( ! post_type_exists( 'mcp_ai_member' ) ) {
			register_post_type(
				'mcp_ai_member',
				array(
					'labels'   => array( 'name' => 'Members' ),
					'public'   => false,
					'supports' => array( 'title' ),
				)
			);
		}
		$member_id = $this->factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_member',
				'post_title' => 'Test Member',
			)
		);

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/members/data' );
		$request->set_param( 'id', 'members' );
		$request->set_param(
			'filters',
			array(
				'member_id' => (string) $member_id,
				'days_back' => '30',
			)
		);

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		if ( is_wp_error( $result ) && 'dashboards_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'Member dashboard page unavailable in this bootstrap.' );
		}

		$this->assertNotWPError( $result );
		$data = $result->get_data();

		$this->assertSame( 'members', $data['dashboard'] );

		// Both sections are on (every flag is seeded), and the payload keys are
		// the namespaced ones the schema declares — the two must agree, or the
		// console renders `undefined` for every card.
		$this->assertArrayHasKey( 'health_history', $data['data'] );
		$this->assertArrayHasKey( 'vitals_history', $data['data'] );
		$this->assertSame( array(), $data['data']['health_history'] );
		$this->assertSame( array(), $data['data']['vitals_history'] );
	}

	/**
	 * Filter values are whitelisted against the dashboard's filter keys.
	 */
	public function test_filters_are_whitelisted() {
		$this->seed_toolkit_flags();
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
	 * Filters may arrive as a JSON-encoded query string (the console SPA
	 * sends `?filters={"member_id":"…"}`). The handler decodes the string
	 * before the whitelist: an undecoded payload would leave member_id
	 * empty and the provider would refuse with a 400, so reaching the
	 * zeroed envelope proves the decoded filter reached the provider.
	 */
	public function test_json_string_filters_reach_the_provider() {
		$this->seed_toolkit_flags();
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		if ( ! post_type_exists( 'mcp_ai_member' ) ) {
			register_post_type(
				'mcp_ai_member',
				array(
					'labels'   => array( 'name' => 'Members' ),
					'public'   => false,
					'supports' => array( 'title' ),
				)
			);
		}
		$member_id = $this->factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_member',
				'post_title' => 'JSON Filter Member',
			)
		);

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/dashboards/members/data' );
		$request->set_param( 'id', 'members' );
		$request->set_param(
			'filters',
			'{"member_id":"' . $member_id . '","days_back":"30"}'
		);

		$result = ( new WP_MCP_AI_Pro_Dashboards_REST_Controller() )->get_data( $request );

		if ( is_wp_error( $result ) && 'dashboards_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'Member dashboard page unavailable in this bootstrap.' );
		}

		$this->assertNotWPError( $result );
		$data = $result->get_data();

		$this->assertSame( 'members', $data['dashboard'] );
		$this->assertArrayHasKey( 'health_history', $data['data'] );
		$this->assertSame( array(), $data['data']['health_history'] );
	}

	/**
	 * The sanitize callback decodes a JSON object string into an
	 * associative array and degrades to an empty array on non-object or
	 * invalid JSON. Arrays pass through untouched.
	 */
	public function test_sanitize_filters_decodes_json_strings() {
		$controller = new WP_MCP_AI_Pro_Dashboards_REST_Controller();

		$this->assertSame(
			array( 'loan_status' => 'performing' ),
			$controller->sanitize_filters( '{"loan_status":"performing"}' )
		);
		$this->assertSame( array(), $controller->sanitize_filters( '{"broken":' ) );
		$this->assertSame( array(), $controller->sanitize_filters( '[1,2,3]' ) );
		$this->assertSame( array(), $controller->sanitize_filters( 'not-json' ) );
		$this->assertSame( array(), $controller->sanitize_filters( '' ) );
		$this->assertSame(
			array( 'a' => 'b' ),
			$controller->sanitize_filters( array( 'a' => 'b' ) )
		);
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
