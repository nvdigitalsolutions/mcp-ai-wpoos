<?php
/**
 * Tests for signed download tokens.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

/**
 * Token issue/verify tests.
 */
class Test_Checkout_Api_Token extends WP_UnitTestCase {

	/**
	 * A freshly issued token verifies.
	 *
	 * @return void
	 */
	public function test_issue_and_verify(): void {
		$issued = NVOOS_Checkout_API_Token::issue( 'license-a', HOUR_IN_SECONDS );

		$this->assertArrayHasKey( 'expires', $issued );
		$this->assertArrayHasKey( 'token', $issued );

		$this->assertTrue(
			NVOOS_Checkout_API_Token::verify( 'license-a', $issued['expires'], $issued['token'] )
		);
	}

	/**
	 * A token for another license key fails.
	 *
	 * @return void
	 */
	public function test_wrong_license_fails(): void {
		$issued = NVOOS_Checkout_API_Token::issue( 'license-a' );

		$this->assertFalse(
			NVOOS_Checkout_API_Token::verify( 'license-b', $issued['expires'], $issued['token'] )
		);
	}

	/**
	 * A tampered token fails.
	 *
	 * @return void
	 */
	public function test_tampered_token_fails(): void {
		$issued = NVOOS_Checkout_API_Token::issue( 'license-a' );

		$this->assertFalse(
			NVOOS_Checkout_API_Token::verify( 'license-a', $issued['expires'], $issued['token'] . 'x' )
		);
	}

	/**
	 * An expired token fails.
	 *
	 * @return void
	 */
	public function test_expired_token_fails(): void {
		$issued = NVOOS_Checkout_API_Token::issue( 'license-a', -10 );

		$this->assertFalse(
			NVOOS_Checkout_API_Token::verify( 'license-a', $issued['expires'], $issued['token'] )
		);
	}

	/**
	 * download_url() produces a URL carrying all three parameters.
	 *
	 * @return void
	 */
	public function test_download_url_shape(): void {
		$url  = NVOOS_Checkout_API_Token::download_url( 'license-a' );
		$args = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

		$this->assertSame( '1', $args['nvoos_checkout_download'] ?? '' );
		$this->assertSame( 'license-a', $args['license'] ?? '' );
		$this->assertNotEmpty( $args['expires'] ?? '' );
		$this->assertNotEmpty( $args['token'] ?? '' );
	}
}
