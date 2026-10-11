<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Integration;

use NvoosContentGraph\Graph\Builder;
use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Graph\Detector;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/helpers/jetengine-cct-stubs.php';

/**
 * Integration tests for the build-time prune of excluded sources.
 *
 * Regression coverage for the bug where a CCT that was indexed and then
 * excluded on the Sources tab kept its nodes (and edges) in the graph
 * forever because builds only ever upserted.
 *
 * Uses the real WordPress test database (real DDL via {@see Db::install()}).
 *
 * @since 1.0.10
 */
class BuilderPruneTest extends WP_UnitTestCase {

	/**
	 * Set up — ensure the plugin boot ran and the schema exists.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! did_action( 'plugins_loaded' ) ) {
			do_action( 'plugins_loaded' );
		}

		Db::install();

		delete_option( 'nvoos_content_graph_settings' );
	}

	/**
	 * Tear down — restore the schema and JetEngine stub state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		nvoos_cg_test_reset_jetengine();
		remove_all_filters( 'nvoos_content_graph_indexed_cct_slugs' );
		if ( post_type_exists( 'jet_book' ) ) {
			unregister_post_type( 'jet_book' );
		}
		Db::install();
		parent::tearDown();
	}

	/**
	 * A CCT indexed before exclusion is fully removed on the next build.
	 *
	 * @return void
	 */
	public function testExcludedCctNodesAndEdgesArePrunedOnBuild(): void {
		$rows = array(
			array(
				'_ID'           => 10,
				'cct_title'     => 'Transcript 10',
				'cct_author_id' => 1,
			),
		);
		nvoos_cg_test_install_jetengine_cct(
			array(
				array(
					'slug' => 'ai_chat_transcripts',
					'name' => 'Chat Transcripts',
					'rows' => $rows,
				),
			)
		);

		// First build indexes the CCT.
		Builder::build();

		$cctNodeId = Detector::cctNodeId( 'ai_chat_transcripts', 10 );
		$this->assertNotNull( Db::getNode( $cctNodeId ) );

		// Exclude the CCT and rebuild — node and edges must disappear.
		update_option(
			'nvoos_content_graph_settings',
			array( 'excluded_cct_slugs' => array( 'ai_chat_transcripts' ) )
		);

		$summary = Builder::build();

		$this->assertNull( Db::getNode( $cctNodeId ) );
		$this->assertSame( array(), Db::getEdgesForNode( $cctNodeId ) );
		$this->assertGreaterThan( 0, $summary['nodes_pruned'] );
	}

	/**
	 * A post of an excluded post type is pruned on rebuild and not re-indexed.
	 *
	 * @return void
	 */
	public function testExcludedPostTypeNodesArePrunedOnBuild(): void {
		register_post_type(
			'jet_book',
			array(
				'public' => true,
				'label'  => 'Jet Books',
			)
		);

		// Index a jet_book post first (opt-in via extra_post_types).
		update_option(
			'nvoos_content_graph_settings',
			array( 'extra_post_types' => array( 'jet_book' ) )
		);
		$book_id = self::factory()->post->create(
			array(
				'post_type'   => 'jet_book',
				'post_status' => 'publish',
			)
		);
		Builder::build();
		$this->assertNotNull( Db::getNode( Detector::postNodeId( $book_id ) ) );

		// Exclude the CPT and rebuild — the node must be pruned.
		update_option(
			'nvoos_content_graph_settings',
			array( 'excluded_post_types' => array( 'jet_book' ) )
		);

		$summary = Builder::build();

		$this->assertNull( Db::getNode( Detector::postNodeId( $book_id ) ) );
		$this->assertGreaterThan( 0, $summary['nodes_pruned'] );
	}
}
