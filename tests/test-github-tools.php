<?php
/**
 * Tests for GitHub Tools
 *
 * @package WP_MCP_AI
 */

/**
 * Test GitHub tools functionality.
 */
class Test_Github_Tools extends WP_UnitTestCase {
	/**
	 * Test that list repositories tool exists.
	 */
	public function test_list_repositories_tool_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_List_Github_Repositories' ) );
	}

	/**
	 * Test that list repositories tool implements required interface.
	 */
	public function test_list_repositories_tool_implements_interface() {
		$tool = new WP_MCP_AI_Tool_List_Github_Repositories();
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
	}

	/**
	 * Test that list repositories tool has correct slug.
	 */
	public function test_list_repositories_tool_slug() {
		$tool = new WP_MCP_AI_Tool_List_Github_Repositories();
		$this->assertEquals( 'list_github_repositories', $tool->get_slug() );
	}

	/**
	 * Test that list repositories tool requires capability.
	 */
	public function test_list_repositories_requires_capability() {
		$tool = new WP_MCP_AI_Tool_List_Github_Repositories();
		
		// Create user without capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);
		
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_github_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that manage codespace tool exists.
	 */
	public function test_manage_codespace_tool_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Manage_Github_Codespace' ) );
	}

	/**
	 * Test that manage codespace tool implements required interface.
	 */
	public function test_manage_codespace_tool_implements_interface() {
		$tool = new WP_MCP_AI_Tool_Manage_Github_Codespace();
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
	}

	/**
	 * Test that manage codespace tool has correct slug.
	 */
	public function test_manage_codespace_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Manage_Github_Codespace();
		$this->assertEquals( 'manage_github_codespace', $tool->get_slug() );
	}

	/**
	 * Test that manage codespace tool requires action parameter.
	 */
	public function test_manage_codespace_requires_action() {
		$tool = new WP_MCP_AI_Tool_Manage_Github_Codespace();
		
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		
		$result = $tool->execute(
			array(), // No action parameter.
			array( 'user_id' => $user_id )
		);
		
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_action', $result->get_error_code() );
	}

	/**
	 * Test that repository operations tool exists.
	 */
	public function test_repository_operations_tool_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Github_Repository_Operations' ) );
	}

	/**
	 * Test that repository operations tool implements required interface.
	 */
	public function test_repository_operations_tool_implements_interface() {
		$tool = new WP_MCP_AI_Tool_Github_Repository_Operations();
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
	}

	/**
	 * Test that repository operations tool has correct slug.
	 */
	public function test_repository_operations_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Github_Repository_Operations();
		$this->assertEquals( 'github_repository_operations', $tool->get_slug() );
	}

	/**
	 * Test that repository operations only allows safe paths.
	 */
	public function test_repository_operations_safe_path_restriction() {
		$tool = new WP_MCP_AI_Tool_Github_Repository_Operations();
		
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		
		// Try to access a file outside custom-tools directory.
		$result = $tool->execute(
			array(
				'action'    => 'get_file',
				'owner'     => 'test-owner',
				'repo'      => 'test-repo',
				'file_path' => 'includes/class-wp-mcp-ai-tool-registry.php', // Core plugin file.
			),
			array( 'user_id' => $user_id )
		);
		
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_unsafe_path', $result->get_error_code() );
	}

	/**
	 * Test that repository operations allows custom-tools paths.
	 */
	public function test_repository_operations_allows_custom_tools_path() {
		$tool = new WP_MCP_AI_Tool_Github_Repository_Operations();
		
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		
		// Try to access a file in custom-tools directory (will fail due to no GitHub token, but should pass safety check).
		$result = $tool->execute(
			array(
				'action'    => 'get_file',
				'owner'     => 'test-owner',
				'repo'      => 'test-repo',
				'file_path' => 'custom-tools/class-wp-mcp-ai-tool-custom-example.php',
			),
			array( 'user_id' => $user_id )
		);
		
		// Should fail with GitHub error (no token), not safety error.
		$this->assertWPError( $result );
		$this->assertNotEquals( 'wp_mcp_ai_unsafe_path', $result->get_error_code() );
	}
}
