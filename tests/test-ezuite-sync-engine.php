<?php
/**
 * EZuite Sync Engine Tests.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.9.0
 */

/**
 * Test class for WP_MCP_AI_EZuite_Sync_Engine.
 */
class Test_EZuite_Sync_Engine extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'WP_MCP_AI_EZuite_Sync_Engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-sync-engine.php';
		}
		if ( class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) ) {
			WP_MCP_AI_Sync_Log_Manager::clear_logs( 'ezuite' );
		}
		update_option(
			'wp_mcp_ai_ezuite_toolkit_settings',
			array(
				'sync_interval'  => 15,
				'sync_direction' => 'read_only',
				'enable_wc_sync' => false,
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		delete_option( 'wp_mcp_ai_ezuite_toolkit_settings' );
		delete_option( 'wp_mcp_ai_ezuite_last_sync_error' );
		delete_option( 'wp_mcp_ai_ezuite_last_sync_error_conn_test_123' );
		if ( class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) ) {
			WP_MCP_AI_Sync_Log_Manager::clear_logs( 'ezuite' );
		}

		parent::tear_down();
	}

	/**
	 * Hook constants match the Action Scheduler contract.
	 */
	public function test_hook_constants() {
		$this->assertEquals( 'wp_mcp_ai_ezuite_full_sync', WP_MCP_AI_EZuite_Sync_Engine::HOOK_FULL_SYNC );
		$this->assertEquals( 'wp_mcp_ai_ezuite_wc_sync', WP_MCP_AI_EZuite_Sync_Engine::HOOK_WC_SYNC );
		$this->assertEquals( 'ezuite', WP_MCP_AI_EZuite_Sync_Engine::GROUP );
		$this->assertEquals( 'ezuite_wc', WP_MCP_AI_EZuite_Sync_Engine::GROUP_WC );
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
		WP_MCP_AI_EZuite_Sync_Engine::init();

		$found = false;
		foreach ( $this->get_callbacks( WP_MCP_AI_EZuite_Sync_Engine::HOOK_FULL_SYNC ) as $callback ) {
			if ( array( 'WP_MCP_AI_EZuite_Sync_Engine', 'run_full_sync' ) === $callback['function'] ) {
				$found = true;
				$this->assertEquals( 2, $callback['accepted_args'] );
			}
		}

		$this->assertTrue( $found, 'run_full_sync callback is not registered on the full-sync hook.' );
	}

	/**
	 * The connection ID queued by Action Scheduler reaches the sync callback.
	 */
	public function test_full_sync_hook_delivers_connection_id_positionally() {
		WP_MCP_AI_EZuite_Sync_Engine::init();

		// Simulate Action Scheduler firing the queued action with positional args.
		do_action_ref_array(
			WP_MCP_AI_EZuite_Sync_Engine::HOOK_FULL_SYNC,
			array( false, 'conn_test_123' )
		);

		if ( ! class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) ) {
			$this->markTestSkipped( 'Sync Log Manager is not available.' );
		}

		$latest = WP_MCP_AI_Sync_Log_Manager::get_latest_run( 'ezuite' );
		$this->assertNotNull( $latest, 'Expected a sync run to be logged.' );
		$this->assertEquals( 'conn_test_123', $latest['connection_id'] );
	}

	/**
	 * Handle_sync_error stores the error message in the shared option.
	 */
	public function test_handle_sync_error_stores_option() {
		$error = new WP_Error( 'test_error', 'Test error message' );
		WP_MCP_AI_EZuite_Sync_Engine::handle_sync_error( $error );
		$stored = get_option( 'wp_mcp_ai_ezuite_last_sync_error', '' );
		$this->assertEquals( 'Test error message', $stored );
		delete_option( 'wp_mcp_ai_ezuite_last_sync_error' );
	}

	/**
	 * Handle_sync_error uses a per-connection option key when given a connection ID.
	 */
	public function test_handle_sync_error_stores_per_connection_option() {
		WP_MCP_AI_EZuite_Sync_Engine::handle_sync_error( 'Connection error', 'conn_test_123' );
		$this->assertEquals( 'Connection error', get_option( 'wp_mcp_ai_ezuite_last_sync_error_conn_test_123', '' ) );
		delete_option( 'wp_mcp_ai_ezuite_last_sync_error_conn_test_123' );
	}

	/**
	 * WC sync does nothing when enable_wc_sync is off.
	 */
	public function test_wc_sync_skipped_when_disabled() {
		$result = WP_MCP_AI_EZuite_Sync_Engine::run_wc_sync();
		$this->assertNull( $result );
	}

	/**
	 * Helper: get raw callback registrations for a hook.
	 *
	 * @param string $hook Hook name.
	 * @return array<int, array>
	 */
	private function get_callbacks( $hook ) {
		global $wp_filter;

		$callbacks = array();
		if ( isset( $wp_filter[ $hook ] ) ) {
			foreach ( $wp_filter[ $hook ]->callbacks as $priority_group ) {
				foreach ( $priority_group as $callback ) {
					$callbacks[] = $callback;
				}
			}
		}

		return $callbacks;
	}
}
