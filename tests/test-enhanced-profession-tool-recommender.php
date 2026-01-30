<?php
/**
 * Tests for Enhanced Profession Tool Recommender.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Enhanced Profession Tool Recommender.
 */
class WP_MCP_AI_Enhanced_Profession_Tool_Recommender_Test extends WP_UnitTestCase {

	/**
	 * Recommender instance.
	 *
	 * @var WP_MCP_AI_Profession_Tool_Recommender
	 */
	protected $recommender;

	/**
	 * Toolkit registry instance.
	 *
	 * @var WP_MCP_AI_Toolkit_Registry
	 */
	protected $toolkit_registry;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $tool_registry;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize registries.
		$this->tool_registry    = WP_MCP_AI_Tool_Registry::get_instance();
		$this->toolkit_registry = WP_MCP_AI_Toolkit_Registry::get_instance();

		$this->tool_registry->init();

		// Create recommender with toolkit registry.
		$this->recommender = new WP_MCP_AI_Profession_Tool_Recommender(
			$this->tool_registry,
			$this->toolkit_registry
		);
	}

	/**
	 * Test that recommender can be instantiated with toolkit registry.
	 */
	public function test_recommender_instantiation() {
		$this->assertInstanceOf(
			WP_MCP_AI_Profession_Tool_Recommender::class,
			$this->recommender
		);
	}

	/**
	 * Test getting toolkit-based recommendations.
	 */
	public function test_get_toolkit_recommendations() {
		$recommendations = $this->recommender->get_toolkit_recommendations( 'writer' );

		$this->assertIsArray( $recommendations );

		// Should have recommendations for content_publishing toolkit.
		$this->assertArrayHasKey( 'content_publishing', $recommendations );

		$content_toolkit = $recommendations['content_publishing'];
		$this->assertArrayHasKey( 'name', $content_toolkit );
		$this->assertArrayHasKey( 'tool_count', $content_toolkit );
		$this->assertArrayHasKey( 'tools', $content_toolkit );

		$this->assertGreaterThan( 0, $content_toolkit['tool_count'] );
	}

	/**
	 * Test getting tools by risk level.
	 */
	public function test_get_tools_by_risk_level() {
		$safe_tools = $this->recommender->get_tools_by_risk_level( 'writer', 'info' );

		$this->assertIsArray( $safe_tools );

		// web_search is tagged for writer and is risk level 'info'.
		$this->assertContains( 'web_search', $safe_tools );
	}

	/**
	 * Test getting safe tools (convenience method).
	 */
	public function test_get_safe_tools() {
		$safe_tools = $this->recommender->get_safe_tools( 'writer' );

		$this->assertIsArray( $safe_tools );

		// Should be same as get_tools_by_risk_level with 'info'.
		$risk_level_tools = $this->recommender->get_tools_by_risk_level( 'writer', 'info' );
		$this->assertSame( $safe_tools, $risk_level_tools );
	}

	/**
	 * Test getting destructive tools.
	 */
	public function test_get_destructive_tools() {
		// Use systems_administrator which should have destructive tools.
		$destructive_tools = $this->recommender->get_destructive_tools( 'systems_administrator' );

		$this->assertIsArray( $destructive_tools );

		// purge_varnish_cache is destructive and tagged for systems_administrator.
		$this->assertContains( 'purge_varnish_cache', $destructive_tools );
	}

	/**
	 * Test getting pattern-compatible tools.
	 */
	public function test_get_pattern_compatible_tools() {
		$orchestrator_tools = $this->recommender->get_pattern_compatible_tools( 'writer', 'orchestrator' );

		$this->assertIsArray( $orchestrator_tools );

		// create_post is tagged for writer and compatible with orchestrator pattern.
		$this->assertContains( 'create_post', $orchestrator_tools );
	}

	/**
	 * Test getting profession statistics.
	 */
	public function test_get_profession_stats() {
		$stats = $this->recommender->get_profession_stats( 'writer' );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total_tools', $stats );
		$this->assertArrayHasKey( 'toolkits_used', $stats );
		$this->assertArrayHasKey( 'patterns_used', $stats );
		$this->assertArrayHasKey( 'safe_tools', $stats );
		$this->assertArrayHasKey( 'destructive_tools', $stats );
		$this->assertArrayHasKey( 'toolkit_breakdown', $stats );
		$this->assertArrayHasKey( 'pattern_breakdown', $stats );

		// Writer should have some tools.
		$this->assertGreaterThan( 0, $stats['total_tools'] );

		// Writer should use multiple toolkits.
		$this->assertGreaterThan( 0, $stats['toolkits_used'] );

		// Writer's tools should be compatible with multiple patterns.
		$this->assertGreaterThan( 0, $stats['patterns_used'] );
	}

	/**
	 * Test enhanced get_recommended_tools with filters.
	 */
	public function test_get_recommended_tools_with_filters() {
		// Get tools with risk level filter.
		$safe_tools = $this->recommender->get_recommended_tools(
			'writer',
			'creative',
			array( 'risk_level' => 'info' )
		);

		$this->assertIsArray( $safe_tools );

		// Verify all returned tools are actually safe.
		foreach ( $safe_tools as $tool_slug ) {
			$metadata = $this->toolkit_registry->get_tool_metadata( $tool_slug );
			if ( isset( $metadata['risk_level'] ) ) {
				$this->assertSame( 'info', $metadata['risk_level'] );
			}
		}
	}

	/**
	 * Test toolkit filter in get_recommended_tools.
	 */
	public function test_get_recommended_tools_with_toolkit_filter() {
		$content_tools = $this->recommender->get_recommended_tools(
			'writer',
			'creative',
			array( 'toolkit' => 'content_publishing' )
		);

		$this->assertIsArray( $content_tools );

		// Verify all returned tools are from content_publishing toolkit.
		foreach ( $content_tools as $tool_slug ) {
			$metadata = $this->toolkit_registry->get_tool_metadata( $tool_slug );
			if ( isset( $metadata['toolkit'] ) ) {
				$this->assertSame( 'content_publishing', $metadata['toolkit'] );
			}
		}
	}

	/**
	 * Test pattern filter in get_recommended_tools.
	 */
	public function test_get_recommended_tools_with_pattern_filter() {
		$sequential_tools = $this->recommender->get_recommended_tools(
			'graphic_designer',
			'creative',
			array( 'pattern' => 'sequential' )
		);

		$this->assertIsArray( $sequential_tools );

		// Should have some sequential tools for graphic designer.
		$this->assertGreaterThan( 0, count( $sequential_tools ) );
	}

	/**
	 * Test backward compatibility (no toolkit registry).
	 */
	public function test_backward_compatibility_without_toolkit_registry() {
		$legacy_recommender = new WP_MCP_AI_Profession_Tool_Recommender( $this->tool_registry );

		$tools = $legacy_recommender->get_recommended_tools( 'writer', 'creative' );

		$this->assertIsArray( $tools );
		$this->assertGreaterThan( 0, count( $tools ) );

		// New methods should return empty/defaults without toolkit registry.
		$toolkit_recommendations = $legacy_recommender->get_toolkit_recommendations( 'writer' );
		$this->assertSame( array(), $toolkit_recommendations );

		$stats = $legacy_recommender->get_profession_stats( 'writer' );
		$this->assertSame( 0, $stats['total_tools'] );
	}
}
