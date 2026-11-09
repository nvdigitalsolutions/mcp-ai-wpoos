<?php
/**
 * Cache tag headers (Proposal 135 / A2) — the vocabulary the frontend's
 * `Astro.cache.invalidate({ tags })` consumes.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\REST\BaseController;

/**
 * Cache-Tag / Surrogate-Key tests.
 *
 * @covers \NvoosAstroBridge\REST\BaseController
 * @group nvoos-astro-bridge
 */
class TestCacheTags extends WP_UnitTestCase {

	/**
	 * Run the B1 filter over a synthetic GET request.
	 *
	 * @param string               $route  Matched route pattern.
	 * @param array<string, mixed> $params Route params.
	 * @return WP_REST_Response
	 */
	private function apply( $route, array $params = array(), $as = 0 ) {
		// The B1 filter is deliberately fail-closed for authenticated users;
		// other suites' bootstrap can leave a user set, so force the state
		// each test asserts.
		wp_set_current_user( $as );

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_route( $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return BaseController::set_cache_headers_on_public_gets(
			rest_ensure_response( array( array( 'id' => 1 ) ) ),
			rest_get_server(),
			$request
		);
	}

	/**
	 * A collection response carries the collection tag on both headers.
	 */
	public function test_collection_tag(): void {
		$headers = $this->apply( '/nvoos-commerce/v1/products' )->get_headers();

		$this->assertArrayHasKey( 'Cache-Tag', $headers );
		$this->assertSame( 'products', $headers['Cache-Tag'] );
		$this->assertSame( 'products', $headers['Surrogate-Key'] );
	}

	/**
	 * A single document adds `collection:<id>` — the targeted purge key.
	 */
	public function test_single_document_tag(): void {
		$headers = $this->apply(
			'/nvoos-commerce/v1/products/(?P<id>\d+)',
			array( 'id' => 42 )
		)->get_headers();

		$this->assertSame( 'products,products:42', $headers['Cache-Tag'] );
		$this->assertSame( 'products products:42', $headers['Surrogate-Key'] );
	}

	/**
	 * The legacy namespace carries the same tags.
	 */
	public function test_legacy_namespace_tag(): void {
		$headers = $this->apply( '/funiq/v1/posts' )->get_headers();

		$this->assertSame( 'posts', $headers['Cache-Tag'] );
	}

	/**
	 * Globals use their own tag.
	 */
	public function test_globals_tag(): void {
		$headers = $this->apply( '/nvoos-commerce/v1/globals/theme' )->get_headers();

		$this->assertSame( 'globals', $headers['Cache-Tag'] );
	}

	/**
	 * Authenticated responses are never tagged (nor cached) — the B1 guard
	 * returns before the headers are applied.
	 */
	public function test_authenticated_requests_are_not_tagged(): void {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$headers = $this->apply( '/nvoos-commerce/v1/products', array(), $admin )->get_headers();

		$this->assertArrayNotHasKey( 'Cache-Tag', $headers );
	}
}
