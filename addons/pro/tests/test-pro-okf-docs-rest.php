<?php
/**
 * Tests for the OKF Knowledge Docs REST adapter.
 *
 * Covers WP_MCP_AI_Pro_REST_Okf_Docs (Proposal 087 Phase 2): the Docs Hub
 * contract shape (manifest / pages / search) built from OKF bundles —
 * bundle-prefixed slugs, cross-link rewriting to island hash routes,
 * heading TOC extraction, prev/next neighbours, and the read permission gate.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for the OKF docs REST surface.
 */
class Test_Pro_Okf_Docs_Rest extends WP_UnitTestCase {

	/**
	 * Temporary uploads root directory for testing.
	 *
	 * @var string
	 */
	private $test_uploads_dir;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_REST_Okf_Docs' ) ) {
			require_once dirname( __DIR__ ) . '/includes/rest/class-wp-mcp-ai-pro-rest-okf-docs.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' ) ) {
			$this->markTestSkipped( 'The OKF engine is not available in this environment.' );
		}

		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-docs-' . uniqid();
		mkdir( $this->test_uploads_dir, 0755, true );

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		wp_set_current_user( 1 ); // Administrator.
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' ) ) {
			remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
			$this->recursive_rmdir( $this->test_uploads_dir );
		}

		parent::tearDown();
	}

	/**
	 * Filter upload dir to use a temp directory for tests.
	 *
	 * @param array $upload_dir Upload directory data.
	 * @return array Modified upload directory data.
	 */
	public function filter_upload_dir( $upload_dir ) {
		$upload_dir['basedir'] = $this->test_uploads_dir;
		return $upload_dir;
	}

	/**
	 * Recursively remove a directory tree.
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file_info ) {
			if ( $file_info->isDir() ) {
				rmdir( $file_info->getPathname() );
			} else {
				unlink( $file_info->getPathname() );
			}
		}

		rmdir( $dir );
	}

	/**
	 * Seed two bundles with linked concepts (incl. a cross-bundle link).
	 *
	 * @return void
	 */
	private function seed_bundles() {
		$manager = new WP_MCP_AI_OKF_Bundle_Manager();

		$manager->create_bundle( 'site-knowledge' );
		$writer = new WP_MCP_AI_OKF_Writer( $manager->resolve_bundle_root( 'site-knowledge' ) );
		$writer->write_concept(
			'policies/refunds',
			array(
				'type'        => 'Policy',
				'title'       => 'Refunds',
				'description' => 'Refund policy.',
			),
			"# Refund Policy\n\n## Eligibility\n\nRefunds within 30 days.\n\nSee [exchanges](/policies/exchanges.md) and [guide](../../site-guide/start.md)."
		);
		$writer->write_concept(
			'policies/exchanges',
			array(
				'type'  => 'Policy',
				'title' => 'Exchanges',
			),
			"# Exchanges\n\nExchanges within 14 days."
		);

		$manager->create_bundle( 'site-guide' );
		$writer = new WP_MCP_AI_OKF_Writer( $manager->resolve_bundle_root( 'site-guide' ) );
		$writer->write_concept(
			'start',
			array(
				'type'  => 'Guide',
				'title' => 'Getting Started',
			),
			"# Getting Started\n\nWelcome to the guide."
		);
	}

	/**
	 * Build a WP_REST_Request for the given docs route.
	 *
	 * @param string $route  Route (relative to the /okf/docs base).
	 * @param array  $params Query params.
	 * @return WP_REST_Request
	 */
	private function request( $route, array $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/okf/docs' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Logged-out users are rejected.
	 */
	public function test_permission_requires_login() {
		wp_set_current_user( 0 );
		$this->seed_bundles();

		$result = WP_MCP_AI_Pro_REST_Okf_Docs::permission_check( $this->request( '/manifest' ) );
		$this->assertWPError( $result );

		wp_set_current_user( 1 );
	}

	/**
	 * The manifest mirrors the Docs Hub Manifest shape with bundle-prefixed slugs.
	 */
	public function test_manifest_shape() {
		$this->seed_bundles();

		$response = WP_MCP_AI_Pro_REST_Okf_Docs::handle_manifest( $this->request( '/manifest' ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'tree', $data );
		$this->assertArrayHasKey( 'slug_map', $data );
		$this->assertArrayHasKey( 'total_pages', $data );
		$this->assertSame( 3, $data['total_pages'] );
		$this->assertSame( array(), $data['broken_links'] );

		$slugs = array();
		foreach ( $data['tree'] as $group ) {
			$this->assertSame( 'okf', $group['source'] );
			foreach ( $group['pages'] as $page ) {
				$slugs[] = $page['slug'];
				$this->assertStringStartsWith( $group['plugin_name'] . '/', $page['slug'] );
				$this->assertSame( array(), $page['toc'] );
			}
		}

		$this->assertContains( 'site-knowledge/policies/refunds', $slugs );
		$this->assertContains( 'site-knowledge/policies/exchanges', $slugs );
		$this->assertContains( 'site-guide/start', $slugs );
	}

	/**
	 * The pages endpoint rewrites cross-links to island hash routes and
	 * extracts the heading TOC.
	 */
	public function test_page_shape_and_link_rewrites() {
		$this->seed_bundles();

		$response = WP_MCP_AI_Pro_REST_Okf_Docs::handle_page(
			$this->request( '/pages/site-knowledge%2Fpolicies%2Frefunds', array( 'slug' => 'site-knowledge/policies/refunds' ) )
		);
		$data     = $response->get_data();

		$this->assertSame( 'site-knowledge/policies/refunds', $data['slug'] );
		$this->assertSame( 'Refunds', $data['title'] );
		$this->assertSame( 'okf', $data['source'] );
		$this->assertSame( 'site-knowledge', $data['plugin_name'] );

		// Same-bundle link → #/site-knowledge/policies/exchanges.
		$this->assertStringContainsString(
			'[exchanges](#/site-knowledge/policies/exchanges)',
			$data['content']
		);
		// Cross-bundle link → #/site-guide/start.
		$this->assertStringContainsString(
			'[guide](#/site-guide/start)',
			$data['content']
		);

		// Heading TOC mirrors rehype-slug anchors.
		$this->assertNotEmpty( $data['toc'] );
		$first = $data['toc'][0];
		$this->assertSame( 1, $first['level'] );
		$this->assertSame( 'Refund Policy', $first['text'] );
		$this->assertSame( 'refund-policy', $first['anchor'] );

		// Neighbours come from the flattened bundle order (bundles are listed
		// alphabetically: site-guide before site-knowledge), so the last
		// concept has a prev but no next.
		$this->assertArrayHasKey( 'slug', $data['prev'] );
		$this->assertSame( 'site-knowledge/policies/exchanges', $data['prev']['slug'] );
		$this->assertNull( $data['next'] );
	}

	/**
	 * Unknown slugs yield a 404 error.
	 */
	public function test_page_missing_returns_404() {
		$this->seed_bundles();

		$response = WP_MCP_AI_Pro_REST_Okf_Docs::handle_page(
			$this->request( '/pages/site-knowledge%2Fmissing', array( 'slug' => 'site-knowledge/missing' ) )
		);
		$this->assertWPError( $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/**
	 * Slugs without a bundle prefix are rejected.
	 */
	public function test_page_without_bundle_prefix_rejected() {
		$this->seed_bundles();

		$response = WP_MCP_AI_Pro_REST_Okf_Docs::handle_page(
			$this->request( '/pages/refunds', array( 'slug' => 'refunds' ) )
		);
		$this->assertWPError( $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/**
	 * Search returns the Docs Hub search shape with bundle-prefixed slugs.
	 */
	public function test_search_shape() {
		$this->seed_bundles();

		$response = WP_MCP_AI_Pro_REST_Okf_Docs::handle_search(
			$this->request( '/search', array( 'q' => 'refunds' ) )
		);
		$data     = $response->get_data();

		$this->assertSame( 'refunds', $data['query'] );
		$this->assertGreaterThanOrEqual( 1, $data['total'] );

		$first = $data['results'][0];
		$this->assertSame( 'site-knowledge/policies/refunds', $first['slug'] );
		$this->assertSame( 'Refunds', $first['title'] );
		$this->assertArrayHasKey( 'excerpt', $first );
		$this->assertArrayHasKey( 'score', $first );
	}

	/**
	 * Empty queries short-circuit to an empty result set.
	 */
	public function test_search_empty_query() {
		$response = WP_MCP_AI_Pro_REST_Okf_Docs::handle_search(
			$this->request( '/search', array( 'q' => '' ) )
		);
		$data     = $response->get_data();

		$this->assertSame( 0, $data['total'] );
		$this->assertSame( array(), $data['results'] );
	}
}
