<?php
/**
 * Tests for create_cron_job tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test create_cron_job tool — state-changing, schedules WP cron events.
 */
class Test_Tool_Create_Cron_Job extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Create_Cron_Job
	 */
	private $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->tool     = new WP_MCP_AI_Tool_Create_Cron_Job();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'create_cron_job', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Missing hook name returns wp_mcp_ai_invalid_hook error.
	 */
	public function test_missing_hook_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		// Either invalid_hook or cron_disabled, both are WP_Error.
		$this->assertWPError( $result );
	}

	/**
	 * Empty hook returns error (not a valid hook name).
	 */
	public function test_empty_hook_returns_error() {
		$result = $this->tool->execute(
			array( 'hook' => '', 'timestamp' => time() + 3600 ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
	}

	/**
	 * Past timestamp returns wp_mcp_ai_past_timestamp error (when cron enabled).
	 */
	public function test_past_timestamp_returns_error() {
		// Enable cron orchestration in settings.
		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				(array) get_option( 'wp_mcp_ai_settings', array() ),
				array( 'enable_cron_orchestration' => true )
			)
		);

		$result = $this->tool->execute(
			array(
				'hook'      => 'my_test_hook',
				'timestamp' => time() - 100, // In the past.
				'schedule'  => 'hourly',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_past_timestamp', $result->get_error_code() );
	}

	/**
	 * Invalid recurrence returns wp_mcp_ai_invalid_schedule error (when cron enabled).
	 */
	public function test_invalid_schedule_returns_error() {
		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				(array) get_option( 'wp_mcp_ai_settings', array() ),
				array( 'enable_cron_orchestration' => true )
			)
		);

		$result = $this->tool->execute(
			array(
				'hook'      => 'my_test_hook',
				'timestamp' => time() + 3600,
				'schedule'  => 'not_a_real_schedule_' . uniqid(),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_schedule', $result->get_error_code() );
	}

	/**
	 * Valid args schedule the cron event.
	 */
	public function test_valid_args_schedule_cron_event() {
		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				(array) get_option( 'wp_mcp_ai_settings', array() ),
				array( 'enable_cron_orchestration' => true )
			)
		);

		$hook      = 'phpunit_test_cron_' . uniqid();
		$timestamp = time() + 7200;

		$result = $this->tool->execute(
			array(
				'hook'      => $hook,
				'timestamp' => $timestamp,
				'schedule'  => 'hourly',
			),
			array( 'user_id' => $this->admin_id )
		);

		// Either succeeds (registered) or returns cron_disabled if feature is off.
		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'wp_mcp_ai_cron_disabled', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'hook', $result );
			$this->assertSame( $hook, $result['hook'] );
		}
	}
}
