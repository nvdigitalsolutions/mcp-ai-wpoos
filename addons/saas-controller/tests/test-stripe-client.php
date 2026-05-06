<?php
/**
 * Tests for NVOOS_SaaS_Controller_Stripe_Client (Phase 6).
 *
 * Stubs all outbound HTTP via `pre_http_request`, mirroring the
 * mutating-Cloudflare-client tests.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * @covers NVOOS_SaaS_Controller_Stripe_Client
 */
class Test_NVOOS_SaaS_Controller_Stripe_Client extends WP_UnitTestCase {

	private $captured = array();
	private $canned   = array();

	public function setUp(): void {
		parent::setUp();
		$this->captured = array();
		$this->canned   = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		parent::tearDown();
	}

	public function intercept( $preempt, $args, $url ) {
		$this->captured[] = array( 'url' => $url, 'args' => $args );
		foreach ( $this->canned as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return $response;
			}
		}
		return new WP_Error( 'no_canned', 'No canned response for ' . $url );
	}

	private function ok_json( $payload, $status = 200 ) {
		return array(
			'response' => array( 'code' => $status, 'message' => 'OK' ),
			'body'     => wp_json_encode( $payload ),
			'headers'  => array(),
		);
	}

	private function err_json( $status = 400, $code = 'parameter_invalid', $msg = 'Invalid product id' ) {
		return array(
			'response' => array( 'code' => $status, 'message' => 'Bad' ),
			'body'     => wp_json_encode( array(
				'error' => array(
					'code'    => $code,
					'message' => $msg,
				),
			) ),
			'headers'  => array(),
		);
	}

	public function test_list_products_returns_keyed_map() {
		$this->canned['/v1/products?'] = $this->ok_json( array(
			'object' => 'list',
			'data'   => array(
				array( 'id' => 'prod_basic', 'name' => 'Basic Plan', 'active' => true ),
				array( 'id' => 'prod_pro',   'name' => 'Pro Plan',   'active' => true ),
			),
		) );

		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_xyz' );
		$out    = $client->list_products( array( 'prod_basic', 'prod_pro' ) );

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'prod_basic', $out );
		$this->assertArrayHasKey( 'prod_pro', $out );
		$this->assertSame( 'Basic Plan', $out['prod_basic']['name'] );

		// Auth header carries the Basic-encoded secret key.
		$auth = $this->captured[0]['args']['headers']['Authorization'];
		$this->assertStringStartsWith( 'Basic ', $auth );
		$this->assertSame( 'sk_test_xyz:', base64_decode( substr( $auth, 6 ) ) );
	}

	public function test_list_products_skips_when_empty() {
		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_xyz' );
		$this->assertSame( array(), $client->list_products( array() ) );
		$this->assertCount( 0, $this->captured, 'No HTTP call should be made for an empty id list.' );
	}

	public function test_list_prices_by_lookup_keys_returns_keyed_map() {
		$this->canned['/v1/prices?'] = $this->ok_json( array(
			'object' => 'list',
			'data'   => array(
				array(
					'id'         => 'price_abc',
					'lookup_key' => 'pro_monthly',
					'product'    => 'prod_pro',
					'currency'   => 'usd',
					'unit_amount' => 1500,
					'recurring'  => array( 'interval' => 'month' ),
				),
			),
		) );

		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_xyz' );
		$out    = $client->list_prices_by_lookup_keys( array( 'pro_monthly' ) );

		$this->assertArrayHasKey( 'pro_monthly', $out );
		$this->assertSame( 'price_abc', $out['pro_monthly']['id'] );
	}

	public function test_create_product_succeeds_and_audits() {
		$this->canned['/v1/products'] = $this->ok_json( array(
			'id'   => 'prod_basic',
			'name' => 'Basic Plan',
		) );

		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_xyz' );
		$out    = $client->create_product( array( 'id' => 'prod_basic', 'name' => 'Basic Plan' ) );

		$this->assertSame( 'prod_basic', $out['id'] );
		$this->assertSame( 'POST', $this->captured[0]['args']['method'] ?? 'POST' );

		// Idempotency-Key header is set (deterministic per id).
		$idem_header = $this->captured[0]['args']['headers']['Idempotency-Key'];
		$this->assertSame( 'nvoos-product-prod_basic', $idem_header );

		// Form-encoded body, not JSON.
		$body = $this->captured[0]['args']['body'];
		$this->assertStringContainsString( 'id=prod_basic', $body );
		$this->assertStringContainsString( 'name=Basic%20Plan', $body );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'stripe', $entries[0]['channel'] );
		$this->assertSame( 'create_stripe_product', $entries[0]['action'] );
		$this->assertSame( 'ok', $entries[0]['status'] );
	}

	public function test_create_product_records_error_audit_on_4xx() {
		$this->canned['/v1/products'] = $this->err_json( 400, 'resource_already_exists', 'A product with this id already exists.' );

		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_xyz' );
		$out    = $client->create_product( array( 'id' => 'prod_dupe', 'name' => 'Dupe' ) );

		$this->assertWPError( $out );
		$this->assertSame( 'stripe_resource_already_exists', $out->get_error_code() );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'error', $entries[0]['status'] );
	}

	public function test_create_product_rejects_missing_fields() {
		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_xyz' );
		$out    = $client->create_product( array( 'id' => 'prod_x' ) );
		$this->assertWPError( $out );
		$this->assertSame( 'invalid_product', $out->get_error_code() );
		$this->assertCount( 0, $this->captured, 'No HTTP call should be made when required fields are missing.' );
	}

	public function test_create_price_uses_idempotency_key_derived_from_tuple() {
		$this->canned['/v1/prices'] = $this->ok_json( array(
			'id'         => 'price_xyz',
			'lookup_key' => 'pro_monthly',
			'product'    => 'prod_pro',
		) );

		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_xyz' );
		$out    = $client->create_price( array(
			'lookup_key'         => 'pro_monthly',
			'product_id'         => 'prod_pro',
			'currency'           => 'usd',
			'unit_amount'        => 1500,
			'recurring_interval' => 'month',
		) );

		$this->assertSame( 'price_xyz', $out['id'] );

		$idem = $this->captured[0]['args']['headers']['Idempotency-Key'];
		$this->assertStringStartsWith( 'nvoos-price-', $idem );

		// The body must contain Stripe's bracket notation for nested fields.
		$body = $this->captured[0]['args']['body'];
		$this->assertStringContainsString( 'recurring%5Binterval%5D=month', $body );
	}

	public function test_create_price_rejects_invalid_input() {
		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_xyz' );
		$out    = $client->create_price( array( 'lookup_key' => 'k', 'product_id' => 'p' ) );
		$this->assertWPError( $out );
		$this->assertSame( 'invalid_price', $out->get_error_code() );
	}

	public function test_from_credential_store_returns_null_when_unset() {
		// No secret_key configured.
		NVOOS_SaaS_Controller_Credential_Store::instance()->clear_all();
		$this->assertNull( NVOOS_SaaS_Controller_Stripe_Client::from_credential_store() );
	}

	public function test_archive_product_succeeds_and_records_audit() {
		$this->canned['/v1/products/prod_basic'] = $this->ok_json( array(
			'id'     => 'prod_basic',
			'active' => false,
		) );
		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_x' );
		$out    = $client->archive_product( 'prod_basic' );

		$this->assertIsArray( $out );
		$this->assertSame( 'prod_basic', $out['id'] );
		$this->assertFalse( $out['active'] );
		$this->assertSame( 'POST', $this->captured[0]['args']['method'] );
		$this->assertStringContainsString( 'active=false', (string) $this->captured[0]['args']['body'] );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'archive_stripe_product', $entries[0]['action'] );
		$this->assertSame( 'ok', $entries[0]['status'] );
	}

	public function test_archive_product_rejects_empty_id() {
		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_x' );
		$out    = $client->archive_product( '' );
		$this->assertWPError( $out );
		$this->assertSame( 'invalid_product', $out->get_error_code() );
	}

	public function test_archive_price_succeeds_and_records_audit() {
		$this->canned['/v1/prices/price_x'] = $this->ok_json( array(
			'id'     => 'price_x',
			'active' => false,
		) );
		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_x' );
		$out    = $client->archive_price( 'price_x' );
		$this->assertSame( 'price_x', $out['id'] );
		$this->assertFalse( $out['active'] );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'archive_stripe_price', $entries[0]['action'] );
	}

	public function test_archive_price_records_error_audit_on_4xx() {
		$this->canned['/v1/prices/price_x'] = $this->err_json( 404, 'resource_missing', 'No such price' );
		$client = new NVOOS_SaaS_Controller_Stripe_Client( 'sk_test_x' );
		$out    = $client->archive_price( 'price_x' );
		$this->assertWPError( $out );
		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'error', $entries[0]['status'] );
	}
}
