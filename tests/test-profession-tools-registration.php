<?php
/**
 * Tests for profession tools registration in tool group map.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for profession tools registration.
 */
class WP_MCP_AI_Profession_Tools_Registration_Test extends WP_UnitTestCase {

	/**
	 * Test that all profession tools are registered.
	 */
	public function test_profession_tools_are_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$profession_tools = array(
			'list_professions',
			'get_profession',
			'save_profession',
			'get_profession_stats',
		);

		foreach ( $profession_tools as $tool_slug ) {
			$this->assertTrue(
				$registry->is_tool_registered( $tool_slug ),
				"{$tool_slug} tool should be registered"
			);

			$tool = $registry->get_tool( $tool_slug );
			$this->assertNotNull( $tool, "Tool {$tool_slug} should be retrievable" );
			$this->assertEquals( $tool_slug, $tool->get_slug(), 'Tool slug should match' );
		}
	}

	/**
	 * Test that profession tools are in the tool group map.
	 */
	public function test_profession_tools_are_in_group_map() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		$profession_tools = array(
			'list_professions',
			'get_profession',
			'save_profession',
			'get_profession_stats',
		);

		foreach ( $profession_tools as $tool_slug ) {
			$this->assertArrayHasKey(
				$tool_slug,
				$group_map,
				"{$tool_slug} should be in tool group map"
			);

			// Verify they're in the wordpress-core group.
			$this->assertEquals(
				'wordpress-core',
				$group_map[ $tool_slug ],
				"{$tool_slug} should be in wordpress-core group"
			);
		}
	}

	/**
	 * Test that profession tool definitions are properly structured.
	 */
	public function test_profession_tools_have_valid_definitions() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$profession_tools = array(
			'list_professions',
			'get_profession',
			'save_profession',
			'get_profession_stats',
		);

		foreach ( $profession_tools as $tool_slug ) {
			$definition = $registry->get_tool_definition( $tool_slug );

			$this->assertNotNull( $definition, "Tool {$tool_slug} should have a definition" );
			$this->assertArrayHasKey( 'name', $definition, "{$tool_slug} definition should have name" );
			$this->assertArrayHasKey( 'description', $definition, "{$tool_slug} definition should have description" );
			$this->assertArrayHasKey( 'parameters', $definition, "{$tool_slug} definition should have parameters" );

			$this->assertNotEmpty( $definition['name'], "{$tool_slug} name should not be empty" );
			$this->assertNotEmpty( $definition['description'], "{$tool_slug} description should not be empty" );
			$this->assertIsArray( $definition['parameters'], "{$tool_slug} parameters should be an array" );
		}
	}
}
