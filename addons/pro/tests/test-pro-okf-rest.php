<?php
/**
 * Tests for the Pro OKF Skills & Knowledge REST controller.
 *
 * Covers WP_MCP_AI_Pro_REST_Okf: permission gates, bundle listing (no
 * filesystem paths), concept listing/search, concept detail (cross-links,
 * traversal rejection), cross-bundle search, and assistant skill grants.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for the OKF REST surface.
 */
class Test_Pro_Okf_Rest extends WP_UnitTestCase {

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

		if ( ! class_exists( 'WP_MCP_AI_Pro_REST_Okf' ) ) {
			require_once dirname( __DIR__ ) . '/includes/rest/class-wp-mcp-ai-pro-rest-okf.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' ) ) {
			$this->markTestSkipped( 'The OKF engine is not available in this environment.' );
		}

		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-rest-' . uniqid();
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
	 * Seed a bundle with stable, draft, and linked concepts.
	 *
	 * @return void
	 */
	private function seed_bundle() {
		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$manager->create_bundle( 'site-knowledge' );

		$writer = new WP_MCP_AI_OKF_Writer( $manager->resolve_bundle_root( 'site-knowledge' ) );
		$writer->write_concept(
			'policies/refunds',
			array(
				'type'        => 'Policy',
				'title'       => 'Refunds',
				'description' => 'Refund policy.',
				'verified'    => array(
					array(
						'by' => 'human:admin',
						'at' => gmdate( 'c' ),
					),
				),
			),
			'# Policy' . "\n\n" . 'Refunds within 30 days. See [exchanges](policies/exchanges.md).'
		);
		$writer->write_concept(
			'policies/exchanges',
			array(
				'type'  => 'Policy',
				'title' => 'Exchanges',
			),
			'# Exchanges' . "\n\n" . 'Exchanges within 14 days.'
		);
		$writer->write_concept(
			'policies/drafts',
			array(
				'type'   => 'Policy',
				'title'  => 'Draft Policy',
				'status' => 'draft',
			),
			'# Draft' . "\n\n" . 'Work in progress.'
		);
	}

	/**
	 * Create an assistant post with OKF grants.
	 *
	 * @param array $grants Grants to store.
	 * @return int Assistant post ID.
	 */
	private function create_assistant( array $grants = array() ) {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'OKF REST Test Assistant',
			)
		);

		if ( ! empty( $grants ) && class_exists( 'WP_MCP_AI_OKF_Skill_Bridge' ) ) {
			update_post_meta( $post_id, WP_MCP_AI_OKF_Skill_Bridge::META_GRANTS, $grants );
		}

		return $post_id;
	}

	/**
	 * Build a WP_REST_Request for the given route.
	 *
	 * @param string $route Route (relative to the /okf base).
	 * @param array  $params Query params.
	 * @return WP_REST_Request
	 */
	private function request( $route, array $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/okf' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Test that logged-out users are rejected.
	 */
	public function test_permission_requires_login() {
		wp_set_current_user( 0 );

		$result = WP_MCP_AI_Pro_REST_Okf::permission_check( $this->request( '/bundles' ) );

		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );

		wp_set_current_user( 1 );
	}

	/**
	 * Test that users without the read capability are rejected.
	 */
	public function test_permission_requires_read() {
		// Every WP role holds `read`, so deny it explicitly for this check.
		$deny = function ( $allcaps, $caps ) {
			if ( in_array( 'read', $caps, true ) ) {
				$allcaps['read'] = false;
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $deny, 10, 2 );

		$result = WP_MCP_AI_Pro_REST_Okf::permission_check( $this->request( '/bundles' ) );

		remove_filter( 'user_has_cap', $deny, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test bundle listing exposes descriptors without filesystem paths.
	 */
	public function test_handle_bundles() {
		$this->seed_bundle();

		$response = WP_MCP_AI_Pro_REST_Okf::handle_bundles( $this->request( '/bundles' ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'bundles', $data );
		$this->assertNotEmpty( $data['bundles'] );

		$site_knowledge = null;
		foreach ( $data['bundles'] as $bundle ) {
			if ( 'site-knowledge' === $bundle['name'] ) {
				$site_knowledge = $bundle;
			}
		}

		$this->assertNotNull( $site_knowledge );
		$this->assertArrayNotHasKey( 'path', $site_knowledge );
		$this->assertSame( 3, $site_knowledge['concept_count'] );
		$this->assertSame( false, $site_knowledge['protected'] );
	}

	/**
	 * Test concept listing returns summaries with trust/status metadata.
	 */
	public function test_handle_concepts() {
		$this->seed_bundle();

		$request  = $this->request(
			'/bundles/site-knowledge/concepts',
			array( 'bundle' => 'site-knowledge' )
		);
		$response = WP_MCP_AI_Pro_REST_Okf::handle_concepts( $request );
		$data     = $response->get_data();

		$this->assertSame( 'site-knowledge', $data['bundle'] );
		$this->assertSame( 3, $data['total'] );

		$ids = array_column( $data['concepts'], 'concept_id' );
		$this->assertContains( 'policies/refunds', $ids );
	}

	/**
	 * Test concept listing free-text filter.
	 */
	public function test_handle_concepts_search_filter() {
		$this->seed_bundle();

		$request  = $this->request(
			'/bundles/site-knowledge/concepts',
			array(
				'bundle' => 'site-knowledge',
				'q'      => 'exchange',
			)
		);
		$response = WP_MCP_AI_Pro_REST_Okf::handle_concepts( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'policies/exchanges', $data['concepts'][0]['concept_id'] );
	}

	/**
	 * Test concept listing status filter.
	 */
	public function test_handle_concepts_status_filter() {
		$this->seed_bundle();

		$request  = $this->request(
			'/bundles/site-knowledge/concepts',
			array(
				'bundle' => 'site-knowledge',
				'status' => 'draft',
			)
		);
		$response = WP_MCP_AI_Pro_REST_Okf::handle_concepts( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'policies/drafts', $data['concepts'][0]['concept_id'] );
	}

	/**
	 * Test concept detail returns body, trust tier, and cross-links.
	 */
	public function test_handle_concept_detail() {
		$this->seed_bundle();

		$request  = $this->request(
			'/bundles/site-knowledge/concepts/policies/refunds',
			array(
				'bundle'  => 'site-knowledge',
				'concept' => 'policies/refunds',
			)
		);
		$response = WP_MCP_AI_Pro_REST_Okf::handle_concept( $request );
		$data     = $response->get_data();

		$this->assertSame( 'policies/refunds', $data['concept_id'] );
		$this->assertSame( 'human-reviewed', $data['trust_tier'] );
		$this->assertSame( array( 'policies/exchanges' ), $data['links'] );
		$this->assertStringContainsString( 'Refunds within 30 days', $data['body'] );
		$this->assertArrayHasKey( 'title', $data['frontmatter'] );
	}

	/**
	 * Test concept detail accepts URL-encoded concept IDs (SPA encodes `/`).
	 */
	public function test_handle_concept_accepts_url_encoded_id() {
		$this->seed_bundle();

		$request  = $this->request(
			'/bundles/site-knowledge/concepts/encoded',
			array(
				'bundle'  => 'site-knowledge',
				'concept' => 'policies%2Frefunds',
			)
		);
		$response = WP_MCP_AI_Pro_REST_Okf::handle_concept( $request );
		$data     = $response->get_data();

		$this->assertSame( 'policies/refunds', $data['concept_id'] );
		$this->assertStringContainsString( 'Refunds within 30 days', $data['body'] );
	}

	/**
	 * Test the concept route matches a percent-encoded concept ID end-to-end.
	 */
	public function test_concept_route_matches_encoded_id() {
		$this->seed_bundle();

		// Routes must be registered on (or after) rest_api_init.
		do_action( 'rest_api_init' );
		WP_MCP_AI_Pro_REST_Okf::register_routes();

		$request  = new WP_REST_Request(
			'GET',
			'/mcp-ai-pro/v1/okf/bundles/site-knowledge/concepts/policies%2Frefunds'
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'policies/refunds', $response->get_data()['concept_id'] );
	}

	/**
	 * Test concept detail rejects unknown concepts.
	 */
	public function test_handle_concept_missing() {
		$this->seed_bundle();

		$request  = $this->request(
			'/bundles/site-knowledge/concepts/policies/nope',
			array(
				'bundle'  => 'site-knowledge',
				'concept' => 'policies/nope',
			)
		);
		$response = WP_MCP_AI_Pro_REST_Okf::handle_concept( $request );

		$this->assertWPError( $response );
	}

	/**
	 * Test concept detail rejects traversal attempts.
	 */
	public function test_handle_concept_rejects_traversal() {
		$this->seed_bundle();

		$request  = $this->request(
			'/bundles/site-knowledge/concepts/traversal',
			array(
				'bundle'  => 'site-knowledge',
				'concept' => '../wp-config',
			)
		);
		$response = WP_MCP_AI_Pro_REST_Okf::handle_concept( $request );

		$this->assertWPError( $response );
	}

	/**
	 * Test cross-bundle search.
	 */
	public function test_handle_search() {
		$this->seed_bundle();

		$request  = $this->request( '/search', array( 'q' => 'refund' ) );
		$response = WP_MCP_AI_Pro_REST_Okf::handle_search( $request );
		$data     = $response->get_data();

		$this->assertSame( 'refund', $data['query'] );
		$this->assertNotEmpty( $data['results'] );
		$this->assertSame( 'site-knowledge', $data['results'][0]['bundle'] );
		$this->assertSame( 'policies/refunds', $data['results'][0]['concept_id'] );
	}

	/**
	 * Test skill listing resolves grants through the bridge gates.
	 */
	public function test_handle_skills() {
		if ( ! class_exists( 'WP_MCP_AI_OKF_Skill_Bridge' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_OKF_Skill_Bridge (Pro) is not available.' );
		}

		$this->seed_bundle();
		$assistant_id = $this->create_assistant(
			array(
				'site-knowledge:policies/refunds',
				'site-knowledge:policies/drafts',
			)
		);

		$request  = $this->request( '/skills', array( 'assistant_id' => $assistant_id ) );
		$response = WP_MCP_AI_Pro_REST_Okf::handle_skills( $request );
		$data     = $response->get_data();

		$this->assertSame( $assistant_id, $data['assistant_id'] );
		$this->assertCount( 2, $data['skills'] );

		$by_name = array();
		foreach ( $data['skills'] as $skill ) {
			$by_name[ $skill['name'] ] = $skill;
		}

		// Stable, granted concept is loadable with trust metadata.
		$this->assertTrue( $by_name['site-knowledge:policies/refunds']['loadable'] );
		$this->assertSame( 'human-reviewed', $by_name['site-knowledge:policies/refunds']['trust_tier'] );
		$this->assertSame( 'Refunds', $by_name['site-knowledge:policies/refunds']['title'] );

		// Draft concept is reported with its gate error.
		$this->assertFalse( $by_name['site-knowledge:policies/drafts']['loadable'] );
		$this->assertStringContainsString( 'draft', $by_name['site-knowledge:policies/drafts']['error'] );
	}

	/**
	 * Test skill listing without an assistant returns an empty payload.
	 */
	public function test_handle_skills_without_assistant() {
		$request  = $this->request( '/skills', array( 'assistant_id' => 0 ) );
		$response = WP_MCP_AI_Pro_REST_Okf::handle_skills( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'assistant_id', $data );
		$this->assertArrayHasKey( 'skills', $data );
		$this->assertIsArray( $data['skills'] );
	}
}
