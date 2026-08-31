<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Commerce;

use NvoosContentGraph\Commerce\Installer;
use NvoosContentGraph\Commerce\License;
use NvoosContentGraph\Commerce\Payments;
use NvoosContentGraph\Commerce\Vendor;
use NvoosContentGraph\Rest\CommerceController;
use NvoosContentGraph\Schema;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Unit tests for the addon purchase flow.
 *
 * The vendor checkout API is short-circuited via the `pre_http_request`
 * filter; no real network requests are made.
 *
 * @since 1.0.4
 */
class CommerceTest extends TestCase {

	/** @var int Admin user ID used across tests. */
	private int $adminId;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->adminId = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->adminId );

		delete_option( Schema::OPTION_LICENSE );
		delete_option( Schema::OPTION_SETTINGS );
		delete_transient( Schema::TRANSIENT_PREFIX . 'commerce_session_throttle_' . $this->adminId );
		delete_transient( Schema::TRANSIENT_PREFIX . 'commerce_verify_throttle_' . $this->adminId );
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( Schema::OPTION_LICENSE );
		delete_option( Schema::OPTION_SETTINGS );
		parent::tearDown();
	}

	/**
	 * Stub the vendor API with a sequence of JSON responses.
	 *
	 * @param array<int,array<string,mixed>> $responses Response arrays in call order.
	 * @return void
	 */
	private function stubVendor( array $responses ): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls, $responses ) {
				$index = min( $calls, count( $responses ) - 1 );
				$calls++;
				return $responses[ $index ];
			},
			10,
			0
		);
	}

	/**
	 * A valid vendor /session response.
	 *
	 * @return array<string,mixed>
	 */
	private function sessionResponse(): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'client_secret'   => 'pi_test_secret_abc',
					'publishable_key' => 'pk_test_abc',
					'amount'          => 4900,
					'currency'        => 'usd',
					'test_mode'       => true,
				)
			),
		);
	}

	/** @test */
	public function defaultPriceIs4900Cents(): void {
		$this->assertSame( 4900, Payments::priceCents() );
	}

	/** @test */
	public function priceFilterIsApplied(): void {
		add_filter( Schema::FILTER_PRICE_CENTS, static fn() => 9900 );
		$this->assertSame( 9900, Payments::priceCents() );
		remove_all_filters( Schema::FILTER_PRICE_CENTS );
	}

	/** @test */
	public function vendorApiUrlIsFilterable(): void {
		$this->assertNotSame( '', Payments::vendorApiUrl() );
		$this->assertTrue( Payments::isConfigured() );

		add_filter( Schema::FILTER_VENDOR_API_URL, static fn() => '' );
		$this->assertSame( '', Payments::vendorApiUrl() );
		$this->assertFalse( Payments::isConfigured() );
		remove_all_filters( Schema::FILTER_VENDOR_API_URL );
	}

	/** @test */
	public function licenseKeyRoundTrips(): void {
		$this->assertFalse( License::isLicensed() );

		$key = License::generateKey();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{40}$/', $key );

		License::save( array( 'license_key' => $key ) );
		$this->assertTrue( License::isLicensed() );
		$this->assertSame( $key, License::licenseKey() );
	}

	/** @test */
	public function installerReportsInstalledState(): void {
		$this->assertIsBool( Installer::isInstalled() );
		$this->assertIsBool( Installer::isActive() );
	}

	/** @test */
	public function sessionCreationReturnsClientSecret(): void {
		$this->stubVendor( array( $this->sessionResponse() ) );

		$controller = new CommerceController();
		$response   = $controller->createSession( new \WP_REST_Request() );

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'pi_test_secret_abc', $data['client_secret'] );
		$this->assertSame( 'pk_test_abc', $data['publishable_key'] );
		$this->assertTrue( $data['test_mode'] );
	}

	/** @test */
	public function sessionCreationSurfacesVendorError(): void {
		$this->stubVendor(
			array(
				array(
					'response' => array( 'code' => 424 ),
					'body'     => wp_json_encode( array( 'message' => 'Checkout not configured.' ) ),
				),
			)
		);

		$controller = new CommerceController();
		$result     = $controller->createSession( new \WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nvoos_content_graph_vendor_error', $result->get_error_code() );
		$this->assertSame( 'Checkout not configured.', $result->get_error_message() );
	}

	/** @test */
	public function sessionCreationIsRateLimited(): void {
		$this->stubVendor( array( $this->sessionResponse() ) );

		$controller = new CommerceController();

		// The throttle fires before the vendor call.
		for ( $i = 0; $i < 5; $i++ ) {
			$controller->createSession( new \WP_REST_Request() );
		}

		$result = $controller->createSession( new \WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nvoos_content_graph_rate_limited', $result->get_error_code() );
	}

	/** @test */
	public function verifyIsRateLimited(): void {
		$this->stubVendor(
			array(
				array(
					'response' => array( 'code' => 402 ),
					'body'     => wp_json_encode( array( 'message' => 'Not completed.' ) ),
				),
			)
		);

		$controller = new CommerceController();
		$request    = new \WP_REST_Request();
		$request->set_param( 'payment_intent', 'pi_1234567890' );

		for ( $i = 0; $i < 15; $i++ ) {
			$controller->verifyPayment( $request );
		}

		$result = $controller->verifyPayment( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nvoos_content_graph_rate_limited', $result->get_error_code() );
	}

	/** @test */
	public function sessionFailsWhenCheckoutUnavailable(): void {
		add_filter( Schema::FILTER_VENDOR_API_URL, static fn() => '' );

		$controller = new CommerceController();
		$result     = $controller->createSession( new \WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nvoos_content_graph_checkout_unavailable', $result->get_error_code() );

		remove_all_filters( Schema::FILTER_VENDOR_API_URL );
	}

	/** @test */
	public function vendorClientSurfacesTransportErrors(): void {
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'http_request_failed', 'Connection refused' ),
			10,
			0
		);

		$vendor = new Vendor( 'https://vendor.example/api' );
		$result = $vendor->createSession();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nvoos_content_graph_vendor_unreachable', $result->get_error_code() );
	}

	/** @test */
	public function verifySurfacesVendorRejection(): void {
		$this->stubVendor(
			array(
				array(
					'response' => array( 'code' => 402 ),
					'body'     => wp_json_encode( array( 'message' => 'This payment has not completed yet.' ) ),
				),
			)
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'payment_intent', 'pi_1234567890' );

		$controller = new CommerceController();
		$result     = $controller->verifyPayment( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nvoos_content_graph_vendor_error', $result->get_error_code() );
		$this->assertSame( 402, $result->get_error_data()['status'] );
		$this->assertFalse( License::isLicensed() );
	}

	/** @test */
	public function verifyRecordsLicenseAndAttemptsInstall(): void {
		$this->stubVendor(
			array(
				// 1. Vendor /verify → license + signed download URL.
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'license_key'   => 'abc123',
							'download_url'  => 'https://vendor.example/download/addon.zip',
							'addon_version' => '1.0.3',
							'amount'        => 4900,
							'currency'      => 'usd',
						)
					),
				),
				// 2. Download of the signed URL → 404.
				array(
					'response' => array( 'code' => 404 ),
					'body'     => 'not found',
				),
			)
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'payment_intent', 'pi_test_paid' );

		$controller = new CommerceController();
		$result     = $controller->verifyPayment( $request );

		// License must be recorded even though the download failed.
		$this->assertTrue( License::isLicensed() );
		$this->assertSame( 'abc123', License::get()['license_key'] );
		$this->assertSame( 'pi_test_paid', License::get()['stripe_payment_intent'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nvoos_content_graph_download_failed', $result->get_error_code() );
		$this->assertArrayHasKey( 'zip_url', $result->get_error_data() );
	}

	/** @test */
	public function verifyFallsBackWhenVendorOmitsDownloadUrl(): void {
		$this->stubVendor(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'license_key'   => 'abc123',
							'addon_version' => '1.0.3',
							'amount'        => 4900,
							'currency'      => 'usd',
						)
					),
				),
				// Fallback GitHub release URL → 404 (no release published yet).
				array(
					'response' => array( 'code' => 404 ),
					'body'     => 'not found',
				),
			)
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'payment_intent', 'pi_test_paid' );

		$controller = new CommerceController();
		$result     = $controller->verifyPayment( $request );

		$this->assertTrue( License::isLicensed() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Payments::zipUrl(), $result->get_error_data()['zip_url'] );
	}
}
