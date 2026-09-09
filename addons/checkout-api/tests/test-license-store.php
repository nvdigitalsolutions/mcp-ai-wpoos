<?php
/**
 * Tests for the license store.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

/**
 * License store tests.
 */
class Test_Checkout_Api_License_Store extends WP_UnitTestCase {

	/**
	 * Ensure the table exists before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		NVOOS_Checkout_API_License_Store::install_table();
	}

	/**
	 * A license can be created and read back by key and payment intent.
	 *
	 * @return void
	 */
	public function test_create_and_read(): void {
		$license = NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => 'test-key-1',
				'product'               => 'nvoos-content-graph-ai',
				'site_url'              => 'https://customer.example',
				'stripe_payment_intent' => 'pi_test_123',
				'stripe_customer'       => 'cus_test_1',
				'amount'                => 4900,
				'currency'              => 'usd',
				'addon_version'         => '1.0.4',
			)
		);

		$this->assertNotWPError( $license );
		$this->assertSame( NVOOS_Checkout_API_License_Store::STATUS_ACTIVE, $license['status'] );

		$by_key = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-1' );
		$this->assertNotNull( $by_key );
		$this->assertSame( 'pi_test_123', $by_key['stripe_payment_intent'] );

		$by_intent = NVOOS_Checkout_API_License_Store::get_by_payment_intent( 'pi_test_123' );
		$this->assertNotNull( $by_intent );
		$this->assertSame( 'test-key-1', $by_intent['license_key'] );
	}

	/**
	 * Revocation flips the status.
	 *
	 * @return void
	 */
	public function test_revoke(): void {
		NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => 'test-key-2',
				'product'               => 'nvoos-content-graph-ai',
				'site_url'              => 'https://customer.example',
				'stripe_payment_intent' => 'pi_test_456',
				'amount'                => 4900,
			)
		);

		$this->assertTrue( NVOOS_Checkout_API_License_Store::revoke( 'test-key-2' ) );

		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-2' );
		$this->assertSame( NVOOS_Checkout_API_License_Store::STATUS_REVOKED, $license['status'] );
	}

	/**
	 * Unknown keys return null.
	 *
	 * @return void
	 */
	public function test_unknown_key_returns_null(): void {
		$this->assertNull( NVOOS_Checkout_API_License_Store::get_by_key( 'does-not-exist' ) );
	}

	/**
	 * The buyer email starts empty and can be recorded exactly once.
	 *
	 * @return void
	 */
	public function test_buyer_email_defaults_empty_and_is_set_once(): void {
		NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => 'test-key-email',
				'product'               => 'nvoos-content-graph-ai',
				'site_url'              => 'https://customer.example',
				'stripe_payment_intent' => 'pi_test_email',
				'amount'                => 4900,
			)
		);

		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-email' );
		$this->assertSame( '', $license['buyer_email'] );

		$this->assertTrue( NVOOS_Checkout_API_License_Store::set_buyer_email( 'test-key-email', 'buyer@example.com' ) );

		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-email' );
		$this->assertSame( 'buyer@example.com', $license['buyer_email'] );

		// A second write targets zero rows (WHERE excludes filled values).
		NVOOS_Checkout_API_License_Store::set_buyer_email( 'test-key-email', 'other@example.com' );
		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-email' );
		$this->assertSame( 'buyer@example.com', $license['buyer_email'] );
	}

	/**
	 * The buyer country starts empty and can be recorded exactly once.
	 *
	 * @return void
	 */
	public function test_buyer_country_defaults_empty_and_is_set_once(): void {
		NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => 'test-key-country',
				'product'               => 'nvoos-content-graph-ai',
				'site_url'              => 'https://customer.example',
				'stripe_payment_intent' => 'pi_test_country',
				'amount'                => 4900,
			)
		);

		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-country' );
		$this->assertSame( '', $license['buyer_country'] );

		$this->assertTrue( NVOOS_Checkout_API_License_Store::set_buyer_country( 'test-key-country', 'DE' ) );

		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-country' );
		$this->assertSame( 'DE', $license['buyer_country'] );

		// A second write targets zero rows (WHERE excludes filled values).
		NVOOS_Checkout_API_License_Store::set_buyer_country( 'test-key-country', 'FR' );
		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-country' );
		$this->assertSame( 'DE', $license['buyer_country'] );
	}

	/**
	 * Consent starts empty (NULL) and can be recorded exactly once.
	 *
	 * @return void
	 */
	public function test_terms_consent_defaults_null_and_is_set_once(): void {
		NVOOS_Checkout_API_License_Store::create(
			array(
				'license_key'           => 'test-key-consent',
				'product'               => 'nvoos-content-graph-ai',
				'site_url'              => 'https://customer.example',
				'stripe_payment_intent' => 'pi_test_consent',
				'amount'                => 4900,
			)
		);

		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-consent' );
		$this->assertNull( $license['terms_agreed_at'] );

		$this->assertTrue( NVOOS_Checkout_API_License_Store::set_terms_agreed( 'test-key-consent', '2026-09-09 12:00:00' ) );

		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-consent' );
		$this->assertSame( '2026-09-09 12:00:00', $license['terms_agreed_at'] );

		// A second write targets zero rows (WHERE excludes filled values).
		NVOOS_Checkout_API_License_Store::set_terms_agreed( 'test-key-consent', '2026-09-09 13:00:00' );
		$license = NVOOS_Checkout_API_License_Store::get_by_key( 'test-key-consent' );
		$this->assertSame( '2026-09-09 12:00:00', $license['terms_agreed_at'] );
	}
}
