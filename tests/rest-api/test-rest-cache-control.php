<?php
/**
 * Tests the WP 6.9 authenticated no-cache opt-out — WP_MCP_AI_REST_Cache_Control.
 *
 * WordPress 6.9's WP_REST_Server::serve_request() applies
 * wp_get_nocache_headers() to every logged-in REST response
 * (rest_send_nocache_headers defaults to is_user_logged_in()), replacing a
 * controller's explicit Cache-Control. The opt-out must fire ONLY for
 * responses that explicitly declare shared cacheability (public/s-maxage) —
 * default-deny for everything else.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Cache_Control_Test extends WP_UnitTestCase {

	/**
	 * Filters leak across suites — reset the hook table around each test.
	 */
	public function setUp(): void {
		parent::setUp();
		remove_all_filters( 'rest_send_nocache_headers' );
	}

	/**
	 * Filters leak across suites — reset the hook table after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'rest_send_nocache_headers' );
		parent::tearDown();
	}

	/**
	 * A response declaring public + max-age opts the request out of the
	 * core no-cache injection.
	 */
	public function test_public_response_opts_out_of_nocache() {
		$response = new WP_REST_Response( array( 'ok' => true ) );
		$response->header( 'Cache-Control', 'public, max-age=60, s-maxage=60' );

		WP_MCP_AI_REST_Cache_Control::preserve_explicit_cacheability(
			$response,
			rest_get_server(),
			new WP_REST_Request()
		);

		$this->assertSame( 20, has_filter( 'rest_send_nocache_headers', '__return_false' ) );
	}

	/**
	 * An s-maxage declaration alone is an explicit shared-cache signal too.
	 */
	public function test_s_maxage_response_opts_out_of_nocache() {
		$response = new WP_REST_Response( array( 'ok' => true ) );
		$response->header( 'Cache-Control', 's-maxage=60' );

		WP_MCP_AI_REST_Cache_Control::preserve_explicit_cacheability(
			$response,
			rest_get_server(),
			new WP_REST_Request()
		);

		$this->assertSame( 20, has_filter( 'rest_send_nocache_headers', '__return_false' ) );
	}

	/**
	 * An unmarked response keeps the core no-cache behavior (default-deny).
	 */
	public function test_unmarked_response_keeps_core_nocache() {
		$response = new WP_REST_Response( array( 'ok' => true ) );

		WP_MCP_AI_REST_Cache_Control::preserve_explicit_cacheability(
			$response,
			rest_get_server(),
			new WP_REST_Request()
		);

		$this->assertFalse( has_filter( 'rest_send_nocache_headers', '__return_false' ) );
	}

	/**
	 * A browser-only (private) declaration must not open a shared-cache gate.
	 */
	public function test_private_response_keeps_core_nocache() {
		$response = new WP_REST_Response( array( 'ok' => true ) );
		$response->header( 'Cache-Control', 'private, max-age=60' );

		WP_MCP_AI_REST_Cache_Control::preserve_explicit_cacheability(
			$response,
			rest_get_server(),
			new WP_REST_Request()
		);

		$this->assertFalse( has_filter( 'rest_send_nocache_headers', '__return_false' ) );
	}

	/**
	 * Non-response values (WP_Error) pass through untouched.
	 */
	public function test_wp_error_passes_through() {
		$error = new WP_Error( 'test_code', 'Test' );

		$result = WP_MCP_AI_REST_Cache_Control::preserve_explicit_cacheability(
			$error,
			rest_get_server(),
			new WP_REST_Request()
		);

		$this->assertSame( $error, $result );
		$this->assertFalse( has_filter( 'rest_send_nocache_headers', '__return_false' ) );
	}
}
