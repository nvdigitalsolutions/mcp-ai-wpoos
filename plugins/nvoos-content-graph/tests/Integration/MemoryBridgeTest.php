<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Integration;

use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Graph\Detector;
use NvoosContentGraph\Memory\Bridge;
use NvoosContentGraph\Schema;
use WP_UnitTestCase;

/**
 * Integration tests for {@see Bridge} — the agent-memory bridge that
 * projects `wp_mcp_ai_memory_stored` / `nvoos_content_graph/memory_stored`
 * events into the graph and serves the NV oOS `wake_up_context` retrieval
 * seam.
 *
 * Uses the real WordPress test database (real DDL via {@see Db::install()}).
 *
 * @since 1.0.5
 */
class MemoryBridgeTest extends WP_UnitTestCase {

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
	}

	/**
	 * Tear down — restore the schema in case a test dropped tables.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Db::install();
		parent::tearDown();
	}

	/** @return void */
	public function testRegisterAttachesMemoryHooksAndRetrieverFilter(): void {
		Bridge::register();

		$this->assertNotFalse( has_action( Schema::ACTION_MEMORY_STORED, array( Bridge::class, 'onMemoryStored' ) ) );
		$this->assertNotFalse( has_action( 'wp_mcp_ai_memory_stored', array( Bridge::class, 'onMemoryStored' ) ) );
		$this->assertNotFalse( has_filter( 'wp_mcp_ai_wake_up_context_graph_retriever', array( Bridge::class, 'onWakeUpGraphRetriever' ) ) );
	}

	/** @return void */
	public function testMalformedPayloadIsIgnored(): void {
		Bridge::onMemoryStored( array() );
		Bridge::onMemoryStored( array( 'context_id' => '' ) );
		Bridge::onMemoryStored( array( 'context_id' => 'ctx_no_agent' ) );

		// No memory node may exist for the agent-less payload — the graph may
		// legitimately contain other nodes (auto-rebuild on post save).
		$this->assertNull( Db::getNode( Bridge::NODE_PREFIX_MEMORY . 'ctx_no_agent' ) );
	}

	/** @return void */
	public function testProjectionCreatesMemoryAgentWingRoomNodesAndEdges(): void {
		Bridge::onMemoryStored(
			array(
				'context_id'   => 'ctx_projection_1',
				'agent_id'     => '42',
				'wing'         => 'client-acme',
				'room'         => 'design-system',
				'title'        => 'Acme uses HSL tokens',
				'content'      => 'All Acme components use the HSL design tokens.',
				'importance'   => 'high',
				'context_type' => 'fact',
				'tags'         => array( 'design', 'tokens' ),
				'verbatim'     => true,
				'stored_at'    => '2026-09-08 10:00:00',
				'expires_at'   => '2026-09-09 10:00:00',
			)
		);

		$memoryNode = Db::getNode( Bridge::NODE_PREFIX_MEMORY . 'ctx_projection_1' );
		$this->assertNotNull( $memoryNode );
		$this->assertSame( 'memory', $memoryNode->type );
		$this->assertSame( 'agent_memory', $memoryNode->source_slug );

		$properties = json_decode( (string) $memoryNode->properties, true );
		$this->assertSame( 'ctx_projection_1', $properties['context_id'] );
		$this->assertSame( '42', $properties['agent_id'] );
		$this->assertSame( 'client-acme', $properties['wing'] );
		$this->assertSame( 'design-system', $properties['room'] );
		$this->assertSame( 'high', $properties['importance'] );
		$this->assertSame( 'fact', $properties['context_type'] );
		$this->assertSame( 'fact', $properties['memory_type'] );
		$this->assertSame( 1, $properties['verbatim'] );
		$this->assertSame( array( 'design', 'tokens' ), $properties['tags'] );

		// Agent node + OBSERVED_BY edge.
		$agentNodeId = Bridge::NODE_PREFIX_AGENT . '42';
		$this->assertNotNull( Db::getNode( $agentNodeId ) );
		$this->assertContains( $agentNodeId, Db::getNeighborIds( Bridge::NODE_PREFIX_MEMORY . 'ctx_projection_1', 'OBSERVED_BY' ) );

		// Wing + room nodes and MEMBER_OF edges.
		$wingNodeId = Bridge::NODE_PREFIX_WING . 'client-acme';
		$roomNodeId = Bridge::NODE_PREFIX_ROOM . 'client-acme:design-system';
		$this->assertNotNull( Db::getNode( $wingNodeId ) );
		$this->assertNotNull( Db::getNode( $roomNodeId ) );
		$this->assertContains( $wingNodeId, Db::getNeighborIds( Bridge::NODE_PREFIX_MEMORY . 'ctx_projection_1', 'MEMBER_OF' ) );
		$this->assertContains( $roomNodeId, Db::getNeighborIds( Bridge::NODE_PREFIX_MEMORY . 'ctx_projection_1', 'MEMBER_OF' ) );
	}

	/** @return void */
	public function testProjectionDerivedFromLinksExistingPostNode(): void {
		$postId = self::factory()->post->create();

		// The graph's post-node convention is Detector::postNodeId()
		// (`post_{id}`), the same id the structural extractor writes during
		// auto-rebuilds.
		$postNodeId = Detector::postNodeId( $postId );
		Db::upsertNode(
			array(
				'node_id' => $postNodeId,
				'label'   => 'Source post',
				'type'    => 'post',
				'post_id' => $postId,
			)
		);

		Bridge::onMemoryStored(
			array(
				'context_id'     => 'ctx_derived_1',
				'agent_id'       => '7',
				'source_post_id' => $postId,
			)
		);

		$this->assertContains(
			$postNodeId,
			Db::getNeighborIds( Bridge::NODE_PREFIX_MEMORY . 'ctx_derived_1', 'DERIVED_FROM' )
		);
	}

	/** @return void */
	public function testRetrieveGraphRanksMemoriesByAgentAnchor(): void {
		Bridge::onMemoryStored(
			array(
				'context_id' => 'ctx_rank_1',
				'agent_id'   => '99',
				'title'      => 'First memory',
				'content'    => 'first',
			)
		);
		Bridge::onMemoryStored(
			array(
				'context_id' => 'ctx_rank_2',
				'agent_id'   => '99',
				'title'      => 'Second memory',
				'content'    => 'second',
			)
		);

		$ranked = Bridge::retrieveGraph( array( 'agent_id' => '99' ) );

		$ids = array_map(
			static function ( array $row ): string {
				return $row['context_id'];
			},
			$ranked
		);
		$this->assertContains( 'ctx_rank_1', $ids );
		$this->assertContains( 'ctx_rank_2', $ids );
		$this->assertSame( array( 'agent' ), $ranked[0]['via'] );
		$this->assertGreaterThan( 0.0, $ranked[0]['score'] );
	}

	/** @return void */
	public function testRetrieveGraphReturnsEmptyForUnknownAgent(): void {
		$this->assertSame( array(), Bridge::retrieveGraph( array( 'agent_id' => '404' ) ) );
		$this->assertSame( array(), Bridge::retrieveGraph( array() ) );
	}

	/** @return void */
	public function testWakeUpRetrieverServesRankingAndDefersWithoutAgent(): void {
		// No agent id → defer to the next provider.
		$this->assertNull( Bridge::onWakeUpGraphRetriever( null, array() ) );

		Bridge::onMemoryStored(
			array(
				'context_id' => 'ctx_retriever_1',
				'agent_id'   => '55',
				'title'      => 'Retriever memory',
				'content'    => 'served through the filter seam',
			)
		);

		$ranked = Bridge::onWakeUpGraphRetriever(
			null,
			array(
				'agent_id' => '55',
				'limit'    => 10,
			)
		);
		$this->assertIsArray( $ranked );
		$this->assertNotEmpty( $ranked );
		$this->assertSame( 'ctx_retriever_1', $ranked[0]['context_id'] );
	}
}
