<?php
/**
 * Site blueprint compiler port tests (Wave E4, sub-cluster 5).
 *
 * Characterization suite for the ported `SiteBlueprintCompiler`:
 * loading from the per-mode blueprint directory, required-key
 * validation, slug listing, summary serialisation, `{placeholder}`
 * substitution (defaults, provided values, partial override), internal
 * node-ID + edge prefixing, the `wp_mcp_ai_site_blueprint_empty`
 * envelope, the in-memory cache, and end-to-end compile → execute runs
 * for both shipped blueprints. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\SiteBuilder\SiteNodeRegistry;
use NvoosContentGraphAiPlatform\SiteBuilder\SitePipelineExecutor;
use NvoosContentGraphAiPlatform\SiteBuilder\SiteBlueprintCompiler;

/**
 * Site blueprint compiler characterization.
 */
class Test_Site_Builder_Blueprint_Compiler extends \WP_UnitTestCase {

	/**
	 * Compiler instance.
	 *
	 * @var SiteBlueprintCompiler
	 */
	private $compiler;

	/**
	 * Executor instance for end-to-end tests.
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

		$this->compiler = new SiteBlueprintCompiler();
		$this->executor = new SitePipelineExecutor( 60 );
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
	 * load() returns valid data for an existing blueprint.
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
	 * load() returns null for a nonexistent blueprint.
	 */
	public function test_load_nonexistent_blueprint_returns_null() {
		$this->assertNull( $this->compiler->load( 'does-not-exist' ) );
	}

	/**
	 * list_all() returns the shipped blueprints.
	 */
	public function test_list_all_returns_slugs() {
		$slugs = $this->compiler->list_all();

		$this->assertContains( 'hero-with-cta', $slugs );
		$this->assertContains( 'two-column-text', $slugs );
	}

	/**
	 * list_all_summaries() returns structured data.
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
	 * compile() prefixes internal node IDs with the blueprint slug.
	 */
	public function test_compile_prefixes_internal_node_ids() {
		$bp    = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile( $bp );

		foreach ( $graph['nodes'] as $node_id => $config ) {
			$this->assertStringStartsWith( 'hero-with-cta__', $node_id, 'Internal node IDs should be prefixed with blueprint slug.' );
		}

		$this->assertArrayHasKey( 'hero-with-cta__heading_block', $graph['nodes'] );
		$this->assertArrayHasKey( 'hero-with-cta__hero_container', $graph['nodes'] );
	}

	/**
	 * compile() substitutes {placeholders} with default values.
	 */
	public function test_compile_substitutes_default_placeholders() {
		$bp    = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile( $bp );

		$heading_node = $graph['nodes']['hero-with-cta__heading_block'];
		$this->assertSame( 'Welcome to Our Site', $heading_node['inputs']['content'], '{heading} should resolve to default.' );
	}

	/**
	 * compile() substitutes {placeholders} with provided values.
	 */
	public function test_compile_substitutes_provided_values() {
		$bp    = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile( $bp, array( 'heading' => 'Custom Title' ) );

		$heading_node = $graph['nodes']['hero-with-cta__heading_block'];
		$this->assertSame( 'Custom Title', $heading_node['inputs']['content'] );
	}

	/**
	 * compile() applies provided values over defaults (partial override).
	 */
	public function test_compile_partial_override() {
		$bp    = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile( $bp, array( 'heading' => 'Overridden' ) );

		$this->assertSame( 'Overridden', $graph['nodes']['hero-with-cta__heading_block']['inputs']['content'] );
		$this->assertSame( 'We build amazing things with WordPress.', $graph['nodes']['hero-with-cta__subheading_block']['inputs']['content'] );
	}

	/**
	 * compile() prefixes edge source/target IDs.
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
	 * compile() returns WP_Error for an empty internal graph.
	 */
	public function test_compile_empty_graph_returns_error() {
		$bp = array(
			'slug'          => 'empty-bp',
			'name'          => 'Empty',
			'internalGraph' => array(
				'nodes' => array(),
				'edges' => array(),
			),
		);

		$result = $this->compiler->compile( $bp );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_site_blueprint_empty', $result->get_error_code() );
	}

	// ─────────── End-to-end: compile → execute ───────────

	/**
	 * End-to-end: compile hero-with-cta and execute the resulting graph.
	 */
	public function test_e2e_hero_blueprint() {
		$bp    = $this->compiler->load( 'hero-with-cta' );
		$graph = $this->compiler->compile(
			$bp,
			array(
				'heading'    => 'Build Faster',
				'subheading' => 'The site builder you always wanted.',
				'cta_text'   => 'Try It Free',
			)
		);

		$result  = $this->executor->execute( $graph, 'hero-with-cta' );
		$outputs = $result['outputs'];

		$html = $outputs['hero-with-cta__hero_container']['html'];

		$this->assertStringContainsString( 'Build Faster', $html );
		$this->assertStringContainsString( 'The site builder you always wanted.', $html );
		$this->assertStringContainsString( 'Try It Free', $html );
		$this->assertStringContainsString( 'display:flex', $html );
		$this->assertStringContainsString( 'flex-direction:column', $html );
	}

	/**
	 * End-to-end: compile two-column-text and execute.
	 */
	public function test_e2e_two_column_blueprint() {
		$bp    = $this->compiler->load( 'two-column-text' );
		$graph = $this->compiler->compile(
			$bp,
			array(
				'left_heading'  => 'Speed',
				'left_body'     => 'Lightning-fast page loads.',
				'right_heading' => 'Security',
				'right_body'    => 'Enterprise-grade protection.',
			)
		);

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
	 * get_summary returns the documented structure.
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
	 * get_summary returns null for an unknown slug.
	 */
	public function test_get_summary_unknown_returns_null() {
		$this->assertNull( $this->compiler->get_summary( 'no-such-blueprint' ) );
	}

	/**
	 * Loaded blueprints are cached in memory.
	 */
	public function test_blueprint_is_cached_after_load() {
		$first  = $this->compiler->load( 'hero-with-cta' );
		$second = $this->compiler->load( 'hero-with-cta' );

		$this->assertSame( $first, $second );
	}

	/**
	 * clear_cache() resets the in-memory store.
	 */
	public function test_clear_cache_clears_memory() {
		$this->compiler->load( 'hero-with-cta' );
		$this->compiler->clear_cache();

		$reloaded = $this->compiler->load( 'hero-with-cta' );
		$this->assertIsArray( $reloaded );
		$this->assertSame( 'hero-with-cta', $reloaded['slug'] );
	}
}
