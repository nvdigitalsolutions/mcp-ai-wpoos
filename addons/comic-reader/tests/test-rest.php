<?php
/**
 * NV oOS Comic Reader — REST API Tests
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

/**
 * Test REST API endpoints.
 */
class Test_Comic_Reader_REST extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		// Register REST routes.
		if ( class_exists( 'NV_oOS_Comic_Reader_REST' ) ) {
			NV_oOS_Comic_Reader_REST::register_routes();
		}

		// Ensure REST server is initialized.
		do_action( 'rest_api_init' );
	}

	/**
	 * Test health endpoint returns ok.
	 *
	 * @return void
	 */
	public function test_health_endpoint() {
		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/health' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'ok', $data['status'] );
	}

	/**
	 * Test manifest endpoint.
	 *
	 * @return void
	 */
	public function test_manifest_endpoint() {
		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/manifest' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'comic-reader', $data['slug'] );
		$this->assertContains( 'cbr', $data['supported_formats'] );
		$this->assertContains( 'cbz', $data['supported_formats'] );
	}

	/**
	 * Test upload requires permission.
	 *
	 * @return void
	 */
	public function test_upload_requires_permission() {
		// Set user to subscriber (cannot upload_files).
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/upload' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test delete requires permission.
	 *
	 * @return void
	 */
	public function test_delete_requires_permission() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'DELETE', '/nvoos-comic-reader/v1/comics/1/delete' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test listing comics returns paginated response.
	 *
	 * @return void
	 */
	public function test_list_comics_returns_paginated() {
		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'comics', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'page', $data );
		$this->assertArrayHasKey( 'total_pages', $data );
	}
}
