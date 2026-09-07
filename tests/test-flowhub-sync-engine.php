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

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Sync_Engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-sync-engine.php';
		}
		update_option(
			'wp_mcp_ai_flowhub_toolkit_settings',
			array(
				'sync_interval'  => 15,
				'sync_direction' => 'flowhub_to_woo',
				'enable_wc_sync' => false,
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		parent::tear_down();
	}

	/**
	 * Hook constants match the Action Scheduler contract.
	 */
	public function test_hook_constants() {
		$this->assertEquals( 'wp_mcp_ai_flowhub_full_sync', WP_MCP_AI_FlowHub_Sync_Engine::HOOK_FULL_SYNC );
		$this->assertEquals( 'wp_mcp_ai_flowhub_wc_sync', WP_MCP_AI_FlowHub_Sync_Engine::HOOK_WC_SYNC );
		$this->assertEquals( 'flowhub', WP_MCP_AI_FlowHub_Sync_Engine::GROUP );
		$this->assertEquals( 'flowhub_wc', WP_MCP_AI_FlowHub_Sync_Engine::GROUP_WC );
	}

	/**
	 * The full-sync callback must be registered with two accepted args.
	 *
	 * Action Scheduler fires the hook with do_action_ref_array( $hook,
	 * array_values( $args ) ). With the default accepted_args of 1, WordPress
	 * truncates the queued args to $dry_run and the connection ID never
	 * reaches the callback, so every scheduled sync fails.
	 */
	public function test_init_registers_full_sync_callback_with_two_accepted_args() {
		WP_MCP_AI_FlowHub_Sync_Engine::init();

		global $wp_filter;

		$hook = WP_MCP_AI_FlowHub_Sync_Engine::HOOK_FULL_SYNC;
		$this->assertTrue( isset( $wp_filter[ $hook ] ), 'Full-sync hook is not registered.' );

		$found = false;
		foreach ( $wp_filter[ $hook ]->callbacks as $priority_group ) {
			foreach ( $priority_group as $callback ) {
				if ( array( 'WP_MCP_AI_FlowHub_Sync_Engine', 'run_full_sync' ) === $callback['function'] ) {
					$found = true;
					$this->assertEquals( 2, $callback['accepted_args'] );
				}
			}
		}

		$this->assertTrue( $found, 'run_full_sync callback is not registered on the full-sync hook.' );
	}

	/**
	 * The connection ID queued by Action Scheduler reaches the sync callback.
	 */
	public function test_full_sync_hook_delivers_connection_id_positionally() {
		WP_MCP_AI_FlowHub_Sync_Engine::init();

		if ( class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) ) {
			WP_MCP_AI_Sync_Log_Manager::clear_logs( 'flowhub' );
		}

		// Simulate Action Scheduler firing the queued action with positional args.
		do_action_ref_array(
			WP_MCP_AI_FlowHub_Sync_Engine::HOOK_FULL_SYNC,
			array( false, 'conn_test_123' )
		);

		if ( ! class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) ) {
			$this->markTestSkipped( 'Sync Log Manager is not available.' );
		}

		$latest = WP_MCP_AI_Sync_Log_Manager::get_latest_run( 'flowhub' );
		$this->assertNotNull( $latest, 'Expected a sync run to be logged.' );
		$this->assertEquals( 'conn_test_123', $latest['connection_id'] );

		WP_MCP_AI_Sync_Log_Manager::clear_logs( 'flowhub' );
	}

	/**
	 * Handle_sync_error stores the error message in the shared option.
	 */
	public function test_handle_sync_error_stores_option() {
		$error = new WP_Error( 'test_error', 'Test error message' );
		WP_MCP_AI_FlowHub_Sync_Engine::handle_sync_error( $error );
		$stored = get_option( 'wp_mcp_ai_flowhub_last_sync_error', '' );
		$this->assertEquals( 'Test error message', $stored );
		delete_option( 'wp_mcp_ai_flowhub_last_sync_error' );
	}

	/**
	 * Handle_sync_error accepts a plain string error.
	 */
	public function test_handle_sync_error_accepts_string() {
		WP_MCP_AI_FlowHub_Sync_Engine::handle_sync_error( 'String error' );
		$stored = get_option( 'wp_mcp_ai_flowhub_last_sync_error', '' );
		$this->assertEquals( 'String error', $stored );
		delete_option( 'wp_mcp_ai_flowhub_last_sync_error' );
	}

	/**
	 * WC sync does nothing when enable_wc_sync is off.
	 */
	public function test_wc_sync_disabled_by_default() {
		$result = WP_MCP_AI_FlowHub_Sync_Engine::run_wc_sync();
		$this->assertEquals( 0, $result ); // Returns 0 when enable_wc_sync is false.
	}
}
