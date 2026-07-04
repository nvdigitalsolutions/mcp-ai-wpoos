<?php
/**
 * FlowHub Sync Engine Tests.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

/**
 * Test class for WP_MCP_AI_FlowHub_Sync_Engine.
 */
class Test_FlowHub_Sync_Engine extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Sync_Engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-sync-engine.php';
		}
		update_option( 'wp_mcp_ai_flowhub_toolkit_settings', array(
			'sync_interval'  => 15,
			'sync_direction' => 'flowhub_to_woo',
			'enable_wc_sync' => false,
		) );
	}

	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		parent::tearDown();
	}

	// ------------------------------------------------------------------ //
	// Constants
	// ------------------------------------------------------------------ //

	public function test_hook_constants() {
		$this->assertEquals( 'wp_mcp_ai_flowhub_full_sync', WP_MCP_AI_FlowHub_Sync_Engine::HOOK_FULL_SYNC );
		$this->assertEquals( 'wp_mcp_ai_flowhub_wc_sync', WP_MCP_AI_FlowHub_Sync_Engine::HOOK_WC_SYNC );
		$this->assertEquals( 'flowhub', WP_MCP_AI_FlowHub_Sync_Engine::GROUP );
		$this->assertEquals( 'flowhub_wc', WP_MCP_AI_FlowHub_Sync_Engine::GROUP_WC );
	}

	// ------------------------------------------------------------------ //
	// Error Handling
	// ------------------------------------------------------------------ //

	public function test_handle_sync_error_stores_option() {
		$error = new WP_Error( 'test_error', 'Test error message' );
		WP_MCP_AI_FlowHub_Sync_Engine::handle_sync_error( $error );
		$stored = get_option( 'wp_mcp_ai_flowhub_last_sync_error', '' );
		$this->assertEquals( 'Test error message', $stored );
		delete_option( 'wp_mcp_ai_flowhub_last_sync_error' );
	}

	public function test_handle_sync_error_accepts_string() {
		WP_MCP_AI_FlowHub_Sync_Engine::handle_sync_error( 'String error' );
		$stored = get_option( 'wp_mcp_ai_flowhub_last_sync_error', '' );
		$this->assertEquals( 'String error', $stored );
		delete_option( 'wp_mcp_ai_flowhub_last_sync_error' );
	}

	// ------------------------------------------------------------------ //
	// WC Sync Direction
	// ------------------------------------------------------------------ //

	public function test_wc_sync_disabled_by_default() {
		$result = WP_MCP_AI_FlowHub_Sync_Engine::run_wc_sync();
		$this->assertEquals( 0, $result ); // Returns 0 when enable_wc_sync is false.
	}
}
