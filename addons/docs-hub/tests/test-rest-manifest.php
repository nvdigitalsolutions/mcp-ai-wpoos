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
	 * Test that the manifest endpoint is accessible to guests when public_access is enabled.
	 *
	 * @return void
	 */
	public function test_manifest_accessible_to_guest_when_public_access_enabled() {
		wp_set_current_user( 0 );

		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array_merge( NV_oOS_Docs_Hub_Plugin::get_settings(), array( 'public_access' => true ) )
		);

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/manifest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), 'Guest should get 200 when public_access is enabled' );

		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
	}

	/**
	 * Test that the manifest endpoint blocks guests when public_access is disabled.
	 *
	 * @return void
	 */
	public function test_manifest_blocked_for_guest_when_public_access_disabled() {
		wp_set_current_user( 0 );

		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array_merge( NV_oOS_Docs_Hub_Plugin::get_settings(), array( 'public_access' => false ) )
		);

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/manifest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status(), 'Guest should get 401 when public_access is disabled' );

		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
	}

	/**
	 * Test that the search endpoint blocks guests when public_access is disabled.
	 *
	 * @return void
	 */
	public function test_search_blocked_for_guest_when_public_access_disabled() {
		wp_set_current_user( 0 );

		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array_merge( NV_oOS_Docs_Hub_Plugin::get_settings(), array( 'public_access' => false ) )
		);

		$request = new WP_REST_Request( 'GET', '/nvoos-docs/v1/search' );
		$request->set_param( 'q', 'test' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status(), 'Guest should get 401 on search when public_access is disabled' );

		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
	}

	/**
	 * Test that logged-in users can always access public endpoints regardless
	 * of the public_access setting.
	 *
	 * @return void
	 */
	public function test_logged_in_user_can_access_manifest_when_public_access_disabled() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array_merge( NV_oOS_Docs_Hub_Plugin::get_settings(), array( 'public_access' => false ) )
		);

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/manifest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), 'Logged-in subscriber should get 200 even when public_access is disabled' );

		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
	}

	/**
	 * Test that context-source pages are stripped from the manifest for non-admins.
	 *
	 * @return void
	 */
	public function test_context_pages_stripped_from_manifest_for_non_admin() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Inject a fake manifest with a context group into the cache.
		$fake_manifest = array(
			'version'      => '1.0.0',
			'built_at'     => time(),
			'total_pages'  => 2,
			'broken_links' => array(),
			'slug_map'     => array(
				'readme'          => 'readme',
				'context-private' => 'context-private',
			),
			'tree'         => array(
				array(
					'plugin_name' => 'NV oOS',
					'source'      => 'base',
					'pages'       => array(
						array(
							'slug'        => 'readme',
							'title'       => 'README',
							'source'      => 'base',
							'plugin_name' => 'NV oOS',
							'order'       => 0,
							'toc'         => array(),
						),
					),
				),
				array(
					'plugin_name' => 'Context',
					'source'      => 'context',
					'pages'       => array(
						array(
							'slug'        => 'context-private',
							'title'       => 'Private',
							'source'      => 'context',
							'plugin_name' => 'Context',
							'order'       => 0,
							'toc'         => array(),
						),
					),
				),
			),
		);

		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->set_manifest( $fake_manifest );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/manifest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );

		// Collect all source values from the returned tree.
		$sources = array_column( $data['tree'], 'source' );
		$this->assertNotContains( 'context', $sources, 'Context group must be stripped for non-admin users' );

		// The context slug must also be absent from slug_map.
		$this->assertArrayNotHasKey( 'context-private', $data['slug_map'], 'Context slug must be removed from slug_map' );

		// total_pages must reflect the stripped count.
		$this->assertEquals( 1, $data['total_pages'], 'total_pages must exclude context pages' );

		$cache->clear();
	}

	/**
	 * Test that context-source pages ARE included in the manifest for admins.
	 *
	 * @return void
	 */
	public function test_context_pages_visible_in_manifest_for_admin() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$fake_manifest = array(
			'version'      => '1.0.0',
			'built_at'     => time(),
			'total_pages'  => 2,
			'broken_links' => array(),
			'slug_map'     => array(
				'readme'          => 'readme',
				'context-private' => 'context-private',
			),
			'tree'         => array(
				array(
					'plugin_name' => 'NV oOS',
					'source'      => 'base',
					'pages'       => array(
						array(
							'slug'        => 'readme',
							'title'       => 'README',
							'source'      => 'base',
							'plugin_name' => 'NV oOS',
							'order'       => 0,
							'toc'         => array(),
						),
					),
				),
				array(
					'plugin_name' => 'Context',
					'source'      => 'context',
					'pages'       => array(
						array(
							'slug'        => 'context-private',
							'title'       => 'Private',
							'source'      => 'context',
							'plugin_name' => 'Context',
							'order'       => 0,
							'toc'         => array(),
						),
					),
				),
			),
		);

		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->set_manifest( $fake_manifest );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/manifest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data    = $response->get_data();
		$sources = array_column( $data['tree'], 'source' );
		$this->assertContains( 'context', $sources, 'Admin must see context group in manifest' );

		$cache->clear();
	}

	/**
	 * Test that a context-source page returns 403 for non-admins.
	 *
	 * @return void
	 */
	public function test_context_page_returns_403_for_non_admin() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Inject a fake page payload with source = context.
		$fake_page = array(
			'slug'          => 'context-private',
			'title'         => 'Private Context Doc',
			'content'       => '<p>Secret</p>',
			'source'        => 'context',
			'plugin_name'   => 'Context',
			'toc'           => array(),
			'prev'          => null,
			'next'          => null,
			'tags'          => array(),
			'description'   => '',
			'last_modified' => 0,
			'relative_path' => '.context/private.md',
		);

		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->set_page( 'context-private', $fake_page );

		$request  = new WP_REST_Request( 'GET', '/nvoos-docs/v1/pages/context-private' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status(), 'Non-admin must get 403 for context-source pages' );

		$cache->clear();
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
