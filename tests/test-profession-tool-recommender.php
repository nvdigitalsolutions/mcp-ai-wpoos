<?php
/**
 * Test Profession Tool Recommender.
 *
 * Tests for the profession tool recommender service.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Profession_Tool_Recommender.
 */
class Test_Profession_Tool_Recommender extends WP_UnitTestCase {
	/**
	 * Tool recommender instance.
	 *
	 * @var WP_MCP_AI_Profession_Tool_Recommender
	 */
	protected $recommender;

	/**
	 * Test setup.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load tool recommender class.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-profession-tool-recommender.php';

		// Initialize tool registry.
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool_registry->init();

		$this->recommender = new WP_MCP_AI_Profession_Tool_Recommender( $tool_registry );
	}

	/**
	 * Test teardown.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that tool recommender class exists.
	 */
	public function test_tool_recommender_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Profession_Tool_Recommender' ) );
	}

	/**
	 * Test get_recommended_tools returns core tools for all professions.
	 */
	public function test_get_recommended_tools_includes_core_tools() {
		$tools = $this->recommender->get_recommended_tools( 'generic_profession', 'other' );

		$this->assertNotEmpty( $tools, 'Should return recommended tools' );
		$this->assertContains( 'web_search', $tools, 'Should include web_search as core tool' );
		$this->assertContains( 'search_content', $tools, 'Should include search_content as core tool' );
	}

	/**
	 * Test get_recommended_tools returns category-specific tools.
	 */
	public function test_get_recommended_tools_includes_category_tools() {
		$tools = $this->recommender->get_recommended_tools( 'generic_technical', 'technical' );

		$this->assertContains( 'check_site_security', $tools, 'Should include technical category tool' );
		$this->assertContains( 'get_system_logs', $tools, 'Should include technical category tool' );
	}

	/**
	 * Test get_recommended_tools returns profession-specific tools.
	 */
	public function test_get_recommended_tools_includes_profession_tools() {
		$tools = $this->recommender->get_recommended_tools( 'web_developer', 'technical' );

		$this->assertContains( 'get_rankmath_seo', $tools, 'Should include web developer specific tool' );
		$this->assertContains( 'purge_cache', $tools, 'Should include web developer specific tool' );
	}

	/**
	 * Test creative professions get media generation tools.
	 */
	public function test_creative_professions_get_media_tools() {
		$tools = $this->recommender->get_recommended_tools( 'graphic_designer', 'creative' );

		$this->assertContains( 'generate_openai_image', $tools, 'Should include image generation tool' );
		$this->assertContains( 'resize_image', $tools, 'Should include image manipulation tool' );
	}

	/**
	 * Test financial professions get appropriate tools.
	 */
	public function test_financial_professions_get_relevant_tools() {
		$tools = $this->recommender->get_recommended_tools( 'accountant', 'financial' );

		$this->assertContains( 'create_chart', $tools, 'Should include chart creation for financial analysis' );
		$this->assertContains( 'create_cron_job', $tools, 'Should include cron for recurring reports' );
	}

	/**
	 * Test get_tool_usage_guidance returns formatted guidance.
	 */
	public function test_get_tool_usage_guidance_returns_formatted_text() {
		$tools    = array( 'web_search', 'search_content', 'save_post' );
		$guidance = $this->recommender->get_tool_usage_guidance( 'content_writer', $tools );

		$this->assertNotEmpty( $guidance, 'Should return guidance text' );
		$this->assertStringContainsString( 'web_search', $guidance, 'Should mention tool name' );
		$this->assertStringContainsString( '###', $guidance, 'Should include section headers' );
	}

	/**
	 * Test get_tool_usage_guidance provides profession-specific context.
	 */
	public function test_get_tool_usage_guidance_provides_context() {
		$tools    = array( 'web_search' );
		$guidance = $this->recommender->get_tool_usage_guidance( 'journalist', $tools );

		$this->assertStringContainsString( 'fact-checking', $guidance, 'Should include journalist-specific context' );
	}

	/**
	 * Test get_tool_reference_section generates complete reference.
	 */
	public function test_get_tool_reference_section_generates_complete_section() {
		$section = $this->recommender->get_tool_reference_section( 'web_developer', 'technical' );

		$this->assertNotEmpty( $section, 'Should return reference section' );
		$this->assertStringContainsString( 'Recommended Tools', $section, 'Should include section title' );
		$this->assertStringContainsString( 'Best Practices', $section, 'Should include best practices' );
		$this->assertStringContainsString( 'Verify permissions', $section, 'Should include permission reminder' );
	}

	/**
	 * Test tool recommendations are deduplicated.
	 */
	public function test_tool_recommendations_are_deduplicated() {
		$tools = $this->recommender->get_recommended_tools( 'software_engineer', 'technical' );

		$unique_tools = array_unique( $tools );
		$this->assertCount(
			count( $unique_tools ),
			$tools,
			'Should not contain duplicate tool recommendations'
		);
	}

	/**
	 * Test emergency manager gets disaster-related tools.
	 */
	public function test_emergency_manager_gets_disaster_tools() {
		$tools = $this->recommender->get_recommended_tools( 'emergency_manager', 'other' );

		$this->assertContains( 'get_gdacs_events', $tools, 'Should include GDACS disaster alerts' );
		$this->assertContains( 'get_nhc_active_storms', $tools, 'Should include NHC storm tracking' );
		$this->assertContains( 'reliefweb_reports', $tools, 'Should include ReliefWeb reports' );
	}

	/**
	 * Test ecommerce professions get WooCommerce tools.
	 */
	public function test_ecommerce_professions_get_woocommerce_tools() {
		$tools = $this->recommender->get_recommended_tools( 'ecommerce_manager', 'other' );

		$this->assertContains( 'get_woo_products', $tools, 'Should include WooCommerce product tool' );
		$this->assertContains( 'get_woo_recent_orders', $tools, 'Should include WooCommerce orders tool' );
	}

	/**
	 * Test tool grouping organizes tools by category.
	 */
	public function test_tool_grouping_organizes_by_category() {
		$reflection = new ReflectionClass( $this->recommender );
		$method     = $reflection->getMethod( 'group_tools_by_category' );
		$method->setAccessible( true );

		$tools  = array( 'web_search', 'generate_openai_image', 'create_chart', 'create_cron_job' );
		$groups = $method->invoke( $this->recommender, $tools );

		$this->assertArrayHasKey( 'Core Tools', $groups, 'Should have Core Tools group' );
		$this->assertArrayHasKey( 'Media Generation', $groups, 'Should have Media Generation group' );
		$this->assertArrayHasKey( 'Data & Analytics', $groups, 'Should have Data & Analytics group' );
		$this->assertArrayHasKey( 'Automation & Scheduling', $groups, 'Should have Automation group' );
	}

	/**
	 * Test that unavailable tools are filtered when registry is provided.
	 */
	public function test_unavailable_tools_are_filtered() {
		// Create mock registry that only has web_search available.
		$mock_registry = $this->createMock( WP_MCP_AI_Tool_Registry::class );
		$mock_registry->method( 'get_tool' )
			->willReturnCallback(
				function( $slug ) {
					return 'web_search' === $slug ? new stdClass() : null;
				}
			);

		$recommender = new WP_MCP_AI_Profession_Tool_Recommender( $mock_registry );

		// Reflection to test filter_available_tools method.
		$reflection = new ReflectionClass( $recommender );
		$method     = $reflection->getMethod( 'filter_available_tools' );
		$method->setAccessible( true );

		$input    = array( 'web_search', 'nonexistent_tool', 'another_missing_tool' );
		$filtered = $method->invoke( $recommender, $input );

		$this->assertCount( 1, $filtered, 'Should only include available tools' );
		$this->assertContains( 'web_search', $filtered, 'Should include web_search' );
		$this->assertNotContains( 'nonexistent_tool', $filtered, 'Should not include unavailable tools' );
	}

	/**
	 * Test that profession-specific guidance is provided when available.
	 */
	public function test_profession_specific_guidance_is_used() {
		$reflection = new ReflectionClass( $this->recommender );
		$method     = $reflection->getMethod( 'get_single_tool_guidance' );
		$method->setAccessible( true );

		$journalist_guidance = $method->invoke( $this->recommender, 'web_search', 'journalist' );
		$generic_guidance    = $method->invoke( $this->recommender, 'web_search', 'generic_profession' );

		$this->assertNotEquals( $journalist_guidance, $generic_guidance, 'Should provide different guidance for different professions' );
		$this->assertStringContainsString( 'journalist', $journalist_guidance, 'Should mention profession in guidance' );
	}
}
