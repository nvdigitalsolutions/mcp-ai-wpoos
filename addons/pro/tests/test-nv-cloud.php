<?php
/**
 * Tests for the NV oOS Cloud subsystem.
 *
 * Covers:
 *  - Service: connect-token storage (encrypted), balance cache, prefs,
 *    markup math, Stripe pass-through math, ledger append/cap/read.
 *  - Billing observer: gates on `nv_hosted` provider, derives wholesale
 *    when the gateway header is missing, drifts cached balance.
 *  - Provider client: delegates to the underlying client.
 *  - Router: `nv_hosted` is short-circuited via the `wp_mcp_ai_route_to_provider`
 *    filter; missing token returns a structured WP_Error.
 *  - REST controller: permission gate, status, save/save-token, ledger,
 *    minimum top-up enforcement.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NV oOS Cloud test-suite.
 */
class Test_WP_MCP_AI_NV_Cloud extends WP_UnitTestCase {

	/**
	 * Set-up: ensure NV Cloud subsystem files are loaded for the test run.
	 *
	 * In production these are loaded by `wp_mcp_ai_pro_init()`; in the unit
	 * test bootstrap that hook may not have fired so we require them here.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/' );
		}

		require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/includes/class-wp-mcp-ai-openrouter-client.php';
		require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/includes/interfaces/interface-wp-mcp-ai-provider-client.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-nv-cloud-service.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/providers/class-wp-mcp-ai-nv-cloud-client.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/providers/class-wp-mcp-ai-nv-cloud-provider-client.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-nv-cloud-billing-observer.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-nv-cloud-rest-controller.php';

		// Reset state.
		delete_option( WP_MCP_AI_NV_Cloud_Service::OPTION_CONNECT );
		delete_option( WP_MCP_AI_NV_Cloud_Service::OPTION_BALANCE );
		delete_option( WP_MCP_AI_NV_Cloud_Service::OPTION_LEDGER );
		delete_option( WP_MCP_AI_NV_Cloud_Service::OPTION_PREFS );
		WP_MCP_AI_NV_Cloud_Service::reset_instance();
		WP_MCP_AI_NV_Cloud_Billing_Observer::reset_instance();

		// `reset_instance()` can only unhook the observer it still holds a
		// reference to. `WP_UnitTestCase_Base::_restore_hooks()` re-installs the
		// hook table captured at set-up, which resurrects the registration of an
		// observer instance that has since been discarded — so the next test
		// starts with a stale callback the singleton no longer knows about and
		// `init()` adds a second one, double-recording every cost event.
		// Clearing the action outright makes each test see exactly the one
		// observer it registers itself.
		remove_all_actions( 'wp_mcp_ai_cost_calculated' );
	}

	/** Tear down test.
	 */
	public function tearDown(): void {
		WP_MCP_AI_NV_Cloud_Billing_Observer::reset_instance();
		WP_MCP_AI_NV_Cloud_Service::reset_instance();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Service tests.
	// ------------------------------------------------------------------

	/** Test service singleton returns same instance.
	 */
	public function test_service_singleton_returns_same_instance() {
		$a = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$b = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$this->assertSame( $a, $b );
	}

	/** Test default state is disconnected.
	 */
	public function test_default_state_is_disconnected() {
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$this->assertFalse( $svc->is_connected() );
		$this->assertSame( '', $svc->get_connect_token() );

		// `get_connection_meta()` always normalises to a fixed key set so the
		// REST status payload keeps a stable object shape (an empty array would
		// encode as a JSON list, not an object). Disconnected therefore means
		// "present but empty", not "absent".
		$this->assertSame(
			array(
				'account_id'   => '',
				'connected_at' => 0,
				'site_url'     => '',
			),
			$svc->get_connection_meta()
		);
	}

	/** Test save connection round trips token and meta.
	 */
	public function test_save_connection_round_trips_token_and_meta() {
		$svc   = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$token = 'cnvc_live_abcdefghijklmnop';
		$ok    = $svc->save_connection( $token, array( 'account_id' => 'acct_123' ) );

		$this->assertTrue( $ok );
		$this->assertTrue( $svc->is_connected() );
		$this->assertSame( $token, $svc->get_connect_token() );

		$meta = $svc->get_connection_meta();
		$this->assertSame( 'acct_123', $meta['account_id'] );
		$this->assertGreaterThan( 0, $meta['connected_at'] );
		$this->assertArrayNotHasKey( 'token', $meta );
	}

	/** Test save connection rejects empty token.
	 */
	public function test_save_connection_rejects_empty_token() {
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$this->assertFalse( $svc->save_connection( '' ) );
		$this->assertFalse( $svc->save_connection( '   ' ) );
		$this->assertFalse( $svc->is_connected() );
	}

	/** Test token is encrypted at rest.
	 */
	public function test_token_is_encrypted_at_rest() {
		$svc   = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$token = 'cnvc_secret_value';
		$svc->save_connection( $token );

		$raw = get_option( WP_MCP_AI_NV_Cloud_Service::OPTION_CONNECT );
		$this->assertIsArray( $raw );
		$this->assertArrayHasKey( 'token', $raw );
		// The stored value must NOT contain the cleartext token.
		$this->assertFalse( strpos( $raw['token'], $token ), 'Stored token leaks plaintext.' );
		// And it must round-trip back through the accessor.
		$this->assertSame( $token, $svc->get_connect_token() );
	}

	/** Test forget connection clears local state.
	 */
	public function test_forget_connection_clears_local_state() {
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$svc->save_connection( 'cnvc_x' );
		$svc->set_cached_balance( 50.0 );
		$svc->append_ledger_entry( array( 'wholesale_usd' => 0.001 ) );

		$svc->forget_connection();

		$this->assertFalse( $svc->is_connected() );
		$this->assertSame( '', $svc->get_connect_token() );
		$this->assertSame( 0.0, $svc->get_cached_balance()['balance'] );
		$this->assertSame( array(), $svc->get_ledger() );
	}

	/** Test compute markup uses seven percent.
	 */
	public function test_compute_markup_uses_seven_percent() {
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$this->assertEquals( 0.07, WP_MCP_AI_NV_Cloud_Service::MARKUP_RATE );
		$this->assertEquals( 0.07, $svc->compute_markup( 1.00 ) );
		$this->assertEquals( 0.0007, $svc->compute_markup( 0.01 ) );
		$this->assertSame( 0.0, $svc->compute_markup( -5 ) );
	}

	/** Test compute stripe passthrough uses 2 9 percent plus 30c.
	 */
	public function test_compute_stripe_passthrough_uses_2_9_percent_plus_30c() {
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		// $25 * 0.029 + $0.30 = 0.725 + 0.30 = 1.025, rounded to 1.03.
		$this->assertEquals( 1.03, $svc->compute_stripe_passthrough( 25.00 ) );
		// $100 * 0.029 + $0.30 = 2.90 + 0.30 = 3.20.
		$this->assertEquals( 3.20, $svc->compute_stripe_passthrough( 100.00 ) );
	}

	/** Test ledger append and cap.
	 */
	public function test_ledger_append_and_cap() {
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		for ( $i = 0; $i < 250; $i++ ) {
			$svc->append_ledger_entry(
				array(
					'wholesale_usd'   => 0.001,
					'service_fee_usd' => 0.00007,
					'total_usd'       => 0.00107,
					'model'           => 'openrouter/auto',
				)
			);
		}
		$ledger = $svc->get_ledger( 500 );
		$this->assertCount( WP_MCP_AI_NV_Cloud_Service::LEDGER_MAX_ENTRIES, $ledger );
	}

	/** Test prefs round trip clamps minimums.
	 */
	public function test_prefs_round_trip_clamps_minimums() {
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$svc->save_prefs(
			array(
				'use_as_default'        => true,
				'auto_topup_enabled'    => true,
				'auto_topup_amount_usd' => 5.0, // Below minimum.
			)
		);
		$prefs = $svc->get_prefs();
		$this->assertTrue( $prefs['use_as_default'] );
		$this->assertTrue( $prefs['auto_topup_enabled'] );
		$this->assertEquals( WP_MCP_AI_NV_Cloud_Service::DEFAULT_MIN_TOPUP_USD, $prefs['auto_topup_amount_usd'] );
	}

	/** Test is default provider requires connection and pref.
	 */
	public function test_is_default_provider_requires_connection_and_pref() {
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$this->assertFalse( $svc->is_default_provider() );

		$svc->save_prefs( array( 'use_as_default' => true ) );
		$this->assertFalse( $svc->is_default_provider() ); // Not connected yet.

		$svc->save_connection( 'cnvc_x' );
		$this->assertTrue( $svc->is_default_provider() );
	}

	/** Test base url can be overridden via filter.
	 */
	public function test_base_url_can_be_overridden_via_filter() {
		$svc      = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$override = static function () {
			return 'https://staging.example.com/v1';
		};
		add_filter( 'wp_mcp_ai_nv_cloud_base_url', $override );
		$this->assertSame( 'https://staging.example.com/v1', $svc->get_base_url() );
		remove_filter( 'wp_mcp_ai_nv_cloud_base_url', $override );
	}

	// ------------------------------------------------------------------
	// Billing observer tests.
	// ------------------------------------------------------------------

	/** Test billing observer ignores non nv hosted requests.
	 */
	public function test_billing_observer_ignores_non_nv_hosted_requests() {
		WP_MCP_AI_NV_Cloud_Billing_Observer::init();
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		do_action(
			'wp_mcp_ai_cost_calculated',
			array( 'cost_usd' => 0.005 ),
			0,
			0,
			array( 'model' => 'gpt-4o' ),
			array( 'provider' => 'openai' )
		);
		$this->assertSame( array(), $svc->get_ledger() );
	}

	/** Test billing observer records nv hosted request.
	 */
	public function test_billing_observer_records_nv_hosted_request() {
		WP_MCP_AI_NV_Cloud_Billing_Observer::init();
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$svc->save_connection( 'cnvc_x' );
		$svc->set_cached_balance( 25.0 );

		do_action(
			'wp_mcp_ai_cost_calculated',
			array( 'cost_usd' => 0.0107 ),
			42,
			1,
			array(
				'model'                  => 'openrouter/auto',
				'nv_cloud_wholesale_usd' => 0.01,
			),
			array(
				'provider' => 'nv_hosted',
			)
		);

		$ledger = $svc->get_ledger();
		$this->assertCount( 1, $ledger );
		$entry = $ledger[0];
		$this->assertSame( 'usage', $entry['kind'] );
		$this->assertSame( 0.01, $entry['wholesale_usd'] );
		$this->assertEqualsWithDelta( 0.0007, $entry['service_fee_usd'], 0.00001 );
		$this->assertEqualsWithDelta( 0.0107, $entry['total_usd'], 0.0001 );
		$this->assertSame( 42, $entry['assistant_id'] );

		// Cached balance should have drifted by the total charged.
		$this->assertEqualsWithDelta( 24.9893, $svc->get_cached_balance()['balance'], 0.0001 );
	}

	/** Test billing observer derives wholesale when header missing.
	 */
	public function test_billing_observer_derives_wholesale_when_header_missing() {
		WP_MCP_AI_NV_Cloud_Billing_Observer::init();
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();

		// No `nv_cloud_wholesale_usd` in the response — observer must derive.
		// the wholesale figure from `cost_usd / (1 + markup)`. We still flag.
		// this as an NV-hosted call via `request['provider']`.
		do_action(
			'wp_mcp_ai_cost_calculated',
			array( 'cost_usd' => 1.07 ),
			0,
			0,
			array( 'model' => 'foo' ),
			array( 'provider' => 'nv_hosted' )
		);

		$entry = $svc->get_ledger()[0];
		// total = 1.00 wholesale + 7% fee = 1.07.
		$this->assertEqualsWithDelta( 1.00, $entry['wholesale_usd'], 0.0001 );
		$this->assertEqualsWithDelta( 0.07, $entry['service_fee_usd'], 0.0001 );
		$this->assertEqualsWithDelta( 1.07, $entry['total_usd'], 0.0001 );
	}

	// ------------------------------------------------------------------
	// Provider client tests.
	// ------------------------------------------------------------------

	/** Test provider adapter reports correct slug.
	 */
	public function test_provider_adapter_reports_correct_slug() {
		$adapter = new WP_MCP_AI_NV_Cloud_Provider_Client();
		$this->assertSame( 'nv_hosted', $adapter->get_provider_slug() );
		$this->assertInstanceOf( 'WP_MCP_AI_NV_Cloud_Client', $adapter->get_client() );
	}

	/** Test client returns wp error when disconnected.
	 */
	public function test_client_returns_wp_error_when_disconnected() {
		$client = new WP_MCP_AI_NV_Cloud_Client();
		$result = $client->create_chat_completion(
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_nv_cloud_not_connected', $result->get_error_code() );
	}

	/** Test client uses service base url and token.
	 */
	public function test_client_uses_service_base_url_and_token() {
		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$svc->save_connection( 'cnvc_xyz' );

		$client = new WP_MCP_AI_NV_Cloud_Client();
		$this->assertSame( 'cnvc_xyz', $client->get_api_key() );
		$this->assertSame( $svc->get_base_url(), $client->get_base_url() );
		$this->assertSame( 'openrouter/auto', $client->get_model() );
	}

	// ------------------------------------------------------------------
	// REST controller tests.
	// ------------------------------------------------------------------

	/** Test rest status endpoint reports connection state.
	 */
	public function test_rest_status_endpoint_reports_connection_state() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$controller = new WP_MCP_AI_REST_NV_Cloud_Controller();
		$response   = $controller->get_status();

		$data = $response->get_data();
		$this->assertFalse( $data['connected'] );
		$this->assertEquals( 0.07, $data['markup_rate'] );
		$this->assertEquals( 25.0, $data['min_topup_usd'] );
	}

	/** Test rest connect persists token.
	 */
	public function test_rest_connect_persists_token() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$controller = new WP_MCP_AI_REST_NV_Cloud_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/cloud/connect' );
		$request->set_param( 'token', 'cnvc_test_token' );
		$request->set_param( 'account_id', 'acct_42' );

		$response = $controller->connect( $request );
		$this->assertNotInstanceOf( 'WP_Error', $response );

		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$this->assertTrue( $svc->is_connected() );
		$this->assertSame( 'cnvc_test_token', $svc->get_connect_token() );
	}

	/** Test rest connect rejects empty token.
	 */
	public function test_rest_connect_rejects_empty_token() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$controller = new WP_MCP_AI_REST_NV_Cloud_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/cloud/connect' );
		$request->set_param( 'token', '' );

		$response = $controller->connect( $request );
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertSame( 'wp_mcp_ai_nv_cloud_invalid_token', $response->get_error_code() );
	}

	/** Test rest topup url enforces minimum.
	 */
	public function test_rest_topup_url_enforces_minimum() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$svc->save_connection( 'cnvc_x' );

		$controller = new WP_MCP_AI_REST_NV_Cloud_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/cloud/topup-url' );
		$request->set_param( 'amount_usd', 5.0 );

		$response = $controller->create_topup_url( $request );
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertSame( 'wp_mcp_ai_nv_cloud_topup_too_small', $response->get_error_code() );
	}

	/** Test rest permission check requires manage options.
	 */
	public function test_rest_permission_check_requires_manage_options() {
		$controller = new WP_MCP_AI_REST_NV_Cloud_Controller();

		wp_set_current_user( 0 );
		$this->assertFalse( $controller->permission_check() );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$this->assertFalse( $controller->permission_check() );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->assertTrue( $controller->permission_check() );
	}

	/** Test rest ledger returns recorded entries.
	 */
	public function test_rest_ledger_returns_recorded_entries() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$svc = WP_MCP_AI_NV_Cloud_Service::get_instance();
		$svc->append_ledger_entry(
			array(
				'wholesale_usd'   => 0.01,
				'service_fee_usd' => 0.0007,
				'total_usd'       => 0.0107,
				'model'           => 'openrouter/auto',
			)
		);

		$controller = new WP_MCP_AI_REST_NV_Cloud_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/cloud/ledger' );
		$request->set_param( 'limit', 10 );
		$response = $controller->get_ledger( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['entries'] );
		$this->assertSame( 'openrouter/auto', $data['entries'][0]['model'] );
	}

	// ------------------------------------------------------------------
	// Router filter tests.
	// ------------------------------------------------------------------

	/** Test router filter short circuits for nv hosted.
	 */
	public function test_router_filter_short_circuits_for_nv_hosted() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/nv-cloud-init.php';

		// Without a connection, the filter should still return a WP_Error.
		// (not null) so the router doesn't fall through to the OpenAI default.
		$result = apply_filters(
			'wp_mcp_ai_route_to_provider',
			null,
			'nv_hosted',
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_nv_cloud_not_connected', $result->get_error_code() );
	}

	/** Test router filter passes through other providers.
	 */
	public function test_router_filter_passes_through_other_providers() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/nv-cloud-init.php';

		$result = apply_filters(
			'wp_mcp_ai_route_to_provider',
			null,
			'openai',
			array(),
			array()
		);

		$this->assertNull( $result );
	}
}
