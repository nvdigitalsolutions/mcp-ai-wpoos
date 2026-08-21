<?php
/**
 * Tests for the Hybrid Knowledge Router (Pro addon, roadmap Phase 8).
 *
 * Covers WP_MCP_AI_Hybrid_Knowledge_Router: deterministic classification
 * (OKF / Vector / Paper ordering), fallback ordering, the signal and
 * decision filters, and deterministic token-overlap OKF search.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Hybrid_Knowledge_Router_Test extends WP_UnitTestCase {

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

		if ( ! class_exists( 'WP_MCP_AI_Hybrid_Knowledge_Router' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Hybrid_Knowledge_Router (Pro) is not available in this environment.' );
		}

		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-router-' . uniqid();
		mkdir( $this->test_uploads_dir, 0755, true );

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		wp_set_current_user( 1 ); // Administrator.
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( class_exists( 'WP_MCP_AI_Hybrid_Knowledge_Router' ) ) {
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
	 * Seed the site-knowledge bundle with policy concepts.
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
				'title'       => 'Refund Policy',
				'description' => 'Refund policy for all products, including procedures.',
			),
			'# Refund Policy' . "\n\n" . 'Refunds within 30 days.'
		);
		$writer->write_concept(
			'policies/shipping',
			array(
				'type'        => 'Policy',
				'title'       => 'Shipping Policy',
				'description' => 'Shipping policy and delivery times.',
			),
			'# Shipping Policy' . "\n\n" . 'Ships in 3-5 days.'
		);
	}

	/**
	 * Test that policy-style queries route to OKF first.
	 */
	public function test_classify_okf_primary() {
		$router = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$plan   = $router->classify( 'What is the refund policy?' );

		$this->assertSame( 'okf', $plan['primary'] );
		$this->assertSame( 'okf', $plan['sources'][0]['source'] );
	}

	/**
	 * Test that incident/history-style queries route to Paper Store first.
	 */
	public function test_classify_paper_primary() {
		$router = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$plan   = $router->classify( 'Show me the incident history' );

		$this->assertSame( 'paper', $plan['primary'] );
		$this->assertSame( 'paper', $plan['sources'][0]['source'] );
	}

	/**
	 * Test that similarity-style queries route to the vector store first.
	 */
	public function test_classify_vector_primary() {
		$router = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$plan   = $router->classify( 'Recommend something like this' );

		$this->assertSame( 'vector', $plan['primary'] );
		$this->assertSame( 'vector', $plan['sources'][0]['source'] );
	}

	/**
	 * Test the fallback order for unmatched queries: OKF, Vector, Paper.
	 */
	public function test_classify_fallback_order() {
		$router = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$plan   = $router->classify( 'banana pancakes' );

		$this->assertSame( array(), $plan['signals'] );
		$this->assertSame( 'okf', $plan['primary'] );
		$this->assertSame( array( 'okf', 'vector', 'paper' ), array_column( $plan['sources'], 'source' ) );
	}

	/**
	 * Test that multi-signal queries keep the OKF → Paper → Vector order.
	 */
	public function test_classify_mixed_signals_order() {
		$router = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$plan   = $router->classify( 'What is the incident policy?' );

		$this->assertSame( 'okf', $plan['primary'] );
		$this->assertSame(
			array( 'okf', 'paper', 'vector' ),
			array_column( $plan['sources'], 'source' )
		);
	}

	/**
	 * Test that the signals filter can extend the pattern table.
	 */
	public function test_signals_filter() {
		add_filter(
			'wp_mcp_ai_hybrid_router_signals',
			static function ( $signals ) {
				$signals['banana'] = 'vector';
				return $signals;
			}
		);

		$router = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$plan   = $router->classify( 'banana pancakes' );

		remove_all_filters( 'wp_mcp_ai_hybrid_router_signals' );

		$this->assertSame( 'vector', $plan['primary'] );
		$this->assertSame( array( 'vector' ), $plan['signals'] );
	}

	/**
	 * Test that the decision filter can replace the whole routing plan.
	 */
	public function test_decision_filter() {
		$custom = array(
			'sources' => array(
				array(
					'source' => 'paper',
					'reason' => 'Custom classifier override.',
				),
			),
			'primary' => 'paper',
			'signals' => array( 'paper' ),
		);

		add_filter(
			'wp_mcp_ai_hybrid_router_decision',
			static function () use ( $custom ) {
				return $custom;
			}
		);

		$router = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$plan   = $router->classify( 'What is the refund policy?' );

		remove_all_filters( 'wp_mcp_ai_hybrid_router_decision' );

		$this->assertSame( $custom, $plan );
	}

	/**
	 * Test that search_okf ranks concepts by token-overlap score.
	 */
	public function test_search_okf_ranking() {
		$this->seed_bundle();

		$router  = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$results = $router->search_okf( 'site-knowledge', 'refund policy', 5 );

		$this->assertIsArray( $results );
		$this->assertNotEmpty( $results );
		$this->assertSame( 'policies/refunds', $results[0]['concept_id'] );
		$this->assertGreaterThan( $results[1]['score'], $results[0]['score'] );
		$this->assertSame( 'policies/shipping', $results[1]['concept_id'] );
		$this->assertArrayHasKey( 'trust_tier', $results[0] );
		$this->assertArrayHasKey( 'stale', $results[0] );
	}

	/**
	 * Test that search_okf respects the top limit.
	 */
	public function test_search_okf_respects_top() {
		$this->seed_bundle();

		$router  = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$results = $router->search_okf( 'site-knowledge', 'policy', 1 );

		$this->assertCount( 1, $results );
	}

	/**
	 * Test that a missing bundle produces a WP_Error.
	 */
	public function test_search_okf_missing_bundle() {
		$router = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$result = $router->search_okf( 'ghost-bundle', 'policy' );

		$this->assertWPError( $result );
		$this->assertSame( 'okf_bundle_not_found', $result->get_error_code() );
	}

	/**
	 * Test that queries with no matching tokens return an empty set.
	 */
	public function test_search_okf_no_matches() {
		$this->seed_bundle();

		$router  = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$results = $router->search_okf( 'site-knowledge', 'zzz qqq' );

		$this->assertSame( array(), $results );
	}

	/**
	 * Test that blank queries produce an empty result set (no token errors).
	 */
	public function test_search_okf_blank_query() {
		$this->seed_bundle();

		$router  = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$results = $router->search_okf( 'site-knowledge', '   ' );

		$this->assertSame( array(), $results );
	}
}
