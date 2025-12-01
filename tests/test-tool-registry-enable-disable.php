<?php
/**
 * Tests for Tool Registry Enable/Disable Functionality
 *
 * @package WP_MCP_AI
 */

/**
 * Test Tool Registry enable/disable functionality.
 */
class Test_Tool_Registry_Enable_Disable extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Clean up disabled tools option before each test.
		delete_option( 'wp_mcp_ai_disabled_tools' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up after test.
		delete_option( 'wp_mcp_ai_disabled_tools' );
		parent::tearDown();
	}

	/**
	 * Test that all tools are enabled by default.
	 */
	public function test_all_tools_enabled_by_default() {
		$disabled = $this->registry->get_disabled_tools();

		$this->assertIsArray( $disabled );
		$this->assertEmpty( $disabled, 'No tools should be disabled by default' );
	}

	/**
	 * Test that a tool can be disabled.
	 */
	public function test_disable_tool() {
		$tool_slug = 'search_content';

		// Tool should be enabled initially.
		$this->assertTrue( $this->registry->is_tool_enabled( $tool_slug ) );

		// Disable the tool.
		$result = $this->registry->disable_tool( $tool_slug );

		$this->assertTrue( $result, 'disable_tool should return true on success' );
		$this->assertFalse( $this->registry->is_tool_enabled( $tool_slug ), 'Tool should be disabled' );

		// Check that it's in the disabled list.
		$disabled = $this->registry->get_disabled_tools();
		$this->assertContains( $tool_slug, $disabled );
	}

	/**
	 * Test that a tool can be enabled.
	 */
	public function test_enable_tool() {
		$tool_slug = 'search_content';

		// Disable the tool first.
		$this->registry->disable_tool( $tool_slug );
		$this->assertFalse( $this->registry->is_tool_enabled( $tool_slug ) );

		// Enable the tool.
		$result = $this->registry->enable_tool( $tool_slug );

		$this->assertTrue( $result, 'enable_tool should return true on success' );
		$this->assertTrue( $this->registry->is_tool_enabled( $tool_slug ), 'Tool should be enabled' );

		// Check that it's not in the disabled list.
		$disabled = $this->registry->get_disabled_tools();
		$this->assertNotContains( $tool_slug, $disabled );
	}

	/**
	 * Test that enabling an already enabled tool returns true.
	 */
	public function test_enable_already_enabled_tool() {
		$tool_slug = 'search_content';

		// Tool is enabled by default.
		$this->assertTrue( $this->registry->is_tool_enabled( $tool_slug ) );

		// Try to enable it again.
		$result = $this->registry->enable_tool( $tool_slug );

		$this->assertTrue( $result, 'Enabling already enabled tool should return true' );
		$this->assertTrue( $this->registry->is_tool_enabled( $tool_slug ) );
	}

	/**
	 * Test that disabling an already disabled tool returns true.
	 */
	public function test_disable_already_disabled_tool() {
		$tool_slug = 'search_content';

		// Disable the tool.
		$this->registry->disable_tool( $tool_slug );
		$this->assertFalse( $this->registry->is_tool_enabled( $tool_slug ) );

		// Try to disable it again.
		$result = $this->registry->disable_tool( $tool_slug );

		$this->assertTrue( $result, 'Disabling already disabled tool should return true' );
		$this->assertFalse( $this->registry->is_tool_enabled( $tool_slug ) );

		// Should only appear once in the disabled list.
		$disabled = $this->registry->get_disabled_tools();
		$count    = count( array_keys( $disabled, $tool_slug, true ) );
		$this->assertEquals( 1, $count, 'Tool should appear exactly once in disabled list' );
	}

	/**
	 * Test that multiple tools can be disabled.
	 */
	public function test_disable_multiple_tools() {
		$tools = array( 'search_content', 'get_recent_posts', 'save_post' );

		foreach ( $tools as $tool_slug ) {
			$this->registry->disable_tool( $tool_slug );
			$this->assertFalse( $this->registry->is_tool_enabled( $tool_slug ) );
		}

		$disabled = $this->registry->get_disabled_tools();
		$this->assertCount( 3, $disabled );

		foreach ( $tools as $tool_slug ) {
			$this->assertContains( $tool_slug, $disabled );
		}
	}

	/**
	 * Test that tool slugs are sanitized.
	 */
	public function test_tool_slugs_are_sanitized() {
		$unsanitized_slug = 'Search Content!@#';
		$sanitized_slug   = 'search_content';

		// Disable with unsanitized slug.
		$this->registry->disable_tool( $unsanitized_slug );

		// Check if the sanitized version was disabled.
		$disabled = $this->registry->get_disabled_tools();
		$this->assertContains( $sanitized_slug, $disabled );
		$this->assertNotContains( $unsanitized_slug, $disabled );
	}

	/**
	 * Test that disabled tools persist across instances.
	 */
	public function test_disabled_tools_persist() {
		$tool_slug = 'search_content';

		// Disable the tool.
		$this->registry->disable_tool( $tool_slug );

		// Get a fresh instance (simulate page reload).
		$new_registry = WP_MCP_AI_Tool_Registry::get_instance();

		$this->assertFalse( $new_registry->is_tool_enabled( $tool_slug ), 'Disabled state should persist' );
	}

	/**
	 * Test that get_disabled_tools returns array even if option doesn't exist.
	 */
	public function test_get_disabled_tools_returns_array() {
		delete_option( 'wp_mcp_ai_disabled_tools' );

		$disabled = $this->registry->get_disabled_tools();

		$this->assertIsArray( $disabled );
		$this->assertEmpty( $disabled );
	}

	/**
	 * Test that get_disabled_tools handles corrupted data.
	 */
	public function test_get_disabled_tools_handles_corrupted_data() {
		// Set corrupted data (not an array).
		update_option( 'wp_mcp_ai_disabled_tools', 'corrupted_data' );

		$disabled = $this->registry->get_disabled_tools();

		$this->assertIsArray( $disabled, 'Should return array even with corrupted data' );
		$this->assertEmpty( $disabled );
	}
}
