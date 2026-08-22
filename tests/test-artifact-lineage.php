<?php
/**
 * Tests for the Artifact Lineage graph (Phase G.2).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test lineage graph payloads, root walks and the ASCII renderer.
 */
class Test_Artifact_Lineage extends WP_UnitTestCase {

	/**
	 * Population hashes for a seed → child → grandchild chain.
	 *
	 * @var array<string,string>
	 */
	private $chain = array(
		'seed'       => '',
		'child'      => '',
		'grandchild' => '',
	);

	/**
	 * Seed the population with a three-generation chain.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Artifact_Lineage' ) || ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'Artifact lineage or population class not available.' );
		}

		WP_MCP_AI_Artifact_Population::clear();

		$this->chain['seed']       = WP_MCP_AI_Artifact_Population::archive( 'prompt', 'a1', array( 'prompt' => 'Seed.' ), 0.5, array(), null, 1, 1000 );
		$this->chain['child']      = WP_MCP_AI_Artifact_Population::archive( 'prompt', 'a1', array( 'prompt' => 'Child.' ), 0.7, array(), $this->chain['seed'], 1, 2000 );
		$this->chain['grandchild'] = WP_MCP_AI_Artifact_Population::archive( 'prompt', 'a1', array( 'prompt' => 'Grandchild.' ), 0.9, array(), $this->chain['child'], 1, 3000 );
	}

	/**
	 * Reset the population after each test.
	 */
	public function tearDown(): void {
		if ( class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			WP_MCP_AI_Artifact_Population::clear();
		}
		parent::tearDown();
	}

	/**
	 * The hash_for helper matches the population's content addressing.
	 */
	public function test_hash_for_matches_population_addressing() {
		$payload = array( 'prompt' => 'Addressed.' );

		$expected = WP_MCP_AI_Artifact_Population::archive( 'prompt', 'a1', $payload, 0.5 );

		$this->assertSame( $expected, WP_MCP_AI_Artifact_Lineage::hash_for( 'prompt', $payload ) );
	}

	/**
	 * The hash_for helper wraps plain prompt strings like the evolver's archive shape.
	 */
	public function test_hash_for_wraps_prompt_strings() {
		$expected = WP_MCP_AI_Artifact_Population::archive( 'prompt', 'a1', array( 'prompt' => 'Plain string.' ), 0.5 );

		$this->assertSame( $expected, WP_MCP_AI_Artifact_Lineage::hash_for( 'prompt', 'Plain string.' ) );
	}

	/**
	 * The graph contains the whole chain with the seed as root.
	 */
	public function test_graph_walks_ancestors_and_descendants() {
		$graph = WP_MCP_AI_Artifact_Lineage::graph( 'prompt', $this->chain['grandchild'] );

		$this->assertSame( $this->chain['seed'], $graph['root'] );
		$this->assertCount( 3, $graph['nodes'] );
		$this->assertArrayHasKey( $this->chain['seed'], $graph['nodes'] );
		$this->assertArrayHasKey( $this->chain['child'], $graph['nodes'] );
		$this->assertArrayHasKey( $this->chain['grandchild'], $graph['nodes'] );

		$this->assertCount( 2, $graph['edges'] );
		$this->assertContains( array( $this->chain['seed'], $this->chain['child'] ), $graph['edges'] );
		$this->assertContains( array( $this->chain['child'], $this->chain['grandchild'] ), $graph['edges'] );
	}

	/**
	 * The get_root helper resolves the seed ancestor.
	 */
	public function test_get_root_resolves_seed() {
		$this->assertSame(
			$this->chain['seed'],
			WP_MCP_AI_Artifact_Lineage::get_root( 'prompt', $this->chain['grandchild'] )
		);
	}

	/**
	 * The depth bound truncates the descendant walk.
	 */
	public function test_depth_bound_truncates_descendants() {
		$graph = WP_MCP_AI_Artifact_Lineage::graph( 'prompt', $this->chain['seed'], 1 );

		$this->assertCount( 2, $graph['nodes'] );
		$this->assertArrayHasKey( $this->chain['seed'], $graph['nodes'] );
		$this->assertArrayHasKey( $this->chain['child'], $graph['nodes'] );
		$this->assertArrayNotHasKey( $this->chain['grandchild'], $graph['nodes'] );
	}

	/**
	 * Unknown hashes produce an empty graph.
	 */
	public function test_unknown_hash_is_empty() {
		$graph = WP_MCP_AI_Artifact_Lineage::graph( 'prompt', 'nonexistent' );

		$this->assertSame( array(), $graph['nodes'] );
		$this->assertSame( '', $graph['root'] );
	}

	/**
	 * The ASCII renderer emits the whole tree deterministically.
	 */
	public function test_render_ascii_tree() {
		$tree = WP_MCP_AI_Artifact_Lineage::render_ascii( 'prompt', $this->chain['grandchild'] );

		$this->assertNotSame( '', $tree );
		$this->assertStringContainsString( substr( $this->chain['seed'], 0, 8 ), $tree );
		$this->assertStringContainsString( substr( $this->chain['child'], 0, 8 ), $tree );
		$this->assertStringContainsString( substr( $this->chain['grandchild'], 0, 8 ), $tree );
		$this->assertStringContainsString( 'prompt', $tree );
		$this->assertStringContainsString( 'score', $tree );
	}

	/**
	 * Unknown hashes render an empty tree.
	 */
	public function test_render_ascii_unknown_hash_is_empty() {
		$this->assertSame( '', WP_MCP_AI_Artifact_Lineage::render_ascii( 'prompt', 'nonexistent' ) );
	}
}
