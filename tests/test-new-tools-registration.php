<?php
/**
 * Tests for new media and comments tools registration.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_New_Tools_Registration_Test extends WP_UnitTestCase {

	/**
	 * Test that generate_image_alt_text tool is registered.
	 */
	public function test_generate_image_alt_text_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->assertTrue(
			$registry->is_tool_registered( 'generate_image_alt_text' ),
			'generate_image_alt_text tool should be registered'
		);

		$tool = $registry->get_tool( 'generate_image_alt_text' );
		$this->assertNotNull( $tool, 'Tool should be retrievable' );
		$this->assertEquals( 'generate_image_alt_text', $tool->get_slug(), 'Tool slug should match' );
	}

	/**
	 * Test that generate_image_caption tool is registered.
	 */
	public function test_generate_image_caption_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->assertTrue(
			$registry->is_tool_registered( 'generate_image_caption' ),
			'generate_image_caption tool should be registered'
		);

		$tool = $registry->get_tool( 'generate_image_caption' );
		$this->assertNotNull( $tool, 'Tool should be retrievable' );
		$this->assertEquals( 'generate_image_caption', $tool->get_slug(), 'Tool slug should match' );
	}

	/**
	 * Test that analyze_comment_content tool is registered.
	 */
	public function test_analyze_comment_content_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->assertTrue(
			$registry->is_tool_registered( 'analyze_comment_content' ),
			'analyze_comment_content tool should be registered'
		);

		$tool = $registry->get_tool( 'analyze_comment_content' );
		$this->assertNotNull( $tool, 'Tool should be retrievable' );
		$this->assertEquals( 'analyze_comment_content', $tool->get_slug(), 'Tool slug should match' );
	}

	/**
	 * Test that all three tools are in the tool group map.
	 */
	public function test_new_tools_are_in_group_map() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'generate_image_alt_text', $group_map, 'generate_image_alt_text should be in group map' );
		$this->assertArrayHasKey( 'generate_image_caption', $group_map, 'generate_image_caption should be in group map' );
		$this->assertArrayHasKey( 'analyze_comment_content', $group_map, 'analyze_comment_content should be in group map' );

		// Verify they're in the wordpress-core group.
		$this->assertEquals( 'wordpress-core', $group_map['generate_image_alt_text'], 'Tool should be in wordpress-core group' );
		$this->assertEquals( 'wordpress-core', $group_map['generate_image_caption'], 'Tool should be in wordpress-core group' );
		$this->assertEquals( 'wordpress-core', $group_map['analyze_comment_content'], 'Tool should be in wordpress-core group' );
	}

	/**
	 * Test that tool definitions are properly structured.
	 */
	public function test_new_tools_have_valid_definitions() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools = array(
			'generate_image_alt_text',
			'generate_image_caption',
			'analyze_comment_content',
		);

		foreach ( $tools as $tool_slug ) {
			$definition = $registry->get_tool_definition( $tool_slug );

			$this->assertNotNull( $definition, "Tool $tool_slug should have a definition" );
			$this->assertArrayHasKey( 'name', $definition, 'Definition should have name' );
			$this->assertArrayHasKey( 'description', $definition, 'Definition should have description' );
			$this->assertArrayHasKey( 'parameters', $definition, 'Definition should have parameters' );

			$this->assertNotEmpty( $definition['name'], 'Tool name should not be empty' );
			$this->assertNotEmpty( $definition['description'], 'Tool description should not be empty' );
			$this->assertIsArray( $definition['parameters'], 'Parameters should be an array' );
		}
	}

	/**
	 * Test that vision tools have the requires-vision-model flag.
	 */
	public function test_vision_tools_have_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$vision_tools = array(
			'generate_image_alt_text',
			'generate_image_caption',
		);

		foreach ( $vision_tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertNotNull( $tool, "Tool $tool_slug should exist" );

			if ( method_exists( $tool, 'get_capability_flags' ) ) {
				$flags = $tool->get_capability_flags();
				$this->assertIsArray( $flags, 'Capability flags should be an array' );
				$this->assertContains( 'requires-vision-model', $flags, 'Vision tool should have requires-vision-model flag' );
			}
		}
	}

	/**
	 * Test that comment analysis tool has proper capability flags.
	 */
	public function test_comment_tool_has_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'analyze_comment_content' );
		$this->assertNotNull( $tool, 'analyze_comment_content tool should exist' );

		if ( method_exists( $tool, 'get_capability_flags' ) ) {
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags, 'Capability flags should be an array' );
			$this->assertContains( 'consumes-tokens', $flags, 'Comment tool should consume tokens' );
			$this->assertContains( 'external-api', $flags, 'Comment tool should use external API' );
		}
	}
}
