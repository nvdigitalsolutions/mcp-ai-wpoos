<?php
/**
 * Tests for the license check/activate endpoints.
 *
 * The endpoints are called directly (not through the REST server) so the
 * `WP_Error` from the rate limiter is observable raw — the repo convention for
 * controller tests.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.2.0
 */

/**
 * License endpoint tests.
 *
 * @since 0.2.0
 */
class Test_Checkout_Api_License_Endpoints extends WP_UnitTestCase {

	/**
	 * Controller under test.
	 *
	 * @var NVOOS_Checkout_API_Rest_Controller
	 */
	private $controller;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->controller = new NVOOS_Checkout_API_Rest_Controller();
		NVOOS_Checkout_API_License_Store::install_table();
	}

	/**
	 * Clean up filters.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'nvoos_checkout_license_plan' );
		remove_all_filters( 'nvoos_checkout_license_legacy_paths' );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * Issue a license row and return its key.
	 *
	 * @param array<string,mixed> $overrides Row overrides.
	 * @return string
	 */
	private function issue( array $overrides = array() ): string {
		$key = bin2hex( random_bytes( 20 ) );

		$row = NVOOS_Checkout_API_License_Store::create(
			array_merge(
				array(
					'license_key'           => $key,
					'product'               => 'nvoos-oos-complete',
					'site_url'              => 'https://estate.nvoos.cloud',
					'stripe_payment_intent' => 'pi_' . $key,
				),
				$overrides
			)
		);

		$this->assertIsArray( $row );

		return $key;
	}

	/**
	 * Build a check request.
	 *
	 * @param string $key     License key.
	 * @param string $site    Site or instance id.
	 * @param string $product Product slug.
	 * @return WP_REST_Request
	 */
	private function check_request( string $key, string $site = 'estate.nvoos.cloud', string $product = 'mcp-ai-wpoos-pro' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/nvoos-checkout/v1/license/check' );
		$request->set_param( 'license_key', $key );
		$request->set_param( 'site_url', $site );
		$request->set_param( 'product', $product );

		return $request;
	}

	/**
	 * A live key for the bound site is valid, with a plan.
	 *
	 * @return void
	 */
	public function test_check_returns_valid_for_active_key(): void {
		$key = $this->issue();

		$response = $this->controller->check_license( $this->check_request( $key ) );
		$data     = $response->get_data();

		$this->assertSame( 'valid', $data['status'] );
		$this->assertSame( 'professional', $data['plan'] );
		$this->assertSame( 'nvoos-oos-complete', $data['product'] );
		$this->assertArrayNotHasKey( 'license_key', $data );
	}

	/**
	 * The internal product slug aliases the sold product.
	 *
	 * @return void
	 */
	public function test_check_accepts_product_alias_both_ways(): void {
		$key = $this->issue();

		$this->assertSame( 'valid', $this->controller->check_license( $this->check_request( $key ) )->get_data()['status'] );

		$stored_as_pro = $this->issue( array( 'product' => 'mcp-ai-wpoos-pro' ) );
		$this->assertSame(
			'valid',
			$this->controller->check_license( $this->check_request( $stored_as_pro, 'estate.nvoos.cloud', 'nvoos-oos-complete' ) )->get_data()['status']
		);
	}

	/**
	 * Scheme, path, trailing slash and port do not affect the site binding.
	 *
	 * @return void
	 */
	public function test_check_normalises_the_site_binding(): void {
		$key = $this->issue( array( 'site_url' => 'https://Estate.NVOOS.cloud:443/' ) );

		$this->assertSame( 'valid', $this->controller->check_license( $this->check_request( $key, 'estate.nvoos.cloud' ) )->get_data()['status'] );
	}

	/**
	 * A different host is invalid and does not re-bind the stored site.
	 *
	 * @return void
	 */
	public function test_check_is_invalid_for_another_site(): void {
		$key = $this->issue();

		$data = $this->controller->check_license( $this->check_request( $key, 'attacker.example' ) )->get_data();

		$this->assertSame( 'invalid', $data['status'] );
		$this->assertSame(
			'valid',
			$this->controller->check_license( $this->check_request( $key ) )->get_data()['status'],
			'A rejected site must not rebind the license.'
		);
	}

	/**
	 * A revoked key is invalid.
	 *
	 * @return void
	 */
	public function test_check_is_invalid_for_revoked_key(): void {
		$key = $this->issue();
		NVOOS_Checkout_API_License_Store::revoke( $key );

		$this->assertSame( 'invalid', $this->controller->check_license( $this->check_request( $key ) )->get_data() );
	}

	/**
	 * An unknown key is invalid — same body, no oracle.
	 *
	 * @return void
	 */
	public function test_check_is_invalid_for_unknown_key(): void {
		$data = $this->controller->check_license( $this->check_request( str_repeat( 'a', 40 ) ) )->get_data();

		$this->assertSame( array( 'status' => 'invalid' ), $data );
	}

	/**
	 * A wrong product is invalid.
	 *
	 * @return void
	 */
	public function test_check_is_invalid_for_wrong_product(): void {
		$key = $this->issue();

		$this->assertSame(
			'invalid',
			$this->controller->check_license( $this->check_request( $key, 'estate.nvoos.cloud', 'some-other-product' ) )->get_data()['status']
		);
	}

	/**
	 * Activation reports success and the same plan.
	 *
	 * @return void
	 */
	public function test_activate_reports_success(): void {
		$key = $this->issue();

		$request = $this->check_request( $key );
		$data    = $this->controller->activate_license( $request )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'valid', $data['status'] );
		$this->assertSame( 'professional', $data['plan'] );
	}

	/**
	 * The plan is filterable without a schema change.
	 *
	 * @return void
	 */
	public function test_plan_is_filterable(): void {
		$key = $this->issue();

		add_filter(
			'nvoos_checkout_license_plan',
			static function (): string {
				return 'enterprise';
			}
		);

		$this->assertSame( 'enterprise', $this->controller->check_license( $this->check_request( $key ) )->get_data()['plan'] );
	}

	/**
	 * The per-IP limiter returns 429 after the bucket is spent.
	 *
	 * @return void
	 */
	public function test_check_is_rate_limited(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$key = $this->issue();

		for ( $i = 0; $i < 60; $i++ ) {
			$this->controller->check_license( $this->check_request( $key ) );
		}

		$result = $this->controller->check_license( $this->check_request( $key ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/**
	 * The legacy `/api/licenses/*` rewrites map onto the namespaced routes.
	 *
	 * @return void
	 */
	public function test_legacy_rewrites_are_registered(): void {
		global $wp_rewrite;

		NVOOS_Checkout_API_Plugin::register_license_rewrites();

		$this->assertArrayHasKey( '^api/licenses/check/?$', $wp_rewrite->extra_rules_top );
		$this->assertArrayHasKey( '^api/licenses/activate/?$', $wp_rewrite->extra_rules_top );
	}
}
