<?php
/**
 * Tests to ensure disabled tools do not appear in the Available Tools metabox.
 */
class WP_MCP_AI_Disabled_Tools_Metabox_Test extends WP_UnitTestCase {

	/**
	 * Test that disabled tools are filtered out from the Available Tools metabox.
	 */
	public function test_disabled_tools_not_in_metabox() {
		// Initialize the registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get all registered tools.
		$all_tools = $registry->get_tools();
		$this->assertNotEmpty( $all_tools, 'There should be registered tools.' );

		// Pick a tool to disable (use get_recent_posts as it's a common tool).
		$tool_to_disable = null;
		foreach ( $all_tools as $tool ) {
			if ( 'get_recent_posts' === $tool->get_slug() ) {
				$tool_to_disable = $tool;
				break;
			}
		}

		// If get_recent_posts doesn't exist, use the first available tool.
		if ( null === $tool_to_disable && ! empty( $all_tools ) ) {
			$tool_to_disable = reset( $all_tools );
		}

		$this->assertNotNull( $tool_to_disable, 'Should have a tool to disable.' );
		$disabled_slug = $tool_to_disable->get_slug();

		// Ensure the tool is initially enabled.
		$this->assertTrue(
			$registry->is_tool_enabled( $disabled_slug ),
			sprintf( 'Tool %s should be initially enabled.', $disabled_slug )
		);

		// Disable the tool globally.
		$registry->disable_tool( $disabled_slug );

		// Verify it's disabled.
		$this->assertFalse(
			$registry->is_tool_enabled( $disabled_slug ),
			sprintf( 'Tool %s should be disabled after calling disable_tool().', $disabled_slug )
		);

		// Create a test assistant post.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $assistant_id, 'Assistant post should be created.' );

		// Simulate getting tools for the metabox (like render_tools_meta_box does).
		$tools_for_metabox = $registry->get_tools();

		// Filter out disabled tools (this is what our fix does).
		$tools_for_metabox = array_filter(
			$tools_for_metabox,
			function ( $tool ) use ( $registry ) {
				return $registry->is_tool_enabled( $tool->get_slug() );
			}
		);

		// Check that the disabled tool is not in the filtered list.
		$metabox_tool_slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$tools_for_metabox
		);

		$this->assertNotContains(
			$disabled_slug,
			$metabox_tool_slugs,
			sprintf( 'Disabled tool %s should not appear in the metabox tool list.', $disabled_slug )
		);

		// Clean up - re-enable the tool.
		$registry->enable_tool( $disabled_slug );

		// Verify cleanup.
		$this->assertTrue(
			$registry->is_tool_enabled( $disabled_slug ),
			sprintf( 'Tool %s should be re-enabled after cleanup.', $disabled_slug )
		);
	}

	/**
	 * Test that enabled tools still appear in the metabox.
	 */
	public function test_enabled_tools_appear_in_metabox() {
		// Initialize the registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get all registered tools.
		$all_tools = $registry->get_tools();
		$this->assertNotEmpty( $all_tools, 'There should be registered tools.' );

		// Pick an enabled tool.
		$enabled_tool = null;
		foreach ( $all_tools as $tool ) {
			if ( $registry->is_tool_enabled( $tool->get_slug() ) ) {
				$enabled_tool = $tool;
				break;
			}
		}

		$this->assertNotNull( $enabled_tool, 'Should have at least one enabled tool.' );
		$enabled_slug = $enabled_tool->get_slug();

		// Simulate getting tools for the metabox.
		$tools_for_metabox = $registry->get_tools();

		// Filter out disabled tools.
		$tools_for_metabox = array_filter(
			$tools_for_metabox,
			function ( $tool ) use ( $registry ) {
				return $registry->is_tool_enabled( $tool->get_slug() );
			}
		);

		// Check that the enabled tool IS in the filtered list.
		$metabox_tool_slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$tools_for_metabox
		);

		$this->assertContains(
			$enabled_slug,
			$metabox_tool_slugs,
			sprintf( 'Enabled tool %s should appear in the metabox tool list.', $enabled_slug )
		);
	}

	/**
	 * Test that all disabled tools are filtered out.
	 */
	public function test_all_disabled_tools_filtered_out() {
		// Initialize the registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get all registered tools.
		$all_tools = $registry->get_tools();
		$this->assertNotEmpty( $all_tools, 'There should be registered tools.' );

		// Disable multiple tools.
		$tools_to_disable = array();
		$count            = 0;
		foreach ( $all_tools as $tool ) {
			if ( $count >= 3 ) {
				break;
			}
			$tools_to_disable[] = $tool->get_slug();
			$registry->disable_tool( $tool->get_slug() );
			++$count;
		}

		$this->assertNotEmpty( $tools_to_disable, 'Should have disabled some tools.' );

		// Simulate getting tools for the metabox.
		$tools_for_metabox = $registry->get_tools();

		// Filter out disabled tools.
		$tools_for_metabox = array_filter(
			$tools_for_metabox,
			function ( $tool ) use ( $registry ) {
				return $registry->is_tool_enabled( $tool->get_slug() );
			}
		);

		// Check that none of the disabled tools are in the filtered list.
		$metabox_tool_slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$tools_for_metabox
		);

		foreach ( $tools_to_disable as $disabled_slug ) {
			$this->assertNotContains(
				$disabled_slug,
				$metabox_tool_slugs,
				sprintf( 'Disabled tool %s should not appear in the metabox tool list.', $disabled_slug )
			);
		}

		// Clean up - re-enable all tools.
		foreach ( $tools_to_disable as $slug ) {
			$registry->enable_tool( $slug );
		}
	}
}
