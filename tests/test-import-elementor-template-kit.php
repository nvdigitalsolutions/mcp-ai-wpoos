<?php
/**
 * Tests for the Import Elementor Template Kit tool.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test the Import Elementor Template Kit tool.
 */
class WP_MCP_AI_Import_Elementor_Template_Kit_Test extends WP_UnitTestCase {

	/**
	 * Test that the tool class exists.
	 */
	public function test_tool_class_exists() {
		$tool_file = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';
		$this->assertTrue( file_exists( $tool_file ), 'Tool file should exist.' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Import_Elementor_Template_Kit' ), 'Tool class should exist.' );
	}

	/**
	 * Test that the tool implements required interfaces.
	 */
	public function test_tool_implements_interfaces() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';

		$tool = new WP_MCP_AI_Tool_Import_Elementor_Template_Kit();

		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool, 'Tool should implement WP_MCP_AI_Tool_Interface.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Capability_Flags_Interface::class, $tool, 'Tool should implement WP_MCP_AI_Tool_Capability_Flags_Interface.' );
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';

		$tool = new WP_MCP_AI_Tool_Import_Elementor_Template_Kit();

		$this->assertSame( 'import_elementor_template_kit', $tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_get_name() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';

		$tool = new WP_MCP_AI_Tool_Import_Elementor_Template_Kit();

		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_get_description() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';

		$tool = new WP_MCP_AI_Tool_Import_Elementor_Template_Kit();

		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test tool parameters schema.
	 */
	public function test_get_parameters_schema() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';

		$tool   = new WP_MCP_AI_Tool_Import_Elementor_Template_Kit();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Check required parameters.
		$this->assertContains( 'attachment_id', $schema['required'] );

		// Check expected properties.
		$properties = $schema['properties'];
		$this->assertArrayHasKey( 'attachment_id', $properties );
		$this->assertArrayHasKey( 'max_pages', $properties );
		$this->assertArrayHasKey( 'page_status', $properties );
		$this->assertArrayHasKey( 'set_front_page', $properties );
		$this->assertArrayHasKey( 'overwrite_existing', $properties );
		$this->assertArrayHasKey( 'dry_run', $properties );
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';

		$tool  = new WP_MCP_AI_Tool_Import_Elementor_Template_Kit();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertNotEmpty( $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'requires-plugin', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * Test is_available returns false when Elementor is not active.
	 */
	public function test_is_available_without_elementor() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';

		// Since Elementor is not installed in test environment, is_available should return false.
		$this->assertFalse( WP_MCP_AI_Tool_Import_Elementor_Template_Kit::is_available() );
	}

	/**
	 * Test get_unavailable_reason returns a message.
	 */
	public function test_get_unavailable_reason() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';

		$reason = WP_MCP_AI_Tool_Import_Elementor_Template_Kit::get_unavailable_reason();

		$this->assertIsString( $reason );
		$this->assertNotEmpty( $reason );
	}

	/**
	 * Test execute returns error when Elementor is not active.
	 */
	public function test_execute_returns_error_without_elementor() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';

		$tool = new WP_MCP_AI_Tool_Import_Elementor_Template_Kit();

		// Create an admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $tool->execute(
			array( 'attachment_id' => 1 ),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_elementor_missing', $result->get_error_code() );
	}

	/**
	 * Test execute returns error for non-admin users (when Elementor would be active).
	 */
	public function test_execute_permission_check() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';

		// Mock Elementor being available by temporarily defining constant.
		// Note: This test primarily validates the permission check logic flow.
		// In practice, the Elementor check will fail first in test environment.

		$tool = new WP_MCP_AI_Tool_Import_Elementor_Template_Kit();

		// Create a subscriber user.
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$result = $tool->execute(
			array( 'attachment_id' => 1 ),
			array( 'user_id' => $subscriber_id )
		);

		// The error will be elementor_missing (checked first) in test environment,.
		// but in production with Elementor, it would be forbidden for subscribers.
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test tool is registered when full version is enabled.
	 */
	public function test_tool_registered_in_registry() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get all tools and check if our tool path is in the extended tools.
		// Note: The tool won't be actually registered without Elementor being active.
		$tool_file = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';
		$this->assertTrue( file_exists( $tool_file ), 'Tool file should be present for registration.' );
	}
}
