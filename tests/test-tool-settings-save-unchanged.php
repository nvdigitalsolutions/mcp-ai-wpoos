<?php
/**
 * Test Tool Settings Manager - Save Unchanged Values
 *
 * Tests that saving unchanged values returns true (success).
 * This addresses the issue where update_option() returns false when
 * the value hasn't changed, which was causing save operations to fail.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

/**
 * Test class for Tool Settings Manager save operations.
 */
class Test_Tool_Settings_Save_Unchanged extends WP_UnitTestCase {

	/**
	 * Test that saving identical capability flags returns true.
	 */
	public function test_save_identical_capability_flags_returns_true() {
		// Load the Tool Settings Manager class.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';

		$tool_slug = 'test_tool';
		$flags     = array( 'read-only', 'cacheable' );

		// First save.
		$result1 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool_slug, $flags );
		$this->assertTrue( $result1, 'First save should return true' );

		// Second save with identical flags.
		$result2 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool_slug, $flags );
		$this->assertTrue( $result2, 'Saving identical flags should return true' );

		// Verify the flags are still set correctly.
		$saved_flags = WP_MCP_AI_Tool_Settings_Manager::get_custom_capability_flags( $tool_slug );
		$this->assertEquals( $flags, $saved_flags, 'Flags should be saved correctly' );

		// Cleanup.
		WP_MCP_AI_Tool_Settings_Manager::reset_tool_settings( $tool_slug );
	}

	/**
	 * Test that saving identical force-sync setting returns true.
	 */
	public function test_save_identical_force_sync_returns_true() {
		// Load the Tool Settings Manager class.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';

		$tool_slug = 'test_tool';

		// First save (enable force-sync).
		$result1 = WP_MCP_AI_Tool_Settings_Manager::set_force_sync( $tool_slug, true );
		$this->assertTrue( $result1, 'First save should return true' );

		// Second save with identical setting.
		$result2 = WP_MCP_AI_Tool_Settings_Manager::set_force_sync( $tool_slug, true );
		$this->assertTrue( $result2, 'Saving identical force-sync setting should return true' );

		// Verify the setting is still set correctly.
		$is_enabled = WP_MCP_AI_Tool_Settings_Manager::is_force_sync_enabled( $tool_slug );
		$this->assertTrue( $is_enabled, 'Force-sync should be enabled' );

		// Cleanup.
		WP_MCP_AI_Tool_Settings_Manager::reset_tool_settings( $tool_slug );
	}

	/**
	 * Test that saving different capability flags returns true.
	 */
	public function test_save_different_capability_flags_returns_true() {
		// Load the Tool Settings Manager class.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';

		$tool_slug = 'test_tool';
		$flags1    = array( 'read-only', 'cacheable' );
		$flags2    = array( 'write', 'state-changing' );

		// First save.
		$result1 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool_slug, $flags1 );
		$this->assertTrue( $result1, 'First save should return true' );

		// Second save with different flags.
		$result2 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool_slug, $flags2 );
		$this->assertTrue( $result2, 'Saving different flags should return true' );

		// Verify the new flags are saved correctly.
		$saved_flags = WP_MCP_AI_Tool_Settings_Manager::get_custom_capability_flags( $tool_slug );
		$this->assertEquals( $flags2, $saved_flags, 'New flags should be saved correctly' );

		// Cleanup.
		WP_MCP_AI_Tool_Settings_Manager::reset_tool_settings( $tool_slug );
	}

	/**
	 * Test that saving different force-sync setting returns true.
	 */
	public function test_save_different_force_sync_returns_true() {
		// Load the Tool Settings Manager class.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';

		$tool_slug = 'test_tool';

		// First save (enable force-sync).
		$result1 = WP_MCP_AI_Tool_Settings_Manager::set_force_sync( $tool_slug, true );
		$this->assertTrue( $result1, 'First save should return true' );

		// Second save (disable force-sync).
		$result2 = WP_MCP_AI_Tool_Settings_Manager::set_force_sync( $tool_slug, false );
		$this->assertTrue( $result2, 'Changing force-sync should return true' );

		// Verify the setting is disabled.
		$is_enabled = WP_MCP_AI_Tool_Settings_Manager::is_force_sync_enabled( $tool_slug );
		$this->assertFalse( $is_enabled, 'Force-sync should be disabled' );

		// Cleanup.
		WP_MCP_AI_Tool_Settings_Manager::reset_tool_settings( $tool_slug );
	}

	/**
	 * Test that clearing capability flags (empty array) returns true.
	 */
	public function test_clear_capability_flags_returns_true() {
		// Load the Tool Settings Manager class.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';

		$tool_slug = 'test_tool';
		$flags     = array( 'read-only', 'cacheable' );

		// First save with flags.
		$result1 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool_slug, $flags );
		$this->assertTrue( $result1, 'First save should return true' );

		// Clear flags (empty array).
		$result2 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool_slug, array() );
		$this->assertTrue( $result2, 'Clearing flags should return true' );

		// Verify the flags are cleared.
		$saved_flags = WP_MCP_AI_Tool_Settings_Manager::get_custom_capability_flags( $tool_slug );
		$this->assertEmpty( $saved_flags, 'Flags should be cleared' );

		// Cleanup.
		WP_MCP_AI_Tool_Settings_Manager::reset_tool_settings( $tool_slug );
	}

	/**
	 * Test that saving empty flags twice returns true.
	 */
	public function test_save_empty_flags_twice_returns_true() {
		// Load the Tool Settings Manager class.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';

		$tool_slug = 'test_tool';

		// First save with empty flags.
		$result1 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool_slug, array() );
		$this->assertTrue( $result1, 'First save with empty flags should return true' );

		// Second save with empty flags (no change).
		$result2 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool_slug, array() );
		$this->assertTrue( $result2, 'Saving empty flags again should return true' );

		// Cleanup.
		WP_MCP_AI_Tool_Settings_Manager::reset_tool_settings( $tool_slug );
	}

	/**
	 * Test that disabling force-sync twice returns true.
	 */
	public function test_disable_force_sync_twice_returns_true() {
		// Load the Tool Settings Manager class.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';

		$tool_slug = 'test_tool';

		// First disable.
		$result1 = WP_MCP_AI_Tool_Settings_Manager::set_force_sync( $tool_slug, false );
		$this->assertTrue( $result1, 'First disable should return true' );

		// Second disable (no change).
		$result2 = WP_MCP_AI_Tool_Settings_Manager::set_force_sync( $tool_slug, false );
		$this->assertTrue( $result2, 'Disabling again should return true' );

		// Verify force-sync is still disabled.
		$is_enabled = WP_MCP_AI_Tool_Settings_Manager::is_force_sync_enabled( $tool_slug );
		$this->assertFalse( $is_enabled, 'Force-sync should be disabled' );

		// Cleanup.
		WP_MCP_AI_Tool_Settings_Manager::reset_tool_settings( $tool_slug );
	}

	/**
	 * Test multiple tools with same flags don't interfere.
	 */
	public function test_multiple_tools_with_same_flags() {
		// Load the Tool Settings Manager class.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';

		$tool1 = 'test_tool_1';
		$tool2 = 'test_tool_2';
		$flags = array( 'read-only', 'cacheable' );

		// Save flags for tool1.
		$result1 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool1, $flags );
		$this->assertTrue( $result1, 'Save for tool1 should return true' );

		// Save same flags for tool2.
		$result2 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool2, $flags );
		$this->assertTrue( $result2, 'Save for tool2 should return true' );

		// Save again for tool1 (should still return true).
		$result3 = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool1, $flags );
		$this->assertTrue( $result3, 'Re-save for tool1 should return true' );

		// Verify both tools have correct flags.
		$saved_flags1 = WP_MCP_AI_Tool_Settings_Manager::get_custom_capability_flags( $tool1 );
		$saved_flags2 = WP_MCP_AI_Tool_Settings_Manager::get_custom_capability_flags( $tool2 );
		$this->assertEquals( $flags, $saved_flags1, 'Tool1 flags should be correct' );
		$this->assertEquals( $flags, $saved_flags2, 'Tool2 flags should be correct' );

		// Cleanup.
		WP_MCP_AI_Tool_Settings_Manager::reset_tool_settings( $tool1 );
		WP_MCP_AI_Tool_Settings_Manager::reset_tool_settings( $tool2 );
	}
}
