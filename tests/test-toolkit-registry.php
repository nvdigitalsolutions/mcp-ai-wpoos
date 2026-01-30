<?php
/**
 * Tests for Toolkit Registry functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Toolkit Registry.
 */
class WP_MCP_AI_Toolkit_Registry_Test extends WP_UnitTestCase {

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

		// Initialize tool registry.
		$this->tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->tool_registry->init();

		// Initialize toolkit registry.
		$this->toolkit_registry = WP_MCP_AI_Toolkit_Registry::get_instance();
	}

	/**
	 * Test that all 12 toolkits are defined.
	 */
	public function test_all_toolkits_defined() {
		$toolkits = $this->toolkit_registry->get_toolkits();

		$this->assertCount( 12, $toolkits, 'Should have exactly 12 toolkits defined' );

		$expected_slugs = array(
			'content_publishing',
			'media_processing',
			'data_analytics',
			'ecommerce_business',
			'developer_technical',
			'security_compliance',
			'research_discovery',
			'geospatial_location',
			'workflow_automation',
			'communication_outreach',
			'integration_external',
			'ai_model_management',
		);

		foreach ( $expected_slugs as $slug ) {
			$this->assertArrayHasKey( $slug, $toolkits, "Toolkit '{$slug}' should be defined" );
		}
	}

	/**
	 * Test getting a specific toolkit.
	 */
	public function test_get_toolkit() {
		$toolkit = $this->toolkit_registry->get_toolkit( 'content_publishing' );

		$this->assertIsArray( $toolkit );
		$this->assertArrayHasKey( 'name', $toolkit );
		$this->assertArrayHasKey( 'description', $toolkit );
		$this->assertArrayHasKey( 'primary_pattern', $toolkit );
		$this->assertArrayHasKey( 'professions', $toolkit );

		$this->assertSame( 'orchestrator', $toolkit['primary_pattern'] );
	}

	/**
	 * Test getting toolkit with invalid slug returns null.
	 */
	public function test_get_invalid_toolkit_returns_null() {
		$toolkit = $this->toolkit_registry->get_toolkit( 'invalid_toolkit' );

		$this->assertNull( $toolkit );
	}

	/**
	 * Test getting tools for a toolkit.
	 */
	public function test_get_toolkit_tools() {
		$tools = $this->toolkit_registry->get_toolkit_tools( 'content_publishing' );

		$this->assertIsArray( $tools );

		// Should contain create_post since we added metadata to it.
		$this->assertContains( 'create_post', $tools, 'create_post should be in content_publishing toolkit' );
	}

	/**
	 * Test getting tools for research_discovery toolkit.
	 */
	public function test_get_research_discovery_tools() {
		$tools = $this->toolkit_registry->get_toolkit_tools( 'research_discovery' );

		$this->assertIsArray( $tools );

		// Should contain web_search since we added metadata to it.
		$this->assertContains( 'web_search', $tools, 'web_search should be in research_discovery toolkit' );
	}

	/**
	 * Test getting tool metadata.
	 */
	public function test_get_tool_metadata() {
		$metadata = $this->toolkit_registry->get_tool_metadata( 'create_post' );

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'slug', $metadata );
		$this->assertArrayHasKey( 'name', $metadata );
		$this->assertArrayHasKey( 'toolkit', $metadata );

		$this->assertSame( 'create_post', $metadata['slug'] );
		$this->assertSame( 'content_publishing', $metadata['toolkit'] );
	}

	/**
	 * Test getting tools by profession.
	 */
	public function test_get_tools_by_profession() {
		$tools = $this->toolkit_registry->get_tools_by_profession( 'writer' );

		$this->assertIsArray( $tools );

		// create_post is tagged for writer profession.
		$this->assertContains( 'create_post', $tools );

		// web_search is NOT tagged for writer (it's researcher, journalist, etc.).
		// But it might be if we add it later.
	}

	/**
	 * Test getting tools by pattern compatibility.
	 */
	public function test_get_tools_by_pattern() {
		$tools = $this->toolkit_registry->get_tools_by_pattern( 'orchestrator' );

		$this->assertIsArray( $tools );

		// Both create_post and web_search support orchestrator pattern.
		$this->assertContains( 'create_post', $tools );
		$this->assertContains( 'web_search', $tools );
	}

	/**
	 * Test getting tools by risk level.
	 */
	public function test_get_tools_by_risk_level() {
		$info_tools = $this->toolkit_registry->get_tools_by_risk_level( 'info' );

		$this->assertIsArray( $info_tools );

		// web_search is risk_level 'info'.
		$this->assertContains( 'web_search', $info_tools );

		$standard_tools = $this->toolkit_registry->get_tools_by_risk_level( 'standard' );

		$this->assertIsArray( $standard_tools );

		// create_post is risk_level 'standard'.
		$this->assertContains( 'create_post', $standard_tools );
	}

	/**
	 * Test coverage report.
	 */
	public function test_get_coverage_report() {
		$coverage = $this->toolkit_registry->get_coverage_report();

		$this->assertIsArray( $coverage );
		$this->assertArrayHasKey( 'total_tools', $coverage );
		$this->assertArrayHasKey( 'mapped_tools', $coverage );
		$this->assertArrayHasKey( 'unmapped_tools', $coverage );
		$this->assertArrayHasKey( 'coverage_percent', $coverage );
		$this->assertArrayHasKey( 'toolkit_counts', $coverage );

		// Should have some tools registered.
		$this->assertGreaterThan( 0, $coverage['total_tools'] );

		// Coverage percent should be between 0 and 100.
		$this->assertGreaterThanOrEqual( 0, $coverage['coverage_percent'] );
		$this->assertLessThanOrEqual( 100, $coverage['coverage_percent'] );
	}

	/**
	 * Test getting toolkit stats.
	 */
	public function test_get_toolkit_stats() {
		$stats = $this->toolkit_registry->get_toolkit_stats();

		$this->assertIsArray( $stats );
		$this->assertCount( 12, $stats, 'Should have stats for all 12 toolkits' );

		foreach ( $stats as $slug => $stat ) {
			$this->assertArrayHasKey( 'name', $stat );
			$this->assertArrayHasKey( 'tool_count', $stat );
			$this->assertIsInt( $stat['tool_count'] );
		}
	}

	/**
	 * Test searching tools.
	 */
	public function test_search_tools() {
		$results = $this->toolkit_registry->search_tools( 'post' );

		$this->assertIsArray( $results );

		// Should find create_post.
		$found_create_post = false;
		foreach ( $results as $result ) {
			if ( 'create_post' === $result['slug'] ) {
				$found_create_post = true;
				break;
			}
		}

		$this->assertTrue( $found_create_post, 'Search for "post" should find create_post' );
	}

	/**
	 * Test getting unmapped tools.
	 */
	public function test_get_unmapped_tools() {
		$unmapped = $this->toolkit_registry->get_unmapped_tools();

		$this->assertIsArray( $unmapped );

		// create_post and web_search should NOT be in unmapped since we added metadata.
		$this->assertNotContains( 'create_post', $unmapped );
		$this->assertNotContains( 'web_search', $unmapped );
	}
}
