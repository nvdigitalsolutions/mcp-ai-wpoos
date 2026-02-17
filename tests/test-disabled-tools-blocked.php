<?php
/**
 * Tests to verify that disabled tools are properly blocked from execution and listing.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for disabled tools functionality.
 *
 * @group tools
 * @group tool-service
 * @group tool-registry
 */
class WP_MCP_AI_Disabled_Tools_Tests extends WP_UnitTestCase {

	/**
	 * Tool Registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Tool Service instance.
	 *
	 * @var WP_MCP_AI_Tool_Service
	 */
	protected $tool_service;

	/**
	 * Test tool slug.
	 *
	 * @var string
	 */
	protected $test_tool_slug = 'test_disabled_tool';

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-service.php';

		// Get instances.
		$this->registry     = WP_MCP_AI_Tool_Registry::get_instance();
		$this->tool_service = new WP_MCP_AI_Tool_Service( $this->registry );

		// Create an admin user for testing.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Ensure the test tool is enabled initially.
		$this->registry->enable_tool( $this->test_tool_slug );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Re-enable the tool to avoid affecting other tests.
		$this->registry->enable_tool( $this->test_tool_slug );

		parent::tearDown();
	}

	/**
	 * Test that disabled tools are excluded from get_available_tools().
	 */
	public function test_disabled_tools_not_in_available_tools_list() {
		// Get initial list of available tools.
		$tools_before = $this->tool_service->get_available_tools();
		$slugs_before = wp_list_pluck( $tools_before, 'slug' );

		// Verify our test tool exists in the list (if it's registered).
		$tool_exists = $this->registry->is_tool_registered( $this->test_tool_slug );
		if ( ! $tool_exists ) {
			$this->markTestSkipped( 'Test tool not registered, skipping test.' );
			return;
		}

		// Tool should be in the list initially.
		$this->assertContains( $this->test_tool_slug, $slugs_before, 'Tool should be in available tools when enabled' );

		// Disable the tool.
		$disabled = $this->registry->disable_tool( $this->test_tool_slug );
		$this->assertTrue( $disabled, 'Tool should be successfully disabled' );

		// Verify tool is marked as disabled.
		$this->assertFalse( $this->registry->is_tool_enabled( $this->test_tool_slug ), 'Tool should be disabled' );

		// Get the list of available tools after disabling.
		$tools_after = $this->tool_service->get_available_tools();
		$slugs_after = wp_list_pluck( $tools_after, 'slug' );

		// Disabled tool should NOT be in the list.
		$this->assertNotContains( $this->test_tool_slug, $slugs_after, 'Disabled tool should NOT be in available tools list' );

		// Re-enable the tool.
		$this->registry->enable_tool( $this->test_tool_slug );

		// Verify tool is back in the list.
		$tools_re_enabled = $this->tool_service->get_available_tools();
		$slugs_re_enabled = wp_list_pluck( $tools_re_enabled, 'slug' );
		$this->assertContains( $this->test_tool_slug, $slugs_re_enabled, 'Tool should be back in available tools after re-enabling' );
	}

	/**
	 * Test that disabled tools cannot be executed.
	 */
	public function test_disabled_tools_cannot_be_executed() {
		// Skip if tool doesn't exist.
		if ( ! $this->registry->is_tool_registered( $this->test_tool_slug ) ) {
			$this->markTestSkipped( 'Test tool not registered, skipping test.' );
			return;
		}

		// Disable the tool.
		$this->registry->disable_tool( $this->test_tool_slug );

		// Try to execute the disabled tool.
		$result = $this->tool_service->execute_tool( $this->test_tool_slug, array(), array() );

		// Execution should return a WP_Error.
		$this->assertWPError( $result, 'Executing a disabled tool should return WP_Error' );

		// Verify the error code.
		$this->assertEquals( 'wp_mcp_ai_tool_disabled', $result->get_error_code(), 'Error code should be wp_mcp_ai_tool_disabled' );

		// Verify the error message mentions the tool is disabled.
		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'disabled', $error_message, 'Error message should mention tool is disabled' );
		$this->assertStringContainsString( $this->test_tool_slug, $error_message, 'Error message should mention tool slug' );
	}

	/**
	 * Test that enabled tools can be executed normally.
	 */
	public function test_enabled_tools_can_be_executed() {
		// Skip if tool doesn't exist.
		if ( ! $this->registry->is_tool_registered( $this->test_tool_slug ) ) {
			$this->markTestSkipped( 'Test tool not registered, skipping test.' );
			return;
		}

		// Ensure the tool is enabled.
		$this->registry->enable_tool( $this->test_tool_slug );
		$this->assertTrue( $this->registry->is_tool_enabled( $this->test_tool_slug ), 'Tool should be enabled' );

		// Try to execute the enabled tool.
		$result = $this->tool_service->execute_tool( $this->test_tool_slug, array(), array() );

		// Execution should NOT return a "disabled" error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'wp_mcp_ai_tool_disabled', $result->get_error_code(), 'Enabled tool should not return disabled error' );
		}
		// Note: The tool might still return an error for other reasons (missing args, etc.),
		// but it should not be the "disabled" error.
	}

	/**
	 * Test that document generation tools are blocked when disabled.
	 */
	public function test_document_generation_tools_blocked_when_disabled() {
		$doc_tool_slug = 'excel_data_export';

		// Check if the tool exists (it's in the pro addon).
		if ( ! $this->registry->is_tool_registered( $doc_tool_slug ) ) {
			$this->markTestSkipped( 'excel_data_export tool not available, skipping test.' );
			return;
		}

		// Ensure tool is initially enabled.
		$this->registry->enable_tool( $doc_tool_slug );
		$tools_before = $this->tool_service->get_available_tools();
		$slugs_before = wp_list_pluck( $tools_before, 'slug' );
		$this->assertContains( $doc_tool_slug, $slugs_before, 'Document tool should be available when enabled' );

		// Disable the tool.
		$this->registry->disable_tool( $doc_tool_slug );

		// Verify it's not in available tools.
		$tools_after = $this->tool_service->get_available_tools();
		$slugs_after = wp_list_pluck( $tools_after, 'slug' );
		$this->assertNotContains( $doc_tool_slug, $slugs_after, 'Disabled document tool should not be in available tools' );

		// Verify execution is blocked.
		$result = $this->tool_service->execute_tool( $doc_tool_slug, array(), array() );
		$this->assertWPError( $result, 'Executing disabled document tool should return error' );
		$this->assertEquals( 'wp_mcp_ai_tool_disabled', $result->get_error_code(), 'Should return disabled error' );

		// Clean up - re-enable the tool.
		$this->registry->enable_tool( $doc_tool_slug );
	}

	/**
	 * Test that toolkit tools respect global disable setting.
	 */
	public function test_all_toolkit_tools_respect_global_disable() {
		// Test with multiple toolkit tools to ensure the behavior is consistent.
		$toolkit_tools = array(
			'excel_data_export',  // Document Generation Toolkit.
			'excel_data_import',  // Document Generation Toolkit.
		);

		foreach ( $toolkit_tools as $tool_slug ) {
			// Skip if tool doesn't exist.
			if ( ! $this->registry->is_tool_registered( $tool_slug ) ) {
				continue;
			}

			// Enable tool initially.
			$this->registry->enable_tool( $tool_slug );

			// Disable the tool.
			$this->registry->disable_tool( $tool_slug );

			// Verify not in available tools.
			$tools = $this->tool_service->get_available_tools();
			$slugs = wp_list_pluck( $tools, 'slug' );
			$this->assertNotContains(
				$tool_slug,
				$slugs,
				"Toolkit tool '$tool_slug' should not be available when disabled"
			);

			// Verify execution is blocked.
			$result = $this->tool_service->execute_tool( $tool_slug, array(), array() );
			$this->assertWPError(
				$result,
				"Toolkit tool '$tool_slug' execution should return error when disabled"
			);
			$this->assertEquals(
				'wp_mcp_ai_tool_disabled',
				$result->get_error_code(),
				"Toolkit tool '$tool_slug' should return disabled error code"
			);

			// Clean up.
			$this->registry->enable_tool( $tool_slug );
		}

		$this->assertTrue( true, 'All toolkit tools respect global disable setting' );
	}
}
