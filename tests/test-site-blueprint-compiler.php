<?php
/**
 * Tests for the Site Blueprint Compiler — loading, validation, placeholder
 * substitution, ID prefixing, and end-to-end compilation → execution.
 *
 * @package    WP_MCP_AI
 * @subpackage Site_Builder
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    GPL-3.0-or-later
 */

/**
 * Test the site blueprint compiler.
 */
class Test_Site_Blueprint_Compiler extends WP_UnitTestCase {

	/**
	 * Compiler instance.
	 *
	 * @var WP_MCP_AI_Site_Blueprint_Compiler
	 */
	private $compiler;

	/**
	 * Executor instance for end-to-end tests.
	 *
	 * @var WP_MCP_AI_Site_Pipeline_Executor
	 */
	private $executor;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/site-builder/class-wp-mcp-ai-site-node-interface.php';
		require_once dirname( __DIR__ ) . '/includes/site-builder/class-wp-mcp-ai-site-node-registry.php';
		require_once dirname( __DIR__ ) . '/includes/site-builder/class-wp-mcp-ai-site-pipeline-executor.php';
		require_once dirname( __DIR__ ) . '/includes/site-builder/class-wp-mcp-ai-site-blueprint-compiler.php';

		// Register built-in nodes.
		$registry = WP_MCP_AI_Site_Node_Registry::get_instance();
		$registry->init();

		$this->compiler = new WP_MCP_AI_Site_Blueprint_Compiler();
		$this->executor = new WP_MCP_AI_Site_Pipeline_Executor( 60 );
	}

	/**
	 * Tear down: clear caches.
	 */
	public function tearDown(): void {
		$this->compiler->clear_cache();
		$this->executor->clear_cache( 'hero-with-cta' );
		$this->executor->clear_cache( 'two-column-text' );
		parent::tearDown();
	}

	// ─────────── Loading ───────────

	/**
	 * Test that load() returns valid data for an existing blueprint.
	 */
	public function test_load_existing_blueprint() {
		$blueprint = $this->compiler->load( 'hero-with-cta' );

		$this->assertIsArray( $blueprint );
		$this->assertSame( 'hero-with-cta', $blueprint['slug'] );
		$this->assertArrayHasKey( 'internalGraph', $blueprint );
		$this->assertArrayHasKey( 'nodes', $blueprint['internalGraph'] );
		$this->assertArrayHasKey( 'edges', $blueprint['internalGraph'] );
	}

	/**
	 * Test that load() returns null for a nonexistent blueprint.
	 */
	public function test_load_nonexistent_blueprint_returns_null() {
		$this->assertNull( $this->compiler->load( 'does-not-exist' ) );
	}

	/**
	 * Test that list_all() returns known blueprints.
	 */
	public function test_list_all_returns_slugs() {
		$slugs = $this->compiler->list_all();

		$this->assertContains( 'hero-with-cta', $slugs );
		$this->assertContains( 'two-column-text', $slugs );
	}

	/**
	 * Test that list_all_summaries() returns structured data.
	 */
	public function test_list_all_summaries() {
		$summaries = $this->compiler->list_all_summaries();

		$this->assertNotEmpty( $summaries );

		$hero = null;
		foreach ( $summaries as $s ) {
			if ( 'hero-with-cta' === $s['slug'] ) {
				$hero = $s;
				break;
			}
		}

		$this->assertIsArray( $hero );
		$this->assertSame( 'Hero Section with CTA', $hero['name'] );
		$this->assertNotEmpty( $hero['inputs'] );
		$this->assertNotEmpty( $hero['outputs'] );
	}

	// ─────────── Compilation ───────────

	/**
	 * Test compile() produces correct node ID prefixing.
	 */
	public function test_compile_prefixes_internal_node_ids() {
		$bp = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile( $bp );

		foreach ( $graph['nodes'] as $node_id => $config ) {
			$this->assertStringStartsWith( 'hero-with-cta__', $node_id, 'Internal node IDs should be prefixed with blueprint slug.' );
		}

		// Verify a known node exists with the prefix.
		$this->assertArrayHasKey( 'hero-with-cta__heading_block', $graph['nodes'] );
		$this->assertArrayHasKey( 'hero-with-cta__hero_container', $graph['nodes'] );
	}

	/**
	 * Test compile() substitutes {placeholders} with default values.
	 */
	public function test_compile_substitutes_default_placeholders() {
		$bp    = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile( $bp );

		$heading_node = $graph['nodes']['hero-with-cta__heading_block'];
		$this->assertSame( 'Welcome to Our Site', $heading_node['inputs']['content'], '{heading} should resolve to default.' );
	}

	/**
	 * Test compile() substitutes {placeholders} with provided values.
	 */
	public function test_compile_substitutes_provided_values() {
		$bp    = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile( $bp, array( 'heading' => 'Custom Title' ) );

		$heading_node = $graph['nodes']['hero-with-cta__heading_block'];
		$this->assertSame( 'Custom Title', $heading_node['inputs']['content'] );
	}

	/**
	 * Test compile() applies provided values over defaults (partial override).
	 */
	public function test_compile_partial_override() {
		$bp    = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile( $bp, array( 'heading' => 'Overridden' ) );

		// heading is overridden.
		$this->assertSame( 'Overridden', $graph['nodes']['hero-with-cta__heading_block']['inputs']['content'] );
		// subheading falls back to default.
		$this->assertSame( 'We build amazing things with WordPress.', $graph['nodes']['hero-with-cta__subheading_block']['inputs']['content'] );
	}

	/**
	 * Test compile() prefixes edge source/target IDs.
	 */
	public function test_compile_prefixes_edges() {
		$bp    = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile( $bp );

		$this->assertNotEmpty( $graph['edges'] );
		$first_edge = $graph['edges'][0];
		$this->assertStringStartsWith( 'hero-with-cta__', $first_edge['source'] );
		$this->assertStringStartsWith( 'hero-with-cta__', $first_edge['target'] );
	}

	/**
	 * Test compile() returns WP_Error for empty internal graph.
	 */
	public function test_compile_empty_graph_returns_error() {
		$bp = array(
			'slug'          => 'empty-bp',
			'name'          => 'Empty',
			'internalGraph' => array( 'nodes' => array(), 'edges' => array() ),
		);

		$result = $this->compiler->compile( $bp );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_site_blueprint_empty', $result->get_error_code() );
	}

	// ─────────── End-to-end: compile → execute ───────────

	/**
	 * Test end-to-end: compile hero-with-cta and execute the resulting graph.
	 */
	public function test_e2e_hero_blueprint() {
		$bp    = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile( $bp, array(
			'heading'    => 'Build Faster',
			'subheading' => 'The site builder you always wanted.',
			'cta_text'   => 'Try It Free',
		) );

		$result  = $this->executor->execute( $graph, 'hero-with-cta' );
		$outputs = $result['outputs'];

		// The output node is hero-with-cta__hero_container.
		$html = $outputs['hero-with-cta__hero_container']['html'];

		$this->assertStringContainsString( 'Build Faster', $html );
		$this->assertStringContainsString( 'The site builder you always wanted.', $html );
		$this->assertStringContainsString( 'Try It Free', $html );
		$this->assertStringContainsString( 'display:flex', $html );
		$this->assertStringContainsString( 'flex-direction:column', $html );
	}

	/**
	 * Test end-to-end: compile two-column-text and execute.
	 */
	public function test_e2e_two_column_blueprint() {
		$bp    = $this->compiler->load( 'two-column-text' );
		$graph = $this->compiler->compile( $bp, array(
			'left_heading'  => 'Speed',
			'left_body'     => 'Lightning-fast page loads.',
			'right_heading' => 'Security',
			'right_body'    => 'Enterprise-grade protection.',
		) );

		$result  = $this->executor->execute( $graph, 'two-column-text' );
		$outputs = $result['outputs'];

		$html = $outputs['two-column-text__outer_row']['html'];

		$this->assertStringContainsString( 'Speed', $html );
		$this->assertStringContainsString( 'Lightning-fast page loads.', $html );
		$this->assertStringContainsString( 'Security', $html );
		$this->assertStringContainsString( 'Enterprise-grade protection.', $html );
		$this->assertStringContainsString( 'display:flex', $html );
		$this->assertStringContainsString( 'flex-direction:row', $html );
	}

	/**
	 * Test get_summary for hero-with-cta returns correct structure.
	 */
	public function test_get_summary_hero() {
		$summary = $this->compiler->get_summary( 'hero-with-cta' );

		$this->assertIsArray( $summary );
		$this->assertSame( 'hero-with-cta', $summary['slug'] );
		$this->assertSame( 'Hero Section with CTA', $summary['name'] );
		$this->assertCount( 4, $summary['inputs'] );
		$this->assertCount( 1, $summary['outputs'] );
	}

	/**
	 * Test get_summary returns null for unknown slug.
	 */
	public function test_get_summary_unknown_returns_null() {
		$this->assertNull( $this->compiler->get_summary( 'no-such-blueprint' ) );
	}

	/**
	 * Test that compiler caches loaded blueprints in memory.
	 */
	public function test_blueprint_is_cached_after_load() {
		$first  = $this->compiler->load( 'hero-with-cta' );
		$second = $this->compiler->load( 'hero-with-cta' );

		// Should return the same array reference (not a deep copy).
		$this->assertSame( $first, $second );
	}

	/**
	 * Test clear_cache() resets the in-memory store.
	 */
	public function test_clear_cache_clears_memory() {
		$this->compiler->load( 'hero-with-cta' );
		$this->compiler->clear_cache();

		// Re-loading should still work (re-read from disk).
		$reloaded = $this->compiler->load( 'hero-with-cta' );
		$this->assertIsArray( $reloaded );
		$this->assertSame( 'hero-with-cta', $reloaded['slug'] );
	}
}
