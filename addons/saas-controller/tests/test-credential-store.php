<?php
/**
 * Tests for NVOOS_SaaS_Controller_Credential_Store.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Round-trip + tampering tests for the credential store.
 */
class Test_NVOOS_SaaS_Controller_Credential_Store extends WP_UnitTestCase {

	/**
	 * Reset state before every test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( NVOOS_SaaS_Controller_Credential_Store::OPTION_KEY );
	}

	/**
	 * Round-trip: write each allowed key, read it back in plaintext.
	 *
	 * @return void
	 */
	public function test_set_and_get_round_trip() {
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$input = array(
			'cloudflare_account_id'       => 'abc123',
			'cloudflare_api_token'        => 'cf_token_super_secret_value',
			'stripe_secret_key'           => 'sk_test_1234567890ABCDEF',
			'stripe_webhook_secret'       => 'whsec_xxxxxxxxxxxxxxxxxxxx',
			'openrouter_api_key'          => 'or_key_zzzzzzzzzzzzz',
			'openrouter_provisioning_key' => 'or_pk_provisioning_yyyyy',
		);
		$this->assertTrue( $store->set( $input ) );

		$out = $store->get_all();
		foreach ( $input as $key => $value ) {
			$this->assertSame( $value, $out[ $key ], "Mismatch for {$key}" );
		}
	}

	/**
	 * Unknown keys are ignored.
	 *
	 * @return void
	 */
	public function test_unknown_keys_are_ignored() {
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$store->set(
			array(
				'cloudflare_account_id' => 'allowed',
				'evil_key'              => 'should_not_persist',
			)
		);
		$envelope = get_option( NVOOS_SaaS_Controller_Credential_Store::OPTION_KEY );
		$this->assertIsArray( $envelope );
		$this->assertArrayHasKey( 'cloudflare_account_id', $envelope );
		$this->assertArrayNotHasKey( 'evil_key', $envelope );
	}

	/**
	 * Masked snapshot redacts everything except the last four characters.
	 *
	 * @return void
	 */
	public function test_get_masked_redacts_secrets() {
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$store->set(
			array(
				'stripe_secret_key' => 'sk_test_super_secret_value_abcd',
			)
		);

		$masked = $store->get_masked();
		$this->assertTrue( $masked['stripe_secret_key']['configured'] );
		$this->assertStringEndsWith( 'abcd', $masked['stripe_secret_key']['masked'] );
		$this->assertStringNotContainsString( 'super_secret', $masked['stripe_secret_key']['masked'] );
		$this->assertFalse( $masked['cloudflare_api_token']['configured'] );
		$this->assertSame( '', $masked['cloudflare_api_token']['masked'] );
	}

	/**
	 * Encrypted envelope on disk does NOT contain the plaintext.
	 *
	 * Verifies the at-rest protection promise.
	 *
	 * @return void
	 */
	public function test_envelope_does_not_leak_plaintext() {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			$this->markTestSkipped( 'openssl extension unavailable; b64 fallback would be expected.' );
		}
		$store     = NVOOS_SaaS_Controller_Credential_Store::instance();
		$plaintext = 'PLAINTEXT_NEEDLE_8675309';
		$store->set( array( 'openrouter_api_key' => $plaintext ) );

		$envelope = get_option( NVOOS_SaaS_Controller_Credential_Store::OPTION_KEY );
		$this->assertStringStartsWith( 'enc:', $envelope['openrouter_api_key'] );
		$this->assertStringNotContainsString( $plaintext, $envelope['openrouter_api_key'] );
	}

	/**
	 * Tampered ciphertext decrypts to '' (no exception, no plaintext leak).
	 *
	 * @return void
	 */
	public function test_tampered_envelope_returns_empty() {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			$this->markTestSkipped( 'openssl extension unavailable.' );
		}
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$store->set( array( 'openrouter_api_key' => 'ROUND_TRIP_VALUE' ) );
		// Corrupt the option directly.
		$envelope                       = get_option( NVOOS_SaaS_Controller_Credential_Store::OPTION_KEY );
		$envelope['openrouter_api_key'] = 'enc:' . base64_encode( 'garbage-not-real-cipher-text' );
		update_option( NVOOS_SaaS_Controller_Credential_Store::OPTION_KEY, $envelope, false );

		$out = $store->get_all();
		$this->assertSame( '', $out['openrouter_api_key'] );
	}

	/**
	 * Clear all removes the option entirely.
	 *
	 * @return void
	 */
	public function test_clear_all_removes_option() {
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$store->set( array( 'cloudflare_api_token' => 'X' ) );
		$this->assertNotFalse( get_option( NVOOS_SaaS_Controller_Credential_Store::OPTION_KEY ) );
		$store->clear_all();
		$this->assertFalse( get_option( NVOOS_SaaS_Controller_Credential_Store::OPTION_KEY ) );
	}

	/**
	 * Clear key only removes the requested key.
	 *
	 * @return void
	 */
	public function test_clear_key_only_removes_one() {
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$store->set(
			array(
				'cloudflare_account_id' => 'A',
				'cloudflare_api_token'  => 'B',
			)
		);
		$this->assertTrue( $store->clear_key( 'cloudflare_api_token' ) );
		$out = $store->get_all();
		$this->assertSame( 'A', $out['cloudflare_account_id'] );
		$this->assertSame( '', $out['cloudflare_api_token'] );
	}

	/**
	 * Clear key rejects non-allowlisted keys.
	 *
	 * @return void
	 */
	public function test_clear_key_rejects_unknown_keys() {
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$this->assertFalse( $store->clear_key( 'evil_key' ) );
	}
}
