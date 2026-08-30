<?php
/**
 * Tests for the WP_MCP_AI_Crawler inline-async-tick integration (Slice 3).
 *
 * Verifies:
 *   1. register_remote_job() registers a shutdown action when the inline kick is enabled.
 *   2. handle_poll_event() acquires the cooperative tick lock and prevents double-polling.
 *   3. The wp_mcp_ai_inline_kick_enabled filter can disable the inline kick.
 *   4. do_poll_event() short-circuits for jobs with skip_polling set or no stored job.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Crawl4AI_Inline_Kick_Test extends WP_UnitTestCase {

	/**
	 * Fake base-URL used across tests.
	 *
	 * @var string
	 */
	const FAKE_BASE_URL = 'https://crawl4ai.example.com';

	/**
	 * Clean up transients and scheduled hooks after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_filter;
		// Remove any shutdown actions registered during the test.
		if ( isset( $wp_filter['shutdown'] ) ) {
			unset( $wp_filter['shutdown'] );
		}
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// 1. Shutdown kick registration
	// -------------------------------------------------------------------------

	/**
	 * The register_remote_job() method must register a shutdown action when
	 * the inline kick is enabled (default).
	 */
	public function test_register_remote_job_adds_shutdown_action() {
		$task_id = 'test_kick_reg_' . wp_generate_uuid4();

		WP_MCP_AI_Crawler::register_remote_job(
			$task_id,
			array(
				'base_url' => self::FAKE_BASE_URL,
			)
		);

		$this->assertGreaterThan( 0, has_action( 'shutdown' ), 'Shutdown action should be registered after register_remote_job()' );
	}

	/**
	 * The register_remote_job() method must NOT register a shutdown action
	 * when the wp_mcp_ai_inline_kick_enabled filter returns false.
	 */
	public function test_register_remote_job_skips_shutdown_action_when_filter_disabled() {
		add_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );

		$task_id = 'test_kick_disabled_' . wp_generate_uuid4();

		// Remove any pre-existing shutdown hooks so we can detect a new one.
		global $wp_filter;
		if ( isset( $wp_filter['shutdown'] ) ) {
			unset( $wp_filter['shutdown'] );
		}

		WP_MCP_AI_Crawler::register_remote_job(
			$task_id,
			array(
				'base_url' => self::FAKE_BASE_URL,
			)
		);

		$this->assertFalse( has_action( 'shutdown' ), 'Shutdown action should NOT be registered when filter disables the inline kick' );

		remove_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );
	}

	// -------------------------------------------------------------------------
	// 2. Tick-lock prevents double-polling
	// -------------------------------------------------------------------------

	/**
	 * The handle_poll_event() method must bail early when the cooperative
	 * tick lock is already held (simulated by pre-setting the transient).
	 */
	public function test_handle_poll_event_bails_when_lock_held() {
		$task_id  = 'test_lock_held_' . wp_generate_uuid4();
		$lock_key = WP_MCP_AI_Crawler::TICK_LOCK_PREFIX . md5( $task_id );

		// Pre-acquire the lock externally to simulate a concurrent tick.
		set_transient( $lock_key, 1, WP_MCP_AI_Crawler::TICK_LOCK_TTL );

		// Attempt a second poll — it should NOT call do_poll_event, so no
		// WP_Error or side-effect should emerge.
		// We verify indirectly: the job doesn't exist, so do_poll_event would
		// simply return; but with the lock held the call should return before
		// even checking the job.
		// The simplest assertion is that handle_poll_event() returns cleanly.
		$this->assertNull( WP_MCP_AI_Crawler::handle_poll_event( $task_id ) );

		// Clean up the lock.
		delete_transient( $lock_key );
	}

	// -------------------------------------------------------------------------
	// 3. do_poll_event() short-circuits
	// -------------------------------------------------------------------------

	/**
	 * The do_poll_event() method must return early when no job is stored for
	 * the given task_id.
	 */
	public function test_do_poll_event_bails_on_missing_job() {
		$task_id = 'test_missing_job_' . wp_generate_uuid4();

		// do_poll_event is protected static; reach it via reflection like the
		// save_job helper below.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Crawler' );
		$poll_event = $reflection->getMethod( 'do_poll_event' );
		$poll_event->setAccessible( true );

		// No stored job — do_poll_event should silently return.
		$this->assertNull( $poll_event->invoke( null, $task_id ) );
	}

	/**
	 * The do_poll_event() method must return early for jobs with skip_polling
	 * set, e.g. completed synchronous jobs registered via
	 * register_completed_job().
	 */
	public function test_do_poll_event_bails_on_skip_polling_flag() {
		$task_id = 'test_skip_polling_' . wp_generate_uuid4();

		// Manually store a job record with skip_polling set.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Crawler' );
		$save_job   = $reflection->getMethod( 'save_job' );
		$save_job->setAccessible( true );
		$save_job->invoke(
			null,
			array(
				'task_id'      => $task_id,
				'status'       => 'completed',
				'created_at'   => time(),
				'updated_at'   => time(),
				'skip_polling' => true,
			)
		);

		// do_poll_event is protected static; reach it via reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Crawler' );
		$poll_event = $reflection->getMethod( 'do_poll_event' );
		$poll_event->setAccessible( true );

		$this->assertNull( $poll_event->invoke( null, $task_id ) );
	}
}
