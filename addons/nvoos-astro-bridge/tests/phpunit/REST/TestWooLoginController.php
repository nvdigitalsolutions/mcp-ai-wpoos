<?php
/**
 * PHPUnit tests for the Woo SSO handoff route (Proposal 108 Phase 3):
 * the owner toggle gate, token shape/expiry/single-use semantics, and the
 * redirect-to-checkout flow with a real auth cookie.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\REST\WooLoginController;
use NvoosAstroBridge\Schema;

/**
 * Woo SSO route tests.
 *
 * @covers \NvoosAstroBridge\REST\WooLoginController
 * @group nvoos-astro-bridge
 */
class TestWooLoginController extends WP_Test_REST_TestCase {

	/**
	 * Admin user id used by the flow assertions.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test state: REST routes, option defaults, admin user.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		delete_option( Schema::OPTION_WOO_SSO_ENABLED );
		update_option( Schema::OPTION_SHOP, Schema::shop_defaults() );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
	}

	/**
	 * Build a token-exchange request.
	 *
	 * @param string $token Raw token.
	 * @return WP_REST_Request
	 */
	private function exchange_request( string $token ): WP_REST_Request {
		return new WP_REST_Request( 'GET', '/nvoos-commerce/v1/woo-login/' . $token );
	}

	/**
	 * Disabled by default — the route 404s with the typed code.
	 */
	public function test_disabled_returns_404(): void {
		$response = rest_get_server()->dispatch( $this->exchange_request( '1.1.abc' ) );
		$this->assertEquals( 404, $response->get_status() );
		$this->assertSame( 'astro_woo_sso_disabled', $response->as_error()->get_error_code() );
	}

	/**
	 * A malformed token is a typed 401 even when enabled.
	 */
	public function test_malformed_token_401(): void {
		update_option( Schema::OPTION_WOO_SSO_ENABLED, 'yes' );

		$response = rest_get_server()->dispatch( $this->exchange_request( 'garbage' ) );
		$this->assertEquals( 401, $response->get_status() );
		$this->assertSame( 'astro_woo_sso_invalid_token', $response->as_error()->get_error_code() );
	}

	/**
	 * The happy path: a minted token signs the shopper in (auth cookie) and
	 * redirects to the checkout URL. The token is single-use — the second
	 * exchange 401s.
	 */
	public function test_valid_token_signs_in_and_redirects_once(): void {
		update_option( Schema::OPTION_WOO_SSO_ENABLED, 'yes' );

		$controller = new WooLoginController();
		$token      = $controller->mint_token( $this->admin_id );

		// wp_set_auth_cookie never populates $_COOKIE under CLI (it writes
		// through setcookie()) — capture the sign-in via the `auth_cookie`
		// filter seam, which carries the user id.
		$signed_in_user = 0;
		add_filter(
			'auth_cookie',
			static function ( $cookie, $user_id ) use ( &$signed_in_user ) {
				$signed_in_user = (int) $user_id;
				return $cookie;
			},
			10,
			2
		);

		$response = rest_get_server()->dispatch( $this->exchange_request( $token ) );
		$this->assertEquals( 302, $response->get_status() );
		$this->assertSame( home_url( '/checkout/' ), $response->get_headers()['Location'] );
		$this->assertSame( $this->admin_id, $signed_in_user );

		// Single use — the transient is consumed.
		$second = rest_get_server()->dispatch( $this->exchange_request( $token ) );
		$this->assertEquals( 401, $second->get_status() );
	}

	/**
	 * A forged token (right shape, wrong HMAC) is a typed 401.
	 */
	public function test_forged_token_401(): void {
		update_option( Schema::OPTION_WOO_SSO_ENABLED, 'yes' );

		$controller = new WooLoginController();
		$controller->mint_token( $this->admin_id );

		$forged   = sprintf( '%d.%d.%s', $this->admin_id, time() + 120, str_repeat( '0', 64 ) );
		$response = rest_get_server()->dispatch( $this->exchange_request( $forged ) );
		$this->assertEquals( 401, $response->get_status() );
		$this->assertSame( 'astro_woo_sso_invalid_token', $response->as_error()->get_error_code() );

		// The real token is still valid afterwards (the forged exchange must
		// not consume it).
		$this->assertNotFalse( get_transient( 'funiq_bridge_woo_sso_' . $this->admin_id ) );
	}
}
