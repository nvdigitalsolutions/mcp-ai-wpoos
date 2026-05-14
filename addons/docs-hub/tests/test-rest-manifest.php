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

	// -------------------------------------------------------------------------
	// Guest / public-access tests
	// -------------------------------------------------------------------------

	/**
	 * Test that the manifest endpoint works for anonymous (logged-out) users.
	 *
	 * @return void
	 */
	public function test_manifest_accessible_to_anonymous_user() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/manifest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'version', $data );
	}

	/**
	 * Test that the pages endpoint is accessible to anonymous users for non-context pages.
	 *
	 * An uncached page slug will trigger an attempt to rebuild (which returns
	 * false in the test environment) and then a 404 — the important thing is
	 * that the request is NOT blocked with 401/403.
	 *
	 * @return void
	 */
	public function test_pages_endpoint_accessible_to_anonymous() {
		wp_set_current_user( 0 );

		// Seed the transient with a normal (non-context) page so we get a 200.
		$slug    = 'test-public-page';
		$payload = array(
			'slug'        => $slug,
			'title'       => 'Test Page',
			'source'      => 'base',
			'plugin_name' => 'Test Plugin',
			'markdown'    => '# Test',
			'toc'         => array(),
		);
		set_transient( 'nvoos_dh_p_' . md5( $slug ), $payload, 3600 );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/pages/' . $slug );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'base', $data['source'] );

		delete_transient( 'nvoos_dh_p_' . md5( $slug ) );
	}

	/**
	 * Test that context pages are blocked for anonymous users (403).
	 *
	 * The scanner guards context files at rebuild-time, but if a manifest was
	 * built by an admin with context_enabled=true the page payloads are stored
	 * in the cache. The REST layer must prevent anonymous readers from fetching
	 * them.
	 *
	 * @return void
	 */
	public function test_context_page_blocked_for_anonymous() {
		// Seed a context page directly into the transient cache.
		$slug    = 'context-test';
		$payload = array(
			'slug'        => $slug,
			'title'       => 'Internal Context Page',
			'source'      => 'context',
			'plugin_name' => 'NV oOS Base',
			'markdown'    => '# Secret internal docs',
			'toc'         => array(),
		);
		set_transient( 'nvoos_dh_p_' . md5( $slug ), $payload, 3600 );

		// Anonymous user — should get 403.
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/pages/' . $slug );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		delete_transient( 'nvoos_dh_p_' . md5( $slug ) );
	}

	/**
	 * Test that context pages are served to admins (not blocked).
	 *
	 * @return void
	 */
	public function test_context_page_accessible_to_admin() {
		$slug    = 'context-admin-test';
		$payload = array(
			'slug'        => $slug,
			'title'       => 'Internal Context Page',
			'source'      => 'context',
			'plugin_name' => 'NV oOS Base',
			'markdown'    => '# Secret internal docs',
			'toc'         => array(),
		);
		set_transient( 'nvoos_dh_p_' . md5( $slug ), $payload, 3600 );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/pages/' . $slug );
		$response = $this->server->dispatch( $request );

		// Admin gets 200, and response uses private no-store Cache-Control.
		$this->assertEquals( 200, $response->get_status() );
		$cc = $response->get_headers()['Cache-Control'] ?? '';
		$this->assertStringContainsString( 'no-store', (string) $cc );

		delete_transient( 'nvoos_dh_p_' . md5( $slug ) );
	}

	/**
	 * Test that context entries are stripped from the manifest for anonymous users.
	 *
	 * @return void
	 */
	public function test_manifest_strips_context_pages_for_anonymous() {
		// Seed a manifest that contains one base page and one context page.
		$manifest = array(
			'version'      => NVOOS_DOCS_HUB_VERSION,
			'built_at'     => time(),
			'total_pages'  => 2,
			'broken_links' => array(),
			'tree'         => array(
				array(
					'source'      => 'base',
					'plugin_name' => 'NV oOS Base',
					'pages'       => array(
						array( 'slug' => 'readme', 'title' => 'README', 'source' => 'base' ),
					),
				),
				array(
					'source'      => 'context',
					'plugin_name' => 'NV oOS Base',
					'pages'       => array(
						array( 'slug' => 'context/conventions', 'title' => 'Conventions', 'source' => 'context' ),
					),
				),
			),
			'slug_map'     => array(
				'readme'               => array( 'path' => '/docs/README.md' ),
				'context/conventions'  => array( 'path' => '/.context/conventions.md' ),
			),
		);
		set_transient( 'nvoos_dh_manifest', $manifest, 3600 );

		// Anonymous user.
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/manifest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		// Only the base page should remain.
		$this->assertEquals( 1, $data['total_pages'] );
		$this->assertArrayHasKey( 'readme', $data['slug_map'] );
		$this->assertArrayNotHasKey( 'context/conventions', $data['slug_map'] );

		// Confirm the context group is gone from tree.
		$sources = array_column( $data['tree'], 'source' );
		$this->assertNotContains( 'context', $sources );

		delete_transient( 'nvoos_dh_manifest' );
	}

	/**
	 * Test that context entries remain visible to admins in the manifest.
	 *
	 * @return void
	 */
	public function test_manifest_includes_context_pages_for_admin() {
		$manifest = array(
			'version'      => NVOOS_DOCS_HUB_VERSION,
			'built_at'     => time(),
			'total_pages'  => 2,
			'broken_links' => array(),
			'tree'         => array(
				array(
					'source'      => 'base',
					'plugin_name' => 'NV oOS Base',
					'pages'       => array(
						array( 'slug' => 'readme', 'title' => 'README', 'source' => 'base' ),
					),
				),
				array(
					'source'      => 'context',
					'plugin_name' => 'NV oOS Base',
					'pages'       => array(
						array( 'slug' => 'context/conventions', 'title' => 'Conventions', 'source' => 'context' ),
					),
				),
			),
			'slug_map'     => array(
				'readme'              => array( 'path' => '/docs/README.md' ),
				'context/conventions' => array( 'path' => '/.context/conventions.md' ),
			),
		);
		set_transient( 'nvoos_dh_manifest', $manifest, 3600 );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/manifest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertEquals( 2, $data['total_pages'] );
		$this->assertArrayHasKey( 'context/conventions', $data['slug_map'] );

		delete_transient( 'nvoos_dh_manifest' );
	}
}
