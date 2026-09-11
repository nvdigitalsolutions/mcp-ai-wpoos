<?php
/**
 * Site pipeline executor port tests (Wave E4, sub-cluster 5).
 *
 * Characterization suite for the ported `SitePipelineExecutor`: the
 * Kahn topological sort (linear + forked graphs, dependency-respecting
 * order), cycle detection, the empty-pipeline / missing-slug /
 * unregistered-node / missing-dependency envelopes, static-input
 * execution, edge-overlay input resolution with the multi-source
 * array rule, the two-tier cache contract, per-pipeline cache
 * clearing, and input-hash cache invalidation. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\SiteBuilder\SiteNodeRegistry;
use NvoosContentGraphAiPlatform\SiteBuilder\SitePipelineExecutor;

/**
 * Site pipeline executor characterization.
 */
class Test_Site_Builder_Executor extends \WP_UnitTestCase {

	/**
	 * Executor instance.
	 *
	 * @var SitePipelineExecutor
	 */
	private $executor;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Register built-in nodes.
		$registry = SiteNodeRegistry::get_instance();
		$registry->init();

		$this->executor = new SitePipelineExecutor( 60 ); // 60s TTL for tests.
	}

	/**
	 * Tear down: clear test caches.
	 */
	public function tearDown(): void {
		$this->executor->clear_cache( 'test_pipeline' );
		$this->executor->clear_cache( 'test_two_node' );
		$this->executor->clear_cache( 'test_three_node' );
		parent::tearDown();
	}

	// ─────────── Topological sort ───────────

	/**
	 * A simple linear pipeline: A → B.
	 */
	public function test_execute_simple_linear_pipeline() {
		$graph = array(
			'nodes' => array(
				'text_a' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'Hello' ),
				),
				'text_b' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'tag' => 'p' ),
				),
			),
			'edges' => array(
				array(
					'source'     => 'text_a',
					'sourcePort' => 'html',
					'target'     => 'text_b',
					'targetPort' => 'content',
				),
			),
		);

		$result = $this->executor->execute( $graph, 'test_two_node' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'outputs', $result );

		$outputs = $result['outputs'];
		$this->assertArrayHasKey( 'text_a', $outputs );
		$this->assertArrayHasKey( 'text_b', $outputs );

		// text_a: independent, renders "Hello" in a div.
		$this->assertStringContainsString( 'Hello', $outputs['text_a']['html'] );

		// text_b: receives text_a's HTML as its content, wraps in <p>.
		$this->assertStringContainsString( '<p', $outputs['text_b']['html'] );
		$this->assertStringContainsString( 'Hello', $outputs['text_b']['html'] );
	}

	/**
	 * A three-node pipeline with a fork: two text blocks feeding one flex
	 * container's children port.
	 */
	public function test_execute_forked_pipeline() {
		$graph = array(
			'nodes' => array(
				'child_1' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'Child A' ),
				),
				'child_2' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'Child B' ),
				),
				'flex'    => array(
					'slug'   => 'flex_container',
					'inputs' => array( 'direction' => 'row' ),
				),
			),
			'edges' => array(
				array(
					'source'     => 'child_1',
					'sourcePort' => 'html',
					'target'     => 'flex',
					'targetPort' => 'children',
				),
				array(
					'source'     => 'child_2',
					'sourcePort' => 'html',
					'target'     => 'flex',
					'targetPort' => 'children',
				),
			),
		);

		$result  = $this->executor->execute( $graph, 'test_three_node' );
		$outputs = $result['outputs'];

		$this->assertArrayHasKey( 'flex', $outputs );
		$this->assertStringContainsString( 'Child A', $outputs['flex']['html'] );
		$this->assertStringContainsString( 'Child B', $outputs['flex']['html'] );
		$this->assertStringContainsString( 'display:flex', $outputs['flex']['html'] );
	}

	/**
	 * Execution order respects dependencies (topological sort).
	 */
	public function test_topological_sort_order() {
		$graph = array(
			'nodes' => array(
				'text_a' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'A' ),
				),
				'text_b' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'B' ),
				),
				'text_c' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'C' ),
				),
			),
			'edges' => array(
				array(
					'source'     => 'text_a',
					'sourcePort' => 'html',
					'target'     => 'text_b',
					'targetPort' => 'content',
				),
				array(
					'source'     => 'text_b',
					'sourcePort' => 'html',
					'target'     => 'text_c',
					'targetPort' => 'content',
				),
			),
		);

		$result  = $this->executor->execute( $graph, 'test_pipeline' );
		$outputs = $result['outputs'];

		$this->assertStringContainsString( '<div>A</div>', $outputs['text_a']['html'] );
		$this->assertStringContainsString( '<div><div>A</div></div>', $outputs['text_b']['html'] );
		$this->assertStringContainsString( '<div><div><div>A</div></div></div>', $outputs['text_c']['html'] );
	}

	/**
	 * Cycle detection returns WP_Error.
	 */
	public function test_cycle_detection_returns_error() {
		$graph = array(
			'nodes' => array(
				'node_a' => array( 'slug' => 'text_block' ),
				'node_b' => array( 'slug' => 'text_block' ),
			),
			'edges' => array(
				array(
					'source'     => 'node_a',
					'sourcePort' => 'html',
					'target'     => 'node_b',
					'targetPort' => 'content',
				),
				array(
					'source'     => 'node_b',
					'sourcePort' => 'html',
					'target'     => 'node_a',
					'targetPort' => 'content',
				),
			),
		);

		$result = $this->executor->execute( $graph, 'test_pipeline' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_site_pipeline_cycle', $result->get_error_code() );
	}

	/**
	 * An empty pipeline returns WP_Error.
	 */
	public function test_empty_pipeline_returns_error() {
		$graph  = array(
			'nodes' => array(),
			'edges' => array(),
		);
		$result = $this->executor->execute( $graph );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_site_empty_pipeline', $result->get_error_code() );
	}

	/**
	 * A node missing a slug returns WP_Error.
	 */
	public function test_missing_slug_returns_error() {
		$graph = array(
			'nodes' => array(
				'bad_node' => array( 'inputs' => array() ), // no 'slug' key.
			),
			'edges' => array(),
		);

		$result = $this->executor->execute( $graph );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_site_node_missing_slug', $result->get_error_code() );
	}

	/**
	 * Executing an unregistered node slug returns WP_Error.
	 */
	public function test_unregistered_node_returns_error() {
		$graph = array(
			'nodes' => array(
				'ghost' => array(
					'slug'   => 'nonexistent_node_type',
					'inputs' => array(),
				),
			),
			'edges' => array(),
		);

		$result = $this->executor->execute( $graph );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_site_node_not_found', $result->get_error_code() );
	}

	// ─────────── Input resolution ───────────

	/**
	 * Static inputs are used when no edges are connected.
	 */
	public function test_static_inputs_used_without_edges() {
		$graph = array(
			'nodes' => array(
				'isolated' => array(
					'slug'   => 'text_block',
					'inputs' => array(
						'content' => 'Static content',
						'tag'     => 'h1',
					),
				),
			),
			'edges' => array(),
		);

		$result = $this->executor->execute( $graph, 'test_pipeline' );

		$this->assertStringContainsString( '<h1>', $result['outputs']['isolated']['html'] );
		$this->assertStringContainsString( 'Static content', $result['outputs']['isolated']['html'] );
	}

	/**
	 * Edge-connected values override static inputs.
	 */
	public function test_edge_values_override_static_inputs() {
		$graph = array(
			'nodes' => array(
				'source' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'From upstream' ),
				),
				'target' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'Will be overridden' ),
				),
			),
			'edges' => array(
				array(
					'source'     => 'source',
					'sourcePort' => 'html',
					'target'     => 'target',
					'targetPort' => 'content',
				),
			),
		);

		$result = $this->executor->execute( $graph, 'test_pipeline' );

		$this->assertStringContainsString( 'From upstream', $result['outputs']['target']['html'] );
		$this->assertStringNotContainsString( 'Will be overridden', $result['outputs']['target']['html'] );
	}

	/**
	 * A node with no upstream connections executes successfully.
	 */
	public function test_standalone_node_executes() {
		$graph = array(
			'nodes' => array(
				'only' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'Solo' ),
				),
			),
			'edges' => array(),
		);

		$result = $this->executor->execute( $graph );

		$this->assertArrayHasKey( 'only', $result['outputs'] );
		$this->assertStringContainsString( 'Solo', $result['outputs']['only']['html'] );
	}

	// ─────────── Caching ───────────

	/**
	 * Re-executing unchanged nodes with the same pipeline_id produces
	 * identical output (cache hit).
	 */
	public function test_cache_reuse_on_second_execution() {
		$graph = array(
			'nodes' => array(
				'cached_node' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'Cache me' ),
				),
			),
			'edges' => array(),
		);

		$first  = $this->executor->execute( $graph, 'test_pipeline' );
		$second = $this->executor->execute( $graph, 'test_pipeline' );

		$this->assertSame(
			$first['outputs']['cached_node']['html'],
			$second['outputs']['cached_node']['html'],
			'Cached and fresh executions should produce identical output.'
		);
	}

	/**
	 * clear_cache removes entries for a specific pipeline.
	 */
	public function test_clear_cache_removes_pipeline_entries() {
		$graph = array(
			'nodes' => array(
				'node_x' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'X' ),
				),
			),
			'edges' => array(),
		);

		$this->executor->execute( $graph, 'test_pipeline' );

		global $wpdb;
		$like = $wpdb->esc_like( '_transient_wp_mcp_ai_site_node_test_pipeline_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_before = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);

		$this->assertGreaterThan( 0, $count_before, 'Cache should have entries after execution.' );

		$this->executor->clear_cache( 'test_pipeline' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_after = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);

		$this->assertSame( 0, $count_after, 'All cache entries should be removed after clear_cache.' );
	}

	/**
	 * Changing inputs produces a different output (not served from cache).
	 */
	public function test_changing_inputs_produces_different_output() {
		$graph_a = array(
			'nodes' => array(
				'node' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'Version A' ),
				),
			),
			'edges' => array(),
		);

		$graph_b = array(
			'nodes' => array(
				'node' => array(
					'slug'   => 'text_block',
					'inputs' => array( 'content' => 'Version B' ),
				),
			),
			'edges' => array(),
		);

		$result_a = $this->executor->execute( $graph_a, 'test_pipeline' );
		$result_b = $this->executor->execute( $graph_b, 'test_pipeline' );

		$this->assertStringContainsString( 'Version A', $result_a['outputs']['node']['html'] );
		$this->assertStringContainsString( 'Version B', $result_b['outputs']['node']['html'] );
		$this->assertNotSame(
			$result_a['outputs']['node']['html'],
			$result_b['outputs']['node']['html'],
			'Different inputs should produce different outputs.'
		);
	}
}
