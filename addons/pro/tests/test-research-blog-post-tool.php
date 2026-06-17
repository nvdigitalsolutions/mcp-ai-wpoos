<?php
/**
 * Tests for the Research Blog Post tool.
 *
 * Validates schema, availability checks, parameter sanitisation,
 * and that the new media-related parameters are optional
 * (backward-compatible with existing research_post callers).
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test Research Blog Post tool.
 */
class Test_Research_Blog_Post_Tool extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Research_Blog_Post|null
	 */
	private $tool;

	/**
	 * Set up the tool instance before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the tool class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Blog_Post' ) ) {
			$file = dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-research-blog-post.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Blog_Post' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Research_Blog_Post class not loaded.' );
		}

		$this->tool = new WP_MCP_AI_Tool_Research_Blog_Post();
	}

	// -----------------------------------------------------------------
	// Metadata tests.
	// -----------------------------------------------------------------

	/**
	 * Test tool slug.
	 */
	public function test_slug() {
		$this->assertSame( 'research_blog_post', $this->tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_name() {
		$this->assertSame( 'Research Blog Post', $this->tool->get_name() );
	}

	/**
	 * Test description is non-empty.
	 */
	public function test_description_not_empty() {
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Test capability flags include 'pro'.
	 */
	public function test_capability_flags_include_pro() {
		$flags = $this->tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'consumes-tokens', $flags );
		$this->assertContains( 'cacheable', $flags );
	}

	// -----------------------------------------------------------------
	// Schema tests.
	// -----------------------------------------------------------------

	/**
	 * Test schema has required 'topic' field.
	 */
	public function test_schema_requires_topic() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'topic', $schema['required'] );
	}

	/**
	 * Test schema includes all expected properties.
	 */
	public function test_schema_properties() {
		$schema = $this->tool->get_parameters_schema();
		$props  = array_keys( $schema['properties'] );

		$expected = array(
			'topic',
			'depth',
			'focus_areas',
			'word_count',
			'template',
			'custom_format_description',
			'template_data',
			'output_format',
			'include_seo',
			'tone',
			'media_strategy',
			'image_style',
			'chart_types',
		);

		foreach ( $expected as $key ) {
			$this->assertContains( $key, $props, "Schema must include property '$key'." );
		}
	}

	/**
	 * Test media_strategy enum values.
	 */
	public function test_media_strategy_enum() {
		$schema  = $this->tool->get_parameters_schema();
		$allowed = $schema['properties']['media_strategy']['enum'];

		$this->assertContains( 'full', $allowed );
		$this->assertContains( 'charts-only', $allowed );
		$this->assertContains( 'images-only', $allowed );
		$this->assertContains( 'minimal', $allowed );
		$this->assertContains( 'none', $allowed );
	}

	/**
	 * Test image_style enum values.
	 */
	public function test_image_style_enum() {
		$schema  = $this->tool->get_parameters_schema();
		$allowed = $schema['properties']['image_style']['enum'];

		$this->assertContains( 'photography', $allowed );
		$this->assertContains( 'illustration', $allowed );
		$this->assertContains( 'infographic', $allowed );
		$this->assertContains( 'stock', $allowed );
		$this->assertContains( 'mixed', $allowed );
	}

	/**
	 * Test chart_types items enum.
	 */
	public function test_chart_types_items_enum() {
		$schema  = $this->tool->get_parameters_schema();
		$allowed = $schema['properties']['chart_types']['items']['enum'];

		$expected = array( 'bar', 'line', 'pie', 'doughnut', 'radar', 'polarArea' );
		foreach ( $expected as $type ) {
			$this->assertContains( $type, $allowed, "chart_types items must include '$type'." );
		}
	}

	/**
	 * Test word_count range (300 – 8000).
	 */
	public function test_word_count_range() {
		$schema = $this->tool->get_parameters_schema();
		$wc     = $schema['properties']['word_count'];

		$this->assertSame( 300, $wc['minimum'] );
		$this->assertSame( 8000, $wc['maximum'] );
		$this->assertSame( 1500, $wc['default'] );
	}

	/**
	 * Test schema disallows additional properties.
	 */
	public function test_schema_no_additional_properties() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertFalse( $schema['additionalProperties'] );
	}

	// -----------------------------------------------------------------
	// Availability tests.
	// -----------------------------------------------------------------

	/**
	 * Test tool is unavailable when feature is disabled.
	 */
	public function test_unavailable_when_feature_disabled() {
		delete_option( 'wp_mcp_ai_settings' );
		$this->assertFalse( WP_MCP_AI_Tool_Research_Blog_Post::is_available() );
	}

	/**
	 * Test tool is available when feature is enabled.
	 */
	public function test_available_when_feature_enabled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_ai_cpt_management' => true ) );
		$this->assertTrue( WP_MCP_AI_Tool_Research_Blog_Post::is_available() );
	}

	/**
	 * Test unavailable reason message.
	 */
	public function test_unavailable_reason() {
		$reason = WP_MCP_AI_Tool_Research_Blog_Post::get_unavailable_reason();
		$this->assertNotEmpty( $reason );
		$this->assertStringContainsString( 'AI CPT Management', $reason );
	}

	// -----------------------------------------------------------------
	// Execute validation tests (no AI call — validate param handling)
	// -----------------------------------------------------------------

	/**
	 * Test execute returns error when topic is missing.
	 */
	public function test_execute_missing_topic() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		$result  = $this->tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_topic', $result->get_error_code() );
	}

	/**
	 * Test execute returns error when user lacks permissions.
	 */
	public function test_execute_no_permission() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$result  = $this->tool->execute(
			array( 'topic' => 'Test topic' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execute rejects invalid user (0).
	 */
	public function test_execute_zero_user() {
		$result = $this->tool->execute(
			array( 'topic' => 'Test topic' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	// -----------------------------------------------------------------
	// Cleanup.
	// -----------------------------------------------------------------

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}
}
