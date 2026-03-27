<?php
/**
 * Tests for WP_MCP_AI_Pro_Schedule_Manager.
 *
 * Covers:
 * - Creating task, workflow, assistant_run, and channel_broadcast schedules
 * - Validation (missing hook, past timestamp, invalid interval)
 * - Validation of channel_broadcast config (missing message, channels, credentials)
 * - Enabling/disabling schedules
 * - Execution history recording
 * - Run-history ring buffer
 * - Retry counting on failure dispatch
 * - Deleting schedules clears WP cron and history
 * - notify_channels field stored and retrieved
 *
 * @package WP_MCP_AI_Pro
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

/**
 * Test suite for Pro Schedule Manager.
 */
class Test_Pro_Schedule_Manager extends WP_UnitTestCase {

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

		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Task schedule creation
	// -------------------------------------------------------------------------

	/**
	 * Test creating a minimal task schedule.
	 */
	public function test_create_task_schedule_returns_id() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'my_test_hook',
				'name'          => 'Test Task',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		$this->assertIsString( $id );
		$this->assertNotEmpty( $id );
	}

	/**
	 * Test that the stored schedule record has expected fields.
	 */
	public function test_create_task_schedule_stores_record() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'     => 'task',
				'hook'              => 'my_test_hook',
				'name'              => 'Test Task',
				'description'       => 'Does something',
				'schedule'          => 'single',
				'timestamp'         => time() + 120,
				'priority'          => 3,
				'tags'              => array( 'cleanup', 'daily' ),
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 120,
			),
			$this->admin_id
		);

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );

		$this->assertIsArray( $schedule );
		$this->assertSame( 'my_test_hook', $schedule['hook'] );
		$this->assertSame( 'Test Task', $schedule['name'] );
		$this->assertSame( 'Does something', $schedule['description'] );
		$this->assertSame( 'task', $schedule['schedule_type'] );
		$this->assertSame( 3, $schedule['priority'] );
		$this->assertContains( 'cleanup', $schedule['tags'] );
		$this->assertTrue( $schedule['notify_on_failure'] );
		$this->assertSame( 2, $schedule['max_retries'] );
		$this->assertSame( 120, $schedule['retry_delay'] );
		$this->assertSame( 'never', $schedule['last_run_status'] );
		$this->assertSame( 0, $schedule['run_count'] );
	}

	/**
	 * Test that task schedule schedules a WP cron event.
	 */
	public function test_create_task_schedule_registers_wp_cron() {
		$ts = time() + 300;

		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'my_cron_hook',
				'schedule'      => 'single',
				'timestamp'     => $ts,
			),
			$this->admin_id
		);

		$next = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $id );
		$this->assertNotFalse( $next );
		$this->assertEqualsWithDelta( $ts, $next, 1 );
	}

	// -------------------------------------------------------------------------
	// Workflow schedule creation
	// -------------------------------------------------------------------------

	/**
	 * Test creating a workflow schedule.
	 */
	public function test_create_workflow_schedule() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'  => 'workflow',
				'name'           => 'Daily SEO Audit',
				'schedule'       => 'single',
				'timestamp'      => time() + 120,
				'workflow_steps' => array(
					array(
						'tool_slug' => 'web_search',
						'arguments' => array( 'query' => 'test' ),
						'label'     => 'Search',
					),
					array(
						'tool_slug' => 'create_post',
						'arguments' => array(),
						'label'     => 'Publish',
					),
				),
			),
			$this->admin_id
		);

		$this->assertNotWPError( $id );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 'workflow', $schedule['schedule_type'] );
		$this->assertCount( 2, $schedule['workflow_steps'] );
		$this->assertSame( 'web_search', $schedule['workflow_steps'][0]['tool_slug'] );
	}

	/**
	 * Test that a workflow schedule with no steps returns an error.
	 */
	public function test_create_workflow_schedule_no_steps_returns_error() {
		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'  => 'workflow',
				'name'           => 'Empty Workflow',
				'schedule'       => 'single',
				'timestamp'      => time() + 120,
				'workflow_steps' => array(),
			),
			$this->admin_id
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_workflow_steps', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Assistant run schedule creation
	// -------------------------------------------------------------------------

	/**
	 * Test creating an assistant_run schedule.
	 */
	public function test_create_assistant_run_schedule() {
		$assistant_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'    => 'assistant_run',
				'name'             => 'Weekly Summary',
				'schedule'         => 'single',
				'timestamp'        => time() + 120,
				'assistant_config' => array(
					'assistant_id' => $assistant_id,
					'message'      => 'Generate the weekly report.',
				),
			),
			$this->admin_id
		);

		$this->assertNotWPError( $id );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 'assistant_run', $schedule['schedule_type'] );
		$this->assertSame( $assistant_id, $schedule['assistant_config']['assistant_id'] );
		$this->assertSame( 'Generate the weekly report.', $schedule['assistant_config']['message'] );
	}

	/**
	 * Test that an assistant_run schedule without a message returns an error.
	 */
	public function test_create_assistant_run_missing_message_returns_error() {
		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'    => 'assistant_run',
				'name'             => 'Bad Config',
				'schedule'         => 'single',
				'timestamp'        => time() + 120,
				'assistant_config' => array( 'assistant_id' => 5 ),
			),
			$this->admin_id
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_assistant_message', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Test that a task with no hook returns an error.
	 */
	public function test_create_task_missing_hook_returns_error() {
		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'name'          => 'No Hook',
				'schedule'      => 'single',
				'timestamp'     => time() + 60,
			),
			$this->admin_id
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_hook', $result->get_error_code() );
	}

	/**
	 * Test that a past timestamp returns an error.
	 */
	public function test_create_schedule_past_timestamp_returns_error() {
		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'my_hook',
				'schedule'      => 'single',
				'timestamp'     => time() - 60,
			),
			$this->admin_id
		);

		$this->assertWPError( $result );
		$this->assertSame( 'past_timestamp', $result->get_error_code() );
	}

	/**
	 * Test that an invalid interval returns an error.
	 */
	public function test_create_schedule_invalid_interval_returns_error() {
		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'my_hook',
				'schedule'      => 'every_99_years',
				'timestamp'     => time() + 60,
			),
			$this->admin_id
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_schedule', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Update
	// -------------------------------------------------------------------------

	/**
	 * Test updating a schedule's name and priority.
	 */
	public function test_update_schedule_changes_fields() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'my_hook',
				'name'          => 'Original',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
				'priority'      => 5,
			),
			$this->admin_id
		);

		WP_MCP_AI_Pro_Schedule_Manager::update_schedule(
			$id,
			array(
				'name'     => 'Updated',
				'priority' => 2,
			),
			$this->admin_id
		);

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 'Updated', $schedule['name'] );
		$this->assertSame( 2, $schedule['priority'] );
	}

	/**
	 * Test that updating a non-existent schedule returns a WP_Error.
	 */
	public function test_update_nonexistent_schedule_returns_error() {
		$result = WP_MCP_AI_Pro_Schedule_Manager::update_schedule(
			'nonexistent_id',
			array( 'name' => 'Test' ),
			$this->admin_id
		);

		$this->assertWPError( $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Toggle
	// -------------------------------------------------------------------------

	/**
	 * Test disabling a schedule removes its WP cron event.
	 */
	public function test_disable_schedule_removes_wp_cron() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'my_hook',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		$before = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $id );
		$this->assertNotFalse( $before );

		WP_MCP_AI_Pro_Schedule_Manager::toggle_schedule( $id, false, $this->admin_id );

		$after = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $id );
		$this->assertFalse( $after );
	}

	// -------------------------------------------------------------------------
	// Delete
	// -------------------------------------------------------------------------

	/**
	 * Test deleting a schedule removes it and its WP cron event.
	 */
	public function test_delete_schedule_removes_record_and_cron() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'my_hook',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		$this->assertNotFalse( WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $id ) );

		$deleted = WP_MCP_AI_Pro_Schedule_Manager::delete_schedule( $id );

		$this->assertTrue( $deleted );
		$this->assertNull( WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id ) );
		$this->assertFalse( WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $id ) );
	}

	/**
	 * Test deleting a non-existent schedule returns false.
	 */
	public function test_delete_nonexistent_schedule_returns_false() {
		$this->assertFalse( WP_MCP_AI_Pro_Schedule_Manager::delete_schedule( 'does_not_exist' ) );
	}

	// -------------------------------------------------------------------------
	// Run history
	// -------------------------------------------------------------------------

	/**
	 * Test that dispatch records a successful run in history.
	 */
	public function test_dispatch_task_records_success_in_history() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'wp_mcp_ai_test_dispatch_hook_' . uniqid(),
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		// Dispatch (no listener on hook → runs silently, no exception).
		$result = WP_MCP_AI_Pro_Schedule_Manager::dispatch( $id );
		$this->assertTrue( $result );

		$history = WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $id );
		$this->assertCount( 1, $history );
		$this->assertSame( 'success', $history[0]['status'] );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 'success', $schedule['last_run_status'] );
		$this->assertSame( 1, $schedule['run_count'] );
	}

	/**
	 * Test that dispatch on a disabled schedule does nothing.
	 */
	public function test_dispatch_disabled_schedule_returns_false() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'my_hook',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
				'enabled'       => false,
			),
			$this->admin_id
		);

		$result = WP_MCP_AI_Pro_Schedule_Manager::dispatch( $id );
		$this->assertFalse( $result );
	}

	/**
	 * Test history ring buffer prunes to MAX_HISTORY_PER_SCHEDULE.
	 */
	public function test_history_ring_buffer_trims_at_limit() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'ring_buffer_test_hook_' . uniqid(),
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		$limit = WP_MCP_AI_Pro_Schedule_Manager::MAX_HISTORY_PER_SCHEDULE;

		// Dispatch more than the limit.
		for ( $i = 0; $i < $limit + 5; $i++ ) {
			WP_MCP_AI_Pro_Schedule_Manager::dispatch( $id );
		}

		$history = WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $id, $limit + 10 );
		$this->assertLessThanOrEqual( $limit, count( $history ) );
	}

	/**
	 * Test clearing run history works.
	 */
	public function test_clear_run_history() {
		$hook = 'clear_history_test_' . uniqid();
		$id   = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => $hook,
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		WP_MCP_AI_Pro_Schedule_Manager::dispatch( $id );
		$this->assertNotEmpty( WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $id ) );

		WP_MCP_AI_Pro_Schedule_Manager::clear_run_history( $id );
		$this->assertEmpty( WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $id ) );
	}

	// -------------------------------------------------------------------------
	// Get schedules / filtering
	// -------------------------------------------------------------------------

	/**
	 * Test get_schedules returns all schedules.
	 */
	public function test_get_schedules_returns_all() {
		WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'hook_a',
				'schedule'      => 'single',
				'timestamp'     => time() + 100,
				'tags'          => array( 'a' ),
			),
			$this->admin_id
		);
		WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'hook_b',
				'schedule'      => 'single',
				'timestamp'     => time() + 200,
				'tags'          => array( 'b' ),
			),
			$this->admin_id
		);

		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
		$this->assertCount( 2, $schedules );
	}

	/**
	 * Test tag filtering.
	 */
	public function test_get_schedules_filters_by_tag() {
		WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'hook_a',
				'schedule'      => 'single',
				'timestamp'     => time() + 100,
				'tags'          => array( 'report' ),
			),
			$this->admin_id
		);
		WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'hook_b',
				'schedule'      => 'single',
				'timestamp'     => time() + 200,
				'tags'          => array( 'cleanup' ),
			),
			$this->admin_id
		);

		$filtered = WP_MCP_AI_Pro_Schedule_Manager::get_schedules( array( 'tag' => 'report' ) );
		$this->assertCount( 1, $filtered );
		$first = reset( $filtered );
		$this->assertSame( 'hook_a', $first['hook'] );
	}

	// -------------------------------------------------------------------------
	// Custom intervals
	// -------------------------------------------------------------------------

	/**
	 * Test custom intervals are registered.
	 */
	public function test_custom_intervals_registered() {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( 'wp_mcp_ai_every_15_minutes', $schedules );
		$this->assertArrayHasKey( 'wp_mcp_ai_every_30_minutes', $schedules );
		$this->assertArrayHasKey( 'wp_mcp_ai_weekly', $schedules );
		$this->assertArrayHasKey( 'wp_mcp_ai_monthly', $schedules );
	}

	// -------------------------------------------------------------------------
	// Channel Broadcast schedule
	// -------------------------------------------------------------------------

	/**
	 * Test creating a valid channel_broadcast schedule.
	 */
	public function test_create_channel_broadcast_schedule_returns_id() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'    => 'channel_broadcast',
				'name'             => 'Daily Telegram Digest',
				'schedule'         => 'single',
				'timestamp'        => time() + 120,
				'broadcast_config' => array(
					'message'     => 'Good morning team!',
					'channels'    => array( 'telegram' ),
					'credentials' => array(
						'telegram' => array(
							'token'   => 'BOT',
							'chat_id' => '123',
						),
					),
				),
			),
			$this->admin_id
		);

		$this->assertIsString( $id );
		$this->assertNotEmpty( $id );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 'channel_broadcast', $schedule['schedule_type'] );
		$this->assertSame( 'Good morning team!', $schedule['broadcast_config']['message'] );
		$this->assertContains( 'telegram', $schedule['broadcast_config']['channels'] );
	}

	/**
	 * Test that missing message in broadcast_config returns WP_Error.
	 */
	public function test_create_channel_broadcast_missing_message_returns_error() {
		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'    => 'channel_broadcast',
				'timestamp'        => time() + 120,
				'broadcast_config' => array(
					'channels'    => array( 'telegram' ),
					'credentials' => array(
						'telegram' => array(
							'token'   => 'BOT',
							'chat_id' => '123',
						),
					),
				),
			),
			$this->admin_id
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_broadcast_message', $result->get_error_code() );
	}

	/**
	 * Test that missing channels returns WP_Error.
	 */
	public function test_create_channel_broadcast_missing_channels_returns_error() {
		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'    => 'channel_broadcast',
				'timestamp'        => time() + 120,
				'broadcast_config' => array(
					'message'     => 'Hello',
					'credentials' => array(
						'telegram' => array(
							'token'   => 'BOT',
							'chat_id' => '123',
						),
					),
				),
			),
			$this->admin_id
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_broadcast_channels', $result->get_error_code() );
	}

	/**
	 * Test that only valid channel slugs are accepted.
	 */
	public function test_create_channel_broadcast_invalid_channels_only_returns_error() {
		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'    => 'channel_broadcast',
				'timestamp'        => time() + 120,
				'broadcast_config' => array(
					'message'     => 'Hello',
					'channels'    => array( 'invalid_channel', 'another_bad_one' ),
					'credentials' => array( 'invalid_channel' => array() ),
				),
			),
			$this->admin_id
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_broadcast_channels', $result->get_error_code() );
	}

	/**
	 * Test that mixed valid and invalid channels are sanitized to only valid ones.
	 */
	public function test_create_channel_broadcast_mixed_channels_filters_invalid() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'    => 'channel_broadcast',
				'timestamp'        => time() + 120,
				'broadcast_config' => array(
					'message'     => 'Hi',
					'channels'    => array( 'slack', 'invalid_xyz', 'discord' ),
					'credentials' => array(
						'slack'   => array(
							'token'   => 'T',
							'channel' => '#general',
						),
						'discord' => array( 'webhook_url' => 'https://discord.com/api/webhooks/test' ),
					),
				),
			),
			$this->admin_id
		);

		$this->assertIsString( $id );
		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertCount( 2, $schedule['broadcast_config']['channels'] );
		$this->assertContains( 'slack', $schedule['broadcast_config']['channels'] );
		$this->assertContains( 'discord', $schedule['broadcast_config']['channels'] );
		$this->assertNotContains( 'invalid_xyz', $schedule['broadcast_config']['channels'] );
	}

	/**
	 * Test that notify_channels and notify_channel_credentials are stored.
	 */
	public function test_notify_channels_stored_on_create() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'              => 'task',
				'hook'                       => 'my_hook',
				'timestamp'                  => time() + 120,
				'notify_on_failure'          => true,
				'notify_channels'            => array( 'telegram', 'slack' ),
				'notify_channel_credentials' => array(
					'telegram' => array(
						'token'   => 'T',
						'chat_id' => '1',
					),
					'slack'    => array(
						'token'   => 'S',
						'channel' => '#alerts',
					),
				),
			),
			$this->admin_id
		);

		$this->assertIsString( $id );
		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertContains( 'telegram', $schedule['notify_channels'] );
		$this->assertContains( 'slack', $schedule['notify_channels'] );
		$this->assertArrayHasKey( 'telegram', $schedule['notify_channel_credentials'] );
	}

	/**
	 * Test that notify_channels can be updated via update_schedule.
	 */
	public function test_notify_channels_updated_via_update_schedule() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'my_hook2',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		$this->assertIsString( $id );

		WP_MCP_AI_Pro_Schedule_Manager::update_schedule(
			$id,
			array(
				'notify_channels' => array( 'discord' ),
			),
			$this->admin_id
		);

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertContains( 'discord', $schedule['notify_channels'] );
	}

	/**
	 * Test TYPE_CHANNEL_BROADCAST constant is defined.
	 */
	public function test_type_channel_broadcast_constant_is_defined() {
		$this->assertSame( 'channel_broadcast', WP_MCP_AI_Pro_Schedule_Manager::TYPE_CHANNEL_BROADCAST );
	}

	// -------------------------------------------------------------------------
	// Timeout field
	// -------------------------------------------------------------------------

	/**
	 * Test that timeout field is stored on schedule creation.
	 */
	public function test_create_schedule_stores_timeout() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'timeout_hook',
				'name'          => 'Timeout Test',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
				'timeout'       => 60,
			),
			$this->admin_id
		);

		$this->assertIsString( $id );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 60, $schedule['timeout'] );
	}

	/**
	 * Test that timeout defaults to zero when not provided.
	 */
	public function test_create_schedule_timeout_defaults_to_zero() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'no_timeout_hook',
				'name'          => 'No Timeout',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		$this->assertIsString( $id );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 0, $schedule['timeout'] );
	}

	/**
	 * Test that timeout can be updated.
	 */
	public function test_update_schedule_timeout() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'upd_timeout_hook',
				'name'          => 'Update Timeout',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		$this->assertIsString( $id );

		WP_MCP_AI_Pro_Schedule_Manager::update_schedule(
			$id,
			array( 'timeout' => 120 ),
			$this->admin_id
		);

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 120, $schedule['timeout'] );
	}

	/**
	 * Test that negative timeout is clamped to zero.
	 */
	public function test_negative_timeout_clamped_to_zero() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'neg_timeout_hook',
				'name'          => 'Neg Timeout',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
				'timeout'       => -10,
			),
			$this->admin_id
		);

		$this->assertIsString( $id );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 0, $schedule['timeout'] );
	}

	// -------------------------------------------------------------------------
	// Callback URL field
	// -------------------------------------------------------------------------

	/**
	 * Test that callback_url is stored on schedule creation.
	 */
	public function test_create_schedule_stores_callback_url() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'cb_hook',
				'name'          => 'Callback Test',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
				'callback_url'  => 'https://example.com/webhook',
			),
			$this->admin_id
		);

		$this->assertIsString( $id );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 'https://example.com/webhook', $schedule['callback_url'] );
	}

	/**
	 * Test that callback_url defaults to empty string when not provided.
	 */
	public function test_create_schedule_callback_url_defaults_to_empty() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'no_cb_hook',
				'name'          => 'No Callback',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		$this->assertIsString( $id );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( '', $schedule['callback_url'] );
	}

	/**
	 * Test that callback_url can be updated.
	 */
	public function test_update_schedule_callback_url() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type' => 'task',
				'hook'          => 'upd_cb_hook',
				'name'          => 'Update CB',
				'schedule'      => 'single',
				'timestamp'     => time() + 120,
			),
			$this->admin_id
		);

		$this->assertIsString( $id );

		WP_MCP_AI_Pro_Schedule_Manager::update_schedule(
			$id,
			array( 'callback_url' => 'https://hooks.example.com/run' ),
			$this->admin_id
		);

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 'https://hooks.example.com/run', $schedule['callback_url'] );
	}

	// -------------------------------------------------------------------------
	// dispatch_assistant_run calls chat endpoint
	// -------------------------------------------------------------------------

	/**
	 * Test that assistant_run dispatch requires both assistant_id and message.
	 */
	public function test_dispatch_assistant_run_missing_config_returns_error() {
		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'    => 'assistant_run',
				'name'             => 'Incomplete AR',
				'schedule'         => 'single',
				'timestamp'        => time() + 120,
				'assistant_config' => array(
					'assistant_id' => 99,
					'message'      => 'Test',
				),
			),
			$this->admin_id
		);

		$this->assertNotWPError( $id );

		// Now manually corrupt the config to test validation.
		$schedules                            = get_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION, array() );
		$schedules[ $id ]['assistant_config'] = array();
		update_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION, $schedules );

		// Dispatch should fail due to missing assistant_id/message.
		$result = WP_MCP_AI_Pro_Schedule_Manager::dispatch( $id );
		$this->assertFalse( $result );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );
		$this->assertSame( 'failure', $schedule['last_run_status'] );
	}

	/**
	 * Test that assistant_run fires the do_action hook on dispatch.
	 */
	public function test_dispatch_assistant_run_fires_action_hook() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'    => 'assistant_run',
				'name'             => 'Action Hook Test',
				'schedule'         => 'single',
				'timestamp'        => time() + 120,
				'assistant_config' => array(
					'assistant_id' => $assistant_id,
					'message'      => 'Run the report.',
				),
			),
			$this->admin_id
		);

		$this->assertNotWPError( $id );

		$fired = false;
		add_action(
			'wp_mcp_ai_pro_scheduled_assistant_run',
			function ( $aid, $msg, $ctx ) use ( &$fired, $assistant_id ) {
				$fired = true;
				$this->assertSame( $assistant_id, $aid );
				$this->assertSame( 'Run the report.', $msg );
				$this->assertArrayHasKey( 'schedule_id', $ctx );
				$this->assertArrayHasKey( 'response', $ctx );
			},
			10,
			3
		);

		WP_MCP_AI_Pro_Schedule_Manager::dispatch( $id );
		$this->assertTrue( $fired, 'The wp_mcp_ai_pro_scheduled_assistant_run action should fire.' );
	}
}
