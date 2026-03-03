<?php
/**
 * Tests for research_page and research_post tool template format support.
 *
 * Validates that:
 * 1. Both tools declare all four template options (block-editor, classic-editor, elementor, custom)
 * 2. The custom_format_description parameter is present in both tools
 * 3. Parameter validation falls back to block-editor for unknown templates
 * 4. Custom template with description is handled correctly
 *
 * @package WP_MCP_AI
 */

/**
 * Test research tool template support.
 */
class WP_MCP_AI_Research_Tool_Template_Support_Test extends WP_UnitTestCase {

	/**
	 * Valid template enum values expected in both tools.
	 *
	 * @var array
	 */
	private $expected_templates = array( 'block-editor', 'classic-editor', 'elementor', 'custom' );

	/**
	 * Test that research_page tool schema includes all four template options.
	 */
	public function test_research_page_schema_includes_custom_template() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'template', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['template'] );
		$this->assertSame( $this->expected_templates, $schema['properties']['template']['enum'] );
	}

	/**
	 * Test that research_post tool schema includes all four template options.
	 */
	public function test_research_post_schema_includes_custom_template() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Post' ) ) {
			$this->markTestSkipped( 'Research Post tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Post();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'template', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['template'] );
		$this->assertSame( $this->expected_templates, $schema['properties']['template']['enum'] );
	}

	/**
	 * Test that research_page tool schema includes custom_format_description.
	 */
	public function test_research_page_schema_includes_custom_format_description() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'custom_format_description', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['custom_format_description']['type'] );
	}

	/**
	 * Test that research_post tool schema includes custom_format_description.
	 */
	public function test_research_post_schema_includes_custom_format_description() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Post' ) ) {
			$this->markTestSkipped( 'Research Post tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Post();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'custom_format_description', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['custom_format_description']['type'] );
	}

	/**
	 * Test that research_page tool default template is block-editor.
	 */
	public function test_research_page_default_template_is_block_editor() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'block-editor', $schema['properties']['template']['default'] );
	}

	/**
	 * Test that research_post tool default template is block-editor.
	 */
	public function test_research_post_default_template_is_block_editor() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Post' ) ) {
			$this->markTestSkipped( 'Research Post tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Post();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'block-editor', $schema['properties']['template']['default'] );
	}

	/**
	 * Test that research_page tool description mentions all supported templates.
	 */
	public function test_research_page_description_mentions_custom_template() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool        = new WP_MCP_AI_Tool_Research_Page();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'Classic Editor', $description );
		$this->assertStringContainsString( 'Block Editor', $description );
		$this->assertStringContainsString( 'Elementor', $description );
		$this->assertStringContainsString( 'Custom', $description );
		$this->assertStringContainsString( 'Telegram Mini App', $description );
	}

	/**
	 * Test that research_post tool description mentions all supported templates.
	 */
	public function test_research_post_description_mentions_custom_template() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Post' ) ) {
			$this->markTestSkipped( 'Research Post tool class not loaded (Pro only).' );
		}

		$tool        = new WP_MCP_AI_Tool_Research_Post();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'Classic Editor', $description );
		$this->assertStringContainsString( 'Block Editor', $description );
		$this->assertStringContainsString( 'Elementor', $description );
		$this->assertStringContainsString( 'Custom', $description );
		$this->assertStringContainsString( 'Telegram Mini App', $description );
	}

	/**
	 * Test that custom_format_description is not a required field.
	 */
	public function test_custom_format_description_is_optional() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$required = isset( $schema['required'] ) ? $schema['required'] : array();
		$this->assertNotContains( 'custom_format_description', $required );
	}

	/**
	 * Test that research_page tool schema template description mentions custom format usage.
	 */
	public function test_research_page_template_description_guides_custom_usage() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$template_desc = $schema['properties']['template']['description'];
		$this->assertStringContainsString( 'custom', $template_desc );
	}

	/**
	 * Test that research_page tool schema includes template_data parameter.
	 */
	public function test_research_page_schema_includes_template_data() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'template_data', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['template_data']['type'] );
	}

	/**
	 * Test that research_post tool schema includes template_data parameter.
	 */
	public function test_research_post_schema_includes_template_data() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Post' ) ) {
			$this->markTestSkipped( 'Research Post tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Post();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'template_data', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['template_data']['type'] );
	}

	/**
	 * Test that template_data is not a required field.
	 */
	public function test_template_data_is_optional() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$required = isset( $schema['required'] ) ? $schema['required'] : array();
		$this->assertNotContains( 'template_data', $required );
	}

	/**
	 * Test that template_data description mentions JSON and Elementor.
	 */
	public function test_template_data_description_mentions_supported_formats() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$desc = $schema['properties']['template_data']['description'];
		$this->assertStringContainsString( 'Elementor', $desc );
		$this->assertStringContainsString( 'JSON', $desc );
		$this->assertStringContainsString( 'Block Editor', $desc );
	}

	/**
	 * Test that research_page tool schema includes output_format parameter.
	 */
	public function test_research_page_schema_includes_output_format() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'output_format', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['output_format']['type'] );
		$this->assertSame( array( 'json', 'pdf', 'docx' ), $schema['properties']['output_format']['enum'] );
		$this->assertSame( 'json', $schema['properties']['output_format']['default'] );
	}

	/**
	 * Test that research_post tool schema includes output_format parameter.
	 */
	public function test_research_post_schema_includes_output_format() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Post' ) ) {
			$this->markTestSkipped( 'Research Post tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Post();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'output_format', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['output_format']['type'] );
		$this->assertSame( array( 'json', 'pdf', 'docx' ), $schema['properties']['output_format']['enum'] );
		$this->assertSame( 'json', $schema['properties']['output_format']['default'] );
	}

	/**
	 * Test that output_format is not a required field.
	 */
	public function test_output_format_is_optional() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$required = isset( $schema['required'] ) ? $schema['required'] : array();
		$this->assertNotContains( 'output_format', $required );
	}

	/**
	 * Test Elementor template auto-detection.
	 */
	public function test_analyze_template_data_detects_elementor() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool = new WP_MCP_AI_Tool_Research_Page();

		// Use reflection to access protected method.
		$method = new ReflectionMethod( $tool, 'analyze_template_data' );
		$method->setAccessible( true );

		$elementor_data = array(
			array(
				'elType'   => 'section',
				'elements' => array(
					array(
						'elType'   => 'column',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'heading',
							),
							array(
								'elType'     => 'widget',
								'widgetType' => 'text-editor',
							),
						),
					),
				),
			),
		);

		$result = $method->invoke( $tool, $elementor_data );

		$this->assertSame( 'elementor', $result['detected_type'] );
		$this->assertContains( 'heading', $result['widget_types'] );
		$this->assertContains( 'text-editor', $result['widget_types'] );
		$this->assertNotEmpty( $result['summary'] );
		$this->assertStringContainsString( 'Elementor', $result['summary'] );
	}

	/**
	 * Test Block Editor template auto-detection.
	 */
	public function test_analyze_template_data_detects_block_editor() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool = new WP_MCP_AI_Tool_Research_Page();

		$method = new ReflectionMethod( $tool, 'analyze_template_data' );
		$method->setAccessible( true );

		$block_data = array(
			array(
				'blockName'   => 'core/heading',
				'attrs'       => array( 'level' => 2 ),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array(),
				'innerBlocks' => array(),
			),
		);

		$result = $method->invoke( $tool, $block_data );

		$this->assertSame( 'block-editor', $result['detected_type'] );
		$this->assertContains( 'core/heading', $result['widget_types'] );
		$this->assertContains( 'core/paragraph', $result['widget_types'] );
		$this->assertNotEmpty( $result['summary'] );
		$this->assertStringContainsString( 'Block Editor', $result['summary'] );
	}

	/**
	 * Test generic JSON template detection fallback.
	 */
	public function test_analyze_template_data_detects_generic() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool = new WP_MCP_AI_Tool_Research_Page();

		$method = new ReflectionMethod( $tool, 'analyze_template_data' );
		$method->setAccessible( true );

		$generic_data = array(
			'header' => array( 'title' => 'My Page' ),
			'body'   => array( 'content' => 'Hello' ),
			'footer' => array( 'copyright' => '2026' ),
		);

		$result = $method->invoke( $tool, $generic_data );

		$this->assertSame( 'generic', $result['detected_type'] );
		$this->assertNotEmpty( $result['summary'] );
		$this->assertStringContainsString( 'header', $result['summary'] );
	}

	/**
	 * Test template_data description mentions auto-detection.
	 */
	public function test_template_data_description_mentions_auto_detection() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool   = new WP_MCP_AI_Tool_Research_Page();
		$schema = $tool->get_parameters_schema();

		$desc = $schema['properties']['template_data']['description'];
		$this->assertStringContainsString( 'auto-detect', $desc );
	}

	/**
	 * Test research_page tool description mentions PDF and Word export.
	 */
	public function test_research_page_description_mentions_export_formats() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Page' ) ) {
			$this->markTestSkipped( 'Research Page tool class not loaded (Pro only).' );
		}

		$tool        = new WP_MCP_AI_Tool_Research_Page();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'PDF', $description );
		$this->assertStringContainsString( 'Word', $description );
	}

	/**
	 * Test research_post tool description mentions PDF and Word export.
	 */
	public function test_research_post_description_mentions_export_formats() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Research_Post' ) ) {
			$this->markTestSkipped( 'Research Post tool class not loaded (Pro only).' );
		}

		$tool        = new WP_MCP_AI_Tool_Research_Post();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'PDF', $description );
		$this->assertStringContainsString( 'Word', $description );
	}
}
