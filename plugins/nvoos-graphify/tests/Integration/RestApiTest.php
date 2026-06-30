<?php
declare(strict_types=1);

namespace NvoosGraphify\Tests\Integration;

use WP_UnitTestCase;

/**
 * Integration tests for the REST API.
 *
 * These tests require the WordPress test suite (full WP environment).
 * Run with: composer run test:integration
 *
 * @since 1.0.0
 */
class RestApiTest extends WP_UnitTestCase {

	/**
	 * Set up the test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Ensure REST API is initialized.
		if ( ! did_action( 'rest_api_init' ) ) {
			do_action( 'rest_api_init' );
		}
	}

	/**
	 * Anonymous requests to read endpoints should return 401.
	 *
	 * @return void
	 */
	public function testUnauthenticatedReadReturns401(): void {
		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosGraphify\Schema::REST_NAMESPACE . '/graph' );
		$response = rest_do_request( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Anonymous requests to write endpoints should return 403.
	 *
	 * Write endpoints require manage_options, not read.
	 *
	 * @return void
	 */
	public function testUnauthenticatedWriteReturns403(): void {
		$request  = new \WP_REST_Request( 'POST', '/' . \NvoosGraphify\Schema::REST_NAMESPACE . '/build' );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Anonymous DELETE requests should return 403.
	 *
	 * Delete endpoints require manage_options.
	 *
	 * @return void
	 */
	public function testUnauthenticatedDeleteReturns403(): void {
		$request  = new \WP_REST_Request( 'DELETE', '/' . \NvoosGraphify\Schema::REST_NAMESPACE . '/sources/test-source' );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Authenticated (editor) GET /graph returns 200.
	 *
	 * @return void
	 */
	public function testEditorCanReadGraph(): void {
		$userId = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $userId );

		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosGraphify\Schema::REST_NAMESPACE . '/graph' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Editor cannot trigger a build (requires manage_options).
	 *
	 * @return void
	 */
	public function testEditorCannotBuild(): void {
		$userId = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $userId );

		$request  = new \WP_REST_Request( 'POST', '/' . \NvoosGraphify\Schema::REST_NAMESPACE . '/build' );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Admin can trigger a build.
	 *
	 * @return void
	 */
	public function testAdminCanBuild(): void {
		$userId = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $userId );

		$request = new \WP_REST_Request( 'POST', '/' . \NvoosGraphify\Schema::REST_NAMESPACE . '/build' );
		$request->set_param( 'incremental', true );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * GET /nodes returns paginated results.
	 *
	 * @return void
	 */
	public function testGetNodesReturnsArray(): void {
		$userId = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $userId );

		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosGraphify\Schema::REST_NAMESPACE . '/nodes' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * GET /export defaults to JSON format.
	 *
	 * @return void
	 */
	public function testExportJson(): void {
		$userId = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $userId );

		$request = new \WP_REST_Request( 'GET', '/' . \NvoosGraphify\Schema::REST_NAMESPACE . '/export' );
		$request->set_param( 'format', 'json' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'nodes', $data );
		$this->assertArrayHasKey( 'links', $data );
	}

	/**
	 * GET /search with missing query returns error.
	 *
	 * @return void
	 */
	public function testSearchRequiresQuery(): void {
		$userId = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $userId );

		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosGraphify\Schema::REST_NAMESPACE . '/search' );
		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * GET /webhooks/{slug} is publicly accessible (HMAC auth).
	 *
	 * @return void
	 */
	public function testWebhookEndpointIsPublic(): void {
		$request  = new \WP_REST_Request( 'POST', '/' . \NvoosGraphify\Schema::REST_NAMESPACE . '/webhooks/test' );
		$response = rest_do_request( $request );

		// Should NOT be 401 — webhooks use HMAC auth, not WP auth.
		$this->assertNotEquals( 401, $response->get_status() );
	}
}
