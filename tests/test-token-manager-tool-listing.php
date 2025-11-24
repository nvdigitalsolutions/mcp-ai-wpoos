<?php
/**
 * Tests for Token Manager tool listing functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test token manager's ability to list all tools.
 */
class Test_Token_Manager_Tool_Listing extends WP_UnitTestCase {

	/**
	 * Test that get_all_available_tools returns registered tools.
	 */
	public function test_get_all_available_tools_returns_registered_tools() {
		// Initialize the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get registered tools count.
		$registered_tools = $registry->get_tools();
		$registered_count = count( $registered_tools );

		// Create a mock token manager section to test the private method.
		$token_manager = new WP_MCP_AI_Section_Token_Manager();

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $token_manager );
		$method     = $reflection->getMethod( 'get_all_available_tools' );
		$method->setAccessible( true );

		// Call the method.
		$tools = $method->invoke( $token_manager );

		// Verify tools is an array.
		$this->assertIsArray( $tools );

		// Verify we got more than the hardcoded 2 tools.
		$this->assertGreaterThan( 2, count( $tools ), 'Should return more than 2 hardcoded tools' );

		// Verify we got all registered tools.
		$this->assertEquals(
			$registered_count,
			count( $tools ),
			'Should return all registered tools from the registry'
		);

		// Verify each tool has a slug and name.
		foreach ( $tools as $slug => $name ) {
			$this->assertNotEmpty( $slug, 'Tool slug should not be empty' );
			$this->assertNotEmpty( $name, 'Tool name should not be empty' );
			$this->assertIsString( $slug, 'Tool slug should be a string' );
			$this->assertIsString( $name, 'Tool name should be a string' );
		}

		// Verify tools are sorted alphabetically by name.
		$tool_names   = array_values( $tools );
		$sorted_names = $tool_names;
		asort( $sorted_names );
		$this->assertEquals(
			array_values( $sorted_names ),
			$tool_names,
			'Tools should be sorted alphabetically by name'
		);
	}

	/**
	 * Test that specific known tools are present in the list.
	 */
	public function test_known_tools_are_present() {
		// Initialize the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create a mock token manager section.
		$token_manager = new WP_MCP_AI_Section_Token_Manager();

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $token_manager );
		$method     = $reflection->getMethod( 'get_all_available_tools' );
		$method->setAccessible( true );

		// Call the method.
		$tools = $method->invoke( $token_manager );

		// Verify some known tools are present.
		$known_tools = array(
			'count_tokens',
			'search_content',
			'get_user_info',
			'get_site_summary',
			'run_crawl4ai_job',
			'check_video_status',
			'list_github_repositories',
		);

		foreach ( $known_tools as $tool_slug ) {
			$this->assertArrayHasKey(
				$tool_slug,
				$tools,
				sprintf( 'Known tool "%s" should be present in the list', $tool_slug )
			);
		}
	}

	/**
	 * Test that the filter hook works.
	 */
	public function test_tools_filter_hook_works() {
		// Add a filter to modify tools.
		add_filter(
			'wp_mcp_ai_token_manager_tools',
			function ( $tools ) {
				$tools['custom_test_tool'] = 'Custom Test Tool';
				return $tools;
			}
		);

		// Create a mock token manager section.
		$token_manager = new WP_MCP_AI_Section_Token_Manager();

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $token_manager );
		$method     = $reflection->getMethod( 'get_all_available_tools' );
		$method->setAccessible( true );

		// Call the method.
		$tools = $method->invoke( $token_manager );

		// Verify custom tool was added.
		$this->assertArrayHasKey( 'custom_test_tool', $tools );
		$this->assertEquals( 'Custom Test Tool', $tools['custom_test_tool'] );

		// Clean up.
		remove_all_filters( 'wp_mcp_ai_token_manager_tools' );
	}
}
