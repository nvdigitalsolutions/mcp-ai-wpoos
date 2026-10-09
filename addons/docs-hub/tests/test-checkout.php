<?php
/**
 * Tests for the Docs Hub checkout integration (NV oOS Complete upsell).
 *
 * Covers the checkout config, license store, installer guard, and the
 * /payments/* REST routes with the vendor HTTP layer mocked — no real
 * network calls and no real payment ever happen in these tests.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.5.2
 */

/**
 * Docs Hub checkout integration tests.
 */
class Test_Docs_Hub_Checkout extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Number of vendor HTTP requests made during a test.
	 *
	 * @var int
	 */
	protected $http_calls = 0;

	/**
	 * Canned vendor response per route (empty = 404-style error).
	 *
	 * @var array<string,array<string,mixed>>
	 */
	protected $vendor_responses = array();

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '0.5.3' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_PATH' ) ) {
			define( 'NVOOS_DOCS_HUB_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_URL' ) ) {
			define( 'NVOOS_DOCS_HUB_URL', 'http://example.com/wp-content/plugins/nvoos-docs-hub/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_FILE' ) ) {
			define( 'NVOOS_DOCS_HUB_FILE', NVOOS_DOCS_HUB_PATH . 'nvoos-docs-hub.php' );
		}

		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-plugin.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/rest/class-nvoos-docs-hub-rest.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/checkout/class-nvoos-docs-hub-checkout.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/checkout/class-nvoos-docs-hub-checkout-client.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/checkout/class-nvoos-docs-hub-checkout-license.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/checkout/class-nvoos-docs-hub-checkout-installer.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/checkout/class-nvoos-docs-hub-checkout-rest.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cache.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-state.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/admin/class-nvoos-docs-hub-settings.php';

		// The installer conflict guard keys off the base plugin being
		// present; make that deterministic in the test environment.
		if ( ! defined( 'WP_MCP_AI_VERSION' ) ) {
			define( 'WP_MCP_AI_VERSION', '9.9.9-test' );
		}

		$this->http_calls       = 0;
		$this->vendor_responses = array();

		// Boot the REST server and register the checkout routes.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $wp_rest_server );
		NV_oOS_Docs_Hub_Checkout_REST::register_routes();

		// Intercept all vendor HTTP calls.
		add_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10, 3 );

		// Admin user.
		wp_set_current_user( 1 );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10 );

		delete_option( NV_oOS_Docs_Hub_Checkout::OPTION_LICENSE );
		delete_option( 'active_plugins' );

		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * Mock the vendor HTTP layer.
	 *
	 * @param false|array|WP_Error $pre    Short-circuit value.
	 * @param array                $args   Request args.
	 * @param string               $url    Request URL.
	 * @return false|array|WP_Error
	 */
	public function mock_http_request( $pre, $args, $url ) {
		++$this->http_calls;

		$route = '';
		$paths = array( '/session', '/verify', '/health' );
		foreach ( $paths as $path ) {
			if ( false !== strpos( $url, $path ) ) {
				$route = trim( $path, '/' );
				break;
			}
		}

		if ( isset( $this->vendor_responses[ $route ] ) ) {
			$canned = $this->vendor_responses[ $route ];
			return array(
				'response' => array(
					'code'    => isset( $canned['status'] ) ? $canned['status'] : 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode( $canned['body'] ),
			);
		}

		return array(
			'response' => array(
				'code'    => 502,
				'message' => 'Bad Gateway',
			),
			'body'     => '',
		);
	}

	/**
	 * A valid vendor /session payload.
	 *
	 * @return array<string,mixed>
	 */
	private function session_payload() {
		return array(
			'status' => 200,
			'body'   => array(
				'client_secret'     => 'pi_test_secret_12345',
				'publishable_key'   => 'pk_test_12345',
				'amount'            => 3499,
				'currency'          => 'usd',
				'test_mode'         => true,
				'terms_url'         => 'https://nvdigitalsolutions.com/terms-of-service',
				'refund_policy_url' => 'https://nvdigitalsolutions.com/refund-policy',
			),
		);
	}

	/**
	 * A valid vendor /verify payload.
	 *
	 * @return array<string,mixed>
	 */
	private function verify_payload() {
		return array(
			'status' => 200,
			'body'   => array(
				'license_key'   => 'test-license-key',
				'download_url'  => 'https://nvdigitalsolutions.com/downloads/complete.zip',
				'addon_version' => '1.1.74',
				'amount'        => 3499,
				'currency'      => 'usd',
			),
		);
	}

	// ─── Config ────────────────────────────────────────────────────────

	/**
	 * Default price, label, and the Stripe-minimum floor.
	 *
	 * @return void
	 */
	public function test_price_defaults_and_floor() {
		$this->assertSame( 3499, NV_oOS_Docs_Hub_Checkout::price_cents() );
		$this->assertSame( '$34.99', NV_oOS_Docs_Hub_Checkout::price_label() );

		add_filter( 'nvoos_docs_hub_checkout_price_cents', '__return_zero' );
		$this->assertSame( 50, NV_oOS_Docs_Hub_Checkout::price_cents() );
		remove_filter( 'nvoos_docs_hub_checkout_price_cents', '__return_zero' );
	}

	/**
	 * Vendor URL default, filter override, and is_configured().
	 *
	 * @return void
	 */
	public function test_vendor_url_configuration() {
		$this->assertTrue( NV_oOS_Docs_Hub_Checkout::is_configured() );
		$this->assertSame(
			'https://nvdigitalsolutions.com/wp-json/nvoos-checkout/v1',
			NV_oOS_Docs_Hub_Checkout::vendor_api_url()
		);

		add_filter( 'nvoos_docs_hub_checkout_vendor_api_url', '__return_empty_string' );
		$this->assertFalse( NV_oOS_Docs_Hub_Checkout::is_configured() );
		remove_filter( 'nvoos_docs_hub_checkout_vendor_api_url', '__return_empty_string' );
	}

	/**
	 * The localized checkout config object name matches what the JS reads.
	 *
	 * The purchase-modal script reads `window.NVOOS_DH_CHECKOUT`; when the
	 * wp_localize_script object name and the JS global drift apart,
	 * `rest_url` and `nonce` are undefined and every /payments call hits
	 * `/wp-admin/undefined/...` (404). This guards both sides.
	 *
	 * @return void
	 */
	public function test_checkout_localize_name_matches_js() {
		NV_oOS_Docs_Hub_Settings::enqueue_admin_assets( 'toplevel_page_nvoos-docs-hub' );

		$scripts = wp_scripts();
		$this->assertArrayHasKey( 'nvoos-dh-checkout', $scripts->registered );
		$this->assertStringContainsString(
			'NVOOS_DH_CHECKOUT',
			$scripts->registered['nvoos-dh-checkout']->extra['data']
		);

		$source = (string) file_get_contents( NVOOS_DOCS_HUB_PATH . 'assets/admin/docs-hub-checkout.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset, not a remote URL.
		$this->assertStringContainsString( 'window.NVOOS_DH_CHECKOUT', $source );
		$this->assertStringNotContainsString( 'nvoosDocsHubCheckout', $source );
	}

	/**
	 * The vendor purchase payload carries the product, site, and version.
	 *
	 * @return void
	 */
	public function test_purchase_payload_shape() {
		$payload = NV_oOS_Docs_Hub_Checkout::purchase_payload();

		$this->assertSame( 'nvoos-oos-complete', $payload['product'] );
		$this->assertSame( home_url( '' ), $payload['site_url'] );
		$this->assertSame( NV_oOS_Docs_Hub_Checkout::addon_version(), $payload['addon_version'] );
	}

	/**
	 * EU country codes are uppercase alpha-2 and filter-invalidated.
	 *
	 * @return void
	 */
	public function test_eu_country_codes() {
		$codes = NV_oOS_Docs_Hub_Checkout::eu_country_codes();

		$this->assertCount( 27, $codes );
		$this->assertContains( 'DE', $codes );
		$this->assertNotContains( 'US', $codes );

		add_filter(
			'nvoos_docs_hub_checkout_eu_countries',
			static function () {
				return array( 'DE', 'invalid-code', 42, 'xx' );
			}
		);
		$this->assertSame( array( 'DE' ), NV_oOS_Docs_Hub_Checkout::eu_country_codes() );
	}

	// ─── License store ─────────────────────────────────────────────────

	/**
	 * License records round-trip through the option store.
	 *
	 * @return void
	 */
	public function test_license_store_round_trip() {
		$this->assertFalse( NV_oOS_Docs_Hub_Checkout_License::is_licensed() );
		$this->assertSame( '', NV_oOS_Docs_Hub_Checkout_License::license_key() );

		$record = array(
			'license_key'           => 'lic-test-123',
			'stripe_payment_intent' => 'pi_test_12345',
			'amount_received'       => 3499,
		);

		NV_oOS_Docs_Hub_Checkout_License::save( $record );

		$this->assertTrue( NV_oOS_Docs_Hub_Checkout_License::is_licensed() );
		$this->assertSame( 'lic-test-123', NV_oOS_Docs_Hub_Checkout_License::license_key() );
		$this->assertSame( $record, NV_oOS_Docs_Hub_Checkout_License::get() );
	}

	// ─── Installer guard ───────────────────────────────────────────────

	/**
	 * The base-plugin conflict guard trips when a base copy exists.
	 *
	 * @return void
	 */
	public function test_installer_detects_existing_base_plugin() {
		$this->assertSame(
			NV_oOS_Docs_Hub_Checkout_Installer::BUNDLE_BASENAME,
			NV_oOS_Docs_Hub_Checkout_Installer::detect_existing_base_plugin()
		);

		add_filter( 'nvoos_docs_hub_checkout_skip_base_plugin_detection', '__return_true' );
		$this->assertSame( '', NV_oOS_Docs_Hub_Checkout_Installer::detect_existing_base_plugin() );
		remove_filter( 'nvoos_docs_hub_checkout_skip_base_plugin_detection', '__return_true' );
	}

	// ─── REST routes ───────────────────────────────────────────────────

	/**
	 * Non-admins cannot start a checkout session.
	 *
	 * @return void
	 */
	public function test_session_forbidden_for_non_admin() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$request  = new WP_REST_Request( 'POST', '/nvoos-docs/v1/payments/session' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The session endpoint proxies the vendor and returns the Stripe session.
	 *
	 * @return void
	 */
	public function test_session_returns_vendor_payload() {
		$this->vendor_responses['session'] = $this->session_payload();

		$request  = new WP_REST_Request( 'POST', '/nvoos-docs/v1/payments/session' );
		$response = NV_oOS_Docs_Hub_Checkout_REST::create_session( $request );

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$data = $response->get_data();

		$this->assertSame( 'pi_test_secret_12345', $data['client_secret'] );
		$this->assertSame( 'pk_test_12345', $data['publishable_key'] );
		$this->assertSame( 3499, $data['amount'] );
		$this->assertSame( 'usd', $data['currency'] );
		$this->assertTrue( $data['test_mode'] );
		$this->assertSame( 'https://nvdigitalsolutions.com/terms-of-service', $data['terms_url'] );
		$this->assertSame( 1, $this->http_calls );
	}

	/**
	 * An already-licensed site short-circuits without contacting the vendor.
	 *
	 * @return void
	 */
	public function test_session_returns_already_licensed_without_http() {
		NV_oOS_Docs_Hub_Checkout_License::save( array( 'license_key' => 'test-license-key' ) );
		update_option( 'active_plugins', array( NV_oOS_Docs_Hub_Checkout_Installer::BUNDLE_BASENAME ) );

		$request  = new WP_REST_Request( 'POST', '/nvoos-docs/v1/payments/session' );
		$response = NV_oOS_Docs_Hub_Checkout_REST::create_session( $request );

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['already_licensed'] );
		$this->assertSame( 'test-license-key', $data['license_key'] );
		$this->assertSame( 0, $this->http_calls, 'The session endpoint must not contact the vendor for an already-licensed site.' );
	}

	/**
	 * A vendor 4xx surfaces its message with the vendor status preserved.
	 *
	 * @return void
	 */
	public function test_session_vendor_error_passthrough() {
		$this->vendor_responses['session'] = array(
			'status' => 424,
			'body'   => array( 'message' => 'Stripe rejected the session.' ),
		);

		$request  = new WP_REST_Request( 'POST', '/nvoos-docs/v1/payments/session' );
		$response = NV_oOS_Docs_Hub_Checkout_REST::create_session( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 424, $response->get_error_data()['status'] );
		$this->assertSame( 'Stripe rejected the session.', $response->get_error_message() );
	}

	/**
	 * The session endpoint rate-limits after five attempts.
	 *
	 * @return void
	 */
	public function test_session_throttle_blocks_sixth_attempt() {
		$this->vendor_responses['session'] = $this->session_payload();

		$request = new WP_REST_Request( 'POST', '/nvoos-docs/v1/payments/session' );

		for ( $i = 0; $i < 5; $i++ ) {
			$response = NV_oOS_Docs_Hub_Checkout_REST::create_session( $request );
			$this->assertNotInstanceOf( WP_Error::class, $response );
		}

		$response = NV_oOS_Docs_Hub_Checkout_REST::create_session( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 429, $response->get_error_data()['status'] );
		$this->assertSame( 5, $this->http_calls, 'The sixth attempt must be blocked before any HTTP call.' );
	}

	/**
	 * The health probe reports vendor reachability.
	 *
	 * @return void
	 */
	public function test_health_probe_reports_reachable_vendor() {
		$this->vendor_responses['health'] = array(
			'status' => 200,
			'body'   => array(
				'status'     => 'ok',
				'service'    => 'nvoos-checkout',
				'version'    => '0.1.2',
				'configured' => true,
			),
		);

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/payments/health' );
		$response = $this->server->dispatch( $request );

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['reachable'] );
		$this->assertSame( 'nvoos-checkout', $data['vendor']['service'] );
		$this->assertSame( 1, $this->http_calls );
	}

	/**
	 * Verify records the license, then the base-plugin guard blocks install.
	 *
	 * The response must still carry the signed download URL and the
	 * licensed=true flag so the buyer can install manually.
	 *
	 * @return void
	 */
	public function test_verify_records_license_then_install_conflict() {
		$this->vendor_responses['verify'] = $this->verify_payload();

		$request = new WP_REST_Request( 'POST', '/nvoos-docs/v1/payments/verify' );
		$request->set_param( 'payment_intent', 'pi_1234567890abcdef' );
		$request->set_param( 'terms_agreed_at', time() - 60 );
		$request->set_param( 'buyer_email', 'buyer@example.com' );
		$request->set_param( 'buyer_country', 'DE' );

		$response = NV_oOS_Docs_Hub_Checkout_REST::verify_payment( $request );

		// The license was recorded before the installer ran.
		$this->assertTrue( NV_oOS_Docs_Hub_Checkout_License::is_licensed() );
		$record = NV_oOS_Docs_Hub_Checkout_License::get();
		$this->assertSame( 'test-license-key', $record['license_key'] );
		$this->assertSame( 'buyer@example.com', $record['buyer_email'] );
		$this->assertSame( 'DE', $record['buyer_country'] );
		$this->assertSame( 'pi_1234567890abcdef', $record['stripe_payment_intent'] );

		// The install step hit the base-plugin conflict guard — the error
		// carries the manual download URL and the licensed flag.
		$this->assertInstanceOf( WP_Error::class, $response );
		$data = $response->get_error_data();
		$this->assertTrue( $data['licensed'] );
		$this->assertSame( 'https://nvdigitalsolutions.com/downloads/complete.zip', $data['zip_url'] );
		$this->assertSame( 1, $this->http_calls, 'Only the vendor verify call — no download attempt.' );
	}

	/**
	 * Verify short-circuits for an already-licensed site without HTTP.
	 *
	 * @return void
	 */
	public function test_verify_already_licensed_short_circuit() {
		NV_oOS_Docs_Hub_Checkout_License::save( array( 'license_key' => 'test-license-key' ) );
		update_option( 'active_plugins', array( NV_oOS_Docs_Hub_Checkout_Installer::BUNDLE_BASENAME ) );

		$request = new WP_REST_Request( 'POST', '/nvoos-docs/v1/payments/verify' );
		$request->set_param( 'payment_intent', 'pi_1234567890abcdef' );

		$response = NV_oOS_Docs_Hub_Checkout_REST::verify_payment( $request );

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['licensed'] );
		$this->assertSame( 'test-license-key', $data['license_key'] );
		$this->assertSame( 0, $this->http_calls, 'The verify endpoint must not contact the vendor for an already-licensed site.' );
	}

	/**
	 * A malformed payment intent fails REST argument validation.
	 *
	 * @return void
	 */
	public function test_verify_rejects_malformed_intent() {
		$request = new WP_REST_Request( 'POST', '/nvoos-docs/v1/payments/verify' );
		$request->set_param( 'payment_intent', 'not-an-intent' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 0, $this->http_calls );
	}

	// ─── Settings-page upsell card ────────────────────────────────────

	/**
	 * The upsell card renders while the Complete bundle is not active.
	 *
	 * @return void
	 */
	public function test_upsell_card_renders_when_bundle_inactive() {
		$html = $this->capture_settings_page();

		$this->assertStringContainsString( 'nvoos-docs-hub-buy-complete', $html );
		$this->assertStringContainsString( 'Unlock AI-powered features', $html );
	}

	/**
	 * The upsell card is hidden once the Complete bundle is active.
	 *
	 * @return void
	 */
	public function test_upsell_card_hidden_when_bundle_active() {
		update_option( 'active_plugins', array( NV_oOS_Docs_Hub_Checkout_Installer::BUNDLE_BASENAME ) );

		$html = $this->capture_settings_page();

		$this->assertStringNotContainsString( 'nvoos-docs-hub-buy-complete', $html );
		$this->assertStringNotContainsString( 'Unlock AI-powered features', $html );
	}

	/**
	 * Render the settings page and return the captured markup.
	 *
	 * @return string
	 */
	private function capture_settings_page() {
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}

		ob_start();
		NV_oOS_Docs_Hub_Settings::render_page();
		return (string) ob_get_clean();
	}
}
