<?php
/**
 * Tests for the log buffer maintenance AJAX handler.
 *
 * Backs the "Compact Log Buffers" and "Delete All Log Entries" buttons on
 * Settings -> Advanced -> Data Management.
 *
 * Dispatches the target `wp_ajax_*` hook directly rather than via
 * WP_Ajax_UnitTestCase::_handleAjax(), which also fires `admin_init` and would
 * re-run the profession seeder on every call.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Maintain_Log_Buffers_Ajax_Test extends WP_UnitTestCase {

	/**
	 * Bare AJAX action name.
	 */
	const ACTION = 'wp_mcp_ai_maintain_log_buffers';

	/**
	 * Fully qualified hook name.
	 */
	const HOOK = 'wp_ajax_wp_mcp_ai_maintain_log_buffers';

	/**
	 * Whether the last dispatch terminated through wp_die().
	 *
	 * @var bool
	 */
	protected $last_dispatch_died = false;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );

		// Keep the buffers deterministic: with logging on, plugin activity during
		// the request appends entries. The maintenance actions must work
		// regardless of the logging setting.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => false )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		if ( ! class_exists( 'WP_MCP_AI_Admin_AJAX_Handlers' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php';
		}

		// Mirror the registration performed by WP_MCP_AI_Settings_Dashboard, which
		// only instantiates under is_admin().
		remove_all_actions( self::HOOK );
		$handlers = new WP_MCP_AI_Admin_AJAX_Handlers();
		add_action( self::HOOK, array( $handlers, 'safe_ajax_handler' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		unset(
			$_POST['action'],
			$_POST['action_type'],
			$_POST['nonce'],
			$_REQUEST['action'],
			$_REQUEST['action_type'],
			$_REQUEST['nonce']
		);

		parent::tearDown();
	}

	/**
	 * Seed both buffers with oversized entries that bypass the write-time budget.
	 *
	 * @param int $count Entries per buffer.
	 * @return void
	 */
	protected function seed_bloated_buffers( $count = 4 ) {
		$entries = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$entries[] = array(
				'timestamp' => '2026-01-01 00:00:0' . $i,
				'type'      => 'tool_error',
				'message'   => 'Tool execution failed.',
				'context'   => array(
					'tool_slug'        => 'create_post',
					'assistant_config' => array(
						'provider'      => 'openai',
						'system_prompt' => str_repeat( 'Lorem ipsum dolor sit amet. ', 2000 ),
					),
				),
			);
		}

		update_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, $entries, false );
		update_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, $entries, false );
	}

	/**
	 * Fire the AJAX hook and decode the JSON body.
	 *
	 * @param string      $action_type Either 'compact' or 'clear'.
	 * @param string|null $nonce       Nonce to send, or null for a valid one.
	 * @return array|null Decoded response, or null when no body was emitted.
	 */
	protected function dispatch( $action_type, $nonce = null ) {
		$nonce_value = ( null === $nonce ) ? wp_create_nonce( self::ACTION ) : $nonce;

		$_POST['action']      = self::ACTION;
		$_POST['action_type'] = $action_type;
		$_POST['nonce']       = $nonce_value;

		// check_ajax_referer() reads the nonce from $_REQUEST.
		$_REQUEST['action']      = self::ACTION;
		$_REQUEST['action_type'] = $action_type;
		$_REQUEST['nonce']       = $nonce_value;

		$this->last_dispatch_died = false;

		ob_start();
		try {
			do_action( self::HOOK );
		} catch ( Exception $e ) {
			// wp_send_json_*() and check_ajax_referer() both terminate through
			// wp_die(), which the test suite converts into an exception.
			$this->last_dispatch_died = true;
		}
		$output = ob_get_clean();

		return ( '' === trim( (string) $output ) ) ? null : json_decode( $output, true );
	}

	/**
	 * Total entries currently held across both buffers.
	 *
	 * @return int
	 */
	protected function total_entries() {
		$stats = WP_MCP_AI_Logger::get_recent_buffer_stats();

		return (int) $stats['total_entries'];
	}

	/**
	 * An administrator must be able to compact the buffers.
	 */
	public function test_compact_reclaims_space_for_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->seed_bloated_buffers( 4 );
		$before = WP_MCP_AI_Logger::get_recent_buffer_stats();

		$response = $this->dispatch( 'compact' );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'Reclaimed', $response['data']['message'] );

		$after = WP_MCP_AI_Logger::get_recent_buffer_stats();
		$this->assertLessThan( $before['total_bytes'], $after['total_bytes'] );

		// Entries are kept.
		$this->assertSame( 8, $after['total_entries'] );
		$this->assertSame( 8, $response['data']['stats']['total_entries'] );
	}

	/**
	 * Compacting already-compact buffers must report that plainly.
	 */
	public function test_compact_reports_no_op_when_already_compact() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->seed_bloated_buffers( 2 );
		WP_MCP_AI_Logger::compact_recent_buffers();

		$response = $this->dispatch( 'compact' );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'already compact', $response['data']['message'] );
	}

	/**
	 * An administrator must be able to clear the buffers.
	 */
	public function test_clear_removes_all_entries_for_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->seed_bloated_buffers( 3 );

		$response = $this->dispatch( 'clear' );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'Deleted 6 log entries', $response['data']['message'] );

		$this->assertFalse( get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, false ) );
		$this->assertFalse( get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, false ) );
	}

	/**
	 * An unknown action_type must be rejected without touching the buffers.
	 */
	public function test_invalid_action_type_is_rejected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->seed_bloated_buffers( 2 );

		$response = $this->dispatch( 'drop_tables' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Invalid action type', $response['data']['message'] );
		$this->assertSame( 4, $this->total_entries() );
	}

	/**
	 * A subscriber must not be able to clear the buffers.
	 */
	public function test_insufficient_capability_is_rejected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->seed_bloated_buffers( 2 );

		$response = $this->dispatch( 'clear' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $response['data']['message'] );
		$this->assertSame( 4, $this->total_entries() );
	}

	/**
	 * A logged-out visitor must not be able to clear the buffers.
	 */
	public function test_anonymous_request_is_rejected() {
		wp_set_current_user( 0 );

		$this->seed_bloated_buffers( 2 );

		$this->dispatch( 'clear' );

		$this->assertSame( 4, $this->total_entries() );
	}

	/**
	 * A bad nonce must abort before any work happens.
	 */
	public function test_invalid_nonce_is_rejected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->seed_bloated_buffers( 2 );

		$this->dispatch( 'clear', 'not-a-valid-nonce' );

		$this->assertTrue( $this->last_dispatch_died, 'An invalid nonce must halt the request.' );
		$this->assertSame( 4, $this->total_entries() );
	}
}
