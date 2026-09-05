<?php
/**
 * Tests for the activity / error logging emitted by the transcript mining job.
 *
 * Verifies:
 * - `enqueue()` writes an info-level `transcript_mining` activity entry.
 * - `handle_tick()` writes an activity entry on the success path.
 * - `handle_tick()` writes a `WP_MCP_AI_Logger::log_error()` entry when the
 *   underlying `mine_agent_memory` tool returns a `WP_Error`.
 *
 * Uses the same `wp_mcp_ai_mine_transcripts_sessions` and
 * `wp_mcp_ai_mine_transcripts_session_messages` filter hooks as
 * `tests/test-transcript-mining-job.php` to inject mock data.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for transcript mining job logging.
 */
class WP_MCP_AI_Transcript_Mining_Job_Logging_Test extends WP_UnitTestCase {

	/**
	 * Mock sessions injected via the sessions filter.
	 *
	 * @var array
	 */
	private $mock_sessions = array();

	/**
	 * Mock messages keyed by session key.
	 *
	 * @var array<string,array>
	 */
	private $mock_messages = array();

	/**
	 * Callable that preempts the wp-cron loopback spawned by enqueue().
	 *
	 * @var callable|null
	 */
	private $cron_loopback_guard = null;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging' => true,
			)
		);

		// Logging is opt-in: drop the static settings cache so the write above
		// is what is_logging_enabled() observes, independent of options left
		// behind by earlier suites in the same process.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		$this->mock_sessions = array();
		$this->mock_messages = array();

		add_filter( 'wp_mcp_ai_mine_transcripts_sessions', array( $this, 'inject_sessions' ), 10, 2 );
		add_filter( 'wp_mcp_ai_mine_transcripts_session_messages', array( $this, 'inject_messages' ), 10, 3 );

		// enqueue() spawns wp-cron via an HTTP loopback; in CI that fires the
		// first tick in a separate PHP process using the real tool, racing the
		// explicit handle_tick() calls below. Preempt the loopback so ticks
		// only ever run in-process.
		$this->cron_loopback_guard = static function ( $preempt, $parsed_args, $url ) {
			unset( $parsed_args );
			if ( false !== strpos( $url, 'wp-cron.php' ) ) {
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => '',
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $this->cron_loopback_guard, 1, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_mine_transcripts_sessions', array( $this, 'inject_sessions' ), 10 );
		remove_filter( 'wp_mcp_ai_mine_transcripts_session_messages', array( $this, 'inject_messages' ), 10 );
		remove_filter( 'pre_http_request', $this->cron_loopback_guard, 1 );
		$this->cron_loopback_guard = null;

		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_mcp_ai_ctx_' ) . '%',
				$wpdb->esc_like( '_transient_wp_mcp_ai_tx_mine_job_' ) . '%'
			)
		);

		parent::tearDown();
	}

	/**
	 * Inject sessions filter callback.
	 *
	 * @param array $sessions Existing.
	 * @param array $args     Args.
	 * @return array
	 */
	public function inject_sessions( $sessions, $args ) {
		unset( $sessions, $args );
		return $this->mock_sessions;
	}

	/**
	 * Inject messages filter callback.
	 *
	 * @param array  $messages    Existing.
	 * @param string $session_key Key.
	 * @param array  $args        Args.
	 * @return array
	 */
	public function inject_messages( $messages, $session_key, $args ) {
		unset( $messages, $args );
		return isset( $this->mock_messages[ $session_key ] ) ? $this->mock_messages[ $session_key ] : array();
	}

	/**
	 * Seed N mock sessions.
	 *
	 * @param int $count Count.
	 */
	private function seed_sessions( $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			$key                         = 'sess_' . $i;
			$this->mock_sessions[]       = array(
				'session_key'  => $key,
				'assistant_id' => '99',
				'turn_count'   => 1,
				'started_at'   => '2026-01-01 00:00:00',
				'last_created' => '2026-01-01 00:01:00',
			);
			$this->mock_messages[ $key ] = array(
				array(
					'role'          => 'user',
					'content'       => 'message ' . $i,
					'message_index' => 0,
				),
				array(
					'role'          => 'assistant',
					'content'       => 'reply ' . $i,
					'message_index' => 1,
				),
			);
		}
	}

	/**
	 * Find recent-activity entries with the given event type.
	 *
	 * @param string $type Event type.
	 * @return array
	 */
	private function find_activity( $type ) {
		$entries = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$matches = array();
		foreach ( (array) $entries as $entry ) {
			if ( isset( $entry['type'] ) && $entry['type'] === $type ) {
				$matches[] = $entry;
			}
		}
		return $matches;
	}

	/**
	 * Find recent-errors entries.
	 *
	 * @return array
	 */
	private function find_errors() {
		return (array) get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array() );
	}

	/**
	 * `enqueue()` must write a `transcript_mining` activity entry recording
	 * the agent_id, queued total, and batch size.
	 */
	public function test_enqueue_writes_activity_entry() {
		$state = WP_MCP_AI_Transcript_Mining_Job::enqueue(
			array( 'agent_id' => 'agent_log_1' ),
			array(
				'session_keys' => array( 'sess_a', 'sess_b' ),
				'batch_size'   => 2,
			)
		);

		$this->assertIsArray( $state );

		$entries = $this->find_activity( 'transcript_mining' );
		$this->assertNotEmpty( $entries, 'enqueue() must record an activity entry' );

		$first = $entries[0];
		$this->assertSame( 'transcript_mining', $first['type'] );
		$this->assertArrayHasKey( 'context', $first );
		$this->assertSame( 'agent_log_1', $first['context']['agent_id'] );
		$this->assertSame( 2, $first['context']['batch_size'] );
		$this->assertSame( 2, $first['context']['total'] );
	}

	/**
	 * A successful tick must record an activity entry with mined / skipped /
	 * failed counts.
	 */
	public function test_successful_tick_writes_activity_entry() {
		$this->seed_sessions( 2 );
		$state = WP_MCP_AI_Transcript_Mining_Job::enqueue(
			array( 'agent_id' => 'agent_log_2' ),
			array(
				'session_keys' => array( 'sess_0', 'sess_1' ),
				'batch_size'   => 2,
			)
		);

		// Clear the enqueue activity entry so we can isolate the tick.
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );

		WP_MCP_AI_Transcript_Mining_Job::handle_tick( $state['id'] );

		$entries = $this->find_activity( 'transcript_mining' );
		$this->assertNotEmpty( $entries, 'handle_tick() must record an activity entry on success' );

		$tick = $entries[0];
		$this->assertSame( 'transcript_mining', $tick['type'] );
		$this->assertArrayHasKey( 'mined', $tick['context'] );
		$this->assertArrayHasKey( 'skipped', $tick['context'] );
		$this->assertArrayHasKey( 'failed', $tick['context'] );
		$this->assertSame( 2, $tick['context']['batch_size'] );
		$this->assertSame( 'agent_log_2', $tick['context']['agent_id'] );
	}

	/**
	 * When the underlying tool returns a WP_Error the tick must write a
	 * recent-errors entry through `WP_MCP_AI_Logger::log_error()`. We exercise
	 * the contract by swapping the `mine_agent_memory` tool in the registry
	 * with a subclass whose `execute()` returns a WP_Error.
	 */
	public function test_failing_tick_writes_recent_error_entry() {
		$this->seed_sessions( 1 );

		$state = WP_MCP_AI_Transcript_Mining_Job::enqueue(
			array( 'agent_id' => 'agent_log_err' ),
			array(
				'session_keys' => array( 'sess_0' ),
				'batch_size'   => 1,
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$original = $registry->get_tool( 'mine_agent_memory' );
		if ( null === $original ) {
			$this->markTestSkipped( 'mine_agent_memory tool is not registered in this build.' );
		}

		$registry->register_tool( new WP_MCP_AI_Tool_Mine_Agent_Memory_Failure_Stub() );

		// Clear so assertions reflect only this tick.
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		WP_MCP_AI_Transcript_Mining_Job::handle_tick( $state['id'] );

		// Restore the original tool.
		$registry->register_tool( $original );

		$errors = $this->find_errors();
		$this->assertNotEmpty( $errors, 'WP_Error from tool->execute() must populate recent-errors.' );

		$last = $errors[0];
		$this->assertSame( 'error', $last['type'] );
		$this->assertStringContainsString( 'Transcript mining tick failed', $last['message'] );
		$this->assertStringContainsString( 'Simulated mining failure', $last['message'] );
		$this->assertSame( 'agent_log_err', $last['context']['agent_id'] );

		$progress = WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] );
		$this->assertSame( 1, $progress['failed_count'] );
	}
}

/**
 * Test fixture: tool stub that always returns WP_Error.
 *
 * Declared at file scope so PHP allows the class declaration.
 * Used by WP_MCP_AI_Transcript_Mining_Job_Logging_Test::test_failing_tick_writes_recent_error_entry().
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- test-only fixture below.
if ( ! class_exists( 'WP_MCP_AI_Tool_Mine_Agent_Memory_Failure_Stub', false ) ) {
	/**
	 * Stub tool that always returns a WP_Error from execute().
	 *
	 * Test-only fixture declared at file scope so PHP allows the class declaration.
	 * Used by WP_MCP_AI_Transcript_Mining_Job_Logging_Test::test_failing_tick_writes_recent_error_entry().
	 */
	class WP_MCP_AI_Tool_Mine_Agent_Memory_Failure_Stub extends WP_MCP_AI_Tool_Mine_Agent_Memory {

		/**
		 * Always returns a WP_Error to simulate a failed mining run.
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return WP_Error
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			return new WP_Error( 'simulated_failure', 'Simulated mining failure for test.' );
		}
	}
}
