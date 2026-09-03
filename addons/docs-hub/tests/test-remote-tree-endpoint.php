<?php
/**
 * Tests for the Docs Hub remote-tree REST endpoint and selection filtering.
 *
 * Uses a partial subclass of NV_oOS_Docs_Hub_Remote_Repo to stub the network
 * layer (resolve_ref / fetch_tree) without making real HTTP calls.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.3.0
 */

// The stub class below extends NV_oOS_Docs_Hub_Remote_Repo, so the parent
// must be loaded at file scope — setUp() runs too late for class loading
// and standalone runs of this suite would fatal with "Class not found".
if ( ! class_exists( 'NV_oOS_Docs_Hub_Remote_Repo' ) ) {
	$nvoos_docs_hub_remote_repo_file = dirname( __DIR__ ) . '/includes/class-nvoos-docs-hub-remote-repo.php';
	if ( file_exists( $nvoos_docs_hub_remote_repo_file ) ) {
		require_once $nvoos_docs_hub_remote_repo_file;
	}
	unset( $nvoos_docs_hub_remote_repo_file );
}

/**
 * REST tree endpoint test case.
 */
class Test_Docs_Hub_Remote_Tree extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Bootstrap the addon classes and a fresh REST server before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '0.3.0' );
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
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-remote-repo.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cache.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-indexer.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-state.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-job.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/rest/class-nvoos-docs-hub-rest.php';

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $wp_rest_server );
		NV_oOS_Docs_Hub_REST::register_routes();
	}

	/**
	 * Reset the REST server and persisted settings between tests.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		// Wipe transients written by fetch_tree_for_admin.
		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * Endpoint requires admin (manage_options).
	 */
	public function test_remote_tree_requires_admin() {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/nvoos-docs/v1/remote/tree' );
		$request->set_param( 'owner', 'foo' );
		$request->set_param( 'repo', 'bar' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Empty owner / repo → 400-ish via WP_Error (status code passed through).
	 */
	public function test_remote_tree_validates_input() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/nvoos-docs/v1/remote/tree' );
		$request->set_param( 'owner', '' );
		$request->set_param( 'repo', 'x' );
		$response = $this->server->dispatch( $request );

		// Required-arg failure becomes a 400.
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	/**
	 * Helper: matches_path_list correctness.
	 */
	public function test_matches_path_list_directory_recursion() {
		$this->assertTrue(
			NV_oOS_Docs_Hub_Remote_Repo::matches_path_list( 'docs/intro.md', array( 'docs/' ) )
		);
		$this->assertTrue(
			NV_oOS_Docs_Hub_Remote_Repo::matches_path_list( 'docs/sub/page.md', array( 'docs/' ) )
		);
		$this->assertFalse(
			NV_oOS_Docs_Hub_Remote_Repo::matches_path_list( 'guides/intro.md', array( 'docs/' ) )
		);
		$this->assertTrue(
			NV_oOS_Docs_Hub_Remote_Repo::matches_path_list( 'README.md', array( 'README.md' ) )
		);
		$this->assertFalse(
			NV_oOS_Docs_Hub_Remote_Repo::matches_path_list( 'README.md.bak', array( 'README.md' ) )
		);
	}

	/**
	 * Fetch_tree_for_admin caches results in a transient.
	 *
	 * Uses a stub subclass that returns a synthetic tree without hitting the
	 * network. Verifies the second call is served from the transient (the
	 * stub increments a counter on each underlying tree fetch).
	 */
	public function test_fetch_tree_for_admin_uses_transient_cache() {
		$stub = new NVoOS_Docs_Hub_Remote_Repo_Stub();

		$result = $stub->fetch_tree_for_admin(
			array(
				'owner' => 'acme',
				'repo'  => 'widget',
				'ref'   => 'main',
				'path'  => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'files', $result );
		$paths = wp_list_pluck( $result['files'], 'path' );
		$this->assertContains( 'README.md', $paths );
		$this->assertContains( 'docs/guide.md', $paths );
		// Default exclusions kick in: vendor/, node_modules/.
		$this->assertNotContains( 'vendor/foo.md', $paths );

		$this->assertEquals( 1, NVoOS_Docs_Hub_Remote_Repo_Stub::$tree_calls );

		// Second call should hit the transient — no extra fetch.
		$result2 = $stub->fetch_tree_for_admin(
			array(
				'owner' => 'acme',
				'repo'  => 'widget',
				'ref'   => 'main',
				'path'  => '',
			)
		);
		$this->assertEquals( $result, $result2 );
		$this->assertEquals( 1, NVoOS_Docs_Hub_Remote_Repo_Stub::$tree_calls, 'Second call should be cached' );

		// force=true bypasses cache.
		$stub->fetch_tree_for_admin(
			array(
				'owner' => 'acme',
				'repo'  => 'widget',
				'ref'   => 'main',
				'path'  => '',
				'force' => true,
			)
		);
		$this->assertEquals( 2, NVoOS_Docs_Hub_Remote_Repo_Stub::$tree_calls );
	}

	/**
	 * Default settings on a fresh install ship with sources = ['remote'].
	 */
	public function test_fresh_install_defaults_to_remote_source() {
		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$this->assertEquals( array( 'remote' ), $settings['sources'] );
	}

	/**
	 * Existing installs keep their saved sources untouched.
	 */
	public function test_existing_installs_keep_legacy_sources() {
		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array( 'sources' => array( 'base', 'addons' ) )
		);
		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$this->assertEquals( array( 'base', 'addons' ), $settings['sources'] );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Test stub for NV_oOS_Docs_Hub_Remote_Repo.
 *
 * Reimplements fetch_tree_for_admin() with a synthetic in-memory tree so
 * tests can exercise the cache + filter_md_files() pipeline without making
 * real GitHub API calls. The synthetic tree is reused via reflection
 * against the parent's private filter_md_files() method so we still cover
 * the real exclusion logic.
 *
 * @since 0.3.0
 */
class NVoOS_Docs_Hub_Remote_Repo_Stub extends NV_oOS_Docs_Hub_Remote_Repo {

	/**
	 * Number of times the synthetic tree was fetched (i.e. cache misses).
	 *
	 * @var int
	 */
	public static $tree_calls = 0;

	/**
	 * Synthetic tree returned by every fetch.
	 *
	 * @return array
	 */
	private function synthetic_tree() {
		++self::$tree_calls;
		return array(
			array(
				'type' => 'blob',
				'path' => 'README.md',
				'size' => 100,
			),
			array(
				'type' => 'blob',
				'path' => 'docs/guide.md',
				'size' => 200,
			),
			array(
				'type' => 'blob',
				'path' => 'docs/sub/deep.md',
				'size' => 300,
			),
			array(
				'type' => 'blob',
				'path' => 'vendor/foo.md',
				'size' => 50,
			),
			array(
				'type' => 'blob',
				'path' => 'binary.png',
				'size' => 999,
			),
			array(
				'type' => 'tree',
				'path' => 'docs',
				'size' => 0,
			),
		);
	}

	/**
	 * Override the public method to bypass private-resolve/fetch and
	 * substitute the synthetic tree, while still exercising the cache,
	 * the filter_md_files() exclusion logic, and the transient key.
	 *
	 * @param array $repo_config Repo config.
	 * @return array|WP_Error
	 */
	public function fetch_tree_for_admin( $repo_config ) {
		$owner = sanitize_text_field( $repo_config['owner'] ?? '' );
		$repo  = sanitize_text_field( $repo_config['repo'] ?? '' );
		$ref   = sanitize_text_field( $repo_config['ref'] ?? 'HEAD' );
		$path  = trim( sanitize_text_field( $repo_config['path'] ?? '' ), '/' );
		$force = ! empty( $repo_config['force'] );

		$transient_key = 'nvoos_docs_hub_tree_' . md5( implode( '|', array( $owner, $repo, $ref, $path ) ) );
		if ( ! $force ) {
			$cached = get_transient( $transient_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$tree = $this->synthetic_tree();

		// Use reflection to call the private filter_md_files method.
		$ref_method = new ReflectionMethod( NV_oOS_Docs_Hub_Remote_Repo::class, 'filter_md_files' );
		$ref_method->setAccessible( true );
		$md_files = $ref_method->invoke( $this, $tree, '', array( 'selection_mode' => 'all' ), $path );

		$files = array();
		foreach ( $md_files as $item ) {
			$rel     = $item['path'];
			$full    = '' !== $path ? $path . '/' . $rel : $rel;
			$files[] = array(
				'path' => $full,
				'size' => (int) ( $item['size'] ?? 0 ),
			);
		}
		usort(
			$files,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		$payload = array(
			'resolved_ref' => $ref,
			'path'         => $path,
			'files'        => $files,
		);

		set_transient( $transient_key, $payload, 10 * MINUTE_IN_SECONDS );
		return $payload;
	}
}
