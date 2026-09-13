<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Commerce;

use NvoosContentGraph\Commerce\Installer;
use NvoosContentGraph\Commerce\License;
use NvoosContentGraph\Commerce\Payments;
use NvoosContentGraph\Commerce\Vendor;
use NvoosContentGraph\Rest\CommerceController;
use NvoosContentGraph\Schema;
use WP_Error;
use WP_UnitTestCase;

/**
 * Unit tests for the addon purchase flow.
 *
 * The vendor checkout API is short-circuited via the `pre_http_request`
 * filter; no real network requests are made.
 *
 * @since 1.0.4
 */
class CommerceTest extends WP_UnitTestCase {

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
		remove_all_filters( 'nvoos_content_graph/commerce/skip_base_plugin_detection' );
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
					'client_secret'     => 'pi_test_secret_abc',
					'publishable_key'   => 'pk_test_abc',
					'amount'            => 4900,
					'currency'          => 'usd',
					'test_mode'         => true,
					'terms_url'         => 'https://vendor.example/terms',
					'refund_policy_url' => 'https://vendor.example/refunds',
				)
			),
		);
	}

	/** @test */
	public function defaultPriceIs3499Cents(): void {
		$this->assertSame( 3499, Payments::priceCents() );
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
	public function fallbackProductUrlDefaultsToReleasesPage(): void {
		$this->assertSame(
			'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases',
			Payments::fallbackProductUrl()
		);
	}

	/** @test */
	public function fallbackProductUrlIsFilterable(): void {
		add_filter( Schema::FILTER_FALLBACK_URL, static fn() => 'https://example.com/buy' );
		$this->assertSame( 'https://example.com/buy', Payments::fallbackProductUrl() );

		// An empty value disables the redirect fallback (JS keeps the in-modal error).
		add_filter( Schema::FILTER_FALLBACK_URL, static fn() => '' );
		$this->assertSame( '', Payments::fallbackProductUrl() );
		remove_all_filters( Schema::FILTER_FALLBACK_URL );
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
		$this->assertIsBool( Installer::isBundleInstalled() );
		$this->assertIsBool( Installer::isBundleActive() );
	}

	/** @test */
	public function purchasePayloadUsesCompleteProduct(): void {
		$payload = Payments::purchasePayload();

		$this->assertSame( Schema::PRODUCT_COMPLETE, $payload['product'] );
		$this->assertSame( 'nvoos-oos-complete', $payload['product'] );
		$this->assertSame( home_url( '' ), $payload['site_url'] );
		$this->assertSame( Payments::addonVersion(), $payload['addon_version'] );
	}

	/** @test */
	public function zipUrlTargetsCompleteReleaseAsset(): void {
		$url = Payments::zipUrl();

		$this->assertStringContainsString( '/releases/download/nvdigital-oos-v', $url );
		$this->assertStringContainsString( 'nvdigital-open-operator-system-oos-complete-', $url );
		$this->assertStringEndsWith( '.zip', $url );
	}

	/** @test */
	public function installerDetectsExistingBasePlugin(): void {
		// A known NV oOS distribution folder present on disk must be detected.
		$fakeDir  = WP_PLUGIN_DIR . '/nvdigital-open-operator-system-oos';
		$fakeFile = $fakeDir . '/nvdigital-open-operator-system-oos.php';

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture plugin folder; native ops are the test seam.
		if ( ! is_dir( $fakeDir ) ) {
			mkdir( $fakeDir, 0777, true );
		}
		touch( $fakeFile );

		try {
			$detected = Installer::detectExistingBasePlugin();
			$this->assertNotSame( '', $detected );
			$this->assertContains( $detected, Installer::KNOWN_BASE_PLUGINS );
		} finally {
			unlink( $fakeFile );
			rmdir( $fakeDir );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/** @test */
	public function installAbortsWhenBasePluginAlreadyInstalled(): void {
		// The test environment must contain some known base-plugin folder;
		// create one when the mount is absent.
		$fakeDir  = WP_PLUGIN_DIR . '/nvdigital-open-operator-system-oos';
		$fakeFile = $fakeDir . '/nvdigital-open-operator-system-oos.php';
		$created  = ! is_dir( $fakeDir );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture plugin folder; native ops are the test seam.
		if ( $created ) {
			mkdir( $fakeDir, 0777, true );
			touch( $fakeFile );
		}

		// Any HTTP attempt would mean the guard failed — count them.
		$http_calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$http_calls ) {
				$http_calls++;
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => '',
				);
			},
			10,
			0
		);

		try {
			$result = Installer::install( 'https://vendor.example/download/complete.zip' );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'nvoos_content_graph_base_plugin_exists', $result->get_error_code() );
			$data = $result->get_error_data();
			$this->assertTrue( $data['manual'] );
			$this->assertSame( 'https://vendor.example/download/complete.zip', $data['zip_url'] );
			$this->assertSame( 0, $http_calls, 'The installer must not download when a base plugin exists.' );
		} finally {
			if ( $created ) {
				unlink( $fakeFile );
				rmdir( $fakeDir );
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
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
		$this->assertSame( 'https://vendor.example/terms', $data['terms_url'] );
		$this->assertSame( 'https://vendor.example/refunds', $data['refund_policy_url'] );
	}

	/** @test */
	public function sessionFallsBackToDefaultLegalUrls(): void {
		$this->stubVendor(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'client_secret'   => 'pi_test_secret_abc',
							'publishable_key' => 'pk_test_abc',
							'amount'          => 4900,
							'currency'        => 'usd',
							'test_mode'       => true,
							// No terms/refund URLs — a legacy vendor.
						)
					),
				),
			)
		);

		$controller = new CommerceController();
		$response   = $controller->createSession( new \WP_REST_Request() );

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$data = $response->get_data();
		$this->assertSame( esc_url_raw( Payments::termsUrl() ), $data['terms_url'] );
		$this->assertSame( esc_url_raw( Payments::refundPolicyUrl() ), $data['refund_policy_url'] );
	}

	/** @test */
	public function sessionRejectsNonHttpLegalUrls(): void {
		$this->stubVendor(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'client_secret'     => 'pi_test_secret_abc',
							'publishable_key'   => 'pk_test_abc',
							'amount'            => 4900,
							'currency'          => 'usd',
							'test_mode'         => true,
							'terms_url'         => 'javascript:alert(1)',
							'refund_policy_url' => 'ftp://vendor.example/refunds',
						)
					),
				),
			)
		);

		$controller = new CommerceController();
		$response   = $controller->createSession( new \WP_REST_Request() );

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$data = $response->get_data();
		$this->assertSame( esc_url_raw( Payments::termsUrl() ), $data['terms_url'] );
		$this->assertSame( esc_url_raw( Payments::refundPolicyUrl() ), $data['refund_policy_url'] );
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

		// A vendor 4xx rejection (Stripe 424) passes through unchanged so
		// the modal shows the message instead of redirecting.
		$this->assertSame( 424, $result->get_error_data()['status'] );
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
	public function sessionReturnsAlreadyLicensedWhenBundleActive(): void {
		License::save( array( 'license_key' => 'test-license-key' ) );

		// Simulate the Complete bundle being the active artifact.
		add_filter( 'option_active_plugins', static fn() => array( Installer::BUNDLE_BASENAME ) );

		// Any HTTP attempt would mean the gate failed — count them.
		$http_calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$http_calls ) {
				$http_calls++;
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => '',
				);
			},
			10,
			0
		);

		try {
			$controller = new CommerceController();
			$response   = $controller->createSession( new \WP_REST_Request() );

			$this->assertNotInstanceOf( WP_Error::class, $response );
			$data = $response->get_data();
			$this->assertTrue( $data['already_licensed'] );
			$this->assertTrue( $data['bundle_active'] );
			$this->assertSame( 'test-license-key', $data['license_key'] );
			$this->assertSame( 0, $http_calls, 'The session endpoint must not contact the vendor for an already-licensed site.' );
		} finally {
			remove_all_filters( 'option_active_plugins' );
		}
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
	public function verifyShortCircuitsWhenLicensedAndAddonActive(): void {
		License::save( array( 'license_key' => 'test-license-key' ) );

		// Simulate the legacy AI addon being the active artifact.
		add_filter( 'option_active_plugins', static fn() => array( Installer::ADDON_BASENAME ) );

		// Any HTTP attempt would mean the short-circuit failed — count them.
		$http_calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$http_calls ) {
				$http_calls++;
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => '',
				);
			},
			10,
			0
		);

		try {
			$controller = new CommerceController();
			$request    = new \WP_REST_Request();
			$request->set_param( 'payment_intent', 'pi_1234567890' );
			$response = $controller->verifyPayment( $request );

			$this->assertNotInstanceOf( WP_Error::class, $response );
			$data = $response->get_data();
			$this->assertTrue( $data['licensed'] );
			$this->assertFalse( $data['bundle_active'], 'The legacy AI addon is not the Complete bundle.' );
			$this->assertSame( 'test-license-key', $data['license_key'] );
			$this->assertStringContainsString( 'AI addon', $data['message'] );
			$this->assertSame( 0, $http_calls, 'The verify endpoint must not contact the vendor when the site is already licensed and active.' );
		} finally {
			remove_all_filters( 'option_active_plugins' );
		}
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
		// The test environment may itself live inside a folder the base-plugin
		// guard detects — skip detection so the download path is exercised.
		add_filter( 'nvoos_content_graph/commerce/skip_base_plugin_detection', '__return_true' );

		$this->stubVendor(
			array(
				// 1. Vendor /verify → license + signed download URL.
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'license_key'   => 'abc123',
							'download_url'  => 'https://vendor.example/download/addon.zip',
							'addon_version' => '1.0.4',
							'amount'        => 4900,
							'currency'      => 'usd',
						)
					),
				),
				// 2. Download of the signed URL → 404.
				array(
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
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
		// See verifyRecordsLicenseAndAttemptsInstall: skip the base-plugin
		// guard so the fallback download path is exercised.
		add_filter( 'nvoos_content_graph/commerce/skip_base_plugin_detection', '__return_true' );

		$this->stubVendor(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'license_key'   => 'abc123',
							'addon_version' => '1.0.4',
							'amount'        => 4900,
							'currency'      => 'usd',
						)
					),
				),
				// Fallback GitHub release URL → 404 (no release published yet).
				array(
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
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

	/** @test */
	public function vendorVerifyForwardsConsentTimestamp(): void {
		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$captured ) {
				$captured = json_decode( (string) $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				);
			},
			10,
			2
		);

		$consentAt = time();

		$vendor = new Vendor( 'https://vendor.example/api' );
		$vendor->verify( 'pi_test_consent', $consentAt );

		$this->assertIsArray( $captured );
		$this->assertSame( $consentAt, $captured['terms_agreed_at'] );
		$this->assertSame( 'pi_test_consent', $captured['payment_intent'] );

		remove_all_filters( 'pre_http_request' );
	}

	/** @test */
	public function vendorVerifyOmitsConsentTimestampWhenAbsent(): void {
		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$captured ) {
				$captured = json_decode( (string) $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				);
			},
			10,
			2
		);

		$vendor = new Vendor( 'https://vendor.example/api' );
		$vendor->verify( 'pi_test_no_consent' );

		$this->assertIsArray( $captured );
		$this->assertArrayNotHasKey( 'terms_agreed_at', $captured );

		remove_all_filters( 'pre_http_request' );
	}

	/** @test */
	public function verifyRecordsConsentInLicenseRecord(): void {
		add_filter( 'nvoos_content_graph/commerce/skip_base_plugin_detection', '__return_true' );

		$this->stubVendor(
			array(
				// 1. Vendor /verify → license + signed download URL.
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'license_key'   => 'consent123',
							'download_url'  => 'https://vendor.example/download/addon.zip',
							'addon_version' => '1.0.4',
							'amount'        => 4900,
							'currency'      => 'usd',
						)
					),
				),
				// 2. Download of the signed URL → 404.
				array(
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'body'     => 'not found',
				),
			)
		);

		$consentAt = time();

		$request = new \WP_REST_Request();
		$request->set_param( 'payment_intent', 'pi_test_consent_paid' );
		$request->set_param( 'terms_agreed_at', $consentAt );

		$controller = new CommerceController();
		$controller->verifyPayment( $request );

		$this->assertTrue( License::isLicensed() );
		$this->assertSame( $consentAt, License::get()['terms_agreed_at'] );
	}

	/** @test */
	public function vendorVerifyForwardsBuyerEmail(): void {
		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$captured ) {
				$captured = json_decode( (string) $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				);
			},
			10,
			2
		);

		$vendor = new Vendor( 'https://vendor.example/api' );
		$vendor->verify( 'pi_test_email', 0, ' buyer@example.com ' );

		$this->assertIsArray( $captured );
		$this->assertSame( 'buyer@example.com', $captured['buyer_email'] );

		remove_all_filters( 'pre_http_request' );
	}

	/** @test */
	public function vendorVerifyOmitsBuyerEmailWhenAbsent(): void {
		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$captured ) {
				$captured = json_decode( (string) $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				);
			},
			10,
			2
		);

		$vendor = new Vendor( 'https://vendor.example/api' );
		$vendor->verify( 'pi_test_no_email' );

		$this->assertIsArray( $captured );
		$this->assertArrayNotHasKey( 'buyer_email', $captured );

		remove_all_filters( 'pre_http_request' );
	}

	/** @test */
	public function verifyRecordsBuyerEmailInLicenseRecord(): void {
		add_filter( 'nvoos_content_graph/commerce/skip_base_plugin_detection', '__return_true' );

		$this->stubVendor(
			array(
				// 1. Vendor /verify → license + signed download URL.
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'license_key'   => 'email123',
							'download_url'  => 'https://vendor.example/download/addon.zip',
							'addon_version' => '1.0.4',
							'amount'        => 4900,
							'currency'      => 'usd',
						)
					),
				),
				// 2. Download of the signed URL → 404.
				array(
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'body'     => 'not found',
				),
			)
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'payment_intent', 'pi_test_email_paid' );
		$request->set_param( 'buyer_email', 'buyer@example.com' );

		$controller = new CommerceController();
		$controller->verifyPayment( $request );

		$this->assertTrue( License::isLicensed() );
		$this->assertSame( 'buyer@example.com', License::get()['buyer_email'] );
	}

	/** @test */
	public function vendorVerifyForwardsBuyerCountry(): void {
		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$captured ) {
				$captured = json_decode( (string) $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				);
			},
			10,
			2
		);

		$vendor = new Vendor( 'https://vendor.example/api' );
		$vendor->verify( 'pi_test_country', 0, '', 'de' );

		$this->assertIsArray( $captured );
		$this->assertSame( 'DE', $captured['buyer_country'] );

		remove_all_filters( 'pre_http_request' );
	}

	/** @test */
	public function vendorVerifyOmitsBuyerCountryWhenAbsent(): void {
		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$captured ) {
				$captured = json_decode( (string) $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				);
			},
			10,
			2
		);

		$vendor = new Vendor( 'https://vendor.example/api' );
		$vendor->verify( 'pi_test_no_country' );

		$this->assertIsArray( $captured );
		$this->assertArrayNotHasKey( 'buyer_country', $captured );

		remove_all_filters( 'pre_http_request' );
	}

	/** @test */
	public function euCountryCodesDefaultsAndIsFilterable(): void {
		$codes = Payments::euCountryCodes();

		$this->assertCount( 27, $codes );
		$this->assertContains( 'DE', $codes );
		$this->assertContains( 'FR', $codes );
		$this->assertNotContains( 'GB', $codes );
		$this->assertNotContains( 'US', $codes );

		add_filter( Schema::FILTER_EU_COUNTRIES, static fn() => array( 'DE', 'not-a-code', 'FR' ) );
		$filtered = Payments::euCountryCodes();
		$this->assertSame( array( 'DE', 'FR' ), $filtered );
		remove_all_filters( Schema::FILTER_EU_COUNTRIES );
	}

	/** @test */
	public function verifyRecordsBuyerCountryInLicenseRecord(): void {
		add_filter( 'nvoos_content_graph/commerce/skip_base_plugin_detection', '__return_true' );

		$this->stubVendor(
			array(
				// 1. Vendor /verify → license + signed download URL.
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'license_key'   => 'country123',
							'download_url'  => 'https://vendor.example/download/addon.zip',
							'addon_version' => '1.0.4',
							'amount'        => 4900,
							'currency'      => 'usd',
						)
					),
				),
				// 2. Download of the signed URL → 404.
				array(
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'body'     => 'not found',
				),
			)
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'payment_intent', 'pi_test_country_paid' );
		$request->set_param( 'buyer_country', 'IE' );

		$controller = new CommerceController();
		$controller->verifyPayment( $request );

		$this->assertTrue( License::isLicensed() );
		$this->assertSame( 'IE', License::get()['buyer_country'] );
	}

	/** @test */
	public function roadmapUrlDefaultsToDiscussions(): void {
		$this->assertSame(
			'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/discussions',
			Payments::roadmapUrl()
		);
	}

	/** @test */
	public function roadmapUrlIsFilterable(): void {
		add_filter( Schema::FILTER_ROADMAP_URL, static fn() => 'https://example.com/roadmap' );
		$this->assertSame( 'https://example.com/roadmap', Payments::roadmapUrl() );
		remove_all_filters( Schema::FILTER_ROADMAP_URL );
	}

	/** @test */
	public function changelogUrlDefaultsToReleases(): void {
		$this->assertSame(
			'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases',
			Payments::changelogUrl()
		);
	}

	/** @test */
	public function changelogUrlIsFilterable(): void {
		add_filter( Schema::FILTER_CHANGELOG_URL, static fn() => 'https://example.com/changelog' );
		$this->assertSame( 'https://example.com/changelog', Payments::changelogUrl() );
		remove_all_filters( Schema::FILTER_CHANGELOG_URL );
	}

	/** @test */
	public function supportEmailDefaultsToVendorAddress(): void {
		$this->assertSame( 'support@nvdigitalsolutions.com', Payments::supportEmail() );
	}

	/** @test */
	public function supportEmailIsFilterable(): void {
		add_filter( Schema::FILTER_SUPPORT_EMAIL, static fn() => 'help@example.com' );
		$this->assertSame( 'help@example.com', Payments::supportEmail() );
		remove_all_filters( Schema::FILTER_SUPPORT_EMAIL );
	}

	/** @test */
	public function vendorHealthReportsReachableService(): void {
		$this->stubVendor(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'status'      => 'ok',
							'service'     => 'nvoos-checkout',
							'version'     => '0.1.1',
							'configured'  => true,
							'server_time' => time(),
						)
					),
				),
			)
		);

		$vendor = new Vendor( 'https://vendor.example/api' );
		$health = $vendor->health();

		$this->assertIsArray( $health );
		$this->assertSame( 'ok', $health['status'] );
		$this->assertSame( 'nvoos-checkout', $health['service'] );
	}

	/** @test */
	public function vendorHealthReportsTransportFailure(): void {
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
			10,
			0
		);

		$vendor = new Vendor( 'https://vendor.example/api' );
		$health = $vendor->health();

		$this->assertInstanceOf( WP_Error::class, $health );
		$this->assertSame( 'nvoos_content_graph_vendor_unreachable', $health->get_error_code() );
		$this->assertStringContainsString( 'cURL error 28', $health->get_error_message() );
	}

	/** @test */
	public function vendorHealthSurfacesVendorError(): void {
		$this->stubVendor(
			array(
				array(
					'response' => array( 'code' => 500 ),
					'body'     => 'boom',
				),
			)
		);

		$vendor = new Vendor( 'https://vendor.example/api' );
		$health = $vendor->health();

		$this->assertInstanceOf( WP_Error::class, $health );
		$this->assertSame( 'nvoos_content_graph_vendor_error', $health->get_error_code() );
	}

	/** @test */
	public function healthCheckReportsReachableVendor(): void {
		$this->stubVendor(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'status'      => 'ok',
							'service'     => 'nvoos-checkout',
							'version'     => '0.1.1',
							'configured'  => true,
							'server_time' => time(),
						)
					),
				),
			)
		);

		$controller = new CommerceController();
		$response   = $controller->checkHealth();

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['reachable'] );
		$this->assertTrue( $data['configured'] );
		$this->assertIsInt( $data['latency_ms'] );
		$this->assertSame( 'nvoos-checkout', $data['vendor']['service'] );
	}

	/** @test */
	public function healthCheckIsNotThrottledWhenSessionBucketExhausted(): void {
		$this->stubVendor(
			array(
				// The session bucket exhausts before the vendor is called.
				$this->sessionResponse(),
				$this->sessionResponse(),
				$this->sessionResponse(),
				$this->sessionResponse(),
				$this->sessionResponse(),
				// The health probe must still reach the vendor afterwards.
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'status'      => 'ok',
							'service'     => 'nvoos-checkout',
							'version'     => '0.1.1',
							'configured'  => true,
							'server_time' => time(),
						)
					),
				),
			)
		);

		$controller = new CommerceController();

		for ( $i = 0; $i < 5; $i++ ) {
			$controller->createSession( new \WP_REST_Request() );
		}

		// The 6th session request would be throttled…
		$throttled = $controller->createSession( new \WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $throttled );
		$this->assertSame( 'nvoos_content_graph_rate_limited', $throttled->get_error_code() );

		// …but the diagnostic probe is not, so admins can still find out
		// whether the vendor is reachable while locked out.
		$response = $controller->checkHealth();
		$this->assertNotInstanceOf( WP_Error::class, $response );
		$this->assertTrue( $response->get_data()['reachable'] );
	}

	/** @test */
	public function healthCheckReportsUnconfiguredBuild(): void {
		add_filter( Schema::FILTER_VENDOR_API_URL, static fn() => '' );

		$controller = new CommerceController();
		$response   = $controller->checkHealth();

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$data = $response->get_data();
		$this->assertFalse( $data['reachable'] );
		$this->assertFalse( $data['configured'] );

		remove_all_filters( Schema::FILTER_VENDOR_API_URL );
	}
}
