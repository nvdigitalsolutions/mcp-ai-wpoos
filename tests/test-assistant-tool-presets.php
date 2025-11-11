<?php
/**
 * Tests for assistant tool presets functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for assistant tool presets functionality.
 */
class WP_MCP_AI_Assistant_Tool_Presets_Test extends WP_UnitTestCase {

	/**
	 * Test that tool presets are properly defined.
	 */
	public function test_tool_presets_structure() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		// Verify presets is an array.
		$this->assertIsArray( $presets );
		$this->assertNotEmpty( $presets, 'At least one preset should be defined.' );

		// Verify each preset has required fields.
		foreach ( $presets as $preset_key => $preset_data ) {
			$this->assertIsArray( $preset_data, "Preset {$preset_key} should be an array." );
			$this->assertArrayHasKey( 'name', $preset_data, "Preset {$preset_key} should have a name." );
			$this->assertArrayHasKey( 'description', $preset_data, "Preset {$preset_key} should have a description." );
			$this->assertArrayHasKey( 'tools', $preset_data, "Preset {$preset_key} should have tools array." );
			$this->assertIsArray( $preset_data['tools'], "Preset {$preset_key} tools should be an array." );
			$this->assertNotEmpty( $preset_data['tools'], "Preset {$preset_key} should have at least one tool." );
		}
	}

	/**
	 * Test that preset tools reference valid tool slugs.
	 */
	public function test_preset_tools_are_valid() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get all registered tool slugs.
		$registered_tools = array();
		foreach ( $registry->get_tools() as $tool ) {
			$registered_tools[] = $tool->get_slug();
		}

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		// Check each tool in each preset.
		foreach ( $presets as $preset_key => $preset_data ) {
			foreach ( $preset_data['tools'] as $tool_slug ) {
				$this->assertContains(
					$tool_slug,
					$registered_tools,
					"Tool '{$tool_slug}' in preset '{$preset_key}' should be a registered tool."
				);
			}
		}
	}

	/**
	 * Test that the preset filter hook works.
	 */
	public function test_preset_filter_hook() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Add a custom preset via filter.
		add_filter(
			'wp_mcp_ai_tool_presets',
			function ( $presets ) {
				$presets['test_preset'] = array(
					'name'        => 'Test Preset',
					'description' => 'A test preset',
					'tools'       => array( 'search_content' ),
				);
				return $presets;
			}
		);

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		$this->assertArrayHasKey( 'test_preset', $presets, 'Custom preset should be added via filter.' );
		$this->assertEquals( 'Test Preset', $presets['test_preset']['name'] );

		// Clean up filter.
		remove_all_filters( 'wp_mcp_ai_tool_presets' );
	}

	/**
	 * Test that content_writing preset exists and contains expected tools.
	 */
	public function test_content_writing_preset() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		$this->assertArrayHasKey( 'content_writing', $presets, 'Content Writing preset should exist.' );

		$content_preset = $presets['content_writing'];
		$this->assertArrayHasKey( 'tools', $content_preset );

		// Check for some expected tools.
		$expected_tools = array( 'search_content', 'save_post', 'get_recent_posts' );
		foreach ( $expected_tools as $tool ) {
			$this->assertContains(
				$tool,
				$content_preset['tools'],
				"Content Writing preset should contain '{$tool}' tool."
			);
		}
	}
}
