<?php
/**
 * Tests for newly registered tools (check_video_status and GitHub tools).
 *
 * @package WP_MCP_AI
 */

/**
 * Test that newly registered tools are properly accessible.
 */
class Test_Newly_Registered_Tools extends WP_UnitTestCase {

	/**
	 * Test that check_video_status tool is registered.
	 */
	public function test_check_video_status_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->assertTrue(
			$registry->is_tool_registered( 'check_video_status' ),
			'check_video_status tool should be registered'
		);

		$tool = $registry->get_tool( 'check_video_status' );
		$this->assertNotNull( $tool, 'Tool should be retrievable' );
		$this->assertEquals( 'check_video_status', $tool->get_slug(), 'Tool slug should match' );
	}

	/**
	 * Test that list_github_repositories tool is registered.
	 */
	public function test_list_github_repositories_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->assertTrue(
			$registry->is_tool_registered( 'list_github_repositories' ),
			'list_github_repositories tool should be registered'
		);

		$tool = $registry->get_tool( 'list_github_repositories' );
		$this->assertNotNull( $tool, 'Tool should be retrievable' );
		$this->assertEquals( 'list_github_repositories', $tool->get_slug(), 'Tool slug should match' );
	}

	/**
	 * Test that github_repository_operations tool is registered.
	 */
	public function test_github_repository_operations_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->assertTrue(
			$registry->is_tool_registered( 'github_repository_operations' ),
			'github_repository_operations tool should be registered'
		);

		$tool = $registry->get_tool( 'github_repository_operations' );
		$this->assertNotNull( $tool, 'Tool should be retrievable' );
		$this->assertEquals( 'github_repository_operations', $tool->get_slug(), 'Tool slug should match' );
	}

	/**
	 * Test that manage_github_codespace tool is registered.
	 */
	public function test_manage_github_codespace_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->assertTrue(
			$registry->is_tool_registered( 'manage_github_codespace' ),
			'manage_github_codespace tool should be registered'
		);

		$tool = $registry->get_tool( 'manage_github_codespace' );
		$this->assertNotNull( $tool, 'Tool should be retrievable' );
		$this->assertEquals( 'manage_github_codespace', $tool->get_slug(), 'Tool slug should match' );
	}

	/**
	 * Test that all newly registered tools are in the tool group map.
	 */
	public function test_newly_registered_tools_are_in_group_map() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'check_video_status', $group_map, 'check_video_status should be in group map' );
		$this->assertArrayHasKey( 'list_github_repositories', $group_map, 'list_github_repositories should be in group map' );
		$this->assertArrayHasKey( 'github_repository_operations', $group_map, 'github_repository_operations should be in group map' );
		$this->assertArrayHasKey( 'manage_github_codespace', $group_map, 'manage_github_codespace should be in group map' );

		// Verify they're all in the external-tools group.
		$this->assertEquals( 'external-tools', $group_map['check_video_status'], 'Tool should be in external-tools group' );
		$this->assertEquals( 'external-tools', $group_map['list_github_repositories'], 'Tool should be in external-tools group' );
		$this->assertEquals( 'external-tools', $group_map['github_repository_operations'], 'Tool should be in external-tools group' );
		$this->assertEquals( 'external-tools', $group_map['manage_github_codespace'], 'Tool should be in external-tools group' );
	}

	/**
	 * Test that newly registered tools have valid definitions.
	 */
	public function test_newly_registered_tools_have_valid_definitions() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools = array(
			'check_video_status',
			'list_github_repositories',
			'github_repository_operations',
			'manage_github_codespace',
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
	 * Test that newly registered tools are accessible to Token Manager.
	 */
	public function test_newly_registered_tools_accessible_to_token_manager() {
		// Initialize the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get tools from Token Usage Service (this is what Token Manager uses).
		$available_tools = WP_MCP_AI_Token_Usage_Service::get_all_available_tools();

		// Verify newly registered tools are in the list.
		$this->assertArrayHasKey( 'check_video_status', $available_tools, 'check_video_status should be available to Token Manager' );
		$this->assertArrayHasKey( 'list_github_repositories', $available_tools, 'list_github_repositories should be available to Token Manager' );
		$this->assertArrayHasKey( 'github_repository_operations', $available_tools, 'github_repository_operations should be available to Token Manager' );
		$this->assertArrayHasKey( 'manage_github_codespace', $available_tools, 'manage_github_codespace should be available to Token Manager' );

		// Verify each has a proper name (not empty).
		$this->assertNotEmpty( $available_tools['check_video_status'], 'Tool should have a name' );
		$this->assertNotEmpty( $available_tools['list_github_repositories'], 'Tool should have a name' );
		$this->assertNotEmpty( $available_tools['github_repository_operations'], 'Tool should have a name' );
		$this->assertNotEmpty( $available_tools['manage_github_codespace'], 'Tool should have a name' );
	}

	/**
	 * Test that newly registered tools are accessible to assistants.
	 */
	public function test_newly_registered_tools_accessible_to_assistants() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get all tools (this is what assistant CPT uses for tool selection).
		$all_tools = $registry->get_tools();

		// Extract slugs from tool objects.
		$tool_slugs = array();
		foreach ( $all_tools as $tool ) {
			if ( is_object( $tool ) && method_exists( $tool, 'get_slug' ) ) {
				$tool_slugs[] = $tool->get_slug();
			}
		}

		// Verify newly registered tools are in the list.
		$this->assertContains( 'check_video_status', $tool_slugs, 'check_video_status should be accessible to assistants' );
		$this->assertContains( 'list_github_repositories', $tool_slugs, 'list_github_repositories should be accessible to assistants' );
		$this->assertContains( 'github_repository_operations', $tool_slugs, 'github_repository_operations should be accessible to assistants' );
		$this->assertContains( 'manage_github_codespace', $tool_slugs, 'manage_github_codespace should be accessible to assistants' );
	}

	/**
	 * Test that GitHub tools have proper capability flags for external API.
	 */
	public function test_github_tools_have_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$github_tools = array(
			'list_github_repositories',
			'github_repository_operations',
			'manage_github_codespace',
		);

		foreach ( $github_tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertNotNull( $tool, "Tool $tool_slug should exist" );

			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$flags = $tool->get_capability_flags();
				$this->assertIsArray( $flags, 'Capability flags should be an array' );
				$this->assertContains( 'external-api', $flags, 'GitHub tool should have external-api flag' );
				$this->assertContains( 'requires-credentials', $flags, 'GitHub tool should require credentials' );
			}
		}
	}

	/**
	 * Test that all filesystem tools are registered (comprehensive check).
	 */
	public function test_all_filesystem_tools_are_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get all tool class files.
		$tool_files = glob( WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-*.php' );
		$this->assertNotEmpty( $tool_files, 'Should find tool files in filesystem' );

		$missing_tools = array();

		foreach ( $tool_files as $file ) {
			$filename = basename( $file );

			// Skip interface/base/trait files.
			if ( strpos( $filename, 'interface' ) !== false ||
				strpos( $filename, 'trait' ) !== false ||
				strpos( $filename, 'base' ) !== false ) {
				continue;
			}

			// Extract class name from file.
			require_once $file;
			$class_name = str_replace( '.php', '', $filename );
			$class_name = str_replace( 'class-', '', $class_name );
			$class_name = str_replace( '-', '_', $class_name );
			$class_name = implode( '_', array_map( 'ucfirst', explode( '_', $class_name ) ) );

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			// Create instance to get slug.
			try {
				$instance = new $class_name();
				if ( method_exists( $instance, 'get_slug' ) ) {
					$slug = $instance->get_slug();
					if ( ! $registry->is_tool_registered( $slug ) ) {
						$missing_tools[] = $slug;
					}
				}
			} catch ( Exception $e ) {
				// Some tools may not be instantiable in test environment.
				continue;
			}
		}

		$this->assertEmpty(
			$missing_tools,
			'All tools in filesystem should be registered. Missing: ' . implode( ', ', $missing_tools )
		);
	}
}
