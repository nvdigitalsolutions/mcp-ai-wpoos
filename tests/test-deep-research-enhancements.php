<?php
/**
 * Tests for deep_research tool enhancements:
 * - memory_agent_id parameter (prior research recall)
 * - store_to_memory parameter (persist findings)
 * - include_site_content parameter (local content + vector store)
 *
 * Validates schema, defaults, and that the new parameters are optional
 * (backward-compatible with existing callers).
 *
 * @package WP_MCP_AI
 */

/**
 * Test deep research enhancements.
 */
class WP_MCP_AI_Deep_Research_Enhancements_Test extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Deep_Research
	 */
	private $tool;

	/**
	 * Set up the tool instance before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_Tool_Deep_Research' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Deep_Research class not loaded.' );
		}
		$this->tool = new WP_MCP_AI_Tool_Deep_Research();
	}

	// -------------------------------------------------------------------------
	// Schema: include_site_content
	// -------------------------------------------------------------------------

	/**
	 * Schema includes include_site_content parameter.
	 */
	public function test_schema_includes_include_site_content() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertArrayHasKey( 'include_site_content', $schema['properties'] );
		$this->assertSame( 'boolean', $schema['properties']['include_site_content']['type'] );
	}

	/**
	 * The include_site_content parameter defaults to true.
	 */
	public function test_include_site_content_defaults_to_true() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertTrue( $schema['properties']['include_site_content']['default'] );
	}

	/**
	 * The include_site_content parameter is optional (not in required array).
	 */
	public function test_include_site_content_is_optional() {
		$schema   = $this->tool->get_parameters_schema();
		$required = isset( $schema['required'] ) ? $schema['required'] : array();
		$this->assertNotContains( 'include_site_content', $required );
	}

	// -------------------------------------------------------------------------
	// Schema: memory_agent_id
	// -------------------------------------------------------------------------

	/**
	 * Schema includes memory_agent_id parameter.
	 */
	public function test_schema_includes_memory_agent_id() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertArrayHasKey( 'memory_agent_id', $schema['properties'] );
	}

	/**
	 * The memory_agent_id parameter accepts integer or string type.
	 */
	public function test_memory_agent_id_accepts_integer_or_string() {
		$schema = $this->tool->get_parameters_schema();
		$types  = $schema['properties']['memory_agent_id']['type'];
		// May be a single string or an array of types.
		$types_array = is_array( $types ) ? $types : array( $types );
		$this->assertContains( 'integer', $types_array );
		$this->assertContains( 'string', $types_array );
	}

	/**
	 * The memory_agent_id parameter is optional (not in required array).
	 */
	public function test_memory_agent_id_is_optional() {
		$schema   = $this->tool->get_parameters_schema();
		$required = isset( $schema['required'] ) ? $schema['required'] : array();
		$this->assertNotContains( 'memory_agent_id', $required );
	}

	// -------------------------------------------------------------------------
	// Schema: store_to_memory
	// -------------------------------------------------------------------------

	/**
	 * Schema includes store_to_memory parameter.
	 */
	public function test_schema_includes_store_to_memory() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertArrayHasKey( 'store_to_memory', $schema['properties'] );
		$this->assertSame( 'boolean', $schema['properties']['store_to_memory']['type'] );
	}

	/**
	 * The store_to_memory parameter defaults to false.
	 */
	public function test_store_to_memory_defaults_to_false() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertFalse( $schema['properties']['store_to_memory']['default'] );
	}

	/**
	 * The store_to_memory parameter is optional (not in required array).
	 */
	public function test_store_to_memory_is_optional() {
		$schema   = $this->tool->get_parameters_schema();
		$required = isset( $schema['required'] ) ? $schema['required'] : array();
		$this->assertNotContains( 'store_to_memory', $required );
	}

	// -------------------------------------------------------------------------
	// Backward compatibility: original parameters still present
	// -------------------------------------------------------------------------

	/**
	 * Original parameters are still present in the schema.
	 */
	public function test_original_parameters_still_present() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'topic', $schema['properties'] );
		$this->assertArrayHasKey( 'depth', $schema['properties'] );
		$this->assertArrayHasKey( 'focus_areas', $schema['properties'] );
		$this->assertArrayHasKey( 'include_sources', $schema['properties'] );
		$this->assertArrayHasKey( 'run_mode', $schema['properties'] );
	}

	/**
	 * The topic parameter is still the only required parameter.
	 */
	public function test_topic_is_the_only_required_parameter() {
		$schema   = $this->tool->get_parameters_schema();
		$required = isset( $schema['required'] ) ? $schema['required'] : array();
		$this->assertSame( array( 'topic' ), $required );
	}

	// -------------------------------------------------------------------------
	// Method: gather_site_content (via reflection)
	// -------------------------------------------------------------------------

	/**
	 * The gather_site_content method returns an array (may be empty when tool not available).
	 */
	public function test_gather_site_content_returns_array() {
		$method = new ReflectionMethod( $this->tool, 'gather_site_content' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, 'test topic', array(), array() );
		$this->assertIsArray( $result );
	}

	// -------------------------------------------------------------------------
	// Method: recall_prior_research (via reflection)
	// -------------------------------------------------------------------------

	/**
	 * The recall_prior_research method returns an array (may be empty when tool not available).
	 */
	public function test_recall_prior_research_returns_array() {
		$method = new ReflectionMethod( $this->tool, 'recall_prior_research' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, 'test topic', 'agent-123', array() );
		$this->assertIsArray( $result );
	}

	// -------------------------------------------------------------------------
	// Method: build_analysis_prompt (via reflection) — new sections
	// -------------------------------------------------------------------------

	/**
	 * Prompt includes prior memory section when prior_memory is non-empty.
	 */
	public function test_build_analysis_prompt_includes_prior_memory_section() {
		$method = new ReflectionMethod( $this->tool, 'build_analysis_prompt' );
		$method->setAccessible( true );

		$search_results = array(
			'results' => array(
				array(
					'title'   => 'Test Result',
					'snippet' => 'Test snippet',
					'url'     => 'https://example.com',
				),
			),
		);

		$prior_memory = array(
			array(
				'title'   => 'Previous Finding',
				'content' => 'Existing insight about the topic.',
			),
		);

		$prompt = $method->invoke(
			$this->tool,
			'AI testing',
			$search_results,
			'standard',
			array(),
			true,
			array(),     // No site content.
			$prior_memory
		);

		$this->assertIsString( $prompt );
		$this->assertStringContainsString( 'Prior Research', $prompt );
		$this->assertStringContainsString( 'Previous Finding', $prompt );
		$this->assertStringContainsString( 'Existing insight', $prompt );
	}

	/**
	 * Prompt includes site content section when site_content is non-empty.
	 */
	public function test_build_analysis_prompt_includes_site_content_section() {
		$method = new ReflectionMethod( $this->tool, 'build_analysis_prompt' );
		$method->setAccessible( true );

		$search_results = array(
			'results' => array(
				array(
					'title'   => 'Test Result',
					'snippet' => 'Test snippet',
					'url'     => 'https://example.com',
				),
			),
		);

		$site_content = array(
			array(
				'title'   => 'Our Product Page',
				'excerpt' => 'Product description here.',
				'url'     => 'https://mysite.com/product',
			),
		);

		$prompt = $method->invoke(
			$this->tool,
			'AI testing',
			$search_results,
			'standard',
			array(),
			true,
			$site_content,
			array()   // No prior memory.
		);

		$this->assertIsString( $prompt );
		$this->assertStringContainsString( 'knowledge base', $prompt );
		$this->assertStringContainsString( 'Our Product Page', $prompt );
		$this->assertStringContainsString( 'Product description here.', $prompt );
	}

	/**
	 * Prompt does NOT include prior memory section when prior_memory is empty.
	 */
	public function test_build_analysis_prompt_omits_prior_memory_section_when_empty() {
		$method = new ReflectionMethod( $this->tool, 'build_analysis_prompt' );
		$method->setAccessible( true );

		$search_results = array(
			'results' => array(
				array(
					'title'   => 'Test Result',
					'snippet' => 'Test snippet',
				),
			),
		);

		$prompt = $method->invoke(
			$this->tool,
			'AI testing',
			$search_results,
			'basic',
			array(),
			true,
			array(),
			array()
		);

		$this->assertStringNotContainsString( 'Prior Research', $prompt );
	}

	// -------------------------------------------------------------------------
	// Method: build_research_report (via reflection) — site_content_count
	// -------------------------------------------------------------------------

	/**
	 * Research report includes site_content_count field.
	 */
	public function test_build_research_report_includes_site_content_count() {
		$method = new ReflectionMethod( $this->tool, 'build_research_report' );
		$method->setAccessible( true );

		$analysis = array(
			'content'  => 'Research findings here.',
			'provider' => 'openai',
			'model'    => 'gpt-4o',
		);

		$search_results = array(
			'sources' => array(
				array(
					'url'   => 'https://example.com',
					'title' => 'Test',
				),
			),
			'queries' => array( 'test query' ),
		);

		$site_content = array(
			array(
				'title'   => 'Site page 1',
				'excerpt' => 'Content 1',
			),
			array(
				'title'   => 'Site page 2',
				'excerpt' => 'Content 2',
			),
		);

		$report = $method->invoke(
			$this->tool,
			'Test topic',
			$analysis,
			$search_results,
			true,
			$site_content
		);

		$this->assertArrayHasKey( 'site_content_count', $report );
		$this->assertSame( 2, $report['site_content_count'] );
	}

	/**
	 * Research report site_content_count is 0 when no site content.
	 */
	public function test_build_research_report_site_content_count_zero_by_default() {
		$method = new ReflectionMethod( $this->tool, 'build_research_report' );
		$method->setAccessible( true );

		$analysis = array(
			'content'  => 'Research findings here.',
			'provider' => 'openai',
			'model'    => 'gpt-4o',
		);

		$search_results = array(
			'sources' => array(),
			'queries' => array( 'test query' ),
		);

		$report = $method->invoke(
			$this->tool,
			'Test topic',
			$analysis,
			$search_results,
			true
		);

		$this->assertSame( 0, $report['site_content_count'] );
	}
}
