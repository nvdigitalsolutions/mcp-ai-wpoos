<?php
/**
 * Tests for credential encryption at rest.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

/**
 * Crypto round-trip tests.
 */
class Test_Checkout_Api_Crypto extends WP_UnitTestCase {

	/**
	 * A value survives encrypt → decrypt.
	 *
	 * @return void
	 */
	public function test_round_trip(): void {
		$secret = 'sk_test_abcdef1234567890';
		$stored = NVOOS_Checkout_API_Crypto::encrypt( $secret );

		$this->assertNotSame( $secret, $stored );
		$this->assertStringStartsWith( NVOOS_Checkout_API_Crypto::PREFIX, $stored );
		$this->assertSame( $secret, NVOOS_Checkout_API_Crypto::decrypt( $stored ) );
	}

	/**
	 * Two encryptions of the same value differ (random IV).
	 *
	 * @return void
	 */
	public function test_random_iv(): void {
		$a = NVOOS_Checkout_API_Crypto::encrypt( 'same-value' );
		$b = NVOOS_Checkout_API_Crypto::encrypt( 'same-value' );

		$this->assertNotSame( $a, $b );
		$this->assertSame( 'same-value', NVOOS_Checkout_API_Crypto::decrypt( $a ) );
		$this->assertSame( 'same-value', NVOOS_Checkout_API_Crypto::decrypt( $b ) );
	}

	/**
	 * Legacy plaintext values pass through unchanged.
	 *
	 * @return void
	 */
	public function test_legacy_plaintext_passthrough(): void {
		$this->assertSame( 'sk_legacy_value', NVOOS_Checkout_API_Crypto::decrypt( 'sk_legacy_value' ) );
	}

	/**
	 * Empty values pass through unchanged.
	 *
	 * @return void
	 */
	public function test_empty_passthrough(): void {
		$this->assertSame( '', NVOOS_Checkout_API_Crypto::encrypt( '' ) );
		$this->assertSame( '', NVOOS_Checkout_API_Crypto::decrypt( '' ) );
	}

	/**
	 * Tampered ciphertext never yields the original value.
	 *
	 * @return void
	 */
	public function test_tampered_ciphertext_fails_safe(): void {
		$stored  = NVOOS_Checkout_API_Crypto::encrypt( 'sk_secret' );
		$tampered = substr( $stored, 0, -2 ) . 'xx';
		$this->assertNotSame( 'sk_secret', NVOOS_Checkout_API_Crypto::decrypt( $tampered ) );
	}
}
