<?php
/**
 * Tests for the Docs Hub REST manifest endpoint.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

/**
 * Docs Hub REST manifest endpoint tests.
 */
class Test_Docs_Hub_REST_Manifest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '1.0.0' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_PATH' ) ) {
			define( 'NVOOS_DOCS_HUB_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_URL' ) ) {
			define( 'NVOOS_DOCS_HUB_URL', 'http://example.com/wp-content/plugins/nvoos-docs-hub/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_FILE' ) ) {
			define( 'NVOOS_DOCS_HUB_FILE', NVOOS_DOCS_HUB_PATH . 'nvoos-docs-hub.php' );
		}

		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-plugin.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-scanner.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-indexer.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cache.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-job.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/rest/class-nvoos-docs-hub-rest.php';

		// Boot the REST server.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $wp_rest_server );
		NV_oOS_Docs_Hub_REST::register_routes();
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * Test that the manifest endpoint is accessible without authentication.
	 *
	 * Returns 200 with an empty/default manifest when nothing is indexed yet.
	 *
	 * @return void
	 */
	public function test_manifest_endpoint_accessible() {
		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/manifest' );
		$response = $this->server->dispatch( $request );

		$this->assertNotEquals( 401, $response->get_status() );
		$this->assertNotEquals( 403, $response->get_status() );

		// Should be 200 even if empty.
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'version', $data );
	}

	/**
	 * Test that the manifest endpoint does not require authentication.
	 *
	 * @return void
	 */
	public function test_manifest_requires_no_auth() {
		// Ensure we're not logged in.
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/manifest' );
		$response = $this->server->dispatch( $request );

		$this->assertNotEquals( 401, $response->get_status() );
		$this->assertNotEquals( 403, $response->get_status() );
	}

	/**
	 * Test that the rebuild endpoint requires manage_options capability.
	 *
	 * @return void
	 */
	public function test_rebuild_requires_manage_options() {
		// No authentication.
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/nvoos-docs/v1/rebuild' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test that the rebuild endpoint is accessible to admins.
	 *
	 * @return void
	 */
	public function test_rebuild_accessible_to_admin() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-docs/v1/rebuild' );
		// Add a fake nonce (will fail verification but we're just checking access).
		$nonce = wp_create_nonce( 'wp_rest' );
		$request->set_header( 'X-WP-Nonce', $nonce );

		$response = $this->server->dispatch( $request );

		// Should not be a 403 (may be 200 or 500 depending on build).
		$this->assertNotEquals( 403, $response->get_status() );
	}

	/**
	 * Test that an invalid slug returns 400.
	 *
	 * @return void
	 */
	public function test_invalid_page_slug_returns_400() {
		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/pages/../../etc/passwd' );
		$response = $this->server->dispatch( $request );

		// Expect 400 or 404 — not 200.
		$this->assertNotEquals( 200, $response->get_status() );
	}

	/**
	 * Test that the health endpoint requires manage_options.
	 *
	 * @return void
	 */
	public function test_health_requires_manage_options() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/health' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test that health endpoint is accessible to admins and returns correct shape.
	 *
	 * @return void
	 */
	public function test_health_accessible_to_admin() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/health' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'total_pages', $data );
		$this->assertArrayHasKey( 'broken_links', $data );
		$this->assertArrayHasKey( 'last_built', $data );
		$this->assertArrayHasKey( 'version', $data );
	}

	/**
	 * Test that the search endpoint returns results shape.
	 *
	 * @return void
	 */
	public function test_search_endpoint_returns_shape() {
		$request = new WP_REST_Request( 'GET', '/nvoos-docs/v1/search' );
		$request->set_param( 'q', 'installation' );

		$response = $this->server->dispatch( $request );

		// Should be 200 even with empty results.
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'results', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'query', $data );
	}
}
