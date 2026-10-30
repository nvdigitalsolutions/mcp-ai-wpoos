<?php
/**
 * PHPUnit tests for the bridge's REST cookie-auth shim.
 *
 * Pins the single-site HTTPS fix: core skips logged-in-cookie auth on single
 * sites and wordpress_sec is path-scoped to wp-admin, so without the
 * determine_current_user filter every contract call 401s in production even
 * with a perfectly valid session (the 2026-10-30 /manage outage).
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Plugin;
use NvoosAstroBridge\Schema;

/**
 * REST cookie-auth shim tests.
 *
 * @covers \NvoosAstroBridge\Plugin::determine_rest_user_from_logged_in_cookie
 * @covers \NvoosAstroBridge\Plugin::is_bridge_rest_request
 * @group nvoos-astro-bridge
 */
class TestRestCookieAuth extends WP_UnitTestCase {

	/**
	 * Ambient auth cookie (the bootstrap's test-admin cookie), restored in tearDown.
	 *
	 * @var string|null
	 */
	private $saved_cookie;

	/**
	 * Ambient request URI, restored in tearDown.
	 *
	 * @var string|null
	 */
	private $saved_uri;

	/**
	 * Ambient REST nonce header, restored in tearDown.
	 *
	 * @var string|null
	 */
	private $saved_nonce;

	/**
	 * Snapshot the ambient auth state and make sure REST_REQUEST is defined.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Snapshot/restore of the bootstrap's ambient auth state; no input processing.
		$this->saved_cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : null;
		$this->saved_uri    = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		$this->saved_nonce  = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? $_SERVER['HTTP_X_WP_NONCE'] : null;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		unset( $GLOBALS['wp_rest_auth_cookie'] );
	}

	/**
	 * Restore the ambient auth cookie, URI and nonce.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_rest_auth_cookie'] );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		if ( null === $this->saved_cookie ) {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		} else {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $this->saved_cookie;
		}
		if ( null === $this->saved_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->saved_uri;
		}
		if ( null === $this->saved_nonce ) {
			unset( $_SERVER['HTTP_X_WP_NONCE'] );
		} else {
			$_SERVER['HTTP_X_WP_NONCE'] = $this->saved_nonce;
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		parent::tearDown();
	}

	/**
	 * Simulate the production request: no nonce, no secure auth cookie — only
	 * the path=/-scoped logged-in cookie a browser actually sends to /api/*.
	 *
	 * The $current_user global is unset (never force-set to 0): _wp_get_current_user()
	 * consults the determine_current_user chain ONLY when the global is untouched,
	 * which is the state a fresh request arrives in.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	private function act_cookie_only( int $user_id ): void {
		unset( $GLOBALS['current_user'] );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test fixture: simulates the incoming cookie, never echoed.
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test fixture: simulates the incoming URI, never echoed.
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/' . Schema::REST_NAMESPACE_NV . '/auth/me';
	}

	/**
	 * The logged-in cookie alone resolves the user for a bridge-namespace URI.
	 */
	public function test_logged_in_cookie_resolves_the_rest_user(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->act_cookie_only( $user_id );

		$resolved = Plugin::determine_rest_user_from_logged_in_cookie( 0 );

		$this->assertSame( $user_id, (int) $resolved );
	}

	/**
	 * The full determine_current_user chain (core + bridge) resolves the user
	 * for a bridge-namespace request. Stock core already resolves via its own
	 * logged-in-cookie filter; this pins the end state the contract depends on.
	 */
	public function test_filter_chain_resolves_user_for_bridge_namespace(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->act_cookie_only( $user_id );

		$resolved = apply_filters( 'determine_current_user', false );

		$this->assertSame( $user_id, (int) $resolved );
	}

	/**
	 * End to end: a dispatched contract request authenticates with the cookie
	 * alone — the exact request shape the /manage gate sends after sign-in.
	 */
	public function test_dispatch_authenticates_with_the_cookie_alone(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->act_cookie_only( $user_id );

		$request  = new WP_REST_Request( 'GET', '/' . Schema::REST_NAMESPACE_NV . '/auth/me' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $user_id, $data['id'] );
		$this->assertTrue( $data['canManage'] );
	}

	/**
	 * Production sequence: the REST pipeline has already forced the session to
	 * user 0 (auth_cookie_valid flipped $wp_rest_auth_cookie, so
	 * rest_cookie_check_errors ran wp_set_current_user(0) on the nonce-less
	 * request). The permission callback must re-resolve the session itself.
	 */
	public function test_dispatch_authenticates_after_forced_logout(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Force-logged-out state: user 0 with the auth-cookie-validated flag set.
		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/' . Schema::REST_NAMESPACE_NV . '/auth/me';

		$request  = new WP_REST_Request( 'GET', '/' . Schema::REST_NAMESPACE_NV . '/auth/me' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $user_id, $data['id'] );
		$this->assertTrue( $data['canManage'] );

		unset( $GLOBALS['wp_rest_auth_cookie'] );
	}

	/**
	 * The capability-gated collections survive the same forced-logout
	 * sequence (managers list = item_permissions_check path).
	 */
	public function test_cap_gated_route_authenticates_after_forced_logout(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/' . Schema::REST_NAMESPACE_NV . '/managers';

		$request  = new WP_REST_Request( 'GET', '/' . Schema::REST_NAMESPACE_NV . '/managers' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		unset( $GLOBALS['wp_rest_auth_cookie'] );
	}

	/**
	 * /auth/nonce must mint for the restored session, not user 0 — the admin
	 * bundle sends the nonce on every call, and a guest-minted nonce fails
	 * wp_verify_nonce once the session is resolved (403 on every collection).
	 */
	public function test_nonce_mints_for_the_restored_session(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/' . Schema::REST_NAMESPACE_NV . '/auth/nonce';

		$request  = new WP_REST_Request( 'GET', '/' . Schema::REST_NAMESPACE_NV . '/auth/nonce' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$nonce = $response->get_data()['nonce'];

		// The minted nonce verifies for the session's real user + token.
		wp_set_current_user( $user_id );
		$this->assertNotFalse( wp_verify_nonce( $nonce, 'wp_rest' ) );

		unset( $GLOBALS['wp_rest_auth_cookie'] );
	}

	/**
	 * A cookie minted for the legacy namespace authenticates there too.
	 */
	public function test_legacy_namespace_is_covered(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->act_cookie_only( $user_id );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/' . Schema::REST_NAMESPACE . '/auth/me';

		$resolved = Plugin::determine_rest_user_from_logged_in_cookie( 0 );

		$this->assertSame( $user_id, (int) $resolved );
	}

	/**
	 * The shim is scoped to the bridge namespaces — other REST routes are
	 * never resolved by the bridge filter (the URI gate, not the chain).
	 */
	public function test_other_rest_namespaces_are_untouched(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->act_cookie_only( $user_id );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/wp/v2/users/me';

		$this->assertSame( 0, (int) Plugin::determine_rest_user_from_logged_in_cookie( 0 ) );
	}

	/**
	 * A forged cookie resolves nothing.
	 */
	public function test_forged_cookie_resolves_nothing(): void {
		$this->act_cookie_only( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test fixture: forged value, never echoed.
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'nosuchuser|9999999999|badtoken|badhmac';

		$this->assertSame( 0, (int) apply_filters( 'determine_current_user', false ) );
	}

	/**
	 * A missing cookie passes through untouched.
	 */
	public function test_missing_cookie_passes_through(): void {
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/' . Schema::REST_NAMESPACE_NV . '/auth/me';

		$this->assertSame( 0, (int) apply_filters( 'determine_current_user', false ) );
	}

	/**
	 * An already-resolved user is never replaced.
	 */
	public function test_resolved_user_is_never_replaced(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$this->assertSame( $user_id, Plugin::determine_rest_user_from_logged_in_cookie( $user_id ) );
	}
}
