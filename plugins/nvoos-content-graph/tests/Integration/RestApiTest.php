<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Integration;

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
		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/graph' );
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
		$request  = new \WP_REST_Request( 'POST', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/build' );
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
		$request  = new \WP_REST_Request( 'DELETE', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/sources/test-source' );
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

		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/graph' );
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

		$request  = new \WP_REST_Request( 'POST', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/build' );
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

		$request = new \WP_REST_Request( 'POST', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/build' );
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

		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/nodes' );
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

		$request = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/export' );
		$request->set_param( 'format', 'json' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'format', $data );
		$this->assertEquals( 'json', $data['format'] );

		// The endpoint wraps the export payload under `data`; for JSON the
		// payload is a JSON string (NetworkX node-link format).
		$this->assertIsString( $data['data'] );
		$export = json_decode( $data['data'], true );
		$this->assertIsArray( $export );
		$this->assertArrayHasKey( 'nodes', $export );
		$this->assertArrayHasKey( 'links', $export );
	}

	/**
	 * GET /search with missing query returns error.
	 *
	 * @return void
	 */
	public function testSearchRequiresQuery(): void {
		$userId = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $userId );

		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/search' );
		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * GET /webhooks/{slug} is publicly accessible (HMAC auth).
	 *
	 * @return void
	 */
	public function testWebhookEndpointIsPublic(): void {
		$request  = new \WP_REST_Request( 'POST', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/webhooks/test' );
		$response = rest_do_request( $request );

		// Should NOT be 401 — webhooks use HMAC auth, not WP auth.
		$this->assertNotEquals( 401, $response->get_status() );
	}

	/**
	 * A valid assistant credential (bearer) can read the graph anonymously.
	 *
	 * @return void
	 */
	public function testBearerCredentialCanReadGraph(): void {
		require_once __DIR__ . '/../helpers/base-plugin-credential-stubs.php';
		$token    = \WP_MCP_AI_Credentials::seed_token();
		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/graph' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * A credential-format bearer token that fails validation is rejected.
	 *
	 * @return void
	 */
	public function testBearerCredentialWithBadTokenReturns401(): void {
		require_once __DIR__ . '/../helpers/base-plugin-credential-stubs.php';
		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/graph' );
		$request->set_header( 'Authorization', 'Bearer cred_forged.wrongsecret' );
		$response = rest_do_request( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * The raw credential header form (no "Bearer" scheme) is accepted too.
	 *
	 * @return void
	 */
	public function testRawCredentialHeaderCanReadGraph(): void {
		require_once __DIR__ . '/../helpers/base-plugin-credential-stubs.php';
		$token    = \WP_MCP_AI_Credentials::seed_token();
		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/graph' );
		$request->set_header( 'Authorization', $token );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Assistant credentials only grant read access — build stays admin-only.
	 *
	 * @return void
	 */
	public function testBearerCredentialCannotBuild(): void {
		require_once __DIR__ . '/../helpers/base-plugin-credential-stubs.php';
		$token    = \WP_MCP_AI_Credentials::seed_token();
		$request  = new \WP_REST_Request( 'POST', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/build' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * GET /graph/visual-config returns the explorer config for editors.
	 *
	 * @return void
	 */
	public function testVisualConfigReturnsExplorerConfig(): void {
		$userId = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $userId );

		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/graph/visual-config' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'visual', $data );
		$this->assertArrayHasKey( 'presets', $data );
		$this->assertArrayHasKey( 'height', $data );
		$this->assertArrayHasKey( 'max_nodes', $data );
		$this->assertIsArray( $data['visual'] );
		$this->assertArrayHasKey( 'theme', $data['visual'] );
	}

	/**
	 * GET /graph/visual-config is available to a valid bearer credential.
	 *
	 * @return void
	 */
	public function testVisualConfigAcceptsBearerCredential(): void {
		require_once __DIR__ . '/../helpers/base-plugin-credential-stubs.php';
		$token    = \WP_MCP_AI_Credentials::seed_token();
		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/graph/visual-config' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * GET /graph/visual-config rejects anonymous requests.
	 *
	 * @return void
	 */
	public function testVisualConfigAnonymousReturns401(): void {
		$request  = new \WP_REST_Request( 'GET', '/' . \NvoosContentGraph\Schema::REST_NAMESPACE . '/graph/visual-config' );
		$response = rest_do_request( $request );

		$this->assertEquals( 401, $response->get_status() );
	}
}
